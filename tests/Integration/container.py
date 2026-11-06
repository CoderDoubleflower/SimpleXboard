#!/usr/bin/env python3
"""Start a built image with its default entrypoint and verify a real restart.

Requires Python's standard library, a local Docker daemon and the built image.
Uses only randomly named disposable containers/volumes, a private temporary
configuration and the guarded seed-panel.php fixture. No existing database or
Docker service is used. Logs and the JSON report are redacted before saving.

Example:
  python3 tests/Integration/container.py --image simplexboard:ci --artifacts /tmp/container-e2e
"""
from __future__ import annotations

import argparse
import base64
import gzip
import hashlib
from html.parser import HTMLParser
import io
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import struct
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urljoin, urlsplit
from urllib.request import Request, urlopen


REPO = Path(__file__).resolve().parents[2]
DATA = "/www/.docker/.data"


class CheckFailed(Exception):
    pass


def require(condition, message):
    if not condition:
        raise CheckFailed(message)


class Redactor:
    def __init__(self, *values):
        self.values = {value for value in values if isinstance(value, str) and value}

    def remember(self, value):
        if isinstance(value, dict):
            for key, item in value.items():
                if str(key).lower() in {"token", "server_token", "uuid", "password", "private_key", "auth_data", "app_key", "secure_path", "subscribe_url"} and isinstance(item, str):
                    self.values.add(item)
                self.remember(item)
        elif isinstance(value, list):
            for item in value:
                self.remember(item)

    def clean(self, value):
        text = str(value)
        for secret in sorted(self.values, key=len, reverse=True):
            text = text.replace(secret, "[REDACTED]")
        text = re.sub(r'([?&](?:token|password|key)=)[^&\s"<>]+', r'\1[REDACTED]', text, flags=re.I)
        text = re.sub(r'(/s/)[A-Za-z0-9_-]+', r'\1[REDACTED]', text)
        text = re.sub(r'("(?:token|server_token|uuid|password|private_key|auth_data)"\s*:\s*")[^"]*', r'\1[REDACTED]', text, flags=re.I)
        text = re.sub(r'Bearer\s+[A-Za-z0-9_+/.=|:-]+', 'Bearer [REDACTED]', text, flags=re.I)
        return text


class API:
    def __init__(self, base_url, admin_path, redactor):
        self.base_url = base_url
        self.admin_path = "/api/v2/" + admin_path + "/"
        self.authorization = ""
        self.redactor = redactor

    def raw(self, method, path, data=None, query=None, authenticated=False, expected=200):
        url = self.base_url + path
        if query:
            url += "?" + urlencode(query)
        headers = {"Accept": "application/json", "User-Agent": "SimpleXboard-container-verification"}
        if authenticated:
            headers["Authorization"] = self.authorization
        if data is not None:
            headers["Content-Type"] = "application/json"
        request = Request(url, method=method, headers=headers,
                          data=json.dumps(data).encode() if data is not None else None)
        try:
            with urlopen(request, timeout=15) as response:
                status, body, response_headers = response.status, response.read(), response.headers
        except HTTPError as error:
            status, body, response_headers = error.code, error.read(), error.headers
        except (URLError, TimeoutError, OSError):
            raise CheckFailed("HTTP transport is unavailable") from None
        require(status == expected, f"HTTP {method} operation returned {status}, expected {expected}")
        return body, response_headers

    def json(self, method, path, **options):
        body, _ = self.raw(method, path, **options)
        try:
            result = json.loads(body)
        except (json.JSONDecodeError, UnicodeError):
            raise CheckFailed("HTTP operation did not return valid JSON") from None
        self.redactor.remember(result)
        return result

    def admin(self, operation, data=None, query=None, method="POST"):
        return self.json(method, self.admin_path + operation, data=data, query=query, authenticated=True)

    def login(self, email, password):
        response = self.json("POST", "/api/v1/passport/auth/login", data={"email": email, "password": password})
        require(response.get("data", {}).get("is_admin") is True, "HTTP login did not authenticate the administrator")
        self.authorization = response["data"]["auth_data"]


class PanelAssets(HTMLParser):
    def __init__(self):
        super().__init__()
        self.assets = []

    def handle_starttag(self, tag, attributes):
        attributes = dict(attributes)
        if tag == "script" and attributes.get("src"):
            self.assets.append(("javascript", attributes["src"], attributes.get("integrity", "")))
        elif tag == "link" and attributes.get("rel") == "stylesheet":
            self.assets.append(("text/css", attributes.get("href", ""), attributes.get("integrity", "")))


def check_panel(api, admin_path):
    body, headers = api.raw("GET", "/" + admin_path)
    require("text/html" in headers.get("Content-Type", ""), "Administrator page is not HTML")
    document = body.decode("utf-8")
    require('id="panel-bootstrap"' in document and 'id="app"' in document, "Administrator page has no panel bootstrap")
    parser = PanelAssets()
    parser.feed(document)
    require({asset[0] for asset in parser.assets} >= {"javascript", "text/css"}, "Built panel JS or CSS is missing")
    for kind, source, integrity in parser.assets:
        parsed = urlsplit(urljoin(api.base_url, source))
        require(parsed.netloc == urlsplit(api.base_url).netloc and parsed.path.startswith("/assets/panel/"), "Panel asset did not resolve inside this container")
        content, asset_headers = api.raw("GET", parsed.path)
        require(kind in asset_headers.get("Content-Type", ""), "Built panel asset has an incorrect MIME type")
        algorithm, separator, expected = integrity.partition("-")
        require(separator and algorithm in {"sha256", "sha384", "sha512"}, "Panel asset integrity metadata is missing")
        actual = base64.b64encode(hashlib.new(algorithm, content).digest()).decode()
        require(actual == expected, "Panel asset bytes do not match the page's integrity metadata")


def check_subscription(api, member):
    config = api.json("GET", "/s/" + member["token"], query={"flag": "sing-box"})
    outbounds = config.get("outbounds", [])
    require(any(node.get("type") == "vless" and node.get("uuid") == member["uuid"] for node in outbounds), "Subscription has no VLESS member credentials")
    require(any(node.get("type") == "hysteria2" and node.get("password") == member["uuid"] for node in outbounds), "Subscription has no Hysteria2 member credentials")


def check_backup(api):
    created = api.admin("backup/create", {})
    name = created.get("data", {}).get("file", {}).get("name")
    require(isinstance(name, str) and name.endswith(".sqlite.gz"), "HTTP backup did not create a native SQLite archive")
    body, headers = api.raw("GET", api.admin_path + "backup/download", query={"name": name}, authenticated=True)
    require(body[:2] == b"\x1f\x8b", "Downloaded backup does not have a gzip header")
    require("application/gzip" in headers.get("Content-Type", ""), "Downloaded backup has an incorrect content type")
    with gzip.GzipFile(fileobj=io.BytesIO(body)) as archive:
        require(archive.read(16) == b"SQLite format 3\0", "Downloaded backup does not contain a native SQLite database")


class WebSocket:
    """Small RFC 6455 client for the existing JSON node protocol; no dependencies."""
    def __init__(self, url, redactor, timeout=30):
        parsed = urlsplit(url)
        require(parsed.scheme == "ws", "Container verification expects a local ws endpoint")
        self.timeout = timeout
        self.redactor = redactor
        self.buffer = b""
        self.socket = socket.create_connection((parsed.hostname, parsed.port or 80), timeout=10)
        key = base64.b64encode(secrets.token_bytes(16)).decode()
        target = (parsed.path or "/") + ("?" + parsed.query if parsed.query else "")
        request = (f"GET {target} HTTP/1.1\r\nHost: {parsed.netloc}\r\nUpgrade: websocket\r\n"
                   f"Connection: Upgrade\r\nSec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n")
        self.socket.sendall(request.encode())
        try:
            while b"\r\n\r\n" not in self.buffer:
                chunk = self.socket.recv(4096)
                require(bool(chunk), "WebSocket closed during HTTP Upgrade")
                self.buffer += chunk
                require(len(self.buffer) < 65536, "WebSocket Upgrade headers are unexpectedly large")
            raw_headers, self.buffer = self.buffer.split(b"\r\n\r\n", 1)
            lines = raw_headers.decode("latin1").split("\r\n")
            require(lines[0].split()[1] == "101", "Caddy WebSocket Upgrade did not return HTTP 101")
            headers = dict(line.split(":", 1) for line in lines[1:] if ":" in line)
            headers = {name.lower(): value.strip() for name, value in headers.items()}
            expected = base64.b64encode(hashlib.sha1((key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").encode()).digest()).decode()
            require(headers.get("sec-websocket-accept") == expected, "WebSocket Upgrade accept key is invalid")
        except Exception:
            self.socket.close()
            raise

    def read_exact(self, length, deadline):
        while len(self.buffer) < length:
            remaining = deadline - time.monotonic()
            require(remaining > 0, "WebSocket event timed out")
            self.socket.settimeout(remaining)
            try:
                chunk = self.socket.recv(max(4096, length - len(self.buffer)))
            except (TimeoutError, OSError):
                raise CheckFailed("WebSocket event timed out or connection failed") from None
            require(bool(chunk), "WebSocket connection closed before the expected event")
            self.buffer += chunk
        value, self.buffer = self.buffer[:length], self.buffer[length:]
        return value

    def send_frame(self, opcode, payload):
        mask = secrets.token_bytes(4)
        length = len(payload)
        if length < 126:
            header = bytes([0x80 | opcode, 0x80 | length])
        elif length < 65536:
            header = bytes([0x80 | opcode, 0x80 | 126]) + struct.pack("!H", length)
        else:
            header = bytes([0x80 | opcode, 0x80 | 127]) + struct.pack("!Q", length)
        self.socket.sendall(header + mask + bytes(value ^ mask[index % 4] for index, value in enumerate(payload)))

    def message(self, deadline):
        fragments = bytearray()
        while True:
            first, second = self.read_exact(2, deadline)
            final, opcode, masked = bool(first & 0x80), first & 0x0F, bool(second & 0x80)
            length = second & 0x7F
            if length == 126:
                length = struct.unpack("!H", self.read_exact(2, deadline))[0]
            elif length == 127:
                length = struct.unpack("!Q", self.read_exact(8, deadline))[0]
            require(not masked and length <= 4 * 1024 * 1024, "Unexpected WebSocket frame format")
            payload = self.read_exact(length, deadline)
            if opcode == 8:
                raise CheckFailed("WebSocket closed before the expected node event")
            if opcode == 9:
                self.send_frame(10, payload)
                continue
            if opcode == 10:
                continue
            require(opcode in {0, 1}, "Expected a JSON text WebSocket frame")
            fragments.extend(payload)
            require(len(fragments) <= 4 * 1024 * 1024, "WebSocket event exceeds the test's size limit")
            if final:
                try:
                    message = json.loads(fragments)
                except (json.JSONDecodeError, UnicodeError):
                    raise CheckFailed("WebSocket returned invalid JSON") from None
                self.redactor.remember(message)
                return message

    def wait(self, event, predicate=lambda message: True):
        deadline = time.monotonic() + self.timeout
        while True:
            message = self.message(deadline)
            if message.get("event") == "ping":
                self.send_frame(1, json.dumps({"event": "pong", "data": {}}).encode())
            elif message.get("event") == "error":
                raise CheckFailed("Node WebSocket returned an authentication or protocol error")
            elif message.get("event") == event and predicate(message):
                return message

    def close(self):
        try:
            self.send_frame(8, b"")
        except OSError:
            pass
        self.socket.close()


def contains_member(message, member):
    return any(int(user.get("id", 0)) == int(member["id"])
               for user in message.get("data", {}).get("users", []))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--image", required=True)
    parser.add_argument("--docker", default="docker")
    parser.add_argument("--artifacts", default=str(REPO / "storage/framework/testing/container-artifacts"))
    parser.add_argument("--timeout", type=int, default=120, help="Startup readiness deadline in seconds")
    args = parser.parse_args()
    artifacts = Path(args.artifacts).resolve()
    artifacts.mkdir(parents=True, exist_ok=True)
    identity = secrets.token_hex(6)
    container = "simplexboard-e2e-" + identity
    volumes = [container + "-database", container + "-redis"]
    admin_path, email, password = "panel-" + identity, "admin-" + identity + "@example.test", secrets.token_urlsafe(32)
    app_key = "base64:" + base64.b64encode(secrets.token_bytes(32)).decode()
    redactor = Redactor(password, app_key, admin_path)
    report = {"status": "failed", "scope": "Built image default entrypoint, Caddy/Octane/Horizon/Redis/Workerman, isolated SQLite and real container restart", "checks": []}
    attempted_container, created_volumes, sockets = False, [], []
    temporary = tempfile.TemporaryDirectory(prefix="simplexboard-container-")
    work = Path(temporary.name)
    started = time.monotonic()

    def passed(name):
        entry = {"check": name, "elapsed_seconds": round(time.monotonic() - started, 2)}
        report["checks"].append(entry)
        print(json.dumps(entry), flush=True)

    def docker(arguments, label, check=True, timeout=60):
        try:
            result = subprocess.run([args.docker, *arguments], capture_output=True, text=True, timeout=timeout)
        except subprocess.TimeoutExpired:
            raise CheckFailed(label + " timed out") from None
        if check and result.returncode:
            (artifacts / "last-command-error.log").write_text(redactor.clean(result.stderr))
            raise CheckFailed(label + " failed; see redacted last-command-error.log")
        return result

    def wait_ready(probe, label):
        deadline = time.monotonic() + args.timeout
        while time.monotonic() < deadline:
            try:
                if probe():
                    return
            except CheckFailed:
                pass
            state = docker(["inspect", "--format", "{{.State.Running}}", container], "Read test container state", check=False)
            require(state.returncode == 0 and state.stdout.strip() == "true", label + ": test container stopped")
            time.sleep(0.5)
        raise CheckFailed(label + " did not become ready")

    def node_socket(base_url, node, token):
        connection = WebSocket(base_url.replace("http://", "ws://", 1) + "/ws?" + urlencode({"node_id": node["id"], "token": token}), redactor, timeout=45)
        sockets.append(connection)
        connection.wait("auth.success")
        connection.wait("sync.config")
        return connection, connection.wait("sync.users")

    try:
        require(bool(shutil.which(args.docker)), "Docker CLI was not found")
        report["image_id"] = docker(["image", "inspect", "--format", "{{.Id}}", args.image], "Inspect the built image").stdout.strip()
        with socket.socket() as reservation:
            reservation.bind(("127.0.0.1", 0))
            port = reservation.getsockname()[1]
        base_url = f"http://127.0.0.1:{port}"
        configuration = {
            "APP_NAME": "SimpleXboard", "APP_ENV": "production", "APP_DEBUG": "false", "APP_KEY": app_key, "APP_URL": base_url,
            "DB_CONNECTION": "sqlite", "DB_DATABASE": DATA + "/database.sqlite", "DATABASE_URL": "",
            "REDIS_CLIENT": "phpredis", "REDIS_HOST": "/data/redis.sock", "REDIS_PORT": "0", "REDIS_PASSWORD": "null",
            "REDIS_DB": "0", "REDIS_CACHE_DB": "1", "REDIS_PREFIX": identity + "_", "CACHE_PREFIX": identity + "_cache_",
            "HORIZON_PREFIX": identity + "_horizon_", "CACHE_DRIVER": "redis", "QUEUE_CONNECTION": "redis",
            "SESSION_DRIVER": "array", "LOG_CHANNEL": "stderr", "INSTALLED": "true",
        }
        env_file = work / ".env"
        env_file.write_text("\n".join(key + "=" + value for key, value in configuration.items()) + "\n")
        env_file.chmod(0o600)
        for volume in volumes:
            docker(["volume", "create", "--label", "simplexboard.integration=round2", volume], "Create isolated test volume")
            created_volumes.append(volume)
        attempted_container = True
        docker([
            "run", "--detach", "--name", container, "--label", "simplexboard.integration=round2", "--env", "RESOURCE_PROFILE=minimal",
            "--publish", f"127.0.0.1:{port}:7001",
            "--mount", f"type=bind,source={env_file},target=/www/.env",
            "--mount", f"type=bind,source={REPO / 'tests/Support'},target=/www/tests/Support,readonly",
            "--mount", f"type=volume,source={volumes[0]},target={DATA}",
            "--mount", f"type=volume,source={volumes[1]},target=/data", args.image,
        ], "Start the built image with its default entrypoint")
        redis_probe = lambda: docker(["exec", container, "redis-cli", "-s", "/data/redis.sock", "PING"], "Probe embedded Redis", check=False).stdout.strip() == "PONG"
        wait_ready(redis_probe, "Embedded Redis")
        docker(["exec", "--user", "www", container, "touch", DATA + "/database.sqlite"], "Create the isolated SQLite file")
        migration = docker(["exec", "--user", "www", container, "php", "artisan", "migrate", "--force", "--no-interaction"], "Migrate the isolated container database", timeout=120)
        (artifacts / "migrate.log").write_text(redactor.clean(migration.stdout + migration.stderr))
        seed_config = {
            "SIMPLEXBOARD_E2E": "1", "E2E_WORK_DIR": DATA, "E2E_FIXTURE_FILE": DATA + "/fixture.json",
            "E2E_ADMIN_PATH": admin_path, "E2E_ADMIN_EMAIL": email, "E2E_ADMIN_PASSWORD": password,
            "E2E_BASE_URL": base_url, "E2E_WS_URL": base_url.replace("http://", "ws://", 1) + "/ws",
        }
        seed_file = work / "seed.env"
        seed_file.write_text("\n".join(key + "=" + value for key, value in seed_config.items()) + "\n")
        seed_file.chmod(0o600)
        docker(["exec", "--user", "www", "--env-file", str(seed_file), container, "php", "/www/tests/Support/seed-panel.php"], "Seed the guarded container fixture")
        fixture = json.loads(docker(["exec", container, "cat", DATA + "/fixture.json"], "Read the private fixture").stdout)
        redactor.remember(fixture)
        docker(["exec", "--user", "www", container, "rm", DATA + "/fixture.json"], "Remove the private fixture file")
        api = API(base_url, admin_path, redactor)
        wait_ready(lambda: api.raw("GET", "/")[0] == b"SimpleXboard", "Caddy and Octane HTTP")
        wait_ready(lambda: docker(["exec", "--user", "www", container, "php", "artisan", "horizon:status"], "Probe Horizon", check=False).returncode == 0, "Horizon")
        passed("Default container starts embedded Redis, Horizon and Caddy/Octane with a migrated database")

        api.login(email, password)
        passed("Administrator logs in through the published Caddy port")
        check_panel(api, admin_path)
        passed("Administrator HTML and built JavaScript/CSS load with matching integrity hashes")
        member = api.admin("user/create", {"name": "Container restart member", "transfer_enable": None})["data"]
        require(member.get("eligible") is True and member.get("access_mode") == "all", "Created member has incorrect default authorization")
        check_subscription(api, member)
        passed("New member receives VLESS and Hysteria2 subscription credentials")
        check_backup(api)
        passed("HTTP backup creation and download succeed as www with gzip and native SQLite headers")

        for node in fixture["nodes"]:
            connection, full = node_socket(base_url, node, fixture["server_token"])
            require(contains_member(full, member), "Enabled member is missing from WebSocket full sync")
        passed("Caddy forwards real WebSocket Upgrade and authenticated node full sync")
        api.admin("user/ban", {"id": member["id"], "banned": True})
        for connection in sockets:
            connection.wait("sync.user.delta", lambda message: message.get("data", {}).get("action") == "remove" and contains_member(message, member))
        api.raw("GET", "/s/" + member["token"], expected=403)
        passed("Disabling a member revokes both connected nodes through Horizon and rejects the subscription")
        for connection in sockets:
            connection.close()
        sockets.clear()

        docker(["restart", "--time", "15", container], "Restart the entire panel container", timeout=90)
        wait_ready(redis_probe, "Embedded Redis after restart")
        wait_ready(lambda: api.raw("GET", "/")[0] == b"SimpleXboard", "Caddy and Octane after restart")
        api.login(email, password)
        check_panel(api, admin_path)
        current = api.admin("user/getUserInfoById", query={"id": member["id"]}, method="GET")["data"]
        require(current.get("token") == member["token"] and current.get("uuid") == member["uuid"] and current.get("banned") is True,
                "Container restart changed member credentials or lost the disabled state")
        api.raw("GET", "/s/" + member["token"], expected=403)
        passed("Container restart preserves administrator login, panel assets, member token/UUID and disabled state")

        for node in fixture["nodes"]:
            full = api.json("GET", "/api/v2/server/user", query={"token": fixture["server_token"], "node_id": node["id"]})
            require(not any(int(user["id"]) == int(member["id"]) for user in full.get("users", [])), "HTTP full sync resurrected a disabled member after restart")
            connection, full = node_socket(base_url, node, fixture["server_token"])
            require(not contains_member(full, member), "WebSocket full sync resurrected a disabled member after restart")
        passed("Node HTTP and WebSocket full sync do not resurrect the disabled member after restart")
        restored = api.admin("user/ban", {"id": member["id"], "banned": False})["data"]
        require(restored["token"] == member["token"] and restored["uuid"] == member["uuid"], "Restoring a member changed its credentials")
        for connection in sockets:
            connection.wait("sync.user.delta", lambda message: message.get("data", {}).get("action") == "add" and contains_member(message, member))
        check_subscription(api, member)
        passed("Member restoration after restart reaches both nodes and restores the same subscription")
        check_backup(api)
        passed("HTTP SQLite backup remains writable and downloadable after container restart")
        report["status"] = "passed"
    except Exception as exception:
        report["error"] = redactor.clean(exception)
        print(json.dumps({"status": "failed", "error": report["error"]}), flush=True)
    finally:
        for connection in sockets:
            try:
                connection.close()
            except OSError:
                pass
        if attempted_container:
            try:
                logs = docker(["logs", "--tail", "4000", container], "Collect container logs", check=False)
                (artifacts / "container.log").write_text(redactor.clean(logs.stdout + logs.stderr))
                state = docker(["inspect", "--format", "{{json .State}}", container], "Collect container state", check=False)
                (artifacts / "container-state.json").write_text(redactor.clean(state.stdout))
            finally:
                docker(["rm", "--force", "--volumes", container], "Remove the disposable container", check=False)
        for volume in reversed(created_volumes):
            docker(["volume", "rm", volume], "Remove the disposable volume", check=False)
        temporary.cleanup()
        (artifacts / "container-results.json").write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps({"status": report["status"], "checks": len(report["checks"])}), flush=True)
    return 0 if report["status"] == "passed" else 1


if __name__ == "__main__":
    raise SystemExit(main())

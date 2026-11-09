#!/usr/bin/env python3
"""Proxy actual loopback HTTP requests through panel-generated subscriptions.

Run with tests/Support/run-stack.py and an official sing-box binary:
  python tests/Support/run-stack.py -- python tests/Integration/protocol_connections.py --sing-box /path/to/sing-box

Requires sing-box with QUIC/uTLS support, curl and OpenSSL. Both protocol servers,
the TLS handshake target and the HTTP origin listen only on loopback. This tests
VLESS + REALITY and Hysteria2 data traffic, not deployment to a VPS or a production
node agent. Subscription responses are executed without modifying their JSON.
REALITY keys and Short IDs come from the authenticated panel generator APIs.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import subprocess
import sys
import threading
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urlparse
from urllib.request import Request, urlopen


class CheckFailed(Exception):
    pass


def require(condition, message):
    if not condition:
        raise CheckFailed(message)


class API:
    def __init__(self, base, admin_path):
        self.base = base.rstrip("/")
        self.admin_path = "/api/v2/" + admin_path.strip("/") + "/"
        self.authorization = ""

    def raw(self, method, path, data=None, query=None, authenticated=True, user_agent=None, require_no_store=False):
        url = self.base + path + ("?" + urlencode(query) if query else "")
        headers = {"Accept": "application/json"}
        if authenticated and self.authorization:
            headers["Authorization"] = self.authorization
        if user_agent:
            headers["User-Agent"] = user_agent
        if data is not None:
            headers["Content-Type"] = "application/json"
        request = Request(url, data=None if data is None else json.dumps(data).encode(), headers=headers, method=method)
        try:
            with urlopen(request, timeout=15) as response:
                require(response.status == 200, "Panel operation returned a non-200 status")
                if require_no_store:
                    directives = {value.strip().lower() for value in response.headers.get("Cache-Control", "").split(",")}
                    require("no-store" in directives, "Credential generation response omitted Cache-Control: no-store")
                return response.read()
        except HTTPError as error:
            # Bodies and URLs can contain credentials; never include them in logs.
            raise CheckFailed(f"Panel HTTP {method} operation returned {error.code}") from None
        except URLError:
            raise CheckFailed("Panel HTTP transport unavailable") from None

    def request(self, method, path, **kwargs):
        try:
            return json.loads(self.raw(method, path, **kwargs))
        except json.JSONDecodeError:
            raise CheckFailed("Panel returned an invalid JSON response") from None

    def admin(self, path, data=None, method="POST", require_no_store=False):
        return self.request(method, self.admin_path + path, data=data, require_no_store=require_no_store)["data"]


def validate_short_id(value):
    require(isinstance(value, str) and re.fullmatch(r"[0-9a-f]{16}", value) is not None,
            "REALITY generator returned an invalid 8-byte Short ID")


def validate_reality_pair(pair):
    require(isinstance(pair, dict), "REALITY generator returned an invalid key pair response")
    for field in ("private_key", "public_key"):
        value = pair.get(field)
        require(isinstance(value, str) and re.fullmatch(r"[A-Za-z0-9_-]{43}", value) is not None,
                "REALITY generator returned an invalid " + field + " encoding")
        decoded = base64.urlsafe_b64decode(value + "=")
        require(len(decoded) == 32 and base64.urlsafe_b64encode(decoded).rstrip(b"=").decode("ascii") == value,
                "REALITY generator returned a noncanonical 32-byte " + field)
    validate_short_id(pair.get("short_id"))


def free_port(kind=socket.SOCK_STREAM):
    with socket.socket(socket.AF_INET, kind) as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def private_write(path, content):
    with os.fdopen(os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600), "wb") as handle:
        handle.write(content if isinstance(content, bytes) else json.dumps(content).encode())


class Processes:
    def __init__(self, artifacts, env):
        self.artifacts, self.env, self.children, self.logs = artifacts, env, [], []

    def start(self, name, argv):
        handle = (self.artifacts / (name + ".log")).open("wb")
        self.logs.append(handle)
        child = subprocess.Popen(argv, env=self.env, stdin=subprocess.DEVNULL, stdout=handle, stderr=subprocess.STDOUT)
        self.children.append(child)
        return child

    @staticmethod
    def stop(child):
        if child.poll() is None:
            child.terminate()
            try:
                child.wait(timeout=5)
            except subprocess.TimeoutExpired:
                child.kill()
                child.wait(timeout=3)

    def close(self):
        for child in reversed(self.children):
            self.stop(child)
        for handle in self.logs:
            handle.close()


def wait_tcp(port, child):
    until = time.monotonic() + 10
    while time.monotonic() < until:
        require(child.poll() is None, "Protocol process exited before listening; see its log")
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=0.2):
                return
        except OSError:
            time.sleep(0.05)
    raise CheckFailed("Protocol process did not open its local listener")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--sing-box", default=os.environ.get("SING_BOX_BIN", "sing-box"))
    args = parser.parse_args()
    require(os.environ.get("SIMPLEXBOARD_E2E") == "1", "Run this test through tests/Support/run-stack.py")
    base = os.environ["E2E_BASE_URL"]
    require(urlparse(base).hostname == "127.0.0.1", "The disposable panel must be on loopback")
    work = Path(os.environ["E2E_WORK_DIR"]) / "protocol-connections"
    work.mkdir(mode=0o700)
    artifacts = Path(os.environ["E2E_ARTIFACT_DIR"])
    binary = shutil.which(args.sing_box)
    require(binary is not None, "sing-box binary not found")
    for tool in ("curl", "openssl"):
        require(shutil.which(tool) is not None, tool + " is required")
    version_output = subprocess.check_output([binary, "version"], text=True)
    version_match = re.search(r"sing-box version (\d+\.\d+\.\d+)", version_output)
    require(version_match is not None, "Could not identify sing-box version")
    version = version_match.group(1)
    result = {"scope": "loopback-only", "sing_box_version": version,
              "binary_sha256": hashlib.sha256(Path(binary).read_bytes()).hexdigest(), "protocols": []}
    api = API(base, os.environ["E2E_ADMIN_PATH"])
    login = api.request("POST", "/api/v1/passport/auth/login", authenticated=False,
                        data={"email": os.environ["E2E_ADMIN_EMAIL"], "password": os.environ["E2E_ADMIN_PASSWORD"]})
    api.authorization = login["data"]["auth_data"]
    fixture = json.loads(Path(os.environ["E2E_FIXTURE_FILE"]).read_text())
    for node in fixture["nodes"]:
        api.admin("server/manage/update", {"id": node["id"], "enabled": False})
    member = api.admin("user/create", {"name": "Protocol connection test", "access_mode": "all"})
    require(member["eligible"], "New test member is not eligible")

    cert, key = work / "localhost.pem", work / "localhost-key.pem"
    subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                    "-subj", "/CN=localhost", "-addext", "subjectAltName=DNS:localhost",
                    "-keyout", str(key), "-out", str(cert)], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    key.chmod(0o600)
    pair = api.admin("server/manage/generateRealityKeyPair", require_no_store=True)
    validate_reality_pair(pair)
    standalone_short_id = api.admin("server/manage/generateShortId", require_no_store=True)
    require(isinstance(standalone_short_id, dict), "Short ID generator returned an invalid response")
    validate_short_id(standalone_short_id.get("short_id"))
    decoy_port, vless_port, hy2_port = free_port(), free_port(), free_port(socket.SOCK_DGRAM)
    short_id = pair["short_id"]
    hy2_password = secrets.token_urlsafe(24)
    nodes = [
        {"type": "vless", "name": "Loopback REALITY", "host": "127.0.0.1", "port": str(vless_port), "server_port": vless_port,
         "show": True, "enabled": True, "protocol_settings": {
             "tls": 2, "network": "tcp", "flow": "xtls-rprx-vision", "utls": {"enabled": True, "fingerprint": "chrome"},
             "reality_settings": {"server_name": "localhost", "server_port": decoy_port, "allow_insecure": False,
                                  "private_key": pair["private_key"], "public_key": pair["public_key"], "short_id": short_id}}},
        {"type": "hysteria", "name": "Loopback Hysteria2", "host": "127.0.0.1", "port": str(hy2_port), "server_port": hy2_port,
         "cert_config": {"cert_mode": "file", "cert_file": str(cert), "key_file": str(key)},
         "show": True, "enabled": True, "protocol_settings": {"version": 2,
             "tls": {"server_name": "localhost", "allow_insecure": False}, "bandwidth": {"up": 100, "down": 100},
             "obfs": {"open": True, "type": "salamander", "password": hy2_password}}},
    ]
    for node in nodes:
        api.admin("server/manage/save", node)
    saved_nodes = api.admin("server/manage/getNodes", method="GET")
    by_name = {node["name"]: node for node in saved_nodes}
    for node in nodes:
        node["id"] = by_name[node["name"]]["id"]

    env = os.environ.copy()
    # Trust only this process-local test certificate without disabling TLS verification.
    env["SSL_CERT_FILE"] = str(cert)
    processes = Processes(artifacts, env)
    origin = None
    try:
        decoy = processes.start("protocol-tls-handshake", ["openssl", "s_server", "-accept", f"127.0.0.1:{decoy_port}",
                                  "-cert", str(cert), "-key", str(key), "-tls1_3", "-alpn", "h2,http/1.1", "-www", "-quiet"])
        wait_tcp(decoy_port, decoy)
        expected_body = ("SimpleXboard protocol verification " + secrets.token_hex(16)).encode()

        class OriginHandler(BaseHTTPRequestHandler):
            def do_GET(self):
                self.send_response(200)
                self.send_header("Content-Length", str(len(expected_body)))
                self.end_headers()
                self.wfile.write(expected_body)

            def log_message(self, *_):
                pass

        origin = ThreadingHTTPServer(("127.0.0.1", 0), OriginHandler)
        threading.Thread(target=origin.serve_forever, daemon=True).start()
        target = f"http://127.0.0.1:{origin.server_port}/protocol-proof"
        for node in nodes:
            label = "vless-reality" if node["type"] == "vless" else "hysteria2"
            query = {"token": fixture["server_token"], "node_id": node["id"]}
            backend = api.request("GET", "/api/v2/server/config", query=query, authenticated=False)
            users = api.request("GET", "/api/v2/server/user", query=query, authenticated=False)["users"]
            backend_user = next((user for user in users if int(user["id"]) == member["id"]), None)
            require(backend_user is not None, label + " node API omitted the test member")
            require(backend_user["uuid"] == member["uuid"], label + " node credentials disagree with member data")
            inbound = {"type": "vless" if label == "vless-reality" else "hysteria2", "tag": label,
                       "listen": "127.0.0.1", "listen_port": backend["server_port"]}
            if label == "vless-reality":
                settings = backend["tls_settings"]
                require(backend["tls"] == 2, "Node API did not retain REALITY mode")
                inbound.update({"users": [{"name": "test-member", "uuid": backend_user["uuid"], "flow": backend["flow"]}],
                    "tls": {"enabled": True, "server_name": settings["server_name"], "reality": {
                        "enabled": True, "private_key": settings["private_key"], "short_id": [settings["short_id"]],
                        "handshake": {"server": "127.0.0.1", "server_port": int(settings["server_port"])}}}})
            else:
                require(backend["version"] == 2, "Node API did not retain Hysteria2 version")
                certificate = backend["cert_config"]
                require(certificate["cert_mode"] == "file", "Node API did not retain the server certificate source")
                inbound.update({"users": [{"name": "test-member", "password": backend_user["uuid"]}],
                    "up_mbps": backend["up_mbps"], "down_mbps": backend["down_mbps"],
                    "obfs": {"type": backend["obfs"], "password": backend["obfs-password"]},
                    "tls": {"enabled": True, "server_name": backend["server_name"],
                            "certificate_path": certificate["cert_file"], "key_path": certificate["key_file"]}})
            server_config = work / (label + "-server.json")
            private_write(server_config, {"log": {"level": "warn"}, "inbounds": [inbound], "outbounds": [{"type": "direct", "tag": "direct"}]})
            with (artifacts / (label + "-server-check.log")).open("wb") as log:
                checked = subprocess.run([binary, "check", "-c", str(server_config)], env=env, stdout=log, stderr=subprocess.STDOUT)
            require(checked.returncode == 0, label + " server config was rejected; see check log")
            server = processes.start(label + "-server", [binary, "run", "-c", str(server_config)])
            if label == "vless-reality":
                wait_tcp(backend["server_port"], server)

            client_port = free_port()
            template = {"log": {"level": "warn"}, "inbounds": [{"type": "socks", "tag": "local-socks", "listen": "127.0.0.1", "listen_port": client_port}],
                        "outbounds": [], "route": {"rules": [], "final": node["name"]}}
            api.admin("config/save", {"subscribe_template_singbox": json.dumps(template), "show_protocol_to_server_enable": False})
            raw = api.raw("GET", "/s/" + member["token"], query={"flag": "sing-box"}, authenticated=False, user_agent="sing-box " + version)
            subscription = json.loads(raw)
            outbound = next((item for item in subscription["outbounds"] if item["tag"] == node["name"]), None)
            require(outbound is not None, label + " subscription omitted the node")
            require(subscription["route"]["final"] == node["name"], "Subscription changed the selected route")
            require(outbound["tls"]["insecure"] is False, label + " subscription disabled certificate verification")
            require(outbound["tls"]["server_name"] == "localhost", label + " subscription changed the TLS name")
            require(pair["private_key"].encode() not in raw, "Subscription exposed the REALITY private key")
            require(outbound.get("uuid", outbound.get("password")) == backend_user["uuid"], "Subscription credentials differ from node API")
            if label == "vless-reality":
                require(outbound["flow"] == "xtls-rprx-vision" and outbound["tls"]["reality"]["public_key"] == pair["public_key"]
                        and outbound["tls"]["reality"]["short_id"] == short_id, "Subscription changed REALITY settings")
            else:
                require(outbound["type"] == "hysteria2" and outbound["obfs"] == {"type": "salamander", "password": hy2_password},
                        "Subscription changed Hysteria2 authentication or obfuscation")
            client_config = work / (label + "-subscription.json")
            private_write(client_config, raw)
            with (artifacts / (label + "-client-check.log")).open("wb") as log:
                checked = subprocess.run([binary, "check", "-c", str(client_config)], env=env, stdout=log, stderr=subprocess.STDOUT)
            require(checked.returncode == 0, label + " subscription was rejected; see check log")
            client = processes.start(label + "-client", [binary, "run", "-c", str(client_config)])
            wait_tcp(client_port, client)
            curl = ["curl", "--silent", "--show-error", "--fail", "--noproxy", "", "--socks5-hostname", f"127.0.0.1:{client_port}",
                    "--connect-timeout", "5", "--max-time", "10", target]
            started = time.monotonic()
            proxied = subprocess.run(curl, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
            require(proxied.returncode == 0 and proxied.stdout == expected_body, label + " HTTP proxy request failed; see protocol logs")
            elapsed = round((time.monotonic() - started) * 1000, 1)
            # A new request must fail with the chosen protocol server stopped.
            processes.stop(server)
            stopped_curl = curl.copy()
            stopped_curl[stopped_curl.index("--max-time") + 1] = "3"
            rejected = subprocess.run(stopped_curl, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=8)
            require(rejected.returncode != 0, label + " bypassed the stopped protocol server")
            require(client.poll() is None, label + " client exited during the negative control")
            processes.stop(client)
            outcome = {"protocol": label, "status": "passed", "http_response_bytes": len(expected_body), "elapsed_ms": elapsed,
                       "subscription_executed_unchanged": True, "tls_verification_enabled": True, "stopped_server_request_failed": True}
            if label == "vless-reality":
                outcome.update({"reality_keypair_source": "admin-api", "generated_keys_format_valid": True,
                                "standalone_short_id_format_valid": True, "generation_response_no_store": True})
            result["protocols"].append(outcome)
            print(json.dumps(outcome), flush=True)
        result["status"] = "passed"
        return 0
    except CheckFailed as error:
        result.update({"status": "failed", "error": str(error)})
        raise
    finally:
        processes.close()
        if origin:
            origin.shutdown()
            origin.server_close()
        (artifacts / "protocol-connections-results.json").write_text(json.dumps(result, indent=2) + "\n")


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except CheckFailed as error:
        print("Protocol connection check failed: " + str(error), file=sys.stderr)
        raise SystemExit(1)

#!/usr/bin/env python3
"""Exercise Hysteria2 certificate failures and recovery with an actual machine.

Run in tests/Support/run-stack.py's disposable panel. The real xboard-node process
loads panel-supplied certificate sources; Mihomo consumes an unchanged panel
subscription and proxies requests to a local origin. No ACME authority or DNS
provider is contacted. Private keys and subscriptions stay in temporary files.
"""
from __future__ import annotations

import argparse
import copy
import hashlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import sys
import threading
from urllib.error import HTTPError
from urllib.parse import urlparse
from urllib.request import Request, urlopen

from protocol_connections import API, CheckFailed, Processes, free_port, private_write, require, wait_tcp
from node_core_lifecycle import eventually, health, panel_node


def udp_listening(port):
    wanted = f"{port:04X}"
    for path in (Path("/proc/net/udp"), Path("/proc/net/udp6")):
        if path.exists():
            for row in path.read_text().splitlines()[1:]:
                columns = row.split()
                if len(columns) > 1 and columns[1].rsplit(":", 1)[-1] == wanted:
                    return True
    return False


def reject_save(api, node):
    request = Request(api.base + api.admin_path + "server/manage/save", method="POST",
                      headers={"Authorization": api.authorization, "Accept": "application/json", "Content-Type": "application/json"},
                      data=json.dumps(node).encode())
    try:
        with urlopen(request, timeout=10):
            raise CheckFailed("Panel accepted an incomplete Hysteria2 certificate source")
    except HTTPError as error:
        require(error.code == 422, "Certificate validation did not return HTTP 422")
        body = json.loads(error.read())
        require("cert_config" in json.dumps(body), "Certificate validation omitted its field error")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--xboard-node", required=True)
    parser.add_argument("--mihomo", required=True)
    parser.add_argument("--kernel", choices=("singbox", "xray"), default="singbox")
    args = parser.parse_args()
    require(os.environ.get("SIMPLEXBOARD_E2E") == "1", "Use tests/Support/run-stack.py")
    base = os.environ["E2E_BASE_URL"]
    require(urlparse(base).hostname == "127.0.0.1", "Disposable panel must use loopback")
    binaries = {name: shutil.which(getattr(args, name)) for name in ("xboard_node", "mihomo")}
    require(all(binaries.values()), "Required test executable is unavailable")
    require(shutil.which("openssl") and shutil.which("curl"), "OpenSSL and curl are required")
    work = Path(os.environ["E2E_WORK_DIR"]) / "hysteria-certificates"
    work.mkdir(mode=0o700)
    artifacts = Path(os.environ["E2E_ARTIFACT_DIR"])
    result = {"scope": "actual machine and Mihomo through local Hysteria2 UDP", "kernel": args.kernel, "status": "running",
              "xboard_node_sha256": hashlib.sha256(Path(binaries["xboard_node"]).read_bytes()).hexdigest(), "phases": []}
    api = API(base, os.environ["E2E_ADMIN_PATH"])
    login = api.request("POST", "/api/v1/passport/auth/login", authenticated=False,
                        data={"email": os.environ["E2E_ADMIN_EMAIL"], "password": os.environ["E2E_ADMIN_PASSWORD"]})
    api.authorization = login["data"]["auth_data"]
    fixture = json.loads(Path(os.environ["E2E_FIXTURE_FILE"]).read_text())
    api.admin("user/ban", {"id": fixture["member"]["id"], "banned": True})
    for existing in fixture["nodes"]:
        api.admin("server/manage/update", {"id": existing["id"], "enabled": False})
    api.admin("config/save", {"server_ws_url": os.environ["E2E_WS_URL"], "server_ws_enable": True,
                             "server_push_interval": 10, "server_pull_interval": 10})

    cert, key = work / "localhost.pem", work / "localhost-key.pem"
    other_cert, other_key = work / "other.pem", work / "other-key.pem"
    for cert_path, key_path, domain in ((cert, key, "localhost"), (other_cert, other_key, "content.localhost")):
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                        "-subj", "/CN=" + domain, "-addext", "subjectAltName=DNS:" + domain,
                        "-keyout", str(key_path), "-out", str(cert_path)], check=True,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        key_path.chmod(0o600)
    malformed = work / "malformed.pem"
    private_write(malformed, b"not a PEM certificate")
    unreadable = work / "unreadable-key.pem"
    private_write(unreadable, key.read_bytes())
    unreadable.chmod(0)
    trust = work / "test-trust.pem"
    private_write(trust, cert.read_bytes() + other_cert.read_bytes())
    env = os.environ.copy()
    env["SSL_CERT_FILE"] = str(trust)
    processes = Processes(artifacts, env)
    origin = agent = None
    clients = []
    try:
        machine = api.admin("server/machine/save", {"name": "Hysteria certificate integration"})
        port, health_port = free_port(socket.SOCK_DGRAM), free_port()
        file_source = {"cert_mode": "file", "cert_file": str(cert), "key_file": str(key)}
        node = {"type": "hysteria", "name": "Hysteria certificate test", "host": "127.0.0.1",
                "port": str(port), "server_port": port, "machine_id": machine["id"], "show": True, "enabled": True,
                "protocol_settings": {"version": 2, "tls": {"server_name": "localhost", "allow_insecure": False},
                    "bandwidth": {"up": 100, "down": 100}, "obfs": {"open": False, "type": "salamander", "password": ""}},
                "cert_config": file_source}
        for source in (None, {"cert_mode": "none"}, {"cert_mode": "file", "cert_file": str(cert)}):
            invalid = copy.deepcopy(node)
            invalid["cert_config"] = source
            invalid["protocol_settings"]["tls"]["allow_insecure"] = True
            reject_save(api, invalid)
        result["phases"].append({"phase": "API_rejection", "skip_client_verification": True, "rejected_sources": 3})
        api.admin("server/manage/save", node)
        node["id"] = next(item["id"] for item in api.admin("server/manage/getNodes", method="GET") if item["name"] == node["name"])
        member = api.admin("user/create", {"name": "Hysteria certificate member", "access_mode": "restricted", "server_ids": [node["id"]]})
        served = api.request("GET", "/api/v2/server/config", query={"token": fixture["server_token"], "node_id": node["id"]}, authenticated=False)
        require(served.get("cert_config") == file_source, "Node API changed the configured certificate source")
        expected = ("Hysteria certificate proof " + secrets.token_hex(16)).encode()

        class OriginHandler(BaseHTTPRequestHandler):
            def do_GET(self):
                self.send_response(200)
                self.send_header("Content-Length", str(len(expected)))
                self.end_headers()
                self.wfile.write(expected)

            def log_message(self, *_):
                pass

        origin = ThreadingHTTPServer(("127.0.0.1", 0), OriginHandler)
        threading.Thread(target=origin.serve_forever, daemon=True).start()
        route = {"ip_cidr": ["127.0.0.1/32"], "port": [origin.server_port], "network": ["tcp"], "outbound": "direct"}
        if args.kernel == "xray":
            route = {"type": "field", "ip": ["127.0.0.1/32"], "port": str(origin.server_port), "network": "tcp", "outboundTag": "direct"}
        config = {"kernel": {"type": args.kernel, "log_level": "error", "config_dir": str(work / "kernel"), "custom_route": [route]},
                  "log": {"level": "info"}, "health_port": health_port,
                  "cert": {"cert_dir": str(work / "cert-cache")},
                  "node": {"push_interval": 5, "pull_interval": 2, "track_interval": 1},
                  "ws": {"status_interval": 1, "backoff_initial": 1, "backoff_max": 2},
                  "instances": [{"panel": {"url": base}, "machine": {"machine_id": machine["id"], "token": machine["token"]}, "health_port": health_port}]}
        config_path = work / "agent.json"
        private_write(config_path, config)
        argv = [binaries["xboard_node"], "-c", str(config_path)]
        if os.geteuid() == 0:
            require(shutil.which("setpriv"), "Root execution needs setpriv for the unreadable-file check")
            argv = ["setpriv", "--bounding-set=-dac_override,-dac_read_search", "--", *argv]
        agent = processes.start("hysteria-certificate-agent", argv)
        wait_tcp(health_port, agent)
        pid = agent.pid

        def wait_ready():
            eventually(lambda: health(health_port, "/readyz")[0] == 200 and udp_listening(port), "Hysteria UDP listener and core readiness", agent)
            eventually(lambda: panel_node(api, node["id"]).get("core_ready") is True, "panel core readiness", agent)

        def create_client(label):
            socks_port = free_port()
            template = {"socks-port": socks_port, "allow-lan": False, "bind-address": "127.0.0.1", "mode": "rule", "log-level": "error",
                        "proxies": [], "proxy-groups": [], "rules": ["MATCH," + node["name"]], "dns": {"enable": False}, "profile": {"store-selected": False}}
            api.admin("config/save", {"subscribe_template_clashmeta": json.dumps(template), "show_protocol_to_server_enable": False})
            raw = api.raw("GET", "/s/" + member["token"], query={"flag": "meta"}, authenticated=False, user_agent="Clash.Meta/1.19.29")
            require(b"hysteria2" in raw and node["name"].encode() in raw, "Subscription omitted Hysteria2")
            require(key.read_bytes().strip() not in raw, "Subscription exposed the server private key")
            path, directory = work / (label + ".yaml"), work / label
            directory.mkdir(mode=0o700)
            private_write(path, raw)
            client = processes.start("hysteria-client-" + label, [binaries["mihomo"], "-f", str(path), "-d", str(directory)])
            wait_tcp(socks_port, client)
            clients.append(client)
            return client, socks_port

        def traffic(client_pair):
            client, socks_port = client_pair
            require(client.poll() is None, "Mihomo exited")
            sent = subprocess.run(["curl", "--silent", "--fail", "--noproxy", "", "--socks5-hostname", f"127.0.0.1:{socks_port}",
                                   "--connect-timeout", "2", "--max-time", "4", f"http://127.0.0.1:{origin.server_port}/proof"],
                                  stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=6)
            return sent.returncode == 0 and sent.stdout == expected

        def save_source(source, insecure=False):
            node["cert_config"] = source
            node["protocol_settings"]["tls"]["allow_insecure"] = insecure
            api.admin("server/manage/save", node)

        def expect_error(label, source, message):
            nonlocal verified_client
            save_source(source)
            def rejected():
                current = panel_node(api, node["id"])
                core = current.get("core_status") or {}
                return current.get("core_ready") is False and message in (core.get("error") or "")
            eventually(rejected, "specific certificate failure: " + label, agent)
            eventually(lambda: not udp_listening(port) and health(health_port, "/readyz")[0] == 503, "old UDP core stopped: " + label, agent)
            require(health(health_port, "/healthz")[0] == 200, "Certificate failure stopped the machine process")
            current = panel_node(api, node["id"])
            require(current.get("control_online") is True and current.get("machine_online") is True,
                    "Certificate failure disconnected the healthy control plane")
            result["phases"].append({"phase": label, "ready": False, "udp_listening": False, "error_contains": message,
                                     "control_online": True, "machine_online": True})
            save_source(file_source)
            wait_ready()
            # Verify a fresh TLS/QUIC handshake after deliberately stopping the core.
            # The machine process remains running; old client sessions are disposable.
            Processes.stop(verified_client[0])
            verified_client = create_client("recovered-" + label)
            eventually(lambda: traffic(verified_client), "new connection after certificate correction: " + label, agent)
            require(agent.pid == pid, "Recovery restarted the machine process")
            result["phases"][-1]["recovery"] = {"same_machine_process": True, "fresh_tls_quic_client": True, "http_request": True}

        wait_ready()
        verified_client = create_client("verified-file")
        eventually(lambda: traffic(verified_client), "actual Hysteria2 request with certificate verification enabled", agent)
        result["phases"].append({"phase": "file_source", "ready": True, "udp_listening": True, "client_tls_verification": True, "http_request": True})
        for label, source, message in (
            ("missing_certificate", {**file_source, "cert_file": str(work / "missing.pem")}, "cert file: open "),
            ("missing_private_key", {**file_source, "key_file": str(work / "missing-key.pem")}, "key file: open "),
            ("unreadable_private_key", {**file_source, "key_file": str(unreadable)}, "permission denied"),
            ("invalid_certificate_pem", {**file_source, "cert_file": str(malformed)}, "failed to find any PEM data in certificate input"),
            ("mismatched_private_key", {**file_source, "key_file": str(other_key)}, "private key does not match public key"),
        ):
            expect_error(label, source, message)
        node["protocol_settings"]["tls"]["server_name"] = "content.localhost"
        save_source({"cert_mode": "content", "cert_content": other_cert.read_text(), "key_content": other_key.read_text()})
        content_client = create_client("verified-content")
        eventually(lambda: traffic(content_client), "new inline PEM certificate and matching SNI", agent)
        wait_ready()
        result["phases"].append({"phase": "content_source", "ready": True, "udp_listening": True, "client_tls_verification": True, "http_request": True})
        # Keep the content-mode cache: switching source must generate a self certificate.
        node["protocol_settings"]["tls"]["server_name"] = "self.localhost"
        save_source({"cert_mode": "self", "domain": "self.localhost"}, insecure=True)
        eventually(lambda: b"self-signed certificate generated" in (artifacts / "hysteria-certificate-agent.log").read_bytes(),
                   "fresh self-signed certificate generation", agent)
        wait_ready()
        self_client = create_client("self-signed")
        eventually(lambda: traffic(self_client), "self-signed certificate with explicit client verification skip", agent)
        result["phases"].append({"phase": "self_source", "ready": True, "udp_listening": True, "skip_client_verification": True, "http_request": True})
        # Changing the self domain must replace the cached certificate too.
        agent_log = artifacts / "hysteria-certificate-agent.log"
        generated_before = agent_log.read_bytes().count(b"self-signed certificate generated")
        node["protocol_settings"]["tls"]["server_name"] = "changed-self.localhost"
        save_source({"cert_mode": "self", "domain": "changed-self.localhost"}, insecure=True)
        eventually(lambda: agent_log.read_bytes().count(b"self-signed certificate generated") > generated_before,
                   "self certificate regenerated for the changed domain", agent)
        wait_ready()
        changed_self_client = create_client("changed-self")
        eventually(lambda: traffic(changed_self_client), "changed self domain remains connectable", agent)
        result["phases"].append({"phase": "self_domain_change", "generated_new_certificate": True, "http_request": True})
        result["status"] = "passed"
        print(json.dumps(result), flush=True)
        return 0
    except Exception as error:
        result["status"] = "failed"
        result["error"] = str(error) if isinstance(error, CheckFailed) else type(error).__name__
        raise
    finally:
        unreadable.chmod(0o600)
        processes.close()
        if origin is not None:
            origin.shutdown()
            origin.server_close()
        (artifacts / "hysteria-certificates.json").write_text(json.dumps(result, indent=2) + "\n")


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (CheckFailed, subprocess.SubprocessError, OSError) as error:
        print("FAIL: " + (str(error) if isinstance(error, CheckFailed) else type(error).__name__), file=sys.stderr)
        raise SystemExit(1)

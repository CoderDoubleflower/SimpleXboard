#!/usr/bin/env python3
"""Run unchanged mihomo subscriptions through two authenticated loopback SOCKS hops.

Run through tests/Support/run-stack.py with --mihomo /path/to/mihomo.
Uses Python's standard library for the entry, exit, HTTP origin and rule provider.
No VPS, external proxy or production database is contacted. Stash's emitted YAML
is inspected, while the real data-plane verification uses the mihomo executable.
"""
from __future__ import annotations

import argparse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import secrets
import selectors
import shutil
import socket
import socketserver
import struct
import subprocess
import sys
import threading
from urllib.parse import urlparse

from protocol_connections import API, CheckFailed, Processes, free_port, parse_yaml, private_write, require, wait_tcp


def receive(sock, length):
    value = bytearray()
    while len(value) < length:
        part = sock.recv(length - len(value))
        if not part:
            raise ConnectionError("Incomplete local SOCKS frame")
        value.extend(part)
    return bytes(value)


class SocksServer(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


class SocksFixture:
    def __init__(self, credential, destinations, events, port=0):
        self.credential = credential.encode()
        self.destinations = destinations
        self.events = events
        fixture = self

        class Handler(socketserver.BaseRequestHandler):
            def handle(self):
                client = self.request
                client.settimeout(8)
                remote = None
                try:
                    version, count = receive(client, 2)
                    methods = receive(client, count)
                    if version != 5 or 2 not in methods:
                        client.sendall(b"\x05\xff")
                        return
                    client.sendall(b"\x05\x02")
                    version, size = receive(client, 2)
                    username = receive(client, size)
                    password = receive(client, receive(client, 1)[0])
                    if version != 1 or username != fixture.credential or password != fixture.credential:
                        client.sendall(b"\x01\x01")
                        return
                    client.sendall(b"\x01\x00")
                    version, command, _, address_type = receive(client, 4)
                    if version != 5 or command != 1:
                        return
                    if address_type == 1:
                        host = socket.inet_ntoa(receive(client, 4))
                    elif address_type == 3:
                        host = receive(client, receive(client, 1)[0]).decode("ascii")
                    elif address_type == 4:
                        host = socket.inet_ntop(socket.AF_INET6, receive(client, 16))
                    else:
                        return
                    port = struct.unpack("!H", receive(client, 2))[0]
                    # Each fixture can connect only to its one intended next hop.
                    destination = fixture.destinations.get((host, port))
                    if destination is None:
                        client.sendall(b"\x05\x02\x00\x01" + b"\x00" * 6)
                        return
                    remote = socket.create_connection(destination, timeout=3)
                    event = {"requested_host": host, "port": port, "authenticated": True, "sent_bytes": 0, "received_bytes": 0}
                    fixture.events.append(event)
                    client.sendall(b"\x05\x00\x00\x01" + b"\x00" * 6)
                    client.settimeout(None)
                    remote.settimeout(None)
                    with selectors.DefaultSelector() as selected:
                        selected.register(client, selectors.EVENT_READ, remote)
                        selected.register(remote, selectors.EVENT_READ, client)
                        while True:
                            ready = selected.select(timeout=8)
                            if not ready:
                                return
                            for key, _ in ready:
                                part = key.fileobj.recv(65536)
                                if not part:
                                    return
                                event["sent_bytes" if key.fileobj is client else "received_bytes"] += len(part)
                                key.data.sendall(part)
                except (ConnectionError, OSError, UnicodeError):
                    return
                finally:
                    if remote:
                        remote.close()

        self.server = SocksServer(("127.0.0.1", port), Handler)
        self.port = self.server.server_address[1]
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    def close(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=2)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--mihomo", default=os.environ.get("MIHOMO_BIN", "mihomo"))
    args = parser.parse_args()
    require(os.environ.get("SIMPLEXBOARD_E2E") == "1", "Run through tests/Support/run-stack.py")
    require(urlparse(os.environ["E2E_BASE_URL"]).hostname == "127.0.0.1", "Panel fixture must be on loopback")
    mihomo = shutil.which(args.mihomo)
    require(mihomo is not None, "mihomo binary is required")
    require(shutil.which("curl") is not None, "curl is required")
    artifacts = Path(os.environ["E2E_ARTIFACT_DIR"])
    work = Path(os.environ["E2E_WORK_DIR"]) / "subscription-chains"
    work.mkdir(mode=0o700)
    report = {"status": "failed", "scope": "loopback-only", "client": "mihomo", "checks": []}
    processes = Processes(artifacts, os.environ.copy())
    origin = provider = entry = exit_hop = None
    entry_events, exit_events, origin_requests, provider_requests = [], [], [], []
    expected = ("SimpleXboard chain proof " + secrets.token_hex(16)).encode()
    try:
        api = API(os.environ["E2E_BASE_URL"], os.environ["E2E_ADMIN_PATH"])
        login = api.request("POST", "/api/v1/passport/auth/login", authenticated=False,
                            data={"email": os.environ["E2E_ADMIN_EMAIL"], "password": os.environ["E2E_ADMIN_PASSWORD"]})
        api.authorization = login["data"]["auth_data"]
        fixture = json.loads(Path(os.environ["E2E_FIXTURE_FILE"]).read_text())
        for node in fixture["nodes"]:
            api.admin("server/manage/update", {"id": node["id"], "enabled": False})
        member = api.admin("user/create", {"name": "Subscription chain verification", "access_mode": "all"})

        class OriginHandler(BaseHTTPRequestHandler):
            def do_GET(self):
                origin_requests.append(self.path)
                self.send_response(200)
                self.send_header("Content-Length", str(len(expected)))
                self.end_headers()
                self.wfile.write(expected)

            def log_message(self, *_):
                pass

        class ProviderHandler(BaseHTTPRequestHandler):
            def do_GET(self):
                provider_requests.append(self.path)
                body = b"payload:\n  - DOMAIN,chain-provider.example\n"
                self.send_response(200)
                self.send_header("Content-Type", "text/yaml")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def log_message(self, *_):
                pass

        # Separate loopback IPs avoid the subscription host's forced DIRECT rule.
        origin = ThreadingHTTPServer(("127.0.0.2", 0), OriginHandler)
        provider = ThreadingHTTPServer(("127.0.0.3", 0), ProviderHandler)
        for server in (origin, provider):
            threading.Thread(target=server.serve_forever, daemon=True).start()
        exit_destinations = {(host, origin.server_port): ("127.0.0.2", origin.server_port)
                             for host in ["chain-custom.example", "chain-provider.example", "127.0.0.2"]}
        exit_hop = SocksFixture(member["uuid"], exit_destinations, exit_events)
        entry_destinations = {("127.0.0.1", exit_hop.port): ("127.0.0.1", exit_hop.port)}
        entry = SocksFixture(member["uuid"], entry_destinations, entry_events)
        for name, port in [("Chain entry", entry.port), ("Chain exit", exit_hop.port)]:
            api.admin("server/manage/save", {"type": "socks", "name": name, "host": "127.0.0.1", "port": str(port),
                      "server_port": port, "show": True, "enabled": True, "protocol_settings": {"tls": 0}})
        nodes = {node["name"]: node for node in api.admin("server/manage/getNodes", method="GET")}
        entry_id, exit_id = nodes["Chain entry"]["id"], nodes["Chain exit"]["id"]
        client_port = free_port()
        profile = api.request("GET", api.admin_path + "subscription-template/fetch", query={"client": "mihomo"})["data"]["profile"]
        profile.update({
            "base_yaml": json.dumps({"socks-port": client_port, "allow-lan": False, "bind-address": "127.0.0.1", "mode": "rule", "log-level": "warning",
                                     "dns": {"enable": False}, "proxies": [], "proxy-groups": [], "rules": ["MATCH,REJECT"]}),
            "preset": "original", "selected_rules": [], "rule_format": "yaml",
            "rule_providers": [{"name": "loopback", "url": f"http://127.0.0.3:{provider.server_port}/rules.yaml",
                                "behavior": "classical", "format": "yaml", "interval": 3600, "policy": f"node:{exit_id}"}],
            "custom_rules": ["IP-CIDR,127.0.0.3/32,DIRECT,no-resolve", f"DOMAIN,chain-custom.example,node:{exit_id}"],
            "chain_rules": [{"exit_nodes": [f"node:{exit_id}"], "entry": f"node:{entry_id}"}],
        })
        api.admin("subscription-template/save", {"client": "mihomo", "profile": profile})
        raw = api.raw("GET", "/s/" + member["token"], query={"flag": "mihomo"}, authenticated=False, user_agent="Mihomo/1.19.29")
        config = parse_yaml(raw)
        proxies = {proxy["name"]: proxy for proxy in config["proxies"]}
        require(proxies["Chain exit"]["dialer-proxy"] == "Chain entry", "Export reversed or lost the proxy chain")
        require("dialer-proxy" not in proxies["Chain entry"], "Entry unexpectedly points back to the exit")
        require(config["rules"].index("DOMAIN,chain-custom.example,Chain exit") < config["rules"].index("RULE-SET,loopback,Chain exit"),
                "Custom rules lost priority over rule providers")
        require(config["rules"][-1] == "MATCH,REJECT", "Unmatched traffic should fail closed")
        path, directory = work / "subscription.yaml", work / "mihomo"
        directory.mkdir()
        private_write(path, raw)
        with (artifacts / "subscription-chain-core-check.log").open("wb") as log:
            checked = subprocess.run([mihomo, "-t", "-d", str(directory), "-f", str(path)], stdout=log, stderr=subprocess.STDOUT)
        require(checked.returncode == 0, "mihomo rejected the unchanged subscription YAML")
        client = processes.start("subscription-chain-client", [mihomo, "-d", str(directory), "-f", str(path)])
        wait_tcp(client_port, client)

        def request(host, succeeds=True):
            before = len(origin_requests)
            command = ["curl", "--silent", "--show-error", "--fail", "--noproxy", "", "--socks5-hostname", f"127.0.0.1:{client_port}",
                       "--connect-timeout", "2", "--max-time", "4", f"http://{host}:{origin.server_port}/proof"]
            sent = subprocess.run(command, capture_output=True, timeout=6)
            if succeeds:
                if sent.returncode != 0 or sent.stdout != expected:
                    report['failed_request'] = {'curl_exit': sent.returncode, 'stderr': sent.stderr.decode(errors='replace')[:1000],
                                                'entry_events': entry_events, 'exit_events': exit_events, 'provider_requests': provider_requests}
                require(sent.returncode == 0 and sent.stdout == expected, "Actual two-hop HTTP request failed")
                require(len(origin_requests) == before + 1, "Successful request did not reach the loopback origin exactly once")
            else:
                require(sent.returncode != 0 and len(origin_requests) == before, "Request bypassed an unavailable hop or terminal REJECT")

        request("chain-custom.example")
        request("chain-provider.example")
        require(len(entry_events) >= 2 and len(exit_events) >= 2, "Both authenticated hops were not observed")
        require(provider_requests, "Client never retrieved the configured loopback rule provider")
        report["checks"].extend(["custom_rule_two_hops", "rule_provider_two_hops", "authenticated_entry_to_exit_order"])
        request("unmatched.example", succeeds=False)
        report["checks"].append("terminal_reject")
        entry_port = entry.port
        entry.close()
        entry = None
        request("chain-custom.example", succeeds=False)
        report["checks"].append("stopped_entry_cannot_be_bypassed")
        entry = SocksFixture(member["uuid"], entry_destinations, entry_events, port=entry_port)
        request("chain-custom.example")
        exit_hop.close()
        exit_hop = None
        request("chain-custom.example", succeeds=False)
        report["checks"].append("stopped_exit_cannot_be_bypassed")

        api.admin("subscription-template/save", {"client": "stash", "profile": profile})
        stash_raw = api.raw("GET", "/s/" + member["token"], query={"flag": "stash"}, authenticated=False)
        stash = {proxy["name"]: proxy for proxy in parse_yaml(stash_raw)["proxies"]}
        require(stash["Chain exit"]["dialer-proxy"] == "Chain entry", "Stash lost the native dialer-proxy chain")
        report["checks"].append("stash_native_chain_fields")
        report.update({"status": "passed", "subscription_executed_unchanged": True, "entry_connections": len(entry_events),
                       "exit_connections": len(exit_events), "origin_requests": len(origin_requests), "provider_fetches": len(provider_requests),
                       "mihomo_version": subprocess.check_output([mihomo, "-v"], text=True).strip().splitlines()[0]})
        print(json.dumps(report), flush=True)
        return 0
    except CheckFailed as error:
        report["error"] = str(error)
        raise
    finally:
        processes.close()
        for hop in (entry, exit_hop):
            if hop:
                hop.close()
        for server in (origin, provider):
            if server:
                server.shutdown()
                server.server_close()
        (artifacts / "subscription-chain-results.json").write_text(json.dumps(report, indent=2) + "\n")


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except CheckFailed as error:
        print("Subscription chain verification failed: " + str(error), file=sys.stderr)
        raise SystemExit(1)

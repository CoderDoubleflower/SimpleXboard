#!/usr/bin/env python3
"""Verify managed exits with the real panel, machine agent, six cores and Mihomo.

Requires a disposable run-stack.py panel and 198.51.100.10/32 assigned to lo.
All endpoints are local. HTTP/UDP origins report the socket peer address so a
successful request alone cannot hide an accidentally direct route. Secrets and
generated subscriptions stay in private temporary files, outside CI evidence.
"""
from __future__ import annotations

import argparse
import base64
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import struct
import subprocess
import sys
import threading
from urllib.parse import quote, urlencode, urlparse
import uuid

from protocol_connections import API, CheckFailed, Processes, free_port, private_write, require, wait_tcp
from node_core_lifecycle import HTTPRelay, WSRelay, eventually, health, listening, panel_node


ORIGIN_IP = "198.51.100.10"
DIRECT_IP = "127.0.0.10"


def receive_exact(sock, length):
    result = b""
    while len(result) < length:
        part = sock.recv(length - len(result))
        if not part:
            raise OSError("SOCKS control connection closed")
        result += part
    return result


def socks_address(sock, atyp):
    if atyp == 1:
        host = socket.inet_ntoa(receive_exact(sock, 4))
    elif atyp == 4:
        host = socket.inet_ntop(socket.AF_INET6, receive_exact(sock, 16))
    elif atyp == 3:
        host = receive_exact(sock, receive_exact(sock, 1)[0]).decode("ascii")
    else:
        raise OSError("Invalid SOCKS address type")
    return host, struct.unpack("!H", receive_exact(sock, 2))[0]


def udp_peer(socks_port, origin_port):
    """Use RFC 1928 UDP ASSOCIATE, keeping its TCP control session alive."""
    try:
        with socket.create_connection(("127.0.0.1", socks_port), timeout=3) as control, socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as udp:
            udp.bind(("127.0.0.1", 0))
            udp.settimeout(3)
            control.sendall(b"\x05\x01\x00")
            if receive_exact(control, 2) != b"\x05\x00":
                return None
            control.sendall(b"\x05\x03\x00\x01" + socket.inet_aton("127.0.0.1") + struct.pack("!H", udp.getsockname()[1]))
            header = receive_exact(control, 4)
            if header[:2] != b"\x05\x00":
                return None
            relay_host, relay_port = socks_address(control, header[3])
            if relay_host in ("0.0.0.0", "::"):
                relay_host = "127.0.0.1"
            nonce = secrets.token_hex(16)
            payload = b"\x00\x00\x00\x01" + socket.inet_aton(ORIGIN_IP) + struct.pack("!H", origin_port) + nonce.encode()
            udp.sendto(payload, (relay_host, relay_port))
            packet, _ = udp.recvfrom(65535)
            if len(packet) < 10 or packet[:3] != b"\x00\x00\x00":
                return None
            if packet[3] == 1:
                offset = 10
            elif packet[3] == 4:
                offset = 22
            elif packet[3] == 3:
                offset = 7 + packet[4]
            else:
                return None
            response = json.loads(packet[offset:])
            return response.get("peer") if response.get("nonce") == nonce else None
    except (OSError, ValueError, UnicodeError):
        return None


class Origins:
    def __init__(self):
        class Handler(BaseHTTPRequestHandler):
            def do_GET(self):
                body = json.dumps({"peer": self.client_address[0], "nonce": self.path.rsplit("/", 1)[-1]}).encode()
                self.send_response(200)
                self.send_header("Content-Length", str(len(body)))
                self.send_header("Connection", "close")
                self.end_headers()
                self.wfile.write(body)

            def log_message(self, *_):
                pass

        try:
            self.http = ThreadingHTTPServer((ORIGIN_IP, 0), Handler)
            self.udp = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            self.udp.bind((ORIGIN_IP, 0))
        except OSError:
            raise CheckFailed("Assign 198.51.100.10/32 to lo before this isolated test") from None
        self.udp.settimeout(0.2)
        self.stopped = threading.Event()
        self.http_thread = threading.Thread(target=self.http.serve_forever, daemon=True)
        self.udp_thread = threading.Thread(target=self.serve_udp, daemon=True)
        self.http_thread.start()
        self.udp_thread.start()

    def serve_udp(self):
        while not self.stopped.is_set():
            try:
                data, peer = self.udp.recvfrom(65535)
                response = json.dumps({"peer": peer[0], "nonce": data.decode("ascii")}).encode()
                self.udp.sendto(response, peer)
            except (OSError, UnicodeError):
                continue

    def close(self):
        self.stopped.set()
        self.udp.close()
        self.http.shutdown()
        self.http.server_close()
        self.http_thread.join(timeout=3)
        self.udp_thread.join(timeout=3)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--xboard-node", required=True)
    parser.add_argument("--kernel-fixture", required=True)
    parser.add_argument("--mihomo", required=True)
    args = parser.parse_args()
    require(os.environ.get("SIMPLEXBOARD_E2E") == "1", "Use tests/Support/run-stack.py")
    base = os.environ["E2E_BASE_URL"]
    require(urlparse(base).hostname == "127.0.0.1", "Disposable panel must use loopback")
    binaries = {name: shutil.which(value) for name, value in vars(args).items()}
    require(all(binaries.values()), "Required test executable is unavailable")
    require(shutil.which("openssl") and shutil.which("curl"), "OpenSSL and curl are required")
    work = Path(os.environ["E2E_WORK_DIR"]) / "outbound-connections"
    work.mkdir(mode=0o700)
    artifacts = Path(os.environ["E2E_ARTIFACT_DIR"])
    result = {"scope": "local documentation-address origin", "status": "running", "protocols": [], "phases": []}
    api = API(base, os.environ["E2E_ADMIN_PATH"])
    login = api.request("POST", "/api/v1/passport/auth/login", authenticated=False,
                        data={"email": os.environ["E2E_ADMIN_EMAIL"], "password": os.environ["E2E_ADMIN_PASSWORD"]})
    api.authorization = login["data"]["auth_data"]
    fixture = json.loads(Path(os.environ["E2E_FIXTURE_FILE"]).read_text())
    api.admin("user/ban", {"id": fixture["member"]["id"], "banned": True})
    for node in fixture["nodes"]:
        api.admin("server/manage/update", {"id": node["id"], "enabled": False})
    cert, key = work / "localhost.pem", work / "localhost-key.pem"
    subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                    "-subj", "/CN=localhost", "-addext", "subjectAltName=DNS:localhost",
                    "-keyout", str(key), "-out", str(cert)], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    key.chmod(0o600)
    env = os.environ.copy()
    env["SSL_CERT_FILE"] = str(cert)
    # Embedded core logs stay terse; no credentials/configuration are printed.
    processes = Processes(artifacts, env)
    http = ws = origin = agent = None
    nodes, clients, external, secret_values = [], {}, {}, []
    health_port = free_port()
    try:
        origin = Origins()
        http, ws = HTTPRelay(base), WSRelay(os.environ["E2E_WS_URL"])
        api.admin("config/save", {"server_ws_url": ws.url, "server_ws_enable": True,
                                 "server_push_interval": 10, "server_pull_interval": 30})
        ingress_pair = api.admin("server/manage/generateRealityKeyPair", require_no_store=True)
        exit_pair = api.admin("server/manage/generateRealityKeyPair", require_no_store=True)
        secret_values += [ingress_pair["private_key"], exit_pair["private_key"], exit_pair["public_key"]]
        decoy_port = free_port()
        decoy = processes.start("outbound-tls-decoy", ["openssl", "s_server", "-accept", f"127.0.0.1:{decoy_port}",
                                  "-cert", str(cert), "-key", str(key), "-tls1_3", "-alpn", "h2,http/1.1", "-www", "-quiet"])
        wait_tcp(decoy_port, decoy)
        server_tls = {"enabled": True, "server_name": "localhost", "certificate_path": str(cert), "key_path": str(key)}

        def start_external(label, protocol, exit_ip, host="127.0.0.1", credentials=None):
            credentials = credentials or {}
            port = free_port(socket.SOCK_DGRAM if protocol == "hysteria2" else socket.SOCK_STREAM)
            user_id = credentials.get("uuid", str(uuid.uuid4()))
            password = credentials.get("password", secrets.token_urlsafe(24))
            username = credentials.get("username", "fixture-user")
            secret_values.extend([user_id, password])
            inbound = {"type": protocol, "tag": "fixture-in", "listen": host, "listen_port": port}
            tls = dict(server_tls)
            if protocol == "vless":
                inbound.update({"users": [{"name": "fixture-user", "uuid": user_id, "flow": "xtls-rprx-vision"}],
                                "tls": {"enabled": True, "server_name": "localhost", "reality": {"enabled": True,
                                  "handshake": {"server": "127.0.0.1", "server_port": decoy_port},
                                  "private_key": exit_pair["private_key"], "short_id": [exit_pair["short_id"]]}}})
                query = {"type": "tcp", "security": "reality", "encryption": "none", "flow": "xtls-rprx-vision",
                         "sni": "localhost", "fp": "chrome", "pbk": exit_pair["public_key"], "sid": exit_pair["short_id"]}
                uri = f"vless://{user_id}@{host}:{port}?{urlencode(query)}#{label}"
            elif protocol == "hysteria2":
                obfs = secrets.token_urlsafe(24)
                secret_values.append(obfs)
                inbound.update({"users": [{"name": "fixture-user", "password": password}], "tls": tls,
                                "obfs": {"type": "salamander", "password": obfs}})
                uri = f"hy2://{quote(password, safe='')}@{host}:{port}?{urlencode({'sni': 'localhost', 'obfs': 'salamander', 'obfs-password': obfs})}#{label}"
            elif protocol == "socks":
                inbound["users"] = [{"username": username, "password": password}]
                uri = f"socks5://{quote(username, safe='')}:{quote(password, safe='')}@{host}:{port}#{label}"
            elif protocol == "shadowsocks":
                inbound.update({"method": "aes-128-gcm", "password": password})
                auth = base64.urlsafe_b64encode(("aes-128-gcm:" + password).encode()).rstrip(b"=").decode()
                uri = f"ss://{auth}@{host}:{port}#{label}"
            elif protocol == "trojan":
                inbound.update({"users": [{"name": "fixture-user", "password": password}], "tls": tls})
                uri = f"trojan://{quote(password, safe='')}@{host}:{port}?sni=localhost#{label}"
            elif protocol == "vmess":
                inbound.update({"users": [{"name": "fixture-user", "uuid": user_id, "alterId": 0}], "tls": tls})
                shared = {"v": "2", "ps": label, "add": host, "port": str(port), "id": user_id,
                          "aid": "0", "scy": "auto", "net": "tcp", "type": "none", "tls": "tls", "sni": "localhost"}
                uri = "vmess://" + base64.b64encode(json.dumps(shared).encode()).decode()
            else:
                raise CheckFailed("Unknown fixture protocol")
            cfg = {"log": {"level": "error"}, "inbounds": [inbound],
                   "outbounds": [{"type": "direct", "tag": "direct", "inet4_bind_address": exit_ip}], "route": {"final": "direct"}}
            path = work / (label + ".json")
            private_write(path, cfg)
            process = processes.start("outbound-" + label, [binaries["kernel_fixture"], "-c", str(path)])
            log = artifacts / ("outbound-" + label + ".log")
            eventually(lambda: b"fixture-ready" in log.read_bytes(), "external fixture ready", process)
            if protocol != "hysteria2":
                wait_tcp_host(host, port, process)
            return {"process": process, "uri": uri, "host": host, "port": port,
                    "password": password, "username": username, "exit_ip": exit_ip}

        for index, protocol in enumerate(("vless", "hysteria2", "socks", "shadowsocks", "trojan", "vmess"), 21):
            endpoint = start_external("exit-" + protocol, protocol, "127.0.0." + str(index))
            imported = api.admin("server/outbound/import", {"uri": endpoint["uri"]}, require_no_store=True)
            require(imported["type"] == protocol, "Share-link import selected the wrong protocol")
            saved = api.admin("server/outbound/save", imported)
            endpoint.update({"id": saved["id"], "record": imported})
            external[protocol] = endpoint

        # Advertise actual node capability before binding an exit to a new node.
        machine = api.admin("server/machine/save", {"name": "Outbound integration machine"})
        agent_config = {"kernel": {"type": "singbox", "log_level": "error", "config_dir": str(work / "kernel"),
                                   "custom_outbound": [{"type": "direct", "tag": "direct", "inet4_bind_address": DIRECT_IP}]},
                        "log": {"level": "info"}, "health_port": health_port,
                        "node": {"push_interval": 5, "pull_interval": 2, "track_interval": 1},
                        "ws": {"status_interval": 1, "backoff_initial": 1, "backoff_max": 2},
                        "instances": [{"panel": {"url": http.url}, "machine": {"machine_id": machine["id"], "token": machine["token"]},
                                       "health_port": health_port}]}
        config_path = work / "agent.json"
        private_write(config_path, agent_config)
        agent_argv = [binaries["xboard_node"], "-c", str(config_path)]
        agent = processes.start("outbound-agent", agent_argv)
        wait_tcp(health_port, agent)
        eventually(lambda: any(row["id"] == machine["id"] and row.get("managed_outbound_supported") is True
                               for row in api.admin("server/machine/fetch", method="GET")), "machine capability advertisement", agent)
        for name, selected in (("Managed ingress", external["vless"]["id"]), ("Direct control", None), ("Hidden consumer", external["socks"]["id"])):
            port = free_port()
            node = {"type": "vless", "name": name, "host": "127.0.0.1", "port": str(port), "server_port": port,
                    "machine_id": machine["id"], "outbound_id": selected, "show": True, "enabled": True,
                    "protocol_settings": {"tls": 2, "network": "tcp", "flow": "xtls-rprx-vision",
                       "utls": {"enabled": True, "fingerprint": "chrome"},
                       "reality_settings": {"server_name": "localhost", "server_port": decoy_port, "allow_insecure": False,
                          "private_key": ingress_pair["private_key"], "public_key": ingress_pair["public_key"], "short_id": ingress_pair["short_id"]}}}
            api.admin("server/manage/save", node)
            node["id"] = next(item["id"] for item in api.admin("server/manage/getNodes", method="GET") if item["name"] == name)
            nodes.append(node)
        main_node, control_node, hidden_node = nodes
        for node in nodes:
            eventually(lambda node=node: http.pulls(node["id"]) > 0, "empty node bootstrap", agent)
            require(not listening(node["server_port"]), "Node accepted connections before member authorization")
        member = api.admin("user/create", {"name": "Outbound integration member", "access_mode": "restricted", "server_ids": [node["id"] for node in nodes]})
        for node in nodes:
            eventually(lambda node=node: listening(node["server_port"]), "authorized ingress listener", agent)

        def subscription(node, socks_port):
            template = {"socks-port": socks_port, "allow-lan": False, "bind-address": "127.0.0.1", "mode": "rule", "log-level": "error",
                        "proxies": [], "proxy-groups": [], "rules": ["MATCH," + node["name"]], "dns": {"enable": False}, "profile": {"store-selected": False}}
            api.admin("config/save", {"subscribe_template_clashmeta": json.dumps(template), "show_protocol_to_server_enable": False})
            raw = api.raw("GET", "/s/" + member["token"], query={"flag": "meta"}, authenticated=False, user_agent="Clash.Meta/1.19.29")
            require(all(value.encode() not in raw for value in secret_values), "Subscription exposed an external exit credential")
            require(ingress_pair["public_key"].encode() in raw and b"xtls-rprx-vision" in raw, "Subscription omitted actual ingress configuration")
            return raw

        for node in nodes:
            socks_port = free_port()
            path = work / ("client-" + str(node["id"]) + ".yaml")
            private_write(path, subscription(node, socks_port))
            directory = work / ("mihomo-" + str(node["id"]))
            directory.mkdir(mode=0o700)
            with (artifacts / ("outbound-client-check-" + str(node["id"]) + ".log")).open("wb") as log:
                check = subprocess.run([binaries["mihomo"], "-t", "-f", str(path), "-d", str(directory)], env=env, stdout=log, stderr=subprocess.STDOUT)
            require(check.returncode == 0, "Mihomo rejected unchanged panel subscription")
            client = processes.start("outbound-client-" + str(node["id"]), [binaries["mihomo"], "-f", str(path), "-d", str(directory)])
            wait_tcp(socks_port, client)
            clients[node["id"]] = (client, socks_port)

        def tcp_peer(node):
            client, socks_port = clients[node["id"]]
            require(client.poll() is None, "Mihomo client exited")
            nonce = secrets.token_hex(16)
            target = f"http://{ORIGIN_IP}:{origin.http.server_port}/{nonce}"
            sent = subprocess.run(["curl", "--silent", "--fail", "--noproxy", "", "--socks5-hostname", f"127.0.0.1:{socks_port}",
                                   "--connect-timeout", "2", "--max-time", "4", target], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=6)
            if sent.returncode:
                return None
            try:
                response = json.loads(sent.stdout)
                return response.get("peer") if response.get("nonce") == nonce else None
            except (ValueError, UnicodeError):
                return None

        def expect_peer(node, peer, description):
            eventually(lambda: tcp_peer(node) == peer, description, agent, timeout=45)

        def select(node, outbound_id):
            node["outbound_id"] = outbound_id
            api.admin("server/manage/save", node)

        expect_peer(control_node, DIRECT_IP, "direct reference source address")
        expect_peer(main_node, external["vless"]["exit_ip"], "created node selected exit")
        result["phases"].append({"phase": "create_with_selected_exit", "machine_capability": True, "exit_peer": external["vless"]["exit_ip"], "direct_peer": DIRECT_IP})
        hidden_node["show"] = False
        api.admin("server/manage/save", hidden_node)
        require(hidden_node["name"].encode() not in subscription(main_node, free_port()), "Hidden node remained in a new subscription")
        http.block_poll = True
        for protocol, endpoint in external.items():
            before = ws.count(main_node["id"], "sync.config")
            select(main_node, endpoint["id"])
            # Selecting the already assigned first exit may be a no-op.
            if protocol != "vless":
                eventually(lambda: ws.count(main_node["id"], "sync.config") > before, "WS exit configuration delivery", agent)
            expect_peer(main_node, endpoint["exit_ip"], "actual TCP through " + protocol)
            expect_peer(control_node, DIRECT_IP, "unrelated direct ingress isolation")
            proof = {"protocol": protocol, "tcp_peer": endpoint["exit_ip"], "tcp": True}
            if protocol in ("socks", "hysteria2"):
                eventually(lambda: udp_peer(clients[main_node["id"]][1], origin.udp.getsockname()[1]) == endpoint["exit_ip"],
                           "actual UDP through " + protocol, agent, timeout=30)
                proof.update({"udp": True, "udp_peer": endpoint["exit_ip"]})
            result["protocols"].append(proof)
        result["phases"].append({"phase": "six_protocols_over_ws", "tcp_protocols": 6, "udp_protocols": 2, "rest_polling_blocked": True})
        for target in (None, external["socks"]["id"], external["hysteria2"]["id"], None):
            select(main_node, target)
            expected = DIRECT_IP if target is None else next(e["exit_ip"] for e in external.values() if e["id"] == target)
            expect_peer(main_node, expected, "direct to B to C to direct")
        result["phases"].append({"phase": "direct_B_C_direct", "actual_source_changes": True})

        # One shared record, two consumers including a node hidden from subscription.
        shared = external["socks"]
        select(main_node, shared["id"])
        for node in (main_node, hidden_node):
            expect_peer(node, shared["exit_ip"], "shared original exit")

        def replace_shared(label, address, peer):
            replacement = start_external(label, "socks", peer, host=address)
            record = dict(shared["record"])
            record["settings"] = dict(record["settings"], server=address, server_port=replacement["port"],
                                      username=replacement["username"], password=replacement["password"])
            record["id"] = shared["id"]
            api.admin("server/outbound/save", record)
            shared["record"] = record
            return replacement

        replaced = replace_shared("replacement-ws", "127.0.0.2", "127.0.0.27")
        for node in (main_node, hidden_node):
            expect_peer(node, replaced["exit_ip"], "shared exit address and password update via WS")
        result["phases"].append({"phase": "shared_record_update_ws", "consumers": 2, "hidden_consumer": True, "same_outbound_id": True,
                                  "address_and_password_changed": True, "exit_peer": replaced["exit_ip"]})

        # Cut WS, then make a further shared edit. Only REST can carry it.
        http.block_poll = False
        ws.set_enabled(False)
        api.admin("config/save", {"server_ws_enable": False})
        def config_pulls(node_id):
            with http.lock:
                return http.successful_pulls[(node_id, "/api/v2/server/config")]

        before_pulls = {node["id"]: config_pulls(node["id"]) for node in (main_node, hidden_node)}
        restored = replace_shared("replacement-rest", "127.0.0.3", "127.0.0.28")
        for node in (main_node, hidden_node):
            eventually(lambda node=node: config_pulls(node["id"]) > before_pulls[node["id"]], "REST configuration compensation", agent)
            expect_peer(node, restored["exit_ip"], "shared exit applied without WebSocket")
        result["phases"].append({"phase": "rest_compensation", "consumers": 2, "exit_peer": restored["exit_ip"], "websocket_disabled": True})

        processes.stop(agent)
        eventually(lambda: not listening(health_port), "old machine process stopped")
        agent = processes.start("outbound-agent-restarted", agent_argv)
        wait_tcp(health_port, agent)
        for node in (main_node, hidden_node):
            expect_peer(node, restored["exit_ip"], "managed exit persists across node restart")
        expect_peer(control_node, DIRECT_IP, "direct control after machine restart")
        result["phases"].append({"phase": "restart_persistence", "consumers": 2, "exit_peer": restored["exit_ip"]})

        processes.stop(restored["process"])
        for node in (main_node, hidden_node):
            for _ in range(2):
                require(tcp_peer(node) is None, "Unavailable selected exit silently fell back to direct")
        require(udp_peer(clients[main_node["id"]][1], origin.udp.getsockname()[1]) is None, "Unavailable UDP exit silently fell back to direct")
        expect_peer(control_node, DIRECT_IP, "origin and direct control remain available during exit outage")
        result["phases"].append({"phase": "selected_exit_outage", "tcp_failed_closed": True, "udp_failed_closed": True, "direct_control_available": True})
        subscription(main_node, free_port())
        result.update({"status": "passed", "subscription_exit_credentials_absent": True, "private_destination_guards_unchanged": True})
        print(json.dumps(result, ensure_ascii=False), flush=True)
        return 0
    except Exception as error:
        message = str(error) if isinstance(error, CheckFailed) else type(error).__name__
        result.update({"status": "failed", "error": message,
                       "observation": {"agent_running": agent is not None and agent.poll() is None,
                                       "healthz": health(health_port, "/healthz")[0], "readyz": health(health_port, "/readyz")[0]}})
        if isinstance(error, CheckFailed):
            raise
        raise CheckFailed("Outbound integration harness failed: " + message) from None
    finally:
        processes.close()
        if ws:
            ws.close()
        if http:
            http.close()
        if origin:
            origin.close()
        (artifacts / "outbound-results.json").write_text(json.dumps(result, indent=2) + "\n")


def wait_tcp_host(host, port, process):
    def available():
        try:
            with socket.create_connection((host, port), timeout=0.2):
                return True
        except OSError:
            return False
    eventually(available, "external TCP listener", process)


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except CheckFailed as error:
        print("Outbound connection check failed: " + str(error), file=sys.stderr)
        raise SystemExit(1)

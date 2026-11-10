#!/usr/bin/env python3
"""Exercise the actual xboard-node machine process and REALITY/Vision data plane.

Run only inside tests/Support/run-stack.py's disposable panel. Two machine
instances in one process start with zero users. A transparent loopback relay
observes real panel WebSocket frames, deliberately duplicates/reorders them, and
can sever WebSockets while retaining REST. No protocol server is mocked.

  python tests/Support/run-stack.py --without-scheduler -- \
    python tests/Integration/node_core_lifecycle.py \
      --xboard-node /path/to/xboard-node --mihomo /path/to/mihomo

--expect-stalled runs the empty-bootstrap reproduction against unpatched v1.13;
success means the synchronization was observed but both listeners stayed closed.
It does not count as a successful data-plane test.
"""
from __future__ import annotations

import argparse
import asyncio
from collections import Counter
import hashlib
from http import HTTPStatus
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
from urllib.parse import parse_qs, urlparse
from urllib.request import Request, urlopen

from websockets.asyncio.client import connect
from websockets.asyncio.server import serve

from protocol_connections import API, CheckFailed, Processes, free_port, private_write, require, wait_tcp


class HTTPRelay:
    """Forward node HTTP calls unchanged and count successful REST snapshots."""

    def __init__(self, upstream):
        self.upstream = upstream
        self.block_poll = False
        self.lock = threading.Lock()
        self.successful_pulls = Counter()
        self.successful_machine_reports = Counter()
        self.last_metrics = {}
        owner = self

        class Handler(BaseHTTPRequestHandler):
            protocol_version = "HTTP/1.1"

            def do_GET(self):
                self.forward()

            def do_POST(self):
                self.forward()

            def log_message(self, *_):
                pass

            def forward(self):
                parsed = urlparse(self.path)
                query = parse_qs(parsed.query)
                is_poll = parsed.path in ("/api/v2/server/config", "/api/v2/server/user")
                length = int(self.headers.get("Content-Length", "0"))
                body = self.rfile.read(length) if length else None
                request_data = json.loads(body) if body else {}
                # Node GET requests carry identity in the query; JSON report
                # requests carry it in the body. Authentication is forwarded
                # unchanged and never included in the evidence or logs.
                node_id = int(query.get("node_id", [request_data.get("node_id", 0)])[0])
                machine_id = int(query.get("machine_id", [request_data.get("machine_id", 0)])[0])
                if is_poll and owner.block_poll:
                    code, payload, headers = 503, b'{"error":"test polling gate"}', {}
                else:
                    headers = {k: v for k, v in self.headers.items()
                               if k.lower() not in ("host", "connection", "content-length", "accept-encoding")}
                    request = Request(owner.upstream + self.path, data=body, headers=headers, method=self.command)
                    try:
                        with urlopen(request, timeout=15) as response:
                            code, payload, headers = response.status, response.read(), dict(response.headers)
                    except HTTPError as error:
                        code, payload, headers = error.code, error.read(), dict(error.headers)
                    except (URLError, TimeoutError, OSError):
                        code, payload, headers = 502, b'{"error":"upstream unavailable"}', {}
                    if code == 200:
                        with owner.lock:
                            if is_poll:
                                owner.successful_pulls[(node_id, parsed.path)] += 1
                            if parsed.path == "/api/v2/server/machine/status":
                                owner.successful_machine_reports[machine_id] += 1
                            if parsed.path == "/api/v2/server/report" and body:
                                report = json.loads(body)
                                owner.last_metrics[node_id] = report.get("metrics", {})
                self.send_response(code)
                for k, v in headers.items():
                    if k.lower() not in ("connection", "content-length", "transfer-encoding", "server", "date"):
                        self.send_header(k, v)
                self.send_header("Content-Length", str(len(payload)))
                self.end_headers()
                try:
                    self.wfile.write(payload)
                except (BrokenPipeError, ConnectionResetError):
                    pass

        self.server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.url = f"http://127.0.0.1:{self.server.server_port}"

    def pulls(self, node_id):
        with self.lock:
            return self.successful_pulls[(node_id, "/api/v2/server/user")]

    def machine_reports(self, machine_id):
        with self.lock:
            return self.successful_machine_reports[machine_id]

    def close(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=5)


class WSRelay:
    """A fault-injection transport forwarding authenticated, real panel frames."""

    def __init__(self, upstream):
        self.upstream = upstream.rstrip("/")
        self.port = free_port()
        self.enabled = True
        self.duplicate = False
        self.drop_delta = set()
        self.reorder_full = set()
        self.counts = Counter()
        self.disconnections = 0
        self.latest_full = {}
        self.node_connections = {}
        self.connections = set()
        self.pending_config = {}
        self.lock = threading.Lock()
        self.started = threading.Event()
        self.thread = threading.Thread(target=lambda: asyncio.run(self.run()), daemon=True)
        self.thread.start()
        require(self.started.wait(10), "WebSocket relay failed to start")
        self.url = f"ws://127.0.0.1:{self.port}"

    async def run(self):
        self.loop = asyncio.get_running_loop()
        self.stop = asyncio.Event()

        async def process_request(connection, request):
            if not self.enabled:
                return connection.respond(HTTPStatus.SERVICE_UNAVAILABLE, "WebSocket test gate closed\n")
            return None

        async with serve(self.handle, "127.0.0.1", self.port, process_request=process_request,
                         ping_interval=None, compression=None):
            self.started.set()
            await self.stop.wait()

    async def handle(self, downstream):
        if not self.enabled:
            await downstream.close()
            return
        self.connections.add(downstream)
        try:
            async with connect(self.upstream + downstream.request.path,
                               ping_interval=None, compression=None, proxy=None) as upstream:
                self.connections.add(upstream)

                async def to_panel():
                    async for frame in downstream:
                        await upstream.send(frame)

                async def to_node():
                    async for frame in upstream:
                        parsed = json.loads(frame)
                        event = parsed.get("event", "")
                        node_id = int((parsed.get("data") or {}).get("node_id", 0))
                        if node_id:
                            self.node_connections[node_id] = downstream
                        if event == "sync.user.delta" and node_id in self.drop_delta:
                            with self.lock:
                                self.counts[(node_id, "dropped.delta")] += 1
                            continue
                        if event in ("sync.config", "sync.users"):
                            self.latest_full[(node_id, event)] = frame
                        if event == "sync.config" and node_id in self.reorder_full:
                            self.pending_config[node_id] = frame
                            continue
                        await self.deliver(downstream, frame, node_id, event)
                        if event == "sync.users" and node_id in self.pending_config:
                            await self.deliver(downstream, self.pending_config.pop(node_id), node_id, "sync.config")

                tasks = [asyncio.create_task(to_panel()), asyncio.create_task(to_node())]
                try:
                    done, pending = await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
                    for task in pending:
                        task.cancel()
                    await asyncio.gather(*tasks, return_exceptions=True)
                finally:
                    self.connections.discard(upstream)
        except Exception as error:
            # Never expose the authenticated upstream URL in websocket tracebacks.
            self.last_error = type(error).__name__
        finally:
            self.connections.discard(downstream)
            for node_id, connection in list(self.node_connections.items()):
                if connection is downstream:
                    del self.node_connections[node_id]

    async def deliver(self, downstream, frame, node_id, event):
        copies = 2 if self.duplicate and event in ("sync.config", "sync.users", "sync.user.delta") else 1
        for _ in range(copies):
            await downstream.send(frame)
            with self.lock:
                self.counts[(node_id, event)] += 1

    def count(self, node_id, event):
        with self.lock:
            return self.counts[(node_id, event)]

    def set_enabled(self, enabled):
        async def change():
            self.enabled = enabled
            if not enabled:
                self.disconnections += len(self.node_connections)
                await asyncio.gather(*(socket.close(code=1012, reason="test disconnection")
                                       for socket in list(self.connections)), return_exceptions=True)
        asyncio.run_coroutine_threadsafe(change(), self.loop).result(timeout=10)

    def replay_full(self, node_id, users_first=False):
        async def replay():
            connection = self.node_connections.get(node_id)
            require(connection is not None, "No active node connection for replay")
            order = ("sync.users", "sync.config") if users_first else ("sync.config", "sync.users")
            for event in order:
                frame = self.latest_full.get((node_id, event))
                require(frame is not None, "No real full-sync frame available for replay")
                await self.deliver(connection, frame, node_id, event)
        asyncio.run_coroutine_threadsafe(replay(), self.loop).result(timeout=10)

    def close(self):
        if self.thread.is_alive():
            self.set_enabled(False)
            self.loop.call_soon_threadsafe(self.stop.set)
            self.thread.join(timeout=10)


def eventually(predicate, description, process=None, timeout=30):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if process is not None:
            require(process.poll() is None, "xboard-node exited while " + description)
        if predicate():
            return
        time.sleep(0.1)
    raise CheckFailed("Timed out: " + description)


def listening(port):
    try:
        with socket.create_connection(("127.0.0.1", port), timeout=0.15):
            return True
    except OSError:
        return False


def health(port, path):
    try:
        with urlopen(f"http://127.0.0.1:{port}{path}", timeout=2) as response:
            return response.status, json.loads(response.read())
    except HTTPError as error:
        try:
            return error.code, json.loads(error.read())
        except json.JSONDecodeError:
            return error.code, {}
    except (URLError, TimeoutError, OSError):
        return 0, {}


def panel_node(api, node_id):
    return next(item for item in api.admin("server/manage/getNodes", method="GET") if item["id"] == node_id)


def check_panel(api, node_id, ready, process, control=None):
    def matched():
        node = panel_node(api, node_id)
        if node.get("core_ready") is not ready:
            return False
        if bool(node.get("is_online")) is not ready:
            return False
        if control is not None and node.get("control_online") is not control:
            return False
        return True
    eventually(matched, f"panel core readiness={ready} for node {node_id}", process)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--xboard-node", "--node", dest="xboard_node", required=True)
    parser.add_argument("--mihomo", default=os.environ.get("MIHOMO_BIN", "mihomo"))
    parser.add_argument("--expect-stalled", action="store_true")
    args = parser.parse_args()
    require(os.environ.get("SIMPLEXBOARD_E2E") == "1", "Use the disposable run-stack.py environment")
    base = os.environ["E2E_BASE_URL"]
    require(urlparse(base).hostname == "127.0.0.1", "The panel must run on loopback")
    binary = shutil.which(args.xboard_node)
    require(binary is not None, "xboard-node binary not found")
    mihomo = shutil.which(args.mihomo)
    if not args.expect_stalled:
        require(mihomo is not None, "mihomo binary not found")
    for tool in ("openssl", "curl"):
        require(shutil.which(tool) is not None, tool + " is required")
    artifacts = Path(os.environ["E2E_ARTIFACT_DIR"])
    work = Path(os.environ["E2E_WORK_DIR"]) / "node-core-lifecycle"
    work.mkdir(mode=0o700)
    result = {"status": "running", "scope": "actual machine process and loopback REALITY/Vision traffic",
              "mode": "baseline-reproduction" if args.expect_stalled else "fixed-regression",
              "xboard_node_sha256": hashlib.sha256(Path(binary).read_bytes()).hexdigest(), "phases": []}
    api = API(base, os.environ["E2E_ADMIN_PATH"])
    login = api.request("POST", "/api/v1/passport/auth/login", authenticated=False,
                        data={"email": os.environ["E2E_ADMIN_EMAIL"], "password": os.environ["E2E_ADMIN_PASSWORD"]})
    api.authorization = login["data"]["auth_data"]
    fixture = json.loads(Path(os.environ["E2E_FIXTURE_FILE"]).read_text())
    api.admin("user/ban", {"id": fixture["member"]["id"], "banned": True})
    for node in fixture["nodes"]:
        api.admin("server/manage/update", {"id": node["id"], "enabled": False})
    http = HTTPRelay(base)
    ws = WSRelay(os.environ["E2E_WS_URL"])
    api.admin("config/save", {"server_ws_url": ws.url, "server_ws_enable": True,
                             "server_push_interval": 10, "server_pull_interval": 30})
    cert, key = work / "localhost.pem", work / "localhost-key.pem"
    subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                    "-subj", "/CN=localhost", "-addext", "subjectAltName=DNS:localhost",
                    "-keyout", str(key), "-out", str(cert)], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    key.chmod(0o600)
    pair = api.admin("server/manage/generateRealityKeyPair", require_no_store=True)
    decoy_port, health_port = free_port(), free_port()
    nodes, machines = [], []
    for index in range(2):
        machine = api.admin("server/machine/save", {"name": f"Lifecycle machine {index}"})
        machines.append(machine)
        port = free_port()
        node = {"type": "vless", "name": f"Lifecycle REALITY {index}", "host": "127.0.0.1",
                "port": str(port), "server_port": port, "machine_id": machine["id"], "show": True, "enabled": True,
                "protocol_settings": {"tls": 2, "network": "tcp", "flow": "xtls-rprx-vision",
                    "utls": {"enabled": True, "fingerprint": "chrome"},
                    "reality_settings": {"server_name": "localhost", "server_port": decoy_port,
                                         "allow_insecure": False, "private_key": pair["private_key"],
                                         "public_key": pair["public_key"], "short_id": pair["short_id"]}}}
        api.admin("server/manage/save", node)
        nodes.append(node)
    by_name = {node["name"]: node for node in api.admin("server/manage/getNodes", method="GET")}
    for node in nodes:
        node["id"] = by_name[node["name"]]["id"]
    a, b = nodes

    def request_full_snapshot(node):
        # A real node quota change triggers ServerObserver::notifyFullSync.
        # Raising an unused test quota keeps the node available throughout.
        node["transfer_enable"] = node.get("transfer_enable", 0) + 1024 ** 3
        api.admin("server/manage/save", node)

    env = os.environ.copy()
    env["SSL_CERT_FILE"] = str(cert)
    processes = Processes(artifacts, env)
    expected = ("SimpleXboard real machine lifecycle " + secrets.token_hex(16)).encode()

    class OriginHandler(BaseHTTPRequestHandler):
        def do_GET(self):
            self.send_response(200)
            self.send_header("Content-Length", str(len(expected)))
            self.end_headers()
            self.wfile.write(expected)

        def log_message(self, *_):
            pass

    origin = ThreadingHTTPServer(("127.0.0.2", 0), OriginHandler)
    threading.Thread(target=origin.serve_forever, daemon=True).start()
    target = f"http://127.0.0.2:{origin.server_port}/lifecycle-proof"
    # The upstream core intentionally blocks private destinations. Allow only
    # this test origin's exact address AND TCP port through the supported raw
    # custom-route option in this temporary agent config; production defaults
    # remain intact. Bind first so the random destination port cannot be reused.
    origin_route = {"ip_cidr": ["127.0.0.2/32"], "port": [origin.server_port],
                    "network": ["tcp"], "outbound": "direct"}
    result["test_origin_route"] = origin_route
    blocker = None
    clients = {}
    agent = None
    try:
        decoy = processes.start("node-core-tls-handshake", ["openssl", "s_server", "-accept", f"127.0.0.1:{decoy_port}",
                                  "-cert", str(cert), "-key", str(key), "-tls1_3", "-alpn", "h2,http/1.1", "-www", "-quiet"])
        wait_tcp(decoy_port, decoy)
        agent_config = {"kernel": {"type": "singbox", "log_level": "warn", "config_dir": str(work / "kernel"),
                                   "custom_route": [origin_route]},
                        "log": {"level": "info"}, "health_port": health_port,
                        "node": {"push_interval": 5, "pull_interval": 2, "track_interval": 1},
                        "ws": {"status_interval": 1, "backoff_initial": 1, "backoff_max": 2},
                        "instances": [{"panel": {"url": http.url}, "machine": {"machine_id": machine["id"], "token": machine["token"]},
                                       "health_port": health_port if index == 0 else 0}
                                      for index, machine in enumerate(machines)]}
        config_path = work / "config.yml"
        private_write(config_path, agent_config)
        agent = processes.start("node-core-agent", [binary, "-c", str(config_path)])
        wait_tcp(health_port, agent)
        agent_pid = agent.pid
        for node in nodes:
            eventually(lambda node=node: http.pulls(node["id"]) > 0 and ws.count(node["id"], "sync.users") > 0,
                       "empty REST bootstrap and machine WebSocket full snapshot", agent)
            require(not listening(node["server_port"]), "An empty bootstrap unexpectedly started its listener")
        require(health(health_port, "/healthz")[0] == 200, "Process liveness failed at empty bootstrap")
        for node, machine in zip(nodes, machines):
            eventually(lambda node=node, machine=machine: http.machine_reports(machine["id"]) > 0
                       and panel_node(api, node["id"]).get("machine_online") is True,
                       "successful real machine status report while proxy has zero users", agent)
        if not args.expect_stalled:
            require(health(health_port, "/readyz")[0] == 503, "Empty machine instances were incorrectly ready")
            for node in nodes:
                check_panel(api, node["id"], False, agent, control=True)
                eventually(lambda node=node: panel_node(api, node["id"]).get("core_status", {}).get("state") == "waiting_users",
                           "explicit waiting-users core state", agent)
        result["phases"].append({"phase": "empty_bootstrap", "users": 0, "listeners": 0, "machine_instances": 2,
                                  "machine_heartbeats": True,
                                  "successful_machine_status_reports": {str(machine["id"]): http.machine_reports(machine["id"])
                                                                        for machine in machines}})

        # Hold REST snapshots unavailable while proving the WS delivery paths.
        # Dropping A's deltas forces its 0 -> 1 transition to use a real full sync.
        http.block_poll = True
        ws.drop_delta.add(a["id"])
        ws.duplicate = True
        member = api.admin("user/create", {"name": "Lifecycle test member", "access_mode": "restricted",
                                           "server_ids": [node["id"] for node in nodes]})
        eventually(lambda: ws.count(a["id"], "dropped.delta") > 0 and ws.count(b["id"], "sync.user.delta") > 0,
                   "real member authorization delta delivery", agent)
        full_before = ws.count(a["id"], "sync.users")
        request_full_snapshot(a)
        eventually(lambda: ws.count(a["id"], "sync.users") > full_before, "full WS user synchronization", agent)

        if args.expect_stalled:
            # Long enough for the actual delivered full/delta events to be consumed.
            end = time.monotonic() + 5
            while time.monotonic() < end:
                require(agent.poll() is None, "Baseline process exited instead of reproducing the stall")
                require(all(not listening(node["server_port"]) for node in nodes), "Baseline unexpectedly recovered; reproduction no longer applies")
                time.sleep(0.1)
            baseline_log = (artifacts / "node-core-agent.log").read_text(errors="replace")
            require(f"[vless:{a['server_port']}] users updated, 1 users" in baseline_log,
                    "Original node did not consume the real nonempty user snapshot")
            require(not any(marker in baseline_log for marker in
                            ("failed to start kernel", "kernel apply failed", "start sing-box:")),
                    "A kernel startup failure must not be counted as the original lifecycle stall")
            for node, machine in zip(nodes, machines):
                users = api.request("GET", "/api/v2/server/user", authenticated=False,
                                    query={"machine_id": machine["id"], "token": machine["token"], "node_id": node["id"]})["users"]
                require(len(users) == 1, "Baseline panel did not authorize exactly one user")
            require(health(health_port, "/healthz")[0] == 200, "Baseline no longer reproduces liveness despite stalled core")
            result["status"] = "reproduced"
            result["phases"].append({"phase": "v1.13_stall", "ws_full_received": True, "ws_delta_received": True,
                                      "panel_users_per_node": 1, "listeners": 0, "healthz_http": 200,
                                      "original_users_updated_log": True, "kernel_start_failure_excluded": True,
                                      "data_plane_test": False})
            print(json.dumps(result, ensure_ascii=False), flush=True)
            return 0

        for node in nodes:
            eventually(lambda node=node: listening(node["server_port"]), "WS 0 -> 1 listener startup", agent)
            check_panel(api, node["id"], True, agent, control=True)
        eventually(lambda: health(health_port, "/readyz")[0] == 200, "all instances ready", agent)

        result["mihomo_version"] = subprocess.check_output([mihomo, "-v"], text=True).strip().splitlines()[0]
        result["mihomo_sha256"] = hashlib.sha256(Path(mihomo).read_bytes()).hexdigest()
        require("1.19.29" in result["mihomo_version"], "Use the reported mihomo v1.19.29 client")
        for node in nodes:
            socks_port = free_port()
            template = {"socks-port": socks_port, "allow-lan": False, "bind-address": "127.0.0.1", "mode": "rule", "log-level": "warning",
                        "proxies": [], "proxy-groups": [], "rules": ["MATCH," + node["name"]],
                        "dns": {"enable": False}, "profile": {"store-selected": False}}
            api.admin("config/save", {"subscribe_template_clashmeta": json.dumps(template), "show_protocol_to_server_enable": False})
            raw = api.raw("GET", "/s/" + member["token"], query={"flag": "meta"}, authenticated=False, user_agent="Clash.Meta/1.19.29")
            for value in (b"xtls-rprx-vision", pair["public_key"].encode(), pair["short_id"].encode()):
                require(value in raw, "Mihomo subscription omitted REALITY/Vision settings")
            require(pair["private_key"].encode() not in raw, "Mihomo subscription leaked private key")
            path = work / f"client-{node['id']}.yaml"
            private_write(path, raw)
            directory = work / f"mihomo-{node['id']}"
            directory.mkdir()
            with (artifacts / f"node-core-mihomo-{node['id']}-check.log").open("wb") as log:
                checked = subprocess.run([mihomo, "-t", "-f", str(path), "-d", str(directory)], env=env, stdout=log, stderr=subprocess.STDOUT)
            require(checked.returncode == 0, "Mihomo rejected the unchanged panel subscription")
            client = processes.start(f"node-core-mihomo-{node['id']}", [mihomo, "-f", str(path), "-d", str(directory)])
            wait_tcp(socks_port, client)
            clients[node["id"]] = (client, socks_port)

        def proxy(node, success=True):
            client, socks_port = clients[node["id"]]
            require(client.poll() is None, "Mihomo client exited")
            command = ["curl", "--silent", "--show-error", "--fail", "--noproxy", "", "--socks5-hostname", f"127.0.0.1:{socks_port}",
                       "--connect-timeout", "2", "--max-time", "4", target]
            sent = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=6)
            if success:
                if sent.returncode != 0 or sent.stdout != expected:
                    result["failed_proxy_request"] = {"node_id": node["id"], "curl_exit": sent.returncode,
                        "stderr": sent.stderr.decode(errors="replace")[:2000], "response_bytes": len(sent.stdout),
                        "expected_body_matched": sent.stdout == expected}
                require(sent.returncode == 0 and sent.stdout == expected, "Actual REALITY/Vision HTTP request failed")
            else:
                require(sent.returncode != 0, "Proxy request bypassed a stopped node core")

        for node in nodes:
            proxy(node)
        result["phases"].append({"phase": "ws_full_and_delta_0_to_1", "real_http_requests": 2, "client": "mihomo v1.19.29",
                                  "flow": "xtls-rprx-vision", "subscription_executed_unchanged": True, "duplicate_delivery": True})

        def revoke_all():
            ws.drop_delta.clear()
            ws.drop_delta.add(a["id"])
            before_full = ws.count(a["id"], "sync.users")
            api.admin("user/update", {"id": member["id"], "server_ids": []})
            request_full_snapshot(a)
            eventually(lambda: ws.count(a["id"], "sync.users") > before_full,
                       "real full empty-user snapshot after revocation", agent)
            empty = json.loads(ws.latest_full[(a["id"], "sync.users")])["data"].get("users")
            require(empty == [], "Panel full revocation did not send an explicit empty user array")
            for node in nodes:
                eventually(lambda node=node: not listening(node["server_port"]), "last user removal stops listener", agent)
                check_panel(api, node["id"], False, agent)
                proxy(node, success=False)
            require(health(health_port, "/healthz")[0] == 200, "Agent liveness failed after revocation")
            require(health(health_port, "/readyz")[0] == 503, "Empty cores were incorrectly marked ready")

        revoke_all()
        ws.drop_delta.add(a["id"])
        ws.reorder_full.add(a["id"])
        api.admin("user/update", {"id": member["id"], "server_ids": [node["id"] for node in nodes]})
        request_full_snapshot(a)
        for node in nodes:
            eventually(lambda node=node: listening(node["server_port"]), "reauthorization after zero users", agent)
            proxy(node)
        ws.replay_full(a["id"], users_first=True)
        time.sleep(0.5)
        for node in nodes:
            proxy(node)
        result["phases"].append({"phase": "ws_0_1_0_1", "users_before_config": True, "repeated_full_snapshot": True,
                                  "real_http_requests": 4, "revoked_requests_rejected": 2,
                                  "empty_full_and_delta_revocation": True})

        # Keep B working while A has a real bind conflict, then remove the conflict.
        ws.drop_delta.clear()
        api.admin("user/update", {"id": member["id"], "server_ids": [b["id"]]})
        eventually(lambda: not listening(a["server_port"]), "A stops before bind conflict", agent)
        check_panel(api, a["id"], False, agent)
        blocker = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        blocker.bind(("127.0.0.1", a["server_port"]))
        blocker.listen(1)
        api.admin("user/update", {"id": member["id"], "server_ids": [node["id"] for node in nodes]})
        log_path = artifacts / "node-core-agent.log"
        eventually(lambda: "kernel apply failed; retry scheduled" in log_path.read_text(errors="replace"), "explicit bind failure logging", agent)

        def panel_bind_failed():
            core = panel_node(api, a["id"]).get("core_status", {})
            error = core.get("error") or ""
            return (core.get("state") == "error" and core.get("ready") is False
                    and core.get("users") == 1 and core.get("applied_users") == 0
                    and "start kernel:" in error and ("bind" in error or "address already in use" in error))

        eventually(panel_bind_failed, "panel error identifies the real failed listener bind", agent)
        check_panel(api, a["id"], False, agent)
        require(health(health_port, "/readyz")[0] == 503, "One failed machine instance incorrectly made aggregate readiness healthy")
        proxy(b)
        blocker.close()
        blocker = None
        # No new configuration/user event is sent after releasing the port.
        eventually(lambda: listening(a["server_port"]), "safe autonomous retry after releasing bind conflict", agent, timeout=45)
        proxy(a)
        check_panel(api, a["id"], True, agent)
        result["phases"].append({"phase": "bind_failure_retry", "explicit_error": True, "failure_not_ready": True,
                                  "other_machine_stayed_usable": True, "retry_without_new_sync": True, "real_http_requests": 2})

        revoke_all()
        before_rest = {node["id"]: http.pulls(node["id"]) for node in nodes}
        http.block_poll = False
        ws.set_enabled(False)
        require(ws.disconnections >= 2, "REST phase did not actually disconnect established WebSocket sessions")
        api.admin("user/update", {"id": member["id"], "server_ids": [node["id"] for node in nodes]})
        for node in nodes:
            eventually(lambda node=node: http.pulls(node["id"]) > before_rest[node["id"]] and listening(node["server_port"]),
                       "REST recovery after an established WS disconnection", agent)
            proxy(node)
            check_panel(api, node["id"], True, agent, control=False)
        # Repeated REST 304/unchanged data must leave each already-running core usable.
        time.sleep(2.5)
        for node in nodes:
            proxy(node)
        before_reconnect = {node["id"]: ws.count(node["id"], "sync.users") for node in nodes}
        ws.reorder_full.clear()
        ws.set_enabled(True)
        for node in nodes:
            eventually(lambda node=node: ws.count(node["id"], "sync.users") > before_reconnect[node["id"]],
                       "machine WS full synchronization after reconnect", agent)
            check_panel(api, node["id"], True, agent, control=True)
            proxy(node)
        result["phases"].append({"phase": "disconnected_rest_reconciliation_and_ws_reconnect", "established_ws_connections_closed": 2,
                                  "successful_rest_users_snapshots": {str(node["id"]): http.pulls(node["id"]) - before_rest[node["id"]] for node in nodes},
                                  "real_http_requests": 6})
        require(agent.pid == agent_pid and agent.poll() is None, "Agent restarted during recovery")
        eventually(lambda: health(health_port, "/readyz")[0] == 200, "all machine cores ready after reconnect", agent)
        processes.stop(agent)
        for node in nodes:
            proxy(node, success=False)
        result["phases"].append({"phase": "negative_control", "agent_stopped_requests_rejected": 2, "clients_remained_running": True})
        result["status"] = "passed"
        result["agent_restart_required"] = False
        print(json.dumps(result, ensure_ascii=False), flush=True)
        return 0
    except Exception as error:
        message = str(error) if isinstance(error, CheckFailed) else type(error).__name__
        result.update({"status": "failed", "error": message})
        # Preserve the independent control/core signals before cleanup. This is
        # useful when a real kernel fails to start in a restricted CI runtime.
        # Never include URLs, authentication material or generated subscriptions.
        observation = {"agent_running": agent is not None and agent.poll() is None,
                       "healthz_http": health(health_port, "/healthz")[0],
                       "readyz_http": health(health_port, "/readyz")[0], "nodes": []}
        for node in nodes:
            current = {"id": node["id"], "listening": listening(node["server_port"])}
            try:
                panel = panel_node(api, node["id"])
                for key in ("machine_online", "control_online", "is_online", "core_ready"):
                    current[key] = panel.get(key)
                core = panel.get("core_status", {})
                current["core_status"] = {key: core.get(key) for key in ("state", "ready", "users", "applied_users", "error")}
            except Exception:
                current["panel_observation"] = "unavailable"
            observation["nodes"].append(current)
        result["failure_observation"] = observation
        if isinstance(error, CheckFailed):
            raise
        raise CheckFailed("Unexpected harness failure: " + message) from None
    finally:
        if blocker:
            blocker.close()
        processes.close()
        ws.close()
        http.close()
        if origin:
            origin.shutdown()
            origin.server_close()
        (artifacts / "node-core-lifecycle-results.json").write_text(json.dumps(result, indent=2) + "\n")


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except CheckFailed as error:
        print("Node core lifecycle check failed: " + str(error), file=sys.stderr)
        raise SystemExit(1)

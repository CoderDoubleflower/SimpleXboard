#!/usr/bin/env python3
"""Real panel HTTP -> queue worker -> Redis -> Workerman WebSocket verification.

Run through tests/Support/run-stack.py against its disposable database. This
simulates the existing node control protocol, not a VLESS/Hysteria data-plane
connection. Only Python's standard library and websockets are required.
"""
from __future__ import annotations

import asyncio
import json
import os
from pathlib import Path
import secrets
import sys
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

import websockets
from websockets.exceptions import InvalidHandshake


class CheckFailed(Exception):
    pass


class API:
    def __init__(self, base: str, admin_path: str):
        self.base = base.rstrip("/")
        self.admin_path = "/api/v2/" + admin_path.strip("/") + "/"
        self.authorization = ""

    async def request(self, method, path, data=None, query=None, authenticated=True, expected=200):
        def send():
            url = self.base + path
            if query:
                url += "?" + urlencode(query)
            headers = {"Accept": "application/json"}
            if authenticated and self.authorization:
                headers["Authorization"] = self.authorization
            if data is not None:
                headers["Content-Type"] = "application/json"
            req = Request(url, data=None if data is None else json.dumps(data).encode(), headers=headers, method=method)
            try:
                with urlopen(req, timeout=15) as response:
                    status, raw = response.status, response.read()
            except HTTPError as error:
                status, raw = error.code, error.read()
            except URLError as error:
                raise CheckFailed("HTTP transport unavailable") from None
            if status != expected:
                # URLs and response bodies may contain credentials. Never log them.
                raise CheckFailed(f"HTTP {method} operation returned {status}, expected {expected}")
            try:
                return json.loads(raw) if raw else None
            except json.JSONDecodeError:
                return raw.decode("utf-8", errors="replace")
        return await asyncio.to_thread(send)

    async def admin(self, path, data=None, method="POST"):
        return await self.request(method, self.admin_path + path, data=data)


class NodeSocket:
    def __init__(self, ws_url: str, node_id: int, token: str, timeout: float):
        self.url = ws_url.rstrip("/") + "?" + urlencode({"node_id": node_id, "token": token})
        self.node_id = node_id
        self.timeout = timeout
        self.messages: list[dict] = []
        self.changed = asyncio.Condition()
        self.ws = None
        self.task = None
        self.closed = False

    async def start(self, accepted=True):
        try:
            self.ws = await websockets.connect(self.url, open_timeout=10, ping_interval=None)
        except InvalidHandshake:
            if not accepted:
                return {"event": "error", "data": {"message": "handshake rejected"}}
            raise CheckFailed("WebSocket handshake rejected valid credentials") from None
        except Exception:
            raise CheckFailed("WebSocket connection could not be opened") from None
        self.task = asyncio.create_task(self.read())
        if accepted:
            await self.wait("auth.success")
            await self.wait("sync.config")
            return await self.wait("sync.users")
        return await self.wait("error", allow_error=True)

    async def read(self):
        try:
            async for raw in self.ws:
                message = json.loads(raw)
                if message.get("event") == "ping":
                    await self.ws.send(json.dumps({"event": "pong", "data": {}}))
                    continue
                async with self.changed:
                    self.messages.append(message)
                    self.changed.notify_all()
        except Exception:
            pass
        finally:
            async with self.changed:
                self.closed = True
                self.changed.notify_all()

    def mark(self):
        return len(self.messages)

    async def wait(self, event: str, predicate=lambda message: True, after=0, allow_error=False):
        deadline = time.monotonic() + self.timeout
        async with self.changed:
            while True:
                for message in self.messages[after:]:
                    if message.get("event") == "error" and not allow_error:
                        raise CheckFailed("WebSocket authentication or protocol error")
                    if message.get("event") == event and predicate(message):
                        return message
                if self.closed:
                    raise CheckFailed(f"WebSocket closed before {event}")
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise CheckFailed(f"WebSocket timeout waiting for {event}")
                try:
                    await asyncio.wait_for(self.changed.wait(), remaining)
                except TimeoutError:
                    raise CheckFailed(f"WebSocket timeout waiting for {event}") from None

    async def close(self):
        if self.ws is not None:
            await self.ws.close()
        if self.task:
            await asyncio.gather(self.task, return_exceptions=True)


def contains_user(message, member_id, uuid=None):
    return any(int(user["id"]) == member_id and (uuid is None or user.get("uuid") == uuid)
               for user in message.get("data", {}).get("users", []))


async def run():
    fixture_path = Path(os.environ["E2E_FIXTURE_FILE"])
    fixture = json.loads(fixture_path.read_text())
    api = API(os.environ["E2E_BASE_URL"], os.environ["E2E_ADMIN_PATH"])
    ws_url = os.environ["E2E_WS_URL"]
    server_token = fixture["server_token"]
    nodes = [node for node in fixture["nodes"] if node.get("enabled", True)]
    if len(nodes) < 2:
        raise CheckFailed("Fixture requires at least two enabled nodes")
    timeout = float(os.environ.get("E2E_SYNC_TIMEOUT", "75"))
    checks = []
    sockets = []
    member = None
    token_changed = False
    report_path = Path(os.environ.get("E2E_REPORT_DIR", os.environ.get("E2E_ARTIFACT_DIR", str(fixture_path.parent)))) / "node-lifecycle-results.json"
    report_path.parent.mkdir(parents=True, exist_ok=True)

    def record(name, started):
        entry = {"check": name, "latency_ms": round((time.monotonic() - started) * 1000, 1)}
        checks.append(entry)
        print(json.dumps(entry), flush=True)

    async def delta(action, expected_action, payload, label):
        marks = [socket.mark() for socket in sockets]
        started = time.monotonic()
        result = await api.admin(action, payload)
        await asyncio.gather(*[
            socket.wait("sync.user.delta", lambda message: message["data"].get("action") == expected_action
                        and contains_user(message, member["id"]), after=mark)
            for socket, mark in zip(sockets, marks)
        ])
        record(label, started)
        return result

    async def full_pull(node):
        return await api.request("GET", "/api/v2/server/user", authenticated=False,
                                 query={"token": server_token, "node_id": node["id"]})

    try:
        login = await api.request("POST", "/api/v1/passport/auth/login", authenticated=False, data={
            "email": os.environ["E2E_ADMIN_EMAIL"], "password": os.environ["E2E_ADMIN_PASSWORD"],
        })
        api.authorization = login["data"]["auth_data"]
        started = time.monotonic()
        for node in nodes:
            handshake = await api.request("GET", "/api/v2/server/handshake", authenticated=False,
                                          query={"token": server_token, "node_id": node["id"]})
            if not handshake["websocket"]["enabled"]:
                raise CheckFailed("HTTP handshake did not advertise running WebSocket server")
            socket = NodeSocket(ws_url, node["id"], server_token, timeout)
            initial = await socket.start()
            if fixture.get("member") and not contains_user(initial, int(fixture["member"]["id"])):
                raise CheckFailed("Existing fixture member missing from initial full sync")
            sockets.append(socket)
        record("HTTP handshake and real WebSocket full sync", started)

        marks = [socket.mark() for socket in sockets]
        started = time.monotonic()
        member = (await api.admin("user/create", {"name": "WS lifecycle probe", "transfer_enable": None}))["data"]
        await asyncio.gather(*[socket.wait("sync.user.delta", lambda msg: msg["data"].get("action") == "add"
                                           and contains_user(msg, member["id"], member["uuid"]), after=mark)
                              for socket, mark in zip(sockets, marks)])
        record("Create member via HTTP and deliver queue delta", started)

        await delta("user/update", "add", {"id": member["id"], "speed_limit": 13}, "Update member through real worker and Redis")
        for node in nodes:
            users = (await full_pull(node))["users"]
            if not any(user["id"] == member["id"] and user["speed_limit"] == 13 for user in users):
                raise CheckFailed("HTTP full pull differs from WebSocket member update")

        old_token, old_uuid = member["token"], member["uuid"]
        marks = [socket.mark() for socket in sockets]
        started = time.monotonic()
        member = (await api.admin("user/resetSecret", {"id": member["id"]}))["data"]
        if member["token"] == old_token or member["uuid"] == old_uuid:
            raise CheckFailed("Credential rotation did not replace both identifiers")
        for socket, mark in zip(sockets, marks):
            await socket.wait("sync.user.delta", lambda msg: msg["data"].get("action") == "remove" and contains_user(msg, member["id"]), after=mark)
            await socket.wait("sync.user.delta", lambda msg: msg["data"].get("action") == "add" and contains_user(msg, member["id"], member["uuid"]), after=mark)
            events = [msg for msg in socket.messages[mark:] if msg.get("event") == "sync.user.delta" and contains_user(msg, member["id"])]
            if events[0]["data"]["action"] != "remove":
                raise CheckFailed("Rotation added new credentials before removing old credentials")
        await api.request("GET", "/s/" + old_token, authenticated=False, expected=403)
        record("Credential rotation removes old identity before adding new", started)

        await delta("user/ban", "remove", {"id": member["id"], "banned": True}, "Disable member on all connected nodes")
        await api.request("GET", "/s/" + member["token"], authenticated=False, expected=403)
        started = time.monotonic()
        await sockets[0].close()
        sockets[0] = NodeSocket(ws_url, nodes[0]["id"], server_token, timeout)
        initial = await sockets[0].start()
        if contains_user(initial, member["id"]):
            raise CheckFailed("Disabled credentials returned after reconnect")
        record("Reconnect keeps disabled member revoked", started)
        await delta("user/ban", "add", {"id": member["id"], "banned": False}, "Re-enable member without changing identity")

        marks = [socket.mark() for socket in sockets]
        started = time.monotonic()
        await api.admin("user/update", {"id": member["id"], "access_mode": "restricted", "server_ids": [nodes[0]["id"]]})
        for index, (socket, mark) in enumerate(zip(sockets, marks)):
            expected_action = "add" if index == 0 else "remove"
            await socket.wait("sync.user.delta", lambda msg, action=expected_action: msg["data"].get("action") == action and contains_user(msg, member["id"]), after=mark)
        record("Direct access changes revoke nodes outside current grant", started)
        await delta("user/update", "add", {"id": member["id"], "access_mode": "all"}, "Restore shared enabled-node access")

        await delta("user/update", "add", {"id": member["id"], "transfer_enable": 50}, "Set positive hard usage limit")
        marks = [socket.mark() for socket in sockets]
        started = time.monotonic()
        await api.request("POST", "/api/v2/server/report", authenticated=False, data={
            "token": server_token, "node_id": nodes[0]["id"], "traffic": {str(member["id"]): [24, 26]},
        })
        await asyncio.gather(*[socket.wait("sync.user.delta", lambda msg: msg["data"].get("action") == "remove" and contains_user(msg, member["id"]), after=mark)
                              for socket, mark in zip(sockets, marks)])
        detail = await api.request("GET", api.admin_path + "user/getUserInfoById", query={"id": member["id"]})
        if detail["data"]["total_used"] != 50 or detail["data"]["actual_u"] != 24 or detail["data"]["eligible"]:
            raise CheckFailed("Actual byte accounting or quota exhaustion is inconsistent")
        record("Traffic report reaches cap and revokes through quota worker", started)
        await delta("traffic-reset/reset-user", "add", {"user_id": member["id"], "reason": "Isolated integration check"}, "Manual usage reset restores limited member")
        await delta("user/update", "add", {"id": member["id"], "transfer_enable": None}, "Null limit remains explicitly unlimited")

        started = time.monotonic()
        mark = sockets[0].mark()
        await api.admin("server/manage/update", {"id": nodes[0]["id"], "enabled": False})
        await sockets[0].wait("sync.users", lambda msg: not msg["data"].get("users"), after=mark)
        if (await full_pull(nodes[0]))["users"]:
            raise CheckFailed("Disabled node returned member credentials in full pull")
        await sockets[0].close()
        sockets[0] = NodeSocket(ws_url, nodes[0]["id"], server_token, timeout)
        if (await sockets[0].start())["data"].get("users"):
            raise CheckFailed("Disabled node gained credentials on reconnect")
        mark = sockets[0].mark()
        await api.admin("server/manage/update", {"id": nodes[0]["id"], "enabled": True})
        await sockets[0].wait("sync.users", lambda msg: contains_user(msg, member["id"], member["uuid"]), after=mark)
        record("Node disable reconnect and re-enable preserve qualification", started)

        started = time.monotonic()
        await api.admin("server/manage/update", {"id": nodes[0]["id"], "show": False})
        if not any(user["id"] == member["id"] for user in (await full_pull(nodes[0]))["users"]):
            raise CheckFailed("Hiding node incorrectly revoked member access")
        await api.admin("server/manage/update", {"id": nodes[0]["id"], "show": nodes[0].get("show", True)})
        record("Subscription visibility does not disable node credentials", started)

        await delta("user/destroy", "remove", {"id": member["id"]}, "Delete member revokes all connected nodes")
        await api.request("GET", "/s/" + member["token"], authenticated=False, expected=403)
        member = None

        # Verify the long-running worker refreshes settings for every new handshake.
        started = time.monotonic()
        new_server_token = secrets.token_hex(24)
        await api.admin("config/save", {"server_token": new_server_token})
        token_changed = True
        fresh = NodeSocket(ws_url, nodes[0]["id"], new_server_token, timeout)
        try:
            await fresh.start()
        finally:
            await fresh.close()
        rejected = NodeSocket(ws_url, nodes[0]["id"], server_token, timeout)
        try:
            await rejected.start(accepted=False)
        finally:
            await rejected.close()
        record("Long-running WebSocket accepts rotated server token only", started)
        result = {"status": "passed", "scope": "Real HTTP, Redis, queue worker and Workerman protocol; no VPS data-plane connection", "checks": checks}
        report_path.write_text(json.dumps(result, indent=2) + "\n")
        print(json.dumps({"status": "passed", "checks": len(checks)}), flush=True)
    except Exception as error:
        reason = str(error) if isinstance(error, CheckFailed) else type(error).__name__
        report_path.write_text(json.dumps({"status": "failed", "error": reason, "checks": checks}, indent=2) + "\n")
        raise CheckFailed(reason) from None
    finally:
        if token_changed:
            try:
                await api.admin("config/save", {"server_token": server_token})
            except Exception:
                pass
        if member is not None:
            try:
                await api.admin("user/destroy", {"id": member["id"]})
            except Exception:
                pass
        for socket in sockets:
            await socket.close()


if __name__ == "__main__":
    try:
        asyncio.run(run())
    except CheckFailed as error:
        print(json.dumps({"status": "failed", "error": str(error)}), file=sys.stderr)
        sys.exit(1)

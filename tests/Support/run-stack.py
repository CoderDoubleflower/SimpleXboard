#!/usr/bin/env python3
"""Run a test command with a disposable SQLite/Redis/PHP/Horizon/WebSocket stack.

All processes share one network namespace. No existing database, Redis instance,
application configuration or production service is used.
"""
import argparse
import base64
import json
import os
from pathlib import Path
import secrets
import signal
import socket
import subprocess
import sys
import tempfile
import time
import urllib.request

REPO = Path(__file__).resolve().parents[2]


def free_port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default=os.environ.get("PHP_BIN", "php"))
    parser.add_argument("--redis", default=os.environ.get("REDIS_SERVER_BIN", "redis-server"))
    parser.add_argument("--artifacts", default=str(REPO / "storage/framework/testing/e2e-artifacts"))
    parser.add_argument("--without-scheduler", action="store_true")
    parser.add_argument("command", nargs=argparse.REMAINDER)
    args = parser.parse_args()
    command = args.command[1:] if args.command[:1] == ["--"] else args.command
    if not command:
        parser.error("Provide the test command after --")
    artifacts = Path(args.artifacts).resolve()
    artifacts.mkdir(parents=True, exist_ok=True)
    children, logs = [], []
    with tempfile.TemporaryDirectory(prefix="simplexboard-e2e-") as temporary:
        work = Path(temporary)
        for path in ["storage/logs", "storage/framework/cache/data", "storage/framework/sessions", "storage/framework/views", "storage/app", "storage/backup"]:
            (work / path).mkdir(parents=True, exist_ok=True)
        db = work / "database.sqlite"
        db.touch()
        redis_port, http_port, ws_port = free_port(), free_port(), free_port()
        identity = secrets.token_hex(6)
        env = os.environ.copy()
        env.update({
            "APP_ENV": "testing", "APP_DEBUG": "false", "APP_KEY": "base64:" + base64.b64encode(secrets.token_bytes(32)).decode(),
            "APP_URL": f"http://127.0.0.1:{http_port}", "DB_CONNECTION": "sqlite", "DB_DATABASE": str(db),
            "DATABASE_URL": "", "CACHE_DRIVER": "redis", "QUEUE_CONNECTION": "redis", "SESSION_DRIVER": "array",
            "REDIS_CLIENT": "phpredis", "REDIS_HOST": "127.0.0.1", "REDIS_PORT": str(redis_port), "REDIS_DB": "0", "REDIS_CACHE_DB": "1",
            "REDIS_PREFIX": "sx_e2e_" + identity + "_", "CACHE_PREFIX": "sx_e2e_cache_" + identity + "_",
            "HORIZON_PREFIX": "sx_e2e_horizon_" + identity + "_", "LARAVEL_STORAGE_PATH": str(work / "storage"),
            "LOG_CHANNEL": "stderr", "SIMPLEXBOARD_E2E": "1", "E2E_BASE_URL": f"http://127.0.0.1:{http_port}",
            "E2E_WS_URL": f"ws://127.0.0.1:{ws_port}", "E2E_ADMIN_PATH": "private-panel", "E2E_ADMIN_EMAIL": "admin@example.test",
            "E2E_ADMIN_PASSWORD": secrets.token_urlsafe(24), "E2E_FIXTURE_FILE": str(work / "fixture.json"),
            "E2E_ARTIFACT_DIR": str(artifacts), "E2E_WORK_DIR": str(work), "E2E_PHP_BIN": args.php,
            "HORIZON_DATA_PIPELINE_MAX": "1", "HORIZON_BUSINESS_MAX": "1", "HORIZON_NOTIFICATION_MAX": "1",
        })
        # PHP child processes must use the same interpreter configuration as the parent.
        ini = subprocess.check_output([args.php, "-r", "echo php_ini_loaded_file();"], text=True).strip()
        if ini:
            env["PHPRC"] = ini
        env["PATH"] = str(Path(args.php).resolve().parent) + os.pathsep + env.get("PATH", "")

        def start(name, argv):
            handle = (artifacts / (name + ".log")).open("wb")
            logs.append(handle)
            child = subprocess.Popen(argv, cwd=REPO, env=env, stdout=handle, stderr=subprocess.STDOUT, start_new_session=True)
            children.append(child)
            return child

        def run(name, argv):
            with (artifacts / (name + ".log")).open("wb") as handle:
                result = subprocess.run(argv, cwd=REPO, env=env, stdout=handle, stderr=subprocess.STDOUT)
            if result.returncode:
                raise RuntimeError(f"{name} failed; see {artifacts / (name + '.log')}")

        def wait_port(port, child, seconds=20):
            end = time.monotonic() + seconds
            while time.monotonic() < end:
                if child.poll() is not None:
                    raise RuntimeError(f"Service exited with status {child.returncode}")
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=0.2):
                        return
                except OSError:
                    time.sleep(0.1)
            raise TimeoutError(f"Service did not bind port {port}")

        try:
            redis = start("redis", [args.redis, "--bind", "127.0.0.1", "--port", str(redis_port), "--save", "", "--appendonly", "no"])
            wait_port(redis_port, redis)
            run("migrate", [args.php, "artisan", "migrate", "--force", "--no-interaction"])
            run("seed", [args.php, "tests/Support/seed-panel.php"])
            horizon = start("horizon", [args.php, "artisan", "horizon", "--environment=local"])
            ws = start("websocket", [args.php, "artisan", "ws-server", "start", "--host=127.0.0.1", "--port=" + str(ws_port)])
            wait_port(ws_port, ws)
            http = start("http", [args.php, "artisan", "serve", "--host=127.0.0.1", "--port=" + str(http_port), "--no-reload"])
            wait_port(http_port, http)
            with urllib.request.urlopen(env["E2E_BASE_URL"], timeout=5) as response:
                if response.status != 200:
                    raise RuntimeError("Application health response failed")
            if not args.without_scheduler:
                start("scheduler", [args.php, "artisan", "schedule:work"])
            time.sleep(1)
            if horizon.poll() is not None:
                raise RuntimeError("Horizon did not stay running")
            print(json.dumps({"stack": "ready", "database": "disposable SQLite", "queue": "real Redis + Horizon", "websocket": "Workerman", "artifacts": str(artifacts)}), flush=True)
            result = subprocess.run(command, cwd=REPO, env=env)
            return result.returncode
        finally:
            for child in reversed(children):
                if child.poll() is None:
                    try:
                        os.killpg(child.pid, signal.SIGTERM)
                    except ProcessLookupError:
                        pass
            for child in reversed(children):
                try:
                    child.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    os.killpg(child.pid, signal.SIGKILL)
                    child.wait(timeout=3)
            for handle in logs:
                handle.close()


if __name__ == "__main__":
    raise SystemExit(main())

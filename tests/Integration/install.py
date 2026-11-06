#!/usr/bin/env python3
"""Exercise the real installer, generated configuration and first admin/member HTTP flow.

Everything is copied into a disposable application directory. The source .env,
database, storage and bootstrap cache are never used as writable runtime paths.
Only sanitized results and service logs are retained in --artifacts.
"""
import argparse
import json
import os
from pathlib import Path
import re
import shutil
import signal
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.request


REPO = Path(__file__).resolve().parents[2]


def free_port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def wait_port(port, child, timeout=15):
    end = time.monotonic() + timeout
    while time.monotonic() < end:
        if child.poll() is not None:
            raise AssertionError("A disposable service exited before becoming ready")
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=0.2):
                return
        except OSError:
            time.sleep(0.1)
    raise AssertionError("A disposable service did not bind its port")


def request(base_url, method, path, body=None, authorization=None):
    headers = {"Accept": "application/json"}
    if authorization:
        headers["Authorization"] = authorization
    if body is not None:
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(base_url + path, data=json.dumps(body).encode() if body is not None else None,
                                 method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=15) as response:
            return response.status, json.loads(response.read())
    except urllib.error.HTTPError as exception:
        return exception.code, {}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default=os.environ.get("PHP_BIN", "php"))
    parser.add_argument("--redis", default=os.environ.get("REDIS_SERVER_BIN", "redis-server"))
    parser.add_argument("--artifacts", default=str(REPO / "storage/framework/testing/install-artifacts"))
    args = parser.parse_args()
    artifacts = Path(args.artifacts).resolve()
    artifacts.mkdir(parents=True, exist_ok=True)
    children, handles, checks = [], [], []
    report = {"status": "failed", "scope": "Actual xboard:install CLI in a disposable copy, real Redis and HTTP", "checks": checks}

    def passed(name):
        checks.append(name)
        print(json.dumps({"check": name}), flush=True)

    try:
        with tempfile.TemporaryDirectory(prefix="simplexboard-install-") as temporary:
            work = Path(temporary)
            for name in ["artisan", "composer.json", "composer.lock", ".env.example", "app", "bootstrap", "config", "database", "routes", "resources", "lang", "public"]:
                source = REPO / name
                if source.is_dir():
                    shutil.copytree(source, work / name, ignore=shutil.ignore_patterns("cache", "__pycache__"))
                elif source.is_file():
                    shutil.copy2(source, work / name)
            # Composer's generated paths must resolve this copy's app/ directory.
            # Share dependency packages, but keep the generated loader local.
            (work / "vendor").mkdir()
            for dependency in (REPO / "vendor").iterdir():
                target = work / "vendor" / dependency.name
                if dependency.name == "composer":
                    shutil.copytree(dependency, target)
                elif dependency.name == "autoload.php":
                    shutil.copy2(dependency, target)
                else:
                    target.symlink_to(dependency, target_is_directory=dependency.is_dir())
            for directory in ["bootstrap/cache", ".docker/.data", "storage/logs", "storage/framework/cache/data",
                              "storage/framework/sessions", "storage/framework/views", "storage/app", "storage/backup"]:
                (work / directory).mkdir(parents=True, exist_ok=True)
            (work / ".env").touch()

            env = {key: value for key, value in os.environ.items() if not key.startswith(("APP_", "DB_", "DATABASE_", "REDIS_", "CACHE_", "QUEUE_", "SESSION_", "E2E_", "ENABLE_"))
                   and key not in ["INSTALLED", "ADMIN_ACCOUNT", "LARAVEL_STORAGE_PATH"]}
            php = str(Path(args.php).resolve()) if "/" in args.php else shutil.which(args.php)
            if not php:
                raise AssertionError("PHP executable was not found")
            ini = subprocess.check_output([php, "-r", "echo php_ini_loaded_file();"], text=True).strip()
            if ini:
                env["PHPRC"] = ini
            env["PATH"] = str(Path(php).parent) + os.pathsep + env.get("PATH", "")
            # Testing mode only enables Symfony's piped prompt fallback. The real
            # command, configuration writes and migrations execute unchanged.
            env.update({"APP_ENV": "testing", "ENABLE_SQLITE": "1", "ADMIN_ACCOUNT": "install-admin@example.test"})
            redis_port, http_port = free_port(), free_port()

            def start(name, argv):
                handle = (artifacts / (name + ".log")).open("wb")
                handles.append(handle)
                process = subprocess.Popen(argv, cwd=work, env=env, stdout=handle, stderr=subprocess.STDOUT, start_new_session=True)
                children.append(process)
                return process

            redis = start("redis", [args.redis, "--bind", "127.0.0.1", "--port", str(redis_port), "--save", "", "--appendonly", "no"])
            wait_port(redis_port, redis)

            # Laravel Prompts falls back to standard console questions for piped input.
            # On Docker hosts, decline the embedded Unix socket and use this TCP Redis.
            answers = ("no\n" if Path("/.dockerenv").exists() else "") + f"127.0.0.1\n{redis_port}\n\n"
            installed = subprocess.run([php, "artisan", "xboard:install", "--no-ansi"], cwd=work, env=env,
                                       input=answers, text=True, capture_output=True, timeout=90)
            output = installed.stdout + installed.stderr
            password_match = re.search(r"管理员密码[：:]\s*([^\r\n]+)", output)
            password = password_match.group(1).strip() if password_match else None
            path_match = re.search(r"访问 http\(s\)://你的站点/([a-zA-Z0-9_-]+)", output)
            admin_path = path_match.group(1) if path_match else None
            sanitized = output
            if password:
                sanitized = sanitized.replace(password, "[REDACTED]")
            if admin_path:
                sanitized = sanitized.replace("你的站点/" + admin_path, "你的站点/[ADMIN_PATH]")
            (artifacts / "install.log").write_text(sanitized)
            if installed.returncode != 0 or not password or not admin_path:
                raise AssertionError("Installer did not return success and generated administrator details; inspect sanitized install.log")

            config = dict(re.findall(r"^([A-Z_]+)=(.*)$", (work / ".env").read_text(), re.MULTILINE))
            assert config.get("INSTALLED") in ["true", "1"], "Installer did not persist completion"
            assert config.get("DB_CONNECTION") == "sqlite", "Installer did not persist SQLite configuration"
            assert config.get("APP_KEY", "").startswith("base64:"), "Installer did not persist an application key"
            assert config.get("CACHE_DRIVER") == "redis" and config.get("QUEUE_CONNECTION") == "redis", "Temporary installer driver overrides leaked into runtime configuration"
            assert not (work / "bootstrap/cache/config.php").exists(), "Installer left its temporary configuration cache in place"
            database = work / config["DB_DATABASE"]
            assert database.is_file() and database.is_relative_to(work), "Installer did not create the isolated database"
            with sqlite3.connect(database) as connection:
                connection.row_factory = sqlite3.Row
                assert connection.execute("PRAGMA integrity_check").fetchone()[0] == "ok", "Installed database failed integrity check"
                assert connection.execute("SELECT COUNT(*) FROM migrations WHERE migration='2026_11_06_000002_remove_member_plan_dependencies'").fetchone()[0] == 1, "Installer did not run the second-round migration"
                assert connection.execute("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('v2_plan','v2_server_group')").fetchone()[0] == 0, "Installer retained active Plan/group tables"
                administrator = connection.execute("SELECT * FROM v2_user WHERE is_admin=1").fetchone()
                assert administrator and administrator["email"] == env["ADMIN_ACCOUNT"], "Installer did not create the configured administrator"
                assert connection.execute("SELECT COUNT(*) FROM v2_user WHERE is_admin=0").fetchone()[0] == 0, "Installer created unwanted ordinary members"
            passed("Real installer persists settings, complete schema and one administrator")
            passed("Temporary install cache and queue overrides do not persist")

            # Application startup uses the generated .env, not the installer process's config.
            env.pop("ENABLE_SQLITE", None)
            env.pop("ADMIN_ACCOUNT", None)
            env.pop("APP_ENV", None)
            http = start("http", [php, "artisan", "serve", "--host=127.0.0.1", "--port=" + str(http_port), "--no-reload"])
            wait_port(http_port, http)
            base_url = f"http://127.0.0.1:{http_port}"
            status, login = request(base_url, "POST", "/api/v1/passport/auth/login", {"email": "install-admin@example.test", "password": password})
            assert status == 200 and login.get("data", {}).get("is_admin") is True, f"Generated administrator login returned HTTP {status}"
            auth = login["data"]["auth_data"]
            passed("Generated administrator password authenticates through HTTP")

            status, result = request(base_url, "GET", f"/api/v2/{admin_path}/user/fetch", authorization=auth)
            assert status == 200 and result.get("data") == [], f"Printed administrator path returned HTTP {status}"
            passed("Installer's printed administrator path reaches authenticated APIs")

            status, created = request(base_url, "POST", f"/api/v2/{admin_path}/user/create", {"name": "Fresh install member"}, authorization=auth)
            assert status == 200 and isinstance(created.get("data"), dict), f"First member creation returned HTTP {status}"
            member = created["data"]
            assert member["access_mode"] == "all" and member["transfer_enable"] is None and member["eligible"] is True, "First member does not use the intended default authorization and unlimited semantics"
            assert member.get("token") and member.get("uuid") and member.get("subscribe_url"), "First member has no subscription credentials"
            with sqlite3.connect(database) as connection:
                row = connection.execute("SELECT password,is_admin FROM v2_user WHERE id=?", [member["id"]]).fetchone()
                assert row == (None, 0), "First ordinary member unexpectedly has a login password or admin role"
            passed("First name-only member has subscription credentials and no login password")

            rerun = subprocess.run([php, "artisan", "xboard:install", "--no-interaction", "--no-ansi"], cwd=work, env=env,
                                   text=True, capture_output=True, timeout=30)
            assert "已经" in rerun.stdout or "重新安装" in rerun.stdout, "Repeated install did not report the existing installation"
            with sqlite3.connect(database) as connection:
                assert connection.execute("SELECT COUNT(*) FROM v2_user").fetchone()[0] == 2, "Repeated install changed existing users"
            passed("Repeated installer leaves the existing administrator and member intact")
            report["status"] = "passed"
    except Exception as exception:
        report["error"] = str(exception)
        print(json.dumps({"status": "failed", "error": str(exception)}), flush=True)
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
        for handle in handles:
            handle.close()
        (artifacts / "install-results.json").write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps({"status": report["status"], "checks": len(checks)}), flush=True)
    return 0 if report["status"] == "passed" else 1


if __name__ == "__main__":
    raise SystemExit(main())

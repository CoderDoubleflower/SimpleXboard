#!/usr/bin/env python3
"""Exercise panel-generated commands with real systemd on disposable CI VMs.

This intentionally requires GitHub Actions, run-stack.py, an explicit flag and
an empty standard installation. Never run it on a user's VPS. The candidate
release is served locally before publication; only its fixed download origin
and HTTPS-only transport restriction are changed in a private script copy.
"""
from __future__ import annotations

import argparse
import hashlib
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import platform
import shlex
import shutil
import subprocess
import threading
import time
from urllib.request import urlopen

from protocol_connections import API, CheckFailed, free_port, require


ROOT = Path(__file__).resolve().parents[2]
SERVICE = "xboard-node.service"
BINARY = "/usr/local/bin/xboard-node"
CLI = "/usr/local/bin/xbctl"
CONFIG = "/etc/xboard-node/config.yml"
CREDENTIALS = "/etc/xboard-node/credentials.env"
UNIT = "/etc/systemd/system/xboard-node.service"
BACKUPS = "/var/backups/simplexboard-node"


def sudo(*args, input=None, check=True):
    result = subprocess.run(["sudo", "-n", "--", *args], input=input, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if check and result.returncode:
        raise CheckFailed("Privileged fixture operation failed: " + args[0])
    return result


def fingerprint(path):
    return sudo("sha256sum", path).stdout.decode().split()[0]


def active():
    return sudo("systemctl", "is-active", "--quiet", SERVICE, check=False).returncode == 0


def wait(predicate, description, seconds=30):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        if predicate():
            return
        time.sleep(0.25)
    raise CheckFailed("Timed out: " + description)


def healthy(port):
    try:
        with urlopen(f"http://127.0.0.1:{port}/healthz", timeout=2) as response:
            return response.status == 200
    except OSError:
        return False


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--release-dir", type=Path, required=True)
    parser.add_argument("--original-node", type=Path, required=True)
    parser.add_argument("--allow-disposable-systemd-install", action="store_true")
    args = parser.parse_args()
    require(args.allow_disposable_systemd_install and os.environ.get("GITHUB_ACTIONS") == "true"
            and os.environ.get("SIMPLEXBOARD_E2E") == "1", "Use an explicitly authorized disposable GitHub Actions VM")
    require(Path("/run/systemd/system").is_dir(), "Real systemd is required")
    require(sudo("true", check=False).returncode == 0, "Passwordless CI sudo is required")
    # Refuse to touch any pre-existing node installation, configuration or backup.
    for path in [BINARY, CLI, UNIT, "/etc/xboard-node", BACKUPS, "/usr/bin/xbctl"]:
        require(sudo("test", "-e", path, check=False).returncode != 0
                and sudo("test", "-L", path, check=False).returncode != 0,
                "The disposable VM already contains node files")
    manifest = json.loads((ROOT / "resources/node/release.json").read_text())
    arch = {"x86_64": "amd64", "aarch64": "arm64"}.get(platform.machine())
    require(arch is not None, "Unexpected native architecture")
    work = Path(os.environ["E2E_WORK_DIR"]) / "node-maintenance-systemd"
    work.mkdir(mode=0o700)
    release = work / "release"
    shutil.copytree(args.release_dir, release)
    artifacts = Path(os.environ["E2E_ARTIFACT_DIR"])
    report = {"status": "running", "scope": "disposable-native-systemd", "architecture": arch, "checks": []}
    secret_values = [os.environ["E2E_ADMIN_PASSWORD"]]
    entered = False
    server = None

    def redact(text):
        for value in secret_values:
            if value:
                text = text.replace(value, "[redacted]")
        return text

    def record(name):
        report["checks"].append(name)
        print(json.dumps({"maintenance": name, "status": "passed"}), flush=True)

    try:
        class Handler(SimpleHTTPRequestHandler):
            def __init__(self, *a, **kw):
                super().__init__(*a, directory=str(release), **kw)

            def log_message(self, *_):
                pass

        server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        base = f"http://127.0.0.1:{server.server_port}"
        public_base = f"https://github.com/{manifest['repository']}/releases/download/{manifest['tag']}"
        manager = (release / manifest["script"]).read_text()
        require(manager.count("RELEASE_BASE='" + public_base + "'") == 1, "Candidate origin marker changed")
        manager = manager.replace("RELEASE_BASE='" + public_base + "'", "RELEASE_BASE='" + base + "'")
        manager = manager.replace("--proto '=https'", "--proto '=http,https'")
        (release / manifest["script"]).write_text(manager)

        def refresh_checksums():
            (release / "SHA256SUMS").write_text("".join(
                hashlib.sha256(p.read_bytes()).hexdigest() + "  " + p.name + "\n"
                for p in sorted(release.iterdir()) if p.is_file() and p.name != "SHA256SUMS"))

        refresh_checksums()
        api = API(os.environ["E2E_BASE_URL"], os.environ["E2E_ADMIN_PATH"])
        login = api.request("POST", "/api/v1/passport/auth/login", authenticated=False,
                            data={"email": os.environ["E2E_ADMIN_EMAIL"], "password": os.environ["E2E_ADMIN_PASSWORD"]})
        api.authorization = login["data"]["auth_data"]
        machine = api.admin("server/machine/save", {"name": "Native maintenance fixture"}, require_no_store=True)
        secret_values.append(machine["token"])
        commands = api.request("GET", api.admin_path + "server/machine/maintenanceCommands",
                               query={"id": machine["id"]}, require_no_store=True)["data"]
        for action in ("upgrade_command", "uninstall_command", "purge_command"):
            require(machine["token"] not in commands[action], "Maintenance command unnecessarily contains credentials")

        def run_command(key, name, expected=0, extra=()):
            nonlocal entered
            command = commands[key].replace(public_base + "/" + manifest["script"], base + "/" + manifest["script"])
            require(command != commands[key], "Panel command did not use the fixed release")
            if extra:
                command += " " + shlex.join(extra)
            entered = True
            argv = ["bash", "-c", command]
            process = subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                                       text=True, start_new_session=True)
            timed_out = False
            try:
                output, _ = process.communicate(timeout=240)
            except subprocess.TimeoutExpired:
                timed_out = True
                # sudo may have root-owned children. Kill this command's entire
                # private process group before finally removes fixture paths.
                sudo("kill", "-TERM", "--", "-" + str(process.pid), check=False)
                try:
                    process.communicate(timeout=5)
                except subprocess.TimeoutExpired:
                    pass
                # The shell can exit before an apt/installer child, so send KILL
                # to any remaining group members even if communicate completed.
                sudo("kill", "-KILL", "--", "-" + str(process.pid), check=False)
                try:
                    output, _ = process.communicate(timeout=5)
                except subprocess.TimeoutExpired:
                    # Do not race an unconfirmed writer by removing its files.
                    # This VM is disposable; report failure and leave its state.
                    entered = False
                    raise CheckFailed("Command process group did not terminate: " + name) from None
            result = subprocess.CompletedProcess(argv, process.returncode, output)
            (artifacts / ("maintenance-" + name + ".log")).write_text(redact(result.stdout))
            require(not timed_out, "Command timed out and its process group was terminated: " + name)
            require((result.returncode == 0) == (expected == 0), "Unexpected command result: " + name)
            return result

        health_port = free_port()
        run_command("install_command", "install", extra=("--health-port", str(health_port)))
        wait(lambda: active() and healthy(health_port), "fresh node service")
        require(manifest["version"] in sudo(BINARY, "-v").stdout.decode(), "Installed the wrong node build")
        wait(lambda: any(m["id"] == machine["id"] and m.get("managed_outbound_supported")
                         for m in api.admin("server/machine/fetch", method="GET")), "actual machine capability")
        record("panel_command_installs_real_node_and_connects_machine")

        preserved = {path: fingerprint(path) for path in [CONFIG, CREDENTIALS, UNIT, CLI]}
        # A real original v1.13 binary reproduces the existing deployment being upgraded.
        sudo("install", "-m", "755", str(args.original_node.resolve()), BINARY + ".fixture")
        sudo("mv", "-f", BINARY + ".fixture", BINARY)
        sudo("systemctl", "restart", SERVICE)
        wait(lambda: active() and healthy(health_port), "original node process")
        old_digest = fingerprint(BINARY)
        run_command("upgrade_command", "upgrade")
        require(manifest["version"] in sudo(BINARY, "-v").stdout.decode(), "Upgrade did not replace the original binary")
        require(all(fingerprint(path) == value for path, value in preserved.items()), "Upgrade changed config, credentials, unit or xbctl")
        backups = sudo("find", BACKUPS, "-mindepth", "2", "-maxdepth", "2", "-type", "f", "-name", "xboard-node").stdout.decode().splitlines()
        require(any(fingerprint(path) == old_digest for path in backups), "Original executable was not backed up")
        record("upgrade_preserves_credentials_config_unit_and_xbctl")
        record("upgrade_uses_existing_custom_health_port_and_backs_up_original")

        run_command("install_command", "install-existing", extra=("--panel", "http://127.0.0.1:1", "--machine-id", "999999"))
        require(all(fingerprint(path) == value for path, value in preserved.items()), "Install on an existing node rebound it")
        record("install_on_existing_service_preserves_original_binding")

        candidate = release / ("xboard-node-linux-" + arch)
        good_binary = candidate.read_bytes()
        before_hash = fingerprint(BINARY)
        before_pid = sudo("systemctl", "show", SERVICE, "--property=MainPID", "--value").stdout
        candidate.write_bytes(good_binary + b"corrupt fixture")
        run_command("upgrade_command", "checksum-failure", expected=1)
        require(fingerprint(BINARY) == before_hash and active(), "Checksum failure changed the installed executable")
        require(sudo("systemctl", "show", SERVICE, "--property=MainPID", "--value").stdout == before_pid, "Checksum failure restarted a healthy service")
        candidate.write_bytes(good_binary)
        record("checksum_failure_leaves_running_node_untouched")

        # A correctly checksummed but broken release exercises real systemd rollback.
        candidate.write_text("#!/usr/bin/env bash\nif [[ ${1:-} == -v ]]; then echo 'xboard-node "
                             + manifest["version"] + " (built failure-fixture)'; exit 0; fi\nexit 42\n")
        refresh_checksums()
        run_command("upgrade_command", "startup-failure", expected=1)
        require(fingerprint(BINARY) == before_hash, "Failed new process did not restore the old executable")
        wait(lambda: active() and healthy(health_port), "old process after automatic rollback")
        require(all(fingerprint(path) == value for path, value in preserved.items()), "Rollback changed existing files")
        candidate.write_bytes(good_binary)
        refresh_checksums()
        record("failed_start_restores_old_binary_and_actual_healthy_service")

        # Disabled health checks must use process stability rather than readiness.
        no_health = {"kernel": {"type": "singbox"}, "health_port": 0,
                     "instances": [{"panel": {"url": os.environ["E2E_BASE_URL"]},
                                    "machine": {"machine_id": machine["id"], "token": machine["token"]}, "health_port": 0}]}
        sudo("tee", CONFIG, input=json.dumps(no_health).encode())
        sudo("chmod", "600", CONFIG)
        sudo("systemctl", "restart", SERVICE)
        wait(active, "health-disabled service")
        preserved_config = fingerprint(CONFIG)
        run_command("upgrade_command", "health-disabled")
        require(active() and fingerprint(CONFIG) == preserved_config, "Disabled health endpoint was not preserved")
        record("disabled_health_endpoint_does_not_cause_false_rollback")

        run_command("uninstall_command", "uninstall-preserve")
        require(not active() and sudo("test", "-e", BINARY, check=False).returncode != 0, "Uninstall left a running node")
        require(sudo("test", "-e", UNIT, check=False).returncode != 0, "Uninstall left the standard systemd unit")
        require(fingerprint(CONFIG) == preserved_config and fingerprint(CREDENTIALS) == preserved[CREDENTIALS], "Default uninstall removed or changed credentials")
        record("uninstall_removes_service_and_programs_but_preserves_configuration")

        run_command("install_command", "restore-retained")
        require(active() and fingerprint(CONFIG) == preserved_config, "Reinstall did not restore retained configuration")
        require(fingerprint(CREDENTIALS) == preserved[CREDENTIALS], "Reinstall rotated retained credentials")
        record("one_command_reinstall_restores_retained_configuration")

        run_command("purge_command", "uninstall-purge")
        require(not active() and sudo("test", "-e", "/etc/xboard-node", check=False).returncode != 0, "Explicit purge retained active configuration")
        require(sudo("test", "-d", BACKUPS, check=False).returncode == 0, "Purge unexpectedly removed independent backups")
        machines = api.admin("server/machine/fetch", method="GET")
        require(any(item["id"] == machine["id"] for item in machines), "Node uninstall removed a panel machine record")
        record("explicit_purge_removes_active_config_and_preserves_backups_and_panel_record")
        report["status"] = "passed"
        return 0
    except Exception as error:
        report["status"] = "failed"
        report["error"] = str(error) if isinstance(error, CheckFailed) else type(error).__name__
        raise CheckFailed(report["error"]) from None
    finally:
        if entered:
            logs = sudo("journalctl", "-u", SERVICE, "-n", "100", "--no-pager", check=False)
            (artifacts / "maintenance-journal.log").write_text(redact(logs.stdout.decode(errors="replace")))
            sudo("systemctl", "disable", "--now", SERVICE, check=False)
            sudo("rm", "-f", BINARY, CLI, UNIT, "/usr/bin/xbctl", check=False)
            sudo("rm", "-rf", "/etc/xboard-node", BACKUPS, "/run/simplexboard-node-maintenance", check=False)
            sudo("systemctl", "daemon-reload", check=False)
        if server:
            server.shutdown()
            server.server_close()
        (artifacts / "node-maintenance-systemd-results.json").write_text(json.dumps(report, indent=2) + "\n")


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except CheckFailed as error:
        print("Native maintenance verification failed: " + str(error))
        raise SystemExit(1)

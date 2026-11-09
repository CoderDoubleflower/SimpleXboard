#!/usr/bin/env python3
"""Exercise node-manager's transactions without touching host services or files.

The production script has no test-directory, download-source or checksum bypass.
Each case copies it into a private temporary root and rewrites an explicit list
of literal constants in that copy. Fake systemctl/curl commands operate only on
that root; SHA-256 checks, files, backups, atomic replacements and flock are real.
Real systemd and published binaries are covered by node_maintenance_systemd.py.
"""
from __future__ import annotations

import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile
import textwrap
import unittest


REPO = Path(__file__).resolve().parents[2]
SOURCE = REPO / "scripts/node-manager.sh"
VERSION = "v1.13-simplexboard.2"


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def executable(path, text):
    path.write_text(text)
    path.chmod(0o755)


class NodeMaintenance(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="sx-maintenance-test-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.bin = self.root / "usr/local/bin/xboard-node"
        self.cli = self.root / "usr/local/bin/xbctl"
        self.link = self.root / "usr/bin/xbctl"
        self.config = self.root / "etc/xboard-node"
        self.unit = self.root / "etc/systemd/system/xboard-node.service"
        self.backups = self.root / "var/backups/simplexboard-node"
        self.lock = self.root / "run/simplexboard-node-maintenance"
        self.release = self.root / "release"
        self.fakebin = self.root / "fakebin"
        for path in (self.bin.parent, self.link.parent, self.unit.parent, self.release,
                     self.fakebin, self.root / "run/systemd/system", self.root / "tmp"):
            path.mkdir(parents=True, exist_ok=True)
        self.state_file = self.root / "state.json"
        self.state_file.write_text(json.dumps({"active": False, "events": [], "downloads": [], "health": [], "uid": 0}))
        self.write_commands()
        self.write_release()
        source = SOURCE.read_text()
        replacements = {
            "export PATH='/usr/sbin:/usr/bin:/sbin:/bin'": f"export PATH='{self.fakebin}:/usr/sbin:/usr/bin:/sbin:/bin'",
            "readonly NODE_BINARY='/usr/local/bin/xboard-node'": f"readonly NODE_BINARY='{self.bin}'",
            "readonly CLI_BINARY='/usr/local/bin/xbctl'": f"readonly CLI_BINARY='{self.cli}'",
            "readonly CLI_LINK='/usr/bin/xbctl'": f"readonly CLI_LINK='{self.link}'",
            "readonly CONFIG_ROOT='/etc/xboard-node'": f"readonly CONFIG_ROOT='{self.config}'",
            "readonly UNIT_FILE='/etc/systemd/system/xboard-node.service'": f"readonly UNIT_FILE='{self.unit}'",
            "readonly BACKUP_ROOT='/var/backups/simplexboard-node'": f"readonly BACKUP_ROOT='{self.backups}'",
            "readonly LOCK_ROOT='/run/simplexboard-node-maintenance'": f"readonly LOCK_ROOT='{self.lock}'",
            "readonly SYSTEMD_RUNTIME='/run/systemd/system'": f"readonly SYSTEMD_RUNTIME='{self.root / 'run/systemd/system'}'",
            "readonly TEMP_TEMPLATE='/tmp/simplexboard-node.XXXXXXXX'": f"readonly TEMP_TEMPLATE='{self.root / 'tmp/simplexboard-node.XXXXXXXX'}'",
            "readonly HEALTH_DELAY=1": "readonly HEALTH_DELAY=0",
            '[[ "$CONFIG_ROOT" == /etc/xboard-node && ! -L "$CONFIG_ROOT" ]]': f'[[ "$CONFIG_ROOT" == {self.config} && ! -L "$CONFIG_ROOT" ]]',
        }
        for before, after in replacements.items():
            self.assertEqual(source.count(before), 1, "The isolated harness must match exactly one production constant")
            source = source.replace(before, after)
        self.script = self.root / "node-manager.sh"
        executable(self.script, source)

    def state(self, **updates):
        value = json.loads(self.state_file.read_text())
        if updates:
            value.update(updates)
            self.state_file.write_text(json.dumps(value))
        return value

    def invoke(self, *args, ok=True):
        result = subprocess.run(["bash", str(self.script), *args], text=True, capture_output=True, timeout=20)
        if ok:
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        else:
            self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertNotIn("fixture-secret-token", result.stdout + result.stderr)
        return result

    def write_commands(self):
        program = f"#!{sys.executable}\nROOT = {str(self.root)!r}\n" + textwrap.dedent(r'''
            import json, os, pathlib, shutil, subprocess, sys
            root = pathlib.Path(ROOT)
            state_file = root / "state.json"
            state = json.loads(state_file.read_text())
            name = pathlib.Path(sys.argv[0]).name
            args = sys.argv[1:]
            binary = root / "usr/local/bin/xboard-node"
            unit = root / "etc/systemd/system/xboard-node.service"
            def save(): state_file.write_text(json.dumps(state))
            def done(code=0): save(); raise SystemExit(code)
            if name == "id": print(state.get("uid", 0)); done()
            if name == "uname": print(state.get("arch", "x86_64") if "-m" in args else "Linux"); done()
            if name == "systemd-detect-virt": done(0 if state.get("container") else 1)
            if name == "docker": print(state.get("docker", "")); done()
            if name == "sudo":
                state["uid"] = 0; state["elevated"] = True; save()
                if args[0] == "--": args.pop(0)
                os.execvp(args[0], args)
            if name == "systemctl":
                action = args[0]
                if action == "show":
                    prop = next(x.split("=", 1)[1] for x in args if x.startswith("--property="))
                    if prop == "LoadState": print("loaded" if unit.exists() else "not-found")
                    if prop == "FragmentPath": print(state.get("fragment", str(unit)))
                    if prop == "ExecStart":
                        command = state.get("execstart") or next(x.split("=", 1)[1] for x in unit.read_text().splitlines() if x.startswith("ExecStart="))
                        print("{ path=" + command.split()[0] + " ; argv[]=" + command + " ; ignore_errors=no ; }")
                    done()
                if action == "is-active": done(0 if state.get("active") else 3)
                if action in ("restart", "start"):
                    new = "built release" in binary.read_text()
                    state["events"].append({"action": action, "new": new})
                    if state.get("fail_all_start") or (new and state.get("fail_new_start")):
                        state["active"] = False; done(1)
                    state["active"] = True; done()
                state["events"].append({"action": action})
                if action in ("stop", "disable"):
                    if state.get("fail_stop"): done(1)
                    state["active"] = False
                done()
            if name == "curl":
                url = args[-1]
                if url.startswith("http://127.0.0.1:"):
                    state["health"].append(url)
                    new = binary.exists() and "built release" in binary.read_text()
                    done(22 if new and state.get("fail_new_health") else 0)
                asset = url.rsplit("/", 1)[1]
                state["downloads"].append(asset)
                if state.get("offline"): done(7)
                target = pathlib.Path(args[args.index("--output") + 1])
                source = root / "release" / asset
                if not source.exists(): done(22)
                shutil.copyfile(source, target)
                done()
            raise RuntimeError("Unexpected isolated command: " + name)
        ''')
        executable(self.fakebin / "dispatch", program)
        for name in ("id", "uname", "systemd-detect-virt", "docker", "sudo", "systemctl", "curl"):
            (self.fakebin / name).symlink_to("dispatch")

    def write_release(self):
        node = f"#!/bin/sh\nprintf '%s\\n' 'xboard-node {VERSION} (built release)'\n"
        cli = f"#!{sys.executable}\n" + textwrap.dedent(r'''
            import pathlib, re, sys
            if sys.argv[1] == "version": print("xbctl fixture (built release)")
            else:
                data = pathlib.Path(sys.argv[sys.argv.index("--config") + 1]).read_text()
                if "instances:\n" in data:
                    data = data.split("instances:\n", 1)[1].split("\n  - ", 1)[0]
                match = re.search(r"(?m)^\s*(?:-\s*)?health_port:\s*['\"]?(\d+)", data)
                if "health_port:" in data and not match: raise SystemExit(1)
                print(match.group(1) if match else 0)
        ''')
        for arch in ("amd64", "arm64"):
            executable(self.release / f"xboard-node-linux-{arch}", node)
            executable(self.release / f"xbctl-linux-{arch}", cli)
        installer = textwrap.dedent(f'''\
            #!/bin/bash
            set -euo pipefail
            shift
            health=65530
            while (( $# )); do
                case "$1" in
                    --binary) binary="$2"; shift 2;;
                    --xbctl-binary) cli="$2"; shift 2;;
                    --health-port) health="$2"; shift 2;;
                    *) shift 2;;
                esac
            done
            install -d -m 700 '{self.config}'
            printf 'health_port: %s\\n' "$health" > '{self.config / "config.yml"}'
            printf 'fixture-secret-token\\n' > '{self.config / "credentials.env"}'
            install -m 755 "$binary" '{self.bin}'
            install -m 755 "$cli" '{self.cli}'
            printf '[Service]\\nExecStart={self.bin} -c {self.config / "config.yml"}\\n' > '{self.unit}'
            systemctl daemon-reload
            systemctl enable xboard-node.service
            systemctl restart xboard-node.service
        ''')
        executable(self.release / "upstream-install.sh", installer)
        self.update_manifest()

    def update_manifest(self):
        entries = [f"{digest(p)}  {p.name}\n" for p in sorted(self.release.iterdir()) if p.name != "SHA256SUMS"]
        (self.release / "SHA256SUMS").write_text("".join(entries))

    def existing(self, port=63456):
        executable(self.bin, "#!/bin/sh\nprintf '%s\\n' 'xboard-node v1.13 (built previous)'\n")
        executable(self.cli, "#!/bin/sh\nprintf '%s\\n' 'xbctl previous (built old)'\n")
        self.link.symlink_to(self.cli)
        self.config.mkdir(mode=0o700)
        (self.config / "config.yml").write_text(f"health_port: 65529\ninstances:\n  - health_port: {port}\n    machine:\n      machine_id: 7\n  - health_port: 65528\n    machine:\n      machine_id: 8\n")
        (self.config / "credentials.env").write_text("SECRET=fixture-secret-token\n")
        (self.config / "credentials.env").chmod(0o600)
        (self.config / "certificate.pem").write_text("fixture certificate\n")
        self.unit.write_text(f"[Service]\nExecStart={self.bin} -c {self.config / 'config.yml'}\nEnvironment=EXISTING_OPTION=preserved\n")
        self.state(active=True)

    def config_snapshot(self):
        return {p.name: (p.read_bytes(), stat.S_IMODE(p.stat().st_mode)) for p in self.config.iterdir() if p.is_file()}

    def test_upgrade_preserves_all_configuration_unit_and_cli(self):
        self.existing()
        original, cli_hash, unit_data, config_data = self.bin.read_bytes(), digest(self.cli), self.unit.read_bytes(), self.config_snapshot()
        self.invoke("upgrade")
        self.assertIn(VERSION, self.bin.read_text())
        self.assertEqual(digest(self.cli), cli_hash)
        self.assertEqual(self.unit.read_bytes(), unit_data)
        self.assertEqual(self.config_snapshot(), config_data)
        backup = next(self.backups.iterdir())
        self.assertEqual((backup / "xboard-node").read_bytes(), original)
        self.assertEqual((backup / "config/credentials.env").read_bytes(), config_data["credentials.env"][0])
        self.assertEqual(stat.S_IMODE(backup.stat().st_mode), 0o700)
        self.assertTrue(all(x == "http://127.0.0.1:63456/healthz" for x in self.state()["health"]))
        self.assertGreaterEqual(len(self.state()["health"]), 3)
        self.assertEqual(list((self.root / "tmp").iterdir()), [])

    def test_bad_checksum_never_changes_running_service(self):
        self.existing()
        previous = self.bin.read_bytes()
        (self.release / "xboard-node-linux-amd64").write_text("truncated download")
        self.invoke("upgrade", ok=False)
        self.assertEqual(self.bin.read_bytes(), previous)
        self.assertEqual(self.state()["events"], [])
        self.assertTrue(self.state()["active"])
        self.assertFalse(self.backups.exists())

    def test_duplicate_manifest_entry_is_rejected(self):
        self.existing()
        manifest = self.release / "SHA256SUMS"
        manifest.write_text(manifest.read_text() + f"{digest(self.release / 'xboard-node-linux-amd64')}  xboard-node-linux-amd64\n")
        self.invoke("upgrade", ok=False)
        self.assertEqual(self.state()["events"], [])

    def test_wrong_version_is_rejected_even_with_valid_checksum(self):
        self.existing()
        executable(self.release / "xboard-node-linux-amd64", "#!/bin/sh\necho 'xboard-node upstream-latest (built other)'\n")
        self.update_manifest()
        self.invoke("upgrade", ok=False)
        self.assertEqual(self.state()["events"], [])

    def test_start_failure_restores_binary_and_preserves_configuration(self):
        self.existing()
        previous, config_data = self.bin.read_bytes(), self.config_snapshot()
        self.state(fail_new_start=True)
        result = self.invoke("upgrade", ok=False)
        self.assertEqual(self.bin.read_bytes(), previous)
        self.assertEqual(self.config_snapshot(), config_data)
        self.assertTrue(self.state()["active"])
        self.assertEqual([x.get("new") for x in self.state()["events"] if x["action"] == "restart"], [True, False])
        self.assertIn("已恢复原节点程序", result.stdout)

    def test_health_failure_restores_old_binary(self):
        self.existing(port=65001)
        previous = self.bin.read_bytes()
        self.state(fail_new_health=True)
        self.invoke("upgrade", ok=False)
        self.assertEqual(self.bin.read_bytes(), previous)
        self.assertTrue(self.state()["active"])
        self.assertTrue(all(":65001/healthz" in x for x in self.state()["health"]))

    def test_disabled_health_checks_only_stable_process(self):
        self.existing(port=0)
        self.invoke("upgrade")
        self.assertEqual(self.state()["health"], [])
        self.assertTrue(self.state()["active"])

    def test_invalid_health_config_does_not_touch_running_service(self):
        self.existing()
        (self.config / "config.yml").write_text("health_port: bad-port\n")
        previous = self.bin.read_bytes()
        self.invoke("upgrade", ok=False)
        self.assertEqual(self.bin.read_bytes(), previous)
        self.assertEqual(self.state()["events"], [])

    def test_absent_health_port_is_disabled(self):
        self.existing()
        (self.config / "config.yml").write_text("kernel:\n  type: singbox\n")
        self.invoke("upgrade")
        self.assertEqual(self.state()["health"], [])

    def test_install_on_existing_service_preserves_binding_and_multiple_instances(self):
        self.existing()
        snapshot = self.config_snapshot()
        self.invoke("install", "--mode", "machine", "--panel", "https://other.example", "--token", "fixture-secret-token", "--machine-id", "999")
        self.assertEqual(self.config_snapshot(), snapshot)
        self.assertNotIn("upstream-install.sh", self.state()["downloads"])

    def test_uninstall_is_offline_and_retains_configuration_and_backup(self):
        self.existing()
        snapshot = self.config_snapshot()
        self.state(offline=True)
        self.invoke("uninstall")
        self.assertFalse(self.bin.exists())
        self.assertFalse(self.cli.exists())
        self.assertFalse(self.link.is_symlink())
        self.assertFalse(self.unit.exists())
        self.assertEqual(self.config_snapshot(), snapshot)
        self.assertTrue(self.backups.exists())
        self.assertEqual(self.state()["downloads"], [])

    def test_reinstall_restores_retained_configuration_without_rebinding(self):
        self.existing(port=0)
        snapshot = self.config_snapshot()
        self.invoke("uninstall")
        self.invoke("install", "--machine-id", "999")
        self.assertEqual(self.config_snapshot(), snapshot)
        self.assertTrue(self.state()["active"])
        self.assertIn(VERSION, self.bin.read_text())
        self.assertTrue(self.unit.exists())

    def test_failed_reinstall_removes_new_programs_but_keeps_original_config(self):
        self.existing(port=0)
        snapshot = self.config_snapshot()
        self.invoke("uninstall")
        self.state(fail_new_start=True)
        self.invoke("install", ok=False)
        self.assertEqual(self.config_snapshot(), snapshot)
        self.assertFalse(self.bin.exists())
        self.assertFalse(self.cli.exists())
        self.assertFalse(self.unit.exists())

    def test_purge_is_explicit_and_leaves_independent_backup(self):
        self.existing()
        self.invoke("uninstall", "--purge")
        self.assertFalse(self.config.exists())
        self.assertTrue((next(self.backups.iterdir()) / "config/credentials.env").exists())

    def test_purge_can_remove_previously_retained_configuration(self):
        self.existing()
        self.invoke("uninstall")
        self.invoke("uninstall", "--purge")
        self.assertFalse(self.config.exists())

    def test_custom_execstart_is_rejected_before_upgrade_or_uninstall(self):
        self.existing()
        self.state(execstart="/usr/local/bin/another-service --secret value")
        for action in ("upgrade", "uninstall"):
            self.invoke(action, ok=False)
        self.assertEqual(self.state()["events"], [])
        self.assertEqual(self.state()["downloads"], [])
        self.assertTrue(self.config.exists())

    def test_container_and_unsupported_architecture_are_rejected(self):
        self.existing()
        self.state(container=True)
        self.invoke("upgrade", ok=False)
        self.state(container=False, arch="riscv64")
        self.invoke("uninstall", ok=False)
        self.assertEqual(self.state()["events"], [])
        self.assertEqual(self.state()["downloads"], [])

    def test_running_docker_node_is_rejected_on_host(self):
        self.existing()
        self.state(docker='ghcr.io/cedar2025/xboard-node:latest node "xboard-node"')
        self.invoke("uninstall", ok=False)
        self.assertTrue(self.bin.exists())
        self.assertEqual(self.state()["events"], [])

    def test_arm64_chooses_matching_fixed_release_assets(self):
        self.existing(port=0)
        self.state(arch="aarch64")
        self.invoke("upgrade")
        self.assertIn("xboard-node-linux-arm64", self.state()["downloads"])
        self.assertNotIn("xboard-node-linux-amd64", self.state()["downloads"])

    def test_unprivileged_invocation_elevates_same_script_and_action(self):
        self.existing(port=0)
        self.state(uid=1000)
        self.invoke("upgrade")
        self.assertTrue(self.state()["elevated"])
        self.assertIn(VERSION, self.bin.read_text())

    def test_concurrent_invocation_is_rejected(self):
        self.existing()
        self.lock.mkdir(mode=0o700)
        with (self.lock / "lock").open("w") as locked:
            fcntl.flock(locked, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.invoke("upgrade", ok=False)
        self.assertEqual(self.state()["events"], [])
        self.assertEqual(self.state()["downloads"], [])

    def test_symlinked_configuration_is_never_purged(self):
        self.existing()
        other = self.root / "other-application"
        self.config.rename(other)
        self.config.symlink_to(other)
        self.invoke("uninstall", "--purge", ok=False)
        self.assertTrue((other / "credentials.env").exists())
        self.assertEqual(self.state()["events"], [])

    def test_unrelated_cli_symlink_is_not_deleted(self):
        self.existing()
        self.link.unlink()
        other = self.root / "other-cli"
        executable(other, "#!/bin/sh\nexit 0\n")
        self.link.symlink_to(other)
        self.invoke("uninstall")
        self.assertEqual(self.link.resolve(), other)

    def test_cannot_stop_service_does_not_remove_program_or_config(self):
        self.existing()
        self.state(fail_stop=True)
        self.invoke("uninstall", "--purge", ok=False)
        self.assertTrue(self.bin.exists())
        self.assertTrue(self.config.exists())

    def test_rollback_failure_reports_backup_for_manual_recovery(self):
        self.existing(port=0)
        previous = self.bin.read_bytes()
        self.state(fail_all_start=True)
        result = self.invoke("upgrade", ok=False)
        self.assertEqual(self.bin.read_bytes(), previous)
        self.assertIn("自动恢复未完成", result.stderr)
        self.assertTrue((next(self.backups.iterdir()) / "xboard-node").exists())

    def test_fresh_install_uses_verified_local_binaries_and_installer(self):
        self.invoke("install", "--panel", "https://panel.example", "--token", "fixture-secret-token", "--machine-id", "7", "--health-port", "0")
        self.assertIn(VERSION, self.bin.read_text())
        self.assertTrue(self.state()["active"])
        self.assertIn("upstream-install.sh", self.state()["downloads"])
        self.assertEqual(self.state()["health"], [])

    def test_corrupt_upstream_installer_is_rejected_before_install(self):
        (self.release / "upstream-install.sh").write_text("corrupt")
        self.invoke("install", "--machine-id", "7", ok=False)
        self.assertFalse(self.bin.exists())
        self.assertFalse(self.config.exists())
        self.assertEqual(self.state()["events"], [])

    def test_download_override_and_purge_on_upgrade_are_rejected(self):
        self.existing()
        for option in ("--binary-url", "--skip-checksum", "--purge", "--version"):
            self.invoke("upgrade", option, ok=False)
        self.assertEqual(self.state()["events"], [])
        self.assertEqual(self.state()["downloads"], [])


if __name__ == "__main__":
    unittest.main(verbosity=2)

#!/usr/bin/env python3
"""Package verified native node builds and publish an immutable public release."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tarfile
import tempfile

ROOT = Path(__file__).resolve().parents[1]
UPSTREAM = "0a29338e1f102a462363ce3527417029f89bab28"


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def manifest():
    data = json.loads((ROOT / "resources/node/release.json").read_text())
    if data["repository"] != "CoderDoubleflower/SimpleXboard" or not re.fullmatch(r"v[0-9.]+-simplexboard\.[0-9]+", data["version"]):
        raise ValueError("Unexpected node release identity")
    if data["tag"] != "node-" + data["version"] or data["script"] != "node-manager.sh" or data["platforms"] != ["linux-amd64", "linux-arm64"]:
        raise ValueError("Unexpected node release manifest")
    manager = (ROOT / "scripts/node-manager.sh").read_text()
    base = f"https://github.com/{data['repository']}/releases/download/{data['tag']}"
    if f"VERSION='{data['version']}'" not in manager or f"RELEASE_BASE='{base}'" not in manager:
        raise ValueError("Node manager and panel release versions disagree")
    return data


def build_info(data):
    inputs = ["resources/node/release.json", "scripts/node-manager.sh",
              "patches/xboard-node/v1.13-core-lifecycle.patch", "patches/xboard-node/v1.13-managed-outbounds.patch",
              "patches/xboard-node/v1.13-node-maintenance.patch",
              "patches/xboard-node/v1.13-server-certificates.patch"]
    return {"version": data["version"], "upstream_commit": UPSTREAM,
            "inputs": {name: digest(ROOT / name) for name in inputs}}


def checksums(directory):
    files = sorted(path for path in directory.iterdir() if path.is_file() and path.name != "SHA256SUMS")
    (directory / "SHA256SUMS").write_text("".join(f"{digest(path)}  {path.name}\n" for path in files))


def stage(args, data):
    source, output = args.node_source.resolve(), args.output.resolve()
    actual = subprocess.check_output(["git", "-C", str(source), "rev-parse", "HEAD"], text=True).strip()
    if actual != UPSTREAM:
        raise ValueError("Node sources must use the pinned upstream commit")
    output.mkdir(parents=True, exist_ok=True)
    if list(output.iterdir()):
        raise ValueError("Release staging directory must be empty")
    for original, name in [(args.binary, f"xboard-node-linux-{args.arch}"),
                           (args.xbctl, f"xbctl-linux-{args.arch}"),
                           (ROOT / "scripts/node-manager.sh", "node-manager.sh"),
                           (source / "install.sh", "upstream-install.sh")]:
        shutil.copyfile(original, output / name)
        (output / name).chmod(0o755)
    (output / "release.json").write_text(json.dumps(data, indent=2) + "\n")
    (output / "build-info.json").write_text(json.dumps(build_info(data), indent=2) + "\n")
    # Include the complete patched node source alongside the executable releases.
    if args.arch == "amd64":
        with tarfile.open(output / "xboard-node-source.tar.gz", "w:gz") as archive:
            for path in sorted(source.rglob("*")):
                relative = path.relative_to(source)
                if ".git" in relative.parts or not path.is_file():
                    continue
                archive.add(path, arcname="xboard-node/" + relative.as_posix(), recursive=False)
    checksums(output)
    print(json.dumps({"staged": str(output), "platform": "linux-" + args.arch, "version": data["version"]}))


def gh(*arguments, check=True):
    return subprocess.run(["gh", *arguments], text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=check)


def publish(args, data):
    if os.environ.get("GITHUB_EVENT_NAME") != "push" or os.environ.get("GITHUB_REF") != "refs/heads/master" or os.environ.get("GITHUB_REPOSITORY") != data["repository"]:
        raise ValueError("Public node releases may only be published by this repository's master push workflow")
    sha = os.environ["GITHUB_SHA"]
    required = {"node-manager.sh", "upstream-install.sh", "release.json", "build-info.json", "xboard-node-source.tar.gz",
                "xboard-node-linux-amd64", "xboard-node-linux-arm64", "xbctl-linux-amd64", "xbctl-linux-arm64"}
    with tempfile.TemporaryDirectory(prefix="simplexboard-node-release-") as temporary:
        output = Path(temporary) / "release"
        output.mkdir()
        for platform in data["platforms"]:
            part = args.input / ("node-release-" + platform)
            subprocess.run(["sha256sum", "--check", "SHA256SUMS"], cwd=part, check=True)
            for path in part.iterdir():
                if not path.is_file() or path.name == "SHA256SUMS":
                    continue
                destination = output / path.name
                if destination.exists() and digest(destination) != digest(path):
                    raise ValueError("Native builds disagree about shared release input: " + path.name)
                shutil.copyfile(path, destination)
        if {p.name for p in output.iterdir()} != required:
            raise ValueError("Native release asset set is incomplete")
        expected_info = build_info(data)
        if json.loads((output / "build-info.json").read_text()) != expected_info:
            raise ValueError("Release artifacts do not match this source revision")
        checksums(output)
        repository, tag = data["repository"], data["tag"]
        existing = gh("release", "view", tag, "--repo", repository, "--json", "isDraft,assets,url", check=False)
        if existing.returncode == 0:
            details = json.loads(existing.stdout)
            if details["isDraft"]:
                raise ValueError("An unfinished draft already exists; inspect it before retrying publication")
            prior = Path(temporary) / "prior"
            prior.mkdir()
            gh("release", "download", tag, "--repo", repository, "--pattern", "build-info.json", "--dir", str(prior))
            if json.loads((prior / "build-info.json").read_text()) != expected_info:
                raise ValueError("Published node release is immutable; bump version and tag before changing its inputs")
            if not (required | {"SHA256SUMS"}).issubset({asset["name"] for asset in details["assets"]}):
                raise ValueError("Existing public release is missing required assets")
            print("Verified existing immutable node release: " + details["url"])
            return
        if "release not found" not in existing.stderr.lower() and "404" not in existing.stderr:
            raise RuntimeError("Could not check the existing release: " + existing.stderr.strip())
        notes = Path(temporary) / "notes.md"
        notes.write_text(f"""## SimpleXboard 节点 {data['version']}

面板的 VPS → 节点维护会生成安装、升级 / 替换和卸载命令。程序自动识别 Linux amd64 / arm64，校验下载文件；升级保留现有配置、凭据和服务设置，自动备份并在新程序启动失败时尝试恢复旧版。

此版本包含核心生命周期修复和六协议出站管理。两种架构均通过真实核心、Mihomo 流量和 systemd 安装 / 升级 / 回滚 / 卸载验收。

默认卸载保留配置；`--purge` 才删除配置。默认安装范围为 systemd 管理的 xboard-node；自定义服务和容器部署会明确提示。

- 面板源码：{sha}
- 节点基线：cedar2025/Xboard-Node@{UPSTREAM}
- `SHA256SUMS`：所有下载文件校验和。
- `build-info.json`：固定上游与补丁 / 管理脚本的内容摘要。
- `xboard-node-source.tar.gz`：对应的完整已打补丁节点源码。
- [使用说明](https://github.com/{repository}/blob/{sha}/docs/node-maintenance.md)

已有配置不需要重新创建机器或更换 token；升级会短暂重启该 VPS 的节点服务。
""")
        assets = [str(path) for path in sorted(output.iterdir())]
        result = gh("release", "create", tag, *assets, "--repo", repository, "--target", sha,
                    "--title", "SimpleXboard Node " + data["version"], "--notes-file", str(notes), "--latest=false")
        print(result.stdout.strip())


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    package = commands.add_parser("stage")
    package.add_argument("--arch", choices=["amd64", "arm64"], required=True)
    package.add_argument("--node-source", type=Path, required=True)
    package.add_argument("--binary", type=Path, required=True)
    package.add_argument("--xbctl", type=Path, required=True)
    package.add_argument("--output", type=Path, required=True)
    release = commands.add_parser("publish")
    release.add_argument("--input", type=Path, required=True)
    args = parser.parse_args()
    (stage if args.command == "stage" else publish)(args, manifest())


if __name__ == "__main__":
    main()

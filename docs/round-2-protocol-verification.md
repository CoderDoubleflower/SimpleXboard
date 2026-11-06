# 第二轮真实协议连接验证

**GitHub CI 已实际通过 VLESS + REALITY 与 Hysteria2 的本机连接验证。** 2026-11-06 的 [run 42291285755 / protocols job 128145060006](https://github.com/CoderDoubleflower/SimpleXboard/actions/runs/42291285755/job/128145060006) 对应分支提交 `008147179a61907f67b5f16214141406d6a486fa`，该 job 的安装、连接测试与证据上传步骤均为 `success`。

| 协议 | HTTP 响应字节数 | 单次请求耗时 | 原样运行订阅 JSON | TLS 校验 | 停止服务端后的请求 |
| --- | ---: | ---: | --- | --- | --- |
| VLESS + REALITY | 67 | 11.3 ms | 通过 | 保持开启 | 按预期失败 |
| Hysteria2 | 67 | 18.0 ms | 通过 | 保持开启 | 按预期失败 |

上述耗时是同一 CI runner 上的单次本机请求记录，用于证明测试实际执行，不作为网络性能指标。机器可读的脱敏结果保存于 [verification/round-2-protocols.json](verification/round-2-protocols.json)，原始日志随该 run 的 `protocol-evidence` artifact 上传。

## 验证方式

`tests/Integration/protocol_connections.py` 通过 `tests/Support/run-stack.py` 启动的临时面板执行以下检查：

- 使用管理员 API 创建成员、VLESS + REALITY 节点和 Hysteria2 节点，再从节点 API 获取实际配置及成员 UUID。
- 将用于本机测试的 SOCKS 入站和指定节点路由保存为订阅模板；直接下载并运行面板生成的 sing-box JSON，不修改下载后的配置。
- 使用官方 sing-box 分别运行客户端和服务端，通过 SOCKS 向本机 HTTP 服务发出请求，校验完整响应。
- 停止对应协议服务端后再次请求，要求失败，并确认客户端仍在运行，排除直连或失效客户端造成的误判。

所有服务仅监听 `127.0.0.1`。TLS 握手目标、临时证书、HTTP 目标都在本机，客户端保持证书校验。私钥、成员凭据、订阅配置仅写入临时工作目录，栈退出时删除；结果文件不保存这些凭据。这项验证不代表 VPS 部署、Cloudflare 链路或生产节点代理程序已经验收。

## 依赖及固定版本下载

需要项目已安装的 PHP/Composer 依赖、PHP Redis/SQLite 等项目运行扩展、`redis-server`、Python 3、`curl` 和 OpenSSL。协议脚本只使用 Python 标准库。

固定使用官方 **sing-box 1.14.4，Linux amd64**：

- 发布地址：<https://github.com/SagerNet/sing-box/releases/tag/v1.14.4>
- 发布元数据：<https://api.github.com/repos/SagerNet/sing-box/releases/tags/v1.14.4>
- 压缩包 SHA256：`699a1b510aa4396b2176d32d1181920399ebf1e267835da6f3b598d0f7c24968`

以下命令适用于 Linux amd64 CI runner；`protocol_tools` 是临时目录，不写入项目依赖或镜像：

```bash
set -eu
protocol_tools="$(mktemp -d)"
curl --fail --silent --show-error --location \
  https://github.com/SagerNet/sing-box/releases/download/v1.14.4/sing-box-1.14.4-linux-amd64.tar.gz \
  --output "$protocol_tools/sing-box-1.14.4-linux-amd64.tar.gz"
(
  cd "$protocol_tools"
  echo '699a1b510aa4396b2176d32d1181920399ebf1e267835da6f3b598d0f7c24968  sing-box-1.14.4-linux-amd64.tar.gz' | sha256sum --check -
  tar -xzf sing-box-1.14.4-linux-amd64.tar.gz
)
"$protocol_tools/sing-box-1.14.4-linux-amd64/sing-box" version

python3 tests/Support/run-stack.py \
  --php "$(command -v php)" \
  --redis "$(command -v redis-server)" \
  --artifacts "${RUNNER_TEMP:-/tmp}/simplexboard-protocol-artifacts" \
  --without-scheduler -- \
  python3 tests/Integration/protocol_connections.py \
  --sing-box "$protocol_tools/sing-box-1.14.4-linux-amd64/sing-box"
```

依赖的安装和下载须在执行测试前成功完成。CI 应保留上述 artifacts 目录，尤其是 `protocol-connections-results.json` 和各协议的 `*-check.log`、`*-server.log`、`*-client.log`。

## 本地受限环境记录

本地受限运行环境已完成官方压缩包 SHA256 校验，二进制报告 `sing-box version 1.14.4`。临时面板、Redis、Horizon 与 WebSocket 栈已启动；管理员登录、节点与成员创建、节点配置/用户下发、VLESS + REALITY 服务端 `sing-box check` 已执行通过。

实际 `sing-box run` 在建立监听之前退出：

```text
start service: initialize network: create netlink socket: operation not permitted
```

因此本地结果为 **运行环境阻塞**。没有修改内核、权限或二进制来绕过限制。随后在 GitHub Ubuntu runner 上运行同一脚本，两种协议的完整 HTTP 请求与停止服务端后的失败检查均通过，结果见本文开头。该 CI 结果补足真实协议连接验证，本地环境限制仍如实保留。

# xboard-node v1.13 的 SimpleXboard 兼容补丁

本目录的补丁配合 SimpleXboard 的核心就绪状态展示及出站管理使用。面板升级不会替换 VPS 上的节点二进制；需要更新节点端才能应用生命周期修复与出站选择。

## 固定来源

- 上游：`https://github.com/cedar2025/Xboard-Node`
- 标签：`v1.13`
- 基线提交：`0a29338e1f102a462363ce3527417029f89bab28`
- 按顺序应用：`v1.13-core-lifecycle.patch`、`v1.13-managed-outbounds.patch`、`v1.13-node-maintenance.patch`、`v1.13-server-certificates.patch`
- 当前修复构建版本：`v1.13-simplexboard.3`，不是上游发布的新版本。

第一份补丁修改节点生命周期、同步状态衔接及就绪检查；第二份增加默认出口、能力声明、出站变更后的单节点核心重建与对应测试；第三份使配套 xbctl 的健康端口解析与实际首实例一致，并明确拒绝损坏配置；第四份把证书读取、权限、PEM 解析及密钥配对错误交给核心生命周期处理，停止旧核心并将具体原因上报给面板，修复后自动重试恢复。沿用上游 `go.mod` / `go.sum` 的核心和依赖版本。不要对其他版本强行应用这些补丁，也不要用会拉取上游版本的原升级命令覆盖修复构建。

## 获取经过验收的构建

本仓库的 **Verify node lifecycle, outbounds and certificates** 工作流在同一固定源码上先复现原缺陷，再应用四份补丁、执行 Go 测试与竞态检查，最后运行实际 machine 多实例、VLESS + REALITY + Vision、六类出站、出口切换及 UDP 请求，并通过真实 Hysteria2 + Mihomo 验证证书失败、修正恢复和客户端连接。

推荐在面板「VPS → 节点维护」复制一键安装或升级命令；脚本自动识别 Linux amd64 / arm64，下载本仓库 [node-v1.13-simplexboard.3 Release](https://github.com/CoderDoubleflower/SimpleXboard/releases/tag/node-v1.13-simplexboard.3) 的固定程序并校验，详见[维护说明](../../docs/node-maintenance.md)。公开 Release 包含完整补丁后源码及校验清单，不依赖 GitHub 登录和临时 artifact。

只有两个原生架构的完整验收均成功，master 工作流才发布 Release。每次 CI 也保留 `xboard-node-v1.13-simplexboard.3-linux-amd64` / `linux-arm64` artifact，供诊断使用，包含二进制和 SHA-256 校验文件，保留 14 天。

## 本地构建

在 SimpleXboard 仓库根目录执行。需要 Git 和满足节点 `go.mod` 要求的 Go 1.26 工具链。首次构建会下载上游锁定依赖。

```bash
PANEL_SOURCE="$PWD"
NODE_SOURCE="$(mktemp -d)"
git clone --branch v1.13 --depth 1 https://github.com/cedar2025/Xboard-Node.git "$NODE_SOURCE"
cd "$NODE_SOURCE"
test "$(git rev-parse HEAD)" = 0a29338e1f102a462363ce3527417029f89bab28
git apply --check "$PANEL_SOURCE/patches/xboard-node/v1.13-core-lifecycle.patch"
git apply "$PANEL_SOURCE/patches/xboard-node/v1.13-core-lifecycle.patch"
git apply --check "$PANEL_SOURCE/patches/xboard-node/v1.13-managed-outbounds.patch"
git apply "$PANEL_SOURCE/patches/xboard-node/v1.13-managed-outbounds.patch"
git apply --check "$PANEL_SOURCE/patches/xboard-node/v1.13-node-maintenance.patch"
git apply "$PANEL_SOURCE/patches/xboard-node/v1.13-node-maintenance.patch"
git apply --check "$PANEL_SOURCE/patches/xboard-node/v1.13-server-certificates.patch"
git apply "$PANEL_SOURCE/patches/xboard-node/v1.13-server-certificates.patch"
go test -p 2 -tags 'with_quic,with_utls,with_wireguard,with_acme,with_clash_api' ./...
go test -p 2 -race -tags 'with_quic,with_utls,with_wireguard,with_acme,with_clash_api' ./internal/cert ./internal/service ./internal/controlplane ./internal/machine ./internal/panel ./internal/kernel/singbox ./cmd/xboard-node
CGO_ENABLED=0 go build -buildvcs=false -trimpath \
  -tags 'with_quic,with_utls,with_wireguard,with_acme,with_clash_api' \
  -ldflags '-s -w -X main.version=v1.13-simplexboard.3' \
  -o xboard-node ./cmd/xboard-node
sha256sum xboard-node
```

构建产物位于打印命令所用的 `$NODE_SOURCE/xboard-node`。交叉编译时显式指定目标 `GOOS` 和 `GOARCH`；`-race` 测试需要在支持的宿主环境中执行。

## 更新与验收

先更新面板及其常驻 WebSocket / 队列进程，使新状态语义生效。使用「VPS → 节点维护 → 升级 / 替换」的一键命令即可自动保留配置、备份和替换节点程序。默认位置是 `/usr/local/bin/xboard-node`，服务名是 `xboard-node.service`；脚本会核对实际 systemd `ExecStart`。升级会重启服务一次加载新程序，后续用户变化自动应用，无需再重启。

`/healthz` 继续表示进程存活。`/readyz` 表示核心就绪：配置未完成、无用户、启动失败、部分实例未就绪时返回 HTTP 503；所有已发现的节点核心就绪时返回 HTTP 200。多个机器实例分别参加汇总，空机器不会凭空变成可用代理。详细错误从节点日志及管理员面板查看。

部署后使用真实订阅完成一次 `0 → 1 → 0 → 1`：检查监听端口、就绪状态和实际代理请求；断开 WebSocket 时还应观察 REST 补偿后恢复。CI 在回环隔离环境中验证这些路径，公网防火墙、路由、DNS 和具体 VPS 环境应在部署后确认。

完整根因与状态语义见 [修复说明](../../docs/node-core-lifecycle-fix.md)。

出站功能的操作、兼容范围及升级顺序见[出站管理说明](../../docs/outbound-management.md)。更新出站配置会重建对应核心，纯成员变更仍使用既有更新路径；核心就绪不等于外部出口连通。

## Hysteria2 服务端证书验收

面板负责校验证书来源和必填字段；节点负责打开 VPS 上的文件、加载 PEM、检查证书与私钥匹配以及实际签发。文件路径指向运行 xboard-node 的主机或容器内部。SNI 与「跳过证书验证」是客户端设置，不能代替服务端证书。

第四份补丁会将 `cert file: open …`、`key file: open …`、`permission denied`、无效 PEM 和密钥不匹配等原因放入 `core_status.error`。配置更新失败时旧核心也会停止；管理进程及 WebSocket 保持工作，修正配置或文件后按已有重试机制恢复。设置 `none` 会清除旧证书材料，不能借用先前证书绕过 Hysteria 的 TLS 要求。自签证书只复用来源、域名、证书摘要和有效期均匹配的缓存，切换来源或域名会生成对应的新证书；旧版缺少来源元数据的缓存会重新生成一次，此后同域名重启继续复用。自动签发的续签通知由串行核心生命周期消费，WebSocket 长期在线或 REST 请求失败也不会漏掉证书更新。

`tests/Integration/hysteria_certificates.py` 在隔离面板中直接下发 `file`、`content`、`self` 三种来源，检查核心状态、UDP 监听和 Mihomo 代理 HTTP 请求；文件和内容模式使用本地信任的测试证书并保持客户端验证开启。它还检查直接 API 在开启「跳过证书验证」时仍拒绝缺证书配置，验证缺文件、无读取权限、损坏 PEM、私钥不匹配，以及每次修复后保持机器进程不变、新建客户端 TLS/QUIC 连接完成真实请求；保留磁盘缓存验证 content → self 来源切换和 self 域名变化会实际生成新证书。HTTP/DNS 自动签发依赖真实域名、验证入口或 DNS 凭据，本地测试不联系公共 CA，不代表真实签发成功。

```bash
python tests/Support/run-stack.py --without-scheduler -- \
  python tests/Integration/hysteria_certificates.py \
    --xboard-node /path/to/patched/xboard-node --mihomo /path/to/mihomo
```

验收脚本默认使用 `singbox`，与原生 CI 保持一致。需要验收节点已支持的 Xray 核心时，在上述命令后添加 `--kernel xray`；脚本会使用该核心对应的测试路由格式，并在证据 JSON 的 `kernel` 字段记录实际核心。两种选择都使用完整的 xboard-node 与 Mihomo 进程。

# xboard-node v1.13 的 SimpleXboard 兼容补丁

本目录的补丁配合 SimpleXboard 的核心就绪状态展示及出站管理使用。面板升级不会替换 VPS 上的节点二进制；需要更新节点端才能应用生命周期修复与出站选择。

## 固定来源

- 上游：`https://github.com/cedar2025/Xboard-Node`
- 标签：`v1.13`
- 基线提交：`0a29338e1f102a462363ce3527417029f89bab28`
- 按顺序应用：`v1.13-core-lifecycle.patch`、`v1.13-managed-outbounds.patch`、`v1.13-node-maintenance.patch`
- 当前修复构建版本：`v1.13-simplexboard.2`，不是上游发布的新版本。

第一份补丁修改节点生命周期、同步状态衔接及就绪检查；第二份增加默认出口、能力声明、出站变更后的单节点核心重建与对应测试；第三份使配套 xbctl 的健康端口解析与实际首实例一致，并明确拒绝损坏配置。沿用上游 `go.mod` / `go.sum` 的核心和依赖版本。不要对其他版本强行应用这些补丁，也不要用会拉取上游版本的原升级命令覆盖修复构建。

## 获取经过验收的构建

本仓库的 **Verify node lifecycle and outbounds** 工作流在同一固定源码上先复现原缺陷，再应用两份补丁、执行 Go 测试与竞态检查，最后运行实际 machine 多实例、VLESS + REALITY + Vision、六类出站、出口切换及 UDP 请求。

推荐在面板「VPS → 节点维护」复制一键安装或升级命令；脚本自动识别 Linux amd64 / arm64，下载本仓库 [node-v1.13-simplexboard.2 Release](https://github.com/CoderDoubleflower/SimpleXboard/releases/tag/node-v1.13-simplexboard.2) 的固定程序并校验，详见[维护说明](../../docs/node-maintenance.md)。公开 Release 包含完整补丁后源码及校验清单，不依赖 GitHub 登录和临时 artifact。

只有两个原生架构的完整验收均成功，master 工作流才发布 Release。每次 CI 也保留 `xboard-node-v1.13-simplexboard.2-linux-amd64` / `linux-arm64` artifact，供诊断使用，包含二进制和 SHA-256 校验文件，保留 14 天。

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
go test -p 2 -tags 'with_quic,with_utls,with_wireguard,with_acme,with_clash_api' ./...
go test -p 2 -race -tags 'with_quic,with_utls,with_wireguard,with_acme,with_clash_api' ./internal/service ./internal/controlplane ./internal/machine ./internal/panel ./internal/kernel/singbox ./cmd/xboard-node
CGO_ENABLED=0 go build -buildvcs=false -trimpath \
  -tags 'with_quic,with_utls,with_wireguard,with_acme,with_clash_api' \
  -ldflags '-s -w -X main.version=v1.13-simplexboard.2' \
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

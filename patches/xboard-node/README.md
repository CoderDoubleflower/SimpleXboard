# xboard-node v1.13 核心生命周期修复

本目录的补丁配合 SimpleXboard 的核心就绪状态展示使用。面板升级会修正监控判定；节点自动从空用户状态恢复，需要同时更新节点二进制。

## 固定来源

- 上游：`https://github.com/cedar2025/Xboard-Node`
- 标签：`v1.13`
- 基线提交：`0a29338e1f102a462363ce3527417029f89bab28`
- 补丁：`v1.13-core-lifecycle.patch`
- 修复构建版本：`v1.13-simplexboard.1`，不是上游发布的新版本。

补丁只修改节点生命周期、同步状态衔接、就绪检查及对应测试；沿用上游 `go.mod` / `go.sum` 的核心和依赖版本。不要对其他版本强行应用此补丁，也不要用会拉取上游 `dev` 的原安装命令覆盖修复构建。

## 获取经过验收的构建

本仓库的 **Verify node core lifecycle** 工作流在同一固定源码上先复现原缺陷，再应用补丁、执行 Go 测试与竞态检查，最后运行实际 machine 多实例、singbox、VLESS + REALITY + Vision 和 mihomo v1.19.29 代理请求。

只有验收成功才会上传 `xboard-node-v1.13-simplexboard.1-linux-amd64` artifact，包含二进制和 SHA-256 校验文件。检查工作流对应的提交与结论，解压后执行 `sha256sum --check xboard-node.sha256`。这是 Linux amd64 构建；其他架构请从相同源码构建。CI artifact 保留 14 天。

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
go test -p 2 -tags 'with_quic,with_utls,with_wireguard,with_acme,with_clash_api' ./...
go test -p 2 -race -tags 'with_quic,with_utls,with_wireguard,with_acme,with_clash_api' ./internal/service ./internal/controlplane ./internal/machine ./cmd/xboard-node
CGO_ENABLED=0 go build -buildvcs=false -trimpath \
  -tags 'with_quic,with_utls,with_wireguard,with_acme,with_clash_api' \
  -ldflags '-s -w -X main.version=v1.13-simplexboard.1 -X main.commit=0a29338+simplexboard' \
  -o xboard-node ./cmd/xboard-node
sha256sum xboard-node
```

构建产物位于打印命令所用的 `$NODE_SOURCE/xboard-node`。交叉编译时显式指定目标 `GOOS` 和 `GOARCH`；`-race` 测试需要在支持的宿主环境中执行。

## 更新与验收

先更新面板及其常驻 WebSocket / 队列进程，使新状态语义生效。保留现有节点配置，并按部署方式替换节点程序。上游脚本的默认位置是 `/usr/local/bin/xboard-node`，服务名是 `xboard-node.service`；以实际 systemd `ExecStart` 为准。替换前备份旧二进制，替换后需要重启服务一次加载新程序。后续用户变化应自动应用，无需再重启。

`/healthz` 继续表示进程存活。`/readyz` 表示核心就绪：配置未完成、无用户、启动失败、部分实例未就绪时返回 HTTP 503；所有已发现的节点核心就绪时返回 HTTP 200。多个机器实例分别参加汇总，空机器不会凭空变成可用代理。详细错误从节点日志及管理员面板查看。

部署后使用真实订阅完成一次 `0 → 1 → 0 → 1`：检查监听端口、就绪状态和实际代理请求；断开 WebSocket 时还应观察 REST 补偿后恢复。CI 在回环隔离环境中验证这些路径，公网防火墙、路由、DNS 和具体 VPS 环境应在部署后确认。

完整根因与状态语义见 [修复说明](../../docs/node-core-lifecycle-fix.md)。

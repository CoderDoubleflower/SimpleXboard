# Hysteria 服务端证书配置与验收

Hysteria / Hysteria2 必须有服务端 TLS 证书。客户端的 SNI 用于识别证书名称；「跳过证书验证」只改变客户端的验证行为。这两个设置都不会为节点生成证书。

## 在面板配置

打开「VPS 与节点 → 节点 → 添加 / 编辑节点」，选择 Hysteria2，在「服务端 TLS 证书」中明确选择来源：

| 来源 | 必需配置 | 运行条件 |
| --- | --- | --- |
| VPS 本地证书文件 | `cert_file`、`key_file` | 路径在节点所在 VPS 上，节点运行用户能够读取；证书和私钥必须配对。 |
| PEM 内容 | `cert_content`、`key_content` | 完整 PEM 证书链与未加密私钥；面板检查 PEM 解析及配对，节点再次加载检查。 |
| HTTP 自动签发 | `domain` | 域名正确解析到节点，公网 TCP 80 能到达 HTTP 验证端口；联系邮箱可选。 |
| DNS 自动签发 | `domain`、`dns_provider` 与该服务商所需 `dns_env` | 凭据有对应域名的 DNS 修改权限；不需要 HTTP 验证端口。 |
| 自签名 | 显式 `cert_mode: self`，域名可选 | 由节点生成证书；域名留空使用 localhost。客户端应信任此证书，或由管理员明确选择跳过验证。面板不会自动开启跳过验证。 |

HTTP 验证监听端口 `http_port` 留空或为 `0` 时使用节点默认值 80。自定义本地监听端口不会改变 ACME 的公网验证端口。通配符域名应使用 DNS 验证。

DNS 来源与节点的 21 个服务商实现保持一致，包括 Cloudflare、阿里云、腾讯云、Route 53、Azure、Google Cloud DNS 等。兼容服务商及凭据键的既有别名；Route 53 可以使用 VPS 上的 AWS 环境身份，Azure / Google Cloud 可以在提供订阅、资源组 / 项目标识后使用环境身份。API 凭据不写入成员订阅，证书配置在管理员审计日志中整体脱敏。

`file` 模式示例：

```json
{
  "cert_mode": "file",
  "cert_file": "/etc/xboard-node/certs/fullchain.pem",
  "key_file": "/etc/xboard-node/certs/privkey.pem"
}
```

文件路径属于 VPS，面板不在自身文件系统上检查它们。路径存在性、访问权限、PEM 解析和私钥配对由节点负责，失败原因进入核心状态。

## 保存、启用与旧配置

- 完整保存 Hysteria 节点必须有完整证书来源，包括保存为停用状态的节点；这次没有引入草稿模式。
- API 与前端使用相同的来源规则。`null`、空来源、`none`、未知模式、文件模式缺任一条路径均被拒绝。
- 单个启用、批量启用和启用节点的机器重绑定均检查当前配置；批量操作在任一节点不合格时整体回滚。
- 旧的无效节点仍然允许禁用或删除，不要求先修复才能停用。
- 旧字段 `mode` 兼容为 `cert_mode`；两个字段冲突时拒绝。没有显式模式、但有成对 PEM 或文件路径的旧配置可推断为对应模式，并显式下发 `cert_mode`。
- 编辑 API 省略 `cert_config` 时保留数据库里的原值；显式传 `null` 表示清空，会被 Hysteria 校验拒绝。
- 旧数据库中的无效 Hysteria 配置不下发，不进入机器实例列表或成员订阅，也不获得全量 / 增量用户授权。面板核心状态显示具体配置问题，机器或 WebSocket 在线不会把它变成可用节点。
- 修复证书并保存后，机器列表重新同步；独立节点同时收到配置和用户快照，无需依赖 WebSocket 断线重连才能恢复。
- 未独立绑定机器的旧订阅别名继续按父节点判断证书与核心状态；真正部署或独立绑定机器的子节点仍须通过自身证书校验，不能借父节点绕过。

本次强制校验范围为 Hysteria 协议（含版本 1、2）。其他协议是否需要普通 TLS 证书仍需按协议与安全模式另行核验；VLESS REALITY 的保存规则保持兼容。

## 节点端更新

文件 / PEM 加载失败时，旧节点版本会记录错误后继续启动或重载，可能丢失具体原因或继续使用旧证书。第四份节点补丁将失败交给核心生命周期处理：停止旧核心、报告 `core_status.error`、保持管理连接并按原有退避机制重试。

证书来源切换、自签名域名变化与续签也参与核心重载。自签名缓存校验来源、域名、证书摘要、SAN 和有效期；旧版没有来源元数据的缓存会在首次使用时重生成一次，此后同域名稳定复用。客户端若固定信任旧自签名证书，需更新信任。ACME 续签通知由核心生命周期统一消费，WebSocket 持续在线也会应用新证书。

本修复构建为 `v1.13-simplexboard.3`。发布仍由 `master` 工作流在 amd64、arm64 全部验收成功后自动完成。在对应 Release 发布后，使用「VPS → 节点维护 → 升级 / 替换」复制一条命令更新，详见[节点维护说明](node-maintenance.md)。分支或 PR 中的版本声明不表示 Release 已经发布。

## 可复现验收

```bash
php vendor/bin/phpunit
npm --prefix panel test
npm --prefix panel run build
python tests/Support/run-stack.py --without-scheduler -- python panel/tests/e2e.py
python tests/Support/run-stack.py --without-scheduler -- \
  python tests/Integration/hysteria_certificates.py \
  --xboard-node /path/to/patched/xboard-node --mihomo /path/to/mihomo
```

新增 PHPUnit 用例覆盖保存、启用、批量原子性、HTTP / WebSocket 下发、用户撤销及恢复、订阅和审计脱敏。前端单元测试与既有浏览器 E2E 覆盖五种来源、必填、错误定位、编辑回填和 390px 布局。

真实流量验收使用临时 SQLite、Redis / Horizon、WebSocket、实际 machine 模式 xboard-node 和 Mihomo 1.19.29。文件和 PEM 模式保持客户端证书验证开启；自签名模式明确选择客户端跳过验证。检查 UDP 监听、核心就绪状态和经 Hysteria2 完成的 HTTP 请求，并验证缺文件、不可读、PEM 损坏、私钥不匹配时旧监听关闭、管理连接保留、修复后恢复。

该回环验收不联系公共 ACME CA 或 DNS API，也不能替代真实 VPS 的公网防火墙、域名解析和自动签发验收。HTTP / DNS 的配置校验通过，仅表示来源结构完整，实际签发仍以节点结果为准。

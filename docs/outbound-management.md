# 出站管理与链式代理

## 使用方式

在「VPS 与节点 → 出站」添加现成的外部代理。支持手动配置，也可以粘贴一条分享链接，识别并检查参数后保存。识别操作只解析文本，不访问链接、不订阅远程地址，也不会直接保存记录。

创建或编辑入口节点时，在「流量出站」选择该出站。默认直连；选择 B 后，默认业务路径为「客户端 → 入口 A → 外部 B → 目标」。成员仍连接入口 A，出口的认证信息不进入成员订阅。B 作为普通代理服务提供连接，不要求安装 xboard-node。

同一台 VPS 的不同入口可以选择不同出口；同一个出口可以被多个入口引用。出口列表显示引用节点。修改共享出口的地址、认证或协议参数后，关联节点（包括隐藏节点）会收到更新；只有配置改变的逻辑节点核心需要重建。

切换出口会中断该节点部分已有连接，由客户端重新连接。出口不可用时，该请求失败，不自动回退到入口直连。被引用的出口必须先解除或变更引用后才能删除。

## 支持范围

| 出站协议 | 导入格式 | 首版连接范围 |
| --- | --- | --- |
| VLESS | `vless://` | TCP，REALITY 或 TLS，支持 Vision |
| Hysteria2 | `hysteria2://`、`hy2://` | TLS、认证、Salamander、带宽参数 |
| SOCKS5 | `socks5://`、`socks://` | 匿名或用户名/密码认证 |
| Shadowsocks | `ss://` | SIP002 及常见旧格式，AEAD 与 SS2022 |
| Trojan | `trojan://` | TCP + TLS、密码认证 |
| VMess | `vmess://` | Base64 JSON 格式，TCP，可选 TLS |

VLESS、Trojan、VMess 的 TCP 指代理连接的传输方式；不代表强制把目标流量限制为 TCP。UDP 是否可用仍取决于出口服务和网络路径。SOCKS5 服务必须实际支持 UDP ASSOCIATE 才能转发 UDP。

Shadowsocks 支持 `aes-128-gcm`、`aes-256-gcm`、`chacha20-ietf-poly1305`、`2022-blake3-aes-128-gcm`、`2022-blake3-aes-256-gcm`、`2022-blake3-chacha20-poly1305`。SS2022 密钥长度按算法校验。当前表单不支持 WebSocket、gRPC、XHTTP、Shadowsocks 插件、Hysteria2 端口跳跃和任意多跳编排；导入不支持的参数会返回明确错误，不会静默丢弃。

当前受管理出站需要入口使用 **sing-box**。Xray 对这些连接参数的转换尚未完整覆盖，因此节点端会明确拒绝非直连的受管理出口，并报告错误。

## 旧高级配置

已有 `custom_outbounds`、`custom_routes`、`route_ids` 保留原有语义。表单会提示已有高级路由。选择受管理出站时，需要先移除冲突的高级配置；不能同时维护两套会相互覆盖的面板路由。

节点本地配置文件中的显式路由仍优先于默认出口；管理员需要自行检查本地分流规则。面板指定的 `default_outbound` 优先于本地配置文件中的 `route.final`，并使用独立的出口 tag，不把 `direct` 偷换为代理。默认私网拦截继续保留。

## 升级与兼容

1. 备份数据库和现有 `APP_KEY`。出站认证参数使用 Laravel 加密存储，恢复备份时必须保留相同的密钥。
2. 按[节点构建说明](../patches/xboard-node/README.md)取得 `v1.13-simplexboard.2`。它基于固定的上游 v1.13，依次应用生命周期和出站补丁。保留 VPS 配置，替换节点二进制并重启节点服务；不要运行会覆盖为上游 dev 的原安装命令。
3. 更新面板代码或镜像，执行 `php artisan migrate --force`。源码部署还需要 `composer install --prefer-dist --no-interaction` 和 `npm --prefix panel run build`；按原部署方式重启常驻 Web、Horizon、WebSocket 进程。
4. 等待新版节点完成认证请求。在出站页添加外部节点，然后编辑或创建入口并选择出口。

新版节点在认证 HTTP 请求中声明 `managed-outbound-v1`。面板只从验证通过的节点/机器请求中记录能力，不把在线状态或管理员填写的版本号当作能力证明。

- 绑定已接入新版节点端的 VPS：创建入口时即可选择出站。
- 新的独立节点：先以直连方式创建，接入新版节点端，再编辑选择出站。
- 旧版节点：不能新绑定出站；请求受管理节点配置会收到升级错误，用户快照为空，增量授权也被阻止。
- 已有出站绑定的节点离线：可以保存其他字段，保留原绑定；这不表示它当前可用。

`core_ready` 表示核心配置与用户已应用，不是外部出口连通性探测。错误密码或出口服务离线可能在实际连接时才暴露。界面不把“核心运行”描述为“出口可达”。

## 实现契约

共享出站保存在 `v2_server_outbound`，`settings` 为加密文本。入口通过可空的 `v2_server.outbound_id` 引用；外键禁止删除仍被引用的出站。

下发配置沿用节点原有的结构化出站格式：

```json
{
  "default_outbound": "sx-outbound-7",
  "custom_outbounds": [
    {
      "tag": "sx-outbound-7",
      "protocol": "socks",
      "settings": {"server": "exit.example.com", "server_port": 1080, "version": "5"}
    }
  ]
}
```

HTTP 拉取和 WebSocket 推送使用同一编译函数；最终 JSON 参与 ETag。共享变更在数据库事务提交后通知引用节点。出站列表不返回认证配置；管理员编辑和导入预览接口返回 `Cache-Control: no-store`。请求日志脱敏出站配置、UUID 和原始分享链接。

节点端检测完整出站集合及最终出口的变化，通过既有生命周期停止并重新启动对应核心。无效的受管理配置进入明确错误状态并停止旧核心，避免保留旧直连。纯用户变更继续增量更新。应用路由失败会向生命周期返回错误。

## 验收入口

后端：

```bash
php vendor/bin/phpunit
```

前端和真实浏览器：

```bash
npm --prefix panel test
npm --prefix panel run build
python tests/Support/run-stack.py --without-scheduler -- python panel/tests/outbound_management.py
```

节点补丁的配置解析、生命周期与并发检查见节点构建说明。GitHub Actions 的 **Verify node lifecycle and outbounds** 还会启动真实 machine 模式、mihomo 客户端和六类外部出口，覆盖实际 TCP、SOCKS5/Hysteria2 UDP、共享出口更新、切换、故障拒绝直连和重启恢复。

端到端测试只在临时数据库、随机端口及隔离的本机地址上运行。工作流为环回接口临时配置 `198.51.100.10/32` 作为测试目标，通过不同出口的源地址证明链路，没有放宽产品的默认私网拦截。测试记录不包含认证信息；节点二进制只在全部节点验收通过后上传。

## 回退

先把所有入口明确切回直连并确认新连接使用直连，再回退面板或节点二进制。回退数据库前备份出站数据；回滚迁移会删除出站表和引用字段。不要在仍使用出站时直接降级为不认识默认出口字段的节点端。

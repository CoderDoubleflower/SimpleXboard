# mihomo / Stash 订阅与模板编辑

SimpleXboard 现在只输出 mihomo 和 Stash 订阅。在「设置 → 订阅模板」分别编辑两端配置，保存后成员下次更新订阅即可生效。模板生成在现有 Laravel 服务中完成，不需要部署 sublink-worker、Cloudflare KV 或新增 Node 服务。

## 订阅入口

| 请求 | 输出 |
| --- | --- |
| `?flag=mihomo` | mihomo YAML |
| `?flag=stash` | Stash YAML |
| 无 flag，Stash User-Agent | Stash YAML |
| 无 flag，mihomo 或通用 User-Agent | mihomo YAML |
| 旧 `meta`、`clash.meta`、`clashmeta`、`clash` 等 mihomo 系别名 | 同一份 mihomo YAML |
| 显式请求 sing-box、Surge、通用节点链接等已停用格式 | HTTP 422，提示使用 mihomo / Stash |

成员 token、UUID 和订阅路径保持原有规则。成员页面只提供 mihomo、Stash 两项，快捷复制默认选择 mihomo。显式 flag 决定格式，实际客户端 User-Agent 仍用于版本适配。

这次限制的是**客户端订阅格式**。VPS 上 xboard-node 的 sing-box 核心和已有的服务端出站功能继续使用。

## 在面板中使用

1. 打开「设置 → 订阅模板」，选择 mihomo 或 Stash。
2. 选择「保留基础 YAML 的规则」、极简、均衡、完整或自选分类。已有模板默认保留原规则，不会自动切换分流方案。
3. 按需添加规则集、自定义规则和客户端代理链。
4. 点击「生成预览」。示例使用演示凭据；选择具体成员时会使用其真实凭据和可见节点范围。
5. 检查最终 YAML 后保存当前客户端模板；另一客户端的设置独立保存。

切换客户端会保留本页的两份草稿。离开设置页前应保存或导出。导入、预览都不会直接修改正在下发的模板。

### 规则集

移植 sublink-worker 的 18 个分类与三种预设：

- **极简**：局域网、中国大陆、境外网站。
- **均衡**：在上述分类上加入 GitHub、Google、YouTube、AI 服务和 Telegram。
- **完整**：包括广告、流媒体、社交媒体、游戏、教育、金融和云服务等全部分类。

分类生成独立选择组。局域网和中国大陆默认直连，广告默认拦截，其余默认使用「节点选择」。更专门的分类排在中国大陆、境外网站等较宽泛分类之前。

内置规则源默认是 MetaCubeX/meta-rules-dat，可在高级选项更换为自己的镜像。默认 YAML，亦可选择 MRS；额外规则集支持 YAML、text、MRS，MRS 只用于 domain / ipcidr。域名集与同名 IP 集使用不同名称和缓存路径，例如 `google` 与 `google-ip`。

额外规则集可编辑名称、URL、behavior、format、更新间隔及目标策略，并调整优先顺序。面板不会请求这些 URL；订阅中的客户端负责下载与更新。

### 自定义规则与顺序

自定义规则每行一条，按从上到下的顺序匹配，例如：

```text
DOMAIN-SUFFIX,work.example,节点选择
DOMAIN,internal.example,DIRECT
IP-CIDR,192.168.50.0/24,DIRECT,no-resolve
IP-CIDR6,2001:db8::/32,REJECT,no-resolve
```

完整优先级是：订阅入口直连规则 → 自定义规则 → 额外规则集 → 所选分类规则或原 YAML 规则 → 最终兜底。额外 provider 已被同策略的自定义 `RULE-SET` 引用时不会重复追加。

自定义规则区不能填写 `MATCH` / `FINAL`。使用生成式预设时由「默认策略」决定兜底；保留原规则时继续使用原模板末尾的兜底。逻辑条件、规则集和子规则引用会在保存、预览时校验。

### 客户端链式代理

选择「先经过的入口」和一个或多个「最终落地节点」。路径为：

**客户端 → 入口 → 落地节点 → 目标网站**

配置写在落地节点的 `dialer-proxy` 字段上，例如：

```yaml
proxies:
  - name: 香港入口
    type: socks5
    server: entry.example.com
    port: 1080
  - name: 美国落地
    type: socks5
    server: exit.example.com
    port: 1080
    dialer-proxy: 香港入口
```

可继续将入口连接到另一个入口，形成多跳。入口也可以是明确列出可选节点的策略组。包含落地自身的「全部节点」组会形成环路，不能作为该落地的入口。

面板节点按 `node:<id>` 保存引用，节点改名和协议名称前缀不会破坏链路。导入时能唯一匹配到面板节点的名字也会转换为稳定 ID。高级引用允许 `proxy:<名称>` 和 `group:<名称>`。

链路始终遵守成员授权：成员看不到落地节点时，该关系不参与其订阅；成员看得到落地但看不到入口时，订阅返回 HTTP 422，要求管理员修正。不会把未授权入口加入订阅，也不会悄悄移除 `dialer-proxy` 使落地直连。

自动筛选入口组如果没有可用节点，也会被阻止。远程代理集的内容可能在生成订阅后变化，因此使用它的入口组会加入 `REJECT` 兜底，避免原生空组退回直连；仅含远程代理集的 `select` 入口组首次使用时，需要在客户端选择实际入口节点，预览会提示。

Stash 的主配置 `dialer-proxy` 需要 iOS/tvOS 3.2.5+ 或 macOS 4.1+。UDP 能力仍取决于整条链路中的代理协议。

「VPS 与节点 → 出站」是服务器内部的转发路径，其认证信息不会自动进入本模板。若在基础 YAML 的 `proxies` 中添加外部节点，这些静态节点及凭据会提供给使用该模板的所有有效成员；只填写准备共享的节点。

## 导入与导出

支持导入本面板导出的 JSON、sublink-worker 的保存设置 JSON、完整转换 URL，以及直接粘贴的 mihomo / Stash YAML。转换 URL 只解析参数，不请求远程地址；仅有短链接 ID 时会提示不能从该 ID 还原配置。

源项目的 `selectedRules`、`customRules`、`dialerProxyRules` / `dialer_proxy_rules` 会转换到模板结构。自定义规则可包含网站集、IP 集、域名后缀、关键词、源地址和目标 CIDR。无法无损转换的字段会明确提示或拒绝，不会把一个不生效的规则当作成功导入。

源输入订阅地址和输入节点列表不作为模板节点导入；成员节点仍由 SimpleXboard 管理。导入源设置会以当前草稿的基础 YAML 为底；导入完整原生 YAML 则替换基础并清空管理式规则和链路，防止旧规则混入。

导入结果先进入草稿。导出的 JSON 包含完整基础 YAML，可能含外部节点凭据，应按敏感配置保管。

## 基础 YAML 与升级兼容

基础 YAML 保留 DNS、代理组、外部节点、远程代理集等设置。

- `proxy-groups[].proxies` 中的 `"$nodes"` 展开为当前成员可见的面板节点。
- 空节点列表同样展开；`"/正则表达式/"` 只展开匹配的面板节点，旧模板的 `#…#`、`~…~` 等有效正则分隔符继续兼容。
- 明确列出的节点和组名保持明确引用，避免把所有节点自动加入链路入口。
- 没有可用节点的普通组使用 REJECT，避免留下未展开占位符或意外直连。
- 重名面板节点以稳定 ID 后缀区分；基础 YAML 内部的重名节点或组会被拒绝。

旧生成器曾把全部节点追加到每个没有正则筛选的组。首次读取旧模板时，会将该行为转换为明确的 `$nodes` 占位符，保留原主组直接选择节点的能力。原数据库 YAML 在管理员保存前不覆盖。已保存的旧客户端模板行保留，便于回退；它们不再作为可选订阅输出。

旧 YAML 即使无效也仍能在编辑器打开并修正。保存会校验 YAML 结构、规则顺序、引用、provider 命名和缓存路径，以及节点／策略组环路；失败不会覆盖当前模板。

## API 与存储

所有接口位于 `/api/v2/{管理路径}/subscription-template/`，要求管理员登录。

| 方法与路径 | 请求 | 行为 |
| --- | --- | --- |
| `GET fetch` | `client=mihomo` 或 `stash` | 模板、分类、预设、节点和预览成员列表 |
| `POST save` | `{client, profile}` | 完整校验后原子保存 |
| `POST preview` | `{client, profile, user_id?}` | 通过真实订阅生成流程预览，不保存 |
| `POST import` | `{client, content, profile?}` | 以可选草稿为底解析导入，不保存 |

现有 `v2_subscribe_templates` 表保存基础 YAML，附加 `sublink_mihomo` / `sublink_stash` 行保存结构化选项，无新增数据库服务或 schema 迁移。现有数据库备份包含这些记录。新接口响应禁止缓存，审计记录会隐藏完整模板、导入内容及凭据。

## 验证与来源

后端测试覆盖两端输出、规则优先级、导入／预览不保存、凭据隔离、节点授权、名称变化、循环引用、空节点、旧模板迁移、原子保存与审计脱敏。前端 Node 测试覆盖序列化、稳定引用、导入导出格式及异步草稿保护。

`tests/Integration/subscription_chains.py` 在隔离的真实面板中保存模板，下载后不修改 YAML，运行 mihomo v1.19.29。两个独立 SOCKS5 服务器分别验证入口和落地认证，并记录转发路径；验证自定义规则、HTTP 规则集、未匹配拦截，以及关闭任一跳后无法抵达目标。该验证不使用浏览器。

Stash 在当前 Linux 验证环境中以原生配置结构和官方文档核验；未声称运行 Apple 平台 Stash 应用。

移植基于用户 fork [954310195/sublink-worker，81146e6](https://github.com/954310195/sublink-worker/tree/81146e682849377cb631b663a119ade3898d0659)。规则分类、规则生成与导入语义源自该版本；保留其 MIT 声明于 [LICENSES/sublink-worker.txt](../LICENSES/sublink-worker.txt)。

协议依据：

- [mihomo dialer-proxy](https://wiki.metacubex.one/config/proxies/dialer-proxy/)
- [mihomo 路由规则](https://wiki.metacubex.one/config/rules/)
- [Stash 代理链](https://stash.wiki/proxy-protocols/dialer-proxy)
- [Stash 规则集合](https://stash.wiki/rules/rule-set)
- [Stash 代理协议](https://stash.wiki/proxy-protocols/proxy-types)

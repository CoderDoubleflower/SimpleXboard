# 空用户首次启动后的核心生命周期与就绪状态

## 问题范围与根因

调查基线为 SimpleXboard `d0475411c97202d20109740944e67a8ff45d6f34`、xboard-node `v1.13` / `0a29338e1f102a462363ce3527417029f89bab28`。用户报告的机器心跳正常、用户同步日志反复出现、代理端口未监听，与以下源码路径吻合。

节点首次用户数为零时，`Service.initialSetup()` 正常跳过核心启动。后续 `applyUserUpdate()` 和增量添加路径却先调用 `ensureRunning()`，再准备新用户状态；`ensureRunning()` 检查旧的 `lastUsers`，发现仍为零就返回。新列表没有进入状态，启动条件也永远不能成立。`users updated` 日志在真正应用之前打印，不能用作应用成功的证据。

另外两处会破坏 `1 → 0` 撤销语义：WebSocket 解码器直接忽略长度为零的全量用户数组；machine mailbox 增量删除最后一个用户时，复制空切片得到 `nil`，服务又把它当作缺失更新跳过。修复明确区分合法的 `users: []` 与缺失、`null` 或畸形载荷。

面板的全量、增量和 REST 用户列表经过一致的资格与直接授权过滤；新增、授权、撤销、删除任务在事务提交后读取最新用户状态。本次没有发现面板漏发用户的证据。面板的问题是监控：`is_online` 只检查最近通信时间，虽然节点已有 `kernel_status`，它没有参与可用性判定。

## 节点修复原则

节点先记录收到的期望配置与用户，再由同一串行生命周期入口协调核心。成功应用的状态独立保存，失败不能抹掉待重试的期望状态。

- 有配置且用户从零变为非零：启动核心。
- 用户被明确清空：停止核心并撤销就绪状态；空列表不会变成“没有更新”。
- 已运行时用户变化：应用完整用户快照，保留正常热更新路径。
- 全量、增量、REST 补偿及配置事件最终进入同一协调逻辑。
- REST 补偿同步更新 machine mailbox 基线；本地 revision 防止较慢的 REST 快照覆盖更新的 WS 状态。断线、重连或拒绝过时快照后清除条件请求的 ETag，再取权威全量，避免旧 `304` 吞掉补偿。
- 重复同步依据实际已应用状态去重；失败后的相同快照仍可以恢复。
- 启动或应用失败：记录错误、清理失败实例、按有上限的退避重试。所有启动、停止、重载由服务循环串行执行。
- 控制通信及进程存活继续保持独立，失败实例不能使其他实例被误报为就绪。

节点修复以固定版本补丁交付，详情见 [构建与升级说明](../patches/xboard-node/README.md)。面板代码更新不等同于已更新任何 VPS 上的节点二进制。

## 面板状态契约

| 字段 | 含义 | 判断依据 |
| --- | --- | --- |
| `machine_online` | 机器最近与面板通信，未绑定机器时为 `null` | 机器启用状态及 `last_seen_at` |
| `control_online` | 面板观察到的 WebSocket 在线 | 认证连接和 pong，120 秒失效，断开立即清理 |
| `control_recent` | 最近有节点控制通信 | REST 或 WS 的最近通信时间，不表示特定传输在线 |
| `core_status` | 核心报告、期望/应用用户数、错误及接收时间 | 独立缓存的节点核心报告 |
| `core_ready` | 核心已报告就绪 | 新结构报告要求 `running`、`ready === true`、期望用户非零且已应用数量一致 |

管理台的“就绪节点”以及兼容字段 `is_online`、`onlineNodes` 使用核心就绪信息。机器心跳、用户拉取、流量上报、WebSocket pong 都不能单独证明核心可用。

核心报告始终写入已认证的实际节点 ID，不能通过 `parent_id` 写入其他节点的状态。具有独立 `machine_id` 的节点读取自身核心、控制通道及机器状态；没有独立机器绑定的别名保留读取父节点状态的既有行为。

支持的新报告为 `core_status: {state, ready, users, applied_users, error}`。WS 的 `node.status` 直接携带它；REST 的 `metrics` 或 `status` 可携带它。只接受真实 JSON 布尔值作为 `ready`。

状态包括 `waiting_config`、`waiting_users`、`starting`、`running`、`error`、`stopped`。面板额外提供 `unknown` 和 `stale`：未收到明确报告时显示未知；收到报告超过 `max(300, server_push_interval × 3)` 秒时失效。时间采用面板接收时间，不接受节点自行给出的未来时间延长有效期。不包含核心字段的心跳不会刷新核心报告。

旧节点显式发送的布尔 `kernel_status` 作为运行状态兼容证据，并用 `source: kernel_status` 标识；完全不报告该字段的旧节点显示未知。核心就绪是节点对本地核心应用状态的报告，不能替代用户网络到 VPS 的实际连通性验证。

监控状态不参与成员授权和订阅节点过滤。核心故障不会导致合法订阅配置被自动删除。

## 回归入口

### 面板与状态展示

```bash
php vendor/bin/phpunit
npm --prefix panel test
npm --prefix panel run build
python tests/Support/run-stack.py --without-scheduler -- python panel/tests/node_status.py
```

PHP 测试覆盖全量/增量/REST 用户一致性、空用户序列、旧 ETag、直接授权撤销与恢复、未知/错误/过期状态、旧节点兼容、多机器隔离、结构化报告优先和订阅保持。浏览器专项测试通过实际面板认证接口提交核心报告，检查机器在线与核心未就绪同时展示、错误文字安全、桌面/375px 布局及概览计数；这项 UI 测试本身不代表启动了真实代理核心。

### 节点进程与实际代理请求

工作流 `.github/workflows/node-core.yml` 固定上游提交与 mihomo v1.19.29 的下载校验值。它先构建原版节点，运行 `--expect-stalled` 复现；再应用补丁，执行 Go 测试和竞态检测，运行修复版。

```bash
python tests/Support/run-stack.py --without-scheduler -- \
  python tests/Integration/node_core_lifecycle.py \
    --xboard-node /path/to/patched/xboard-node --mihomo /path/to/mihomo
```

两个 machine 实例共用一个实际节点进程，使用嵌入的 singbox 核心。代理客户端直接运行面板生成的 mihomo 订阅；HTTP 源站、TLS 握手目标和所有代理监听只在回环测试环境使用。透明转发层只注入重复、乱序和 WebSocket 断线，不模拟代理核心。

上游默认阻断私网出站，因此临时测试配置通过现有 `kernel.custom_route`，为本次 HTTP 源站的 `127.0.0.1/32`、随机端口与 TCP 协议添加一条精确的 `direct` 规则。生产默认路由未修改。测试比较随机响应正文，并在停止节点后确认相同客户端请求失败。

验收场景：首次零用户、WS 全量启动、WS 增量启动、`0 → 1 → 0 → 1`、重复快照、用户先于配置、真实端口占用导致启动失败、移除占用后无新同步自动恢复、断开已建立 WS 后 REST 补偿、WS 重连、多实例相互隔离，以及停止节点后同一客户端请求必须失败的负向对照。

每轮 CI 的 JSON 报告、节点日志和浏览器截图是实际执行结果；测试脚本的存在本身不代表验收通过。构建 artifact 只在修复版全链路验收成功后上传。

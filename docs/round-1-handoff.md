# SimpleXboard 第一轮交接

## 交付范围与基线

- 日期：2026-11-06。
- 仓库：`CoderDoubleflower/SimpleXboard`。
- 实施分支：`refactor/round-1-private-members`。
- 开始时 HEAD：`d826a3b32f73bde24acce2a3ab033c6538ce121d`，与任务书分析基线一致，工作区干净。
- 本轮的最终代码以该分支/对应 PR 的 HEAD 为准；提交号在 PR 描述与交付回复中记录。第二轮应从该提交继续，不能重新从上面的旧基线裁剪。
- 本次完成代码、迁移文件、自动化测试和交接；没有连接生产数据库、执行服务器部署、配置 Cloudflare 或修改真实节点。

第一轮把成员开通从购买链路中拆出，退役商业交易和运营功能，保留已有订阅与节点通信架构。管理/用户端仍是上游编译产物，完整前端精简留在第二轮。

## 已实现

| 领域 | 当前行为 |
| --- | --- |
| 成员创建 | 管理员直接创建成员，自动生成独立 token、UUID 和有效额度，不生成订单 |
| 交易 | 支付方式、收银、回调、订单、优惠券、礼品卡及相关服务、模型、校验和资源删除 |
| 推广与客服 | 邀请码、邀请关系维护、返佣、提现、余额操作和工单入口删除 |
| 注册 | V1/V2 公开注册、试用、邀请强制和注册专用限制删除；旧配置不能重新开启 |
| 套餐 | 移除公开销售、价格周期、续费、换购、容量销售和付费重置；保留管理员内部配置 |
| 插件 | 删除 AlipayF2f、Btcpay、CoinPayments、Coinbase、Epay、Mgate；遗留支付清单在 PHP 加载前被拦截，不能启用或读取/改写配置 |
| Telegram | 保留绑定、解绑、流量和订阅查询，删除支付和工单联动 |
| 邮件 | 删除群发和到期/流量提醒；保留验证、密码恢复、邮件登录和 SMTP 测试 |
| 统计 | 保留成员、节点、在线、日流量、流量历史和排名；商业汇总固定为空或零 |
| 运维 | 保留节点/机器鉴权、配置和用户下发、流量、在线维护、日志、备份和核心 CLI |

相关调度已经删除：`check:order`、`check:commission`、`check:ticket`、`send:remindMail`。Horizon 不再消费 `order_handle`、`send_email_mass`，仍消费 `default`、`traffic_fetch`、`stat`、`user_alive_sync`、`send_email`、`send_telegram`、**`node_sync`**。

旧商业邮件任务即使还在 `send_email` 中，`SendEmailJob` 也只执行 `verify` 和 `mailLogin`。已删除的商业命令不会被 scheduler 或默认插件安装流程调用。

## 成员配置的过渡规则

新增的普通迁移 `2026_11_06_000001_prepare_private_members.php` 增加 `v2_plan.is_internal`，建立一条隐藏内部配置和一个专用分组。

| 项目 | 默认值/规则 |
| --- | --- |
| 配置名称 | 内部默认成员配置 |
| 分组名称 | 内部默认成员组 |
| 流量 | 1024 GiB，即 1 TiB；存到 User 时为 `1099511627776` bytes |
| 重置 | 面板时区每月 1 日 |
| 到期 | `expired_at = null`，长期有效 |
| 限速/设备数 | 默认不设置额外限制，可由管理员配置 |
| 销售 | 默认配置不可展示售卖、购买或续费，没有价格 API |

默认配置的 Plan/group ID 由数据库分配，**不可在客户端或第二轮代码中硬编码为 1**。

现有成员不会被重新分组或修改凭据、额度、到期时间。旧的长期套餐继续沿用原先“不自动周期重置”的行为；本轮只为 `is_internal=true` 的默认配置开放长期成员周期重置。通过 `plan/save` 新建的其他配置也沿用旧语义：成员到期时间为 `null` 时不会自动周期重置；如需长期有效且按月重置，应使用默认内部配置。普通用户配额的 `0/null` 仍然表示没有可用额度，不代表无限流量。

默认分组不会自动加入旧节点，以免扩大访问范围。全新配置节点时，管理员应选择“内部默认成员组”；已有环境创建成员时，可显式传入已有 `plan_id` 使用原分组。节点 `group_ids` 的现有内容不由迁移改写。

管理员保存内部配置时只允许核心字段。明确选择 `force_update` 才同步到已有成员；省略限速或设备限制时保留配置原值。仅保存成员备注等信息、并携带未改变的 `plan_id` 时，不会悄悄重写该成员已有分组。

### 管理员创建接口

新增 `POST /api/v2/{secure_path}/user/create`，使用现有管理员会话认证：

```json
{
  "email": "friend@example.com",
  "password": "replace-with-a-strong-password"
}
```

必填邮箱和至少 8 位密码。邮箱沿用现有规范化处理。可选 `plan_id`、`expired_at`、`transfer_enable`、`speed_limit`、`device_limit`。其中成员 `transfer_enable` 单位是 bytes，必须大于零，Plan 中的同名字段仍以 GiB 计。

响应包含成员 ID、UUID、token 和 `subscribe_url`，不返回密码。重复邮箱、不存在的配置或零额度会验证失败。原 `/user/generate` 单个和批量入口继续可用，未选择配置时使用内部默认配置；该旧入口的“未填写密码时使用规范化邮箱”行为暂时保留。自动化调用应使用显式要求密码的新接口。

### 启停与同步

批量禁用改为触发成员模型事件，同时撤销其全部登录会话。模型更新在事务内捕获原分组，`NodeUserSyncJob` 等提交成功后才入队；分组变化先向旧组节点撤销，再向新组节点同步。凭据重置生成新 token 与 UUID，旧订阅链接失效。

旧前端提交余额、佣金等已退役的筛选字段时返回 422，避免把“按筛选禁用”错误扩大成全部成员。旧节点的轮询与现有 WebSocket 增量同步机制继续保留。

## 数据库升级与可选清理

### 普通升级

保留全部历史 migration。普通 `php artisan migrate --force` 只执行新的过渡配置迁移；不删除商业表和历史数据，不动节点配置或现有成员。

新增迁移的 `down()` 有意保留新增列、内部配置和分组。旧代码会忽略该标记；删除它会使后续重新升级生成重复默认组，并改变本轮新增长期成员的重置行为。因此代码回退不自动清理这些新增数据，也不自动重新启用支付插件。

实际升级前需停止旧应用/队列进程并备份数据库，切换到本轮代码和锁定依赖后再执行普通迁移、清理应用缓存并重启 Octane/Horizon/节点 WebSocket 服务。遗留 `order_handle`、`send_email_mass` 队列要单独归档或清除；不要清空 `node_sync`、流量或在线状态队列。

### 可选永久清理

另提供 `database/cleanup/2026_11_06_000001_remove_retired_commerce.php`，**不在普通迁移扫描目录中**。只有确认不需要商业历史数据、已有备份并停止旧进程后才显式执行：

```bash
php artisan migrate --path=database/cleanup --force
```

它删除以下退役数据：

- 表：`v2_order`、`v2_payment`、`v2_coupon`、`v2_commission_log`、`v2_invite_code`、`v2_ticket`、`v2_ticket_message`、`v2_gift_card_template`、`v2_gift_card_code`、`v2_gift_card_usage`。
- User 字段：邀请人、余额、佣金余额/比例/类型/自动审核、折扣、邮件提醒开关。
- Plan 字段：价格、销售展示/售卖/续费开关、销售容量、商品文案和标签。

清理是不可逆的数据删除；`down()` 不伪造恢复，必须使用清理前的数据库备份回退。本次只在隔离测试数据库中验证了清理，**没有在用户环境执行**。

仍保留 `v2_stat` 旧商业列，新日汇总写零；旧商业金额不通过统计 API 暴露。旧设置键、已停用支付插件记录、历史商业邮件模板/日志以及原始 migration 仍可作为归档存在，第二轮可统一处理。

## 兼容接口与前端残留

以下字段只为当前编译前端提供只读形状，不执行业务查询或写入：

| 位置 | 第一轮兼容值 |
| --- | --- |
| `user/info` | `balance=0`，`remind_expire=0`，`remind_traffic=0` |
| `user/getStat` | `[0,0,0]` |
| `user/comm/config` | `withdraw_methods=[]`、`withdraw_close=1`、分佣关闭 |
| guest/admin 注册配置 | 固定关闭注册，不读取旧开关决定是否开放 |
| admin config | `invite=[]`、`app=[]`；旧商业字段提交后不保存 |
| admin 看板 | 收入、佣金、工单计数固定为零；流量/成员/在线指标仍真实计算 |

普通成员不能查询或挑选套餐；`getSubscribe` 只带当前 Plan 的核心配置。所有注册、支付回调、下单、礼品卡、优惠券、邀请、提现、工单和自有 App 分发入口退役后返回 404。

已按固定子模块版本 `ef5f43da335092cbff8fdf0ad7ff9b4d92d7d0d7` 检查管理端打包代码；没有改子模块指针或压缩 JS。需要第二轮处理的界面残留包括：

- 用户注册按钮：旧主题不读取 `stop_register`，按钮仍可见，但注册 API 已删除。
- 用户套餐购买、订单、推广、提现、礼品卡、工单及提醒设置入口。
- 管理端订单、支付、优惠券、礼品卡、工单、推广和群发入口。
- 管理端套餐价格/销售开关及用户余额/佣金/折扣列，配置页旧商业选项。
- 管理首页商业图表仍请求 `/stat/getOrder`，请求会 404；共享汇总接口已保留形状，避免查询已删除表。打包代码可处理图表缺数据，但没有浏览器交互验收。

确认另有两个未改动的上游基线问题：知识库 `getCategory` 和公告 `update` 路由指向不存在的方法。本轮未扩大范围修复这两个非核心接口。

## 依赖与构建

Composer 移除三个无剩余调用的直接依赖：`stripe/stripe-php`、`php-curl-class/php-curl-class`、`bacon/bacon-qr-code`。锁文件同时移除相关依赖和原本已无根依赖的旧 Reverb/React PHP 图，共 17 个包。**保留包没有版本变化，也没有新增包**；Workerman、Redis、Horizon、Octane 保留。

本轮运行 Composer 审计时发现上游锁定版本仍有已知依赖公告，未扩大范围升级框架。依赖更新应另做兼容验证，不能把本轮通过功能测试等同于依赖安全审计通过。

原 Dockerfile 会从远端克隆代码，上游 Compose 示例还引用上游镜像。若需要试运行本轮代码，应明确使用本仓库和实施分支，例如：

```bash
docker build \
  --build-arg REPO_URL=https://github.com/CoderDoubleflower/SimpleXboard \
  --build-arg BRANCH_NAME=refactor/round-1-private-members \
  -t simplexboard:round-1 .
```

然后将所用 Compose 配置的镜像指定为 `simplexboard:round-1`。这里给出构建入口，不表示已完成镜像构建或发布。Docker/Octane/外部 Redis 的部署架构本轮未重写。

## 验证证据

验证环境为 PHP 8.4.24、Composer 2.11.1、锁定依赖、PHPUnit 11.5.27、SQLite。`phpunit.xml` 使用真正的 `:memory:` 数据库；命名 Redis 设置缓存隔离为 array，直接 Redis 消息操作在相应用例中显式模拟。

执行的命令：

```bash
composer install --no-interaction --no-scripts
composer validate --no-check-publish
php vendor/bin/phpunit
php artisan route:list --json
git diff --check
```

最终结果：

| 检查 | 结果 |
| --- | --- |
| PHPUnit 全套 | **62 tests / 443 assertions，通过**（9.438 秒） |
| PHP 语法检查 | 应用、配置、迁移、路由、测试、剩余插件及引导代码，共 **277 个文件，0 错误** |
| 路由加载 | `route:list --json` 成功；退役业务路由不存在，并有 HTTP 404 回归覆盖 |
| Composer 安装/清单 | 锁定依赖安装成功，清单验证通过；两个上游通配版本声明仍产生提示 |
| Git 差异检查 | `git diff --check` 通过 |

Composer 清单验证不包含依赖安全结论；已知公告见上面的依赖说明。

自动化验证覆盖：

- 历史 migration 全新建库、安装器管理员创建逻辑、直接创建与旧生成接口。
- 独立凭据、零额度拒绝、管理员鉴权、成员启停、会话撤销、凭据重置、旧订阅失效。
- 旧成员标识/配额/节点范围不变，内部配置回退再升级不重复建立，省略限制字段不放开限制。
- 原分组节点撤销和新分组节点加入的增量消息，提交后入队标记。
- General、Clash Meta、Sing-box 中 VLESS + REALITY、Hysteria2 的 UUID、TLS/REALITY、公钥、SNI、obfs 字段，订阅不泄露 REALITY 私钥。
- 节点鉴权、配置下发、全量用户下发、现有握手接口。
- 商业入口不可达、旧配置不能重新开启、遗留支付插件不能加载或暴露配置。
- 商业表/字段清理后成员、配置、看板和订阅仍可使用。
- 流量缓存累计、日汇总幂等、统计边界与防止月数据重复累计、必要邮件与旧商业邮件退出。

未验证：真实 VPS 连接和撤销时延、实际节点代理版本、真实客户端连接、Cloudflare 反向代理、MySQL/PostgreSQL 升级、Docker 镜像构建、Octane/Horizon 长驻进程和浏览器完整交互。协议代码没有裁剪，实机节点代理与客户端版本需部署时补记。

## 第二轮接续

1. 从本分支最终 HEAD/合并后的对应提交开始，先确认实际 Git 状态。
2. 用独立成员配置替换 `plan_id/group_id/group_ids` 过渡关联，设计统一无限额度语义和节点访问规则。
3. 获得可维护的前端源码或重建精简界面，删除上述旧导航、表单和只读兼容字段。
4. 清理 `v2_stat` 商业列、旧设置/插件记录、剩余邮件与 Telegram 依赖；对可选数据库清理是否已执行做检测。
5. 补足真实节点、客户端、Cloudflare、MySQL/PostgreSQL 和长驻进程验证；不要重置现有成员凭据。

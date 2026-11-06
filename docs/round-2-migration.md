# 第二轮数据迁移与回退

本轮迁移为 `database/migrations/2026_11_06_000002_remove_member_plan_dependencies.php`，从第一轮基线 `184394cba29894f4f868128a738cfd456d73fd9f` 升级。它将旧 Plan / 权限组换成成员直接授权、归档退役数据并区分历史倍率用量与新增实际字节。历史 migrations 保留，普通 `migrate` 会执行本轮迁移。

已在隔离 SQLite 文件数据库演练“备份 → 升级 → 单步 down”，另覆盖第一轮可选商业数据清理已执行的安装。没有在用户服务器或生产数据库执行；MySQL / PostgreSQL 的升级与恢复尚未实跑。生产环境应先在同数据库引擎、同版本的副本执行本节流程。

## 1. 数据变化与保留边界

| 内容 | 升级行为 |
| --- | --- |
| 既有成员身份 | 保留 `id`、订阅 `token`、节点 `uuid`、`banned`、`expired_at`、速度与设备限制。管理员身份和密码字段保留。 |
| 既有额度 | 正数、零保持；旧 `null` 转为 `0`，防止原不可用成员意外变成不限量。`expired_at=0` 仍已到期，不转为永久。 |
| 新建成员 | 名称 / 邮箱至少一项；普通成员没有登录密码。默认 `transfer_enable=null`，表示明确不限量；`0` 表示不可用，正数为字节上限。默认 `access_mode=all`。 |
| 既有授权范围 | 全部旧成员设为 `restricted`。根据旧 `user.group_id` 与节点 `group_ids`，写入 `v2_user_server` 的明确节点集合。隐藏、暂时停用或额度耗尽节点仍保留关联；无旧组的成员保留空集合，不自动共享所有节点。 |
| 新增节点 | `all` 成员自动共享符合条件的节点；`restricted` 成员需显式授权。节点 `show` 仅控制订阅可见，`enabled` 决定节点是否获得成员凭据。 |
| 旧节点 `enabled=null` | 独立节点（`machine_id=null`）转为 `true`，保留旧独立接口可用行为；机器下节点转为 `false`，保留旧机器节点列表仅选 `enabled=true` 的行为。显式 `false` 保持停用，并在本轮订阅及下发入口统一生效。 |
| 普通成员登录 | 清空普通成员的 `password/password_algo/password_salt`，撤销其 Sanctum 会话。旧值先归档。管理员密码和会话不由此迁移撤销。 |
| 周期重置 | 不再按旧 Plan 周期自动清零；只保留管理员明确执行的流量重置。历史 migration 调用的 `reset:traffic` 保留兼容空操作。 |
| 运行中设置 | 旧主题、邮件、Telegram 等设置键可仍存在于 `v2_settings`，退役功能不再读取；本迁移不把它们当成有效配置返回。 |

成员可用条件统一为：非管理员、未禁用、未到期，且额度为 `null` 或正数且 `u+d` 未达上限。节点授权还需节点启用、节点自身额度可用，以及 `all` / 直接关联满足授权。旧节点自身的额度 `null/0` 保持不限量语义；这与成员额度 `0` 不可用的语义不同。

### 历史倍率与实际字节

迁移保持成员原 `u/d`，并把当时的值复制到 `legacy_u/legacy_d`。因此既有额度使用量不会被重算、减少或解禁；升级后的上报仅按实际字节累加，不读取 `rate` 或分时倍率。

- 额度已用：`u + d`，含尚未重置的旧倍率折算贡献。
- 本次计量周期内的实际新增用量：`max(0, u - legacy_u) + max(0, d - legacy_d)`。
- 手动重置同时清零 `u/d` 与 `legacy_u/legacy_d`，旧值写入重置日志。
- 旧 `v2_stat_user` 原表归档。新的同名表从空表开始，只保存本轮实际字节，唯一键为成员、记录时间、记录类型；API 不将旧倍率统计伪装成真实字节。
- 旧 `v2_stat` 原表归档。新表保留成员数量、节点实际流量总量及记录时间，复制这些已有核心指标，去除商业金额和邀请字段。

历史倍率用量通常无法精确反推真实网络字节，归档用于核对，不自动换算。

### 归档表

`v2_round2_archive_user/server/tokens` 为逐行 JSON 快照；`v2_round2_archive_metadata` 记录原字段、索引和迁移时间。它们包括旧密码哈希和令牌记录，应和数据库备份使用同等访问权限。

存在的以下旧表会直接改名为 `v2_round2_archive_` 加原后缀，保留原表结构和数据：

- `plan`、`server_group`。
- `plugins`、`knowledge`、`notice`、`mail_log`、`mail_templates`。
- `order`、`payment`、`coupon`、`commission_log`、`invite_code`、`ticket`、`ticket_message`。
- `gift_card_template`、`gift_card_code`、`gift_card_usage`。
- `stat_user`、`stat`，随后创建新的核心统计表。

第一轮可选清理已经删除的表或列不会凭空恢复；本轮只归档升级前仍存在的数据。不要在回退窗口内删除归档表。

## 2. 升级前备份

先记录旧代码提交或镜像摘要、数据库连接、`APP_KEY`、实际持久化目录、Redis 实例 / 前缀、进程管理配置。保存旧代码或镜像、环境配置和自定义订阅模板、证书等部署文件。`backup:database` 只备份数据库，不包含这些文件，也不包含 Redis 队列。

按以下顺序停止写入：

1. 关闭外部写入口和节点报告入口，停止旧 WebSocket 接收新报告。
2. 让旧代码的 `traffic_fetch`、`stat`、`user_alive_sync`、`node_sync` 队列完成，并核对失败 / 延迟任务及旧统计缓存。不能处理的任务连同 Redis 状态另存，记录处理决定；不要直接清空核心队列。
3. 执行 `php artisan down`，停止 Web / Octane、Horizon、定时调度和 WebSocket 进程。维护模式本身不会停止全部长驻进程。
4. 保留数据库和维护命令所需 Redis 可用，在指向同一数据库、同一持久化目录的维护 shell 中执行备份。准备第二轮代码，但此时不要迁移或启动业务进程。

第二轮备份 CLI 可在旧数据库结构上运行：

```bash
php artisan backup:database
```

记录命令输出的实际文件名，并把文件复制到本次发布目录以外的受控位置；对该副本记录 SHA-256。SQLite 输出 `storage/backup/<时间>_<随机值>.sqlite.gz`，使用原生一致性快照，包含 WAL 中已提交数据；MySQL 输出 `.sql.gz`。其他数据库使用其原生备份工具。

在隔离目录解压 SQLite 副本并检查：

```bash
gzip -dc /safe/path/pre-round2.sqlite.gz > /isolated/path/round1-copy.sqlite
sqlite3 /isolated/path/round1-copy.sqlite 'PRAGMA integrity_check;'
```

结果应为 `ok`。随后让隔离应用连接这个副本，使用独立 Redis 数据库 / 前缀，在副本上完成升级和单步 down；不要把演练指向生产配置。备份副本是恢复保障，迁移内归档不能替代它。

容器安装应在挂载相同配置与数据卷的维护容器内运行上述 CLI，并单独保证所需数据库 / Redis 可用；启动普通应用容器不能代替停写。第二轮容器入口不再自动执行迁移。

## 3. 执行升级与检查

保持业务进程停止，切换到第二轮代码 / 已验证镜像及其锁定依赖，在维护 shell 执行：

```bash
php artisan config:clear
php artisan migrate:status
php artisan migrate --force
php artisan route:clear
php artisan view:clear
php artisan cache:forget admin_settings redis
php artisan migrate:status
```

确认 `2026_11_06_000002_remove_member_plan_dependencies` 已执行、归档存在，抽查至少一个原可用成员、原零 / 空额度成员、原禁用成员及隐藏节点：

- ID、订阅 token、UUID 和原禁用状态未变；管理员能保留原密码登录。
- 旧成员为 `restricted`，直接节点集合与升级前组授权相同；旧空额度为 `0`。
- `legacy_u/d` 等于升级前累计值；正数额度没有放宽。
- 普通密码为空且普通会话已撤销，旧数据可在快照中核对。
- `v2_plan` / `v2_server_group` 等退役运行表已改名归档；节点 ID、地址、协议配置和机器关联保持。

验证后执行 `php artisan up`，用第二轮代码重新启动 Web / Octane、Horizon、WebSocket 和定时调度。不要只热重载一部分服务；节点重新握手时会获取当前全量凭据。核对管理员登录、成员订阅、节点全量下发和一次成员禁用 / 恢复，再开放入口。

额度耗尽在流量任务写入后由原每分钟调度触发撤销；上报间隔、队列积压和节点响应共同影响传播时间。无需等待调度的管理员变更仍通过 after-commit 队列发送。它们不是同步数据平面的绝对零延迟限制。

## 4. 单步 down：保留升级后的核心数据

单步 down 用于撤回本轮结构并回到第一轮代码，前提是归档完整。先重新关闭入口、排空 / 记录队列、进入维护模式并停止全部应用写进程；再为**当前第二轮状态**创建独立备份。

仍在第二轮代码下检查 migration 状态，确认本迁移是要撤回的最后一项，再执行：

```bash
php artisan migrate:status
php artisan migrate:rollback --step=1 --path=database/migrations/2026_11_06_000002_remove_member_plan_dependencies.php --force
```

不要用无范围的 `migrate:reset` 或回退所有历史 migration。本轮只演练了本迁移的 down；旧 SQLite 历史 migration 存在独立的索引回退问题。

down 会先写 `v2_round2_rollback_user/server/stat_user/stat` JSON 快照，再恢复旧字段和改名的原表，其边界如下：

| 数据 | down 后结果 |
| --- | --- |
| 既有成员当前 ID / token / UUID / 禁用状态 | 保留当前值，包含本轮已轮换的凭据；不回到升级前凭据。 |
| 已删除成员 | 不复活。原始行仍可从升级前外部备份恢复。 |
| 当前 `u/d`、到期日、姓名 / 邮箱等核心数据 | 保留。恢复旧代码后旧倍率计算规则重新生效。 |
| 原 Plan / group、员工和退役字段 | 从升级快照恢复；不能把本轮直接授权自动翻译为新的旧组结构。 |
| 本轮改为不限量的旧成员 | 旧代码不支持本轮不限量语义，回填升级前额度；旧空额度且当前仍为零时恢复原空值，继续不可用。 |
| 本轮新增普通成员 | 保留行，但设为禁用、额度零；无可安全推导的旧 Plan / 组。 |
| 普通成员旧密码 | 原普通成员旧哈希可恢复；管理员当前密码不被替换。 |
| Sanctum 会话 | 撤销的会话不自动恢复；`v2_round2_archive_tokens` 继续保留，回退后重新登录。 |
| 节点 | 保留当前核心配置和流量；恢复旧 group / rate 字段及升级前 enabled 值。本轮新增节点没有旧组授权。 |
| 统计 | 旧 `stat/stat_user` 原表恢复；升级期间实际统计保存在 rollback 快照中，不混入旧倍率统计。 |
| 新增字段 / pivot | `name/access_mode/legacy_u/legacy_d` 和 `v2_user_server` 暂留，保存新数据；第一轮代码不使用这些授权字段。 |
| 设置、部署文件 | down 不恢复设置更改、环境文件、模板或镜像；按发布记录处理。 |

down 成功后升级用的 user/server JSON 快照与 metadata 会删除；令牌快照及 rollback 快照保留。已存在的 rollback 快照不会被覆盖，因此反复升降级前必须另作带时间的外部备份，不能把它当成每次操作的最新备份。

随后切回保存的第一轮代码或镜像，在继续停止业务进程的状态下清理配置、路由、视图及 `admin_settings` 缓存，确认旧表存在和管理员能认证，再退出维护模式并重启第一轮进程。不要在 down 后继续运行第二轮业务服务。

## 5. 全量备份恢复：回到升级前时点

当迁移中断、归档缺失，或需要精确恢复升级前数据库时，使用外部备份。不要手工拼接残缺归档后继续迁移。全量恢复会用备份时点替换当前数据库：升级后创建 / 删除 / 轮换、流量、设置变化都不会自动合并；先保留当前数据库和 Redis 状态，供以后核对。

SQLite 原生备份可以在第二轮维护 CLI 中恢复。将已核对的 `.sqlite.gz` 放入当前 `storage/backup`，使用**文件名**参数：

```bash
php artisan down
php artisan backup:restore pre-round2.sqlite.gz --force
```

命令要求维护模式和 `--force`，检查解压后 `PRAGMA integrity_check`，先创建当前库安全备份，再替换数据库文件并清理旧 WAL / SHM，保留原文件权限；打印的恢复前副本也应另存。它不会替你停止其他进程，也不会替你切换代码。

如果 CLI 本身无法启动，可在全部 SQLite 连接已关闭后，用原生工具从已校验的备份恢复到单独临时文件，再替换配置指向的实际数据库文件；同时保存并移开旧数据库及其 WAL / SHM，保留属主与权限。不要在正在写入的 SQLite 文件上直接覆盖或只复制主文件。

MySQL 使用原生客户端导入对应 SQL 备份；PostgreSQL 使用相应原生备份 / 恢复流程。上述 SQLite CLI 不接管这些恢复。

数据库恢复完成后，恢复与备份匹配的旧代码 / 镜像、部署文件、环境配置及 `APP_KEY`。不要在恢复后的旧数据库上启动会自动升级的新进程。清理旧应用缓存；按备份时点处理遗留队列，避免恢复后的数据库再次消费已入账或不兼容的任务。最后检查 migration 记录、成员凭据、权限组、管理员登录和订阅，再退出维护并启动旧服务。

## 6. 已执行验证与仍需补验

核心测试命令：

```bash
php vendor/bin/phpunit --filter 'PrivateMembersTest|PrivateMemberMigrationTest|MemberNodeLifecycleTest|PrivateSubscriptionTest|ServerHandshakeTest'
```

已通过 26 个测试、326 个断言，包括原生 SQLite 文件副本完整性检查、备份哈希保持、升级 / 单步回退、旧 null / 零额度、独立 / 机器节点空 enabled、直接授权、实际字节及 VLESS + REALITY / Hysteria2 配置输出。此处不是整个项目测试数。

真实控制链路复现命令（要求 PHP、Redis 和 Python `websockets` 可用）：

```bash
python3 tests/Support/run-stack.py \
  --php /path/to/php \
  --redis /path/to/redis-server \
  --artifacts /isolated/path/node-e2e \
  -- python3 tests/Integration/node_lifecycle.py
```

2026-11-06 在独立 SQLite / Redis / Horizon / Laravel HTTP / Workerman / `schedule:work` 进程中通过全部 17 项。生产每分钟调度未被人为加速；报告保存为 artifacts 目录下的 `node-lifecycle-results.json`。测试覆盖全量与增量、凭据 remove-before-add、禁用 / 删除 / 额度耗尽后撤销、恢复、节点禁用与重连，以及全局服务端令牌轮换。首轮真实测试发现长驻 WebSocket 的设置实例持有旧令牌，已改为每次握手重新读取设置；完整复跑通过。

这验证的是真实控制链路，没有连接用户 VPS，也没有宣称真实 VLESS / Hysteria2 客户端数据平面通过。机器 `is_active` 原有 HTTP 鉴权和 WS 握手门槛保留；旧版已连接机器在状态变化后不立即断开，本轮未扩展该既有行为。MySQL / PostgreSQL 数据迁移、完整镜像构建与真实服务器上线仍各需独立验证。

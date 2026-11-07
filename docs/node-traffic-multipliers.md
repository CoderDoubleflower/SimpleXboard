# 每节点固定流量倍率

本补丁在第二轮 `b339e40512b247150cd2671a1d01e625453af750` 之后增加固定倍率，沿用现有 HTTP 上报、Horizon 计量和 WebSocket 成员同步，不改变节点协议。

## 设置与计量规则

在「VPS 与节点 → 编辑节点」填写流量倍率，节点列表会显示对应值。范围 `0–100`，最多两位小数，默认 `1`；`0` 表示不扣成员额度。分时倍率仍不可用。

| 项目 | 计量口径 |
| --- | --- |
| 成员 `u/d`、已用额度、剩余额度与资格判断 | 按各份报告对应的节点倍率累计；保留升级前已用额度 |
| 成员 `actual_u/actual_d` | 当前手动重置周期内的实际上传 / 下载 |
| 成员每日统计、节点 / VPS 流量、概览与流量排名 | 实际网络字节 |
| 节点自身流量上限 | 实际字节，不随倍率改变 |
| 订阅 `subscription-userinfo` 用量 | 与成员额度一致，使用折算后的 `u/d` |
| `legacy_u/legacy_d` | 第二轮以前无法反推真实字节的历史额度，不混入新实际统计 |

例如，同一成员分别在 `0.5×` 和 `2×` 节点各传输 10 GB，其实际流量为 20 GB，额度累计为 25 GB。`transfer_enable=null` 仍表示不限量，记录用量但不因用量停用；`transfer_enable=0` 仍无可用额度，即使节点设为 `0×` 也不会下发成员凭据。

面板收到一份报告时读取数据库中的当前倍率，并将它固定进队列任务。之后编辑倍率，不改变已入账或已入队报告。报告可能包含在编辑之前产生的流量；由于协议没有每段流量的发生时间，**整份报告按接收时的倍率处理**，不能按流量发生时刻拆分。修改无需重启节点或重连已有 WebSocket。

倍率转换为整数百分数计算。上传、下载各保留 `0–99` 百分之一字节的余数，跨节点与报告继续累计。例如，两份各 1 byte 的上传报告在 `0.5×` 下累计扣 1 byte。余数保存在数据库中，重启不会丢失；手动重置同时清零额度、实际累计、历史额度及余数，并保留重置前数值的日志。每日流量统计不随成员手动重置删除。

## 数据迁移

新增迁移：`database/migrations/2026_11_07_000001_add_node_traffic_multipliers.php`。

- `v2_server.rate` 为两位小数，现有与新节点默认 `1.00`。不会读取第二轮归档中的旧倍率。
- 成员新增独立实际计数 `actual_u/actual_d`，从升级前 `max(0,u-legacy_u)`、`max(0,d-legacy_d)` 回填，保留第二轮以来已产生的实际流量。
- 成员新增 `billing_u_remainder/billing_d_remainder`，初值为零。
- 不改变已有额度、token、UUID、授权、到期时间或禁用状态，不重新折算历史用量。

### 已有安装升级

此补丁需要数据库迁移和应用进程重启。只拉取代码或重启容器不会自动升级数据库。

1. 暂停管理写入与节点报告入口，让旧版本待处理的 `traffic_fetch`、`stat` 等任务处理完；记录失败与延迟任务。随后停止 Web / Octane、Horizon、WebSocket 和调度器。
2. 备份当前数据库并保存匹配的旧代码或镜像。保持维护命令所需的数据库 / Redis 可用，SQLite / MySQL 可以使用 `php artisan backup:database`；备份应另存并核对。
3. 切换到补丁版本及其锁定依赖，在保持业务进程停止的维护环境运行：

   ```bash
   php artisan config:clear
   php artisan migrate --force
   php artisan route:clear
   php artisan view:clear
   php artisan migrate:status
   ```

4. 确认迁移成功，重启全部应用进程加载新代码，再恢复入口。在管理台确认已有节点为 `1×`，再逐个设置需要的倍率。

旧版本已经入队且不带倍率的计费任务按 `1×` 兼容，但这不能代替排空队列和停止旧进程；升级后继续运行旧计费 worker 会漏记新增实际计数。

### 回退边界

第二轮代码通过 `u/d - legacy_u/legacy_d` 推导实际流量，不能表达一般倍率下的两套计数。本迁移的 `down()` 在删除任何新增字段前检查：每个成员的实际计数仍与旧推导相同，且两个计费余数均为零，才允许回退。

在维护停写并备份当前状态后，确认本迁移是最新一项，才可执行有范围的回退：

```bash
php artisan migrate:rollback --step=1 --path=database/migrations/2026_11_07_000001_add_node_traffic_multipliers.php --force
```

若已有倍率计量导致两套计数不同，命令会在破坏数据前拒绝，不会调整额度或假造实际流量。此时保留当前库与队列，再用升级前备份和匹配代码整体恢复；不要手工删除字段或修改计数绕过检查。恢复备份会回到备份时点，之后的数据需要另行核对。正常回退删除倍率设置，第二轮后续报告恢复 `1×`。恢复步骤与进程停写要求可参阅[第二轮迁移说明](round-2-migration.md)。

## 验证方式

本补丁的验收覆盖默认值、迁移与身份保持、输入校验、独立节点倍率、零倍率、无限 / 零 / 正数额度、定点小数累计、倍率接收快照、实际统计、手动重置和有损回退拒绝；另使用真实独立 SQLite 写入进程验证锁等待与余额更新，检查事务回滚不会重复扣额。

真实链路继续由现有脚本验证，并增加倍率场景：

```bash
php vendor/bin/phpunit
npm --prefix panel test
npm --prefix panel run build
python tests/Integration/install.py --artifacts storage/framework/testing/install-e2e
python tests/Support/run-stack.py --artifacts storage/framework/testing/node-e2e -- python tests/Integration/node_lifecycle.py
python tests/Support/run-stack.py --without-scheduler --artifacts storage/framework/testing/panel-e2e -- python panel/tests/e2e.py
python tests/Integration/container.py --image simplexboard:ci --artifacts storage/framework/testing/container-e2e
```

节点集成保持真实 HTTP / Redis / Horizon / Workerman 和原调度器，验证 `0.5× / 2× / 3× / 0×`、修改后不重算、独立实际统计、分数累计、免费节点与零成员额度、按倍率耗尽后订阅 / HTTP 全量 / WebSocket 一致撤权。容器集成验证完整重启前后的倍率、实际计数与分数余量。浏览器集成验证桌面 / 手机管理流程与倍率保存、重开编辑和列表展示。

CI 同时构建当前源码的 Docker 镜像，并实际执行 VLESS + REALITY、Hysteria2 代理连接回归。不会发布镜像或连接生产 VPS。

### 2026-11-07 本地验收

| 项目 | 已执行结果 |
| --- | --- |
| 后端全量 | 92 tests / 895 assertions 通过；含迁移回退、真实 SQLite 写竞争、事务重试和大整数边界 |
| 前端模块 | 18 / 18 通过；构建产物 `5fb0be8abce9f7db` |
| 全新安装 | 6 / 6 通过；真实安装、HTTP 登录、首个成员与重复安装保护 |
| 真实节点链路 | 24 / 24 通过；PHP 8.4.24、独立 SQLite / Redis / Horizon / Workerman |
| 浏览器 | 14 / 14 通过；1440px 桌面、390px 手机，12 张截图，无 JavaScript 异常或意外 HTTP 错误 |

加权额度撤销使用原有每分钟调度，测试未加速或替换撤权任务；实际延迟受报告间隔、调度周期与队列影响。完整 Docker 构建、容器重启与实际协议连接由合并前 CI 执行；最终提交和各项结果以对应 PR 的检查记录为准。

本补丁的数据库迁移与回退演练使用 SQLite。MySQL / PostgreSQL 未在本次本地环境启动实例，不能将 SQL 兼容性审查视为这两种数据库的实际验收。

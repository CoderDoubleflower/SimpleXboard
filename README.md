# SimpleXboard

面向个人与熟人共用的 VPS 节点和订阅管理台，基于 [Xboard](https://github.com/cedar2025/Xboard) 精简。

一个管理员维护机器、节点和成员。成员只持有独立订阅 token 与节点 UUID，不需要注册、购买套餐或登录用户中心。新成员默认**永久有效、不限流量、共享所有已启用节点**；已有成员升级时保留原先的节点授权范围。

## 管理台

后台保留五个一级菜单：

| 菜单 | 功能 |
| --- | --- |
| 概览 | 节点状态、成员数量、实际上传与下载、运行状态 |
| VPS 与节点 | 机器凭据、节点连接参数、启停及订阅可见性 |
| 成员与订阅 | 创建与编辑成员、独立订阅、停用、删除、凭据轮换与导出 |
| 日志与备份 | 管理审计、失败任务、数据库备份与离线恢复说明 |
| 设置 | 管理员密码与会话、订阅域名、节点同步和订阅模板 |

管理端源码在 [`panel/`](panel/)，使用原生 JavaScript 模块与 CSS。构建产物由确定性脚本生成至 `public/assets/panel/`，不依赖上游管理端产物子模块。根路径不提供成员门户。

VLESS + REALITY、Hysteria2、现有其他协议及必要客户端格式保留。Redis、Horizon、Octane、WebSocket、机器与节点鉴权继续使用现有架构。

## 成员和额度

```http
POST /api/v2/{管理路径}/user/create
Authorization: Bearer {管理员会话令牌}
Content-Type: application/json

{
  "name": "家人",
  "email": "friend@example.com",
  "transfer_enable": null,
  "expired_at": null
}
```

`name` 和 `email` 至少提供一个。返回成员记录和独立订阅地址。成员不设置密码，也不能调用后台 API。

| 字段 | 语义 |
| --- | --- |
| `transfer_enable: null` | 不限额，已有用量仍然计数 |
| `transfer_enable: 0` | 无可用额度，不下发订阅或节点凭据 |
| `transfer_enable: 正整数` | 用量硬上限，单位为 bytes |
| `expired_at: null` | 永久有效 |
| `access_mode: all` | 共享所有已启用节点，新成员默认值 |
| `access_mode: restricted` + `server_ids` | 直接指定成员可以访问的节点 |

节点 `enabled` 决定是否可用，`show` 只控制订阅中的展示。隐藏节点不会被当作停用。禁用、删除和凭据轮换会向相关节点发送撤销；全量重连也遵循同一资格规则。

第二轮删除了套餐、分组、员工角色、普通成员登录、CMS、主题和插件框架、Telegram 与邮件入口、倍率计费及订阅说明伪节点。原有自动套餐周期重置已停用，硬上限需要管理员明确调整或手动重置。

## 构建与运行

使用本仓库的锁文件和实际检出源码：

```bash
composer install --prefer-dist --no-interaction
npm --prefix panel ci
npm --prefix panel run build
```

本地镜像构建：

```bash
docker build -t ghcr.io/coderdoubleflower/simplexboard:local .
```

Compose 示例均指向此 fork 的本地构建镜像，并包含 `build.context: .`。镜像启动不会自动迁移数据库或拉取代码。Push/PR 只运行验证；发布镜像需要手动触发发布工作流并指定标签。

全新安装使用 `php artisan xboard:install`，按提示配置数据库、Redis 和管理员。已有数据库**不要重新安装**；升级与回退步骤见下方交接记录。运行时需启动 Web、Horizon、节点 WebSocket 和调度器；沿用所选部署方式的进程管理配置。

管理员忘记密码时在可信主机终端执行：

```bash
php artisan reset:password admin@example.com
```

密码通过隐藏输入提供，更新后撤销该管理员的全部旧会话。

## 开发验证

```bash
php vendor/bin/phpunit
npm --prefix panel test
npm --prefix panel run build
python -m pip install -r tests/requirements-e2e.txt
python -m playwright install --with-deps chromium
python tests/Support/run-stack.py -- python tests/Integration/node_lifecycle.py
python tests/Support/run-stack.py --without-scheduler -- python panel/tests/e2e.py
```

集成测试要求本机 PHP Redis 扩展、Redis 服务端程序及 Python；测试脚本创建独立 SQLite、Redis 和随机端口，启动真实 Horizon/Workerman，结束后清理，不使用已有业务库。可通过 `--php`、`--redis` 指定程序路径，通过 `--artifacts` 指定测试记录目录。

## 升级、回退与验证记录

- [第二轮交接与自测记录](docs/round-2-handoff.md)
- [第二轮数据迁移、备份与回退](docs/round-2-migration.md)
- [第二轮镜像构建验证](docs/round-2-build-verification.md)
- [第一轮历史记录](docs/round-1-handoff.md)

升级保留旧成员 ID、token、UUID、禁用状态和用量；历史 `0/null` 额度不会变成无限成员。旧分组转换为直接成员与节点授权，历史倍率统计与新实际字节分开保存。具体映射和回退边界以迁移文档为准。

## 许可证与来源

延续上游 [MIT License](LICENSE)。保留原项目的节点协议实现和运维架构，感谢 Xboard 及其贡献者。

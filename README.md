# SimpleXboard

面向个人与熟人共用的 VPS 节点和订阅管理台，基于 [Xboard](https://github.com/cedar2025/Xboard) 精简。

## 第一轮状态

第一轮已移除支付、订单、优惠券、礼品卡、推广返佣、提现、工单、公开注册、试用和商业邮件提醒。管理员可以直接创建可用成员，每人拥有独立的订阅 token 和 UUID，不需要购买或订单激活。

保留管理员认证、成员管理、订阅生成、节点与机器管理、VLESS + REALITY、Hysteria2 及原有其他协议、流量统计、在线状态、节点增量同步、日志和备份。

套餐与分组暂时作为内部配置保留。全新成员默认使用隐藏的内部配置：**1024 GiB（1 TiB）、每月 1 日重置、长期有效**。现有成员的凭据、额度、到期时间和节点分组不由本轮迁移重写。创建节点时需要为它选择对应成员分组；不会把旧节点自动开放给默认组。

**第一轮交接和验证记录：[docs/round-1-handoff.md](docs/round-1-handoff.md)**。第二轮须从本轮实际分支/提交继续。

## 创建成员

现有管理界面“生成用户”路径继续可用，未选择内部套餐时会分配默认配置。也新增管理员 API：

```http
POST /api/v2/{管理路径}/user/create
Authorization: Bearer {管理员会话令牌}
Content-Type: application/json

{
  "email": "friend@example.com",
  "password": "replace-with-a-strong-password"
}
```

返回成员信息和独立订阅地址。可选 `plan_id` 复用已有配置；`transfer_enable` 使用 **bytes**，必须大于零；`expired_at` 为 Unix 秒时间戳，省略或 `null` 表示长期有效。内部 Plan 的额度单位沿用 GiB。

## 运行与升级

运行环境仍使用 PHP、Laravel 12、Redis、Horizon 与 Octane。本轮没有改变节点通信架构。

从本仓库的第一轮分支取代码后，按交接文档执行依赖安装、普通 migration 与服务重启。普通 migration 保留商业历史数据；永久清理需要显式执行 `database/cleanup` 中的独立迁移。

原项目的安装文档和 Compose 示例暂作环境参考，其中的 `cedar2025/xboard` 镜像仍是上游完整版本。**运行 SimpleXboard 时应使用本仓库代码构建的镜像或源码，不能直接用上游镜像替代。** 本轮未发布镜像或部署服务器，构建命令见交接文档。

## 前端边界

管理端 `public/assets/admin` 是编译产物子模块，用户主题也是已打包资源。第一轮没有手改压缩 JS，旧界面中的套餐销售、订单、推广、工单等导航可能仍出现，对应后端入口已退役。第二轮会处理前端入口和彻底去掉套餐/分组。

## 开发验证

```bash
composer install
php vendor/bin/phpunit
```

测试默认使用 SQLite 内存数据库和隔离的缓存。Redis 消息相关测试显式模拟消息边界，不能代替真实 VPS、节点代理和客户端连接测试。

## 许可证与来源

延续上游 [MIT License](LICENSE)。保留原项目的节点协议实现和运维架构，感谢 Xboard 及其贡献者。

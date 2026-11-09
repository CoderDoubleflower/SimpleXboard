# VPS 节点的一键安装、升级和卸载

在「VPS 与节点 → VPS」找到机器，点击 **节点维护**。选择动作后复制一条命令，在对应 VPS 的终端执行即可。面板负责生成命令；点击复制不会直接登录 VPS、重启服务或删除任何记录。

## 可用动作

| 动作 | 行为 |
| --- | --- |
| 安装接入 | 自动识别 Linux amd64 / arm64，下载经过验收的 SimpleXboard 配套节点，创建标准 systemd 服务并接入这台机器。 |
| 升级 / 替换 | 替换节点程序，保留全部实例、配置、凭据、机器绑定、原 systemd unit 及本机 xbctl。自动备份，启动失败时尝试恢复旧程序。 |
| 卸载节点 | 停止并删除整台 VPS 的 xboard-node 服务及其运行程序，默认保留 `/etc/xboard-node` 配置、凭据和独立备份。 |
| 同时删除当前配置与凭据 | 在卸载页明确勾选后追加 `--purge`，删除 `/etc/xboard-node`；独立备份仍保留。 |

升级会短暂重启节点服务，影响该服务下的全部实例与已有连接。升级和卸载命令不包含面板令牌。安装命令包含接入凭据，请勿公开分享。

已有标准安装再次运行「安装接入」会保留原绑定并升级；不会用命令里的新机器 ID 覆盖现有实例。卸载后保留的配置可通过再次运行安装命令恢复。需要迁移机器绑定时，应明确修改配置，不能把重新安装当作重新绑定。

## 适用安装方式

支持使用面板原安装命令创建的标准 systemd 部署：

- 程序：`/usr/local/bin/xboard-node`
- 管理工具：`/usr/local/bin/xbctl`
- 配置：`/etc/xboard-node/config.yml`
- 凭据：`/etc/xboard-node/credentials.env`
- 服务：`xboard-node.service`

脚本在修改前检查实际服务启动路径、架构、关键文件和并发维护锁。检测到 Docker 节点、容器内执行、符号链接或不兼容的自定义服务时，会明确退出，避免改错安装。普通用户可以经 sudo 提权；root 可直接运行。

## 备份与恢复

升级前把原二进制、完整配置、unit 和 xbctl 备份到 `/var/backups/simplexboard-node/<时间与随机后缀>/`，目录仅 root 可访问。备份含接入凭据，不应公开。

新版文件先完成下载、SHA-256 校验和本机版本验证，再原子替换。服务重启后检查实际健康端口和进程稳定性；健康接口关闭时不强制检查默认端口，也不把“没有授权用户导致核心尚未就绪”当作程序启动失败。新程序启动失败会恢复备份程序并重新启动；若恢复也失败，命令会返回失败并打印备份路径。

卸载及 `--purge` 都不删除独立备份。需要清除所有历史凭据时，还应自行检查并清理该备份目录。已经使用受管理出站的节点不能直接降级到不支持出站的版本；先明确取消出站绑定，再执行有意的降级。

## 删除面板 VPS 记录

“删除 VPS 记录”弹窗会先提供卸载命令。可先复制到对应 VPS 执行，再确认删除面板记录。复制命令、切换选项和取消弹窗都不会删除记录。

删除记录使机器令牌失效，并解除关联节点的机器绑定；它不会代替 VPS 上的程序卸载。节点端卸载同样不会自动删除面板机器或节点记录。

## 发布来源

维护脚本和二进制使用本仓库固定的公开 Release，不再使用上游 `latest`，也不依赖会到期、需要 GitHub 登录的 Actions artifact。

当前版本信息集中于 `resources/node/release.json`。Release 提供两个架构的节点与 xbctl、固定的上游安装器、`SHA256SUMS`、`build-info.json` 和完整补丁后节点源码。首次安装仍复用固定上游配置生成方式，但只传入本 Release 已校验的本地程序。

两种原生架构均经过 Go 回归、并发检查、真实代理链路以及真实 systemd 的安装、升级、校验失败保护、启动失败恢复和卸载验收后，master 工作流才发布公开下载。已发布版本不覆盖；改变发布输入必须更新版本及 tag。

## 验证入口

```bash
php vendor/bin/phpunit --filter NodeMaintenanceCommandsTest
npm --prefix panel test
python tests/Integration/node_maintenance.py
python tests/Support/run-stack.py --without-scheduler -- python panel/tests/node_maintenance.py
```

`tests/Integration/node_maintenance_systemd.py` 会实际创建与删除标准系统服务，仅允许在明确授权的全新 GitHub Actions 测试 VM 中运行，不能在用户 VPS 上运行。

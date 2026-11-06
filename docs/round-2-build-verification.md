# 第二轮镜像构建验证

## 当前结论

2026-11-06，GitHub CI 已从提交 `008147179a61907f67b5f16214141406d6a486fa` 的实际检出源码完成 Linux amd64 全量镜像构建，加载到 runner 的 Docker daemon，随后容器内 `php artisan --version` 成功返回 `Laravel Framework 12.54.1`。没有发布镜像或部署服务器。

证据：[run 42291285755 / image job 128145060328](https://github.com/CoderDoubleflower/SimpleXboard/actions/runs/42291285755/job/128145060328)。构建日志中的镜像摘要为 `sha256:2c828d4c09be3ae3cc130d1e9907a9ec7039f4eb6bf54cdb14acbb74fc660bcd`，本地标签为 `simplexboard:ci`，`push: false`。Dockerfile 的 PHP 最低版本与扩展检查、Composer 锁文件安装、前端构建、应用阶段及镜像 CLI 启动均已执行成功。
当前 Dockerfile 从本地上下文复制 SimpleXboard 后端；Node 阶段使用 `panel/package-lock.json` 构建前端，再将产物复制到应用阶段；Composer 使用仓库中的锁文件安装。Push/PR 工作流只验证，镜像发布只允许显式 `workflow_dispatch` 并指定标签。

## 先前本地构建尝试与环境限制

- Linux amd64；无 Docker daemon。
- Buildah `1.39.3`，image-spec `1.1.0`，runtime-spec `1.2.0`。
- 工具来自 Debian trixie 官方包，仅下载并解压到临时 `runtime/container-tools`，没有安装系统服务或更改全局权限。
- 使用 VFS 存储与 chroot isolation，缓存、配置和运行目录均在临时目录中；没有连接用户服务器。

从仓库目录执行的构建命令（`../runtime/bin/buildah` 是配置了上述临时目录的本地包装脚本）：

```bash
unshare --user --map-root-user ../runtime/bin/buildah build \
  --signature-policy ../runtime/container-tools/config/containers/policy.json \
  --isolation chroot --network host --target runtime --layers \
  -t localhost/simplexboard:round2-runtime -f Dockerfile .
```

命令已进入 Dockerfile 的 `FROM phpswoole/swoole:php8.2-alpine`，连接 Docker Hub 并开始下载镜像层。解压基础镜像时失败：

```text
potentially insufficient UIDs or GIDs available in user namespace
(requested 0:42 for /etc/shadow)
lchown /etc/shadow: invalid argument
```

外层 `/proc/self/uid_map` 为 `1000 0 1`，只提供单个 ID 映射。直接运行 rootless Buildah 的较早尝试也报告 `write /proc/19/setgroups: operation not permitted`。`unshare --user --map-root-user` 可成功创建命名空间，但不能补足基础镜像所需的用户/组映射。

失败发生在 Dockerfile 的 `RUN` 步骤之前，因此容器内 PHP 版本检查、依赖安装、应用阶段及镜像启动均未在这次尝试中完成。没有使用忽略所有权错误的选项，也没有改写系统映射。

## 复现构建

在支持正常用户/组映射的 Docker/BuildKit 环境中运行完整构建，例如：

```bash
docker build -t ghcr.io/coderdoubleflower/simplexboard:local .
docker run --rm --entrypoint php \
  ghcr.io/coderdoubleflower/simplexboard:local artisan --version
```

仓库 CI 已实际通过完整本地上下文构建及上述 CLI 启动检查，`push: false`。真实协议连接由独立 job 验证；容器默认长驻服务及重启保持性正在独立启动测试中核验，不能用 CLI 版本输出替代。

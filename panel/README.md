# SimpleXboard 管理台

这套界面随当前 fork 的后端一起构建和发布，源码在本目录中维护。管理台只有五个一级菜单：概览、VPS 与节点、成员与订阅、日志与备份、设置。成员通过独立订阅链接使用节点，不进入管理台。

## 构建

需要 Node.js 20 或更新版本。构建使用 Node 标准库，无前端运行时或构建第三方依赖。

```sh
npm --prefix panel ci
npm --prefix panel test
npm --prefix panel run build
```

`scripts/build.mjs` 对所有 `src/` 文件的相对路径及内容按固定顺序计算 SHA-256。生成目录为 `public/assets/panel/<内容哈希>/`，入口和 CSS 位于 `manifest.json`，清单同时记录每个文件的 SHA-256 integrity。构建不写时间戳；相同源码重复构建得到完全相同的文件。

源码、`package-lock.json`、清单和完整构建产物均应受版本管理。不要直接修改产物。CI 可在构建后执行：

```sh
git diff --exit-code -- public/assets/panel
```

根目录的 Node 依赖用于面板服务运行，与本目录独立。不要因管理台无依赖而移除根目录依赖。

## 源码结构

| 文件 | 责任 |
| --- | --- |
| `src/app.js` | 管理员登录、五个菜单、页面导航、会话失效处理 |
| `src/api.js` | 管理接口、超时、错误、二进制授权下载 |
| `src/ui.js` | 安全 DOM 构建、表单、对话框、列表、状态及复制 |
| `src/domain.js` | 成员、节点表单到后端字段的转换 |
| `src/format.js` | 流量、时间、状态、协议和订阅格式 |
| `src/pages/` | 五个页面的实际接口调用和交互 |
| `src/styles.css` | 桌面与移动布局 |
| `tests/` | Node 模块测试和真实后端浏览器验收 |

Blade 入口为 `resources/views/admin.blade.php`，只加载本地清单中的产物。标题与 `secure_path` 通过带 `JSON_HEX_*` 转义的 `application/json` 脚本传入；接口响应和用户内容通过 DOM 文本节点显示，不插入 HTML。管理员会话仅存储于当前标签页的 `sessionStorage`，下载请求通过 Authorization 请求头携带会话，不将令牌放入下载 URL。

## 关键业务语义

- 成员只需名称或邮箱，无密码；新成员默认不限额度、永久有效、共享全部启用节点。指定节点模式使用 `server_ids` 直接授权。
- 成员流量上限留空代表 `null`（不限），`0` 代表没有可用额度。节点上限的 `null` 和 `0` 则都代表不限。两者按后端既有语义分别处理。
- 成员“实际流量”只显示 `actual_u + actual_d`；“额度已用”显示含历史折算量的 `u + d`。不将历史额度混称为实际传输量。
- 节点“启用”影响成员授权；“订阅展示”只影响订阅内容。机器记录及其令牌独立于节点。
- VLESS/REALITY 与 Hysteria2 提供常用连接字段。REALITY 密钥使用浏览器 WebCrypto X25519 生成。已有其他协议和高级字段可通过 JSON 维护，编辑时保留未修改的扩展字段。
- 备份从真实后端生成并通过授权请求下载。恢复说明提供服务器 CLI 操作，不提供网页覆盖数据库的按钮。失败任务仅展示安全文本，不提供任务重试或删除动作。
- 更改后台路径后使用后端返回的同源 `admin_url` 跳转。更新密码或撤销全部会话后，当前会话也立即退出。

## 真实浏览器验收

`tests/e2e.py` 需要 Python Playwright 和 Chromium。首次安装可运行：

```sh
python3 -m pip install playwright
python3 -m playwright install chromium
```

通过仓库的隔离栈运行，不要指向正在使用的实例：

```sh
python3 tests/Support/run-stack.py \
  --php /path/to/php \
  --redis /path/to/redis-server \
  --artifacts /tmp/simplexboard-panel-e2e \
  --without-scheduler \
  -- python3 panel/tests/e2e.py
```

栈会准备临时 SQLite、真实 Redis/Horizon、WebSocket 服务及管理员，注入 `E2E_BASE_URL`、`E2E_ADMIN_PATH`、`E2E_ADMIN_EMAIL`、`E2E_ADMIN_PASSWORD`、`E2E_ARTIFACT_DIR`。脚本拒绝非本地地址和没有 `SIMPLEXBOARD_E2E=1` 标记的环境。`E2E_CHROMIUM_PATH` 可指定系统浏览器路径；未指定时使用 Playwright 已安装的 Chromium。

验收涵盖登录和会话、VPS 创建/编辑/令牌/命令、两种节点协议、节点停用与隐藏、成员配额和授权、真实订阅请求、凭据轮换、导出、备份、日志、安全设置、后台路径迁移、HTML 特殊字符安全引导、移动布局、故障提示及恢复，并输出 JSON 报告和截图。测试只操作隔离栈中的验收记录。实际 VPS 安装、外部 TLS 证书、网络转发质量及外部客户端连通性仍需部署后在真实网络中验证。

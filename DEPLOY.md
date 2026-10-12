# 部署与更新

更新日期：2026-10-11。本文针对当前教程后端、原生微信小程序和 WordPress 同步插件。历史一次性补丁及旧版本安装记录不再作为操作步骤。

## 教程后端

现有站点为 `jc.52okp.com`，服务器由用户确认使用 Debian 12、宝塔、PHP 8.4 与 MySQL 5.7。站点运行目录指向后端项目的 `public/`，Nginx 伪静态使用 [backend/宝塔伪静态.conf](backend/宝塔伪静态.conf)。EdgeOne 在访客侧提供 HTTPS；若采用 HTTP 回源，宝塔源站无须为这个配置另装证书。

生产数据库、微信小程序 AppSecret、WordPress 同步密钥和更新令牌存于服务器 `.env`，不纳入源码或发布 ZIP。配置项见 [backend/.env.example](backend/.env.example)。宝塔 PHP 8.4 CLI 的已核实路径是 `/www/server/php/84/bin/php`；后台安装更新若不能自动找到 CLI，可在服务器 `.env` 设置 `UPDATE_PHP_CLI` 为该路径。

新建独立站点时，可用 `backend/scripts/build-first-deploy.php` 生成首发包，上传到新站根目录并访问 `/install.php`。如果使用旧 SQL 迁移数据，先在宝塔把备份导入**新建的 MySQL 5.7 库**，再由网页安装器完成迁移。已经安装的站点不重新执行 `install.php`；安装完成后移除服务器上的安装入口与临时安装凭据。当前仓库不保存旧 SQL 备份或历史首发 ZIP。

## 教程后端在线更新

后端项目标识为 `jc52okp`。每次发布需要 GitHub [52okp/jc52okp](https://github.com/52okp/jc52okp) 的正式 `vX.Y.Z` Release 同时包含 `jc52okp-update.zip` 与 `update-manifest.json`。随后在 52okp 统一更新中心导入、校验并发布，站点后台才会检查到新版。清单的 `from` 必须等于站点当前 `APP_VERSION`；不要覆盖已发布版本的资产。

构建增量更新时，先把**紧邻上一版** Release 的两个资产下载到仓库外的临时目录。以从 1.0.7 升到 1.1.0 为例：

```powershell
gh release download v1.0.7 -R 52okp/jc52okp -p jc52okp-update.zip -p update-manifest.json -D D:/jc-release/base
php backend/scripts/build-update.php --project=jc52okp --version=1.1.0 --from=1.0.7 --notes-file=D:/jc-release/notes.txt --output=D:/jc-release/output --base-package=D:/jc-release/base/jc52okp-update.zip --base-manifest=D:/jc-release/base/update-manifest.json --manual-migration=database/migrations/20261011120000_harden_authentication.php
```

先创建 `base/` 和 `output/`，并准备非空的 `notes.txt`。构建器会校验基线包和清单，只加入新增或哈希变化的文件；外部清单保留完整文件哈希清单，所以下一版可以继续以前一份增量包为基线。包内版本文件保持在更新中心要求的 4 KB 以内。删除旧代码文件会被拒绝，须另行设计带备份与恢复的清理步骤。新增或修改数据库迁移也不通过普通在线更新包自动执行。首次安装包与后续增量包不要混用。

站点点击“安装此版本”后，由服务器后台任务下载、校验、备份、替换并做健康检查。服务器 `.env`、数据库、上传目录和运行数据不参与替换。若任务中断，查看 `runtime/update-jobs/<任务 ID>/status.json` 与 `worker.log`；确认执行器已停后，再按实际任务状态使用 `scripts/update-worker.php recover <任务 ID>`，不要在任务运行时手动恢复。

### 管理员认证安全迁移

1.1.0 认证修复包含 `database/migrations/20261011120000_harden_authentication.php`，将 `system_user.password` 扩至 255 位并给会员令牌建立单列索引。先备份 MySQL 5.7 数据库，将这一份迁移文件手动放到服务器站点根目录的 `database/migrations/`，在后端根目录执行 `/www/server/php/84/bin/php think migrate:run`，核对迁移成功及密码字段长度，再从统一更新中心在线安装 1.1.0。增量包虽然带有该迁移文件供核对，但现有安装器只在服务器上同名文件内容完全一致时跳过，不会写入或执行它。其余变化的代码文件由在线安装器安装；不要先手动覆盖登录控制器。

新版管理员登录直接通过 HTTPS 提交密码，由服务端验证旧 MD5，成功后升级为自带盐的哈希。若 EdgeOne 使用 HTTP 回源，EdgeOne 到源站之间仍可能传输明文密码；上线这批认证改动前应启用加密回源或等效的受保护通道。原有小程序会员令牌会失效，小程序下一次调用会员接口时会自动重新登录；新版令牌有效期 30 天，仅通过 `Member-Token` 请求头传递，数据库只保存摘要。部署后核对后台登录、改密、会员重新登录，以及验证码响应中不再有 `code` 字段。

## WordPress 插件

插件源码在 `wp-wechat-draft-sync/`。将该目录按 WordPress 插件目录结构打包后，在 WordPress 后台安装或替换；不要把插件 ZIP 混入教程后端更新包。插件的公众号草稿配置与“教程同步”配置相互独立。教程同步填写后端 HTTPS 地址、稳定站点标识、独立签名密钥和要同步的分类；密钥与后端服务器配置保持一致，不要写进 Git。

文章发布或正文刷新后先进入队列；逐篇状态出现教程文章 ID 才表示后端确认。若任务计划时间已过仍显示“等待发送”，检查 WordPress 的 WP-Cron、站点回环请求和服务器定时任务。当前插件源码 1.5.3 提供逐篇“立即发送 / 立即重试”按钮，可绕过延误的定时任务直接处理该条；它不能替代长期正常运行的自动调度。失败原因会显示在对应行。

## 原生微信小程序

用微信开发者工具打开 `miniprogram-native/`，后端 API 基址为 `https://jc.52okp.com`。确认微信公众平台的 request、uploadFile 和实际图片来源域名允许列表，先上传体验版并用真机核对首页、分类、文章 HTML 正文、会员资料、收藏、历史、分享及扫码确认；通过后再提交审核和发布。修改本地文件不会自动更新已发布的小程序。

### 多头图与分层人物

分层头图对应教程后端 `1.1.1`（从生产站点 `1.1.0` 升级），新增 `banner.character_image` 字段。新版首页接口直接查询这个字段，所以**先完成数据库迁移，再在后台安装 1.1.1**。线上更新器不会写入或执行数据库迁移。

1. 在宝塔「数据库」为当前教程后端使用的 MySQL 5.7 数据库创建备份，确认备份文件已生成；同时确认站点后台当前 `APP_VERSION` 为 `1.1.0`。
2. 把本项目的 [迁移文件](backend/database/migrations/20261012120000_add_banner_character_image.php) 上传到服务器**后端根目录**的 `database/migrations/20261012120000_add_banner_character_image.php`。后端根目录是同时含 `think`、`.env`、`app/` 的目录，不是 `public/`。可从 `v1.1.1` Release ZIP 中取出同一路径的文件；服务器上的文件内容必须与更新包一致，否则在线安装器会拒绝安装。
3. SSH 进入上述后端根目录，运行：

   ```sh
   /www/server/php/84/bin/php think migrate:run
   ```

4. 在宝塔数据库的 SQL 窗口执行 `SHOW COLUMNS FROM banner LIKE 'character_image';`，应返回一行、类型为 `varchar(500)`。若迁移失败或查不到字段，先排查数据库连接及迁移输出，不要安装新版代码。
5. 在 GitHub `52okp/jc52okp` 创建正式 `v1.1.1` Release，上传 `jc52okp-update.zip` 和 `update-manifest.json`；在 52okp 统一更新中心为项目 `jc52okp` 导入、校验 GitHub 来源并发布。然后回到教程后端「系统更新」检查并安装 `1.1.1`。安装器会核对迁移文件与包内文件相同，更新程序文件并自动将服务器 `.env` 的 `APP_VERSION` 改为 `1.1.1`。
6. 安装完成后核对首页接口正常返回头图、后台「首页头图」可保存人物 PNG；若程序更新回滚，保留新增字段，先排查更新任务，不要直接删除该字段或恢复过期数据库备份。

后台「首页头图」可以新增或编辑多条启用记录：原“图片”字段作为背景图，新“人物图”上传透明 PNG，排序值大的先展示；未上传人物图的旧记录继续使用小程序内置人物。小程序首页会自动轮播多条记录。图片地址所在域名仍须加入微信小程序图片来源域名允许列表。

## 发布核对

- 后端：配置和数据库保留，更新包的产品、起始版本、大小和 SHA-256 与正式 Release 一致。
- WordPress：核对插件版本、选中分类、单篇发送结果和教程文章 ID；公众号草稿功能单独回归。
- 小程序：核对图片来源、HTML 正文、会员登录和真实设备的页面布局。
- 本地：可运行 `backend/tests/`、`miniprogram-native/tests/` 和 `wp-wechat-draft-sync/tests/` 中与本次改动相关的脚本。

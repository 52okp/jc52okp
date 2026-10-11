# 部署与更新

更新日期：2026-10-11。本文针对当前教程后端、原生微信小程序和 WordPress 同步插件。历史一次性补丁及旧版本安装记录不再作为操作步骤。

## 教程后端

现有站点为 `jc.52okp.com`，服务器由用户确认使用 Debian 12、宝塔、PHP 8.4 与 MySQL 5.7。站点运行目录指向后端项目的 `public/`，Nginx 伪静态使用 [backend/宝塔伪静态.conf](backend/宝塔伪静态.conf)。EdgeOne 在访客侧提供 HTTPS；若采用 HTTP 回源，宝塔源站无须为这个配置另装证书。

生产数据库、微信小程序 AppSecret、WordPress 同步密钥和更新令牌存于服务器 `.env`，不纳入源码或发布 ZIP。配置项见 [backend/.env.example](backend/.env.example)。宝塔 PHP 8.4 CLI 的已核实路径是 `/www/server/php/84/bin/php`；后台安装更新若不能自动找到 CLI，可在服务器 `.env` 设置 `UPDATE_PHP_CLI` 为该路径。

新建独立站点时，可用 `backend/scripts/build-first-deploy.php` 生成首发包，上传到新站根目录并访问 `/install.php`。如果使用旧 SQL 迁移数据，先在宝塔把备份导入**新建的 MySQL 5.7 库**，再由网页安装器完成迁移。已经安装的站点不重新执行 `install.php`；安装完成后移除服务器上的安装入口与临时安装凭据。当前仓库不保存旧 SQL 备份或历史首发 ZIP。

## 教程后端在线更新

后端项目标识为 `jc52okp`。每次发布需要 GitHub [52okp/jc52okp](https://github.com/52okp/jc52okp) 的正式 `vX.Y.Z` Release 同时包含 `jc52okp-update.zip` 与 `update-manifest.json`。随后在 52okp 统一更新中心导入、校验并发布，站点后台才会检查到新版。清单的 `from` 必须等于站点当前 `APP_VERSION`；不要覆盖已发布版本的资产。

构建增量更新时，先把**紧邻上一版** Release 的两个资产下载到仓库外的临时目录。以从 1.0.7 升到 1.0.8 为例：

```powershell
gh release download v1.0.7 -R 52okp/jc52okp -p jc52okp-update.zip -p update-manifest.json -D D:/jc-release/base
php backend/scripts/build-update.php --project=jc52okp --version=1.0.8 --from=1.0.7 --notes-file=D:/jc-release/notes.txt --output=D:/jc-release/output --base-package=D:/jc-release/base/jc52okp-update.zip --base-manifest=D:/jc-release/base/update-manifest.json
```

先创建 `base/` 和 `output/`，并准备非空的 `notes.txt`。构建器会校验基线包和清单，只加入新增或哈希变化的文件；包内保留完整文件哈希清单，所以下一版可以继续以前一份增量包为基线。删除旧代码文件会被拒绝，须另行设计带备份与恢复的清理步骤。新增或修改数据库迁移也不通过普通在线更新包自动执行。首次安装包与后续增量包不要混用。

站点点击“安装此版本”后，由服务器后台任务下载、校验、备份、替换并做健康检查。服务器 `.env`、数据库、上传目录和运行数据不参与替换。若任务中断，查看 `runtime/update-jobs/<任务 ID>/status.json` 与 `worker.log`；确认执行器已停后，再按实际任务状态使用 `scripts/update-worker.php recover <任务 ID>`，不要在任务运行时手动恢复。

### 管理员认证安全迁移

认证修复包含 `database/migrations/20261011120000_harden_authentication.php`，将 `system_user.password` 扩至 255 位并给会员令牌建立单列索引。现有在线更新器会拒绝新的数据库迁移，因此这次须在备份数据库后人工发布：先把新迁移文件放到站点相应目录，在宝塔终端用站点 PHP CLI 于后端根目录执行 `/www/server/php/84/bin/php think migrate:run`，确认迁移成功后，再替换同批代码文件。不要先替换登录控制器，否则旧的 32 位密码字段无法保存新哈希。

新版管理员登录直接通过 HTTPS 提交密码，由服务端验证旧 MD5，成功后升级为自带盐的哈希。若 EdgeOne 使用 HTTP 回源，EdgeOne 到源站之间仍可能传输明文密码；上线这批认证改动前应启用加密回源或等效的受保护通道。原有小程序会员令牌会失效，小程序下一次调用会员接口时会自动重新登录；新版令牌有效期 30 天，仅通过 `Member-Token` 请求头传递，数据库只保存摘要。部署后核对后台登录、改密、会员重新登录，以及验证码响应中不再有 `code` 字段。

## WordPress 插件

插件源码在 `wp-wechat-draft-sync/`。将该目录按 WordPress 插件目录结构打包后，在 WordPress 后台安装或替换；不要把插件 ZIP 混入教程后端更新包。插件的公众号草稿配置与“教程同步”配置相互独立。教程同步填写后端 HTTPS 地址、稳定站点标识、独立签名密钥和要同步的分类；密钥与后端服务器配置保持一致，不要写进 Git。

文章发布或正文刷新后先进入队列；逐篇状态出现教程文章 ID 才表示后端确认。若任务计划时间已过仍显示“等待发送”，检查 WordPress 的 WP-Cron、站点回环请求和服务器定时任务。当前插件源码 1.5.3 提供逐篇“立即发送 / 立即重试”按钮，可绕过延误的定时任务直接处理该条；它不能替代长期正常运行的自动调度。失败原因会显示在对应行。

## 原生微信小程序

用微信开发者工具打开 `miniprogram-native/`，后端 API 基址为 `https://jc.52okp.com`。确认微信公众平台的 request、uploadFile 和实际图片来源域名允许列表，先上传体验版并用真机核对首页、分类、文章 HTML 正文、会员资料、收藏、历史、分享及扫码确认；通过后再提交审核和发布。修改本地文件不会自动更新已发布的小程序。

## 发布核对

- 后端：配置和数据库保留，更新包的产品、起始版本、大小和 SHA-256 与正式 Release 一致。
- WordPress：核对插件版本、选中分类、单篇发送结果和教程文章 ID；公众号草稿功能单独回归。
- 小程序：核对图片来源、HTML 正文、会员登录和真实设备的页面布局。
- 本地：可运行 `backend/tests/`、`miniprogram-native/tests/` 和 `wp-wechat-draft-sync/tests/` 中与本次改动相关的脚本。

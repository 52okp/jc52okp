# jc.52okp.com 首次部署操作单

更新日期：2026-10-09。目标是 Debian 12、宝塔、MySQL 5.7、PHP 8.5 和新站点 `jc.52okp.com`。站点尚未创建，下面的 `<SITE_ROOT>` 须在建站后替换为实际路径。首次发布使用 `install.php` 网页向导；52okp 更新中心尚无本项目身份，后台目前只能检查更新，不能在线安装。

## 1. 备份和准备新库

当前线上 MySQL 5.7 是旧数据来源，用户确认 2026-10-09 13:38 导出后没有新增数据。新站必须用**新建的空 MySQL 5.7 数据库**。通过宝塔将旧 SQL 导入这个新库，然后进入 `install.php`。不要把旧 SQL 导入正在运行的旧库，因为原转储含 `DROP TABLE`。旧库与旧站先保留以便回退。

先在宝塔备份旧库，并在宝塔新建空库和专用账号。把本机 `D:\downloads\ai_6008686_xyz_2026-10-09_13-38-47_mysql_data_1DeC7.sql.zip` 解压，得到 `.sql` 文件，然后通过宝塔的数据库导入功能将其导入**新库**。若切换前旧库又发生写入，以那时重新导出的最新 SQL 替代旧文件；此安装器要求导入库保留原项目 17 张表及 8 条旧迁移记录。SQL 备份文件不要放进网站目录。

在新库核对基线：文章 8（原可见 7）、分类 5、头图 4、会员 467、收藏 20、历史 330。文章 ID 应保持 1–8，ID 2 原本隐藏；若与最新备份不一致，先排查再迁移。详细验收见 [旧库迁移验收.md](旧库迁移验收.md)。不要将 SQL、数据库密码或本机 `backend/.env` 放到网站公开目录。

## 2. 建站和检查 PHP

`jc.52okp.com` 通过 EdgeOne 加速：访客侧在 EdgeOne 启用 HTTPS 和强制 HTTPS，EdgeOne 可以通过 HTTP 回源，宝塔源站无需本地证书。宝塔新建该站点，选择 PHP 8.5。新站代码目录记为 `<SITE_ROOT>`。网站运行目录设为 `<SITE_ROOT>/public`。Nginx 伪静态使用 [backend/宝塔伪静态.conf](backend/宝塔伪静态.conf)。若使用 Apache，项目自带 `public/.htaccess`。

`install.php` 会检查 PHP 版本、扩展、`vendor/` 和写入权限。`composer.lock` 的 PHP 基线是 8.2，本地只在 8.2 跑过；服务器的 PHP 8.5 必须通过新站及新库的迁移和接口回归。至少在宝塔 PHP 设置中开启 `pdo_mysql`、`mbstring`、`curl`、`dom`、`fileinfo`、`gd`、`zip`、`openssl`。

站点的 PHP 进程需要读取 `app/`、`config/`、`vendor/`，写入 `runtime/` 和实际上传目录。`open_basedir` 若开启，允许范围须覆盖整个 `<SITE_ROOT>`，无需关闭整台服务器的限制。

## 3. 上传代码并打开安装向导

本地首发包由 `backend/scripts/build-first-deploy.php` 生成，包含后端代码、`vendor/`、迁移、公共资源及 `public/install.php`，不含 `.env`、数据库、运行数据和上传数据。将包解压到 `<SITE_ROOT>`，确认 `<SITE_ROOT>/public/index.php`、`<SITE_ROOT>/public/install.php` 和 `<SITE_ROOT>/vendor/autoload.php` 存在。不要把整个 `backend/` 原样上传。

首次安装时使用的历史后端包是 `deploy-artifacts/backend-web-install-20261009-v4-edgeone.zip`，SHA-256 为 `3512aa280b272527d1731ea5d25970fd38b10e2871efbf92d7ce74c2e698adeb`。已安装站点的 WordPress 兼容更新见文末增量补丁，不要重新运行安装器。

第一次打开 `https://jc.52okp.com/install.php` 时，如果 EdgeOne 使用 HTTP 回源，安装器会在站点根目录生成 `.install-edgeone-key`，并提示配置回源请求头。在宝塔文件管理器读取该文件的值，**不要发在聊天里**；到 EdgeOne 规则引擎将匹配类型设为 `HOST = jc.52okp.com`，在“操作”中选择“修改 HTTP 回源请求头”，类型选“设置”、头部名称选“自定义”并填写 `X-52OKP-Install-Key`，头部值填该文件内容。EdgeOne 默认携带 `X-Forwarded-Proto`，安装器会同时核对访客协议、EdgeOne 标记及临时密钥。保存并发布规则后刷新安装页即可继续。

随后按页面操作：环境检测 → 填**已导入 SQL 的新库**地址、端口、库名、用户名、密码（可同时填小程序 AppSecret）→ 核对页面显示的文章、会员等数量 → 勾选确认 → 点击“确认并迁移”。安装器会自动创建服务器 `.env`、运行 3 项新增迁移并校验旧数据。小程序 AppID 已固定为 `wx4ec45155a93041cc`。AppSecret 不要发在聊天里或写入发布包。

成功后使用旧库原有管理员账号登录，并立即在宝塔删除 `<SITE_ROOT>/public/install.php` 和 `<SITE_ROOT>/.install-edgeone-key`，同时删除 EdgeOne 的临时回源请求头；保留根目录的 `install.lock`。安装器不会生成“admin/admin”账号，也不会插入示例文章。若安装中断，锁会阻止重复执行；按页面提示检查日志、从备份恢复**新库**后再重试。

新站使用新数据库，旧站继续写旧库。若公开发布时要完整继承后续用户行为，正式切换前需短暂停写旧站、再次导出最新数据并重建新库，随后重跑迁移。若旧站长期并行，两站的会员、收藏和历史会分叉；数据库不会自动同步。

## 4. 验收安装结果

网页安装器会创建 WordPress 来源表、后台更新菜单，将文章正文改为 `MEDIUMTEXT`，并给两篇旧文章的资源链接补 `https://`。MySQL DDL 不能按整批事务回滚；迁移前备份是恢复依据。

## 5. 验收新站和 WordPress

- `https://jc.52okp.com/api/article/list` 返回成功 JSON、7 篇可见旧文章；`/api/article/detail?id=5` 可读。
- `https://jc.52okp.com/admin/login.html` 使用原数据库已有管理员账号登录；不存在“admin/admin”默认账号步骤。
- 检查分类、封面、正文、会员登录、收藏、历史和 HTTPS 图片；查看 PHP-FPM 与应用日志，排除 PHP 8.5 兼容错误。

新站通过后将 `WP_SYNC_MAINTENANCE=0`。在 WordPress 安装或升级 `wp-wechat-draft-sync/` 插件前，备份原插件目录和 WordPress 数据库。在“52okp微信发布工具箱 → 教程同步”填写 `https://jc.52okp.com`、相同的站点标识与独立密钥、需要同步的分类，然后启用。先用一篇新文章验证创建、修改和撤回；原公众号草稿功能也需回归。已有 8 篇教程文章直接保留为本地基础文章，无需二次编辑。若 WordPress 中也有同一篇，先建立一对一来源映射再点“历史文章分批入队”，否则会产生重复文章。

## 6. 发布原生微信小程序

使用微信开发者工具打开 [miniprogram-native](miniprogram-native) 目录，本次不再编译 `frontend/`。代码已将教程 API 改为 `https://jc.52okp.com`；独立扫码登录仍请求 `https://open.52okp.com`。在微信公众平台为该 AppID 配置这两个 HTTPS `request` 合法域名，并将 `https://jc.52okp.com` 配到 `uploadFile` 合法域名。检查实际远程图片资源域名及平台规则。

先上传体验版并在真机测试首页、文章详情、图片、会员登录、头像上传、收藏、历史、分享、扫码确认；通过后提交审核和发布。旧版小程序仍指向 `https://ai.600868.xyz`，新站创建不会自动让旧版客户端改向。旧站先保持服务；日后要停旧站，先安排旧域名兼容转发和数据切换。

## 回退与更新边界

若新站验收失败，暂停新站与 WordPress 教程同步，旧站和旧库保持原样；修复新站或从迁移前备份重建新库。不要用旧 SQL 覆盖仍在运行的旧库。52okp 更新中心目前未为本项目注册独立项目，后台没有安装、备份、切换和恢复任务，首次部署及当前后续升级都需要人工处理。

## WordPress 编辑器与封面兼容补丁（2026-10-09）

已安装站点不要再次运行 `install.php`。本次增量文件是 `deploy-artifacts/backend-wordpress-compat-20261009.zip`（SHA-256：`733b8ae3c115d434c8030f1819e19b8771965a8c8d521172cb1f0fae503493f2`），ZIP 内路径相对于后端站点根目录。先备份站点代码，再在宝塔中覆盖对应的 7 个文件；不会覆盖 `.env`、数据库、上传或运行目录。随后安装 `deploy-artifacts/wp-wechat-draft-sync-1.5.0.zip`（SHA-256：`d75a2100e562c84ab1c18dc79ea2ef817015e60934907f90878853576b286145`），配置保留。最后在 WordPress“教程同步”点击“刷新 WordPress 正文（下一批）”，每次最多 50 篇；如果之前执行过该功能，先点“重新开始正文刷新”。逐篇核对教程文章 ID 和错误列。

后台新上传的本地封面也保留原图比例，不再强制裁成正方形。原生小程序源码 `miniprogram-native/` 的文章封面已改成完整比例展示；需要通过微信开发者工具重新上传小程序版本。真机访问外链图片前，核对微信公众平台允许的图片来源域名，至少检查实际使用的 `52okp.com` 和 `52okp.600867.xyz`。后端不会下载或重新托管 WordPress 图片。

# 52okp 教程项目

本仓库包含教程后端、微信小程序及 WordPress 教程同步插件的源码。

| 目录 | 内容 |
| --- | --- |
| `backend/` | ThinkPHP / ThinkAdmin 后端、网页安装器、迁移和更新包构建脚本 |
| `miniprogram-native/` | 当前原生微信小程序，教程 API 使用 `jc.52okp.com` |
| `wp-wechat-draft-sync/` | WordPress 微信发布与教程同步插件 |
| `frontend/` | 早期 uni-app 前端，保留源码供参考 |
| `legacy-miniprogram/` | 更早的小程序示例，保留源码供参考 |

## 首次部署

按 [DEPLOY.md](DEPLOY.md) 在 Debian 12、PHP 8.5、MySQL 5.7、宝塔环境安装。数据库先导入旧 SQL 备份，再通过 `backend/public/install.php` 完成迁移。站点运行目录设为 `backend/public`，Nginx 伪静态见 `backend/宝塔伪静态.conf`。生产 `.env` 由安装器创建，本仓库只提供不含真实密码的模板。

首次发布包可在 `backend/` 目录运行 `php scripts/build-first-deploy.php` 生成；后续更新包构建入口为 `php scripts/build-update.php`。构建产物和本地数据库不纳入源码仓库。

## 更新中心状态

后台更新页在当前页面检查版本；正式发布可用时，超级管理员可以启动后台代码安装任务，查看预检、备份、安装和健康检查状态。失败时恢复已替换文件；包含新增或修改数据库迁移的发布包会被拒绝，须另行备份数据库并人工迁移。旧站首次启用此功能须手工覆盖更新入口与执行器文件，见 [DEPLOY.md](DEPLOY.md)。服务器 `.env` 中的 `OSS_PROJECT`、`OSS_TOKEN` 和 `APP_VERSION` 不得提交到仓库。

## 开发检查

后端依赖由 `backend/composer.lock` 锁定，首次部署包使用仓库中的 `backend/vendor/`。项目测试脚本位于 `backend/tests/`、`miniprogram-native/tests/` 与 `frontend/tests/`。

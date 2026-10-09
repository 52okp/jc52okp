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

后台目前只有只读更新检查。52okp 更新中心尚未为本项目注册独立身份，在线安装、备份、切换和恢复流程尚未接入。注册时为本项目单独配置 `OSS_PROJECT`、`OSS_TOKEN` 和 `APP_VERSION`，切勿提交实际令牌或服务器 `.env`。

## 开发检查

后端依赖由 `backend/composer.lock` 锁定，首次部署包使用仓库中的 `backend/vendor/`。项目测试脚本位于 `backend/tests/`、`miniprogram-native/tests/` 与 `frontend/tests/`。

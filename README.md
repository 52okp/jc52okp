# 52okp AI 教程

本仓库保留当前使用的三部分源码：

| 目录 | 用途 |
| --- | --- |
| `backend/` | ThinkPHP / ThinkAdmin 教程 API、管理后台、网页安装器、MySQL 迁移和在线更新客户端 |
| `miniprogram-native/` | 原生微信小程序，使用 `https://jc.52okp.com` 教程 API |
| `wp-wechat-draft-sync/` | WordPress 微信发布工具箱及教程文章同步插件 |

部署与更新步骤见 [DEPLOY.md](DEPLOY.md)，当前功能、版本边界及待核实事项见 [项目总结.md](项目总结.md)。早期 uni-app、示例小程序、旧补丁包和预览产物已从当前工作目录清理。

## 开发入口

- 后端网站运行目录为 `backend/public/`，数据库使用 MySQL 5.7。依赖锁定在 `backend/composer.lock`，部署用依赖保存在 `backend/vendor/`。
- 使用微信开发者工具打开 `miniprogram-native/`。小程序配置和页面入口分别在 `project.config.json` 与 `app.json`。
- WordPress 插件入口为 `wp-wechat-draft-sync/wp-wechat-draft-sync.php`。公众号草稿和教程后端同步分别配置。
- `.env`、数据库、上传内容、运行缓存和 ZIP 发布包不纳入源码版本管理。

## 版本发布

教程后端的正式更新由 [52okp/jc52okp](https://github.com/52okp/jc52okp) 的 `vX.Y.Z` Release 提供 `jc52okp-update.zip` 与 `update-manifest.json`，再由 52okp 统一更新中心校验并发布。打包脚本 `backend/scripts/build-update.php` 可使用紧邻上一版 Release 的两个资产生成增量包，只收录新增或内容变化的文件。WordPress 插件和微信小程序分别发布。

# MySQL 5.7 目标环境

本项目目标数据库为 MySQL 5.7，后端未指定 `DB_TYPE` 时默认连接 MySQL；新增同步表使用 InnoDB、`utf8mb4_general_ci` 和普通整型/文本字段，不依赖 MySQL 8 的 JSON 或窗口函数。本地 `backend/.env` 已指向独立的 MySQL 5.7.44 服务（`127.0.0.1:3307/ai_tutorial`）；生产配置未修改。

本机安装位置：`C:\mysql57-ai-tutorial`；Windows 服务名 `AiTutorialMySQL57`，自动启动。应用账号与 root 的连接文件位于该目录下 ACL 受限的 `private/`，本文不记录密码。旧本地环境文件保存在 `private/backend-env-before-mysql.env`。不要将这些文件提交到仓库或放进更新包。

在**已备份且隔离的数据库**中先创建 UTF-8 数据库和单独账户，再用非公开环境文件设置 `DB_TYPE=mysql` 及 `DB_MYSQL_*`。运行 `php think migrate:status` 核对目标连接，确认不是生产库后才运行 `php think migrate:run`。这一步会运行项目所有待执行迁移及原有种子逻辑，不能直接对未知现有数据执行。

若现有正式数据来自 SQLite，需先盘点文章、分类、会员、收藏、历史、头图、系统配置、管理员和上传记录，制作保持主键的转移方案，在 MySQL 5.7 演练后比较数量、关联和样例内容。若正式库已是 MySQL，需在其备份副本中演练增量迁移。不要仅凭本地 `DB_TYPE=sqlite` 推断正式库类型。

可在源库与演练后的目标库分别运行 `php scripts/inventory-data.php`，比较各表行数、最大主键、收藏/历史缺失关联和重复组合。脚本只查询数据，不输出账号、密钥或文章内容；其 `mysql57_target` 仅核对服务器版本字符串以 `5.7.` 开头。新同步迁移将文章正文从旧 `TEXT` 扩为 `MEDIUMTEXT`，以容纳受 2 MiB 接收上限约束的 WordPress 正文。真实 5.7 首次演练曾发现复合主键列需要显式 `NOT NULL`，迁移现已修正并成功执行。

本地旧库已按《旧库迁移验收.md》导入并核对；未来生产切换仍需按当时源库做备份和增量核对。`backend/tests/wordpress-sync.php` 明确使用 SQLite 副本，`backend/tests/wordpress-sync-mysql57.php` 只允许命名为 `ai_tutorial_test_*` 的可丢弃 MySQL 5.7 克隆库。

当前依赖锁定的 PHP 运行基线至少 8.2；MySQL 5.7 指的是**数据库服务版本**，与 PHP 主版本分开核对。原有 `composer.json` 的宽松 PHP 声明未改，部署前应按 `composer.lock` 和实际 PHP CLI/FPM 校验平台依赖。

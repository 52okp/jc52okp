<?php
/**
 * Plugin Name: 52okp微信发布工具箱
 * Description: 按文章分类将 WordPress 内容自动排版并同步到一个或多个微信公众号草稿箱。
 * Version: 1.4.0
 * Author: 52OKP
 * License: AGPL-3.0-or-later
 * Text Domain: wp-wechat-draft-sync
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WWDS_VERSION', '1.4.0');
define('WWDS_FILE', __FILE__);

require_once __DIR__ . '/includes/class-wwds-plugin.php';
require_once __DIR__ . '/includes/class-wwds-tutorial-sync.php';

WWDS_Plugin::instance();
WWDS_Tutorial_Sync::instance();
register_activation_hook(__FILE__, array('WWDS_Tutorial_Sync', 'install'));

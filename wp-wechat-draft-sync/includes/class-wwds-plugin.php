<?php

if (!defined('ABSPATH')) {
    exit;
}

final class WWDS_Plugin
{
    const OPTION = 'wwds_settings';
    const CRON_HOOK = 'wwds_sync_post';
    const META_MEDIA_ID = '_wwds_media_id';
    const META_STATUS = '_wwds_status';
    const META_MESSAGE = '_wwds_message';
    const META_SYNCED_AT = '_wwds_synced_at';
    const META_ACCOUNT_MEDIA_IDS = '_wwds_account_media_ids';
    const META_ACCOUNT_STATUSES = '_wwds_account_statuses';

    private static $instance;

    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('save_post_post', array($this, 'queue_published_post'), 20, 3);
        add_action(self::CRON_HOOK, array($this, 'sync_post'));
        add_action('admin_post_wwds_sync_now', array($this, 'manual_sync'));
        add_action('add_meta_boxes_post', array($this, 'add_meta_box'));
        add_action('admin_notices', array($this, 'admin_notice'));
        add_filter('plugin_action_links_' . plugin_basename(WWDS_FILE), array($this, 'settings_link'));
    }

    public function settings_link($links)
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=wwds')) . '">控制台</a>');
        return $links;
    }

    public function admin_menu()
    {
        add_menu_page('52okp微信发布工具箱', '微信发布工具箱', 'manage_options', 'wwds', array($this, 'settings_page'), 'dashicons-megaphone', 58);
    }

    public function admin_assets($hook)
    {
        if ($hook !== 'toplevel_page_wwds' && strpos($hook, '_page_wwds-tutorial') === false) {
            return;
        }
        wp_enqueue_style('wwds-admin', plugins_url('assets/admin.css', WWDS_FILE), array(), WWDS_VERSION);
        wp_enqueue_script('wwds-admin', plugins_url('assets/admin.js', WWDS_FILE), array(), WWDS_VERSION, true);
    }

    public function register_settings()
    {
        register_setting('wwds_group', self::OPTION, array($this, 'sanitize_settings'));
    }

    public function sanitize_settings($input)
    {
        $old = $this->settings();
        $old_accounts = array();
        foreach ($old['accounts'] as $account) {
            $old_accounts[$account['id']] = $account;
        }
        $accounts = array();
        if (!empty($input['accounts']) && is_array($input['accounts'])) {
            foreach ($input['accounts'] as $row_id => $row) {
                $id = sanitize_key(isset($row['id']) ? $row['id'] : $row_id);
                if (!$id) {
                    $id = 'account_' . wp_generate_password(10, false, false);
                }
                $appid = sanitize_text_field(isset($row['appid']) ? $row['appid'] : '');
                $name = sanitize_text_field(isset($row['name']) ? $row['name'] : '');
                $secret = !empty($row['secret'])
                    ? sanitize_text_field($row['secret'])
                    : (isset($old_accounts[$id]['secret']) ? $old_accounts[$id]['secret'] : '');
                if ($appid === '' && $name === '' && $secret === '') {
                    continue;
                }
                $categories = array();
                if (!empty($row['categories']) && is_array($row['categories'])) {
                    $categories = array_values(array_unique(array_filter(array_map('absint', $row['categories']))));
                }
                $accounts[] = array(
                    'id' => $id,
                    'name' => $name ?: ('公众号 ' . (count($accounts) + 1)),
                    'appid' => $appid,
                    'secret' => $secret,
                    'author' => sanitize_text_field(isset($row['author']) ? $row['author'] : ''),
                    'categories' => $categories,
                );
            }
        }
        return array(
            'auto_sync' => empty($input['auto_sync']) ? 0 : 1,
            'accounts' => $accounts,
        );
    }

    private function settings()
    {
        $raw = get_option(self::OPTION, array());
        $settings = wp_parse_args($raw, array(
            'auto_sync' => 1,
            'accounts' => array(),
        ));
        // Read the pre-1.2 single-account format without modifying the option.
        if (empty($settings['accounts']) && !empty($raw['appid'])) {
            $settings['accounts'][] = array(
                'id' => 'legacy_' . substr(md5($raw['appid']), 0, 12),
                'name' => '原公众号',
                'appid' => $raw['appid'],
                'secret' => isset($raw['secret']) ? $raw['secret'] : '',
                'author' => isset($raw['author']) ? $raw['author'] : '',
                'categories' => array(),
            );
        }
        return $settings;
    }

    public function settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = $this->settings();
        $account_count = count($settings['accounts']);
        $ready_count = 0;
        $routed_categories = array();
        foreach ($settings['accounts'] as $account) {
            if (!empty($account['appid']) && !empty($account['secret'])) {
                $ready_count++;
            }
            $routed_categories = array_merge($routed_categories, array_map('intval', $account['categories']));
        }
        $routed_categories = array_unique(array_filter($routed_categories));
        ?>
        <div class="wwds-console">
            <header class="wwds-topbar">
                <div class="wwds-brand"><span class="wwds-logo">微</span><strong>52okp微信发布工具箱</strong><span class="wwds-version">v<?php echo esc_html(WWDS_VERSION); ?></span><span class="wwds-divider"></span><span>发布配置</span></div>
                <a class="wwds-doc-link" href="https://52okp.com" target="_blank" rel="noopener noreferrer">访问 52okp ↗</a>
            </header>
            <main class="wwds-main">
                <section class="wwds-heading">
                    <h1>发布控制台</h1>
                    <p>管理公众号连接、分类路由和自动同步策略。</p>
                </section>

                <section class="wwds-overview">
                    <div class="wwds-overview-main">
                        <div class="wwds-section-title"><span class="wwds-title-icon">▣</span><div><h2>同步环境概览</h2><p>文章将使用摸鱼绿主题写入匹配公众号的草稿箱。</p></div></div>
                        <div class="wwds-stats">
                            <div class="wwds-stat"><span>公众号数量</span><strong><?php echo esc_html($account_count); ?></strong><small>已配置账号</small></div>
                            <div class="wwds-stat"><span>连接就绪</span><strong><?php echo esc_html($ready_count); ?></strong><small>AppID 与密钥完整</small></div>
                            <div class="wwds-stat"><span>路由分类</span><strong><?php echo esc_html(count($routed_categories)); ?></strong><small>已分配分类</small></div>
                            <div class="wwds-stat"><span>自动同步</span><strong><?php echo $settings['auto_sync'] ? '已开启' : '已关闭'; ?></strong><small>按文章分类执行</small></div>
                        </div>
                        <div class="wwds-ready <?php echo $ready_count ? 'is-ready' : ''; ?>"><span><?php echo $ready_count ? '✓' : '!'; ?></span><div><strong><?php echo $ready_count ? '发布环境已配置' : '等待配置公众号'; ?></strong><p><?php echo $ready_count ? '已具备同步条件，请继续检查每个账号的分类路由。' : '添加公众号并填写 AppID、AppSecret 后即可开始。'; ?></p></div></div>
                    </div>
                    <aside class="wwds-overview-side">
                        <div><span class="wwds-side-label">同步模式</span><strong>分类路由</strong><p>一篇文章可以同时进入多个匹配账号。</p></div>
                        <div><span class="wwds-side-label">模板样式</span><strong>摸鱼绿</strong><p>静态微信兼容排版，不生成无效交互。</p></div>
                        <div class="wwds-warning"><strong>未选择分类</strong><p>对应公众号不会自动同步，但仍可在文章页手动发送。</p></div>
                    </aside>
                </section>

                <form method="post" action="options.php" class="wwds-settings-form">
                <?php settings_fields('wwds_group'); ?>
                    <div class="wwds-capability-head"><div><h2>公众号与分类路由</h2><p>每个公众号独立配置密钥、默认作者及允许自动同步的文章分类。</p></div><button type="button" class="button button-primary" id="wwds-add-account">＋ 添加公众号</button></div>
                    <div class="wwds-switch-row"><div><strong>按分类自动同步</strong><p>发布或更新文章时，自动发送到分类规则匹配的公众号草稿箱。</p></div><label class="wwds-switch"><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[auto_sync]" value="1" <?php checked($settings['auto_sync'], 1); ?>><span></span></label></div>
                    <div id="wwds-accounts" class="wwds-account-grid">
                    <?php foreach ($settings['accounts'] as $index => $account) { $this->account_fields($account, (string) $index); } ?>
                    </div>
                    <div class="wwds-form-footer"><p><span>ⓘ</span> AppSecret 留空表示保留原值；保存后不会回显密钥。</p><?php submit_button('保存全部配置', 'primary', 'submit', false); ?></div>
                </form>
                <section class="wwds-notice-card"><strong>个人未认证订阅号提示</strong><p>微信可能不开放草稿箱或素材接口。返回错误码 48001 表示当前公众号没有对应 API 权限；这不会影响其他账号继续同步。</p></section>
            </main>
            <script type="text/html" id="tmpl-wwds-account"><?php $this->account_fields(array('id' => '__ID__', 'name' => '', 'appid' => '', 'secret' => '', 'author' => '', 'categories' => array()), '__INDEX__'); ?></script>
        </div>
        <?php
    }

    private function account_fields($account, $index)
    {
        $base = self::OPTION . '[accounts][' . $index . ']';
        $categories = get_categories(array('hide_empty' => false));
        ?>
        <article class="wwds-account">
            <div class="wwds-account-head"><div class="wwds-account-avatar">公</div><div><h3><?php echo esc_html($account['name'] ?: '新公众号'); ?></h3><span><?php echo $account['appid'] && $account['secret'] ? '● 配置完整' : '○ 待完善'; ?></span></div><button type="button" class="wwds-remove-account" aria-label="删除公众号">×</button></div>
            <input type="hidden" name="<?php echo esc_attr($base); ?>[id]" value="<?php echo esc_attr($account['id']); ?>">
            <div class="wwds-field-grid">
                <label><span>账号名称</span><input type="text" name="<?php echo esc_attr($base); ?>[name]" value="<?php echo esc_attr($account['name']); ?>" placeholder="例如：技术公众号"></label>
                <label><span>默认作者</span><input type="text" name="<?php echo esc_attr($base); ?>[author]" value="<?php echo esc_attr($account['author']); ?>" placeholder="留空使用文章作者"></label>
                <label class="is-wide"><span>AppID</span><input type="text" name="<?php echo esc_attr($base); ?>[appid]" value="<?php echo esc_attr($account['appid']); ?>" autocomplete="off" placeholder="wx..."></label>
                <label class="is-wide"><span>AppSecret</span><input type="password" name="<?php echo esc_attr($base); ?>[secret]" value="" autocomplete="new-password" placeholder="<?php echo $account['secret'] ? '已安全保存，留空不修改' : '请输入 AppSecret'; ?>"></label>
            </div>
            <div class="wwds-category-head"><div><strong>自动同步分类</strong><p>至少匹配一项才会自动同步</p></div><span><?php echo esc_html(count($account['categories'])); ?> 项</span></div>
            <div class="wwds-category-list">
                <?php foreach ($categories as $category) : ?><label><input type="checkbox" name="<?php echo esc_attr($base); ?>[categories][]" value="<?php echo esc_attr($category->term_id); ?>" <?php checked(in_array((int) $category->term_id, array_map('intval', $account['categories']), true)); ?>><span><?php echo esc_html($category->name); ?></span></label><?php endforeach; ?>
            </div>
        </article>
        <?php
    }

    public function queue_published_post($post_id, $post, $update)
    {
        $settings = $this->settings();
        if (!$settings['auto_sync'] || $post->post_status !== 'publish' || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        // Scheduled posts and AI publishers also save posts inside WP-Cron.
        // They must enqueue synchronization just like an editor publish.
        // Gutenberg may save taxonomy terms after save_post. Evaluate category
        // routing in the delayed cron callback, when the final terms exist.
        $cleared = wp_clear_scheduled_hook(self::CRON_HOOK, array($post_id), true);
        $scheduled = is_wp_error($cleared) || $cleared === false
            ? $cleared
            : wp_schedule_single_event(time() + 5, self::CRON_HOOK, array($post_id), true);
        if (is_wp_error($scheduled) || !$scheduled) {
            $detail = is_wp_error($scheduled) ? $scheduled->get_error_message() : '无法保存定时任务。';
            update_post_meta($post_id, self::META_STATUS, 'error');
            update_post_meta($post_id, self::META_MESSAGE, '自动同步排队失败：' . $detail);
            return;
        }
        update_post_meta($post_id, self::META_STATUS, 'queued');
        update_post_meta($post_id, self::META_MESSAGE, '已排队，等待同步。');
    }

    public function add_meta_box()
    {
        add_meta_box('wwds-status', '公众号草稿', array($this, 'meta_box'), 'post', 'side', 'high');
    }

    public function meta_box($post)
    {
        $status = get_post_meta($post->ID, self::META_STATUS, true);
        $message = get_post_meta($post->ID, self::META_MESSAGE, true);
        $synced = get_post_meta($post->ID, self::META_SYNCED_AT, true);
        $labels = array('queued' => '等待同步', 'syncing' => '同步中', 'success' => '同步成功', 'partial' => '部分成功', 'error' => '同步失败', 'skipped' => '未匹配分类');
        echo '<p><strong>状态：</strong>' . esc_html(isset($labels[$status]) ? $labels[$status] : '尚未同步') . '</p>';
        if ($synced) {
            echo '<p><strong>时间：</strong>' . esc_html($synced) . '</p>';
        }
        if ($message) {
            echo '<p style="word-break:break-word">' . esc_html($message) . '</p>';
        }
        $account_statuses = get_post_meta($post->ID, self::META_ACCOUNT_STATUSES, true);
        if (is_array($account_statuses) && $account_statuses) {
            echo '<hr><p><strong>各公众号：</strong></p>';
            foreach ($account_statuses as $item) {
                $ok = isset($item['status']) && $item['status'] === 'success';
                echo '<p style="word-break:break-word;margin:8px 0"><strong>' . esc_html(isset($item['name']) ? $item['name'] : '公众号') . '：</strong><span style="color:' . ($ok ? '#008a20' : '#b32d2e') . '">' . ($ok ? '成功' : '失败') . '</span>';
                if (!empty($item['message'])) {
                    echo '<br><small>' . esc_html($item['message']) . '</small>';
                }
                echo '</p>';
            }
        }
        if ($post->post_status === 'publish') {
            $url = wp_nonce_url(admin_url('admin-post.php?action=wwds_sync_now&post_id=' . $post->ID), 'wwds_sync_' . $post->ID);
            echo '<p><a class="button button-secondary" href="' . esc_url($url) . '">同步到全部公众号 / 重试</a></p>';
            echo '<p class="description">手动同步不受分类规则限制。</p>';
        }
    }

    public function manual_sync()
    {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_die('无权执行此操作。');
        }
        check_admin_referer('wwds_sync_' . $post_id);
        $result = $this->sync_post($post_id, true);
        $result_key = is_wp_error($result) ? 'error' : 'success';
        wp_safe_redirect(add_query_arg('wwds_result', $result_key, get_edit_post_link($post_id, 'url')));
        exit;
    }

    public function admin_notice()
    {
        if (empty($_GET['wwds_result'])) {
            return;
        }
        $success = $_GET['wwds_result'] === 'success';
        echo '<div class="notice notice-' . ($success ? 'success' : 'error') . ' is-dismissible"><p>' . ($success ? '已同步到微信公众号草稿箱。' : '同步失败，请查看“公众号草稿”状态框中的错误信息。') . '</p></div>';
    }

    public function sync_post($post_id, $manual = false)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish') {
            return new WP_Error('invalid_post', '文章不存在或尚未发布。');
        }

        $accounts = $this->eligible_accounts($post_id, (bool) $manual);
        if (!$accounts) {
            $message = $manual ? '没有已完整配置的公众号。' : '文章分类未匹配任何公众号规则。';
            update_post_meta($post_id, self::META_STATUS, 'skipped');
            update_post_meta($post_id, self::META_MESSAGE, $message);
            return new WP_Error('wwds_no_account', $message);
        }

        update_post_meta($post_id, self::META_STATUS, 'syncing');
        update_post_meta($post_id, self::META_MESSAGE, '正在向 ' . count($accounts) . ' 个公众号上传图片并创建草稿。');
        $statuses = array();
        $success_count = 0;
        foreach ($accounts as $account) {
            try {
                $media_id = $this->sync_post_to_account($post, $account);
                $statuses[$account['id']] = array('name' => $account['name'], 'status' => 'success', 'message' => '草稿已创建或更新。Media ID：' . $media_id, 'time' => current_time('mysql'));
                $success_count++;
            } catch (Exception $e) {
                $statuses[$account['id']] = array('name' => $account['name'], 'status' => 'error', 'message' => $e->getMessage(), 'time' => current_time('mysql'));
            }
            update_post_meta($post_id, self::META_ACCOUNT_STATUSES, $statuses);
        }
        $total = count($accounts);
        $failed = $total - $success_count;
        $status = $success_count === $total ? 'success' : ($success_count > 0 ? 'partial' : 'error');
        $summary = '共处理 ' . $total . ' 个公众号：成功 ' . $success_count . ' 个，失败 ' . $failed . ' 个。';
        update_post_meta($post_id, self::META_STATUS, $status);
        update_post_meta($post_id, self::META_MESSAGE, $summary);
        update_post_meta($post_id, self::META_SYNCED_AT, current_time('mysql'));
        return $failed ? new WP_Error('wwds_partial_failure', $summary) : true;
    }

    private function eligible_accounts($post_id, $manual)
    {
        $settings = $this->settings();
        $post_categories = array_map('intval', wp_get_post_categories($post_id));
        $result = array();
        foreach ($settings['accounts'] as $account) {
            if (empty($account['appid']) || empty($account['secret'])) {
                continue;
            }
            $categories = !empty($account['categories']) ? array_map('intval', $account['categories']) : array();
            if ($manual || ($categories && array_intersect($post_categories, $categories))) {
                $result[] = $account;
            }
        }
        return $result;
    }

    private function sync_post_to_account($post, $account)
    {
        $token = $this->access_token($account);
        $content = $this->prepare_content($post, $token);
        $cover_url = get_the_post_thumbnail_url($post->ID, 'full');
        if (!$cover_url) {
            $cover_url = $this->first_image_url($post->post_content);
        }
        if (!$cover_url) {
            throw new Exception('没有找到封面图。请设置特色图片或在正文中加入图片。');
        }
        $thumb_media_id = $this->upload_file($cover_url, $token, true);
        $author = $account['author'] ?: get_the_author_meta('display_name', $post->post_author);
        $article = array(
            'title' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
            'author' => $author,
            'digest' => $this->digest($post),
            'content' => $content,
            'content_source_url' => get_permalink($post),
            'thumb_media_id' => $thumb_media_id,
            'need_open_comment' => 0,
            'only_fans_can_comment' => 0,
        );
        $media_ids = get_post_meta($post->ID, self::META_ACCOUNT_MEDIA_IDS, true);
        $media_ids = is_array($media_ids) ? $media_ids : array();
        if (empty($media_ids) && ($legacy_media_id = get_post_meta($post->ID, self::META_MEDIA_ID, true))) {
            $configured = $this->settings()['accounts'];
            if (!empty($configured[0]['id']) && $configured[0]['id'] === $account['id']) {
                $media_ids[$account['id']] = $legacy_media_id;
            }
        }
        $media_id = isset($media_ids[$account['id']]) ? $media_ids[$account['id']] : '';
        if ($media_id) {
            $this->wechat_json('https://api.weixin.qq.com/cgi-bin/draft/update?access_token=' . rawurlencode($token), array('media_id' => $media_id, 'index' => 0, 'articles' => $article));
        } else {
            $response = $this->wechat_json('https://api.weixin.qq.com/cgi-bin/draft/add?access_token=' . rawurlencode($token), array('articles' => array($article)));
            if (!empty($response['media_id'])) {
                $media_id = sanitize_text_field($response['media_id']);
                $media_ids[$account['id']] = $media_id;
                update_post_meta($post->ID, self::META_ACCOUNT_MEDIA_IDS, $media_ids);
            }
        }
        return $media_id;
    }

    private function access_token($account)
    {
        if (empty($account['appid']) || empty($account['secret'])) {
            throw new Exception('公众号配置不完整，请填写 AppID 和 AppSecret。');
        }
        $cache_key = 'wwds_token_' . md5($account['appid']);
        $token = get_transient($cache_key);
        if ($token) {
            return $token;
        }
        $url = add_query_arg(array('grant_type' => 'client_credential', 'appid' => $account['appid'], 'secret' => $account['secret']), 'https://api.weixin.qq.com/cgi-bin/token');
        $data = $this->wechat_request($url, array('method' => 'GET'));
        if (empty($data['access_token'])) {
            throw new Exception($this->wechat_error($data));
        }
        set_transient($cache_key, $data['access_token'], max(60, intval($data['expires_in']) - 300));
        return $data['access_token'];
    }

    private function prepare_content($post, $token)
    {
        $html = apply_filters('the_content', $post->post_content);
        if (!class_exists('DOMDocument')) {
            throw new Exception('服务器缺少 PHP DOM 扩展，无法处理正文图片。');
        }
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><section id="wwds-root">' . $html . '</section>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        foreach ($dom->getElementsByTagName('img') as $image) {
            $src = $image->getAttribute('src');
            if (!$src || strpos($src, 'mmbiz.qpic.cn') !== false) {
                continue;
            }
            $image->setAttribute('src', $this->upload_file($this->absolute_url($src), $token, false));
            $image->removeAttribute('srcset');
            $image->removeAttribute('sizes');
        }
        $root = $dom->getElementById('wwds-root');
        $this->apply_moyu_green_theme($dom, $root);
        $root->removeAttribute('id');
        return $dom->saveHTML($root);
    }

    /**
     * Deterministic WordPress-to-WeChat renderer based on the modified
     * 52okp/gzh-design-skill Moyu Green component library. Interactive TOC,
     * fake buttons and English subtitles are intentionally not generated.
     */
    private function apply_moyu_green_theme($dom, $root)
    {
        $root->setAttribute('style', "max-width:677px;margin:0 auto;background:#ffffff;font-family:-apple-system,BlinkMacSystemFont,'PingFang SC','Hiragino Sans GB','Microsoft YaHei',sans-serif;color:#374151;line-height:1.75;letter-spacing:0.5px;overflow-x:hidden;");

        foreach ($this->nodes($root, 'p') as $p) {
            $p->setAttribute('style', 'margin:0 0 16px;font-size:14px;line-height:1.9;text-align:justify;color:#374151;');
        }
        foreach ($this->nodes($root, 'strong') as $strong) {
            $strong->setAttribute('style', 'color:#059669;font-weight:700;');
        }
        foreach ($this->nodes($root, 'em') as $em) {
            $em->setAttribute('style', 'font-style:normal;border-bottom:2px solid #A7F3D0;font-weight:600;');
        }
        foreach ($this->nodes($root, 'del') as $del) {
            $del->setAttribute('style', 'background:#F3F4F6;color:#6B7280;padding:2px 6px;border-radius:4px;font-size:13px;text-decoration:line-through;font-weight:600;');
        }
        foreach ($this->nodes($root, 'code') as $code) {
            if (!$code->parentNode || strtolower($code->parentNode->nodeName) !== 'pre') {
                $code->setAttribute('style', "background:#F1F5F9;color:#059669;padding:1px 6px;border-radius:4px;font-family:'SF Mono',Consolas,Monaco,monospace;font-size:14px;");
            }
        }

        $chapter = 0;
        foreach (array_merge($this->nodes($root, 'h1'), $this->nodes($root, 'h2')) as $heading) {
            $chapter++;
            $wrap = $this->element($dom, 'section', 'margin-top:' . ($chapter === 1 ? '16' : '48') . 'px;margin-bottom:32px;padding:0 20px;');
            $row = $this->element($dom, 'section', 'display:flex;align-items:center;gap:16px;margin-bottom:24px;');
            $number_box = $this->element($dom, 'section', 'text-align:center;flex-shrink:0;');
            $number_box->appendChild($this->leaf_element($dom, 'p', str_pad((string) $chapter, 2, '0', STR_PAD_LEFT), 'margin:0;font-size:28px;font-weight:900;color:#059669;line-height:1;letter-spacing:-2px;'));
            $number_box->appendChild($this->leaf_element($dom, 'p', 'PART', 'margin:0;font-size:8px;font-weight:700;color:#D1D5DB;letter-spacing:2px;'));
            $divider = $this->element($dom, 'span', 'width:1px;height:36px;background:#E5E7EB;flex-shrink:0;');
            $divider->appendChild($this->leaf_element($dom, 'span', "\xc2\xa0", ''));
            $title_box = $this->element($dom, 'section', '');
            $title_box->appendChild($this->leaf_element($dom, 'p', trim($heading->textContent), 'margin:0;font-size:17px;font-weight:900;color:#111827;letter-spacing:0.3px;'));
            $row->appendChild($number_box);
            $row->appendChild($divider);
            $row->appendChild($title_box);
            $wrap->appendChild($row);
            $heading->parentNode->replaceChild($wrap, $heading);
        }

        foreach ($this->nodes($root, 'h3') as $heading) {
            $replacement = $this->element($dom, 'p', 'font-size:15px;font-weight:900;color:#111827;margin:32px 0 16px;');
            $highlight = $this->element($dom, 'span', 'background:linear-gradient(180deg,transparent 65%,#FDE68A 65%);padding:0 4px;');
            $highlight->appendChild($this->leaf_element($dom, 'span', trim($heading->textContent), ''));
            $replacement->appendChild($highlight);
            $heading->parentNode->replaceChild($replacement, $heading);
        }
        foreach (array_merge($this->nodes($root, 'h4'), $this->nodes($root, 'h5'), $this->nodes($root, 'h6')) as $heading) {
            $replacement = $this->leaf_element($dom, 'p', trim($heading->textContent), 'font-size:14px;font-weight:800;color:#059669;margin:24px 0 12px;border-left:3px solid #059669;padding-left:10px;');
            $heading->parentNode->replaceChild($replacement, $heading);
        }

        foreach ($this->nodes($root, 'blockquote') as $quote) {
            $quote->setAttribute('style', 'background:#F9FAFB;border:1px dashed #D1D5DB;border-radius:8px;padding:12px 16px;margin:0 0 24px;text-align:justify;color:#374151;font-size:13px;line-height:1.6;');
        }
        $this->replace_lists($dom, $root);

        foreach ($this->nodes($root, 'pre') as $pre) {
            $this->replace_code_block($dom, $pre);
        }

        foreach ($this->nodes($root, 'table') as $table) {
            $table->setAttribute('style', 'width:100%;border-collapse:collapse;font-size:13px;margin:0 0 24px;');
            foreach ($this->nodes($table, 'th') as $cell) {
                $cell->setAttribute('style', 'background:#059669;color:#fff;font-weight:700;padding:8px 12px;text-align:left;border:1px solid #E5E7EB;');
            }
            foreach ($this->nodes($table, 'td') as $cell) {
                $cell->setAttribute('style', 'padding:8px 12px;border:1px solid #E5E7EB;color:#374151;');
            }
        }

        foreach ($this->nodes($root, 'img') as $image) {
            $image->setAttribute('style', 'max-width:100%;height:auto;display:block;margin:0 auto;');
            $parent = $image->parentNode;
            if ($parent && in_array(strtolower($parent->nodeName), array('p', 'figure'), true)) {
                $parent->setAttribute('style', 'display:block;background:#FFF;border-radius:12px;padding:6px;border:1px solid #E5E7EB;box-shadow:0 4px 12px -2px rgba(0,0,0,0.08);margin:0 0 10px;text-align:center;overflow:hidden;');
            }
        }

        // Fragment links are unreliable in WeChat articles. Keep their label
        // as ordinary text instead of presenting a button that cannot work.
        foreach ($this->nodes($root, 'a') as $link) {
            $href = trim($link->getAttribute('href'));
            if ($href === '' || strpos($href, '#') === 0) {
                $link->removeAttribute('href');
                $link->setAttribute('style', 'color:#059669;font-weight:600;text-decoration:none;');
            } else {
                $link->setAttribute('style', 'color:#059669;text-decoration:underline;word-break:break-all;');
            }
        }
        foreach ($this->nodes($root, 'span') as $span) {
            $span->removeAttribute('leaf');
        }
    }

    private function replace_lists($dom, $root)
    {
        foreach (array('ul', 'ol') as $tag) {
            foreach ($this->nodes($root, $tag) as $list) {
                if (!$list->parentNode) {
                    continue;
                }
                $ordered = $tag === 'ol';
                $container = $this->element(
                    $dom,
                    'section',
                    $ordered
                        ? 'margin:0 0 24px;'
                        : 'margin:0 0 24px;padding:14px 16px;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;'
                );
                $items = array();
                foreach ($list->childNodes as $child) {
                    if ($child instanceof DOMElement && strtolower($child->nodeName) === 'li') {
                        $items[] = $child;
                    }
                }
                foreach ($items as $index => $item) {
                    $row = $this->element($dom, 'section', 'display:flex;align-items:flex-start;margin:0 0 12px;');
                    if ($ordered) {
                        $marker = $this->leaf_element($dom, 'span', (string) ($index + 1), 'display:inline-block;min-width:22px;height:22px;line-height:22px;text-align:center;background:#059669;color:#fff;font-size:11px;font-weight:700;border-radius:50%;margin:3px 10px 0 0;');
                    } else {
                        $marker = $this->leaf_element($dom, 'span', '•', 'display:inline-block;width:18px;color:#059669;font-size:18px;font-weight:900;line-height:1.7;margin-right:6px;');
                    }
                    $text = $this->element($dom, 'p', 'font-size:14px;color:#374151;margin:0;line-height:1.9;flex:1;text-align:justify;');
                    while ($item->firstChild) {
                        $text->appendChild($item->firstChild);
                    }
                    $row->appendChild($marker);
                    $row->appendChild($text);
                    $container->appendChild($row);
                }
                $list->parentNode->replaceChild($container, $list);
            }
        }
    }

    private function replace_code_block($dom, $pre)
    {
        $language = 'CODE';
        $code_nodes = $pre->getElementsByTagName('code');
        if ($code_nodes->length) {
            $class = $code_nodes->item(0)->getAttribute('class');
            if (preg_match('/language-([a-z0-9_+-]+)/i', $class, $match)) {
                $language = strtoupper($match[1]);
            }
        }
        $outer = $this->element($dom, 'section', 'margin:0 0 20px;border-radius:8px;overflow:hidden;background:#1E293B;box-shadow:0 4px 16px -8px rgba(15,23,42,0.4);');
        $bar = $this->element($dom, 'section', 'display:flex;align-items:center;padding:9px 14px;background:#0F172A;');
        foreach (array(array('#FF5F56', '7px'), array('#FFBD2E', '7px'), array('#27C93F', '0')) as $dot) {
            $bar->appendChild($this->leaf_element($dom, 'span', '.', 'display:inline-block;width:10px;height:10px;border-radius:50%;background:' . $dot[0] . ';margin-right:' . $dot[1] . ';font-size:0;line-height:0;overflow:hidden;'));
        }
        $bar->appendChild($this->leaf_element($dom, 'span', $language, 'margin-left:12px;font-size:12px;color:#64748B;font-family:Consolas,Monaco,monospace;letter-spacing:1px;'));
        $body = $this->element($dom, 'section', 'padding:11px 14px;');
        $lines = preg_split('/\r\n|\r|\n/', rtrim($pre->textContent, "\r\n"));
        foreach ($lines as $line) {
            $line = str_replace("\t", '　　', $line);
            $line = preg_replace_callback('/^ +/', function ($match) {
                return str_repeat('　', strlen($match[0]));
            }, $line);
            $body->appendChild($this->leaf_element($dom, 'p', $line === '' ? "\xc2\xa0" : $line, "margin:0;font-family:'SF Mono',Consolas,Monaco,monospace;font-size:13px;line-height:1.6;color:#E2E8F0;word-break:break-word;"));
        }
        $outer->appendChild($bar);
        $outer->appendChild($body);
        $pre->parentNode->replaceChild($outer, $pre);
    }

    private function nodes($root, $tag)
    {
        $result = array();
        foreach ($root->getElementsByTagName($tag) as $node) {
            $result[] = $node;
        }
        return $result;
    }

    private function element($dom, $name, $style)
    {
        $element = $dom->createElement($name);
        if ($style !== '') {
            $element->setAttribute('style', $style);
        }
        return $element;
    }

    private function leaf_element($dom, $name, $text, $style)
    {
        $element = $this->element($dom, $name, $style);
        $leaf = $dom->createElement('span');
        $leaf->appendChild($dom->createTextNode($text));
        $element->appendChild($leaf);
        return $element;
    }

    private function upload_file($url, $token, $permanent)
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = download_url($url, 30);
        if (is_wp_error($tmp)) {
            throw new Exception('下载图片失败：' . $tmp->get_error_message());
        }
        $upload_path = $tmp;
        $converted_path = '';
        $endpoint = $permanent
            ? 'https://api.weixin.qq.com/cgi-bin/material/add_material?type=image&access_token=' . rawurlencode($token)
            : 'https://api.weixin.qq.com/cgi-bin/media/uploadimg?access_token=' . rawurlencode($token);
        try {
            $mime = function_exists('wp_get_image_mime') ? wp_get_image_mime($tmp) : '';
            if (!$mime && function_exists('getimagesize')) {
                $image_info = @getimagesize($tmp);
                $mime = !empty($image_info['mime']) ? $image_info['mime'] : '';
            }

            $allowed = $permanent
                ? array('image/jpeg', 'image/png', 'image/gif', 'image/bmp')
                : array('image/jpeg', 'image/png');

            if (!in_array($mime, $allowed, true)) {
                $editor = wp_get_image_editor($tmp);
                if (is_wp_error($editor)) {
                    throw new Exception('图片格式为 ' . ($mime ?: '未知格式') . '，微信不支持，且服务器无法转换为 JPEG：' . $editor->get_error_message());
                }
                $converted_path = wp_tempnam('wwds-converted.jpg');
                $saved = $editor->save($converted_path, 'image/jpeg');
                if (is_wp_error($saved) || empty($saved['path'])) {
                    $reason = is_wp_error($saved) ? $saved->get_error_message() : '转换结果为空';
                    throw new Exception('无法把图片转换为微信支持的 JPEG：' . $reason);
                }
                $upload_path = $saved['path'];
                $mime = 'image/jpeg';
            }

            $extensions = array(
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/bmp' => 'bmp',
            );
            $filename = 'wechat-image.' . (isset($extensions[$mime]) ? $extensions[$mime] : 'jpg');
            $data = $this->multipart_request($endpoint, $upload_path, $filename, $mime ?: 'image/jpeg');
        } finally {
            @unlink($tmp);
            if ($converted_path && $converted_path !== $tmp) {
                @unlink($converted_path);
            }
        }
        $key = $permanent ? 'media_id' : 'url';
        if (empty($data[$key])) {
            throw new Exception($this->wechat_error($data));
        }
        return $data[$key];
    }

    private function multipart_request($url, $path, $filename, $mime)
    {
        $boundary = '----WWDS' . wp_generate_password(24, false, false);
        $body = '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="media"; filename="' . str_replace('"', '', $filename) . '"' . "\r\n";
        $body .= 'Content-Type: ' . $mime . "\r\n\r\n";
        $body .= file_get_contents($path) . "\r\n--" . $boundary . "--\r\n";
        return $this->wechat_request($url, array(
            'method' => 'POST',
            'headers' => array('Content-Type' => 'multipart/form-data; boundary=' . $boundary),
            'body' => $body,
            'timeout' => 60,
        ));
    }

    private function wechat_json($url, $payload)
    {
        $data = $this->wechat_request($url, array(
            'method' => 'POST',
            'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
            'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timeout' => 60,
        ));
        if (!empty($data['errcode'])) {
            throw new Exception($this->wechat_error($data));
        }
        return $data;
    }

    private function wechat_request($url, $args)
    {
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new Exception('连接微信服务器失败：' . $response->get_error_message());
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            throw new Exception('微信服务器返回了无法识别的响应（HTTP ' . wp_remote_retrieve_response_code($response) . '）。');
        }
        if (!empty($data['errcode'])) {
            throw new Exception($this->wechat_error($data));
        }
        return $data;
    }

    private function wechat_error($data)
    {
        $code = isset($data['errcode']) ? intval($data['errcode']) : 0;
        $message = isset($data['errmsg']) ? $data['errmsg'] : '未知错误';
        if ($code === 48001) {
            $message .= '；当前公众号没有此接口权限，个人未认证订阅号可能无法使用草稿箱 API。';
        } elseif ($code === 40164) {
            $message .= '；请把 WordPress 服务器公网 IP 加入公众号 IP 白名单。';
        }
        return '微信 API 错误 ' . $code . '：' . $message;
    }

    private function digest($post)
    {
        $text = $post->post_excerpt ?: wp_strip_all_tags(strip_shortcodes($post->post_content));
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        return function_exists('mb_substr') ? mb_substr($text, 0, 120) : substr($text, 0, 120);
    }

    private function first_image_url($content)
    {
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $match)) {
            return $this->absolute_url($match[1]);
        }
        return '';
    }

    private function absolute_url($url)
    {
        if (strpos($url, '//') === 0) {
            return (is_ssl() ? 'https:' : 'http:') . $url;
        }
        if (!preg_match('#^https?://#i', $url)) {
            return home_url('/' . ltrim($url, '/'));
        }
        return $url;
    }
}

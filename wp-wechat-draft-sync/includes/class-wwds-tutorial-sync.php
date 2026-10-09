<?php

if (!defined('ABSPATH')) exit;

/** Separate queue and configuration; the existing WeChat draft pipeline is untouched. */
final class WWDS_Tutorial_Sync
{
    const OPTION = 'wwds_tutorial_settings';
    const VERSION_OPTION = 'wwds_tutorial_schema';
    const REVISION_META = '_wwds_tutorial_revision';
    const CRON = 'wwds_tutorial_process';
    const CAPTURE = 'wwds_tutorial_capture';
    private static $instance;

    public static function instance()
    {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    public static function install()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'wwds_tutorial_queue';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            event_id char(32) NOT NULL,
            revision bigint(20) unsigned NOT NULL,
            action varchar(20) NOT NULL,
            payload longtext NOT NULL,
            state varchar(20) NOT NULL,
            attempts int(11) NOT NULL DEFAULT 0,
            next_at bigint(20) unsigned NOT NULL DEFAULT 0,
            last_error text NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_id (event_id),
            KEY pending (state,next_at),
            KEY post_id (post_id)
        ) $charset;");
        update_option(self::VERSION_OPTION, '1', false);
    }

    private function __construct()
    {
        add_action('init', array($this, 'maybe_install'));
        add_action('wp_after_insert_post', array($this, 'after_save'), 20, 4);
        add_action('save_post_post', array($this, 'save_fields'), 15, 3);
        add_action('before_delete_post', array($this, 'before_delete'), 10, 2);
        add_action('set_object_terms', array($this, 'terms_changed'), 10, 6);
        add_action('added_post_meta', array($this, 'meta_changed'), 10, 4);
        add_action('updated_post_meta', array($this, 'meta_changed'), 10, 4);
        add_action('deleted_post_meta', array($this, 'meta_changed'), 10, 4);
        add_action(self::CAPTURE, array($this, 'capture'));
        add_action(self::CRON, array($this, 'process'));
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_wwds_tutorial_retry', array($this, 'retry'));
        add_action('admin_post_wwds_tutorial_import', array($this, 'import_batch'));
        add_action('add_meta_boxes_post', array($this, 'add_meta_box'));
    }

    public function maybe_install()
    {
        if (get_option(self::VERSION_OPTION) !== '1') self::install();
    }

    private function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'wwds_tutorial_queue';
    }

    private function settings()
    {
        return wp_parse_args(get_option(self::OPTION, array()), array(
            'enabled' => 0, 'endpoint' => '', 'site_id' => '', 'secret' => '', 'categories' => array(),
        ));
    }

    public function register_settings()
    {
        register_setting('wwds_tutorial_group', self::OPTION, array($this, 'sanitize_settings'));
    }

    public function sanitize_settings($input)
    {
        $old = $this->settings();
        $endpoint = esc_url_raw(trim((string)($input['endpoint'] ?? '')));
        $parts = wp_parse_url($endpoint);
        if ($endpoint !== '' && (!wp_http_validate_url($endpoint) || !$parts ||
            ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
            (!empty($parts['path']) && $parts['path'] !== '/') || !empty($parts['query']) ||
            !empty($parts['fragment']) || !empty($parts['user']) || !empty($parts['pass']))) {
            add_settings_error(self::OPTION, 'endpoint', '请填写无路径的 HTTPS 后端地址。');
            $endpoint = $old['endpoint'];
        }
        $site = sanitize_key($input['site_id'] ?? '');
        if ($site !== '' && !preg_match('/^[a-z][a-z0-9_-]{1,79}$/', $site)) {
            add_settings_error(self::OPTION, 'site_id', '站点标识需以小写字母开头，长度 2–80。');
            $site = $old['site_id'];
        }
        $secret = empty($input['secret']) ? $old['secret'] : sanitize_text_field($input['secret']);
        if ($secret !== '' && strlen($secret) < 32) {
            add_settings_error(self::OPTION, 'secret', '签名密钥至少 32 个字符。');
            $secret = $old['secret'];
        }
        return array(
            'enabled' => empty($input['enabled']) ? 0 : 1,
            'endpoint' => untrailingslashit($endpoint),
            'site_id' => $site,
            'secret' => $secret,
            'categories' => array_values(array_unique(array_filter(array_map('absint', (array)($input['categories'] ?? array()))))),
        );
    }

    public function after_save($post_id, $post, $update, $post_before)
    {
        if ($post->post_type === 'post' && !wp_is_post_revision($post_id) && !wp_is_post_autosave($post_id)) {
            $this->enqueue($post_id, $post);
        }
    }

    public function before_delete($post_id, $post)
    {
        if ($post->post_type === 'post') $this->enqueue($post_id, $post, true);
    }

    public function terms_changed($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids)
    {
        if ($taxonomy === 'category' && get_post_type($object_id) === 'post') $this->schedule_capture((int)$object_id);
    }

    public function meta_changed($meta_id, $post_id, $meta_key, $meta_value)
    {
        if (in_array($meta_key, array('_wwds_tutorial_links', '_wwds_tutorial_unzip_code'), true) && get_post_type($post_id) === 'post') {
            $this->schedule_capture((int)$post_id);
        }
    }

    private function schedule_capture($post_id)
    {
        if (!wp_next_scheduled(self::CAPTURE, array($post_id))) {
            wp_schedule_single_event(time() + 5, self::CAPTURE, array($post_id));
        }
    }

    public function capture($post_id)
    {
        $post = get_post($post_id);
        if ($post && $post->post_type === 'post') $this->enqueue($post_id, $post);
    }

    private function enqueue($post_id, $post, $force_unpublish = false)
    {
        global $wpdb;
        $settings = $this->settings();
        if (!$settings['enabled'] || !$settings['endpoint'] || !$settings['site_id'] || strlen($settings['secret']) < 32) return;
        $categories = array_map('intval', wp_get_post_categories($post_id));
        $public = !$force_unpublish && $post->post_status === 'publish' && $post->post_password === '' &&
            !empty($settings['categories']) && (bool)array_intersect($categories, $settings['categories']);
        $revision = (int)get_post_meta($post_id, self::REVISION_META, true) + 1;
        update_post_meta($post_id, self::REVISION_META, $revision);
        $action = $public ? 'upsert' : 'unpublish';
        $article = null;
        if ($public) {
            $terms = get_the_category($post_id);
            $category_data = array();
            foreach ($terms as $term) $category_data[] = array('id' => (int)$term->term_id, 'name' => $term->name);
            usort($category_data, function ($a, $b) { return $a['id'] <=> $b['id']; });
            $tags = wp_get_post_tags($post_id, array('fields' => 'names'));
            $article = array(
                'title' => html_entity_decode(get_the_title($post_id), ENT_QUOTES, 'UTF-8'),
                'summary' => $post->post_excerpt ?: wp_trim_words(wp_strip_all_tags($post->post_content), 50),
                'content' => $post->post_content,
                'cover' => get_the_post_thumbnail_url($post_id, 'full') ?: '',
                'categories' => $category_data,
                'tags' => is_array($tags) ? $tags : array(),
                'source_url' => get_permalink($post_id),
            );
            // Optional tutorial metadata is deliberately separate from _wwds_* draft state.
            $links = get_post_meta($post_id, '_wwds_tutorial_links', true);
            if (is_array($links)) $article['links'] = $links;
            if (metadata_exists('post', $post_id, '_wwds_tutorial_unzip_code')) {
                $article['unzip_code'] = (string)get_post_meta($post_id, '_wwds_tutorial_unzip_code', true);
            }
        }
        $event = array(
            'schema_version' => 1, 'site_id' => $settings['site_id'], 'event_id' => bin2hex(random_bytes(16)),
            'post_id' => (int)$post_id, 'source_revision' => $revision, 'action' => $action,
            'content_hash' => hash('sha256', wp_json_encode(array($action, $article))),
        );
        if ($public) $event['article'] = $article;
        $wpdb->insert($this->table(), array(
            'post_id' => $post_id, 'event_id' => $event['event_id'], 'revision' => $revision,
            'action' => $action, 'payload' => wp_json_encode($event),
            'state' => 'queued', 'attempts' => 0, 'next_at' => time(), 'last_error' => '',
        ));
        $this->schedule();
    }

    private function schedule()
    {
        if (!wp_next_scheduled(self::CRON)) wp_schedule_single_event(time() + 10, self::CRON);
    }

    public function process()
    {
        global $wpdb;
        $table = $this->table();
        $now = time();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE state IN ('queued','sending') AND next_at <= %d ORDER BY id ASC LIMIT 10", $now
        ));
        foreach ($rows as $row) {
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET state='sending', next_at=%d WHERE id=%d AND state=%s AND next_at<=%d",
                $now + 600, $row->id, $row->state, $now
            ));
            if ($claimed !== 1) continue;
            $error = $this->send($row->payload);
            if ($error === '') {
                $wpdb->update($table, array('state' => 'success', 'last_error' => ''), array('id' => $row->id));
            } else {
                $attempts = (int)$row->attempts + 1;
                $delay = min(86400, 60 * (2 ** min($attempts, 10)));
                $wpdb->update($table, array('state' => 'queued', 'attempts' => $attempts,
                    'next_at' => time() + $delay, 'last_error' => wp_html_excerpt($error, 1000)), array('id' => $row->id));
            }
        }
        $next = $wpdb->get_var("SELECT MIN(next_at) FROM $table WHERE state IN ('queued','sending')");
        if ($next) wp_schedule_single_event(max(time() + 10, (int)$next), self::CRON);
    }

    private function send($body)
    {
        $settings = $this->settings();
        if (!$settings['enabled'] || !$settings['endpoint'] || !$settings['secret']) return '教程同步未配置';
        $path = '/api/wordpress_sync/events';
        $time = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = "POST\n$path\n$time\n$nonce\n" . hash('sha256', $body);
        $response = wp_remote_post($settings['endpoint'] . $path, array(
            'timeout' => 20, 'redirection' => 0, 'sslverify' => true,
            'headers' => array(
                'Content-Type' => 'application/json', 'X-WP-Sync-Site' => $settings['site_id'],
                'X-WP-Sync-Time' => $time, 'X-WP-Sync-Nonce' => $nonce,
                'X-WP-Sync-Signature' => hash_hmac('sha256', $canonical, $settings['secret']),
            ), 'body' => $body,
        ));
        if (is_wp_error($response)) return $response->get_error_message();
        $status = wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if ($status === 200 && is_array($decoded) && ($decoded['code'] ?? null) === 1) return '';
        return 'HTTP ' . $status . ': ' . sanitize_text_field($decoded['msg'] ?? '无有效成功响应');
    }

    public function menu()
    {
        add_submenu_page('wwds', '教程同步', '教程同步', 'manage_options', 'wwds-tutorial', array($this, 'page'));
    }

    public function page()
    {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $settings = $this->settings();
        $rows = $wpdb->get_results("SELECT id,post_id,revision,state,attempts,next_at,last_error FROM {$this->table()} ORDER BY id DESC LIMIT 30");
        $categories = get_categories(array('hide_empty' => false));
        echo '<div class="wrap"><h1>教程后端同步</h1><form method="post" action="options.php">';
        settings_fields('wwds_tutorial_group');
        echo '<p><label><input type="checkbox" name="' . self::OPTION . '[enabled]" value="1" ' . checked($settings['enabled'], 1, false) . '>启用教程同步</label></p>';
        echo '<p>后端 HTTPS 地址 <input class="regular-text" name="' . self::OPTION . '[endpoint]" value="' . esc_attr($settings['endpoint']) . '" placeholder="https://example.com"></p>';
        echo '<p>稳定站点标识 <input name="' . self::OPTION . '[site_id]" value="' . esc_attr($settings['site_id']) . '"></p>';
        echo '<p>独立签名密钥 <input type="password" name="' . self::OPTION . '[secret]" value="" autocomplete="new-password" placeholder="' . ($settings['secret'] ? '已设置，留空保留' : '至少 32 字符') . '"></p>';
        echo '<p>同步分类：</p><p>';
        foreach ($categories as $category) echo '<label style="margin-right:16px"><input type="checkbox" name="' . self::OPTION . '[categories][]" value="' . (int)$category->term_id . '" ' . checked(in_array((int)$category->term_id, $settings['categories'], true), true, false) . '>' . esc_html($category->name) . '</label>';
        echo '</p>';
        submit_button('保存教程同步配置');
        echo '</form><hr><h2>历史文章分批入队</h2><p>每次最多 50 篇，仅追加事件，不会因为某页失败而下架其他文章。</p>';
        echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=wwds_tutorial_import'), 'wwds_tutorial_import')) . '">入队下一批</a>';
        echo '<h2>最近任务</h2><table class="widefat striped"><thead><tr><th>文章</th><th>版本</th><th>状态</th><th>尝试</th><th>下次</th><th>错误</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $retry = wp_nonce_url(admin_url('admin-post.php?action=wwds_tutorial_retry&id=' . $row->id), 'wwds_tutorial_retry_' . $row->id);
            echo '<tr><td>' . (int)$row->post_id . '</td><td>' . (int)$row->revision . '</td><td>' . esc_html($row->state) . '</td><td>' . (int)$row->attempts . '</td><td>' . esc_html(wp_date('Y-m-d H:i', (int)$row->next_at)) . '</td><td>' . esc_html($row->last_error) . '</td><td><a href="' . esc_url($retry) . '">重试</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function add_meta_box()
    {
        add_meta_box('wwds-tutorial', '教程后端同步', array($this, 'meta_box'), 'post', 'side');
    }

    public function meta_box($post)
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE post_id=%d ORDER BY id DESC LIMIT 1", $post->ID));
        echo $row ? '<p>状态：' . esc_html($row->state) . '，版本：' . (int)$row->revision . '</p><p>' . esc_html($row->last_error) . '</p>' : '<p>尚未入队</p>';
        wp_nonce_field('wwds_tutorial_fields_' . $post->ID, 'wwds_tutorial_nonce');
        $links = get_post_meta($post->ID, '_wwds_tutorial_links', true);
        $lines = array();
        foreach (is_array($links) ? $links : array() as $link) {
            $lines[] = implode(' | ', array($link['type'] ?? '', $link['url'] ?? '', $link['code'] ?? ''));
        }
        echo '<p><label for="wwds_tutorial_links"><strong>教程资料链接</strong></label><textarea id="wwds_tutorial_links" name="wwds_tutorial_links" rows="4" style="width:100%" placeholder="类型 | HTTPS 地址 | 提取码">' . esc_textarea(implode("\n", $lines)) . '</textarea><small>每行一条，留空表示清空。与公众号草稿资料互不影响。</small></p>';
        echo '<p><label for="wwds_tutorial_unzip"><strong>解压密码</strong></label><input id="wwds_tutorial_unzip" name="wwds_tutorial_unzip" style="width:100%" value="' . esc_attr(get_post_meta($post->ID, '_wwds_tutorial_unzip_code', true)) . '"></p>';
    }

    public function save_fields($post_id, $post, $update)
    {
        if (empty($_POST['wwds_tutorial_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wwds_tutorial_nonce'])), 'wwds_tutorial_fields_' . $post_id) ||
            !current_user_can('edit_post', $post_id) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        $links = array();
        foreach (explode("\n", (string)wp_unslash($_POST['wwds_tutorial_links'] ?? '')) as $line) {
            if (trim($line) === '') continue;
            $parts = array_map('trim', explode('|', $line, 3));
            $url = esc_url_raw($parts[1] ?? '');
            if (strpos($url, 'https://') !== 0) continue;
            $links[] = array(
                'type' => sanitize_text_field($parts[0] ?? ''),
                'url' => $url, 'code' => sanitize_text_field($parts[2] ?? ''),
            );
        }
        update_post_meta($post_id, '_wwds_tutorial_links', $links);
        update_post_meta($post_id, '_wwds_tutorial_unzip_code', sanitize_text_field(wp_unslash($_POST['wwds_tutorial_unzip'] ?? '')));
    }

    public function retry()
    {
        if (!current_user_can('manage_options')) wp_die('无权操作');
        $id = absint($_GET['id'] ?? 0);
        check_admin_referer('wwds_tutorial_retry_' . $id);
        global $wpdb;
        $wpdb->update($this->table(), array('state' => 'queued', 'next_at' => time()), array('id' => $id));
        $this->schedule();
        wp_safe_redirect(admin_url('admin.php?page=wwds-tutorial'));
        exit;
    }

    public function import_batch()
    {
        if (!current_user_can('manage_options')) wp_die('无权操作');
        check_admin_referer('wwds_tutorial_import');
        global $wpdb;
        $cursor = (int)get_option('wwds_tutorial_import_cursor', 0);
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' AND ID>%d ORDER BY ID ASC LIMIT 50", $cursor));
        foreach ($ids as $id) {
            $post = get_post((int)$id);
            if ($post) $this->enqueue((int)$id, $post);
            $cursor = (int)$id;
        }
        update_option('wwds_tutorial_import_cursor', $cursor, false);
        wp_safe_redirect(admin_url('admin.php?page=wwds-tutorial'));
        exit;
    }
}

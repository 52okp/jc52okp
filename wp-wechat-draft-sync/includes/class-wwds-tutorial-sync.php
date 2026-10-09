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
    const IMPORT_CURSOR = 'wwds_tutorial_selected_import_cursor_v2';
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
            result varchar(20) NOT NULL DEFAULT '',
            remote_article_id bigint(20) unsigned NOT NULL DEFAULT 0,
            synced_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY event_id (event_id),
            KEY pending (state,next_at),
            KEY post_id (post_id)
        ) $charset;");
        update_option(self::VERSION_OPTION, '2', false);
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
        add_action('admin_post_wwds_tutorial_process', array($this, 'process_now'));
        add_action('admin_post_wwds_tutorial_reset_import', array($this, 'reset_import'));
        add_action('add_meta_boxes_post', array($this, 'add_meta_box'));
    }

    public function maybe_install()
    {
        if (get_option(self::VERSION_OPTION) !== '2') self::install();
    }

    private function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'wwds_tutorial_queue';
    }

    private function settings()
    {
        $host = strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST));
        $legacy_overlap = in_array($host, array('52okp.com', 'www.52okp.com'), true) ? array(484) : array();
        return wp_parse_args(get_option(self::OPTION, array()), array(
            'enabled' => 0, 'endpoint' => '', 'site_id' => '', 'secret' => '',
            'categories' => array(), 'excluded_post_ids' => $legacy_overlap,
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
            'excluded_post_ids' => array_values(array_unique(array_filter(array_map(
                'absint', preg_split('/[\s,，]+/u', (string)($input['excluded_post_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)
            )))),
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
        if (!$settings['enabled'] || !$settings['endpoint'] || !$settings['site_id'] || strlen($settings['secret']) < 32) return false;
        if (in_array((int)$post_id, array_map('intval', (array)$settings['excluded_post_ids']), true)) return false;
        $categories = array_map('intval', wp_get_post_categories($post_id));
        $public = !$force_unpublish && $post->post_status === 'publish' && $post->post_password === '' &&
            !empty($settings['categories']) && (bool)array_intersect($categories, $settings['categories']);
        if (!$public) {
            // Only retract an article that this plugin previously tried to publish.
            // Unrelated historical posts must not become successful "sync" tasks.
            $latest = $wpdb->get_var($wpdb->prepare(
                "SELECT action FROM {$this->table()} WHERE post_id=%d ORDER BY id DESC LIMIT 1", $post_id
            ));
            if ($latest !== 'upsert') return false;
        }
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
        $inserted = $wpdb->insert($this->table(), array(
            'post_id' => $post_id, 'event_id' => $event['event_id'], 'revision' => $revision,
            'action' => $action, 'payload' => wp_json_encode($event),
            'state' => 'queued', 'attempts' => 0, 'next_at' => time(), 'last_error' => '',
        ));
        if ($inserted) $this->schedule();
        return (bool)$inserted;
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
        $excluded = array_map('intval', (array)$this->settings()['excluded_post_ids']);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE state IN ('queued','sending') AND next_at <= %d ORDER BY id ASC LIMIT 10", $now
        ));
        foreach ($rows as $row) {
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET state='sending', next_at=%d WHERE id=%d AND state=%s AND next_at<=%d",
                $now + 600, $row->id, $row->state, $now
            ));
            if ($claimed !== 1) continue;
            if ($row->action === 'upsert' && in_array((int)$row->post_id, $excluded, true)) {
                $wpdb->update($table, array('state' => 'excluded', 'result' => 'excluded',
                    'last_error' => '', 'synced_at' => 0), array('id' => $row->id));
                continue;
            }
            $delivery = $this->send($row->payload, $row->action);
            if ($delivery['error'] === '') {
                $wpdb->update($table, array(
                    'state' => $delivery['result'] === 'stale' ? 'stale' : 'success',
                    'result' => $delivery['result'],
                    'remote_article_id' => $delivery['article_id'],
                    'synced_at' => time(), 'last_error' => '',
                ), array('id' => $row->id));
            } else {
                $attempts = (int)$row->attempts + 1;
                $delay = min(86400, 60 * (2 ** min($attempts, 10)));
                $wpdb->update($table, array('state' => 'queued', 'attempts' => $attempts,
                    'next_at' => time() + $delay, 'last_error' => wp_html_excerpt($delivery['error'], 1000)), array('id' => $row->id));
            }
        }
        $next = $wpdb->get_var("SELECT MIN(next_at) FROM $table WHERE state IN ('queued','sending')");
        if ($next) wp_schedule_single_event(max(time() + 10, (int)$next), self::CRON);
    }

    private function send($body, $action)
    {
        $settings = $this->settings();
        if (!$settings['enabled'] || !$settings['endpoint'] || !$settings['secret']) return array('error' => '教程同步未配置');
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
        if (is_wp_error($response)) return array('error' => $response->get_error_message());
        $status = wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if ($status === 200 && is_array($decoded) && ($decoded['code'] ?? null) === 1 && is_array($decoded['data'] ?? null)) {
            $result = (string)($decoded['data']['result'] ?? '');
            $article_id = absint($decoded['data']['article_id'] ?? 0);
            if (in_array($result, array('applied', 'unchanged', 'stale'), true) &&
                ($action !== 'upsert' || $result === 'stale' || $article_id > 0)) {
                return array('error' => '', 'result' => $result, 'article_id' => $article_id);
            }
            return array('error' => '后端已响应，但没有确认教程文章 ID 或处理结果');
        }
        return array('error' => 'HTTP ' . $status . ': ' . sanitize_text_field($decoded['msg'] ?? '无有效成功响应'));
    }

    public function menu()
    {
        add_submenu_page('wwds', '教程同步', '教程同步', 'manage_options', 'wwds-tutorial', array($this, 'page'));
    }

    private function selected_posts($settings, $cursor = 0, $limit = 0)
    {
        global $wpdb;
        $terms = array_values(array_unique(array_filter(array_map('absint', (array)$settings['categories']))));
        if (!$terms) return array();
        $placeholders = implode(',', array_fill(0, count($terms), '%d'));
        $sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id=p.ID
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
            WHERE p.post_type='post' AND p.post_status='publish' AND p.post_password=''
            AND p.ID>%d AND tt.taxonomy='category' AND tt.term_id IN ($placeholders)
            ORDER BY p.ID ASC";
        $values = array_merge(array((int)$cursor), $terms);
        if ($limit > 0) {
            $sql .= ' LIMIT %d';
            $values[] = (int)$limit;
        }
        return array_map('intval', $wpdb->get_col($wpdb->prepare($sql, $values)));
    }

    private function latest_tasks($post_ids)
    {
        global $wpdb;
        $latest = array();
        foreach (array_chunk($post_ids, 100) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $table = $this->table();
            $sql = "SELECT id,post_id,revision,action,state,attempts,next_at,last_error,result,remote_article_id,synced_at
                FROM $table WHERE id IN (SELECT MAX(id) FROM $table WHERE post_id IN ($placeholders) GROUP BY post_id)";
            foreach ($wpdb->get_results($wpdb->prepare($sql, $chunk)) as $row) {
                $latest[(int)$row->post_id] = $row;
            }
        }
        return $latest;
    }

    private function task_status($row, $excluded = false)
    {
        if ($excluded) return array('已排除', 'muted');
        if (!$row) return array('未入队', 'muted');
        if ($row->action !== 'upsert') return array('未同步：下架事件', 'warning');
        if ($row->state === 'stale') return array('旧版本已忽略', 'warning');
        if ($row->state === 'success') {
            return (int)$row->remote_article_id > 0
                ? array('已同步到教程后台', 'success')
                : array('已发送，旧记录未核实', 'warning');
        }
        if ($row->state === 'sending') return array('发送中', 'pending');
        if ($row->state === 'queued') return $row->attempts ? array('重试中', 'warning') : array('等待发送', 'pending');
        return array('同步失败', 'error');
    }

    public function page()
    {
        if (!current_user_can('manage_options')) return;
        $settings = $this->settings();
        $categories = get_categories(array('hide_empty' => false));
        $post_ids = $this->selected_posts($settings);
        $excluded = array_map('intval', (array)$settings['excluded_post_ids']);
        $tasks = $this->latest_tasks($post_ids);
        $cursor = (int)get_option(self::IMPORT_CURSOR, 0);
        $counts = array('success' => 0, 'pending' => 0, 'attention' => 0, 'excluded' => 0);
        foreach ($post_ids as $id) {
            $status = $this->task_status($tasks[$id] ?? null, in_array($id, $excluded, true));
            if ($status[1] === 'success') $counts['success']++;
            elseif ($status[1] === 'pending') $counts['pending']++;
            elseif ($status[0] === '已排除') $counts['excluded']++;
            else $counts['attention']++;
        }
        $scanned = count(array_filter($post_ids, function ($id) use ($cursor) { return $id <= $cursor; }));
        $ready = !empty($settings['enabled']) && $settings['endpoint'] && $settings['site_id'] && strlen($settings['secret']) >= 32 && $settings['categories'];
        ?>
        <div class="wwds-console wwds-tutorial">
            <header class="wwds-topbar"><div class="wwds-brand"><span class="wwds-logo">教</span><strong>52okp微信发布工具箱</strong><span class="wwds-version">v<?php echo esc_html(WWDS_VERSION); ?></span><span class="wwds-divider"></span><span>教程同步</span></div><a class="wwds-doc-link" href="<?php echo esc_url(admin_url('admin.php?page=wwds')); ?>">返回发布控制台 ↗</a></header>
            <main class="wwds-main">
                <section class="wwds-heading"><h1>教程同步控制台</h1><p>查看选中分类的文章是否在教程后台创建成功。</p></section>
                <section class="wwds-overview"><div class="wwds-overview-main">
                    <div class="wwds-section-title"><span class="wwds-title-icon">▣</span><div><h2>同步状态概览</h2><p>“已同步”需要后端确认教程文章 ID；旧版成功记录会标为待核实。</p></div></div>
                    <div class="wwds-stats">
                        <div class="wwds-stat"><span>所选分类文章</span><strong><?php echo esc_html(count($post_ids)); ?></strong><small>已发布且无密码</small></div>
                        <div class="wwds-stat"><span>确认已同步</span><strong><?php echo esc_html($counts['success']); ?></strong><small>后端返回文章 ID</small></div>
                        <div class="wwds-stat"><span>等待或发送中</span><strong><?php echo esc_html($counts['pending']); ?></strong><small>尚未确认到达</small></div>
                        <div class="wwds-stat"><span>需处理</span><strong><?php echo esc_html($counts['attention']); ?></strong><small>未入队、重试或旧记录</small></div>
                    </div>
                    <div class="wwds-ready <?php echo $ready ? 'is-ready' : ''; ?>"><span><?php echo $ready ? '✓' : '!'; ?></span><div><strong><?php echo $ready ? '连接配置完整' : '请完善教程同步配置'; ?></strong><p><?php echo $ready ? '配置完整不代表文章已经同步，请以逐篇状态和后端文章 ID 为准。' : '启用同步并填写后端地址、站点标识、签名密钥和分类。'; ?></p></div></div>
                </div><aside class="wwds-overview-side"><div><span class="wwds-side-label">历史扫描进度</span><strong><?php echo esc_html($scanned . ' / ' . count($post_ids)); ?></strong><p>每次只扫描选中分类，最多 50 篇。</p></div><div><span class="wwds-side-label">已排除</span><strong><?php echo esc_html($counts['excluded']); ?> 篇</strong><p>旧教程库已有的同名文章可排除，避免重复创建。</p></div></aside></section>

                <form method="post" action="options.php" class="wwds-settings-form">
                    <?php settings_fields('wwds_tutorial_group'); ?>
                    <div class="wwds-capability-head"><div><h2>教程后台连接</h2><p>保存配置后，新发布或更新的匹配文章会自动入队。</p></div></div>
                    <div class="wwds-switch-row"><div><strong>启用教程同步</strong><p>与公众号草稿同步配置相互独立。</p></div><label class="wwds-switch"><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[enabled]" value="1" <?php checked($settings['enabled'], 1); ?>><span></span></label></div>
                    <div class="wwds-account"><div class="wwds-field-grid">
                        <label class="is-wide"><span>教程后端 HTTPS 地址</span><input type="url" name="<?php echo esc_attr(self::OPTION); ?>[endpoint]" value="<?php echo esc_attr($settings['endpoint']); ?>" placeholder="https://jc.52okp.com"></label>
                        <label><span>稳定站点标识</span><input type="text" name="<?php echo esc_attr(self::OPTION); ?>[site_id]" value="<?php echo esc_attr($settings['site_id']); ?>"></label>
                        <label><span>独立签名密钥</span><input type="password" name="<?php echo esc_attr(self::OPTION); ?>[secret]" value="" autocomplete="new-password" placeholder="<?php echo $settings['secret'] ? '已保存，留空保留' : '至少 32 字符'; ?>"></label>
                    </div><div class="wwds-category-head"><div><strong>同步分类</strong><p>历史补齐和新文章自动同步都使用以下分类。</p></div><span><?php echo esc_html(count($settings['categories'])); ?> 项</span></div>
                    <div class="wwds-category-list"><?php foreach ($categories as $category) : ?><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[categories][]" value="<?php echo (int)$category->term_id; ?>" <?php checked(in_array((int)$category->term_id, array_map('intval', $settings['categories']), true)); ?>><span><?php echo esc_html($category->name); ?></span></label><?php endforeach; ?></div>
                    <div class="wwds-field-grid"><label class="is-wide"><span>排除同步的 WordPress 文章 ID</span><input type="text" name="<?php echo esc_attr(self::OPTION); ?>[excluded_post_ids]" value="<?php echo esc_attr(implode(',', $excluded)); ?>" placeholder="例如：484"><small>这些文章仍显示在清单中，但不会自动同步或历史补齐。多个 ID 用逗号隔开。</small></label></div></div>
                    <div class="wwds-form-footer"><p>ⓘ 密钥留空会保留原值；页面不会回显密钥。</p><?php submit_button('保存教程同步配置', 'primary', 'submit', false); ?></div>
                </form>

                <section class="wwds-tutorial-card"><div class="wwds-capability-head"><div><h2>历史文章补齐</h2><p>从头按已选分类扫描，已确认同步的文章不会重复入队。</p></div></div>
                    <div class="wwds-tutorial-actions"><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wwds_tutorial_import'), 'wwds_tutorial_import')); ?>">入队下一批（最多 50 篇）</a><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wwds_tutorial_reset_import'), 'wwds_tutorial_reset_import')); ?>">从头重新扫描</a><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="wwds_tutorial_process"><?php wp_nonce_field('wwds_tutorial_process'); ?><button type="submit" class="button">立即处理待发送（最多 10 条）</button></form></div>
                    <p class="description">重新扫描只重置扫描位置，不会删除教程后台文章；已排除的 WordPress ID 不会入队。</p>
                </section>

                <section class="wwds-tutorial-card"><div class="wwds-capability-head"><div><h2>逐篇同步状态</h2><p>状态来自最新任务；“已同步到教程后台”必须显示后端文章 ID。</p></div><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wwds-tutorial')); ?>">刷新状态</a></div>
                    <div class="wwds-tutorial-table-wrap"><table class="widefat striped wwds-tutorial-table"><thead><tr><th>WordPress 文章</th><th>最新任务</th><th>同步状态</th><th>教程文章 ID</th><th>确认时间 / 错误</th><th>操作</th></tr></thead><tbody>
                    <?php foreach ($post_ids as $id) : $row = $tasks[$id] ?? null; $status = $this->task_status($row, in_array($id, $excluded, true)); ?>
                        <tr><td><a href="<?php echo esc_url(get_edit_post_link($id)); ?>"><?php echo esc_html(get_the_title($id)); ?></a><small>WP #<?php echo (int)$id; ?></small></td>
                            <td><?php echo $row ? esc_html(($row->action === 'upsert' ? '发布' : '下架') . ' · v' . $row->revision) : '—'; ?></td>
                            <td><span class="wwds-tutorial-badge is-<?php echo esc_attr($status[1]); ?>"><?php echo esc_html($status[0]); ?></span></td>
                            <td><?php echo $row && $row->remote_article_id ? (int)$row->remote_article_id : '—'; ?></td>
                            <td><?php echo $row && $row->synced_at ? esc_html(wp_date('Y-m-d H:i:s', (int)$row->synced_at)) : '—'; ?><?php if ($row && $row->last_error) : ?><small class="wwds-tutorial-error"><?php echo esc_html($row->last_error); ?></small><?php endif; ?></td>
                            <td><?php if ($row && $row->action === 'upsert' && $row->state === 'queued' && $row->attempts) : ?><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wwds_tutorial_retry&id=' . $row->id), 'wwds_tutorial_retry_' . $row->id)); ?>">立即重试</a><?php else : ?>—<?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$post_ids) : ?><tr><td colspan="6">当前分类没有符合条件的已发布文章。</td></tr><?php endif; ?>
                    </tbody></table></div>
                </section>
            </main>
        </div>
        <?php
    }

    public function add_meta_box()
    {
        add_meta_box('wwds-tutorial', '教程后端同步', array($this, 'meta_box'), 'post', 'side');
    }

    public function meta_box($post)
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE post_id=%d ORDER BY id DESC LIMIT 1", $post->ID));
        $excluded = in_array((int)$post->ID, array_map('intval', (array)$this->settings()['excluded_post_ids']), true);
        $status = $this->task_status($row, $excluded);
        echo '<p><strong>状态：</strong>' . esc_html($status[0]) . '</p>';
        if ($row) {
            echo '<p>任务：' . esc_html($row->action === 'upsert' ? '发布' : '下架') . ' · v' . (int)$row->revision . '</p>';
            if ($row->remote_article_id) echo '<p><strong>教程文章 ID：</strong>' . (int)$row->remote_article_id . '</p>';
            if ($row->synced_at) echo '<p><strong>确认时间：</strong>' . esc_html(wp_date('Y-m-d H:i:s', (int)$row->synced_at)) . '</p>';
            if ($row->last_error) echo '<p style="word-break:break-word;color:#b42318">' . esc_html($row->last_error) . '</p>';
        }
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

    public function process_now()
    {
        if (!current_user_can('manage_options')) wp_die('无权操作');
        check_admin_referer('wwds_tutorial_process');
        $this->process();
        wp_safe_redirect(admin_url('admin.php?page=wwds-tutorial'));
        exit;
    }

    public function reset_import()
    {
        if (!current_user_can('manage_options')) wp_die('无权操作');
        check_admin_referer('wwds_tutorial_reset_import');
        update_option(self::IMPORT_CURSOR, 0, false);
        wp_safe_redirect(admin_url('admin.php?page=wwds-tutorial'));
        exit;
    }

    public function import_batch()
    {
        if (!current_user_can('manage_options')) wp_die('无权操作');
        check_admin_referer('wwds_tutorial_import');
        $settings = $this->settings();
        if (!$settings['enabled'] || !$settings['endpoint'] || !$settings['site_id'] || strlen($settings['secret']) < 32 || !$settings['categories']) {
            wp_die('请先保存完整的教程同步配置和至少一个分类。');
        }
        $cursor = (int)get_option(self::IMPORT_CURSOR, 0);
        $ids = $this->selected_posts($settings, $cursor, 50);
        $latest = $this->latest_tasks($ids);
        $excluded = array_map('intval', (array)$settings['excluded_post_ids']);
        foreach ($ids as $id) {
            $cursor = (int)$id;
            if (in_array($id, $excluded, true)) continue;
            $prior = $latest[$id] ?? null;
            if ($prior && $prior->action === 'upsert' &&
                (($prior->state === 'success' && (int)$prior->remote_article_id > 0) ||
                in_array($prior->state, array('queued', 'sending'), true))) continue;
            $post = get_post((int)$id);
            if ($post && !$this->enqueue((int)$id, $post)) {
                // Leave this article as the next candidate if the queue insert failed.
                $cursor = max(0, (int)$id - 1);
                break;
            }
        }
        update_option(self::IMPORT_CURSOR, $cursor, false);
        wp_safe_redirect(admin_url('admin.php?page=wwds-tutorial'));
        exit;
    }
}

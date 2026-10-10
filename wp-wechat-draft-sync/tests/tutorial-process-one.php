<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
function add_action(...$args): void {}
function home_url($path = ''): string { return 'https://example.com' . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function get_option($name, $default = false) {
    return $name === WWDS_Tutorial_Sync::OPTION
        ? array('enabled' => 1, 'endpoint' => 'https://jc.example.com',
            'site_id' => 'example', 'secret' => str_repeat('a', 32))
        : $default;
}
function wp_parse_args($args, $defaults): array { return array_merge($defaults, $args); }
function wp_next_scheduled($hook) { return false; }
function wp_schedule_single_event($timestamp, $hook, $args = array(), $wp_error = false): bool { return true; }
function is_wp_error($value): bool { return false; }
function wp_remote_post($url, $args) {
    $GLOBALS['sent'][] = $args['body'];
    return array('status' => 200, 'body' => '{"code":1,"data":{"result":"applied","article_id":222}}');
}
function wp_remote_retrieve_response_code($response): int { return $response['status']; }
function wp_remote_retrieve_body($response): string { return $response['body']; }
function absint($value): int { return abs((int)$value); }

final class TutorialQueueDb
{
    public string $prefix = 'wp_';
    public array $rows;

    public function __construct()
    {
        $future = time() + 3600;
        $this->rows = [
            1 => (object)['id' => 1, 'post_id' => 11, 'state' => 'queued',
                'next_at' => $future, 'action' => 'upsert', 'payload' => '{"post_id":11}', 'attempts' => 0],
            2 => (object)['id' => 2, 'post_id' => 12, 'state' => 'queued',
                'next_at' => $future, 'action' => 'upsert', 'payload' => '{"post_id":12}', 'attempts' => 0],
        ];
    }

    public function prepare($sql, ...$args): string { return sprintf($sql, ...$args); }

    public function get_results($sql): array
    {
        if (str_contains($sql, 'WHERE id=2') && $this->rows[2]->state === 'queued') {
            return [$this->rows[2]];
        }
        throw new RuntimeException('Targeted send selected unexpected rows');
    }

    public function query($sql): int
    {
        if (!str_contains($sql, 'WHERE id=2') || $this->rows[2]->state !== 'queued') {
            throw new RuntimeException('Targeted send claimed the wrong row');
        }
        $this->rows[2]->state = 'sending';
        return 1;
    }

    public function update($table, $values, $where): int
    {
        foreach ($values as $key => $value) $this->rows[$where['id']]->$key = $value;
        return 1;
    }

    public function get_var($sql) { return null; }
}

require dirname(__DIR__) . '/includes/class-wwds-tutorial-sync.php';
$wpdb = new TutorialQueueDb();
$GLOBALS['sent'] = [];
WWDS_Tutorial_Sync::instance()->process(2);
if (count($GLOBALS['sent']) !== 1 || $GLOBALS['sent'][0] !== '{"post_id":12}' ||
    $wpdb->rows[2]->state !== 'success' || $wpdb->rows[2]->remote_article_id !== 222 ||
    $wpdb->rows[1]->state !== 'queued') {
    throw new RuntimeException('Immediate send did not process exactly the selected queued article');
}
echo "Targeted immediate tutorial send passed\n";

<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
$event_at = false;
$schedule_failures = 0;
function add_action(...$args): void {}
function wp_next_scheduled($hook) { global $event_at; return $event_at; }
function wp_unschedule_event($timestamp, $hook, $args = array(), $wp_error = false) {
    global $event_at;
    if ($event_at !== $timestamp) return false;
    $event_at = false;
    return true;
}
function wp_schedule_single_event($timestamp, $hook, $args = array(), $wp_error = false) {
    global $event_at, $schedule_failures;
    if ($schedule_failures > 0) { $schedule_failures--; return false; }
    $event_at = $timestamp;
    return true;
}
function is_wp_error($value): bool { return false; }

require dirname(__DIR__) . '/includes/class-wwds-tutorial-sync.php';
$sync = WWDS_Tutorial_Sync::instance();
$schedule = new ReflectionMethod($sync, 'schedule');
$schedule->setAccessible(true);
$check = static function ($condition, $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$now = time();
$check($schedule->invoke($sync) === '' && $event_at >= $now + 10, 'Initial event was not scheduled');
$later = $now + 3600;
$event_at = $later;
$check($schedule->invoke($sync) === '' && $event_at < $later, 'A late event blocked a new article');
$early = $event_at;
$check($schedule->invoke($sync, $now + 1800) === '' && $event_at === $early, 'Early event was moved later');
$event_at = $later;
$schedule_failures = 1;
$check($schedule->invoke($sync) !== '' && $event_at === $later, 'Failed scheduling lost the prior event');
echo "Tutorial queue scheduling checks passed\n";

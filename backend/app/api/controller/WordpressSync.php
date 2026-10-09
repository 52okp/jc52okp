<?php

declare(strict_types=1);

namespace app\api\controller;

use app\service\WordpressSync as SyncService;
use RuntimeException;
use think\facade\Db;
use think\Response;

/** Independent machine credential. Never accepts a Member-Token as authorization. */
class WordpressSync
{
    private const PATH = '/api/wordpress_sync/events';

    public function events(): Response
    {
        $request = request();
        if (!$request->isPost()) return json(['code' => 0, 'msg' => 'method not allowed', 'data' => null], 405);
        if (parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) !== self::PATH) {
            return json(['code' => 0, 'msg' => 'not found', 'data' => null], 404);
        }
        if (getenv('WP_SYNC_MAINTENANCE') === '1' || env('WP_SYNC_MAINTENANCE', false)) return json(['code' => 0, 'msg' => 'retry later', 'data' => null], 503);
        $body = (string)$request->getContent();
        if ($body === '' || strlen($body) > 2200000) return json(['code' => 0, 'msg' => 'invalid body', 'data' => null], 413);
        $site = (string)$request->header('X-WP-Sync-Site', '');
        $time = (string)$request->header('X-WP-Sync-Time', '');
        $nonce = (string)$request->header('X-WP-Sync-Nonce', '');
        $signature = (string)$request->header('X-WP-Sync-Signature', '');
        $configuredSite = (string)(getenv('WP_SYNC_SITE_ID') ?: env('WP_SYNC_SITE_ID', ''));
        $secret = (string)(getenv('WP_SYNC_SECRET') ?: env('WP_SYNC_SECRET', ''));
        if ($configuredSite === '' || strlen($secret) < 32 || $site !== $configuredSite ||
            !preg_match('/^[0-9]{10}$/', $time) || abs(time() - (int)$time) > 300 ||
            !preg_match('/^[a-f0-9]{32}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return json(['code' => 0, 'msg' => 'unauthorized', 'data' => null], 401);
        }
        $canonical = "POST\n" . self::PATH . "\n" . $time . "\n" . $nonce . "\n" . hash('sha256', $body);
        if (!hash_equals(hash_hmac('sha256', $canonical, $secret), $signature)) {
            return json(['code' => 0, 'msg' => 'unauthorized', 'data' => null], 401);
        }
        $event = json_decode($body, true);
        if (!is_array($event) || ($event['site_id'] ?? '') !== $site) {
            return json(['code' => 0, 'msg' => 'site mismatch', 'data' => null], 400);
        }
        try {
            // The unique key rejects concurrent replays. Old nonces may be pruned separately.
            Db::name('wp_sync_nonce')->insert(['site_id' => $site, 'nonce' => $nonce, 'expires_at' => time() + 600]);
        } catch (\Throwable $e) {
            if (in_array((string)$e->getCode(), ['23000', '19'], true)) {
                return json(['code' => 0, 'msg' => 'replayed request', 'data' => null], 409);
            }
            return json(['code' => 0, 'msg' => 'temporary sync failure', 'data' => null], 503);
        }
        if (random_int(1, 100) === 1) {
            try { Db::name('wp_sync_nonce')->where('expires_at', '<', time())->delete(); }
            catch (\Throwable $e) { /* Expiry cleanup can be retried later. */ }
        }
        try {
            $result = (new SyncService())->apply($event);
            return json(['code' => 1, 'msg' => 'ok', 'data' => $result]);
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'same revision has different content' ? 409 : 422;
            return json(['code' => 0, 'msg' => $e->getMessage(), 'data' => null], $status);
        } catch (\Throwable $e) {
            // Avoid leaking database details to WordPress and logs with credentials.
            return json(['code' => 0, 'msg' => 'temporary sync failure', 'data' => null], 503);
        }
    }
}

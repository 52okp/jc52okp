<?php

declare(strict_types=1);

/** First deployment: import the 2026-10-09 legacy SQL into a new MySQL 5.7 database, then open this page. */
const ROOT_PATH = __DIR__ . '/..';
const ENV_FILE = ROOT_PATH . '/.env';
const LOCK_FILE = ROOT_PATH . '/install.lock';
const EDGEONE_KEY_FILE = ROOT_PATH . '/.install-edgeone-key';
const APP_ID = 'wx4ec45155a93041cc';

@ini_set('display_errors', '0');
@set_time_limit(0);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$local = in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
$nativeHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$edgeOneKey = null;
if (!is_file(LOCK_FILE)) {
    if (!is_file(EDGEONE_KEY_FILE)) {
        $handle = @fopen(EDGEONE_KEY_FILE, 'x');
        if ($handle !== false) {
            $createdKey = bin2hex(random_bytes(32));
            @fwrite($handle, $createdKey . "\n");
            fclose($handle);
            @chmod(EDGEONE_KEY_FILE, 0600);
        }
    }
    $candidate = trim((string)@file_get_contents(EDGEONE_KEY_FILE));
    if (preg_match('/^[a-f0-9]{64}$/D', $candidate)) $edgeOneKey = $candidate;
}
$forwardedHttps = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
$edgeOneHttps = $edgeOneKey !== null && $forwardedHttps
    && strtolower((string)($_SERVER['HTTP_HOST'] ?? '')) === 'jc.52okp.com'
    && preg_match('/^TencentEdgeOne(?:;|$)/i', trim((string)($_SERVER['HTTP_CDN_LOOP'] ?? ''))) === 1
    && hash_equals($edgeOneKey, (string)($_SERVER['HTTP_X_52OKP_INSTALL_KEY'] ?? ''));
$https = $nativeHttps || $edgeOneHttps;
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['httponly' => true, 'secure' => $https, 'samesite' => 'Strict']);
    session_start();
}
if ($https && empty($_SESSION['installer_secure_cookie'])) {
    session_regenerate_id(true);
    $_SESSION['installer_secure_cookie'] = true;
}
$_SESSION['install_csrf'] ??= bin2hex(random_bytes(24));

function h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function page(string $title, string $body): void
{
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . ' · 教程站安装</title><style>'
        . 'body{margin:0;background:#f5f6fa;color:#253044;font:15px/1.6 system-ui,"Microsoft YaHei",sans-serif}'
        . 'main{max-width:680px;margin:40px auto;padding:28px;background:#fff;border-radius:14px;box-shadow:0 8px 28px #1a285015}'
        . 'h1{margin:0 0 8px;font-size:24px}p{margin:10px 0}table{width:100%;border-collapse:collapse;margin:14px 0}'
        . 'td,th{padding:9px;border-bottom:1px solid #e8ebf2;text-align:left}label{display:block;margin:15px 0 4px;font-weight:600}'
        . 'input[type=text],input[type=password],input[type=number]{box-sizing:border-box;width:100%;padding:10px;border:1px solid #cbd3df;border-radius:7px;font:inherit}'
        . 'button,.button{display:inline-block;margin-top:16px;background:#2467d6;color:white;border:0;border-radius:7px;padding:10px 18px;text-decoration:none;font:inherit;cursor:pointer}'
        . '.note{padding:12px 15px;background:#eef4ff;border-radius:8px}.error{padding:12px 15px;background:#fff0f0;color:#ae2020;border-radius:8px}'
        . '.ok{color:#167d46}.bad{color:#b42318}code{background:#f1f3f7;padding:1px 4px;border-radius:3px}'
        . '</style></head><body><main><h1>' . h($title) . '</h1>' . $body . '</main></body></html>';
}

function csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . h((string)$_SESSION['install_csrf']) . '">';
}

function validCsrf(): bool
{
    return isset($_POST['csrf']) && is_string($_POST['csrf'])
        && hash_equals((string)$_SESSION['install_csrf'], $_POST['csrf']);
}

function stop(string $message, bool $retry = true): void
{
    page('无法继续安装', '<div class="error">' . h($message) . '</div>'
        . ($retry ? '<p><a class="button" href="install.php?step=config">返回数据库配置</a></p>' : ''));
    exit;
}

function requirements(): array
{
    $result = ['PHP ≥ 8.2' => version_compare(PHP_VERSION, '8.2.0', '>=')];
    foreach (['pdo', 'pdo_mysql', 'gd', 'mbstring', 'curl', 'fileinfo', 'openssl', 'dom', 'zip'] as $ext) {
        $result['扩展 ' . $ext] = extension_loaded($ext);
    }
    $result['vendor/autoload.php'] = is_file(ROOT_PATH . '/vendor/autoload.php');
    $result['根目录可写'] = is_writable(ROOT_PATH);
    $runtime = ROOT_PATH . '/runtime';
    $result['runtime 可写'] = (is_dir($runtime) || @mkdir($runtime, 0755, true)) && is_writable($runtime);
    return $result;
}

function connection(array $input): PDO
{
    $host = (string)$input['host'];
    $port = (int)$input['port'];
    $name = (string)$input['database'];
    $user = (string)$input['username'];
    if (!in_array($host, ['127.0.0.1', 'localhost'], true) || $port < 1 || $port > 65535
        || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name)
        || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $user)) {
        throw new RuntimeException('请检查数据库地址、端口、库名和用户名。当前安装器只连接本机 MySQL。');
    }
    return new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, (string)$input['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 8,
    ]);
}

function inspectLegacy(PDO $pdo): array
{
    $required = [
        'article', 'article_category', 'banner', 'member', 'member_favorite', 'member_history', 'migrations',
        'system_auth', 'system_auth_node', 'system_base', 'system_config', 'system_data', 'system_file',
        'system_menu', 'system_oplog', 'system_queue', 'system_user',
    ];
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    sort($required);
    sort($tables);
    if ($tables !== $required) {
        throw new RuntimeException('该库不是完整的旧版教程库，或已被迁移。请在新建空库中通过宝塔导入旧 SQL，再回来安装。');
    }
    $expectedVersions = [
        '20241010000001', '20241010000002', '20260606120000', '20260608120000',
        '20260609120000', '20260609140000', '20260609160000', '20260610120000',
    ];
    $versions = array_map('strval', $pdo->query('SELECT version FROM migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN));
    if ($versions !== $expectedVersions) {
        throw new RuntimeException('旧版迁移记录不匹配。请核对导入的 SQL 是否为本项目旧库。');
    }
    $counts = [];
    foreach (['article', 'article_category', 'banner', 'member', 'member_favorite', 'member_history', 'system_user'] as $table) {
        $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    }
    if ($counts['article'] < 1 || $counts['member'] < 1 || $counts['system_user'] < 1) {
        throw new RuntimeException('旧文章、会员或管理员数据缺失；请先核对 SQL 导入结果。');
    }
    return $counts;
}

function articleFingerprint(PDO $pdo): array
{
    $result = [];
    foreach ($pdo->query('SELECT id,content,status,is_deleted FROM article ORDER BY id') as $row) {
        $result[(string)$row['id']] = hash('sha256', (string)$row['content']) . ':' . $row['status'] . ':' . $row['is_deleted'];
    }
    return $result;
}

function writeEnvironment(array $input): void
{
    $secret = trim((string)($input['wx_secret'] ?? ''));
    if ($secret !== '' && !preg_match('/^[A-Za-z0-9]{32,64}$/D', $secret)) {
        throw new RuntimeException('小程序 AppSecret 格式不正确；可先留空，安装后再填写。');
    }
    $lines = [
        '# Generated by the first-deployment installer', 'APP_DEBUG=false', 'DB_TYPE=mysql',
        'DB_MYSQL_HOST=' . $input['host'], 'DB_MYSQL_PORT=' . $input['port'],
        'DB_MYSQL_DATABASE=' . $input['database'], 'DB_MYSQL_USERNAME=' . $input['username'],
        'DB_MYSQL_PASSWORD_B64=' . base64_encode((string)$input['password']),
        'DB_MYSQL_PREFIX=', 'DB_MYSQL_CHARSET=utf8mb4',
        'CACHE_TYPE=file', 'SESSION_TYPE=file', 'SESSION_NAME=ssid', 'SESSION_EXPIRE=7200',
        'WXAPP_APPID=' . APP_ID, 'WXAPP_SECRET=' . $secret,
        'WP_SYNC_SITE_ID=', 'WP_SYNC_SECRET=', 'WP_SYNC_MAINTENANCE=1',
        'OSS_ORIGIN=https://app.52okp.com', 'OSS_PROJECT=', 'OSS_TOKEN=', 'APP_VERSION=',
    ];
    $handle = @fopen(ENV_FILE, 'x');
    if ($handle === false) throw new RuntimeException('无法创建 .env；请检查站点根目录权限。');
    try {
        if (@fwrite($handle, implode("\n", $lines) . "\n") === false) {
            throw new RuntimeException('写入 .env 失败。');
        }
        @fflush($handle);
    } finally {
        fclose($handle);
    }
    @chmod(ENV_FILE, 0600);
}

$step = (string)($_GET['step'] ?? 'check');
if (is_file(LOCK_FILE)) {
    $installed = trim((string)@file_get_contents(LOCK_FILE)) === 'installed';
    page($installed ? '系统已安装' : '安装未完成', $installed
        ? '<p class="note">安装入口已锁定。请删除服务器上的 <code>public/install.php</code>，然后进入管理后台。</p><p><a class="button" href="/admin/login.html">进入后台</a></p>'
        : '<p class="error">安装中断。请检查服务器日志，并在恢复新库备份后处理 <code>.env</code> 与 <code>install.lock</code>。不要在原库上重复运行。</p>');
    exit;
}
if (is_file(ENV_FILE)) {
    stop('服务器已存在 .env。为避免覆盖现有站点配置，网页安装器已停止。', false);
}
if (!$https && !$local) {
    stop('请从 EdgeOne 的 HTTPS 域名访问。若源站使用 HTTP 回源，请在 EdgeOne 增加回源请求头 X-52OKP-Install-Key，值取自站点根目录的 .install-edgeone-key 文件；安装器还会核对 X-Forwarded-Proto 和 EdgeOne 标记。', false);
}

if ($step === 'check') {
    $checks = requirements();
    $body = '<p class="note">先在宝塔新建 MySQL 5.7 数据库并导入旧 SQL，再用此向导完成升级迁移。安装器不创建数据库、示例文章或默认管理员。</p><table>';
    foreach ($checks as $name => $pass) {
        $body .= '<tr><td>' . h($name) . '</td><td class="' . ($pass ? 'ok' : 'bad') . '">' . ($pass ? '通过' : '未通过') . '</td></tr>';
    }
    $body .= '</table>' . (!in_array(false, $checks, true)
        ? '<a class="button" href="?step=config">下一步：连接旧库</a>'
        : '<p class="error">请先在宝塔修复未通过的项目，然后刷新本页。</p>');
    page('环境检测', $body);
    exit;
}

if ($step === 'config' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (in_array(false, requirements(), true)) stop('环境检测未通过，请返回首页查看。', false);
    page('连接已导入的旧库', '<p class="note">只填写新建并已导入旧 SQL 的 MySQL 5.7 数据库。安装器会先展示文章与会员数量，再由你确认迁移。</p>'
        . '<form method="post" action="?step=preview">' . csrf()
        . '<label>数据库地址</label><input type="text" name="host" value="127.0.0.1" required>'
        . '<label>端口</label><input type="number" name="port" value="3306" min="1" max="65535" required>'
        . '<label>数据库名</label><input type="text" name="database" required>'
        . '<label>数据库用户名</label><input type="text" name="username" required>'
        . '<label>数据库密码</label><input type="password" name="password" autocomplete="new-password">'
        . '<label>微信小程序 AppSecret（可留空，稍后在 .env 填写）</label><input type="password" name="wx_secret" autocomplete="new-password">'
        . '<p>小程序 AppID：<code>' . APP_ID . '</code></p><button type="submit">检查旧库</button></form>');
    exit;
}

if ($step === 'preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validCsrf()) stop('页面已过期，请刷新后重试。');
    $input = [
        'host' => trim((string)($_POST['host'] ?? '')), 'port' => trim((string)($_POST['port'] ?? '')),
        'database' => trim((string)($_POST['database'] ?? '')), 'username' => trim((string)($_POST['username'] ?? '')),
        'password' => (string)($_POST['password'] ?? ''), 'wx_secret' => trim((string)($_POST['wx_secret'] ?? '')),
    ];
    try {
        $pdo = connection($input);
        $counts = inspectLegacy($pdo);
        if ($input['wx_secret'] !== '' && !preg_match('/^[A-Za-z0-9]{32,64}$/D', $input['wx_secret'])) {
            throw new RuntimeException('小程序 AppSecret 格式不正确。');
        }
    } catch (PDOException $error) {
        error_log('Installer database preview failed: ' . $error->getMessage());
        stop('数据库连接或旧库读取失败。请检查宝塔中的新库、账号权限及 SQL 导入结果。');
    } catch (RuntimeException $error) {
        stop($error->getMessage());
    } catch (Throwable $error) {
        error_log('Installer database preview failed: ' . $error->getMessage());
        stop('数据库连接或旧库读取失败。请检查宝塔中的新库、账号权限及 SQL 导入结果。');
    }
    $_SESSION['install_candidate'] = ['input' => $input, 'counts' => $counts, 'at' => time()];
    $labels = ['article' => '文章', 'article_category' => '分类', 'banner' => '头图', 'member' => '会员',
        'member_favorite' => '收藏', 'member_history' => '历史', 'system_user' => '后台用户'];
    $body = '<p class="note">识别到完整旧库及 8 条原有迁移记录。即将只运行新增迁移，保留原文章和管理员。</p><table>';
    foreach ($labels as $table => $label) $body .= '<tr><td>' . h($label) . '</td><td>' . $counts[$table] . '</td></tr>';
    $body .= '</table><form method="post" action="?step=install">' . csrf()
        . '<label><input type="checkbox" name="confirmed" value="yes" required> 我确认这是从旧 SQL 导入的新数据库，且已保存迁移前备份。</label>'
        . '<button type="submit">确认并迁移</button></form>';
    page('核对旧数据', $body);
    exit;
}

if ($step === 'install' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validCsrf() || ($_POST['confirmed'] ?? '') !== 'yes') stop('请重新核对并确认旧库。');
    $candidate = $_SESSION['install_candidate'] ?? null;
    if (!is_array($candidate) || time() - (int)($candidate['at'] ?? 0) > 900) stop('核对结果已过期，请重新填写数据库。');
    unset($_SESSION['install_candidate']);
    $input = $candidate['input'];
    try {
        $pdo = connection($input);
        $before = inspectLegacy($pdo);
        if ($before !== $candidate['counts']) throw new RuntimeException('核对后数据库数据发生变化，安装已停止。');
        $fingerprint = articleFingerprint($pdo);
        $lock = @fopen(LOCK_FILE, 'x');
        if ($lock === false) throw new RuntimeException('无法创建安装锁；请检查根目录权限。');
        fwrite($lock, 'installing');
        fclose($lock);
        writeEnvironment($input);
        require ROOT_PATH . '/vendor/autoload.php';
        $app = \think\admin\service\RuntimeService::init();
        $app->initialize();
        $app->console->call('migrate:run', ['--no-interaction' => true], 'buffer');

        foreach ($before as $table => $count) {
            $after = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            if ($after !== $count) throw new RuntimeException('迁移后旧数据数量发生变化：' . $table);
        }
        if (articleFingerprint($pdo) !== $fingerprint) throw new RuntimeException('迁移后旧文章正文或状态发生变化。');
        $versions = array_map('strval', $pdo->query('SELECT version FROM migrations WHERE version >= 20261009120000 ORDER BY version')->fetchAll(PDO::FETCH_COLUMN));
        if ($versions !== ['20261009120000', '20261009130000', '20261009140000']) {
            throw new RuntimeException('新增迁移记录不完整。');
        }
        if (@file_put_contents(LOCK_FILE, 'installed') === false) throw new RuntimeException('无法完成安装锁。');
        unset($_SESSION['install_csrf']);
        page('安装完成', '<p class="note">原有 ' . $before['article'] . ' 篇文章、' . $before['member']
            . ' 位会员及收藏、历史均已保留。请使用旧库中的管理员账号登录；不会生成默认账号。</p>'
            . '<p><a class="button" href="/admin/login.html">进入管理后台</a>　<a class="button" href="/api/article/list">检查文章接口</a></p>'
            . '<p class="error">请立即在宝塔删除 <code>public/install.php</code>。如 AppSecret 留空，请在服务器的 <code>.env</code> 中填写后再测试会员登录。</p>');
    } catch (Throwable $error) {
        error_log('Installer migration failed: ' . $error->getMessage());
        stop(is_file(LOCK_FILE)
            ? '安装未完成。请查看服务器日志。安装锁已阻止重复执行；修复问题后，请从迁移前备份恢复新库，再清理安装器生成的 .env 和 install.lock。'
            : '安装尚未开始迁移。请查看服务器日志，检查数据库连接和站点目录权限。', false);
    }
    exit;
}

header('Location: install.php?step=check', true, 303);

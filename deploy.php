<?php
/*
 * Saltanat News Karachi — protected deployment dashboard.
 * Design reminder: a calm navy-and-cream internal control surface that keeps every Git action explicit,
 * narrow in scope, and visually subordinate to status and auditability.
 */
declare(strict_types=1);

const DEPLOY_REPOSITORY = '/home/noorgeec/saltanat';
const DEPLOY_LIVE_ROOT = '/home/noorgeec/saltanatnewskarachi.com.pk';
const DEPLOY_BRANCH = 'main-m81';
const DEPLOY_REMOTE = 'https://github.com/moon060781-bot/saltanat';

$configPath = dirname(__DIR__) . '/cred/slt.env';
$auditPath = dirname(__DIR__) . '/cred/saltanat-deploy.log';

ini_set('session.use_strict_mode', '1');
if (session_status() === PHP_SESSION_NONE) {
    session_name('saltanat_deploy');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function readAdminPassword(string $path): string
{
    if (!is_readable($path)) {
        return '';
    }

    foreach ((array) file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim((string) $line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        if (trim($key) === 'ADMIN_PASS') {
            return trim($value, " \t\n\r\0\x0B\"'");
        }
    }

    return '';
}

function csrfToken(): string
{
    if (empty($_SESSION['saltanat_deploy_csrf'])) {
        $_SESSION['saltanat_deploy_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['saltanat_deploy_csrf'];
}

function validCsrf(): bool
{
    return isset($_POST['csrf'], $_SESSION['saltanat_deploy_csrf'])
        && is_string($_POST['csrf'])
        && hash_equals((string) $_SESSION['saltanat_deploy_csrf'], $_POST['csrf']);
}

function audit(string $path, string $action, string $outcome, string $detail = ''): void
{
    $entry = implode("\t", [
        gmdate('c'),
        'admin',
        preg_replace('/[^a-z_-]/', '', strtolower($action)) ?: 'unknown',
        preg_replace('/[^a-z_-]/', '', strtolower($outcome)) ?: 'unknown',
        str_replace(["\r", "\n", "\t"], ' ', trim($detail)),
    ]) . "\n";

    $newFile = !is_file($path);
    @file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);
    if ($newFile) {
        @chmod($path, 0600);
    }
}

function runGit(array $arguments): array
{
    $git = '/usr/bin/git';
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'code' => 126, 'output' => 'Hosting PHP میں Git process چلانے کی اجازت دستیاب نہیں ہے۔'];
    }
    if (!is_executable($git) || !is_dir(DEPLOY_REPOSITORY . '/.git')) {
        return ['ok' => false, 'code' => 127, 'output' => 'Repository یا Git executable دستیاب نہیں ہے۔'];
    }

    $command = $git . ' -C ' . escapeshellarg(DEPLOY_REPOSITORY);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }

    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptor, $pipes, null, ['PATH' => '/usr/bin:/bin']);
    if (!is_resource($process)) {
        return ['ok' => false, 'code' => 1, 'output' => 'Git process شروع نہیں ہو سکا۔'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $output = trim((string) $stdout . "\n" . (string) $stderr);
    $shortOutput = function_exists('mb_substr') ? mb_substr($output, 0, 2400) : substr($output, 0, 2400);

    return [
        'ok' => $code === 0,
        'code' => $code,
        'output' => $shortOutput,
    ];
}

function repositoryReady(): array
{
    $branch = runGit(['branch', '--show-current']);
    $remote = runGit(['remote', 'get-url', 'origin']);
    $branchName = trim($branch['output']);
    $remoteUrl = trim($remote['output']);
    $allowedRemotes = [DEPLOY_REMOTE, DEPLOY_REMOTE . '.git'];

    if (!$branch['ok'] || !$remote['ok']) {
        return [false, 'Repository metadata حاصل نہیں ہو سکی۔'];
    }
    if ($branchName !== DEPLOY_BRANCH) {
        return [false, 'Allowed branch فعال نہیں ہے۔'];
    }
    if (!in_array($remoteUrl, $allowedRemotes, true)) {
        return [false, 'Remote URL محفوظ allow-list سے مطابقت نہیں رکھتا۔'];
    }

    return [true, ''];
}

function workingTreeClean(): bool
{
    $status = runGit(['status', '--porcelain']);
    return $status['ok'] && trim($status['output']) === '';
}

function syncFromGitHub(): array
{
    [$ready, $reason] = repositoryReady();
    if (!$ready) {
        return [false, $reason];
    }
    if (!workingTreeClean()) {
        return [false, 'Repository میں uncommitted تبدیلیاں ہیں؛ پہلے انہیں محفوظ یا resolve کریں۔'];
    }

    $fetch = runGit(['fetch', '--prune', 'origin']);
    if (!$fetch['ok']) {
        return [false, 'GitHub fetch ناکام رہا: ' . $fetch['output']];
    }

    $pull = runGit(['pull', '--ff-only', 'origin', DEPLOY_BRANCH]);
    if (!$pull['ok']) {
        return [false, 'Fast-forward sync ناکام رہا: ' . $pull['output']];
    }

    return [true, trim($pull['output']) ?: 'GitHub source پہلے ہی تازہ ہے۔'];
}

function copyFileToLive(string $relativePath): bool
{
    $source = DEPLOY_REPOSITORY . '/' . $relativePath;
    $destination = DEPLOY_LIVE_ROOT . '/' . $relativePath;
    if (!is_file($source)) {
        return false;
    }
    if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true) && !is_dir(dirname($destination))) {
        return false;
    }
    return copy($source, $destination);
}

function copyImagesToLive(): int
{
    $sourceRoot = DEPLOY_REPOSITORY . '/images';
    $destinationRoot = DEPLOY_LIVE_ROOT . '/images';
    if (!is_dir($sourceRoot)) {
        return 0;
    }

    $copied = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        $relative = substr($item->getPathname(), strlen($sourceRoot) + 1);
        $destination = $destinationRoot . '/' . $relative;
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true) && !is_dir(dirname($destination))) {
            continue;
        }
        if (copy($item->getPathname(), $destination)) {
            $copied++;
        }
    }

    return $copied;
}

function publishToLive(): array
{
    [$ready, $reason] = repositoryReady();
    if (!$ready) {
        return [false, $reason];
    }
    if (!is_dir(DEPLOY_LIVE_ROOT)) {
        return [false, 'Live document root دستیاب نہیں ہے۔'];
    }

    $files = ['index.html', 'admin.php', 'deploy.php', '.htaccess'];
    foreach ($files as $file) {
        if (!copyFileToLive($file)) {
            return [false, $file . ' کو live folder میں copy نہیں کیا جا سکا۔'];
        }
    }

    $images = copyImagesToLive();
    $liveArchive = DEPLOY_LIVE_ROOT . '/media/issues.json';
    if (!is_file($liveArchive) && !copyFileToLive('media/issues.json')) {
        return [false, 'ابتدائی archive file کو live folder میں copy نہیں کیا جا سکا۔'];
    }

    return [true, 'Current source live document root میں publish ہو گیا۔ ' . $images . ' image file(s) sync ہوئیں؛ live PDFs اور archive محفوظ رکھے گئے۔'];
}

function repositoryStatus(): array
{
    $head = runGit(['rev-parse', '--short', 'HEAD']);
    $branch = runGit(['branch', '--show-current']);
    $remote = runGit(['remote', 'get-url', 'origin']);
    $dirty = runGit(['status', '--porcelain']);
    $last = runGit(['log', '-1', '--format=%h|%cI|%s']);
    $recent = runGit(['log', '-5', '--date=short', '--format=%h|%ad|%s']);

    $lastParts = $last['ok'] ? explode('|', trim($last['output']), 3) : [];
    $recentRows = [];
    if ($recent['ok']) {
        foreach (array_filter(explode("\n", trim($recent['output']))) as $row) {
            $parts = explode('|', $row, 3);
            if (count($parts) === 3) {
                $recentRows[] = $parts;
            }
        }
    }

    return [
        'head' => $head['ok'] ? trim($head['output']) : '—',
        'branch' => $branch['ok'] ? trim($branch['output']) : '—',
        'remote' => $remote['ok'] ? trim($remote['output']) : '—',
        'clean' => $dirty['ok'] && trim($dirty['output']) === '',
        'last' => [
            'commit' => $lastParts[0] ?? '—',
            'date' => $lastParts[1] ?? '—',
            'subject' => $lastParts[2] ?? 'Commit information دستیاب نہیں ہے۔',
        ],
        'recent' => $recentRows,
    ];
}

function auditTail(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_slice(array_reverse($lines), 0, 8);
}

$adminPassword = readAdminPassword($configPath);
if ($adminPassword === '') {
    http_response_code(503);
    exit('Deploy setup is incomplete. Add ADMIN_PASS to /home/noorgeec/cred/slt.env outside the public web directory.');
}

$flash = $_SESSION['saltanat_deploy_flash'] ?? null;
unset($_SESSION['saltanat_deploy_flash']);
$loginError = '';
$loggedIn = !empty($_SESSION['saltanat_deploy_admin']);

if (!$loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!validCsrf()) {
        $loginError = 'Security token درست نہیں ہے۔ براہ کرم صفحہ دوبارہ کھولیں۔';
    } elseif (!empty($_SESSION['saltanat_deploy_lock_until']) && time() < (int) $_SESSION['saltanat_deploy_lock_until']) {
        $loginError = 'کوششیں عارضی طور پر محدود ہیں۔ چند منٹ بعد دوبارہ کوشش کریں۔';
    } elseif (hash_equals($adminPassword, (string) ($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['saltanat_deploy_admin'] = true;
        $_SESSION['saltanat_deploy_attempts'] = 0;
        $_SESSION['saltanat_deploy_csrf'] = bin2hex(random_bytes(32));
        audit($auditPath, 'login', 'success');
        header('Location: deploy.php');
        exit;
    } else {
        $attempts = (int) ($_SESSION['saltanat_deploy_attempts'] ?? 0) + 1;
        $_SESSION['saltanat_deploy_attempts'] = $attempts;
        if ($attempts >= 5) {
            $_SESSION['saltanat_deploy_lock_until'] = time() + 600;
        }
        audit($auditPath, 'login', 'failed');
        $loginError = 'پاس ورڈ درست نہیں ہے۔';
    }
}

if ($loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validCsrf()) {
        http_response_code(403);
        exit('Security token invalid. Reload the dashboard and try again.');
    }

    $action = (string) $_POST['action'];
    if ($action === 'logout') {
        audit($auditPath, 'logout', 'success');
        session_unset();
        session_destroy();
        header('Location: deploy.php');
        exit;
    }

    if ($action === 'sync') {
        [$success, $message] = syncFromGitHub();
        audit($auditPath, 'sync', $success ? 'success' : 'failed', $success ? 'fast-forward only' : 'blocked');
        $_SESSION['saltanat_deploy_flash'] = ['success' => $success, 'message' => $message];
    } elseif ($action === 'publish') {
        [$success, $message] = publishToLive();
        audit($auditPath, 'publish', $success ? 'success' : 'failed', $success ? 'allow-listed files only' : 'blocked');
        $_SESSION['saltanat_deploy_flash'] = ['success' => $success, 'message' => $message];
    } else {
        audit($auditPath, 'unknown', 'failed');
        $_SESSION['saltanat_deploy_flash'] = ['success' => false, 'message' => 'Allowed action منتخب نہیں کی گئی۔'];
    }

    header('Location: deploy.php');
    exit;
}

$status = $loggedIn ? repositoryStatus() : [];
$auditEntries = $loggedIn ? auditTail($auditPath) : [];
$csrf = csrfToken();
?><!doctype html>
<html lang="ur" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Saltanat Deploy Dashboard</title>
  <style>
    :root{--navy:#0e1d34;--navy-2:#162b4b;--cream:#f5efe1;--paper:#fffaf0;--ink:#14233d;--line:#d6c5a4;--gold:#c99c55;--mint:#266f6d;--danger:#a04e43;--muted:#657186;--shadow:0 18px 45px rgba(14,29,52,.13)}
    *{box-sizing:border-box} body{margin:0;background:var(--cream);color:var(--ink);font-family:Tahoma,"Noto Nastaliq Urdu",serif;line-height:1.7}.shell{width:min(1180px,calc(100% - 32px));margin:auto}.topbar{background:var(--navy);border-bottom:4px solid var(--gold);color:#fff}.topbar-inner{min-height:74px;display:flex;align-items:center;justify-content:space-between;gap:18px}.eyebrow{margin:0;color:#f0cd90;font:700 11px/1.2 monospace;letter-spacing:.12em}.topbar h1{margin:3px 0 0;font-size:25px;line-height:1.35}.topbar-actions{display:flex;align-items:center;gap:10px}.badge{display:inline-flex;align-items:center;gap:6px;border:1px solid rgba(255,255,255,.28);padding:5px 9px;color:#eaf2f5;font:11px/1.3 monospace}.content{padding:28px 0 42px}.flash{margin:0 0 18px;border-right:5px solid;padding:12px 15px;background:var(--paper);box-shadow:var(--shadow);font-size:13px}.flash.ok{border-color:var(--mint);color:#155452}.flash.error{border-color:var(--danger);color:#7e342c}.dashboard-grid{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(300px,.9fr);gap:20px}.card{border:1px solid var(--line);background:var(--paper);box-shadow:var(--shadow)}.card-head{padding:16px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:12px}.card-head h2{margin:0;font-size:20px;line-height:1.4}.card-body{padding:18px}.status-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.metric{border:1px solid #e3d7c1;padding:12px;background:#fffdf8}.metric span{display:block;color:var(--muted);font:11px/1.4 monospace}.metric strong{display:block;margin-top:4px;font-family:"Source Sans 3",Tahoma,sans-serif;font-size:17px;overflow-wrap:anywhere}.state{font-size:13px;font-weight:700}.state.clean{color:var(--mint)}.state.dirty{color:var(--danger)}.commit-note{margin:14px 0 0;padding:12px 14px;background:#f0e5cf;border-right:4px solid var(--gold);font-size:13px}.commit-note strong{display:block}.operations{display:grid;gap:12px}.operation{border:1px solid #dfd0b6;padding:15px;background:#fffdf8}.operation h3{margin:0;font-size:18px}.operation p{margin:5px 0 11px;color:#586276;font-size:12px;line-height:1.9}.button{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid var(--navy);padding:7px 14px;background:var(--navy);color:#fff;font:700 12px/1.2 Tahoma,"Noto Nastaliq Urdu",serif;text-decoration:none;cursor:pointer;transition:transform .16s ease,background .16s ease}.button:hover{background:var(--navy-2)}.button:active{transform:scale(.98)}.button.secondary{background:transparent;color:var(--navy)}.button.warn{border-color:var(--gold);background:var(--gold);color:#15233a}.list{margin:0;padding:0;list-style:none;display:grid;gap:9px}.list li{border-bottom:1px solid #eadfcd;padding:0 0 9px;font-size:12px}.list li:last-child{border-bottom:0;padding-bottom:0}.muted{color:var(--muted);font-size:12px}.history{grid-column:1/-1}.commit-table{width:100%;border-collapse:collapse;font-size:12px}.commit-table th,.commit-table td{padding:9px 8px;border-bottom:1px solid #e9deca;text-align:right;vertical-align:top}.commit-table th{color:#586276;font-weight:700}.commit-table td:first-child{font-family:monospace;color:var(--mint);font-weight:700}.workflow{margin-top:20px;border-top:2px solid var(--navy);padding:17px 0 0}.workflow h2{margin:0 0 8px;font-size:19px}.workflow ol{margin:0;padding-right:22px;color:#4d576a;font-size:12px;line-height:2}.footer-note{margin-top:20px;color:#657186;font-size:11px}.login-shell{width:min(520px,calc(100% - 30px));margin:9vh auto}.login-card{border:1px solid var(--line);border-top:5px solid var(--gold);background:var(--paper);padding:28px;box-shadow:var(--shadow)}.login-card h1{margin:7px 0;font-size:28px}.login-card p{color:#596376;font-size:13px}.login-card label{display:block;margin:17px 0 5px;font-weight:700}.login-card input{width:100%;border:1px solid #cdbb9d;padding:11px;background:#fffdf8;color:var(--ink);font:16px sans-serif}.login-error{color:#8c3d34;font-size:13px}.security-note{margin-top:18px;padding:11px 12px;border-right:4px solid var(--gold);background:#f0e5cf;color:#5c4b31;font-size:12px;line-height:1.9}@media(max-width:780px){.shell{width:min(100% - 22px,1180px)}.topbar-inner{align-items:flex-start;flex-direction:column;padding:14px 0;gap:9px}.dashboard-grid{grid-template-columns:1fr}.status-grid{grid-template-columns:1fr}.commit-table{display:block;overflow:auto;white-space:nowrap}.card-head{align-items:flex-start;flex-direction:column}.footer-note{line-height:1.9}}
  </style>
</head>
<body>
<?php if (!$loggedIn): ?>
  <main class="login-shell">
    <section class="login-card">
      <p class="eyebrow" style="color:#9a763d">SALTANAT · DEPLOY CONTROL</p>
      <h1>محفوظ ڈپلائے ڈیش بورڈ</h1>
      <p>یہ صفحہ صرف مجاز editor کے لیے ہے۔ یہ dashboard arbitrary commands، branch changes، restore یا public secrets کی اجازت نہیں دیتا۔</p>
      <?php if ($loginError !== ''): ?><p class="login-error"><?= e($loginError) ?></p><?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="login" value="1">
        <label for="password">پاس ورڈ</label>
        <input id="password" type="password" name="password" required autofocus>
        <button class="button" type="submit" style="margin-top:18px">لاگ اِن</button>
      </form>
      <p class="security-note">Password `ADMIN_PASS` کے طور پر `/home/noorgeec/cred/slt.env` میں public web root سے باہر محفوظ ہے۔</p>
      <p><a class="button secondary" href="index.html">ہوم پیج</a></p>
    </section>
  </main>
<?php else: ?>
  <header class="topbar">
    <div class="shell topbar-inner">
      <div><p class="eyebrow">SALTANAT · GITHUB &amp; CPANEL CONTROL</p><h1>ڈپلائے ڈیش بورڈ</h1></div>
      <div class="topbar-actions"><span class="badge">ALLOWED BRANCH · <?= e(DEPLOY_BRANCH) ?></span><form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="button secondary" style="color:#fff;border-color:rgba(255,255,255,.5)" type="submit" name="action" value="logout">لاگ آؤٹ</button></form></div>
    </div>
  </header>
  <main class="shell content">
    <?php if (is_array($flash)): ?><p class="flash <?= !empty($flash['success']) ? 'ok' : 'error' ?>"><?= e((string) ($flash['message'] ?? '')) ?></p><?php endif; ?>
    <div class="dashboard-grid">
      <section class="card">
        <div class="card-head"><h2>Repository کی موجودہ حالت</h2><span class="state <?= $status['clean'] ? 'clean' : 'dirty' ?>"><?= $status['clean'] ? '● Clean working tree' : '● Uncommitted changes موجود ہیں' ?></span></div>
        <div class="card-body">
          <div class="status-grid">
            <div class="metric"><span>ACTIVE BRANCH</span><strong><?= e($status['branch']) ?></strong></div>
            <div class="metric"><span>CURRENT HEAD</span><strong><?= e($status['head']) ?></strong></div>
            <div class="metric"><span>APPROVED REMOTE</span><strong><?= e($status['remote']) ?></strong></div>
            <div class="metric"><span>LIVE ROOT</span><strong><?= e(DEPLOY_LIVE_ROOT) ?></strong></div>
          </div>
          <div class="commit-note"><strong><?= e($status['last']['commit']) ?> — <?= e($status['last']['subject']) ?></strong><span class="muted"><?= e($status['last']['date']) ?></span></div>
        </div>
      </section>
      <aside class="card">
        <div class="card-head"><h2>محفوظ actions</h2><span class="muted">CSRF protected</span></div>
        <div class="card-body operations">
          <section class="operation"><h3>1. GitHub سے sync</h3><p>صرف approved remote اور `<?= e(DEPLOY_BRANCH) ?>` branch سے fetch اور fast-forward pull کیا جائے گا۔ اگر source dirty ہو تو action رک جائے گا۔</p><form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="button" type="submit" name="action" value="sync">GitHub سے sync کریں</button></form></section>
          <section class="operation"><h3>2. Live website publish</h3><p>Current source کی allow-listed web files اور images live root میں copy ہوں گی۔ Live PDFs اور `issues.json` محفوظ رہیں گے۔</p><form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="button warn" type="submit" name="action" value="publish">Live website publish کریں</button></form></section>
        </div>
      </aside>
      <section class="card history">
        <div class="card-head"><h2>حالیہ GitHub commits</h2><span class="muted">Latest 5 commits</span></div>
        <div class="card-body">
          <table class="commit-table"><thead><tr><th>Commit</th><th>تاریخ</th><th>پیغام</th></tr></thead><tbody><?php if ($status['recent']): ?><?php foreach ($status['recent'] as $commit): ?><tr><td><?= e($commit[0]) ?></td><td><?= e($commit[1]) ?></td><td><?= e($commit[2]) ?></td></tr><?php endforeach; ?><?php else: ?><tr><td colspan="3">Commit history دستیاب نہیں ہے۔</td></tr><?php endif; ?></tbody></table>
        </div>
      </section>
      <section class="card">
        <div class="card-head"><h2>Audit trail</h2><span class="muted">External private log</span></div>
        <div class="card-body"><ul class="list"><?php if ($auditEntries): ?><?php foreach ($auditEntries as $entry): ?><li><?= e($entry) ?></li><?php endforeach; ?><?php else: ?><li class="muted">ابھی کوئی action log نہیں ہوا۔</li><?php endif; ?></ul></div>
      </section>
      <section class="card">
        <div class="card-head"><h2>کام کرنے کا طریقہ</h2><span class="muted">Controlled workflow</span></div>
        <div class="card-body"><ol class="workflow"><li>نئی تبدیلی پہلے GitHub branch `<?= e(DEPLOY_BRANCH) ?>` پر commit اور push کریں۔</li><li>اس dashboard میں **GitHub سے sync** چلائیں۔</li><li>clean status confirm ہونے کے بعد **Live website publish کریں**۔</li><li>PDF کا ہفتہ وار شمارہ `admin.php` سے live media folder میں upload کریں؛ publish action اسے overwrite نہیں کرے گا۔</li></ol><p class="footer-note">یہ dashboard جان بوجھ کر commit creation، push، branch switching، destructive reset اور arbitrary shell commands کی اجازت نہیں دیتا۔ یہ پابندی source، PDFs اور hosting account کے تحفظ کے لیے ہے۔</p></div>
      </section>
    </div>
  </main>
<?php endif; ?>
</body>
</html>

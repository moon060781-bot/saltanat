<?php
/*
 * Saltanat News Karachi — protected deployment dashboard.
 * Design reminder: a compact navy-and-cream, commit-centric control surface. Current commit
 * context comes first; restore remains explicit, narrow in scope, and visibly safeguarded.
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

function runGitRaw(array $arguments): array
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

    return [
        'ok' => $code === 0,
        'code' => $code,
        'output' => $code === 0 ? (string) $stdout : trim((string) $stderr),
    ];
}

function approvedRestoreCommits(): array
{
    [$ready] = repositoryReady();
    if (!$ready) {
        return [];
    }

    $history = runGitRaw(['log', '--date=iso-strict', '--format=%H|%h|%cI|%s', DEPLOY_BRANCH]);
    if (!$history['ok']) {
        return [];
    }

    $commits = [];
    foreach (array_filter(explode("\n", trim($history['output']))) as $row) {
        $parts = explode('|', $row, 4);
        if (count($parts) !== 4 || !preg_match('/^[a-f0-9]{40}$/i', $parts[0])) {
            continue;
        }
        $commits[] = [
            'hash' => strtolower($parts[0]),
            'short' => $parts[1],
            'timestamp' => $parts[2],
            'date' => substr($parts[2], 0, 10),
            'subject' => $parts[3],
        ];
    }

    return $commits;
}

function selectedApprovedCommit(string $hash, array $commits): ?array
{
    foreach ($commits as $commit) {
        if (hash_equals($commit['hash'], strtolower($hash))) {
            return $commit;
        }
    }

    return null;
}

function validRestorePath(string $relativePath): bool
{
    if ($relativePath === 'index.html') {
        return true;
    }

    return str_starts_with($relativePath, 'images/')
        && !str_contains($relativePath, '..')
        && !str_contains($relativePath, "\0");
}

function writeLiveContent(string $relativePath, string $content): bool
{
    if (!validRestorePath($relativePath)) {
        return false;
    }

    $destination = DEPLOY_LIVE_ROOT . '/' . $relativePath;
    if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true) && !is_dir(dirname($destination))) {
        return false;
    }

    return file_put_contents($destination, $content, LOCK_EX) !== false;
}

function restoreSelectedCommit(array $commit): array
{
    [$ready, $reason] = repositoryReady();
    if (!$ready) {
        return [false, $reason];
    }
    if (!is_dir(DEPLOY_LIVE_ROOT)) {
        return [false, 'Live document root دستیاب نہیں ہے۔'];
    }

    $commitHash = $commit['hash'];
    $requiredFiles = ['index.html'];
    $imageList = runGit(['ls-tree', '-r', '--name-only', $commitHash, 'images']);
    if (!$imageList['ok']) {
        return [false, 'Selected commit کی file list حاصل نہیں ہو سکی۔'];
    }

    $restorePaths = $requiredFiles;
    foreach (array_filter(explode("\n", trim($imageList['output']))) as $imagePath) {
        $imagePath = trim($imagePath);
        if (validRestorePath($imagePath)) {
            $restorePaths[] = $imagePath;
        }
    }

    foreach (array_unique($restorePaths) as $relativePath) {
        $content = runGitRaw(['show', '--no-textconv', $commitHash . ':' . $relativePath]);
        if (!$content['ok']) {
            return [false, $relativePath . ' selected commit میں موجود نہیں ہے؛ restore روک دیا گیا۔'];
        }
        if (!writeLiveContent($relativePath, (string) $content['output'])) {
            return [false, $relativePath . ' کو live folder میں لکھا نہیں جا سکا۔'];
        }
    }

    return [true, 'Commit ' . $commit['short'] . ' کا public homepage اور images live website پر restore ہو گئے۔ Dashboard، admin controls، server configuration، PDFs اور live archive محفوظ ہیں۔'];
}

function repositoryStatus(): array
{
    $head = runGit(['rev-parse', '--short', 'HEAD']);
    $branch = runGit(['branch', '--show-current']);
    $remote = runGit(['remote', 'get-url', 'origin']);
    $dirty = runGit(['status', '--porcelain=v1', '--untracked-files=all']);
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

    $dirtyEntries = $dirty['ok'] ? array_slice(array_filter(explode("\n", trim($dirty['output']))), 0, 12) : [];

    return [
        'head' => $head['ok'] ? trim($head['output']) : '—',
        'branch' => $branch['ok'] ? trim($branch['output']) : '—',
        'remote' => $remote['ok'] ? trim($remote['output']) : '—',
        'clean' => $dirty['ok'] && trim($dirty['output']) === '',
        'dirtyEntries' => $dirtyEntries,
        'last' => [
            'commit' => $lastParts[0] ?? '—',
            'date' => $lastParts[1] ?? '—',
            'subject' => $lastParts[2] ?? 'Commit information دستیاب نہیں ہے۔',
        ],
        'recent' => $recentRows,
    ];
}

function commitDescriptionLines(string $short, string $timestamp, string $subject): array
{
    return [
        'پیغام: ' . $subject,
        'Commit ID: ' . $short,
        'Branch: ' . DEPLOY_BRANCH,
        'Commit وقت: ' . $timestamp,
        'Status: approved repository history میں موجود ہے۔',
        'Restore scope: صرف public homepage اور images۔',
        'Copy source: approved Git commit سے live website تک۔',
        'محفوظ: deploy.php، admin.php اور server configuration۔',
        'محفوظ: media PDFs اور live issues.json۔',
        'Restore سے پہلے dropdown selection اور confirmation ضروری ہے۔',
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
    } elseif ($action === 'restore') {
        $selectedHash = trim((string) ($_POST['restore_commit'] ?? ''));
        $confirmed = (string) ($_POST['confirm_restore'] ?? '') === 'restore-selected-version';
        $commit = $confirmed ? selectedApprovedCommit($selectedHash, approvedRestoreCommits()) : null;
        if (!$confirmed) {
            $success = false;
            $message = 'Restore سے پہلے selected version confirmation ضروری ہے۔';
        } elseif ($commit === null) {
            $success = false;
            $message = 'منتخب commit approved dropdown history میں موجود نہیں ہے۔';
        } else {
            [$success, $message] = restoreSelectedCommit($commit);
        }
        audit($auditPath, 'restore', $success ? 'success' : 'failed', $commit['short'] ?? 'invalid-selection');
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
$restoreCommits = $loggedIn ? approvedRestoreCommits() : [];
$currentCommitDescription = $loggedIn
    ? commitDescriptionLines((string) $status['last']['commit'], (string) $status['last']['date'], (string) $status['last']['subject'])
    : [];
$csrf = csrfToken();
?><!doctype html>
<html lang="ur" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Saltanat Deploy Dashboard</title>
  <style>
    :root{--navy:#0e1d34;--navy-2:#162b4b;--cream:#f5efe1;--paper:#fffaf0;--ink:#14233d;--line:#d6c5a4;--gold:#c99c55;--mint:#266f6d;--danger:#a04e43;--muted:#657186;--shadow:0 18px 45px rgba(14,29,52,.13)}
    *{box-sizing:border-box} body{margin:0;background:var(--cream);color:var(--ink);font-family:Tahoma,"Noto Nastaliq Urdu",serif;line-height:1.7}.shell{width:min(1180px,calc(100% - 32px));margin:auto}.topbar{background:var(--navy);border-bottom:4px solid var(--gold);color:#fff}.topbar-inner{min-height:74px;display:flex;align-items:center;justify-content:space-between;gap:18px}.eyebrow{margin:0;color:#f0cd90;font:700 11px/1.2 monospace;letter-spacing:.12em}.topbar h1{margin:3px 0 0;font-size:25px;line-height:1.35}.topbar-actions{display:flex;align-items:center;justify-content:flex-end;flex-wrap:wrap;gap:8px}.topbar-actions form{margin:0}.badge{display:inline-flex;align-items:center;gap:6px;border:1px solid rgba(255,255,255,.28);padding:5px 9px;color:#eaf2f5;font:11px/1.3 monospace}.content{padding:28px 0 42px}.flash{margin:0 0 18px;border-right:5px solid;padding:12px 15px;background:var(--paper);box-shadow:var(--shadow);font-size:13px}.flash.ok{border-color:var(--mint);color:#155452}.flash.error{border-color:var(--danger);color:#7e342c}.dashboard-grid{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(300px,.9fr);gap:20px}.card{border:1px solid var(--line);background:var(--paper);box-shadow:var(--shadow)}.card-head{padding:16px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:12px}.card-head h2{margin:0;font-size:20px;line-height:1.4}.card-body{padding:18px}.status-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.metric{border:1px solid #e3d7c1;padding:12px;background:#fffdf8}.metric span{display:block;color:var(--muted);font:11px/1.4 monospace}.metric strong{display:block;margin-top:4px;font-family:"Source Sans 3",Tahoma,sans-serif;font-size:17px;overflow-wrap:anywhere}.state{font-size:13px;font-weight:700}.state.clean{color:var(--mint)}.state.dirty{color:var(--danger)}.commit-note{margin:14px 0 0;padding:12px 14px;background:#f0e5cf;border-right:4px solid var(--gold);font-size:13px}.commit-note strong{display:block}.dirty-diagnostic{margin:12px 0 0;padding:10px 12px;border-right:4px solid var(--danger);background:#fff0ed;color:#72362f;font-size:12px}.dirty-diagnostic strong{display:block;margin-bottom:4px}.dirty-diagnostic code{display:block;direction:ltr;text-align:left;overflow-wrap:anywhere;font:11px/1.7 monospace}.operations{display:grid;gap:12px}.operation{border:1px solid #dfd0b6;padding:15px;background:#fffdf8}.operation h3{margin:0;font-size:18px}.operation p{margin:5px 0 11px;color:#586276;font-size:12px;line-height:1.9}.restore-operation{border-top:4px solid var(--gold);background:#fff9ed}.restore-operation label{display:block;margin:10px 0 5px;font-size:12px;font-weight:700}.restore-operation select{width:100%;padding:9px;border:1px solid #bea477;background:#fffdf8;color:var(--ink);font:12px Tahoma,"Noto Nastaliq Urdu",serif}.restore-confirm{display:flex!important;align-items:flex-start;gap:8px;margin:11px 0!important;color:#714c1a;font-weight:400!important;line-height:1.7}.restore-confirm input{width:16px;height:16px;flex:0 0 auto;margin-top:3px}.restore-note{margin:10px 0 0;padding:9px 10px;border-right:3px solid var(--gold);background:#f2e7d0;color:#5a4b34;font-size:11px;line-height:1.9}.button{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid var(--navy);padding:7px 14px;background:var(--navy);color:#fff;font:700 12px/1.2 Tahoma,"Noto Nastaliq Urdu",serif;text-decoration:none;cursor:pointer;transition:transform .16s ease,background .16s ease}.button:hover{background:var(--navy-2)}.button:active{transform:scale(.98)}.button.secondary{background:transparent;color:var(--navy)}.button.warn{border-color:var(--gold);background:var(--gold);color:#15233a}.button.restore{border-color:var(--danger);background:var(--danger);color:#fff}.button.restore:hover{background:#83382f}.topbar .button.secondary{color:#fff;border-color:rgba(255,255,255,.5)}.topbar .button.secondary:hover{background:rgba(255,255,255,.12)}.list{margin:0;padding:0;list-style:none;display:grid;gap:9px}.list li{border-bottom:1px solid #eadfcd;padding:0 0 9px;font-size:12px}.list li:last-child{border-bottom:0;padding-bottom:0}.muted{color:var(--muted);font-size:12px}.history{grid-column:1/-1}.commit-table{width:100%;border-collapse:collapse;font-size:12px}.commit-table th,.commit-table td{padding:9px 8px;border-bottom:1px solid #e9deca;text-align:right;vertical-align:top}.commit-table th{color:#586276;font-weight:700}.commit-table td:first-child{font-family:monospace;color:var(--mint);font-weight:700}.workflow{margin-top:20px;border-top:2px solid var(--navy);padding:17px 0 0}.workflow h2{margin:0 0 8px;font-size:19px}.workflow ol{margin:0;padding-right:22px;color:#4d576a;font-size:12px;line-height:2}.footer-note{margin-top:20px;color:#657186;font-size:11px}.login-shell{width:min(520px,calc(100% - 30px));margin:9vh auto}.login-card{border:1px solid var(--line);border-top:5px solid var(--gold);background:var(--paper);padding:28px;box-shadow:var(--shadow)}.login-card h1{margin:7px 0;font-size:28px}.login-card p{color:#596376;font-size:13px}.login-card label{display:block;margin:17px 0 5px;font-weight:700}.login-card input{width:100%;border:1px solid #cdbb9d;padding:11px;background:#fffdf8;color:var(--ink);font:16px sans-serif}.login-error{color:#8c3d34;font-size:13px}.security-note{margin-top:18px;padding:11px 12px;border-right:4px solid var(--gold);background:#f0e5cf;color:#5c4b31;font-size:12px;line-height:1.9}@media(max-width:780px){.shell{width:min(100% - 22px,1180px)}.topbar-inner{align-items:flex-start;flex-direction:column;padding:14px 0;gap:9px}.topbar-actions{justify-content:flex-start}.dashboard-grid{grid-template-columns:1fr}.status-grid{grid-template-columns:1fr}.commit-table{display:block;overflow:auto;white-space:nowrap}.card-head{align-items:flex-start;flex-direction:column}.footer-note{line-height:1.9}}
    /* Commit-centric control surface: status + current commit first, restore below. */
    .topbar-inner{min-height:58px}.topbar h1{font-size:22px}.content{padding:14px 0 20px}.flash{margin-bottom:12px;padding:9px 12px;box-shadow:none}.compact-dashboard{display:grid;gap:12px}
    /* ─── STATUS + CURRENT COMMIT (top) ─── */
    .compact-status{padding:13px 15px;border:1px solid var(--line);border-top:4px solid var(--mint);background:var(--paper);box-shadow:var(--shadow)}.status-top-row{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:10px}.status-line{display:flex;align-items:center;flex-wrap:wrap;gap:7px;font:11px/1.5 monospace}.status-line strong{color:var(--mint)}.status-line .separator{color:#c1aa81}.repo-alert{display:block;width:100%;color:#8a382f;font:10px/1.5 monospace;margin-top:4px}.compact-actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px}.compact-actions form{margin:0}.compact-actions .button{min-height:32px;padding:5px 12px}
    .commit-panel{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px;padding-top:10px;border-top:1px solid var(--line)}.commit-highlight{padding:10px 12px;background:#f0e8d4;border-right:4px solid var(--gold)}.commit-highlight .ch-label{color:#7a5c1e;font:700 10px/1.3 monospace;letter-spacing:.08em;margin-bottom:4px}.commit-highlight .ch-hash{font:700 15px/1.2 monospace;color:var(--navy)}.commit-highlight .ch-subject{font-size:13px;line-height:1.55;margin-top:3px;overflow-wrap:anywhere}.commit-highlight .ch-meta{margin-top:5px;color:#6a5a40;font-size:11px;line-height:1.6}.commit-highlight .ch-elapsed{font-weight:700;color:#7a5c1e}.commit-description{padding:10px 12px;background:#faf5ec;border-right:3px solid #d6c5a4}.commit-description .cd-label{color:#7a5c1e;font:700 10px/1.3 monospace;letter-spacing:.08em;margin-bottom:6px}.commit-description ol{margin:0;padding-right:18px;color:#4d576a;font-size:11px;line-height:1.9;counter-reset:cd-counter}.commit-description ol li{list-style:none;counter-increment:cd-counter;padding-bottom:1px;border-bottom:1px solid #ede4d2}.commit-description ol li::before{content:counter(cd-counter) ". ";color:#c99c55;font-weight:700}
    /* ─── RESTORE (bottom) ─── */
    .compact-restore{padding:13px 15px;border:1px solid var(--line);border-top:4px solid var(--gold);background:var(--paper);box-shadow:var(--shadow)}.restore-title-row{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:9px}.restore-title-row h2{margin:0;font-size:19px;line-height:1.3}.restore-title-row span{color:#805716;font:10px/1.4 monospace;white-space:nowrap}.restore-form{display:grid;grid-template-columns:minmax(260px,1fr) minmax(240px,.9fr) max-content;align-items:center;gap:9px}.restore-form select{width:100%;min-width:0;height:38px;padding:6px 9px;border:1px solid #bea477;background:#fffdf8;color:var(--ink);font:12px Tahoma,"Noto Nastaliq Urdu",serif}.restore-form .restore-confirm{display:flex!important;align-items:center;gap:7px;margin:0!important;color:#61471f;font-size:11px;font-weight:400!important;line-height:1.45}.restore-form .restore-confirm input{width:15px;height:15px;flex:0 0 auto;margin:0}.restore-form .button{white-space:nowrap;min-height:38px}.compact-note{margin:8px 0 0;color:#6a5a40;font-size:10px;line-height:1.5}
    .selected-commit-panel{display:none;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px;padding-top:10px;border-top:1px solid var(--line)}.selected-commit-panel.visible{display:grid}.selected-commit-panel .commit-highlight{background:#fff3e0;border-right-color:var(--danger)}.selected-commit-panel .commit-highlight .ch-label{color:#7e3a2c}.selected-commit-panel .commit-highlight .ch-hash{color:var(--danger)}.selected-commit-panel .commit-highlight .ch-elapsed{color:#7e3a2c}.selected-commit-panel .commit-description{background:#fff8f5;border-right-color:#d4a090}
    @media(max-width:780px){.content{padding:11px 0 18px}.compact-status,.compact-restore{padding:12px}.status-top-row{flex-direction:column;align-items:flex-start}.compact-actions{width:100%}.compact-actions form{flex:1}.compact-actions .button{width:100%}.commit-panel,.selected-commit-panel{grid-template-columns:1fr}.restore-title-row{align-items:flex-start;flex-direction:column;gap:3px}.restore-form{grid-template-columns:1fr}.restore-form .button{width:100%}}
  </style>
</head>
<body>
<?php if (!$loggedIn): ?>
  <main class="login-shell">
    <section class="login-card">
      <p class="eyebrow" style="color:#9a763d">SALTANAT · DEPLOY CONTROL</p>
      <h1>محفوظ ڈپلائے ڈیش بورڈ</h1>
      <p>یہ صفحہ صرف مجاز editor کے لیے ہے۔ یہ dashboard arbitrary commands، branch changes یا public secrets کی اجازت نہیں دیتا؛ restore صرف approved commit dropdown سے ممکن ہے۔</p>
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
      <div class="topbar-actions"><span class="badge">ALLOWED BRANCH · <?= e(DEPLOY_BRANCH) ?></span><a class="button secondary" href="index.html">مین پیج</a><a class="button secondary" href="admin.php">ایڈمن پیج</a><form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="button secondary" type="submit" name="action" value="logout">لاگ آؤٹ</button></form></div>
    </div>
  </header>
  <main class="shell content">
    <?php if (is_array($flash)): ?><p class="flash <?= !empty($flash['success']) ? 'ok' : 'error' ?>"><?= e((string) ($flash['message'] ?? '')) ?></p><?php endif; ?>
    <?php
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $lastTs = $status['last']['date'] ?? '';
        $lastSubject = $status['last']['subject'] ?? '';
        $lastCommit = $status['last']['commit'] ?? '—';
        $elapsedHtml = '';
        if ($lastTs && $lastTs !== '—') {
            try {
                $commitDt = new DateTimeImmutable($lastTs, new DateTimeZone('UTC'));
                $diff = $nowUtc->diff($commitDt);
                if ($diff->days > 0) {
                    $elapsedHtml = $diff->days . ' دن پہلے';
                } elseif ($diff->h > 0) {
                    $elapsedHtml = $diff->h . ' گھنٹے پہلے';
                } elseif ($diff->i > 0) {
                    $elapsedHtml = $diff->i . ' منٹ پہلے';
                } else {
                    $elapsedHtml = 'ابھی';
                }
            } catch (\Throwable $ex) {
                $elapsedHtml = '';
            }
        }
        $commitDateDisplay = $lastTs ? substr($lastTs, 0, 10) : '—';
        $commitTimeDisplay = $lastTs ? substr($lastTs, 11, 8) . ' UTC' : '—';
        $restoreCommitsJson = json_encode($restoreCommits, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    ?>
    <div class="compact-dashboard">
      <!-- ─── STATUS + CURRENT COMMIT (top) ─── -->
      <section class="compact-status" aria-label="Repository کی موجودہ حالت">
        <div class="status-top-row">
          <div class="status-line"><strong><?= $status['clean'] ? '● CLEAN' : '● CHANGES FOUND' ?></strong><span class="separator">·</span><span><?= e($status['branch']) ?></span><span class="separator">·</span><span>HEAD <?= e($status['head']) ?></span><?php if (!$status['clean']): ?><span class="repo-alert">Sync سے پہلے repository changes resolve کریں۔ <?= e(implode(' · ', $status['dirtyEntries'])) ?></span><?php endif; ?></div>
          <div class="compact-actions"><form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="button" type="submit" name="action" value="sync">GitHub سے sync</button></form><form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="button warn" type="submit" name="action" value="publish">Live website publish</button></form></div>
        </div>
        <div class="commit-panel">
          <div class="commit-highlight">
            <div class="ch-label">CURRENT COMMIT — HIGHLIGHT</div>
            <div class="ch-hash"><?= e($lastCommit) ?></div>
            <div class="ch-subject"><?= e($lastSubject) ?></div>
            <div class="ch-meta">
              <span><?= e($commitDateDisplay) ?></span> · <span><?= e($commitTimeDisplay) ?></span><br>
              <span class="ch-elapsed"><?= e($elapsedHtml) ?></span>
            </div>
          </div>
          <div class="commit-description">
            <div class="cd-label">EXTENDED DESCRIPTION</div>
            <ol><?php foreach ($currentCommitDescription as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ol>
          </div>
        </div>
      </section>
      <!-- ─── RESTORE (bottom) ─── -->
      <section class="operation restore-operation compact-restore">
        <div class="restore-title-row"><h2>منتخب version restore</h2><span>PUBLIC HOMEPAGE + IMAGES ONLY</span></div>
        <form class="restore-form" method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><select id="restore_commit" name="restore_commit" aria-label="Restore کرنے کے لیے commit منتخب کریں" required><option value="" selected disabled>ایک محفوظ commit منتخب کریں…</option><?php foreach ($restoreCommits as $commit): ?><option value="<?= e($commit['hash']) ?>"><?= e($commit['short']) ?> · <?= e($commit['date']) ?> · <?= e($commit['subject']) ?></option><?php endforeach; ?></select><label class="restore-confirm"><input type="checkbox" name="confirm_restore" value="restore-selected-version" required>منتخب version کو live website پر restore کرنے کی تصدیق</label><button class="button restore" type="submit" name="action" value="restore">منتخب version restore کریں</button></form>
        <div class="selected-commit-panel" id="selected-commit-panel">
          <div class="commit-highlight">
            <div class="ch-label">SELECTED COMMIT — HIGHLIGHT</div>
            <div class="ch-hash" id="sel-hash">—</div>
            <div class="ch-subject" id="sel-subject">—</div>
            <div class="ch-meta">
              <span id="sel-date">—</span> · <span id="sel-time">—</span><br>
              <span class="ch-elapsed" id="sel-elapsed"></span>
            </div>
          </div>
          <div class="commit-description">
            <div class="cd-label">EXTENDED DESCRIPTION</div>
            <ol id="sel-desc-list"></ol>
          </div>
        </div>
        <p class="compact-note">`deploy.php`، `admin.php`، server configuration، PDFs اور live `issues.json` محفوظ رہیں گے۔</p>
      </section>
    </div>
  </main>
  <script>
  (function(){
    var commits=<?= $restoreCommitsJson ?>;
    var idx={};
    commits.forEach(function(c){idx[c.hash]=c;});
    var sel=document.getElementById('restore_commit');
    var panel=document.getElementById('selected-commit-panel');
    var elHash=document.getElementById('sel-hash');
    var elSubject=document.getElementById('sel-subject');
    var elDate=document.getElementById('sel-date');
    var elTime=document.getElementById('sel-time');
    var elElapsed=document.getElementById('sel-elapsed');
    var elList=document.getElementById('sel-desc-list');
    function descLines(c){
      return[
        'پیغام: '+c.subject,
        'Commit ID: '+c.short,
        'Branch: <?= e(DEPLOY_BRANCH) ?>',
        'Commit وقت: '+(c.timestamp||c.date),
        'Status: approved repository history میں موجود ہے۔',
        'Restore scope: صرف public homepage اور images۔',
        'Copy source: approved Git commit سے live website تک۔',
        'محفوظ: deploy.php، admin.php اور server configuration۔',
        'محفوظ: media PDFs اور live issues.json۔',
        'Restore سے پہلے dropdown selection اور confirmation ضروری ہے۔'
      ];
    }
    function elapsed(ts){
      if(!ts)return'';
      var diff=Math.floor((Date.now()-new Date(ts).getTime())/1000);
      if(diff<60)return'ابھی';
      if(diff<3600)return Math.floor(diff/60)+' منٹ پہلے';
      if(diff<86400)return Math.floor(diff/3600)+' گھنٹے پہلے';
      return Math.floor(diff/86400)+' دن پہلے';
    }
    sel.addEventListener('change',function(){
      var c=idx[sel.value];
      if(!c){panel.classList.remove('visible');return;}
      elHash.textContent=c.short;
      elSubject.textContent=c.subject;
      var ts=c.timestamp||c.date||'';
      elDate.textContent=ts.substring(0,10)||'—';
      elTime.textContent=ts.length>10?ts.substring(11,19)+' UTC':'—';
      elElapsed.textContent=elapsed(ts);
      elList.innerHTML='';
      descLines(c).forEach(function(l){var li=document.createElement('li');li.textContent=l;elList.appendChild(li);});
      panel.classList.add('visible');
    });
  })();
  </script>
<?php endif; ?>
</body>
</html>

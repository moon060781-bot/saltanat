<?php
/*
 * Saltanat News Karachi — secure dual-source weekly issue publisher.
 * Design reminder: warm paper and navy internal controls; let the publishing source be explicit,
 * keep each action narrow, and preserve a simple Monday workflow for the editor.
 */
declare(strict_types=1);

$configPath = dirname(__DIR__) . '/cred/slt.env';
$mediaPath = __DIR__ . '/media';
$archivePath = $mediaPath . '/issues.json';
$cspNonce = base64_encode(random_bytes(18));

ini_set('session.use_strict_mode', '1');
if (session_status() === PHP_SESSION_NONE) {
    session_name('saltanat_admin');
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
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'nonce-" . $cspNonce . "'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

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
    if (empty($_SESSION['saltanat_admin_csrf'])) {
        $_SESSION['saltanat_admin_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['saltanat_admin_csrf'];
}

function validCsrf(): bool
{
    return isset($_POST['csrf'], $_SESSION['saltanat_admin_csrf'])
        && is_string($_POST['csrf'])
        && hash_equals((string) $_SESSION['saltanat_admin_csrf'], $_POST['csrf']);
}

function normalizeIssueId(string $value): string
{
    $value = trim($value);
    return preg_match('/^[A-Za-z0-9_-]{1,24}$/', $value) ? $value : '';
}

function normalizeLabel(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($value === '') {
        return '';
    }
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    return $length <= 120 ? $value : '';
}

function parsePublicationDate(string $value): ?DateTimeImmutable
{
    $timezone = new DateTimeZone('Asia/Karachi');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    $hasErrors = is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);
    return $date instanceof DateTimeImmutable && !$hasErrors ? $date : null;
}

function urduDate(DateTimeImmutable $date): string
{
    $months = [
        1 => 'جنوری', 2 => 'فروری', 3 => 'مارچ', 4 => 'اپریل', 5 => 'مئی', 6 => 'جون',
        7 => 'جولائی', 8 => 'اگست', 9 => 'ستمبر', 10 => 'اکتوبر', 11 => 'نومبر', 12 => 'دسمبر',
    ];
    return $date->format('j') . ' ' . $months[(int) $date->format('n')] . ' ' . $date->format('Y');
}

function validGoogleDriveLink(string $rawUrl): ?array
{
    $rawUrl = trim($rawUrl);
    if ($rawUrl === '' || filter_var($rawUrl, FILTER_VALIDATE_URL) === false) {
        return null;
    }

    $parts = parse_url($rawUrl);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
        return null;
    }

    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host !== 'drive.google.com' || isset($parts['user'], $parts['pass'])) {
        return null;
    }

    $path = (string) ($parts['path'] ?? '');
    parse_str((string) ($parts['query'] ?? ''), $query);
    $driveId = '';
    if (preg_match('#/(?:file|document)/d/([A-Za-z0-9_-]{10,})#', $path, $matches)) {
        $driveId = $matches[1];
    } elseif (in_array(trim($path, '/'), ['open', 'uc'], true) && isset($query['id']) && is_string($query['id'])) {
        $driveId = $query['id'];
    }

    if (!preg_match('/^[A-Za-z0-9_-]{10,}$/', $driveId)) {
        return null;
    }

    $resourceKey = isset($query['resourcekey']) && is_string($query['resourcekey']) ? $query['resourcekey'] : '';
    if ($resourceKey !== '' && !preg_match('/^[A-Za-z0-9_-]{5,160}$/', $resourceKey)) {
        return null;
    }

    return [
        'source' => 'google-drive',
        'drive_id' => $driveId,
        'drive_resource_key' => $resourceKey,
        // Retain `file` for the original archive contract; index.html can derive the viewer securely from drive_id.
        'file' => 'https://drive.google.com/file/d/' . $driveId . '/preview' . ($resourceKey !== '' ? '?resourcekey=' . rawurlencode($resourceKey) : ''),
    ];
}

function loadArchive(string $path): array
{
    if (!is_file($path)) {
        return ['issues' => []];
    }
    $archive = json_decode((string) file_get_contents($path), true);
    if (!is_array($archive) || !isset($archive['issues']) || !is_array($archive['issues'])) {
        return ['issues' => []];
    }
    $archive['issues'] = array_values(array_filter($archive['issues'], 'is_array'));
    return $archive;
}

function addIssueToArchive(string $archivePath, array $issue): array
{
    $lockPath = $archivePath . '.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return [false, 'آرکائیو lock نہیں ہو سکا۔ براہ کرم دوبارہ کوشش کریں۔'];
    }

    try {
        $archive = loadArchive($archivePath);
        foreach ($archive['issues'] as $existing) {
            if (hash_equals((string) ($existing['id'] ?? ''), (string) $issue['id'])) {
                return [false, 'یہ شمارہ نمبر پہلے سے آرکائیو میں موجود ہے۔'];
            }
        }

        array_unshift($archive['issues'], $issue);
        $json = json_encode($archive, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $temporaryPath = tempnam(dirname($archivePath), 'issues-');
        if ($temporaryPath === false || file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            if ($temporaryPath !== false && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
            return [false, 'آرکائیو فائل لکھنے کے لیے تیار نہیں ہو سکی۔'];
        }
        if (!rename($temporaryPath, $archivePath)) {
            @unlink($temporaryPath);
            return [false, 'آرکائیو update نہیں ہو سکا۔ media/ permissions چیک کریں۔'];
        }
        @chmod($archivePath, 0644);
        return [true, ''];
    } catch (JsonException $exception) {
        return [false, 'آرکائیو data درست JSON میں تبدیل نہیں ہو سکا۔'];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function uploadErrorMessage(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'PDF فائل hosting کی upload limit سے بڑی ہے۔',
        UPLOAD_ERR_PARTIAL => 'PDF فائل مکمل upload نہیں ہو سکی۔ دوبارہ کوشش کریں۔',
        UPLOAD_ERR_NO_FILE => 'براہ کرم PDF فائل منتخب کریں۔',
        default => 'PDF upload میں مسئلہ پیش آیا۔',
    };
}

function validUploadedPdf(?array $upload): array
{
    if (!is_array($upload) || !isset($upload['error']) || (int) $upload['error'] !== UPLOAD_ERR_OK) {
        return [false, uploadErrorMessage((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE))];
    }
    if (!isset($upload['tmp_name'], $upload['name'], $upload['size']) || !is_uploaded_file((string) $upload['tmp_name'])) {
        return [false, 'Upload کی گئی فائل قابلِ اعتماد نہیں ہے۔'];
    }
    if ((int) $upload['size'] < 5 || (int) $upload['size'] > 50 * 1024 * 1024) {
        return [false, 'PDF فائل 50 MB سے کم ہونی چاہیے۔'];
    }
    if (strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION)) !== 'pdf') {
        return [false, 'صرف .pdf extension والی فائل قبول ہے۔'];
    }
    $signature = file_get_contents((string) $upload['tmp_name'], false, null, 0, 5);
    if ($signature !== '%PDF-') {
        return [false, 'منتخب فائل درست PDF نہیں لگ رہی۔'];
    }
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime = $finfo ? finfo_file($finfo, (string) $upload['tmp_name']) : 'application/pdf';
    if ($finfo) {
        finfo_close($finfo);
    }
    if (!in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
        return [false, 'فائل MIME type PDF نہیں ہے۔'];
    }
    return [true, ''];
}

$adminPassword = readAdminPassword($configPath);
if ($adminPassword === '') {
    http_response_code(503);
    exit('Admin setup is incomplete. Add ADMIN_PASS to /home/noorgeec/cred/slt.env outside the public web directory.');
}

$loggedIn = !empty($_SESSION['saltanat_admin']);
$loginError = '';
$message = null;

if (!$loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!validCsrf()) {
        $loginError = 'Security token درست نہیں ہے۔ براہ کرم صفحہ دوبارہ کھولیں۔';
    } elseif (!empty($_SESSION['saltanat_admin_lock_until']) && time() < (int) $_SESSION['saltanat_admin_lock_until']) {
        $loginError = 'کوششیں عارضی طور پر محدود ہیں۔ چند منٹ بعد دوبارہ کوشش کریں۔';
    } elseif (hash_equals($adminPassword, (string) ($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['saltanat_admin'] = true;
        $_SESSION['saltanat_admin_attempts'] = 0;
        $_SESSION['saltanat_admin_csrf'] = bin2hex(random_bytes(32));
        header('Location: admin.php');
        exit;
    } else {
        $attempts = (int) ($_SESSION['saltanat_admin_attempts'] ?? 0) + 1;
        $_SESSION['saltanat_admin_attempts'] = $attempts;
        if ($attempts >= 5) {
            $_SESSION['saltanat_admin_lock_until'] = time() + 600;
        }
        $loginError = 'پاس ورڈ درست نہیں ہے۔';
    }
}

if ($loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!validCsrf()) {
        http_response_code(403);
        exit('Security token invalid. Reload the page and try again.');
    }
    session_unset();
    session_destroy();
    header('Location: admin.php');
    exit;
}

if ($loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish_issue'])) {
    if (!validCsrf()) {
        $message = ['type' => 'error', 'text' => 'Security token درست نہیں ہے۔ براہ کرم صفحہ دوبارہ کھولیں۔'];
    } else {
        $source = (string) ($_POST['source'] ?? '');
        $issueId = normalizeIssueId((string) ($_POST['issue_id'] ?? ''));
        $label = normalizeLabel((string) ($_POST['label'] ?? ''));
        $date = parsePublicationDate((string) ($_POST['date'] ?? ''));

        if (!in_array($source, ['hosting', 'google-drive'], true)) {
            $message = ['type' => 'error', 'text' => 'Publish source درست منتخب نہیں کیا گیا۔'];
        } elseif ($issueId === '' || $label === '' || !$date) {
            $message = ['type' => 'error', 'text' => 'درست شمارہ نمبر، عنوان اور اشاعت کی تاریخ لازمی ہیں۔'];
        } elseif (!is_dir($mediaPath) && !mkdir($mediaPath, 0755, true) && !is_dir($mediaPath)) {
            $message = ['type' => 'error', 'text' => 'media/ folder تیار نہیں ہو سکا۔'];
        } else {
            $baseIssue = [
                'id' => $issueId,
                'label' => $label,
                'date' => urduDate($date),
                'source' => $source,
            ];

            if ($source === 'google-drive') {
                $drive = validGoogleDriveLink((string) ($_POST['drive_url'] ?? ''));
                if ($drive === null) {
                    $message = ['type' => 'error', 'text' => 'صرف درست Google Drive PDF share link قبول ہے۔ Link میں drive.google.com/file/d/... یا drive.google.com/open?id=... ہونا چاہیے۔'];
                } else {
                    $issue = array_merge($baseIssue, $drive);
                    [$saved, $saveMessage] = addIssueToArchive($archivePath, $issue);
                    $message = $saved
                        ? ['type' => 'success', 'text' => 'Google Drive شمارہ آرکائیو میں شامل ہو گیا۔ Drive sharing “Anyone with the link — Viewer” ضرور رکھیں۔']
                        : ['type' => 'error', 'text' => $saveMessage];
                }
            } else {
                $upload = $_FILES['pdf'] ?? null;
                [$isPdf, $pdfMessage] = validUploadedPdf(is_array($upload) ? $upload : null);
                if (!$isPdf) {
                    $message = ['type' => 'error', 'text' => $pdfMessage];
                } else {
                    $safeFile = 'Saltanat-' . $issueId . '_' . strtolower($date->format('dMy')) . '.pdf';
                    $destination = $mediaPath . '/' . $safeFile;
                    if (is_file($destination)) {
                        $message = ['type' => 'error', 'text' => 'اسی نام کا PDF پہلے سے موجود ہے۔ شمارہ نمبر یا تاریخ چیک کریں۔'];
                    } elseif (!move_uploaded_file((string) $upload['tmp_name'], $destination)) {
                        $message = ['type' => 'error', 'text' => 'PDF فائل محفوظ نہیں ہو سکی۔ media/ folder permissions چیک کریں۔'];
                    } else {
                        @chmod($destination, 0644);
                        $issue = array_merge($baseIssue, ['file' => $safeFile]);
                        [$saved, $saveMessage] = addIssueToArchive($archivePath, $issue);
                        if (!$saved) {
                            @unlink($destination);
                            $message = ['type' => 'error', 'text' => $saveMessage];
                        } else {
                            $message = ['type' => 'success', 'text' => 'PDF hosting server پر upload ہو گیا اور آرکائیو میں شامل ہو گیا۔'];
                        }
                    }
                }
            }
        }
    }
}

$csrf = csrfToken();
?><!doctype html>
<html lang="ur" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#16213e">
  <title>Saltanat Admin — ہفتہ وار شمارہ</title>
  <style>
    /* Saltanat admin: warm Urdu paper interface; clear source choice and one-step weekly publishing. */
    :root{--navy:#16213e;--navy-2:#24355a;--paper:#f7f0e2;--card:#fffaf0;--line:#d9c9ae;--gold:#b7823a;--teal:#17656a;--red:#8e3b32;--muted:#4a5870;--shadow:0 16px 40px rgba(54,42,22,.10)}
    *{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--navy);font-family:Tahoma,"Noto Nastaliq Urdu",serif;line-height:1.8}.shell{width:min(720px,calc(100% - 30px));margin:7vh auto}.card{border:1px solid var(--line);border-top:5px solid var(--gold);background:var(--card);padding:26px 28px;box-shadow:var(--shadow)}h1{margin:0;font-size:29px;line-height:1.4}.eyebrow{margin:0 0 5px;color:var(--gold);font:700 11px/1.3 monospace;letter-spacing:.1em}.intro{margin:8px 0 16px;color:var(--muted);font-size:13px}.notice{margin:0 0 15px;padding:10px 12px;border-right:4px solid;background:#f3ead8;font-size:12px}.notice.success{border-color:var(--teal);color:#15575a}.notice.error{border-color:var(--red);color:#7d3029}.field{margin-top:13px}.field label,.source-heading{display:block;margin:0 0 5px;font-weight:700;font-size:13px}input{width:100%;border:1px solid #cdbb9d;background:#fffdf7;color:var(--navy);padding:10px 11px;font:14px Tahoma,"Noto Nastaliq Urdu",serif}input:focus{outline:3px solid rgba(183,130,58,.24);border-color:var(--gold)}fieldset{margin:18px 0 0;border:1px solid var(--line);padding:12px 14px 14px;background:#fcf6ea}legend{padding:0 6px;color:var(--navy);font-size:13px;font-weight:700}.source-option{display:flex!important;align-items:flex-start;gap:9px;margin:0 0 7px!important;font-size:13px}.source-option:last-child{margin-bottom:0!important}.source-option input{width:17px;height:17px;flex:0 0 auto;margin:4px 0 0}.source-copy{display:block;color:var(--muted);font-size:11px;line-height:1.7}.source-panel{margin-top:14px;padding:13px;border:1px solid #ddc9a4;background:#fffdf8}.source-panel[hidden]{display:none}.help{margin:6px 0 0;color:#59667b;font-size:11px;line-height:1.8}.drive-note{border-right:3px solid var(--gold);padding:8px 10px;background:#f7eedc;color:#5b4b32}.button-row{display:flex;flex-wrap:wrap;align-items:center;gap:9px;margin-top:19px}.button{display:inline-flex;align-items:center;justify-content:center;min-height:39px;border:1px solid var(--navy);padding:8px 15px;background:var(--navy);color:#fff;font:700 13px/1.2 Tahoma,"Noto Nastaliq Urdu",serif;text-decoration:none;cursor:pointer;transition:background .16s ease,transform .16s ease}.button:hover{background:var(--navy-2)}.button:active{transform:scale(.98)}.button.secondary{background:transparent;color:var(--navy)}.button.logout{border-color:#a77764;color:#7d3029}.source-badge{display:inline-block;margin-top:5px;padding:2px 7px;border:1px solid #cdbb9d;background:#f4ead6;color:#5e4c31;font:10px/1.4 monospace}.small-print{margin:17px 0 0;color:#657186;font-size:10px;line-height:1.8}@media(max-width:560px){.shell{width:min(100% - 20px,720px);margin:18px auto}.card{padding:21px 17px}h1{font-size:25px}.button-row{align-items:stretch;flex-direction:column}.button{width:100%}}
  </style>
</head>
<body>
<main class="shell">
  <section class="card">
    <?php if (!$loggedIn): ?>
      <p class="eyebrow">SALTANAT · WEEKLY PUBLISHER</p>
      <h1>سلطنت نیوز — Admin</h1>
      <p class="intro">یہ page صرف مجاز editor کے لیے ہے۔ Password public web folder سے باہر محفوظ ہے۔</p>
      <?php if ($loginError !== ''): ?><p class="notice error"><?= e($loginError) ?></p><?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="login" value="1">
        <div class="field"><label for="password">پاس ورڈ</label><input id="password" type="password" name="password" required autofocus></div>
        <div class="button-row"><button class="button" type="submit">لاگ اِن</button><a class="button secondary" href="index.html">ہوم پیج</a></div>
      </form>
    <?php else: ?>
      <p class="eyebrow">SALTANAT · WEEKLY PUBLISHER</p>
      <h1>نیا ہفتہ وار شمارہ</h1>
      <p class="intro">PDF کو اپنے hosting server پر upload کریں یا Google Drive میں رکھ کر اس کا shareable link دیں۔ دونوں صورتوں میں تازہ شمارہ archive کے شروع میں شامل ہو جائے گا۔</p>
      <?php if (is_array($message)): ?><p class="notice <?= e((string) $message['type']) ?>"><?= e((string) $message['text']) ?></p><?php endif; ?>
      <form method="post" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <div class="field"><label for="issue_id">شمارہ نمبر</label><input id="issue_id" name="issue_id" placeholder="مثلاً 11" pattern="[A-Za-z0-9_-]{1,24}" required></div>
        <div class="field"><label for="label">عنوان</label><input id="label" name="label" placeholder="مثلاً شمارہ 11" maxlength="120" required></div>
        <div class="field"><label for="date">اشاعت کی تاریخ</label><input id="date" type="date" name="date" value="<?= e((new DateTimeImmutable('now', new DateTimeZone('Asia/Karachi')))->format('Y-m-d')) ?>" required></div>
        <fieldset>
          <legend>PDF کا ماخذ منتخب کریں</legend>
          <label class="source-option"><input type="radio" name="source" value="hosting" checked><span><strong>Hosting server پر PDF upload کریں</strong><span class="source-copy">PDF `media/` folder میں محفوظ ہوگی اور server storage استعمال ہوگا۔</span></span></label>
          <label class="source-option"><input type="radio" name="source" value="google-drive"><span><strong>Google Drive کا PDF link دیں</strong><span class="source-copy">PDF Drive پر رہے گی؛ hosting storage استعمال نہیں ہوگا۔</span></span></label>
        </fieldset>
        <section id="hosting-panel" class="source-panel" aria-labelledby="hosting-source-title">
          <label id="hosting-source-title" for="pdf">PDF فائل</label>
          <input id="pdf" type="file" name="pdf" accept="application/pdf,.pdf" required>
          <p class="help">صرف حقیقی PDF فائل قبول ہے؛ زیادہ سے زیادہ 50 MB۔ خودکار نام: <span class="source-badge">Saltanat-{issue}_ddmmmyy.pdf</span></p>
        </section>
        <section id="drive-panel" class="source-panel" aria-labelledby="drive-source-title" hidden>
          <label id="drive-source-title" for="drive_url">Google Drive PDF share link</label>
          <input id="drive_url" type="url" name="drive_url" inputmode="url" placeholder="https://drive.google.com/file/d/FILE_ID/view?usp=sharing">
          <p class="help drive-note">Drive میں PDF کی sharing پہلے <strong>Anyone with the link — Viewer</strong> رکھیں۔ صرف `drive.google.com` یا `docs.google.com` کی recognized PDF links قبول ہوں گی۔</p>
        </section>
        <div class="button-row"><button id="publish-button" class="button" type="submit" name="publish_issue" value="1">PDF upload اور شائع کریں</button><a class="button secondary" href="index.html">ویب سائٹ دیکھیں</a></div>
      </form>
      <form method="post" class="button-row"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="button secondary logout" type="submit" name="logout" value="1">لاگ آؤٹ</button></form>
      <p class="small-print">Existing local archive issues بدستور کام کریں گے۔ Google Drive file delete، move یا private کرنے سے reader میں وہ شمارہ دستیاب نہیں رہے گا۔</p>
    <?php endif; ?>
  </section>
</main>
<?php if ($loggedIn): ?>
<script nonce="<?= e($cspNonce) ?>">
  (function () {
    const sourceInputs = Array.from(document.querySelectorAll('input[name="source"]'));
    const hostingPanel = document.getElementById('hosting-panel');
    const drivePanel = document.getElementById('drive-panel');
    const pdf = document.getElementById('pdf');
    const driveUrl = document.getElementById('drive_url');
    const publishButton = document.getElementById('publish-button');
    function updateSource() {
      const selected = sourceInputs.find((input) => input.checked);
      const isDrive = selected && selected.value === 'google-drive';
      hostingPanel.hidden = isDrive;
      drivePanel.hidden = !isDrive;
      pdf.required = !isDrive;
      driveUrl.required = isDrive;
      publishButton.textContent = isDrive ? 'Google Drive شمارہ شائع کریں' : 'PDF upload اور شائع کریں';
    }
    sourceInputs.forEach((input) => input.addEventListener('change', updateSource));
    updateSource();
  }());
</script>
<?php endif; ?>
</body>
</html>

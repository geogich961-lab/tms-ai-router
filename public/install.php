<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [];
$checks['PHP >= 8.0'] = PHP_VERSION_ID >= 80000;
$checks['SQLite3'] = class_exists('SQLite3');
$checks['cURL'] = function_exists('curl_init');
$checks['Sodium hoặc OpenSSL'] = function_exists('sodium_crypto_secretbox') || function_exists('openssl_encrypt');
$checks['config/app.php'] = is_file($root . '/config/app.php');
$checks['database/schema.sql'] = is_file($root . '/database/schema.sql');
$checks['app/Core/App.php'] = is_file($root . '/app/Core/App.php');
$checks['app/Core/Auth.php'] = is_file($root . '/app/Core/Auth.php');

$storagePath = $root . '/storage';
if (!is_dir($storagePath)) {
    @mkdir($storagePath, 0700, true);
}
$checks['Storage có thể ghi'] = is_dir($storagePath) && is_writable($storagePath);

$ready = !in_array(false, $checks, true);
$error = '';
$success = false;
$alreadyInstalled = false;
$csrf = '';

if ($ready) {
    require_once $root . '/app/Core/App.php';
    require_once $root . '/app/Core/Auth.php';

    try {
        \TmsAi\Core\App::boot();
        $alreadyInstalled = \TmsAi\Core\Auth::hasAdmin();
        $csrf = \TmsAi\Core\App::csrf();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyInstalled) {
            \TmsAi\Core\App::verifyCsrf();
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');

            if (strlen($username) < 3) {
                $error = 'Tên đăng nhập phải có ít nhất 3 ký tự.';
            } elseif (strlen($password) < 8) {
                $error = 'Mật khẩu phải có ít nhất 8 ký tự.';
            } elseif ($password !== $confirm) {
                $error = 'Mật khẩu xác nhận không khớp.';
            } elseif (!\TmsAi\Core\Auth::setup($username, $password)) {
                $error = 'Không thể tạo tài khoản quản trị.';
            } else {
                \TmsAi\Core\Auth::login($username, $password);
                @file_put_contents($storagePath . '/install.lock', json_encode([
                    'installed_at' => time(),
                    'version' => (string)\TmsAi\Core\App::config('version', 'unknown'),
                ], JSON_UNESCAPED_SLASHES));
                $success = true;
                $alreadyInstalled = true;
            }
        }
    } catch (Throwable $e) {
        $ready = false;
        $error = $e->getMessage();
    }
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $scheme . '://' . $host;
$docRootHint = str_replace('\\', '/', $root . '/public');
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cài đặt TMS AI Router</title>
<style>
:root{color-scheme:light;--bg:#f5f7fb;--card:#fff;--text:#172033;--muted:#667085;--line:#e4e7ec;--accent:#ef4444;--ok:#16a34a;--bad:#dc2626}
*{box-sizing:border-box}body{margin:0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:var(--bg);color:var(--text)}
.wrap{max-width:980px;margin:40px auto;padding:0 18px}.hero{margin-bottom:22px}.eyebrow{font-size:12px;font-weight:800;letter-spacing:.12em;color:var(--accent);text-transform:uppercase}.hero h1{font-size:32px;margin:7px 0 8px}.hero p{margin:0;color:var(--muted);line-height:1.6}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:22px;box-shadow:0 8px 30px rgba(16,24,40,.05)}.card h2{margin:0 0 16px;font-size:18px}
.check{display:flex;align-items:center;justify-content:space-between;padding:11px 0;border-bottom:1px solid var(--line);gap:12px}.check:last-child{border-bottom:0}.badge{font-size:12px;font-weight:800;padding:5px 9px;border-radius:999px}.ok{background:#ecfdf3;color:var(--ok)}.bad{background:#fef2f2;color:var(--bad)}
label{display:block;font-size:13px;font-weight:700;margin:14px 0 7px}input{width:100%;padding:12px 13px;border:1px solid #d0d5dd;border-radius:10px;font-size:14px;outline:none}input:focus{border-color:#98a2b3;box-shadow:0 0 0 3px rgba(152,162,179,.16)}button,.btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:10px;padding:12px 16px;font-weight:800;font-size:14px;text-decoration:none;cursor:pointer}.primary{background:#111827;color:#fff;width:100%;margin-top:18px}.secondary{background:#f2f4f7;color:#344054}.alert{padding:12px 14px;border-radius:10px;margin:0 0 14px;font-size:14px;line-height:1.5}.alert.bad{background:#fef2f2;color:#991b1b}.alert.ok{background:#ecfdf3;color:#166534}.hint{background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px;padding:12px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;word-break:break-all;color:#475467}.small{font-size:12px;color:var(--muted);line-height:1.6;margin-top:12px}.done{text-align:center;padding:22px 8px}.done .icon{font-size:40px}.done h2{font-size:24px;margin:8px 0}.done p{color:var(--muted);line-height:1.6}.actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:18px}
@media(max-width:760px){.wrap{margin:20px auto}.grid{grid-template-columns:1fr}.hero h1{font-size:26px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="hero">
    <div class="eyebrow">TMS AI Router</div>
    <h1>Trình cài đặt Web</h1>
    <p>Dùng khi bạn upload TMS AI Router lên TMS OS hoặc hosting PHP như một website bình thường.</p>
  </div>

  <?php if ($success): ?>
    <div class="card done">
      <div class="icon">✓</div>
      <h2>Cài đặt hoàn tất</h2>
      <p>Database đã được khởi tạo và tài khoản Admin đã được tạo. Bạn đang được đăng nhập tự động.</p>
      <div class="actions">
        <a class="btn primary" style="width:auto" href="/">Mở Dashboard</a>
        <a class="btn secondary" href="/health">Kiểm tra Health</a>
      </div>
      <div class="small">Vì lý do bảo mật, sau khi cài đặt bạn có thể xóa file <b>public/install.php</b>. Nếu giữ lại, installer sẽ tự khóa khi đã có tài khoản Admin.</div>
    </div>
  <?php else: ?>
    <div class="grid">
      <div class="card">
        <h2>1. Kiểm tra môi trường</h2>
        <?php foreach ($checks as $name => $ok): ?>
          <div class="check"><span><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></span><span class="badge <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'OK' : 'THIẾU' ?></span></div>
        <?php endforeach; ?>
        <div class="small">Document Root của website nên trỏ tới:</div>
        <div class="hint"><?= htmlspecialchars($docRootHint, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="small">URL hiện tại: <b><?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?></b></div>
      </div>

      <div class="card">
        <h2>2. Tạo tài khoản Admin</h2>
        <?php if ($error !== ''): ?><div class="alert bad"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <?php if ($alreadyInstalled): ?>
          <div class="alert ok">TMS AI Router đã được cài đặt trên database này.</div>
          <div class="actions">
            <a class="btn primary" style="width:auto" href="/">Mở Dashboard</a>
            <a class="btn secondary" href="/login">Đăng nhập</a>
          </div>
        <?php elseif (!$ready): ?>
          <div class="alert bad">Môi trường chưa đạt yêu cầu. Hãy xử lý các mục “THIẾU” ở cột bên trái rồi tải lại trang.</div>
        <?php else: ?>
          <form method="post" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <label for="username">Tên đăng nhập</label>
            <input id="username" name="username" minlength="3" maxlength="64" required placeholder="admin">
            <label for="password">Mật khẩu</label>
            <input id="password" type="password" name="password" minlength="8" required placeholder="Tối thiểu 8 ký tự">
            <label for="confirm_password">Nhập lại mật khẩu</label>
            <input id="confirm_password" type="password" name="confirm_password" minlength="8" required placeholder="Nhập lại mật khẩu">
            <button class="primary" type="submit">Cài đặt TMS AI Router</button>
          </form>
          <div class="small">Installer sẽ tự tạo SQLite database, schema, thư mục storage/secure/logs/cache và tài khoản quản trị đầu tiên.</div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>

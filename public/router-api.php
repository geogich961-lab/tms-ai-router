<?php
declare(strict_types=1);

foreach ([
    'app/Core/App.php','app/Core/Auth.php','app/Core/Crypto.php',
    'app/Services/ProviderService.php','app/Services/UsageService.php','app/Services/ClientKeyService.php',
    'app/Services/GatewayService.php','app/Services/UpdateService.php'
] as $f) require dirname(__DIR__) . '/' . $f;

use TmsAi\Core\App;
use TmsAi\Core\Auth;
use TmsAi\Services\ProviderService;
use TmsAi\Services\UsageService;
use TmsAi\Services\ClientKeyService;
use TmsAi\Services\UpdateService;

try {
    App::boot();
    Auth::requireAdmin();

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        App::json(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $data = App::inputJson();
    $action = trim((string)($data['_action'] ?? ''));
    $csrf = (string)($data['_csrf'] ?? '');
    unset($data['_action'], $data['_csrf']);

    if ($csrf === '' || !hash_equals(App::csrf(), $csrf)) {
        App::json(['ok' => false, 'error' => 'CSRF token không hợp lệ.'], 419);
    }

    $providers = new ProviderService();
    $usage = new UsageService();
    $keys = new ClientKeyService();
    $updates = new UpdateService();

    switch ($action) {
        case 'ping':
            App::json(['ok' => true, 'data' => ['version' => App::config('version'), 'time' => time()]]);
        case 'status':
            App::json(['ok' => true, 'data' => $usage->summary()]);
        case 'provider-get':
            $r = $providers->find((int)($data['id'] ?? 0));
            if (!$r) App::json(['ok' => false, 'error' => 'Provider không tồn tại.'], 404);
            $r['api_key'] = $r['api_key'] !== '' ? '••••••••' : '';
            App::json(['ok' => true, 'data' => $r]);
        case 'provider-save':
            try { App::json(['ok' => true, 'id' => $providers->save($data)]); }
            catch (Throwable $e) { App::json(['ok' => false, 'error' => $e->getMessage()], 422); }
        case 'provider-delete':
            $providers->delete((int)($data['id'] ?? 0));
            App::json(['ok' => true]);
        case 'key-create':
            App::json(['ok' => true, 'key' => $keys->create((string)($data['name'] ?? 'Default'))]);
        case 'key-revoke':
            $keys->revoke((int)($data['id'] ?? 0));
            App::json(['ok' => true]);
        case 'settings-save':
            $v = (string)($data['routing_strategy'] ?? 'priority');
            if (!in_array($v, ['priority','round_robin','least_used','quota_first'], true)) $v = 'priority';
            $s = App::db()->prepare("INSERT INTO settings(key,value) VALUES('routing_strategy',:v) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
            $s->bindValue(':v', $v, SQLITE3_TEXT);
            $s->execute();
            App::json(['ok' => true]);
        case 'update-check':
            try { App::json(['ok' => true, 'update' => $updates->check()]); }
            catch (Throwable $e) { App::json(['ok' => false, 'error' => $e->getMessage()], 502); }
        case 'update-apply':
            try { App::json($updates->apply()); }
            catch (Throwable $e) { App::json(['ok' => false, 'error' => $e->getMessage()], 500); }
        default:
            App::json(['ok' => false, 'error' => 'Dashboard action không hợp lệ.'], 404);
    }
} catch (Throwable $e) {
    App::json(['ok' => false, 'error' => $e->getMessage()], 500);
}

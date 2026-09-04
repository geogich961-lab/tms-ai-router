<?php
declare(strict_types=1);
namespace TmsAi\Services;

use TmsAi\Core\App;
use TmsAi\Core\Crypto;

final class ProviderService
{
    public function all(): array
    {
        $o = []; $r = App::db()->query('SELECT * FROM providers ORDER BY priority,id');
        while ($x = $r->fetchArray(SQLITE3_ASSOC)) { unset($x['api_key_enc']); $x['models'] = $this->models((int)$x['id']); $x['quotas'] = $this->quotas((int)$x['id']); $o[] = $x; }
        return $o;
    }

    public function find(int $id): ?array
    {
        $s = App::db()->prepare('SELECT * FROM providers WHERE id=:id'); $s->bindValue(':id', $id, SQLITE3_INTEGER); $x = $s->execute()?->fetchArray(SQLITE3_ASSOC);
        if (!$x) return null; $x['api_key'] = Crypto::decrypt((string)$x['api_key_enc']); $x['models'] = $this->models($id); $x['quotas'] = $this->quotas($id); return $x;
    }

    public function models(int $id): array
    {
        $s = App::db()->prepare('SELECT public_model,upstream_model,enabled FROM provider_models WHERE provider_id=:id ORDER BY public_model'); $s->bindValue(':id', $id, SQLITE3_INTEGER); $r = $s->execute(); $o = [];
        while ($x = $r?->fetchArray(SQLITE3_ASSOC)) $o[] = $x; return $o;
    }

    public function quotas(int $id): array
    {
        $s = App::db()->prepare('SELECT * FROM quota_windows WHERE provider_id=:id ORDER BY window_seconds'); $s->bindValue(':id', $id, SQLITE3_INTEGER); $r = $s->execute(); $o = [];
        while ($x = $r?->fetchArray(SQLITE3_ASSOC)) $o[] = $x; return $o;
    }

    public function save(array $d): int
    {
        $id = (int)($d['id'] ?? 0); $name = trim((string)($d['name'] ?? '')); $base = rtrim(trim((string)($d['base_url'] ?? '')), '/'); $secret = trim((string)($d['api_key'] ?? ''));
        $kind = (string)($d['kind'] ?? 'openai_compatible'); if (!in_array($kind, ['openai_compatible', 'anthropic', 'gemini'], true)) $kind = 'openai_compatible';
        if ($name === '' || $base === '' || !preg_match('#^https?://#i', $base)) throw new \InvalidArgumentException('Tên và Base URL hợp lệ là bắt buộc.');
        $now = time();
        if ($id > 0) {
            $cur = $this->find($id); if (!$cur) throw new \InvalidArgumentException('Provider không tồn tại.');
            $enc = $secret !== '' ? Crypto::encrypt($secret) : Crypto::encrypt((string)$cur['api_key']);
            $s = App::db()->prepare('UPDATE providers SET name=:n,kind=:k,base_url=:b,api_key_enc=:a,enabled=:e,priority=:p,weight=:w,timeout_seconds=:t,updated_at=:u WHERE id=:id'); $s->bindValue(':id', $id, SQLITE3_INTEGER);
        } else {
            $enc = Crypto::encrypt($secret);
            $s = App::db()->prepare('INSERT INTO providers(name,kind,base_url,api_key_enc,enabled,priority,weight,timeout_seconds,created_at,updated_at) VALUES(:n,:k,:b,:a,:e,:p,:w,:t,:c,:u)'); $s->bindValue(':c', $now, SQLITE3_INTEGER);
        }
        $s->bindValue(':n', $name, SQLITE3_TEXT); $s->bindValue(':k', $kind, SQLITE3_TEXT); $s->bindValue(':b', $base, SQLITE3_TEXT); $s->bindValue(':a', $enc, SQLITE3_TEXT); $s->bindValue(':e', !empty($d['enabled']) ? 1 : 0, SQLITE3_INTEGER); $s->bindValue(':p', max(1, (int)($d['priority'] ?? 100)), SQLITE3_INTEGER); $s->bindValue(':w', max(1, (int)($d['weight'] ?? 100)), SQLITE3_INTEGER); $s->bindValue(':t', max(10, min(600, (int)($d['timeout_seconds'] ?? 120))), SQLITE3_INTEGER); $s->bindValue(':u', $now, SQLITE3_INTEGER); $s->execute();
        if ($id <= 0) $id = (int)App::db()->lastInsertRowID();

        App::db()->exec('DELETE FROM provider_models WHERE provider_id=' . $id);
        foreach (preg_split('/[\r\n,]+/', (string)($d['models'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $p = array_map('trim', explode('=', $line, 2)); if (empty($p[0])) continue;
            $m = App::db()->prepare('INSERT INTO provider_models(provider_id,public_model,upstream_model,enabled) VALUES(:p,:m,:u,1)'); $m->bindValue(':p', $id, SQLITE3_INTEGER); $m->bindValue(':m', $p[0], SQLITE3_TEXT); $m->bindValue(':u', $p[1] ?? $p[0], SQLITE3_TEXT); $m->execute();
        }

        App::db()->exec('DELETE FROM quota_windows WHERE provider_id=' . $id); $qs = json_decode((string)($d['quotas_json'] ?? '[]'), true);
        if (is_array($qs)) foreach ($qs as $q) {
            if (!is_array($q) || empty($q['label']) || empty($q['window_seconds'])) continue;
            $z = App::db()->prepare('INSERT INTO quota_windows(provider_id,label,window_seconds,token_limit,request_limit,reset_mode,reset_anchor,enabled) VALUES(:p,:l,:w,:t,:r,:m,:a,1)');
            foreach ([':p' => $id, ':w' => max(60, (int)$q['window_seconds']), ':t' => max(0, (int)($q['token_limit'] ?? 0)), ':r' => max(0, (int)($q['request_limit'] ?? 0)), ':a' => max(0, (int)($q['reset_anchor'] ?? 0))] as $k => $v) $z->bindValue($k, $v, SQLITE3_INTEGER);
            $z->bindValue(':l', (string)$q['label'], SQLITE3_TEXT); $z->bindValue(':m', in_array(($q['reset_mode'] ?? ''), ['rolling', 'fixed'], true) ? $q['reset_mode'] : 'rolling', SQLITE3_TEXT); $z->execute();
        }
        return $id;
    }

    public function delete(int $id): void { App::db()->exec('DELETE FROM providers WHERE id=' . max(0, $id)); }
    public function publicModels(): array
    {
        $r = App::db()->query('SELECT DISTINCT public_model FROM provider_models pm JOIN providers p ON p.id=pm.provider_id WHERE p.enabled=1 AND pm.enabled=1 ORDER BY public_model'); $o = [];
        while ($x = $r->fetchArray(SQLITE3_ASSOC)) $o[] = ['id' => $x['public_model'], 'object' => 'model', 'created' => 0, 'owned_by' => 'tms-ai-router']; return $o;
    }
}

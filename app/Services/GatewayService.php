<?php
declare(strict_types=1);
namespace TmsAi\Services;

use TmsAi\Core\App;

final class GatewayService
{
    public function __construct(private ProviderService $providers, private UsageService $usage) {}

    public function chat(array $payload, array $client): array
    {
        if (!empty($payload['stream'])) {
            $this->streamChat($payload, $client);
        }
        return $this->runChat($payload, $client);
    }

    public function responses(array $payload, array $client): array
    {
        if (!empty($payload['stream'])) {
            return ['status' => 400, 'body' => ['error' => ['message' => 'Streaming /v1/responses chưa hỗ trợ ở v1.1.0; hãy dùng /v1/chat/completions.', 'type' => 'unsupported_feature']]];
        }
        $model = trim((string)($payload['model'] ?? ''));
        $input = $payload['input'] ?? '';
        $messages = [];
        if (is_string($input)) $messages[] = ['role' => 'user', 'content' => $input];
        elseif (is_array($input)) {
            foreach ($input as $item) {
                if (is_string($item)) $messages[] = ['role' => 'user', 'content' => $item];
                elseif (is_array($item) && isset($item['role'])) $messages[] = ['role' => (string)$item['role'], 'content' => $this->contentText($item['content'] ?? '')];
            }
        }
        $chat = ['model' => $model, 'messages' => $messages, 'temperature' => $payload['temperature'] ?? null, 'max_tokens' => $payload['max_output_tokens'] ?? 1024];
        $r = $this->runChat(array_filter($chat, static fn($v) => $v !== null), $client);
        if ($r['status'] < 200 || $r['status'] >= 300) return $r;
        $body = $r['body'];
        $text = (string)($body['choices'][0]['message']['content'] ?? '');
        return ['status' => 200, 'body' => [
            'id' => 'resp_' . bin2hex(random_bytes(10)), 'object' => 'response', 'created_at' => time(), 'status' => 'completed', 'model' => $model,
            'output' => [['type' => 'message', 'id' => 'msg_' . bin2hex(random_bytes(8)), 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $text]]]],
            'usage' => ['input_tokens' => (int)($body['usage']['prompt_tokens'] ?? 0), 'output_tokens' => (int)($body['usage']['completion_tokens'] ?? 0), 'total_tokens' => (int)($body['usage']['total_tokens'] ?? 0)]
        ]];
    }

    private function runChat(array $p, array $client): array
    {
        $model = trim((string)($p['model'] ?? ''));
        if ($model === '') return ['status' => 400, 'body' => ['error' => ['message' => 'model là bắt buộc.', 'type' => 'invalid_request_error']]];
        $routes = $this->routes($model);
        if (!$routes) return ['status' => 404, 'body' => ['error' => ['message' => 'Không có provider cho model: ' . $model, 'type' => 'model_not_found']]];
        $rid = 'tmsreq_' . bin2hex(random_bytes(10)); $last = null;
        foreach ($routes as $route) {
            $pid = (int)$route['provider_id']; if (!$this->usage->providerAllowed($pid)) continue;
            $prov = $this->providers->find($pid); if (!$prov || empty($prov['enabled'])) continue;
            $start = microtime(true);
            $resp = $this->dispatch($prov, $p, (string)$route['upstream_model']);
            $latency = (int)round((microtime(true) - $start) * 1000);
            $body = json_decode($resp['body'], true);
            if (is_array($body) && $resp['status'] >= 200 && $resp['status'] < 300) $body = $this->normalizeBody($prov, $body, $model);
            $usage = is_array($body) && is_array($body['usage'] ?? null) ? $body['usage'] : [];
            $in = (int)($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0); $out = (int)($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0); $total = (int)($usage['total_tokens'] ?? ($in + $out));
            $source = ($in || $out || $total) ? 'provider' : 'estimated';
            if ($source === 'estimated' && is_array($body)) { $in = $this->estimate($p['messages'] ?? []); $out = $this->estimate($body['choices'] ?? []); $total = $in + $out; }
            $ok = $resp['status'] >= 200 && $resp['status'] < 300;
            $this->usage->record(['request_id' => $rid, 'provider_id' => $pid, 'client_key_id' => (int)$client['id'], 'model' => $model, 'upstream_model' => $route['upstream_model'], 'input_tokens' => $in, 'output_tokens' => $out, 'total_tokens' => $total, 'usage_source' => $source, 'status_code' => $resp['status'], 'latency_ms' => $latency, 'success' => $ok]);
            if ($ok && is_array($body)) { header('X-TMS-Provider: ' . rawurlencode((string)$prov['name'])); header('X-TMS-Request-Id: ' . $rid); return ['status' => $resp['status'], 'body' => $body]; }
            $last = is_array($body) ? $body : ['error' => ['message' => 'Upstream HTTP ' . $resp['status']]];
            if (!in_array($resp['status'], [408, 409, 425, 429, 500, 502, 503, 504], true)) break;
        }
        return ['status' => 502, 'body' => $last ?: ['error' => ['message' => 'Không còn provider khả dụng.', 'type' => 'upstream_unavailable']]];
    }

    private function streamChat(array $p, array $client): never
    {
        $model = trim((string)($p['model'] ?? '')); if ($model === '') App::json(['error' => ['message' => 'model là bắt buộc.']], 400);
        $routes = $this->routes($model); $selected = null; $prov = null;
        foreach ($routes as $route) { if (!$this->usage->providerAllowed((int)$route['provider_id'])) continue; $candidate = $this->providers->find((int)$route['provider_id']); if ($candidate && !empty($candidate['enabled'])) { $selected = $route; $prov = $candidate; break; } }
        if (!$selected || !$prov) App::json(['error' => ['message' => 'Không còn provider khả dụng.', 'type' => 'upstream_unavailable']], 502);
        if (($prov['kind'] ?? 'openai_compatible') !== 'openai_compatible') App::json(['error' => ['message' => 'SSE streaming v1.1.0 hiện hỗ trợ provider OpenAI-compatible. Anthropic/Gemini native dùng non-stream.', 'type' => 'unsupported_feature']], 400);

        $up = $p; $up['model'] = $selected['upstream_model']; $url = rtrim((string)$prov['base_url'], '/') . '/chat/completions';
        header('Content-Type: text/event-stream; charset=utf-8'); header('Cache-Control: no-cache, no-transform'); header('X-Accel-Buffering: no'); header('X-TMS-Provider: ' . rawurlencode((string)$prov['name']));
        while (ob_get_level() > 0) @ob_end_flush();
        $rid = 'tmsreq_' . bin2hex(random_bytes(10)); header('X-TMS-Request-Id: ' . $rid);
        $start = microtime(true); $status = 200; $outText = ''; $usage = [];
        $ch = curl_init($url); $headers = ['Content-Type: application/json', 'Accept: text/event-stream', 'User-Agent: TMS-AI-Router/' . App::config('version', '1.1.0')]; if ((string)$prov['api_key'] !== '') $headers[] = 'Authorization: Bearer ' . $prov['api_key'];
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => false, CURLOPT_CONNECTTIMEOUT => (int)App::config('connect_timeout', 15), CURLOPT_TIMEOUT => max(30, (int)$prov['timeout_seconds']), CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => json_encode($up, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), CURLOPT_WRITEFUNCTION => function($ch, $chunk) use (&$outText, &$usage) {
            foreach (preg_split('/\r?\n/', $chunk) ?: [] as $line) if (str_starts_with($line, 'data: ') && trim(substr($line, 6)) !== '[DONE]') { $j = json_decode(substr($line, 6), true); if (is_array($j)) { $delta = $j['choices'][0]['delta']['content'] ?? ''; if (is_string($delta)) $outText .= $delta; if (is_array($j['usage'] ?? null)) $usage = $j['usage']; } }
            echo $chunk; @flush(); return strlen($chunk);
        }]);
        $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); if ($ok === false) { echo "event: error\ndata: " . json_encode(['error' => ['message' => curl_error($ch)]], JSON_UNESCAPED_UNICODE) . "\n\n"; @flush(); } curl_close($ch);
        $in = (int)($usage['prompt_tokens'] ?? 0); $out = (int)($usage['completion_tokens'] ?? 0); $total = (int)($usage['total_tokens'] ?? ($in + $out)); $source = ($in || $out || $total) ? 'provider' : 'estimated';
        if ($source === 'estimated') { $in = $this->estimate($p['messages'] ?? []); $out = $this->estimate($outText); $total = $in + $out; }
        $this->usage->record(['request_id' => $rid, 'provider_id' => (int)$selected['provider_id'], 'client_key_id' => (int)$client['id'], 'model' => $model, 'upstream_model' => $selected['upstream_model'], 'input_tokens' => $in, 'output_tokens' => $out, 'total_tokens' => $total, 'usage_source' => $source, 'status_code' => $status ?: 502, 'latency_ms' => (int)round((microtime(true) - $start) * 1000), 'success' => $ok !== false && $status >= 200 && $status < 300]);
        exit;
    }

    private function dispatch(array $prov, array $p, string $upstreamModel): array
    {
        $kind = (string)($prov['kind'] ?? 'openai_compatible');
        return match ($kind) {
            'anthropic' => $this->postAnthropic($prov, $p, $upstreamModel),
            'gemini' => $this->postGemini($prov, $p, $upstreamModel),
            default => $this->postOpenAI($prov, $p, $upstreamModel),
        };
    }

    private function postOpenAI(array $prov, array $p, string $model): array { $p['model'] = $model; return $this->postJson(rtrim((string)$prov['base_url'], '/') . '/chat/completions', $p, ['Authorization: Bearer ' . $prov['api_key']], (int)$prov['timeout_seconds']); }
    private function postAnthropic(array $prov, array $p, string $model): array
    {
        $system = ''; $messages = []; foreach ((array)($p['messages'] ?? []) as $m) { if (!is_array($m)) continue; $role = (string)($m['role'] ?? 'user'); $text = $this->contentText($m['content'] ?? ''); if ($role === 'system') { $system .= ($system ? "\n" : '') . $text; continue; } $messages[] = ['role' => $role === 'assistant' ? 'assistant' : 'user', 'content' => $text]; }
        $body = ['model' => $model, 'max_tokens' => (int)($p['max_tokens'] ?? 1024), 'messages' => $messages]; if ($system !== '') $body['system'] = $system; if (isset($p['temperature'])) $body['temperature'] = $p['temperature'];
        $base = rtrim((string)$prov['base_url'], '/'); $url = str_ends_with($base, '/v1') ? $base . '/messages' : $base . '/v1/messages';
        return $this->postJson($url, $body, ['x-api-key: ' . $prov['api_key'], 'anthropic-version: 2023-06-01'], (int)$prov['timeout_seconds']);
    }
    private function postGemini(array $prov, array $p, string $model): array
    {
        $contents = []; $system = ''; foreach ((array)($p['messages'] ?? []) as $m) { if (!is_array($m)) continue; $role = (string)($m['role'] ?? 'user'); $text = $this->contentText($m['content'] ?? ''); if ($role === 'system') { $system .= ($system ? "\n" : '') . $text; continue; } $contents[] = ['role' => $role === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $text]]]; }
        $body = ['contents' => $contents, 'generationConfig' => array_filter(['temperature' => $p['temperature'] ?? null, 'maxOutputTokens' => $p['max_tokens'] ?? null], static fn($v) => $v !== null)]; if ($system !== '') $body['systemInstruction'] = ['parts' => [['text' => $system]]];
        $base = rtrim((string)$prov['base_url'], '/'); if (!str_contains($base, '/v1beta')) $base .= '/v1beta'; $url = $base . '/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode((string)$prov['api_key']);
        return $this->postJson($url, $body, [], (int)$prov['timeout_seconds']);
    }

    private function normalizeBody(array $prov, array $body, string $publicModel): array
    {
        $kind = (string)($prov['kind'] ?? 'openai_compatible'); if ($kind === 'openai_compatible') return $body;
        if ($kind === 'anthropic') { $text = ''; foreach ((array)($body['content'] ?? []) as $c) if (is_array($c) && ($c['type'] ?? '') === 'text') $text .= (string)($c['text'] ?? ''); $u = (array)($body['usage'] ?? []); return ['id' => (string)($body['id'] ?? ('chatcmpl_' . bin2hex(random_bytes(8)))), 'object' => 'chat.completion', 'created' => time(), 'model' => $publicModel, 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => (($body['stop_reason'] ?? '') === 'max_tokens' ? 'length' : 'stop')]], 'usage' => ['prompt_tokens' => (int)($u['input_tokens'] ?? 0), 'completion_tokens' => (int)($u['output_tokens'] ?? 0), 'total_tokens' => (int)(($u['input_tokens'] ?? 0) + ($u['output_tokens'] ?? 0))]]; }
        $text = (string)($body['candidates'][0]['content']['parts'][0]['text'] ?? ''); $u = (array)($body['usageMetadata'] ?? []); return ['id' => 'chatcmpl_' . bin2hex(random_bytes(8)), 'object' => 'chat.completion', 'created' => time(), 'model' => $publicModel, 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => (int)($u['promptTokenCount'] ?? 0), 'completion_tokens' => (int)($u['candidatesTokenCount'] ?? 0), 'total_tokens' => (int)($u['totalTokenCount'] ?? 0)]];
    }

    private function postJson(string $url, array $payload, array $extraHeaders, int $timeout): array
    {
        if (!function_exists('curl_init')) return ['status' => 500, 'body' => json_encode(['error' => ['message' => 'PHP cURL chưa được cài.']])];
        $ch = curl_init($url); $headers = array_merge(['Content-Type: application/json', 'Accept: application/json', 'User-Agent: TMS-AI-Router/' . App::config('version', '1.1.0')], array_values(array_filter($extraHeaders)));
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => (int)App::config('connect_timeout', 15), CURLOPT_TIMEOUT => max(10, $timeout), CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); if ($body === false) { $body = json_encode(['error' => ['message' => curl_error($ch), 'type' => 'upstream_transport_error']]); if ($status === 0) $status = 502; } curl_close($ch); return ['status' => $status ?: 502, 'body' => (string)$body];
    }

    private function routes(string $model): array
    {
        $strategy = App::db()->querySingle("SELECT value FROM settings WHERE key='routing_strategy'") ?: 'priority';
        $sql = 'SELECT pm.provider_id,pm.upstream_model,p.priority FROM provider_models pm JOIN providers p ON p.id=pm.provider_id WHERE pm.enabled=1 AND p.enabled=1 AND pm.public_model=:m ';
        if (in_array($strategy, ['least_used', 'quota_first'], true)) $sql .= "ORDER BY (SELECT COALESCE(SUM(total_tokens),0) FROM usage_events u WHERE u.provider_id=p.id AND u.created_at>=" . strtotime('today') . ') ASC,p.priority ASC';
        elseif ($strategy === 'round_robin') $sql .= 'ORDER BY (SELECT COALESCE(MAX(created_at),0) FROM usage_events u WHERE u.provider_id=p.id) ASC,p.priority ASC'; else $sql .= 'ORDER BY p.priority,p.id';
        $s = App::db()->prepare($sql); $s->bindValue(':m', $model, SQLITE3_TEXT); $r = $s->execute(); $o = []; while ($x = $r?->fetchArray(SQLITE3_ASSOC)) $o[] = $x; return $o;
    }

    private function contentText(mixed $content): string { if (is_string($content)) return $content; if (!is_array($content)) return ''; $out = ''; foreach ($content as $part) { if (is_string($part)) $out .= $part; elseif (is_array($part)) $out .= (string)($part['text'] ?? $part['input_text'] ?? ''); } return $out; }
    private function estimate(mixed $x): int { $t = is_string($x) ? $x : json_encode($x, JSON_UNESCAPED_UNICODE); $len = function_exists('mb_strlen') ? mb_strlen((string)$t, 'UTF-8') : strlen((string)$t); return max(0, (int)ceil($len / 4)); }
}

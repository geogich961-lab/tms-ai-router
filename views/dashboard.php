<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0b1020">
<title>TMS AI Router</title>
<link rel="stylesheet" href="/assets/app.css?v=1.1.0">
</head>
<body data-csrf="<?=htmlspecialchars($csrf,ENT_QUOTES)?>">
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="brand"><div class="brand-logo">T</div><div><strong>TMS AI Router</strong><small>v1.1.0</small></div></div>
        <nav class="nav">
            <button class="nav-item active" data-target="overview"><span>◫</span>Overview</button>
            <button class="nav-item" data-target="providers"><span>◇</span>Providers</button>
            <button class="nav-item" data-target="keys"><span>⌘</span>API Keys</button>
            <button class="nav-item" data-target="settings"><span>⚙</span>Routing</button>
            <button class="nav-item" data-target="updates"><span>↻</span>Updates</button>
        </nav>
        <div class="sidebar-foot"><div class="status-dot"><i></i><span>Gateway online</span></div><form method="post" action="/logout"><button class="logout">Đăng xuất</button></form></div>
    </aside>
    <div class="backdrop" id="sidebarBackdrop"></div>
    <main class="content">
        <header class="mobile-head"><button id="menuToggle" class="icon-btn">☰</button><div><strong>TMS AI Router</strong><small>v1.1.0</small></div><span class="online-pill">Online</span></header>

        <section class="page active" id="overview">
            <div class="page-head"><div><p class="eyebrow">ROUTER OVERVIEW</p><h1>AI Gateway Dashboard</h1><p class="sub">Theo dõi token, quota và trạng thái provider theo thời gian thực.</p></div><div class="api-chip"><span>API</span><code>/v1</code></div></div>
            <div class="metrics">
                <article><div class="metric-icon">↗</div><span>Requests hôm nay</span><b id="mRequests"><?=number_format((int)$summary['requests'])?></b><small>24 giờ gần nhất</small></article>
                <article><div class="metric-icon">I</div><span>Input tokens</span><b id="mInput"><?=number_format((int)$summary['input_tokens'])?></b><small>Prompt / input</small></article>
                <article><div class="metric-icon">O</div><span>Output tokens</span><b id="mOutput"><?=number_format((int)$summary['output_tokens'])?></b><small>Completion / output</small></article>
                <article><div class="metric-icon">Σ</div><span>Total tokens</span><b id="mTotal"><?=number_format((int)$summary['total_tokens'])?></b><small>Tổng mức sử dụng</small></article>
            </div>
            <section class="panel"><div class="panel-head"><div><p class="eyebrow">QUOTA MONITOR</p><h2>Provider usage & reset</h2></div><span class="live-badge"><i></i>Live</span></div><div id="providerUsage"></div></section>
            <section class="panel api-panel"><div><p class="eyebrow">OPENAI COMPATIBLE</p><h2>Gateway endpoints</h2><p class="sub">Dùng Client API Key do Router tạo để kết nối ứng dụng.</p></div><div class="endpoint-list"><code>GET /v1/models</code><code>POST /v1/chat/completions</code><code>POST /v1/responses</code></div></section>
        </section>

        <section class="page" id="providers">
            <div class="page-head"><div><p class="eyebrow">PROVIDER HUB</p><h1>Providers</h1><p class="sub">OpenAI-compatible, Anthropic và Gemini native.</p></div><button class="primary" id="addProvider">+ Thêm provider</button></div>
            <section class="panel table-panel"><div class="table-wrap"><table><thead><tr><th>Provider</th><th>Type</th><th>Base URL</th><th>Models</th><th>Priority</th><th></th></tr></thead><tbody>
            <?php foreach($providerList as $p):?><tr><td><div class="provider-name"><span class="provider-avatar"><?=htmlspecialchars(strtoupper(substr((string)$p['name'],0,1)))?></span><div><b><?=htmlspecialchars($p['name'])?></b><small><?=$p['enabled']?'Active':'Disabled'?></small></div></div></td><td><span class="type-pill"><?=htmlspecialchars((string)($p['kind']??'openai_compatible'))?></span></td><td><code><?=htmlspecialchars($p['base_url'])?></code></td><td><?=count($p['models'])?></td><td><?=$p['priority']?></td><td class="actions"><button class="ghost edit-provider" data-id="<?=$p['id']?>">Sửa</button><button class="danger-ghost delete-provider" data-id="<?=$p['id']?>">Xóa</button></td></tr><?php endforeach;?>
            <?php if(!$providerList):?><tr><td colspan="6"><div class="empty">Chưa có provider. Thêm provider đầu tiên để bắt đầu routing.</div></td></tr><?php endif;?></tbody></table></div></section>
        </section>

        <section class="page" id="keys">
            <div class="page-head"><div><p class="eyebrow">ACCESS CONTROL</p><h1>Client API Keys</h1><p class="sub">Khóa truy cập dành cho IDE, app và các AI client bên ngoài.</p></div><button class="primary" id="addKey">+ Tạo API key</button></div>
            <section class="panel table-panel"><div class="table-wrap"><table><thead><tr><th>Tên</th><th>Key prefix</th><th>Trạng thái</th><th>Last used</th><th></th></tr></thead><tbody>
            <?php foreach($keyList as $k):?><tr><td><b><?=htmlspecialchars($k['name'])?></b></td><td><code><?=htmlspecialchars($k['key_prefix'])?>…</code></td><td><span class="status-pill <?=$k['enabled']?'ok':'off'?>"><?=$k['enabled']?'Active':'Revoked'?></span></td><td><?=!empty($k['last_used_at'])?date('d/m/Y H:i',(int)$k['last_used_at']):'—'?></td><td><?php if($k['enabled']):?><button class="danger-ghost revoke-key" data-id="<?=$k['id']?>">Thu hồi</button><?php endif;?></td></tr><?php endforeach;?>
            <?php if(!$keyList):?><tr><td colspan="5"><div class="empty">Chưa có Client API Key.</div></td></tr><?php endif;?></tbody></table></div></section>
        </section>

        <section class="page" id="settings">
            <div class="page-head"><div><p class="eyebrow">SMART ROUTING</p><h1>Routing strategy</h1><p class="sub">Chọn cách Router ưu tiên provider khi một model có nhiều route.</p></div></div>
            <section class="panel settings-grid">
                <label class="setting-card"><input type="radio" name="strategy" value="priority" <?=$routingStrategy==='priority'?'checked':''?>><div><b>Priority</b><p>Ưu tiên provider có priority nhỏ nhất.</p></div></label>
                <label class="setting-card"><input type="radio" name="strategy" value="round_robin" <?=$routingStrategy==='round_robin'?'checked':''?>><div><b>Round Robin</b><p>Luân phiên provider theo lần sử dụng gần nhất.</p></div></label>
                <label class="setting-card"><input type="radio" name="strategy" value="least_used" <?=$routingStrategy==='least_used'?'checked':''?>><div><b>Least Used</b><p>Ưu tiên provider dùng ít token hơn trong ngày.</p></div></label>
                <label class="setting-card"><input type="radio" name="strategy" value="quota_first" <?=$routingStrategy==='quota_first'?'checked':''?>><div><b>Quota First</b><p>Ưu tiên route còn ít mức sử dụng hơn.</p></div></label>
            </section>
            <button class="primary" id="saveRouting">Lưu routing strategy</button>
        </section>

        <section class="page" id="updates">
            <div class="page-head"><div><p class="eyebrow">HOT UPDATE</p><h1>Update Center</h1><p class="sub">Kiểm tra và cập nhật trực tiếp từ GitHub, không cần cài lại.</p></div></div>
            <section class="panel update-card"><div class="version-box"><span>Phiên bản hiện tại</span><b>v1.1.0</b><small>Stable channel · GitHub Releases</small></div><div class="update-info" id="updateInfo"><h2>Sẵn sàng kiểm tra cập nhật</h2><p>TMS AI Router sẽ giữ nguyên SQLite, master key và toàn bộ thư mục <code>storage/</code>.</p><div class="update-actions"><button class="primary" id="checkUpdate">Kiểm tra cập nhật</button><button class="primary hidden" id="applyUpdate">Cập nhật ngay</button></div></div></section>
        </section>
    </main>
</div>

<dialog id="providerModal"><form id="providerForm" class="modal-form"><div class="modal-head"><div><p class="eyebrow">PROVIDER CONFIG</p><h2>Provider</h2></div><button type="button" id="closeProvider" class="icon-btn">×</button></div><input id="pId" type="hidden">
<div class="form-grid"><label>Tên provider<input id="pName" required placeholder="OpenRouter"></label><label>Loại provider<select id="pKind"><option value="openai_compatible">OpenAI-compatible</option><option value="anthropic">Anthropic native</option><option value="gemini">Gemini native</option></select></label></div>
<label>Base URL<input id="pBase" required placeholder="https://openrouter.ai/api/v1"></label><label>API Key<input id="pKey" type="password" autocomplete="off" placeholder="Để trống khi sửa để giữ key hiện tại"></label>
<div class="form-grid"><label>Priority<input id="pPriority" type="number" min="1" value="100"></label><label class="toggle-label"><span>Trạng thái</span><span><input id="pEnabled" type="checkbox" checked> Enabled</span></label></div>
<label>Model mapping<textarea id="pModels" rows="5" placeholder="gpt-4o-mini&#10;claude-sonnet=claude-3-7-sonnet-latest"></textarea><small>Mỗi dòng: public_model hoặc public_model=upstream_model</small></label>
<label>Quota windows (JSON)<textarea id="pQuotas" rows="7">[{"label":"5 giờ","window_seconds":18000,"token_limit":0,"request_limit":0,"reset_mode":"rolling"},{"label":"Hàng ngày","window_seconds":86400,"token_limit":0,"request_limit":0,"reset_mode":"fixed"}]</textarea></label>
<div class="modal-actions"><button type="button" class="ghost" id="cancelProvider">Hủy</button><button type="submit" class="primary">Lưu provider</button></div></form></dialog>

<script>window.TMS_INITIAL_SUMMARY=<?=json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;</script>
<script src="/assets/app.js?v=1.1.0"></script>
</body></html>

<?php
require_once __DIR__ . '/../inc/auth.php';
$me = require_admin();
if (!is_super($me)) { http_response_code(403); exit('Консоль доступна только главному админу.'); }
?>
<!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Консоль и логи — Искра</title>
<link rel="stylesheet" href="/assets/style.css?v=1">
<style>
main{max-width:900px}
.sub{color:#8b949e;font-size:13px;margin:0 0 14px}
#out{background:#0a0d12;border:1px solid #262d36;border-radius:10px;padding:14px;min-height:300px;max-height:60vh;overflow:auto;white-space:pre-wrap;word-break:break-word;font-family:ui-monospace,Consolas,monospace;font-size:13px;color:#d6deeb;margin:0 0 12px}
.row{display:flex;gap:8px}
.row input{flex:1;padding:10px;border-radius:9px;border:1px solid #30363d;background:#0d1117;color:#e6edf3;font-family:ui-monospace,Consolas,monospace}
.row button{width:auto;margin:0;padding:9px 16px;border-radius:9px;border:1px solid #30363d;background:#21262d;color:#e6edf3;cursor:pointer}
.row button:hover{border-color:#f0883e;color:#f0883e}
</style></head>
<body>
<header class="top">
  <a class="logo" href="/"><span class="spark">◆</span> Консоль и логи</a>
  <nav><span class="nick"><?= htmlspecialchars($me['nick']) ?></span><a href="/admin.php">← Админка</a></nav>
</header>
<main>
  <p class="sub">Команды выполняются от пользователя <b>www-data</b> (не root), таймаут 20 секунд. Только для главного админа.</p>
  <pre id="out">Введи команду и нажми «Выполнить», или «Логи».</pre>
  <div class="row">
    <input id="cmd" placeholder="команда, напр. whoami, ls -la, systemctl status iskra-video, df -h" autocomplete="off">
    <button id="run">Выполнить</button>
    <button id="logs">Логи</button>
  </div>
</main>
<script>
const api = (a, body) => fetch('/api/v1/index.php?action=' + a,
  body ? {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)} : {method:'POST'})
  .then(r => r.json());
const out = document.getElementById('out'), cmd = document.getElementById('cmd');
async function run(){
  const c = cmd.value.trim(); if (!c) return;
  out.textContent = '$ ' + c + '\n…';
  const r = await api('admin_console', {cmd:c});
  out.textContent = '$ ' + c + '\n' + (r.out ?? r.err ?? '(нет вывода)');
  out.scrollTop = out.scrollHeight;
}
document.getElementById('run').onclick = run;
cmd.addEventListener('keydown', e => { if (e.key==='Enter'){ e.preventDefault(); run(); } });
document.getElementById('logs').onclick = async () => {
  out.textContent = 'Загружаю логи…';
  const r = await api('admin_logs');
  out.textContent = r.out || r.err || '(пусто)';
  out.scrollTop = out.scrollHeight;
};
cmd.focus();
</script>
</body></html>

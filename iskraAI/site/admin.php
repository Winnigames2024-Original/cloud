<?php
require_once __DIR__ . '/inc/auth.php';
$me = require_admin();
$super = is_super($me);
$title = 'Админка';
?>
<!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Админка — Искра</title>
<link rel="stylesheet" href="/assets/style.css?v=1">
<style>
main{max-width:960px}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:20px;margin-bottom:20px}
.panel h2{font-size:18px;margin:0 0 14px}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{text-align:left;padding:9px 8px;border-bottom:1px solid var(--line)}
th{color:var(--dim);font-weight:600}
.tag{font-size:12px;padding:2px 7px;border-radius:6px;background:var(--panel2);color:var(--dim)}
.tag.adm{color:#7ee787} .tag.ban{color:#ff9f99} .tag.sup{color:var(--accent)}
.act{display:flex;gap:6px;flex-wrap:wrap}
.act button{width:auto;margin:0;padding:5px 10px;font-size:12px;border-radius:7px;background:var(--panel2);color:var(--text);border:1px solid var(--line);cursor:pointer}
.act button:hover{border-color:var(--accent);color:var(--accent)}
.act button.danger:hover{border-color:#f85149;color:#f85149}
.row2{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
textarea{width:100%;min-height:90px;padding:10px;border-radius:9px;border:1px solid var(--line);background:#0d1117;color:var(--text);font:inherit}
.srv button{background:#2d1414;border-color:#5c2626}
.srv button:hover{border-color:#f85149;color:#f85149}
.back{color:var(--dim);font-size:14px}
</style></head>
<body>
<header class="top">
  <a class="logo" href="/"><span class="spark">◆</span> Админка</a>
  <nav><span class="nick"><?= htmlspecialchars($me['nick']) ?></span><a href="/">← В чат</a><a href="/logout.php">Выйти</a></nav>
</header>
<main>

  <div class="panel">
    <h2>Технические работы</h2>
    <div class="row2">
      <label class="chk" style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="maint"> Включить (сайт закрыт для всех, кроме админов)</label>
    </div>
    <label>Сообщение на время работ</label>
    <textarea id="maint-msg" style="min-height:50px"></textarea>
    <button id="maint-save" style="width:auto;margin-top:12px;padding:9px 18px">Сохранить</button>
  </div>

  <div class="panel">
    <h2>Системный промпт Искры</h2>
    <p class="sub" style="color:var(--dim);font-size:13px;margin:0 0 10px">Добавляется ко всем ответам. Задаёт характер и правила по умолчанию.</p>
    <textarea id="sys"></textarea>
    <button id="sys-save" style="width:auto;margin-top:12px;padding:9px 18px">Сохранить</button>
  </div>

  <div class="panel">
    <h2>Производительность</h2>
    <p class="sub" style="color:var(--dim);font-size:13px;margin:0 0 12px">Освобождает память и перезапускает тяжёлые сервисы (картинки, Qwen), снимая нагрузку с GPU.</p>
    <button id="antilag" style="width:auto;padding:9px 18px">⚡ Анти-лаг</button>
  </div>

  <div class="panel">
    <h2>Пользователи</h2>
    <table><thead><tr><th>Имя</th><th>E-mail</th><th>Статус</th><th>Действия</th></tr></thead>
    <tbody id="users"></tbody></table>
  </div>

  <?php if ($super): ?>
  <div class="panel srv">
    <h2>GPU-сервер</h2>
    <p class="sub" style="color:var(--dim);font-size:13px;margin:0 0 12px">Перезагрузка: сайт вернётся сам через 1–2 минуты. Выключение: сайт погаснет, включить обратно можно только в кабинете Intelion.</p>
    <div class="act">
      <button id="reboot">⟳ Перезагрузить сервер</button>
      <button id="shutdown" class="danger">⏻ Выключить сервер</button>
    </div>
  </div>
  <?php endif; ?>

</main>
<script>
const api = (a, body) => fetch('/api/v1/index.php?action=' + a,
  body ? {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)} : {method:'POST'})
  .then(r => r.json());
const esc = t => { const d=document.createElement('div'); d.textContent=t??''; return d.innerHTML; };

async function loadUsers(){
  const d = await api('admin_users');
  const tb = document.getElementById('users'); tb.innerHTML='';
  (d.users||[]).forEach(u => {
    const tr = document.createElement('tr');
    let tags = '';
    if (u.super) tags += '<span class="tag sup">главный</span> ';
    else if (u.role==='admin') tags += '<span class="tag adm">админ</span> ';
    if (+u.banned) tags += '<span class="tag ban">бан</span>';
    let acts = '';
    if (!u.super) {
      acts += (u.role==='admin')
        ? '<button data-a="admin_role" data-r="user" data-u="'+u.id+'">Снять админа</button>'
        : '<button data-a="admin_role" data-r="admin" data-u="'+u.id+'">Дать админа</button>';
      acts += (+u.banned)
        ? '<button data-a="admin_ban" data-b="0" data-u="'+u.id+'">Разбан</button>'
        : '<button data-a="admin_ban" data-b="1" data-u="'+u.id+'">Бан</button>';
      acts += '<button data-a="admin_impersonate" data-u="'+u.id+'">Войти</button>';
      acts += '<button class="danger" data-a="admin_del" data-u="'+u.id+'">Удалить</button>';
    } else acts = '—';
    tr.innerHTML = '<td>'+esc(u.nick)+'</td><td>'+esc(u.email)+'</td><td>'+(tags||'—')+'</td><td class="act">'+acts+'</td>';
    tb.appendChild(tr);
  });
  tb.querySelectorAll('button').forEach(b => b.onclick = async () => {
    const a = b.dataset.a, uid = +b.dataset.u;
    if (a==='admin_del' && !confirm('Удалить этот аккаунт навсегда?')) return;
    const body = {uid};
    if (a==='admin_role') body.role = b.dataset.r;
    if (a==='admin_ban') body.ban = +b.dataset.b;
    const r = await api(a, body);
    if (r.err) { alert(r.err); return; }
    if (a==='admin_impersonate') { location.href='/'; return; }
    loadUsers();
  });
}

(async () => {
  const m = await api('admin_maint_get');
  document.getElementById('maint').checked = m.on==='1';
  document.getElementById('maint-msg').value = m.msg||'';
  const s = await api('admin_sys_get');
  document.getElementById('sys').value = s.prompt||'';
  loadUsers();
})();

document.getElementById('maint-save').onclick = async () => {
  await api('admin_maint_set', {on: document.getElementById('maint').checked, msg: document.getElementById('maint-msg').value});
  alert('Сохранено');
};
document.getElementById('sys-save').onclick = async () => {
  await api('admin_sys_set', {prompt: document.getElementById('sys').value});
  alert('Сохранено');
};
document.getElementById('antilag').onclick = async () => {
  const b = document.getElementById('antilag'); b.disabled = true; b.textContent = 'Чищу…';
  const r = await api('admin_antilag');
  b.textContent = r.ok ? '✓ Нагрузка снята' : (r.err || 'Ошибка');
  setTimeout(() => { b.disabled = false; b.textContent = '⚡ Анти-лаг'; }, 2500);
};
const rb = document.getElementById('reboot');
if (rb) rb.onclick = async () => { if(!confirm('Перезагрузить GPU-сервер? Сайт вернётся через 1–2 минуты.'))return; const r=await api('admin_reboot'); alert(r.ok?'Сервер перезагружается…':(r.err||'Ошибка')); };
const sd = document.getElementById('shutdown');
if (sd) sd.onclick = async () => { if(!confirm('ВЫКЛЮЧИТЬ сервер? Сайт погаснет, включить можно только в кабинете Intelion.'))return; const r=await api('admin_shutdown'); alert(r.ok?'Сервер выключается…':(r.err||'Ошибка')); };
</script>
</body></html>

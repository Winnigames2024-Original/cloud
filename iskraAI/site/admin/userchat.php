<?php
require_once __DIR__ . '/../inc/auth.php';
$me = require_admin();
?>
<!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Чаты пользователей — Искра</title>
<link rel="stylesheet" href="/assets/style.css?v=1">
<style>
main{max-width:1100px}
.bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
select,.pick{padding:9px;border-radius:9px;border:1px solid var(--line);background:#0d1117;color:var(--text);font:inherit}
.grid{display:grid;grid-template-columns:260px 1fr;gap:16px}
.col{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:14px;min-height:60vh}
.col h3{font-size:14px;color:var(--dim);margin:0 0 10px}
.chat-b{display:block;width:100%;text-align:left;padding:9px 11px;margin-bottom:6px;border-radius:9px;background:var(--panel2,#161b22);color:var(--text);border:1px solid var(--line);cursor:pointer;font:inherit}
.chat-b:hover,.chat-b.on{border-color:var(--accent);color:var(--accent)}
.msgs{display:flex;flex-direction:column;gap:8px;max-height:52vh;overflow:auto;padding-right:4px;margin-bottom:12px}
.m{padding:8px 11px;border-radius:10px;max-width:80%;white-space:pre-wrap;word-wrap:break-word;font-size:14px}
.m.user{align-self:flex-end;background:#1f6feb33;border:1px solid #1f6feb55}
.m.ai{align-self:flex-start;background:var(--panel2,#161b22);border:1px solid var(--line)}
.m .who{font-size:11px;color:var(--dim);margin-bottom:3px}
.send{display:flex;gap:8px}
.send textarea{flex:1;min-height:46px;padding:10px;border-radius:9px;border:1px solid var(--line);background:#0d1117;color:var(--text);font:inherit}
.send button{width:auto;padding:0 18px;border-radius:9px}
.hint{color:var(--dim);font-size:13px}
</style></head>
<body>
<header class="top">
  <a class="logo" href="/"><span class="spark">◆</span> Чаты пользователей</a>
  <nav><span class="nick"><?= htmlspecialchars($me['nick']) ?></span><a href="/admin.php">← Админка</a><a href="/">В чат</a></nav>
</header>
<main>
  <div class="bar">
    <label class="hint">Пользователь:</label>
    <select id="user"></select>
  </div>

  <div class="grid">
    <div class="col">
      <h3>Чаты пользователя</h3>
      <div id="chats"><p class="hint">Выбери пользователя.</p></div>
    </div>
    <div class="col">
      <h3 id="chat-title">Сообщения</h3>
      <div class="msgs" id="msgs"><p class="hint">Выбери чат слева.</p></div>
      <div class="send" id="send-box" hidden>
        <textarea id="text" placeholder="Сообщение от Искры в этот чат…"></textarea>
        <button id="send">Отправить</button>
      </div>
    </div>
  </div>
</main>
<script>
const api = (a, body) => fetch('/api/v1/index.php?action=' + a,
  body ? {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)} : {method:'POST'})
  .then(r => r.json());
const esc = t => { const d=document.createElement('div'); d.textContent=t??''; return d.innerHTML; };
let curChat = null;

async function loadUsers(){
  const d = await api('admin_users');
  const sel = document.getElementById('user'); sel.innerHTML = '';
  (d.users||[]).forEach(u => { const o=document.createElement('option'); o.value=u.id; o.textContent=u.nick+' ('+u.email+')'; sel.appendChild(o); });
  if ((d.users||[]).length) loadChats();
}

async function loadChats(){
  const uid = +document.getElementById('user').value;
  const d = await api('admin_user_chats', {uid});
  const box = document.getElementById('chats'); box.innerHTML='';
  if (!(d.chats||[]).length) { box.innerHTML='<p class="hint">У пользователя нет чатов.</p>'; return; }
  d.chats.forEach(c => {
    const b=document.createElement('button'); b.className='chat-b'; b.textContent=c.title||'Без названия';
    b.onclick=()=>{ document.querySelectorAll('.chat-b').forEach(x=>x.classList.remove('on')); b.classList.add('on'); openChat(c.chat_id, c.title); };
    box.appendChild(b);
  });
}

async function openChat(cid, title){
  curChat = cid;
  document.getElementById('chat-title').textContent = title || 'Сообщения';
  document.getElementById('send-box').hidden = false;
  const d = await api('admin_chat_messages&chat='+encodeURIComponent(cid));
  const box = document.getElementById('msgs'); box.innerHTML='';
  if (!(d.messages||[]).length) { box.innerHTML='<p class="hint">Чат пустой.</p>'; }
  (d.messages||[]).forEach(m => {
    const el=document.createElement('div'); el.className='m '+(m.role==='ai'?'ai':'user');
    el.innerHTML='<div class="who">'+(m.role==='ai'?'Искра':'Пользователь')+'</div>'+esc(m.content);
    box.appendChild(el);
  });
  box.scrollTop = box.scrollHeight;
}

document.getElementById('user').onchange = loadChats;
document.getElementById('send').onclick = async () => {
  const text = document.getElementById('text').value.trim();
  if (!curChat || !text) { alert('Выбери чат и напиши текст'); return; }
  const r = await api('admin_chat_send', {chat:curChat, text});
  if (r.err) { alert(r.err); return; }
  document.getElementById('text').value='';
  openChat(curChat, document.getElementById('chat-title').textContent);
};
loadUsers();
</script>
</body></html>

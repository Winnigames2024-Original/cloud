const $ = s => document.querySelector(s);
const app = $('#app'), msgs = $('#messages'), input = $('#input');
let current = null;
let cfg = { persona: '', allow_anger: 0, mode: 'auto', model: 'smart' };
let pending = [];   // прикреплённые файлы, ещё не отправленные

const api = (a, body) => fetch('/api/v1/index.php?action=' + a,
  body ? {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)} : {})
  .then(r => { if (r.status === 401) { location.href = '/login.php'; throw new Error('auth'); } return r.json(); });

// ── темы ──
const THEMES = ['dark', 'light', 'violet', 'ocean'];
let ti = Math.max(0, THEMES.indexOf(localStorage.getItem('iskra_theme') || 'dark'));
function applyTheme(){ document.documentElement.dataset.theme = THEMES[ti]; localStorage.setItem('iskra_theme', THEMES[ti]); }
applyTheme();
$('#theme').onclick = () => { ti = (ti + 1) % THEMES.length; applyTheme(); };

const isMobile = () => matchMedia('(max-width:720px)').matches;
if (isMobile()) app.classList.add('nosidebar');
$('#burger').onclick = () => app.classList.toggle('nosidebar');

function esc(t){ const d=document.createElement('div'); d.textContent=t; return d.innerHTML; }

// уведомления (когда вкладка свёрнута)
function notify(text){
  if (!('Notification' in window) || Notification.permission !== 'granted' || !document.hidden) return;
  try { new Notification('Искра', { body: (text||'').slice(0,140) || 'Новый ответ', icon: '/assets/icon-192.png' }); } catch(_){}
}

function inlineFmt(s){
  return esc(s).replace(/`([^`]+)`/g, '<code class="inline">$1</code>');
}
function renderText(text){
  const lines = String(text).split('\n');
  let html = '', buf = [];
  const flush = () => { if (buf.length){ html += '<div>' + inlineFmt(buf.join('\n')) + '</div>'; buf = []; } };
  lines.forEach(l => {
    if (l.startsWith('[img]')) { flush(); html += '<img class="genimg" src="' + esc(l.slice(5)) + '">'; }
    else if (l.startsWith('[audio]')) { flush(); html += '<audio class="genaudio" controls src="' + esc(l.slice(7)) + '"></audio>'; }
    else if (l.startsWith('[video]')) { flush(); html += '<video class="genvideo" controls src="' + esc(l.slice(7)) + '"></video>'; }
    else if (l.startsWith('[vjob]')) { /* маркер фоновой задачи видео — не показываем */ }
    else if (l.startsWith('[file]')) { flush(); const p = l.slice(6).split('|');
      html += '<a class="filechip" href="' + esc(p[1]||'#') + '" target="_blank">📎 ' + esc(p[0]||'файл') + '</a>'; }
    else buf.push(l);
  });
  flush();
  return html;
}
function renderContent(text){
  const parts = String(text).split('```');
  let html = '';
  parts.forEach((part, i) => {
    if (i % 2 === 1) {
      let body = part, lang = '';
      const nl = part.indexOf('\n');
      if (nl >= 0) { const first = part.slice(0, nl).trim();
        if (/^[a-zA-Z0-9+#.\-]{0,15}$/.test(first)) { lang = first; body = part.slice(nl + 1); } }
      body = body.replace(/\n$/, '');
      html += '<div class="codeblock"><button class="copy">Копировать</button><pre><code>' + esc(body) + '</code></pre></div>';
    } else {
      html += renderText(part);
    }
  });
  return html;
}
// кнопка «Копировать» у блоков кода
document.addEventListener('click', e => {
  if (e.target.classList.contains('copy')) {
    const code = e.target.parentElement.querySelector('code');
    if (code) navigator.clipboard.writeText(code.textContent).then(() => {
      const t = e.target.textContent; e.target.textContent = 'Скопировано';
      setTimeout(() => e.target.textContent = t, 1200);
    });
  }
});

function addMsg(role, text, id){
  $('#empty')?.remove();
  const row = document.createElement('div');
  row.className = 'row ' + role;
  if (id) row.dataset.id = id;
  row.innerHTML = '<div class="bubble">' + renderContent(text) + '</div>';
  const act = document.createElement('div'); act.className = 'msg-actions';
  if (role === 'user') { const e = document.createElement('button'); e.textContent='✎'; e.title='Изменить';
    e.onclick = () => editMsg(row); act.appendChild(e); }
  const d = document.createElement('button'); d.textContent='×'; d.title='Удалить';
  d.onclick = () => delMsg(row); act.appendChild(d);
  row.appendChild(act);
  msgs.appendChild(row); msgs.scrollTop = msgs.scrollHeight;
  return row;
}

async function editMsg(row){
  const id = row.dataset.id; if (!id) return;
  const first = row.querySelector('.bubble div');
  const cur = first ? first.textContent : '';
  const nv = prompt('Изменить сообщение:', cur);
  if (nv === null || !nv.trim()) return;
  await api('msg_edit', {id, text: nv});
  openChat(current);
}
async function delMsg(row){
  if (!confirm('Удалить это сообщение?')) return;
  if (row.dataset.id) await api('msg_delete', {id: row.dataset.id});
  row.remove();
}

// ── вложения ──
async function uploadFiles(fileList){
  for (const file of fileList) {
    const fd = new FormData(); fd.append('file', file);
    try {
      const r = await fetch('/api/v1/index.php?action=upload', {method:'POST', body:fd}).then(r=>r.json());
      if (r.url) pending.push({url:r.url, name:r.name, img:!!r.img});
      else alert(r.err || 'Не удалось загрузить файл');
    } catch(_) { alert('Ошибка загрузки'); }
  }
  renderPreviews();
}
$('#attach').onclick = () => $('#file').click();
$('#file').onchange = async e => { await uploadFiles(e.target.files); e.target.value = ''; };

function renderPreviews(){
  const box = $('#previews'); box.innerHTML = '';
  pending.forEach((f, i) => {
    const el = document.createElement('div'); el.className = 'prev';
    if (f.img) { const im = document.createElement('img'); im.src = f.url; im.onclick = () => openLightbox(f.url); el.appendChild(im); }
    else { const s = document.createElement('span'); s.className = 'pf'; s.textContent = '📎 ' + f.name; el.appendChild(s); }
    const x = document.createElement('button'); x.textContent='×';
    x.onclick = () => { pending.splice(i,1); renderPreviews(); };
    el.appendChild(x); box.appendChild(el);
  });
}

// ── перетаскивание файлов в низ ──
const dropArea = document.querySelector('.composer');
let dragDepth = 0;
window.addEventListener('dragenter', e => { e.preventDefault(); dragDepth++; dropArea.classList.add('dragover'); });
window.addEventListener('dragover', e => { e.preventDefault(); });
window.addEventListener('dragleave', e => { e.preventDefault(); if (--dragDepth <= 0) dropArea.classList.remove('dragover'); });
window.addEventListener('drop', async e => {
  e.preventDefault(); dragDepth = 0; dropArea.classList.remove('dragover');
  if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) await uploadFiles(e.dataTransfer.files);
});

// ── просмотр картинок (лайтбокс) ──
const lb = document.createElement('div'); lb.className = 'lightbox'; lb.hidden = true;
lb.innerHTML = '<img alt="">'; document.body.appendChild(lb);
lb.onclick = () => { lb.hidden = true; };
function openLightbox(src){ lb.querySelector('img').src = src; lb.hidden = false; }
msgs.addEventListener('click', e => { if (e.target.classList.contains('genimg')) openLightbox(e.target.src); });

async function loadChats(){
  const d = await api('chats');
  const list = $('#chatlist'); list.innerHTML = '';
  (d.chats||[]).forEach(c => {
    const el = document.createElement('div');
    el.className = 'chat-item' + (c.chat_id===current?' active':'');
    el.innerHTML = '<span class="t">'+esc(c.title)+'</span><span class="ren">✎</span><span class="del">×</span>';
    el.querySelector('.t').onclick = () => openChat(c.chat_id);
    el.querySelector('.ren').onclick = async e => { e.stopPropagation();
      const nv = prompt('Название чата:', c.title);
      if (nv && nv.trim()) { await api('chat_rename',{chat:c.chat_id, title:nv}); loadChats(); } };
    el.querySelector('.del').onclick = async e => { e.stopPropagation();
      if (!confirm('Удалить чат целиком?')) return;
      await api('delete',{chat:c.chat_id});
      if (current===c.chat_id){ current=null; msgs.innerHTML=''; }
      loadChats(); };
    list.appendChild(el);
  });
}

const MODEL_NAMES = { smart: 'Искра Про', own: 'Искра Базовая' };
function setModelLabel(){
  $('#model-btn').textContent = (MODEL_NAMES[cfg.model] || 'Модель') + ' ▾';
  document.querySelectorAll('#model-menu .pick-item').forEach(el => el.classList.toggle('on', el.dataset.v === cfg.model));
}
$('#model-btn').onclick = e => { e.stopPropagation(); const m = $('#model-menu'); m.hidden = !m.hidden; };
document.querySelectorAll('#model-menu .pick-item').forEach(el => el.onclick = e => {
  e.stopPropagation(); cfg.model = el.dataset.v; setModelLabel(); $('#model-menu').hidden = true; saveCfg();
});
document.addEventListener('click', () => { const m = $('#model-menu'); if (m) m.hidden = true; });

function toggleIdeas(){
  const m = $('#mode').value;
  const a = $('#ideas-args'); if (a) a.hidden = (m !== 'ideas');
  const v = $('#vid-len');    if (v) v.hidden = (m !== 'video');
}
function applyCfgToUI(){ $('#mode').value = cfg.mode||'auto'; setModelLabel(); toggleIdeas(); }
document.querySelectorAll('#ideas-args button').forEach(b => b.onclick = () => {
  input.value = b.dataset.a; input.focus();
});
async function loadCfg(id){
  const d = await api('settings_get&chat=' + encodeURIComponent(id));
  cfg = { persona:d.persona||'', allow_anger:+d.allow_anger||0, mode:d.mode||'auto', model:d.model||'smart' };
  applyCfgToUI();
}
async function saveCfg(){
  if (!current) current = crypto.randomUUID();
  await api('settings_set', {chat:current, persona:cfg.persona, allow_anger:cfg.allow_anger, mode:cfg.mode, model:cfg.model});
}

let liveTimer = null, lastMsgId = 0;
function stopLive(){ if (liveTimer){ clearInterval(liveTimer); liveTimer = null; } }
function startLive(){
  stopLive();
  liveTimer = setInterval(async () => {
    if (!current) return;
    try {
      const d = await api('messages_since&chat=' + encodeURIComponent(current) + '&after=' + lastMsgId);
      (d.messages||[]).forEach(m => {
        lastMsgId = Math.max(lastMsgId, +m.id);
        if (msgs.querySelector('.row[data-id="' + m.id + '"]')) return;   // уже на экране
        const row = addMsg(m.role, m.content, m.id);
        if (m.role === 'ai') { const vm = /\[vjob\]([a-f0-9]+)/.exec(m.content||''); if (vm) pollVideo(vm[1], row); }
      });
    } catch(e){}
  }, 4000);
}

async function openChat(id, push = true){
  current = id;
  if (push) history.pushState({id}, '', '#' + id);
  if(isMobile()) app.classList.add('nosidebar');
  const d = await api('history&chat='+encodeURIComponent(id));
  msgs.innerHTML=''; lastMsgId = 0;
  (d.messages||[]).forEach(m => {
    const row = addMsg(m.role, m.content, m.id);
    lastMsgId = Math.max(lastMsgId, +m.id || 0);
    if (m.role === 'ai') { const vm = /\[vjob\]([a-f0-9]+)/.exec(m.content||''); if (vm) pollVideo(vm[1], row); }
  });
  await loadCfg(id);
  loadChats();
  startLive();
}

function newChat(push = true){
  stopLive(); lastMsgId = 0;
  current = null; pending = []; renderPreviews();
  if (push) history.pushState({}, '', location.pathname);
  cfg = { persona:'', allow_anger:0, mode:'auto', model:'smart' }; applyCfgToUI();
  msgs.innerHTML = '<div class="empty" id="empty"><div class="spark-big">◆</div><p>Новый чат. Напиши первое сообщение.</p></div>';
  loadChats();
  if(isMobile()) app.classList.add('nosidebar');
}
$('#newchat').onclick = () => newChat();

window.addEventListener('popstate', () => {
  const id = location.hash.slice(1);
  if (id) openChat(id, false); else newChat(false);
});

$('#mode').onchange  = () => { cfg.mode  = $('#mode').value;  toggleIdeas(); saveCfg(); };

async function pollVideo(job, row){
  for (let i = 0; i < 3600; i++){           // до ~5 часов (длинное видео)
    await new Promise(r => setTimeout(r, 5000));
    let s; try { s = await api('video_status&job=' + encodeURIComponent(job)); } catch(e){ continue; }
    if (s.state === 'pending' && s.total > 1){
      row.querySelector('.bubble').textContent = '⏳ Собираю видео… клип ' + (s.done||0) + '/' + s.total;
    }
    if (s.state === 'done' && s.url){
      row.querySelector('.bubble').innerHTML = renderContent('[video]' + s.url);
      if (row.dataset.id) api('video_save', {id:+row.dataset.id, url:s.url});
      notify('Видео готово'); loadChats(); return;
    }
    if (s.state === 'error'){
      row.querySelector('.bubble').textContent = 'Не удалось сгенерировать видео: ' + (s.err || '');
      return;
    }
  }
  row.querySelector('.bubble').textContent = 'Видео генерируется дольше обычного — загляни в чат чуть позже.';
}

async function send(){
  const text = input.value.trim();
  if(!text && !pending.length) return;
  if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
  if(!current){ current = crypto.randomUUID(); history.replaceState({id:current}, '', '#' + current); }
  const files = pending.slice(); pending = []; renderPreviews();
  input.value=''; input.style.height='auto';
  let shown = text;
  files.forEach(f => shown += '\n' + (f.img ? '[img]'+f.url : '[file]'+f.name+'|'+f.url));
  const urow = addMsg('user', shown || '(файл)');
  const typing = addMsg('ai', '…');
  $('#send').disabled = true;
  try {
    const body = {chat:current, text, files};
    if ($('#mode').value === 'video') { const vl = $('#vidlen'); body.seconds = vl ? +vl.value : 5; }
    const d = await api('send', body);
    if (d.umid) { urow.dataset.id = d.umid; lastMsgId = Math.max(lastMsgId, +d.umid); }
    if (d.takeover) {
      typing.remove();   // чат ведёт админ — ответ придёт от него через живой опрос
    } else {
      if (d.id) { typing.dataset.id = d.id; lastMsgId = Math.max(lastMsgId, +d.id); }
      typing.querySelector('.bubble').innerHTML = renderContent(d.reply || 'Ошибка ответа');
      notify((d.reply||'').startsWith('[img]') ? 'Искра прислала картинку' : d.reply);
      if (d.video_job) pollVideo(d.video_job, typing);
    }
  } catch(e){ typing.querySelector('.bubble').textContent = 'Ошибка сети'; }
  $('#send').disabled = false;
  loadChats();
  if (app.classList.contains('showfiles')) loadFiles();
}
$('#send').onclick = send;
input.addEventListener('keydown', e => { if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); send(); } });
input.addEventListener('input', () => { input.style.height='auto'; input.style.height=Math.min(input.scrollHeight,180)+'px'; });

// ── поделиться ──
$('#share').onclick = async () => {
  if (!current) { alert('Сначала напиши что-нибудь в чате.'); return; }
  const cur = await api('vis_get&chat=' + encodeURIComponent(current));
  if (cur.visibility === 'public') {
    if (confirm('Чат сейчас публичный. Сделать приватным?')) {
      await api('visibility', {chat:current, visibility:'private'});
      alert('Чат снова приватный.');
    }
    return;
  }
  await api('visibility', {chat:current, visibility:'public'});
  const link = location.origin + '/chats/' + current + '/';
  try { await navigator.clipboard.writeText(link); alert('Чат публичный. Ссылка скопирована:\n' + link); }
  catch(_) { prompt('Чат публичный. Скопируй ссылку:', link); }
};

// ── действия ИИ ──
const ACT_NAMES = {file:'📄 Создан файл', image:'🖼 Нарисована картинка', vk:'📣 Пост в ВК', web:'🔎 Поиск в Google', wiki:'📘 Поиск в Википедии'};
$('#acts').onclick = async () => {
  if (!current) { alert('Открой чат.'); return; }
  const d = await api('actions&chat=' + encodeURIComponent(current));
  const box = $('#acts-list'); box.innerHTML = '';
  if (!d.actions || !d.actions.length) { box.innerHTML = '<div class="act-empty">Пока действий не было.</div>'; }
  else d.actions.forEach(a => {
    const el = document.createElement('div'); el.className = 'act';
    el.innerHTML = '<div class="act-k">' + (ACT_NAMES[a.kind] || esc(a.kind)) + '</div>'
      + '<div class="act-d">' + esc(a.detail) + '</div><div class="act-t">' + esc(a.created_at) + '</div>';
    box.appendChild(el);
  });
  $('#actions').hidden = false;
};
$('#acts-close').onclick = () => $('#actions').hidden = true;

// ── проводник (правая панель) ──
function human(n){ const u=['Б','КБ','МБ']; let i=0; while(n>=1024&&i<2){n/=1024;i++;} return (i?n.toFixed(1):n)+' '+u[i]; }
async function loadFiles(){
  const box = $('#rb-files'); box.innerHTML = '';
  if (!current) { box.innerHTML = '<div class="rb-empty">Открой чат.</div>'; return; }
  $('#rb-ext').href = 'http://' + location.hostname + ':23456/' + current;
  const d = await api('files_list&chat=' + encodeURIComponent(current));
  if (!d.files || !d.files.length) { box.innerHTML = '<div class="rb-empty">Файлов пока нет.<br>Попроси Искру: «создай файл …»</div>'; return; }
  d.files.forEach(f => {
    const el = document.createElement('div'); el.className = 'rb-file';
    el.innerHTML = '<span class="n">'+(f.img?'🖼':'📄')+' '+esc(f.name)+'</span><span class="sz">'+human(f.size)+'</span>';
    el.onclick = () => f.img ? openLightbox(f.url) : openFile(f.name);
    if (/\.(py|js)$/i.test(f.name)) {
      const run = document.createElement('button'); run.className='rbrun'; run.textContent='▶'; run.title='Запустить';
      run.onclick = async e => { e.stopPropagation(); run.disabled=true; run.textContent='…';
        const r = await api('run_file', {chat:current, name:f.name}); run.disabled=false; run.textContent='▶';
        addMsg('ai', 'Запустила `'+f.name+'`:\n\n```\n'+(r.out||'')+'\n```'); };
      el.appendChild(run);
    }
    const del = document.createElement('button'); del.className='rbdel'; del.textContent='×';
    del.onclick = async e => { e.stopPropagation(); if(!confirm('Удалить '+f.name+'?'))return;
      await api('file_del', {chat:current, name:f.name}); loadFiles(); };
    el.appendChild(del); box.appendChild(el);
  });
}
$('#files').onclick = () => { app.classList.toggle('showfiles'); if (app.classList.contains('showfiles')) loadFiles(); };
$('#rb-close').onclick = () => app.classList.remove('showfiles');
$('#rb-refresh').onclick = loadFiles;

// просмотр/правка файла
let vName = '';
async function openFile(name){
  const d = await api('file_get&chat=' + encodeURIComponent(current) + '&name=' + encodeURIComponent(name));
  if (d.err) { alert('Не удалось открыть файл'); return; }
  vName = name; $('#v-name').textContent = name; $('#v-content').value = d.content || '';
  $('#viewer').hidden = false;
}
$('#v-close').onclick = () => $('#viewer').hidden = true;
$('#v-save').onclick = async () => { await api('file_put', {chat:current, name:vName, content:$('#v-content').value}); $('#viewer').hidden = true; loadFiles(); };
$('#v-del').onclick = async () => { if(!confirm('Удалить '+vName+'?'))return; await api('file_del', {chat:current, name:vName}); $('#viewer').hidden = true; loadFiles(); };

// ── характер ──
$('#gear').onclick = () => {
  $('#s-persona').value = cfg.persona || '';
  $('#s-anger').checked = !!cfg.allow_anger;
  $('#settings').hidden = false;
};
$('#s-cancel').onclick = () => $('#settings').hidden = true;
$('#s-save').onclick = async () => {
  cfg.persona = $('#s-persona').value;
  cfg.allow_anger = $('#s-anger').checked ? 1 : 0;
  await saveCfg();
  $('#settings').hidden = true;
};

loadChats();
// открыть чат из адреса при загрузке
(() => { const id = location.hash.slice(1); if (id) openChat(id, false); })();
// установка как приложение (PWA)
if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});

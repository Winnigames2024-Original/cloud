const $ = s => document.querySelector(s);
const app = $('#app'), msgs = $('#messages'), input = $('#input');
let current = null;
let cfg = { persona: '', allow_anger: 0, mode: 'auto', model: 'smart' };
let pending = [];   // прикреплённые файлы, ещё не отправленные

const api = (a, body) => fetch('/api/v1/index.php?action=' + a,
  body ? {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)} : {})
  .then(r => r.json());

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

function renderContent(text){
  const lines = String(text).split('\n');
  let html = '', buf = [];
  const flush = () => { if (buf.length){ html += '<div>' + esc(buf.join('\n')) + '</div>'; buf = []; } };
  lines.forEach(l => {
    if (l.startsWith('[img]')) { flush(); html += '<img class="genimg" src="' + esc(l.slice(5)) + '">'; }
    else if (l.startsWith('[file]')) { flush(); const p = l.slice(6).split('|');
      html += '<a class="filechip" href="' + esc(p[1]||'#') + '" target="_blank">📎 ' + esc(p[0]||'файл') + '</a>'; }
    else buf.push(l);
  });
  flush();
  return html;
}

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

function applyCfgToUI(){ $('#mode').value = cfg.mode||'auto'; $('#model').value = cfg.model||'smart'; }
async function loadCfg(id){
  const d = await api('settings_get&chat=' + encodeURIComponent(id));
  cfg = { persona:d.persona||'', allow_anger:+d.allow_anger||0, mode:d.mode||'auto', model:d.model||'smart' };
  applyCfgToUI();
}
async function saveCfg(){
  if (!current) current = crypto.randomUUID();
  await api('settings_set', {chat:current, persona:cfg.persona, allow_anger:cfg.allow_anger, mode:cfg.mode, model:cfg.model});
}

async function openChat(id, push = true){
  current = id;
  if (push) history.pushState({id}, '', '#' + id);
  if(isMobile()) app.classList.add('nosidebar');
  const d = await api('history&chat='+encodeURIComponent(id));
  msgs.innerHTML='';
  (d.messages||[]).forEach(m => addMsg(m.role, m.content, m.id));
  await loadCfg(id);
  loadChats();
}

function newChat(push = true){
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

$('#mode').onchange  = () => { cfg.mode  = $('#mode').value;  saveCfg(); };
$('#model').onchange = () => { cfg.model = $('#model').value; saveCfg(); };

async function send(){
  const text = input.value.trim();
  if(!text && !pending.length) return;
  if(!current){ current = crypto.randomUUID(); history.replaceState({id:current}, '', '#' + current); }
  const files = pending.slice(); pending = []; renderPreviews();
  input.value=''; input.style.height='auto';
  let shown = text;
  files.forEach(f => shown += '\n' + (f.img ? '[img]'+f.url : '[file]'+f.name+'|'+f.url));
  const urow = addMsg('user', shown || '(файл)');
  const typing = addMsg('ai', '…');
  $('#send').disabled = true;
  try {
    const d = await api('send', {chat:current, text, files});
    if (d.umid) urow.dataset.id = d.umid;
    if (d.id) typing.dataset.id = d.id;
    typing.querySelector('.bubble').innerHTML = renderContent(d.reply || 'Ошибка ответа');
  } catch(e){ typing.querySelector('.bubble').textContent = 'Ошибка сети'; }
  $('#send').disabled = false;
  loadChats();
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

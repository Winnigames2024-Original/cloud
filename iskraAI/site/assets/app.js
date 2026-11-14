const $ = s => document.querySelector(s);
const app = $('#app'), msgs = $('#messages'), input = $('#input');
let current = null;
const api = (a, body) => fetch('/api/v1/index.php?action=' + a,
  body ? {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)} : {})
  .then(r => r.json());

const isMobile = () => matchMedia('(max-width:720px)').matches;
if (isMobile()) app.classList.add('nosidebar');
$('#burger').onclick = () => app.classList.toggle('nosidebar');

function esc(t){ const d=document.createElement('div'); d.textContent=t; return d.innerHTML; }

function addMsg(role, text, id){
  $('#empty')?.remove();
  const row = document.createElement('div');
  row.className = 'row ' + role;
  if (id) row.dataset.id = id;
  row.innerHTML = '<div class="bubble">' + esc(text) + '</div>';
  const act = document.createElement('div');
  act.className = 'msg-actions';
  if (role === 'user') {
    const e = document.createElement('button'); e.textContent = '✎';
    e.title = 'Изменить'; e.onclick = () => editMsg(row); act.appendChild(e);
  }
  const d = document.createElement('button'); d.textContent = '×';
  d.title = 'Удалить'; d.onclick = () => delMsg(row); act.appendChild(d);
  row.appendChild(act);
  msgs.appendChild(row);
  msgs.scrollTop = msgs.scrollHeight;
  return row;
}

async function editMsg(row){
  const id = row.dataset.id; if (!id) return;
  const cur = row.querySelector('.bubble').textContent;
  const nv = prompt('Изменить сообщение:', cur);
  if (nv === null || !nv.trim()) return;
  await api('msg_edit', {id, text: nv});
  row.querySelector('.bubble').textContent = nv;
}

async function delMsg(row){
  const id = row.dataset.id;
  if (!confirm('Удалить это сообщение?')) return;
  if (id) await api('msg_delete', {id});
  row.remove();
}

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
      if (nv && nv.trim()) { await api('chat_rename',{chat:c.chat_id, title:nv}); loadChats(); }
    };
    el.querySelector('.del').onclick = async e => { e.stopPropagation();
      if (!confirm('Удалить чат целиком?')) return;
      await api('delete',{chat:c.chat_id});
      if (current===c.chat_id){ current=null; msgs.innerHTML=''; }
      loadChats();
    };
    list.appendChild(el);
  });
}

async function openChat(id){
  current = id;
  if(isMobile()) app.classList.add('nosidebar');
  const d = await api('history&chat='+encodeURIComponent(id));
  msgs.innerHTML='';
  (d.messages||[]).forEach(m => addMsg(m.role, m.content, m.id));
  loadChats();
}

$('#newchat').onclick = () => {
  current = null;
  msgs.innerHTML = '<div class="empty" id="empty"><div class="spark-big">◆</div><p>Новый чат. Напиши первое сообщение.</p></div>';
  loadChats();
  if(isMobile()) app.classList.add('nosidebar');
};

async function send(){
  const text = input.value.trim();
  if(!text) return;
  if(!current) current = crypto.randomUUID();
  input.value=''; input.style.height='auto';
  const urow = addMsg('user', text);
  const typing = addMsg('ai', '…');
  $('#send').disabled = true;
  try {
    const d = await api('send', {chat:current, text});
    if (d.umid) urow.dataset.id = d.umid;
    if (d.id) typing.dataset.id = d.id;
    typing.querySelector('.bubble').textContent = d.reply || 'Ошибка ответа';
  } catch(e){ typing.querySelector('.bubble').textContent = 'Ошибка сети'; }
  $('#send').disabled = false;
  loadChats();
}
$('#send').onclick = send;
input.addEventListener('keydown', e => { if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); send(); } });
input.addEventListener('input', () => { input.style.height='auto'; input.style.height=Math.min(input.scrollHeight,180)+'px'; });

$('#gear').onclick = async () => {
  if(!current) current = crypto.randomUUID();
  const d = await api('settings_get&chat='+encodeURIComponent(current));
  $('#s-persona').value = d.persona || '';
  $('#s-anger').checked = !!(+d.allow_anger);
  $('#settings').hidden = false;
};
$('#s-cancel').onclick = () => $('#settings').hidden = true;
$('#s-save').onclick = async () => {
  await api('settings_set', {chat:current, persona:$('#s-persona').value, allow_anger:$('#s-anger').checked?1:0});
  $('#settings').hidden = true;
};

loadChats();

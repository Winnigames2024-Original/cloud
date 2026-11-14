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

function addMsg(role, text){
  $('#empty')?.remove();
  const row = document.createElement('div');
  row.className = 'row ' + role;
  row.innerHTML = '<div class="bubble">' + esc(text) + '</div>';
  msgs.appendChild(row);
  msgs.scrollTop = msgs.scrollHeight;
  return row;
}

async function loadChats(){
  const d = await api('chats');
  const list = $('#chatlist'); list.innerHTML = '';
  (d.chats||[]).forEach(c => {
    const el = document.createElement('div');
    el.className = 'chat-item' + (c.chat_id===current?' active':'');
    el.innerHTML = '<span class="t">'+esc(c.title)+'</span><span class="del">×</span>';
    el.querySelector('.t').onclick = () => openChat(c.chat_id);
    el.querySelector('.del').onclick = async e => { e.stopPropagation();
      await api('delete',{chat:c.chat_id});
      if(current===c.chat_id){ current=null; msgs.innerHTML=''; }
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
  (d.messages||[]).forEach(m => addMsg(m.role, m.content));
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
  addMsg('user', text);
  const typing = addMsg('ai', '…');
  $('#send').disabled = true;
  try {
    const d = await api('send', {chat:current, text});
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

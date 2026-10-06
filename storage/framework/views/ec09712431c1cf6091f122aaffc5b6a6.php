<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
<title>PayYigi Support</title>
<?php echo $__env->make('support._styles', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<style>
.app{display:grid;grid-template-columns:320px 1fr;height:100%}
aside{background:var(--card);display:flex;flex-direction:column;min-height:0;border-right:1px solid var(--line)}
.side-head{padding:20px;background:var(--grad)}
.side-head img{display:block}
.side-head small{display:block;margin-top:10px;color:#F3D9F5;word-break:break-all}
.side-head .out{margin-top:8px;background:none;border:0;padding:0;color:#fff;text-decoration:underline;cursor:pointer;font-size:13px}
.new{margin:16px}
.list{overflow:auto;flex:1;padding:0 8px 16px}
.t{display:block;width:100%;text-align:left;border:0;background:transparent;border-radius:8px;padding:12px;cursor:pointer}
.t:hover,.t.on{background:var(--deep)}
.t b{display:block;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.t span{font-size:12px;color:var(--muted)}
.empty{padding:20px;color:var(--muted);font-size:14px}
main{display:flex;flex-direction:column;min-height:0;min-width:0}
.top{display:flex;align-items:center;gap:12px;padding:16px 20px;background:var(--card);border-bottom:1px solid var(--line)}
.top h2{margin:0;font-size:16px;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.back{display:none;background:none;border:0;font-size:20px;cursor:pointer;color:var(--text)}
.chip{font-size:12px;padding:3px 10px;border-radius:99px;background:var(--deep);color:var(--muted)}
.chip.escalated{background:rgba(228,51,225,.18);color:#F3B6F2}
.msgs{flex:1;overflow:auto;padding:24px 20px;display:flex;flex-direction:column;gap:12px}
.m{max-width:min(560px,85%);padding:12px 16px;border-radius:14px;white-space:pre-wrap;word-wrap:break-word}
.m.ai{align-self:flex-start;background:var(--card);border-bottom-left-radius:4px}
.m.user{align-self:flex-end;background:var(--grad);color:#fff;border-bottom-right-radius:4px}
.m.err{align-self:center;background:transparent;color:var(--danger);font-size:13px;padding:0}
.typing{display:inline-flex;gap:4px;padding:4px 0}
.typing i{width:7px;height:7px;border-radius:50%;background:var(--muted);animation:b 1s infinite}
.typing i:nth-child(2){animation-delay:.15s}.typing i:nth-child(3){animation-delay:.3s}
@keyframes b{0%,60%,100%{opacity:.3}30%{opacity:1}}
.composer{display:flex;gap:10px;padding:14px 20px;background:var(--card);border-top:1px solid var(--line)}
.composer textarea{flex:1;resize:none;max-height:140px;padding:12px 14px;border-radius:10px;border:1px solid var(--line);background:var(--deep)}
.blank{margin:auto;text-align:center;color:var(--muted);padding:20px}
.blank h2{color:var(--text);margin:0 0 6px}
@media (max-width:800px){
  .app{grid-template-columns:1fr}
  main{display:none}
  .app.chatting aside{display:none}
  .app.chatting main{display:flex}
  .back{display:block}
}
</style>
</head>
<body>
<div class="app" id="app">
  <aside>
    <div class="side-head">
      <img src="https://payyigi.com/payigi-logo-bg.png" alt="PayYigi" width="88">
      <small><?php echo e($email); ?></small>
      <button class="out" id="out" type="button">Sign out</button>
    </div>
    <button class="btn new" id="new" type="button">Start a new chat</button>
    <div class="list" id="list"></div>
  </aside>
  <main>
    <div class="top" id="top" hidden>
      <button class="back" id="back" type="button" aria-label="Back to chats">&larr;</button>
      <h2 id="title"></h2>
      <span class="chip" id="chip"></span>
    </div>
    <div class="msgs" id="msgs">
      <div class="blank"><h2>How can we help?</h2><p>Pick a chat on the left or start a new one.</p></div>
    </div>
    <form class="composer" id="form" hidden>
      <textarea id="input" rows="1" maxlength="2000" placeholder="Type your message…" aria-label="Message"></textarea>
      <button class="btn" id="send" type="submit">Send</button>
    </form>
  </main>
</div>

<script>
const csrf=document.querySelector('meta[name=csrf-token]').content;
const $=id=>document.getElementById(id);
let tickets=[],active=null,busy=false;

async function api(url,opt={}){
  const r=await fetch(url,{...opt,headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'}});
  if(r.status===401){location.href='/support?expired=1';throw new Error('expired')}
  const d=await r.json().catch(()=>({}));
  if(!r.ok)throw new Error(d.message||'Something went wrong. Try again.');
  return d.data??d;
}
const ago=s=>{if(!s)return'';const d=new Date(s);return d.toLocaleDateString(undefined,{day:'numeric',month:'short'})+' '+d.toLocaleTimeString(undefined,{hour:'2-digit',minute:'2-digit'})};

function renderList(){
  const l=$('list');l.replaceChildren();
  if(!tickets.length){const e=document.createElement('div');e.className='empty';e.textContent='No chats yet. Start a new chat to talk to our assistant.';l.append(e);return}
  tickets.forEach(t=>{
    const b=document.createElement('button');b.type='button';b.className='t'+(t.id===active?' on':'');
    const h=document.createElement('b');h.textContent=t.subject||'New chat';
    const s=document.createElement('span');s.textContent=t.reference+' · '+ago(t.last_message_at)+(t.status==='escalated'?' · with support team':'');
    b.append(h,s);b.onclick=()=>openTicket(t.id);l.append(b);
  });
}
function bubble(role,text){
  const d=document.createElement('div');d.className='m '+role;d.textContent=text;$('msgs').append(d);scroll();return d;
}
const scroll=()=>{const m=$('msgs');m.scrollTop=m.scrollHeight};
function setHeader(t){
  $('top').hidden=false;$('form').hidden=false;
  $('title').textContent=t.subject||'New chat';
  const c=$('chip');c.textContent=t.status==='escalated'?'With support team':t.status==='closed'?'Closed':'Open';
  c.className='chip '+t.status;
  $('input').disabled=t.status==='closed';$('send').disabled=t.status==='closed';
}
async function loadTickets(){tickets=await api('/support/tickets');renderList()}

async function openTicket(id){
  active=id;renderList();$('app').classList.add('chatting');
  const {ticket,messages}=await api('/support/tickets/'+id);
  setHeader(ticket);$('msgs').replaceChildren();messages.forEach(m=>bubble(m.role,m.body));$('input').focus();
}
$('new').onclick=async()=>{
  try{
    const {ticket,messages}=await api('/support/tickets',{method:'POST'});
    tickets.unshift(ticket);active=ticket.id;renderList();$('app').classList.add('chatting');
    setHeader(ticket);$('msgs').replaceChildren();messages.forEach(m=>bubble(m.role,m.body));$('input').focus();
  }catch(e){alert(e.message)}
};
$('back').onclick=()=>$('app').classList.remove('chatting');
$('out').onclick=async()=>{try{await api('/support/logout',{method:'POST'})}catch{}location.href='/support'};

$('form').addEventListener('submit',async e=>{
  e.preventDefault();
  const text=$('input').value.trim();
  if(!text||busy||!active)return;
  busy=true;$('send').disabled=true;$('input').value='';autoGrow();
  bubble('user',text);
  const wait=bubble('ai','');wait.innerHTML='<span class="typing"><i></i><i></i><i></i></span>';
  try{
    const d=await api('/support/tickets/'+active+'/messages',{method:'POST',body:JSON.stringify({message:text})});
    wait.textContent=d.ai.body;
    const i=tickets.findIndex(t=>t.id===active);if(i>-1)tickets[i]=d.ticket;
    tickets.sort((a,b)=>new Date(b.last_message_at)-new Date(a.last_message_at));
    setHeader(d.ticket);renderList();scroll();
  }catch(err){wait.remove();bubble('err',err.message)}
  busy=false;$('send').disabled=false;$('input').focus();
});
const autoGrow=()=>{const t=$('input');t.style.height='auto';t.style.height=Math.min(t.scrollHeight,140)+'px'};
$('input').addEventListener('input',autoGrow);
$('input').addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();$('form').requestSubmit()}});

loadTickets();
</script>
</body>
</html>
<?php /**PATH C:\Users\Admin\Desktop\payyigi-api\resources\views/support/portal.blade.php ENDPATH**/ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
<title>PayYigi Support</title>
<?php echo $__env->make('support._styles', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<style>
body{display:grid;place-items:center;padding:20px}
.box{width:100%;max-width:440px;background:var(--card);border-radius:16px;overflow:hidden}
.head{padding:32px 28px;text-align:center;background:var(--grad)}
.head h1{margin:14px 0 4px;font-size:26px;color:#fff}
.head p{margin:0;color:#F3D9F5;font-size:14px}
.body{padding:28px}
label{display:block;margin-bottom:6px;font-size:13px;color:var(--muted)}
input{width:100%;padding:13px 14px;border-radius:8px;border:1px solid var(--line);background:var(--deep)}
.btn{width:100%;margin-top:16px}
.note{margin-top:18px;padding:12px 14px;border-radius:8px;background:var(--deep);font-size:14px}
.err{color:var(--danger)}
</style>
</head>
<body>
<div class="box">
    <div class="head">
        <img src="https://payyigi.com/payigi-logo-bg.png" alt="PayYigi" width="96">
        <h1>PayYigi Support</h1>
        <p>Chat with our assistant about your account and transactions.</p>
    </div>
    <div class="body">
        <?php if($expired): ?>
            <div class="note err" style="margin:0 0 18px">That link has expired or your session ended. Enter your email to get a new link.</div>
        <?php endif; ?>
        <form id="f" novalidate>
            <label for="email">Your email address</label>
            <input id="email" type="email" autocomplete="email" placeholder="you@example.com" required>
            <button class="btn" id="go" type="submit">Send me a secure link</button>
        </form>
        <div id="msg" class="note" hidden role="status"></div>
    </div>
</div>
<script>
const csrf=document.querySelector('meta[name=csrf-token]').content;
const f=document.getElementById('f'),msg=document.getElementById('msg'),go=document.getElementById('go');
f.addEventListener('submit',async e=>{
  e.preventDefault();
  go.disabled=true;msg.hidden=true;msg.classList.remove('err');
  try{
    const r=await fetch('/support/request',{
      method:'POST',
      headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
      body:JSON.stringify({email:document.getElementById('email').value})
    });
    const d=await r.json().catch(()=>({}));
    if(r.status===419){msg.textContent='This page expired. Refresh it and try again.';msg.classList.add('err')}
    else if(r.status===429){msg.textContent='Too many attempts. Please wait a few minutes and try again.';msg.classList.add('err')}
    else{
      msg.textContent=r.ok?d.message:(d.errors?.email?.[0]||d.message||'Something went wrong. Try again.');
      if(!r.ok)msg.classList.add('err');
    }
  }catch{msg.textContent='Network error. Check your connection and try again.';msg.classList.add('err')}
  msg.hidden=false;go.disabled=false;
});
</script>
</body>
</html><?php /**PATH C:\Users\Admin\Desktop\payyigi-api\resources\views/support/request.blade.php ENDPATH**/ ?>
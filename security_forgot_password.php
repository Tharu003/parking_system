<?php
session_start();
require_once 'config/db.php';
if(!empty($_SESSION['security_logged_in'])){header('Location:index.php');exit;}
$step=(int)($_SESSION['security_reset_step']??1); $error=''; $success='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(isset($_POST['find_account'])){
  $username=trim($_POST['username']??'');
  $stmt=$conn->prepare("SELECT id,username,security_question FROM security_users WHERE username=? AND status='ACTIVE' LIMIT 1");
  $stmt->bind_param("s",$username);$stmt->execute();$u=$stmt->get_result()->fetch_assoc();$stmt->close();
  if(!$u){$error='ක්‍රියාකාරී ආරක්ෂක ගිණුමක් සොයාගත නොහැකි විය.';}else{$_SESSION['security_reset_user']=$u;$_SESSION['security_reset_step']=2;$step=2;}
 }elseif(isset($_POST['reset_password'])){
  $u=$_SESSION['security_reset_user']??null;$answer=strtolower(trim($_POST['security_answer']??''));$new=$_POST['new_password']??'';$confirm=$_POST['confirm_password']??'';
  if(!$u){$step=1;$error='යළි පිහිටුවීමේ සැසිය කල් ඉකුත් වී ඇත. කරුණාකර නැවත ආරම්භ කරන්න.';}
  elseif(strlen($new)<8||$new!==$confirm){$step=2;$error='මුරපදය අවම වශයෙන් අක්ෂර 8ක් විය යුතු අතර, මුරපද දෙකම එකිනෙකට ගැළපිය යුතුය.';}
  else{
   $stmt=$conn->prepare("SELECT id,security_answer FROM security_users WHERE id=? LIMIT 1");$stmt->bind_param("i",$u['id']);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
   if(!$row||!password_verify($answer,$row['security_answer'])){$step=2;$error='ආරක්ෂක ප්‍රශ්නයට පිළිතුර වැරදිය.';}
   else{$hash=password_hash($new,PASSWORD_DEFAULT);$up=$conn->prepare("UPDATE security_users SET password=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");$up->bind_param("si",$hash,$row['id']);$up->execute();$up->close();unset($_SESSION['security_reset_user'],$_SESSION['security_reset_step']);$step=3;$success='ඔබගේ මුරපදය සාර්ථකව යාවත්කාලීන කරන ලදී.';}
  }
 }
}
$stepLabels=['Find account','Verify','Done'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ParkSmart | Reset Password</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Noto+Sans+Sinhala:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;min-height:100vh;min-height:100dvh;background:linear-gradient(135deg,#07162e,#f6f8fc 50%);display:grid;place-items:center;font-family:'Plus Jakarta Sans','Noto Sans Sinhala',sans-serif;color:#0a192f;padding:24px}

.card{position:relative;width:min(480px,100%);background:#fff;border-radius:28px;padding:36px;border:1px solid #e2e8f0;box-shadow:0 25px 65px rgba(10,25,47,.15);overflow:hidden}
.card::before{content:"";position:absolute;left:0;right:0;top:0;height:5px;background:linear-gradient(90deg,#ff6b00,#ff9d38)}

/* header */
.head{display:flex;align-items:center;gap:15px;margin-bottom:26px}
.logo{width:56px;height:56px;flex:0 0 auto;border-radius:17px;background:linear-gradient(135deg,#ff6b00,#ff9d38);color:#fff;display:grid;place-items:center;font-size:24px;box-shadow:0 12px 26px rgba(255,107,0,.3)}
.h{font-size:23px;font-weight:800;color:#0a192f;margin:0;letter-spacing:-.3px;line-height:1.2}
.sub{font-size:13px;color:#64748b;margin:5px 0 0}

/* step indicator */
.steps{list-style:none;display:flex;margin:0 0 28px;padding:0}
.steps li{flex:1;position:relative;display:flex;flex-direction:column;align-items:center;gap:8px;font-size:11px;font-weight:700;color:#94a3b8;text-align:center}
.steps li::before{content:"";position:absolute;top:15px;left:-50%;width:100%;height:2px;background:#e2e8f0}
.steps li:first-child::before{display:none}
.steps b{position:relative;z-index:1;width:32px;height:32px;border-radius:50%;background:#fff;border:2px solid #e2e8f0;display:grid;place-items:center;font-size:12px;font-weight:800;color:#94a3b8}
.steps li.active{color:#0a192f}
.steps li.active b{border-color:#ff6b00;color:#ff6b00;box-shadow:0 0 0 4px rgba(255,107,0,.1)}
.steps li.done{color:#334155}
.steps li.done b{background:#10b981;border-color:#10b981;color:#fff}
.steps li.active::before,.steps li.done::before{background:#10b981}

/* alerts */
.alert{display:flex;gap:10px;align-items:flex-start;padding:13px 15px;border-radius:13px;margin-bottom:20px;font-size:13px;font-weight:700;line-height:1.7}
.alert i{margin-top:4px}
.bad{background:#fff1f2;color:#be123c;border:1px solid #fecdd3}
.good{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}

/* account chip + question */
.chip{display:inline-flex;align-items:center;gap:8px;max-width:100%;padding:8px 13px;border-radius:999px;background:#f6f8fc;border:1px solid #e2e8f0;font-size:12px;font-weight:700;color:#334155;margin-bottom:18px}
.chip i{color:#ff6b00}
.chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.q{padding:13px 15px;border-radius:12px;background:#f6f8fc;border:1.5px solid #e2e8f0;font-size:14px;font-weight:700;color:#0a192f;display:flex;gap:11px;align-items:center;line-height:1.5}
.q i{color:#ff6b00}

/* fields */
.group{margin-bottom:16px;min-width:0}
.group label{display:block;font-size:12px;font-weight:700;margin-bottom:7px;color:#334155}
.wrap{position:relative}
.wrap>i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px;pointer-events:none;transition:color .2s}
.wrap:focus-within>i{color:#ff6b00}
.wrap input{width:100%;height:48px;padding:0 15px 0 42px;border:1.5px solid #e2e8f0;border-radius:12px;outline:0;font:600 14px 'Plus Jakarta Sans','Noto Sans Sinhala',sans-serif;color:#0a192f;background:#fff;transition:border-color .2s,box-shadow .2s}
.wrap input::placeholder{color:#94a3b8;font-weight:500}
.wrap input:hover{border-color:#94a3b8}
.wrap input:focus{border-color:#ff6b00;box-shadow:0 0 0 4px rgba(255,107,0,.1)}
.wrap.has-toggle input{padding-right:46px}
.eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);width:36px;height:36px;border:0;border-radius:9px;background:transparent;color:#94a3b8;cursor:pointer;display:grid;place-items:center;font-size:14px}
.eye:hover{color:#334155;background:#f6f8fc}
.eye:focus-visible{outline:2px solid #ff6b00;outline-offset:1px}

.meter{display:flex;align-items:center;gap:10px;margin-top:9px}
.bars{flex:1;display:grid;grid-template-columns:repeat(4,1fr);gap:5px}
.bars i{height:5px;border-radius:5px;background:#e2e8f0;transition:background .25s}
.meter span{font-size:11px;font-weight:700;color:#64748b;min-width:64px;text-align:right}
.hint{margin:7px 0 0;font-size:11px;font-weight:600;color:#64748b;min-height:16px;line-height:1.6}
.hint.err{color:#be123c}.hint.ok{color:#047857}

/* buttons */
.btn{width:100%;min-height:52px;margin-top:8px;border:0;border-radius:13px;padding:14px;background:linear-gradient(135deg,#ff6b00,#ff8d2b);color:#fff;font:800 14px 'Plus Jakarta Sans','Noto Sans Sinhala',sans-serif;cursor:pointer;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:10px;box-shadow:0 12px 26px rgba(255,107,0,.28);transition:transform .15s,box-shadow .15s}
.btn:hover{transform:translateY(-1px);box-shadow:0 16px 32px rgba(255,107,0,.34)}
.btn:active{transform:translateY(0)}
.btn:focus-visible{outline:3px solid #0a192f;outline-offset:3px}
.back{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:20px;color:#64748b;font-size:13px;font-weight:700;text-decoration:none}
.back:hover{color:#0a192f}

/* success */
.done-box{text-align:center;padding-top:4px}
.tick{width:72px;height:72px;margin:0 auto 18px;border-radius:50%;background:#ecfdf5;border:1px solid #a7f3d0;color:#047857;display:grid;place-items:center;font-size:30px}
.done-box .alert{justify-content:center;text-align:left}

/* responsive */
@media(max-width:520px){
  body{padding:12px;place-items:start center}
  .card{padding:28px 20px 24px;border-radius:22px}
  .head{gap:12px;margin-bottom:22px}
  .logo{width:48px;height:48px;font-size:21px;border-radius:14px}
  .h{font-size:20px}
  .steps{margin-bottom:24px}
  .wrap input{font-size:16px} /* stops iOS zoom on focus */
}
@media(max-width:340px){
  body{padding:8px}
  .card{padding:24px 16px 20px}
  .steps li{font-size:10px}
}
@media(prefers-reduced-motion:reduce){*{transition:none!important}}
</style>
<?php include __DIR__ . "/includes/pwa.php"; ?>
</head>
<body>
<div class="card">

  <div class="head">
    <div class="logo"><i class="fa-solid fa-key"></i></div>
    <div>
      <h1 class="h">Security Password Reset</h1>
      <p class="sub">Recover your security account safely.</p>
    </div>
  </div>

  <ol class="steps" aria-label="Progress">
    <?php foreach($stepLabels as $i=>$label): $n=$i+1;
      $cls = ($step===3 || $n<$step) ? 'done' : ($n===$step ? 'active' : ''); ?>
      <li class="<?=$cls?>"<?=$n===$step&&$step<3?' aria-current="step"':''?>>
        <b><?php if($cls==='done'):?><i class="fa-solid fa-check"></i><?php else: echo $n; endif;?></b>
        <?=$label?>
      </li>
    <?php endforeach;?>
  </ol>

  <?php if($error):?><div class="alert bad" role="alert"><i class="fa-solid fa-circle-exclamation"></i><span><?=htmlspecialchars($error)?></span></div><?php endif;?>

  <?php if($step===3):?>
    <div class="done-box">
      <div class="tick"><i class="fa-solid fa-check"></i></div>
      <div class="alert good"><i class="fa-solid fa-circle-check"></i><span><?=htmlspecialchars($success)?></span></div>
      <a class="btn" href="index.php"><i class="fa-solid fa-right-to-bracket"></i> Go to Security Login</a>
    </div>

  <?php elseif($step===1):?>
    <form method="post" id="resetForm">
      <div class="group">
        <label for="username">Username</label>
        <div class="wrap"><i class="fa-solid fa-user"></i><input id="username" name="username" required autofocus autocomplete="username" placeholder="Enter your username"></div>
      </div>
      <button class="btn" name="find_account"><i class="fa-solid fa-magnifying-glass"></i> Find Account</button>
    </form>

  <?php else: $u=$_SESSION['security_reset_user'];?>
    <div class="chip"><i class="fa-solid fa-user"></i><span><?=htmlspecialchars($u['username']??'')?></span></div>
    <form method="post" id="resetForm">
      <div class="group">
        <label>Security question</label>
        <div class="q"><i class="fa-solid fa-circle-question"></i><span><?=htmlspecialchars($u['security_question'])?></span></div>
      </div>
      <div class="group">
        <label for="security_answer">Answer</label>
        <div class="wrap"><i class="fa-solid fa-comment"></i><input id="security_answer" name="security_answer" required autofocus autocomplete="off"></div>
      </div>
      <div class="group">
        <label for="new_password">New password</label>
        <div class="wrap has-toggle"><i class="fa-solid fa-lock"></i><input id="new_password" type="password" name="new_password" minlength="8" required autocomplete="new-password"><button type="button" class="eye" data-target="new_password" aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div>
        <div class="meter" aria-live="polite"><div class="bars"><i></i><i></i><i></i><i></i></div><span id="strengthLabel"></span></div>
      </div>
      <div class="group">
        <label for="confirm_password">Confirm password</label>
        <div class="wrap has-toggle"><i class="fa-solid fa-lock"></i><input id="confirm_password" type="password" name="confirm_password" minlength="8" required autocomplete="new-password"><button type="button" class="eye" data-target="confirm_password" aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div>
        <p class="hint" id="matchHint" aria-live="polite"></p>
      </div>
      <button class="btn" name="reset_password"><i class="fa-solid fa-rotate"></i> Update Password</button>
    </form>
  <?php endif;?>

  <a class="back" href="index.php"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
</div>

<script>
(function(){
  var form=document.getElementById('resetForm');
  if(!form) return;
  var pw=document.getElementById('new_password'), cf=document.getElementById('confirm_password');

  // Sinhala validation messages (replaces the browser's default pop-ups)
  var M={
    required:{username:'පරිශීලක නාමය ඇතුළත් කරන්න.',security_answer:'ආරක්ෂක ප්‍රශ්නයට පිළිතුර ඇතුළත් කරන්න.',new_password:'නව මුරපදය ඇතුළත් කරන්න.',confirm_password:'මුරපදය නැවත ඇතුළත් කරන්න.'},
    short:'මුරපදය අවම වශයෙන් අක්ෂර 8ක් විය යුතුය.',
    mismatch:'මුරපද දෙක එකිනෙකට ගැළපෙන්නේ නැත.'
  };
  function msg(el){
    var v=el.validity;
    if(v.valueMissing) return M.required[el.name]||'මෙම ක්ෂේත්‍රය පුරවන්න.';
    if(v.tooShort) return M.short;
    if(cf&&el===cf&&pw.value!==cf.value) return M.mismatch;
    return '';
  }
  form.querySelectorAll('input').forEach(function(el){
    el.addEventListener('invalid',function(){el.setCustomValidity('');var m=msg(el);if(m)el.setCustomValidity(m);});
    el.addEventListener('input',function(){el.setCustomValidity('');});
  });

  if(!pw) return; // step 1 has no password fields

  // show / hide password
  document.querySelectorAll('.eye').forEach(function(b){
    b.addEventListener('click',function(){
      var f=document.getElementById(b.dataset.target), show=f.type==='password';
      f.type=show?'text':'password';
      b.setAttribute('aria-label',show?'Hide password':'Show password');
      b.firstElementChild.className=show?'fa-regular fa-eye-slash':'fa-regular fa-eye';
    });
  });

  // strength meter
  var bars=document.querySelectorAll('.bars i'), label=document.getElementById('strengthLabel');
  var lv=[['',''],['දුර්වලයි','#be123c'],['මධ්‍යමයි','#ff6b00'],['හොඳයි','#10b981'],['ශක්තිමත්','#10b981']];
  function score(v){
    if(!v) return 0;
    var s=0;
    if(v.length>=8) s++;
    if(v.length>=12) s++;
    if(/[a-z]/.test(v)&&/[A-Z]/.test(v)&&/\d/.test(v)) s++;
    if(/[^A-Za-z0-9]/.test(v)) s++;
    return Math.max(s,1);
  }
  function paintStrength(){
    var s=score(pw.value);
    bars.forEach(function(b,i){ b.style.background=i<s?lv[s][1]:'#e2e8f0'; });
    label.textContent=lv[s][0]; label.style.color=lv[s][1]||'#64748b';
  }

  // match hint
  var hint=document.getElementById('matchHint');
  function paintMatch(){
    if(!cf.value){hint.textContent='';hint.className='hint';return;}
    var ok=pw.value===cf.value;
    hint.textContent=ok?'මුරපද ගැළපේ.':'මුරපද තවම ගැළපෙන්නේ නැත.';
    hint.className='hint '+(ok?'ok':'err');
  }
  pw.addEventListener('input',function(){paintStrength();paintMatch();cf.setCustomValidity('');});
  cf.addEventListener('input',paintMatch);

  // catch a password mismatch before the form is sent
  form.querySelector('button[name="reset_password"]').addEventListener('click',function(){
    cf.setCustomValidity('');
    if(cf.value&&pw.value!==cf.value) cf.setCustomValidity(M.mismatch);
  });
})();
</script>
</body>
</html>
<?php
session_start();
require_once 'config/db.php';

if (!empty($_SESSION['security_logged_in'])) { header('Location:index.php'); exit; }

$error=''; $success=false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $name=trim($_POST['full_name']??'');
    $username=trim($_POST['username']??'');
    $email=trim($_POST['email']??'');
    $phone=trim($_POST['phone']??'');
    $password=$_POST['password']??'';
    $confirm=$_POST['confirm_password']??'';
    $question=trim($_POST['security_question']??'');
    $answer=strtolower(trim($_POST['security_answer']??''));

    if($name===''||$username===''||$email===''||$password===''||$confirm===''||$question===''||$answer===''){
        $error='කරුණාකර අවශ්‍ය සියලු තොරතුරු පුරවන්න.';
    } elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)){
        $error='කරුණාකර වලංගු ඊමේල් ලිපිනයක් ඇතුළත් කරන්න.';
    } elseif(strlen($password)<8){
        $error='මුරපදය අවම වශයෙන් අක්ෂර 8ක් විය යුතුය.';
    } elseif($password!==$confirm){
        $error='මුරපද දෙක එකිනෙකට ගැළපෙන්නේ නැත.';
    } else {
        $check=$conn->prepare("SELECT id FROM security_users WHERE username=? OR email=? LIMIT 1");
        $check->bind_param("ss",$username,$email); $check->execute();
        if($check->get_result()->num_rows){
            $error='මෙම පරිශීලක නාමය හෝ ඊමේල් ලිපිනය දැනටමත් ලියාපදිංචි කර ඇත.';
        } else {
            $hash=password_hash($password,PASSWORD_DEFAULT);
            $answer_hash=password_hash($answer,PASSWORD_DEFAULT);
            $status='ACTIVE';
            $stmt=$conn->prepare("INSERT INTO security_users (full_name,username,email,phone,password,security_question,security_answer,status) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->bind_param("ssssssss",$name,$username,$email,$phone,$hash,$question,$answer_hash,$status);
            if($stmt->execute()){
                $success=true;
                $audit='New security account registered: '.$username;
                $log=$conn->prepare("INSERT INTO audit_logs (user_name,action) VALUES (?,?)");
                $log->bind_param("ss",$name,$audit); $log->execute(); $log->close();
            } else $error='ලියාපදිංචිය අසාර්ථක විය. කරුණාකර නැවත උත්සාහ කරන්න.';
            $stmt->close();
        }
        $check->close();
    }
}

$questions=['First School',"Mother's Maiden Name",'First Pet','Favorite Place'];
$selectedQ=$_POST['security_question']??'';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ParkSmart | Security Registration</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Noto+Sans+Sinhala:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;min-height:100vh;min-height:100dvh;font-family:'Plus Jakarta Sans','Noto Sans Sinhala',sans-serif;background:#f5f7fb;color:#172033;display:grid;place-items:center;padding:24px}

/* ---------- Shell: two panels on desktop, stacked on mobile ---------- */
.shell{width:min(1040px,100%);display:grid;grid-template-columns:minmax(300px,5fr) 7fr;background:#fff;border:1px solid #e2e8f0;border-radius:28px;box-shadow:0 25px 70px rgba(10,25,47,.1);overflow:hidden}

/* ---------- Brand panel ---------- */
.aside{position:relative;background:#0a192f;color:#fff;padding:44px 38px;display:flex;flex-direction:column;justify-content:space-between;gap:36px;overflow:hidden}
.aside::before{content:"";position:absolute;width:340px;height:340px;right:-140px;top:-120px;border-radius:50%;background:radial-gradient(circle,rgba(255,107,0,.35),rgba(255,107,0,0) 70%);pointer-events:none}
.aside::after{content:"P";position:absolute;right:-18px;bottom:-70px;font-size:300px;font-weight:800;line-height:1;color:rgba(255,255,255,.04);pointer-events:none;user-select:none}
.aside>*{position:relative;z-index:1}
.brand{display:flex;align-items:center;gap:14px}
.logo{width:55px;height:55px;flex:0 0 auto;border-radius:16px;background:linear-gradient(135deg,#ff6b00,#ff9d38);color:#fff;display:grid;place-items:center;font-size:24px;box-shadow:0 12px 28px rgba(255,107,0,.35)}
.brand strong{display:block;font-size:20px;font-weight:800;letter-spacing:.2px}
.brand span{display:block;margin-top:2px;font-size:12px;color:#94a3b8;font-weight:500}
.pitch h1{margin:0 0 12px;font-size:30px;line-height:1.2;font-weight:800;letter-spacing:-.4px}
.pitch p{margin:0;color:#94a3b8;font-size:14px;line-height:1.65;max-width:34ch}
.steps{list-style:none;margin:0;padding:0;display:grid;gap:16px}
.steps li{display:flex;gap:14px;align-items:flex-start;font-size:13px;line-height:1.5;color:#e2e8f0}
.steps b{flex:0 0 auto;width:28px;height:28px;border-radius:9px;background:rgba(255,107,0,.16);border:1px solid rgba(255,157,56,.45);color:#ff9d38;display:grid;place-items:center;font-size:12px;font-weight:800}
.steps small{display:block;color:#94a3b8;font-size:12px;margin-top:2px}

/* ---------- Form panel ---------- */
.main{padding:44px 44px 34px;min-width:0}
.main h2{margin:0;font-size:24px;font-weight:800;color:#0a192f;letter-spacing:-.3px}
.main .sub{margin:6px 0 24px;color:#64748b;font-size:13px}

.alert{display:flex;gap:10px;align-items:flex-start;padding:13px 15px;border-radius:13px;margin-bottom:20px;font-size:13px;font-weight:700;line-height:1.7}
.alert i{margin-top:2px}
.bad{background:#fff1f2;border:1px solid #fecdd3;color:#be123c}
.good{background:#ecfdf5;border:1px solid #a7f3d0;color:#047857}

fieldset{border:0;margin:0 0 6px;padding:0;min-width:0}
legend{display:flex;align-items:center;gap:10px;width:100%;padding:0;margin:0 0 14px;font-size:12px;font-weight:800;color:#334155;letter-spacing:.2px}
legend::after{content:"";flex:1;height:1px;background:#e2e8f0}
fieldset+fieldset{margin-top:22px}

.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.full{grid-column:1/-1}
.group{min-width:0}
.group label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:7px}
.group label em{font-style:normal;color:#94a3b8;font-weight:600}

.input{position:relative}
.input>i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px;pointer-events:none;transition:color .2s}
.input:focus-within>i{color:#ff6b00}
.input input,.input select{width:100%;height:48px;padding:0 15px 0 42px;border:1.5px solid #e2e8f0;border-radius:12px;outline:0;font:600 14px 'Plus Jakarta Sans','Noto Sans Sinhala',sans-serif;color:#172033;background:#fff;transition:border-color .2s,box-shadow .2s}
.input select{appearance:none;-webkit-appearance:none;padding-right:40px;cursor:pointer;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'><path d='M1 1.5l5 5 5-5' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/></svg>");background-repeat:no-repeat;background-position:right 16px center}
.input input::placeholder{color:#94a3b8;font-weight:500}
.input input:hover,.input select:hover{border-color:#94a3b8}
.input input:focus,.input select:focus{border-color:#ff6b00;box-shadow:0 0 0 4px rgba(255,107,0,.1)}
.input.has-toggle input{padding-right:46px}
.eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);width:36px;height:36px;border:0;border-radius:9px;background:transparent;color:#94a3b8;cursor:pointer;display:grid;place-items:center;font-size:14px}
.eye:hover{color:#334155;background:#f5f7fb}
.eye:focus-visible{outline:2px solid #ff6b00;outline-offset:1px}

/* password strength */
.meter{display:flex;align-items:center;gap:10px;margin-top:9px}
.bars{flex:1;display:grid;grid-template-columns:repeat(4,1fr);gap:5px}
.bars i{height:5px;border-radius:5px;background:#e2e8f0;transition:background .25s}
.meter span{font-size:11px;font-weight:700;color:#64748b;min-width:64px;text-align:right}
.hint{margin:7px 0 0;font-size:11px;font-weight:600;color:#64748b;min-height:16px}
.hint.err{color:#be123c}.hint.ok{color:#047857}

.btn{margin-top:26px;width:100%;min-height:52px;border:0;border-radius:13px;padding:14px;background:linear-gradient(135deg,#ff6b00,#ff8d2b);color:#fff;font:800 14px 'Plus Jakarta Sans','Noto Sans Sinhala',sans-serif;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;box-shadow:0 12px 26px rgba(255,107,0,.28);transition:transform .15s,box-shadow .15s}
.btn:hover{transform:translateY(-1px);box-shadow:0 16px 32px rgba(255,107,0,.34)}
.btn:active{transform:translateY(0)}
.btn:focus-visible{outline:3px solid #0a192f;outline-offset:3px}
a.btn{text-decoration:none}

.bottom{text-align:center;margin-top:22px;font-size:13px}
.bottom a{color:#2563eb;font-weight:800;text-decoration:none}
.bottom a:hover{text-decoration:underline}

/* success */
.done{text-align:center;padding:18px 0 4px}
.done .tick{width:72px;height:72px;margin:0 auto 18px;border-radius:50%;background:#ecfdf5;border:1px solid #a7f3d0;color:#047857;display:grid;place-items:center;font-size:30px}
.done .alert{justify-content:center;text-align:left}

/* ---------- Responsive ---------- */
@media(max-width:900px){
  body{place-items:start center}
  .shell{grid-template-columns:1fr;max-width:640px}
  .aside{padding:26px 28px;gap:0}
  .aside::after{font-size:200px;right:-10px;bottom:-50px}
  .pitch,.steps{display:none}
  .main{padding:30px 28px 26px}
}
@media(max-width:640px){
  body{padding:12px}
  .shell{border-radius:22px}
  .aside{padding:22px 22px}
  .logo{width:48px;height:48px;font-size:21px;border-radius:14px}
  .main{padding:26px 20px 22px}
  .main h2{font-size:21px}
  .grid{grid-template-columns:1fr;gap:14px}
  .full{grid-column:auto}
  .input input,.input select{font-size:16px} /* stops iOS zoom on focus */
}
@media(max-width:360px){
  body{padding:8px}
  .main{padding:22px 16px 18px}
}
@media(prefers-reduced-motion:reduce){*{transition:none!important}}
</style>
<?php include __DIR__ . "/includes/pwa.php"; ?>
</head>
<body>
<div class="shell">

  <aside class="aside">
    <div class="brand">
      <div class="logo"><i class="fa-solid fa-user-plus"></i></div>
      <div><strong>ParkSmart</strong><span>Security portal</span></div>
    </div>

    <div class="pitch">
      <h1>Join the security team</h1>
      <p>Create your staff account to manage vehicle entry and exit at the gate.</p>
    </div>

    <ol class="steps">
      <li><b>1</b><div>Enter your details<small>Name, username, email and phone</small></div></li>
      <li><b>2</b><div>Set a password<small>At least 8 characters</small></div></li>
      <li><b>3</b><div>Add a recovery question<small>Used if you forget your password</small></div></li>
    </ol>
  </aside>

  <main class="main">
    <h2>Security Staff Registration</h2>
    <p class="sub">Create a ParkSmart security account</p>

    <?php if($error):?><div class="alert bad" role="alert"><i class="fa-solid fa-circle-exclamation"></i><span><?=htmlspecialchars($error)?></span></div><?php endif;?>

    <?php if($success):?>
      <div class="done">
        <div class="tick"><i class="fa-solid fa-check"></i></div>
        <div class="alert good"><i class="fa-solid fa-circle-check"></i><span>ලියාපදිංචිය සාර්ථකව අවසන් විය. දැන් ඔබට පිවිසිය හැක.</span></div>
        <a class="btn" href="index.php"><i class="fa-solid fa-right-to-bracket"></i> Go to Security Login</a>
      </div>
    <?php else:?>
      <form method="post" id="regForm">

        <fieldset>
          <legend>Personal details</legend>
          <div class="grid">
            <div class="group">
              <label for="full_name">Full name</label>
              <div class="input"><i class="fa-solid fa-id-card"></i><input id="full_name" name="full_name" required autocomplete="name" value="<?=htmlspecialchars($_POST['full_name']??'')?>"></div>
            </div>
            <div class="group">
              <label for="username">Username</label>
              <div class="input"><i class="fa-solid fa-user"></i><input id="username" name="username" required autocomplete="username" value="<?=htmlspecialchars($_POST['username']??'')?>"></div>
            </div>
            <div class="group">
              <label for="email">Email</label>
              <div class="input"><i class="fa-solid fa-envelope"></i><input id="email" type="email" name="email" required autocomplete="email" inputmode="email" value="<?=htmlspecialchars($_POST['email']??'')?>"></div>
            </div>
            <div class="group">
              <label for="phone">Phone <em>(optional)</em></label>
              <div class="input"><i class="fa-solid fa-phone"></i><input id="phone" type="tel" name="phone" autocomplete="tel" inputmode="tel" value="<?=htmlspecialchars($_POST['phone']??'')?>"></div>
            </div>
          </div>
        </fieldset>

        <fieldset>
          <legend>Password</legend>
          <div class="grid">
            <div class="group">
              <label for="password">Password</label>
              <div class="input has-toggle"><i class="fa-solid fa-lock"></i><input id="password" type="password" name="password" minlength="8" required autocomplete="new-password"><button type="button" class="eye" data-target="password" aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div>
              <div class="meter" aria-live="polite"><div class="bars"><i></i><i></i><i></i><i></i></div><span id="strengthLabel"></span></div>
            </div>
            <div class="group">
              <label for="confirm_password">Confirm password</label>
              <div class="input has-toggle"><i class="fa-solid fa-lock"></i><input id="confirm_password" type="password" name="confirm_password" minlength="8" required autocomplete="new-password"><button type="button" class="eye" data-target="confirm_password" aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div>
              <p class="hint" id="matchHint" aria-live="polite"></p>
            </div>
          </div>
        </fieldset>

        <fieldset>
          <legend>Account recovery</legend>
          <div class="grid">
            <div class="group">
              <label for="security_question">Security question</label>
              <div class="input"><i class="fa-solid fa-circle-question"></i>
                <select id="security_question" name="security_question" required>
                  <option value="">Choose a question</option>
                  <?php foreach($questions as $q):?><option value="<?=htmlspecialchars($q)?>"<?=$selectedQ===$q?' selected':''?>><?=htmlspecialchars($q)?></option><?php endforeach;?>
                </select>
              </div>
            </div>
            <div class="group">
              <label for="security_answer">Security answer</label>
              <div class="input"><i class="fa-solid fa-comment"></i><input id="security_answer" name="security_answer" required autocomplete="off"></div>
            </div>
          </div>
        </fieldset>

        <button class="btn" name="register"><i class="fa-solid fa-user-shield"></i> Register Security Account</button>
      </form>
    <?php endif;?>

    <div class="bottom"><a href="index.php"><i class="fa-solid fa-arrow-left"></i> Back to Security Login</a></div>
  </main>
</div>

<script>
(function(){
  var pw=document.getElementById('password'), cf=document.getElementById('confirm_password');
  if(!pw) return;

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
  var steps=[['',''],['දුර්වලයි','#be123c'],['මධ්‍යමයි','#ff6b00'],['හොඳයි','#047857'],['ශක්තිමත්','#047857']];
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
    bars.forEach(function(b,i){ b.style.background=i<s?steps[s][1]:'#e2e8f0'; });
    label.textContent=steps[s][0]; label.style.color=steps[s][1]||'#64748b';
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

  // Sinhala validation messages (replaces the browser's default pop-ups)
  var M={
    required:{full_name:'ඔබගේ සම්පූර්ණ නම ඇතුළත් කරන්න.',username:'පරිශීලක නාමය ඇතුළත් කරන්න.',email:'ඊමේල් ලිපිනය ඇතුළත් කරන්න.',password:'මුරපදය ඇතුළත් කරන්න.',confirm_password:'මුරපදය නැවත ඇතුළත් කරන්න.',security_question:'ආරක්ෂක ප්‍රශ්නයක් තෝරන්න.',security_answer:'ආරක්ෂක ප්‍රශ්නයට පිළිතුර ඇතුළත් කරන්න.'},
    email:'කරුණාකර වලංගු ඊමේල් ලිපිනයක් ඇතුළත් කරන්න.',
    short:'මුරපදය අවම වශයෙන් අක්ෂර 8ක් විය යුතුය.',
    mismatch:'මුරපද දෙක එකිනෙකට ගැළපෙන්නේ නැත.'
  };
  function msg(el){
    var v=el.validity;
    if(v.valueMissing) return M.required[el.name]||'මෙම ක්ෂේත්‍රය පුරවන්න.';
    if(v.typeMismatch&&el.type==='email') return M.email;
    if(v.tooShort) return M.short;
    if(el===cf&&pw.value!==cf.value) return M.mismatch;
    return '';
  }
  var form=document.getElementById('regForm');
  form.querySelectorAll('input,select').forEach(function(el){
    el.addEventListener('invalid',function(){el.setCustomValidity('');var m=msg(el);if(m)el.setCustomValidity(m);});
    ['input','change'].forEach(function(ev){el.addEventListener(ev,function(){el.setCustomValidity('');});});
  });
  form.querySelector('button[name="register"]').addEventListener('click',function(){
    cf.setCustomValidity('');
    if(cf.value&&pw.value!==cf.value) cf.setCustomValidity(M.mismatch);
  });
})();
</script>
</body>
</html>
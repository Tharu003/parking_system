<?php
session_start();
require_once 'config/db.php';

if (!empty($_SESSION['security_logged_in'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'කරුණාකර ඔබගේ පරිශීලක නාමය සහ මුරපදය ඇතුළත් කරන්න.';
    } else {
        $stmt = $conn->prepare("SELECT id, full_name, username, email, password, status FROM security_users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || $user['status'] !== 'ACTIVE' || !password_verify($password, $user['password'])) {
            $error = $user && $user['status'] !== 'ACTIVE'
                ? 'මෙම ආරක්ෂක ගිණුම ක්‍රියාකාරී නොවේ. කරුණාකර පරිපාලක අමතන්න.'
                : 'පරිශීලක නාමය හෝ මුරපදය වැරදිය.';
        } else {
            session_regenerate_id(true);
            $_SESSION['security_logged_in'] = true;
            $_SESSION['security_id'] = (int)$user['id'];
            $_SESSION['security_name'] = $user['full_name'];
            $_SESSION['security_username'] = $user['username'];
            $_SESSION['security_email'] = $user['email'];

            $name = $user['full_name'];
            $action = 'Security staff logged in';
            $log = $conn->prepare("INSERT INTO audit_logs (user_name, action) VALUES (?, ?)");
            $log->bind_param("ss", $name, $action);
            $log->execute();
            $log->close();

            header('Location: dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ParkSmart | Security Login</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=Noto+Sans+Sinhala:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;min-height:100vh;min-height:100dvh;font-family:'Plus Jakarta Sans','Noto Sans Sinhala',sans-serif;background:#f5f7fb;color:#172033;display:grid;grid-template-columns:1.05fr 1fr}

/* ---------- Left brand panel ---------- */
.left{background:linear-gradient(145deg,#07162e,#0b2344 55%,#07162e);color:#fff;padding:clamp(32px,5vw,55px);display:flex;flex-direction:column;justify-content:space-between;gap:32px;position:relative;overflow:hidden}
.left:before{content:"";position:absolute;width:420px;height:420px;border-radius:50%;background:radial-gradient(circle,rgba(255,107,0,.3),transparent 68%);right:-160px;top:-150px}
.left:after{content:"";position:absolute;width:360px;height:360px;border-radius:50%;background:radial-gradient(circle,rgba(37,99,235,.25),transparent 68%);left:-160px;bottom:-150px}
.left>*{position:relative;z-index:1}
.brand{display:flex;align-items:center;gap:13px}
.logo{width:52px;height:52px;flex:0 0 auto;border-radius:15px;background:linear-gradient(135deg,#ff6b00,#ff9d38);color:#fff;display:grid;place-items:center;font-size:24px;box-shadow:0 10px 25px rgba(255,107,0,.3)}
.brand h1{margin:0;font-size:24px;letter-spacing:.3px}
.brand h1 span{color:#ff7a19}
.brand small{display:block;margin-top:2px;color:#9fb0c7;font-size:12px;font-weight:500}
.hero{text-align:center}
.shield{width:150px;height:150px;border-radius:38px;margin:0 auto 24px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.13);display:grid;place-items:center;font-size:72px;color:#ff7a19;box-shadow:0 20px 50px rgba(0,0,0,.2)}
.hero h2{font-size:clamp(25px,2.6vw,31px);margin:0 0 10px;letter-spacing:-.3px}
.hero p{color:#aebdd0;max-width:470px;margin:auto;line-height:1.7;font-size:15px}
.features{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.feature{padding:15px 10px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.05);border-radius:15px;text-align:center}
.feature i{color:#ff8a32;font-size:20px}
.feature b{display:block;font-size:12px;margin-top:8px;font-weight:700}

/* ---------- Right / login card ---------- */
.right{display:grid;place-items:center;padding:30px}
.stack{width:min(440px,100%)}
.mbrand{display:none;align-items:center;justify-content:center;gap:12px;margin-bottom:22px}
.mbrand .logo{width:46px;height:46px;font-size:21px;border-radius:14px}
.mbrand strong{display:block;font-size:20px;color:#07162e;letter-spacing:.3px;line-height:1.1}
.mbrand strong span{color:#ff7a19}
.mbrand small{display:block;margin-top:3px;color:#69778c;font-size:11px;font-weight:500}

.card{width:100%;background:#fff;border:1px solid #e4e9f1;border-radius:25px;padding:38px;box-shadow:0 24px 60px rgba(16,30,54,.1)}
.icon{width:58px;height:58px;border-radius:17px;background:#eff6ff;color:#2563eb;display:grid;place-items:center;font-size:25px;margin-bottom:17px}
.card h2{margin:0;font-size:25px;color:#07162e;letter-spacing:-.3px}
.sub{color:#69778c;font-size:13px;margin:7px 0 25px;line-height:1.6}

.alert{display:flex;gap:10px;align-items:flex-start;padding:13px 15px;border-radius:12px;background:#fff1f2;border:1px solid #fecdd3;color:#be123c;font-size:13px;font-weight:700;margin-bottom:18px;line-height:1.7}
.alert i{margin-top:4px}

.group{margin-bottom:17px}
.group label{display:block;font-size:12px;font-weight:700;color:#31415a;margin-bottom:7px}
.input{position:relative}
.input>i{position:absolute;left:15px;top:50%;transform:translateY(-50%);color:#8995a7;font-size:14px;pointer-events:none;transition:color .2s}
.input:focus-within>i{color:#ff6b00}
.input input{width:100%;height:50px;padding:0 15px 0 43px;border:1.5px solid #e1e7ef;border-radius:13px;outline:none;font:600 14px 'Plus Jakarta Sans','Noto Sans Sinhala',sans-serif;color:#172033;background:#fff;transition:border-color .2s,box-shadow .2s}
.input input::placeholder{color:#8995a7;font-weight:500}
.input input:hover{border-color:#8995a7}
.input input:focus{border-color:#ff6b00;box-shadow:0 0 0 4px rgba(255,107,0,.1)}
.input.has-toggle input{padding-right:48px}
.eye{position:absolute;right:7px;top:50%;transform:translateY(-50%);width:36px;height:36px;border:0;border-radius:9px;background:none;color:#718096;cursor:pointer;display:grid;place-items:center;font-size:14px}
.eye:hover{background:#f5f7fb;color:#172033}
.eye:focus-visible{outline:2px solid #ff6b00;outline-offset:1px}

.btn{width:100%;min-height:52px;margin-top:6px;padding:15px;border:0;border-radius:13px;background:linear-gradient(135deg,#ff6b00,#ff8d2b);color:#fff;font:800 15px 'Plus Jakarta Sans','Noto Sans Sinhala',sans-serif;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;box-shadow:0 12px 25px rgba(255,107,0,.25);transition:transform .15s,box-shadow .15s}
.btn:hover{transform:translateY(-1px);box-shadow:0 16px 32px rgba(255,107,0,.32)}
.btn:active{transform:translateY(0)}
.btn:focus-visible{outline:3px solid #07162e;outline-offset:3px}

.links{display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px 16px;margin-top:20px;padding-top:18px;border-top:1px solid #e4e9f1;font-size:12px;font-weight:800}
.links a{color:#2563eb;text-decoration:none}
.links a:hover{text-decoration:underline}
.back{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:22px;color:#738097;text-decoration:none;font-size:13px;font-weight:700}
.back:hover{color:#172033}

/* ---------- Responsive ---------- */
@media(max-width:1100px) and (min-width:901px){
  .shield{width:120px;height:120px;font-size:56px;border-radius:32px}
  .features{gap:10px}
  .feature{padding:13px 6px}
  .card{padding:32px 28px}
}
@media(max-height:700px) and (min-width:901px){
  .left{gap:20px;padding-top:32px;padding-bottom:32px}
  .shield{width:96px;height:96px;font-size:44px;border-radius:26px;margin-bottom:16px}
  .hero p{font-size:14px;line-height:1.55}
}
@media(max-width:900px){
  body{grid-template-columns:1fr}
  .left{display:none}
  .mbrand{display:flex}
  .right{min-height:100vh;min-height:100dvh;padding:22px 18px}
  .card{padding:30px 24px}
}
@media(max-width:480px){
  .right{padding:16px 12px}
  .card{padding:26px 18px;border-radius:22px}
  .icon{width:52px;height:52px;font-size:22px;border-radius:15px}
  .card h2{font-size:22px}
  .input input{font-size:16px} /* stops iOS zoom on focus */
  .links{flex-direction:column;align-items:flex-start}
}
@media(max-width:340px){
  .right{padding:10px 8px}
  .card{padding:22px 14px}
}
@media(prefers-reduced-motion:reduce){*{transition:none!important}}
</style>
<?php include __DIR__ . "/includes/pwa.php"; ?>
</head>
<body>

<section class="left">
  <div class="brand">
    <div class="logo"><i class="fa-solid fa-square-parking"></i></div>
    <div><h1>PARK<span>SMART</span></h1><small>Security control system</small></div>
  </div>
  <div class="hero">
    <div class="shield"><i class="fa-solid fa-shield-halved"></i></div>
    <h2>Secure Entry &amp; Exit</h2>
    <p>Authorized security staff can issue parking tickets, process vehicle exits and manage VIP vehicle verification.</p>
  </div>
  <div class="features">
    <div class="feature"><i class="fa-solid fa-ticket"></i><b>Ticketing</b></div>
    <div class="feature"><i class="fa-solid fa-qrcode"></i><b>QR / Search</b></div>
    <div class="feature"><i class="fa-solid fa-crown"></i><b>VIP Check</b></div>
  </div>
</section>

<section class="right">
  <div class="stack">

    <div class="mbrand">
      <div class="logo"><i class="fa-solid fa-square-parking"></i></div>
      <div><strong>PARK<span>SMART</span></strong><small>Security control system</small></div>
    </div>

    <div class="card">
      <div class="icon"><i class="fa-solid fa-user-shield"></i></div>
      <h2>Security Login</h2>
      <div class="sub">Sign in with your authorized security account.</div>

      <?php if($error): ?><div class="alert" role="alert"><i class="fa-solid fa-circle-exclamation"></i><span><?=htmlspecialchars($error)?></span></div><?php endif; ?>

      <form method="post" id="loginForm">
        <div class="group">
          <label for="username">Username</label>
          <div class="input"><i class="fa-solid fa-user"></i><input id="username" name="username" required autocomplete="username" placeholder="Enter username"></div>
        </div>
        <div class="group">
          <label for="password">Password</label>
          <div class="input has-toggle"><i class="fa-solid fa-lock"></i><input type="password" id="password" name="password" required autocomplete="current-password" placeholder="Enter password"><button type="button" class="eye" id="eyeBtn" aria-label="Show password"><i id="eye" class="fa-solid fa-eye"></i></button></div>
        </div>
        <button class="btn" name="login"><i class="fa-solid fa-right-to-bracket"></i> Secure Login</button>
      </form>

      <div class="links">
        <a href="security_register.php"><i class="fa-solid fa-user-plus"></i> New Security Staff? Register</a>
        <a href="security_forgot_password.php">Forgot Password?</a>
      </div>
      <a class="back" href="admin_login.php"><i class="fa-solid fa-arrow-left"></i> Admin Login</a>
    </div>

  </div>
</section>

<script>
(function(){
  var p=document.getElementById('password'), e=document.getElementById('eye'), b=document.getElementById('eyeBtn');
  b.addEventListener('click',function(){
    var show=p.type==='password';
    p.type=show?'text':'password';
    e.className=show?'fa-solid fa-eye-slash':'fa-solid fa-eye';
    b.setAttribute('aria-label',show?'Hide password':'Show password');
  });

  // Sinhala validation messages (replaces the browser's default pop-ups)
  var M={username:'පරිශීලක නාමය ඇතුළත් කරන්න.',password:'මුරපදය ඇතුළත් කරන්න.'};
  document.querySelectorAll('#loginForm input').forEach(function(el){
    el.addEventListener('invalid',function(){
      el.setCustomValidity('');
      if(el.validity.valueMissing) el.setCustomValidity(M[el.name]||'මෙම ක්ෂේත්‍රය පුරවන්න.');
    });
    el.addEventListener('input',function(){el.setCustomValidity('');});
  });
})();
</script>
</body>
</html>
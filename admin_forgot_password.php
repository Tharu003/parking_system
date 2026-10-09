<?php
session_start();
include 'config/db.php';

$step = 1;
$error_msg = "";
$success_msg = "";
$found_admin = null;

// STEP 1: Find Account
if (isset($_POST['find_account'])) {
    $username = trim($_POST['username']);
    $stmt = $conn->prepare("SELECT id, username, security_question, security_answer FROM admins WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        $_SESSION['reset_admin'] = $res->fetch_assoc();
        $step = 2;
    } else {
        $error_msg = "ඇතුළත් කළ Username එක හමු නොවීය!";
    }
}

// STEP 2: Reset Password
if (isset($_POST['reset_password'])) {
    $answer = strtolower(trim($_POST['security_answer']));
    $new_pass = trim($_POST['new_password']);
    $admin = $_SESSION['reset_admin'];

    if (password_verify($answer, $admin['security_answer']) || $answer === strtolower($admin['security_answer'])) {
        $hashed_new_pass = password_hash($new_pass, PASSWORD_BCRYPT);
        $update_stmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
        $update_stmt->bind_param("si", $hashed_new_pass, $admin['id']);

        if ($update_stmt->execute()) {
            unset($_SESSION['reset_admin']);
            $success_msg = "මුරපදය සාර්ථකව වෙනස් විය! දැන් Log විය හැක.";
            $step = 3;
        } else {
            $error_msg = "Password වෙනස් කිරීමට නොහැකි විය.";
            $step = 2;
        }
    } else {
        $error_msg = "Security Answer එක වැරදියි!";
        $step = 2;
    }
}

// Keep step 2 active on redisplay after a failed attempt within step 2
if (isset($_POST['reset_password']) && $step === 2 && isset($_SESSION['reset_admin'])) {
    $found_admin = $_SESSION['reset_admin'];
} elseif ($step === 2 && isset($_SESSION['reset_admin'])) {
    $found_admin = $_SESSION['reset_admin'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ParkSmart - Forgot Password</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-orange: #FF6B00;
            --orange-light: #FF9E00;
            --navy-blue: #0A192F;
            --light-blue: #2563EB;
            --soft-blue-bg: #EFF6FF;
            --bg-light: #F8FAFC;
            --white: #FFFFFF;
            --text-dark: #1E293B;
            --text-muted: #64748B;
            --border-color: #E2E8F0;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
        html, body { height: 100%; }

        body {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1.05fr 1fr;
            background: var(--white);
        }

        /* ============ LEFT: BRAND / ILLUSTRATION PANEL ============ */
        .brand-panel {
            position: relative;
            background: linear-gradient(155deg, var(--navy-blue) 0%, #112B4D 55%, var(--navy-blue) 100%);
            color: var(--white);
            padding: 50px 55px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
        }

        .brand-panel::before {
            content: '';
            position: absolute;
            width: 420px; height: 420px;
            background: radial-gradient(circle, rgba(255, 107, 0, 0.28) 0%, transparent 70%);
            top: -140px; right: -140px;
            border-radius: 50%;
        }
        .brand-panel::after {
            content: '';
            position: absolute;
            width: 320px; height: 320px;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.22) 0%, transparent 70%);
            bottom: -120px; left: -100px;
            border-radius: 50%;
        }

        .brand-panel > * { position: relative; z-index: 2; }

        .brand-logo-row { display: flex; align-items: center; gap: 14px; }
        .logo-icon {
            background: linear-gradient(135deg, var(--primary-orange), var(--orange-light));
            width: 52px; height: 52px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; color: var(--white);
            box-shadow: 0 10px 25px rgba(255, 107, 0, 0.35);
            flex-shrink: 0;
        }
        .brand-logo-row h1 { font-size: 22px; font-weight: 800; letter-spacing: 0.3px; }
        .brand-logo-row h1 span { color: var(--primary-orange); }
        .brand-logo-row p { font-size: 10.5px; color: rgba(255,255,255,0.55); letter-spacing: 1.5px; font-weight: 600; text-transform: uppercase; }

        /* Illustration */
        .illustration-wrap { display: flex; align-items: center; justify-content: center; margin: 10px 0; }

        /* Recovery step tracker on the brand panel */
        .recovery-steps {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .r-step {
            display: flex;
            align-items: center;
            gap: 14px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.1);
            padding: 14px 16px;
            border-radius: 14px;
            backdrop-filter: blur(6px);
            transition: 0.3s;
        }
        .r-step .r-num {
            width: 34px; height: 34px; border-radius: 10px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: rgba(255,255,255,0.1); color: rgba(255,255,255,0.5);
            font-size: 14px; font-weight: 800;
        }
        .r-step h4 { font-size: 13px; font-weight: 700; color: rgba(255,255,255,0.55); margin-bottom: 2px; }
        .r-step p { font-size: 11px; color: rgba(255,255,255,0.35); font-weight: 500; }

        .r-step.done .r-num { background: rgba(16, 185, 129, 0.2); color: #34D399; }
        .r-step.done h4 { color: rgba(255,255,255,0.85); }
        .r-step.active {
            background: rgba(255, 107, 0, 0.1);
            border-color: rgba(255, 107, 0, 0.35);
        }
        .r-step.active .r-num { background: var(--primary-orange); color: #FFF; }
        .r-step.active h4 { color: #FFFFFF; }
        .r-step.active p { color: rgba(255,255,255,0.6); }

        .brand-footer-note {
            font-size: 11px; color: rgba(255,255,255,0.4); font-weight: 500;
            border-top: 1px solid rgba(255,255,255,0.1); padding-top: 16px;
        }

        /* ============ RIGHT: FORM PANEL ============ */
        .form-panel {
            display: flex; align-items: center; justify-content: center;
            padding: 40px 30px; background: var(--bg-light);
        }

        .login-card {
            width: 100%; max-width: 400px;
            background: var(--white); border-radius: 24px; padding: 42px 36px;
            border: 1px solid var(--border-color);
            box-shadow: 0 20px 45px rgba(10, 25, 47, 0.08);
        }

        .mobile-brand { display: none; align-items: center; gap: 12px; margin-bottom: 24px; }
        .mobile-brand .logo-icon { width: 44px; height: 44px; font-size: 20px; border-radius: 12px; }
        .mobile-brand h1 { font-size: 18px; font-weight: 800; color: var(--navy-blue); }
        .mobile-brand h1 span { color: var(--primary-orange); }

        /* Mobile-only compact step dots */
        .mobile-step-dots { display: none; gap: 8px; margin-bottom: 20px; }
        .mobile-step-dots span { width: 26px; height: 5px; border-radius: 3px; background: var(--border-color); }
        .mobile-step-dots span.active { background: var(--primary-orange); }
        .mobile-step-dots span.done { background: #10B981; }

        .card-header { margin-bottom: 26px; }
        .card-header .badge-shield {
            width: 54px; height: 54px; border-radius: 16px;
            background: var(--soft-blue-bg);
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; color: var(--light-blue); margin-bottom: 16px;
        }
        .card-header.success .badge-shield { background: rgba(16, 185, 129, 0.12); color: #10B981; }
        .card-header h2 { font-size: 23px; font-weight: 800; color: var(--navy-blue); }
        .card-header p { font-size: 13px; color: var(--text-muted); margin-top: 5px; font-weight: 500; }

        .form-group { margin-bottom: 18px; }
        .form-label {
            display: block; color: var(--navy-blue); font-size: 11px; font-weight: 700;
            margin-bottom: 7px; text-transform: uppercase; letter-spacing: 0.4px;
        }
        .input-wrap { position: relative; }
        .input-wrap i.field-icon {
            position: absolute; left: 15px; top: 50%; transform: translateY(-50%);
            color: var(--text-muted); font-size: 14px; pointer-events: none;
        }
        .form-control {
            width: 100%; padding: 13px 16px 13px 42px;
            background: var(--bg-light);
            border: 1.5px solid var(--border-color); border-radius: 12px;
            color: var(--text-dark); font-size: 14px; font-weight: 600;
            outline: none; transition: 0.25s;
        }
        .form-control::placeholder { color: #A0AEC0; font-weight: 500; }
        .form-control:focus {
            border-color: var(--primary-orange); background: var(--white);
            box-shadow: 0 0 0 4px rgba(255, 107, 0, 0.12);
        }
        .form-control[readonly] { background: var(--soft-blue-bg); color: var(--light-blue); font-weight: 700; }

        .toggle-pass {
            position: absolute; right: 15px; top: 50%; transform: translateY(-50%);
            color: var(--text-muted); font-size: 14px; cursor: pointer; background: none; border: none;
        }

        .btn-submit {
            width: 100%; padding: 15px;
            background: linear-gradient(135deg, var(--primary-orange), var(--orange-light));
            border: none; border-radius: 12px; color: var(--white); font-size: 15px; font-weight: 700;
            cursor: pointer; transition: 0.3s; margin-top: 8px;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            box-shadow: 0 10px 25px rgba(255, 107, 0, 0.28);
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 14px 30px rgba(255, 107, 0, 0.4); }

        .btn-secondary {
            width: 100%; padding: 15px;
            background: var(--navy-blue); border: none; border-radius: 12px;
            color: var(--white); font-size: 15px; font-weight: 700; text-decoration: none;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            margin-top: 10px; transition: 0.3s;
        }
        .btn-secondary:hover { background: var(--light-blue); }

        .alert {
            padding: 12px 14px; border-radius: 12px; font-size: 13px;
            display: flex; align-items: center; gap: 8px; margin-bottom: 20px; font-weight: 600;
        }
        .alert-error { background: rgba(239, 68, 68, 0.08); color: #DC2626; border: 1px solid rgba(239, 68, 68, 0.25); }
        .alert-success { background: rgba(16, 185, 129, 0.08); color: #059669; border: 1px solid rgba(16, 185, 129, 0.25); }

        .success-icon-big {
            width: 78px; height: 78px; border-radius: 50%;
            background: rgba(16, 185, 129, 0.1); color: #10B981;
            display: flex; align-items: center; justify-content: center;
            font-size: 34px; margin: 6px auto 20px auto;
        }

        .back-site {
            display: inline-flex; align-items: center; gap: 8px; justify-content: center; width: 100%;
            margin-top: 22px; font-size: 12.5px; font-weight: 700;
            color: var(--text-muted); text-decoration: none;
        }
        .back-site:hover { color: var(--primary-orange); }

        @media (max-width: 980px) {
            body { grid-template-columns: 1fr; }
            .brand-panel { display: none; }
            .mobile-brand { display: flex; }
            .mobile-step-dots { display: flex; }
        }
        @media (max-width: 480px) {
            .login-card { padding: 32px 22px; border-radius: 18px; }
        }
    </style>
<?php include __DIR__ . "/includes/pwa.php"; ?></head>
<body>

    <!-- ============ LEFT BRAND PANEL ============ -->
    <div class="brand-panel">
        <div class="brand-logo-row">
            <div class="logo-icon"><i class="fa-solid fa-square-parking"></i></div>
            <div>
                <h1>PARK<span>SMART</span></h1>
                <p>Smart Urban Parking System</p>
            </div>
        </div>

        <div class="illustration-wrap">
            <svg width="260" height="200" viewBox="0 0 260 200" xmlns="http://www.w3.org/2000/svg">
                <circle cx="130" cy="95" r="78" fill="rgba(255,255,255,0.04)"/>
                <circle cx="130" cy="95" r="78" fill="none" stroke="rgba(255,107,0,0.35)" stroke-width="2" stroke-dasharray="6 8"/>
                <g transform="translate(90,55)">
                    <rect x="0" y="0" width="80" height="80" rx="18" fill="#FFFFFF"/>
                    <path d="M40 16 L58 26 V48 C58 62 50 70 40 74 C30 70 22 62 22 48 V26 Z" fill="#0A192F"/>
                    <path d="M32 43 L38 50 L50 35" stroke="#FF6B00" stroke-width="4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                </g>
                <g transform="translate(30,150)">
                    <rect x="0" y="0" width="46" height="46" rx="12" fill="#FF6B00"/>
                    <text x="23" y="31" font-family="Plus Jakarta Sans, sans-serif" font-size="24" font-weight="900" fill="#FFFFFF" text-anchor="middle">?</text>
                </g>
                <g transform="translate(184,150)">
                    <rect x="0" y="0" width="46" height="46" rx="12" fill="#2563EB"/>
                    <path d="M12 24 L20 32 L34 14" stroke="#FFFFFF" stroke-width="4.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                </g>
            </svg>
        </div>

        <div class="recovery-steps">
            <div class="r-step <?php echo $step > 1 ? 'done' : 'active'; ?>">
                <div class="r-num"><?php echo $step > 1 ? '<i class="fa-solid fa-check"></i>' : '1'; ?></div>
                <div>
                    <h4>Find Your Account</h4>
                    <p>Enter your admin username</p>
                </div>
            </div>
            <div class="r-step <?php echo $step > 2 ? 'done' : ($step === 2 ? 'active' : ''); ?>">
                <div class="r-num"><?php echo $step > 2 ? '<i class="fa-solid fa-check"></i>' : '2'; ?></div>
                <div>
                    <h4>Verify Security Answer</h4>
                    <p>Confirm your identity to continue</p>
                </div>
            </div>
            <div class="r-step <?php echo $step === 3 ? 'active' : ''; ?>">
                <div class="r-num"><?php echo $step === 3 ? '<i class="fa-solid fa-check"></i>' : '3'; ?></div>
                <div>
                    <h4>Set New Password</h4>
                    <p>Regain access to your control panel</p>
                </div>
            </div>
        </div>

        <div class="brand-footer-note">
            &copy; <?php echo date('Y'); ?>  &middot; ParkSmart Control Panel
        </div>
    </div>

    <!-- ============ RIGHT FORM PANEL ============ -->
    <div class="form-panel">
        <div class="login-card">

            <div class="mobile-brand">
                <div class="logo-icon"><i class="fa-solid fa-square-parking"></i></div>
                <h1>PARK<span>SMART</span></h1>
            </div>

            <div class="mobile-step-dots">
                <span class="<?php echo $step >= 1 ? ($step > 1 ? 'done' : 'active') : ''; ?>"></span>
                <span class="<?php echo $step >= 2 ? ($step > 2 ? 'done' : 'active') : ''; ?>"></span>
                <span class="<?php echo $step === 3 ? 'active' : ''; ?>"></span>
            </div>

            <?php if ($step === 3): ?>

                <div class="success-icon-big"><i class="fa-solid fa-circle-check"></i></div>
                <div class="card-header success" style="text-align: center;">
                    <h2>Password Updated</h2>
                    <p><?php echo $success_msg; ?></p>
                </div>

                <a href="admin_login.php" class="btn-secondary">
                    <i class="fa-solid fa-right-to-bracket"></i> Go to Admin Login
                </a>

            <?php else: ?>

                <div class="card-header">
                    <div class="badge-shield"><i class="fa-solid fa-key"></i></div>
                    <h2>Password Reset</h2>
                    <p>Recover admin account access</p>
                </div>

                <?php if ($error_msg): ?>
                    <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_msg; ?></div>
                <?php endif; ?>

                <?php if ($step === 1): ?>
                    <form action="" method="POST">
                        <div class="form-group">
                            <label class="form-label">Admin Username</label>
                            <div class="input-wrap">
                                <i class="fa-solid fa-user field-icon"></i>
                                <input type="text" name="username" class="form-control" placeholder="e.g. admin_john" required autofocus>
                            </div>
                        </div>
                        <button type="submit" name="find_account" class="btn-submit">
                            <i class="fa-solid fa-magnifying-glass"></i> Find Account
                        </button>
                    </form>

                <?php elseif ($step === 2): ?>
                    <form action="" method="POST">
                        <div class="form-group">
                            <label class="form-label">Security Question</label>
                            <div class="input-wrap">
                                <i class="fa-solid fa-circle-question field-icon"></i>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($_SESSION['reset_admin']['security_question']); ?>" readonly>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Your Answer</label>
                            <div class="input-wrap">
                                <i class="fa-solid fa-comment-dots field-icon"></i>
                                <input type="text" name="security_answer" class="form-control" placeholder="Enter your answer" required autofocus>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <div class="input-wrap">
                                <i class="fa-solid fa-lock field-icon"></i>
                                <input type="password" name="new_password" id="newPasswordField" class="form-control" placeholder="••••••••" required>
                                <button type="button" class="toggle-pass" onclick="togglePassword()">
                                    <i class="fa-solid fa-eye" id="toggleIcon"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" name="reset_password" class="btn-submit">
                            <i class="fa-solid fa-rotate"></i> Update Password
                        </button>
                    </form>
                <?php endif; ?>

                <a href="admin_login.php" class="back-site"><i class="fa-solid fa-arrow-left"></i> Back to Admin Login</a>

            <?php endif; ?>

        </div>
    </div>

    <script>
        function togglePassword() {
            const field = document.getElementById('newPasswordField');
            const icon = document.getElementById('toggleIcon');
            if (!field) return;
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>

</body>
</html>

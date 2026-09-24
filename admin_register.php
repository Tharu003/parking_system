<?php
session_start();
include 'config/db.php';

// Super Admin විසින් ලබාදෙන රහස් අංකය (Master Security Key)
define('SUPER_ADMIN_MASTER_KEY', 'Nishara@2026');

$error_msg = "";
$success_msg = "";

if (isset($_POST['admin_register'])) {
    $name              = trim($_POST['name']);
    $username          = trim($_POST['username']);
    $email             = trim($_POST['email']);
    $password          = trim($_POST['password']);
    $master_key        = trim($_POST['master_key']);
    $security_question = trim($_POST['security_question']);
    $security_answer   = strtolower(trim($_POST['security_answer']));

    if ($master_key !== SUPER_ADMIN_MASTER_KEY) {
        $error_msg = "ඇතුළත් කළ Master Key එක වැරදියි! Registration අසාර්ථකයි.";
    } else {
        // Username / Email පද්ධතියේ තිබේදැයි බලමු
        $check_stmt = $conn->prepare("SELECT id FROM admins WHERE username = ? OR email = ?");
        $check_stmt->bind_param("ss", $username, $email);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows > 0) {
            $error_msg = "මෙම Username හෝ Email එක මීට පෙර භාවිතා කර ඇත!";
        } else {
            // Password & Security Answer Encrypt කිරීම
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            $hashed_answer   = password_hash($security_answer, PASSWORD_BCRYPT);

            $stmt = $conn->prepare("INSERT INTO admins (name, username, email, password, security_question, security_answer) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $name, $username, $email, $hashed_password, $security_question, $hashed_answer);

            if ($stmt->execute()) {
                $success_msg = "නව Admin ගිණුම සාර්ථකව සාදන ලදී! දැන් Log විය හැක.";
            } else {
                $error_msg = "Registration ක්‍රියාවලිය අසාර්ථක විය.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ParkSmart - Admin Registration</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=Noto+Sans+Sinhala:wght@400;500;600;700;800&display=swap" rel="stylesheet">

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

        /* සිංහල පෙළ සඳහා විශේෂ Font එකක් (කියවීමට පහසු, ලස්සන) */
        .si {
            font-family: 'Noto Sans Sinhala', 'Plus Jakarta Sans', sans-serif;
            line-height: 1.7;
        }

        body {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 0.95fr 1.15fr;
            background: var(--white);
        }

        /* ============ LEFT: BRAND / ILLUSTRATION PANEL ============ */
        .brand-panel {
            position: relative;
            background: linear-gradient(155deg, var(--navy-blue) 0%, #112B4D 55%, var(--navy-blue) 100%);
            color: var(--white);
            padding: 50px 50px;
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
        .brand-logo-row h1 { font-size: 24px; font-weight: 800; letter-spacing: 0.3px; }
        .brand-logo-row h1 span { color: var(--primary-orange); }
        .brand-logo-row p { font-size: 12px; color: rgba(255,255,255,0.6); letter-spacing: 0.3px; font-weight: 600; }

        .illustration-wrap { display: flex; align-items: center; justify-content: center; margin: 6px 0; }

        .notice-box {
            background: rgba(255, 107, 0, 0.1);
            border: 1px solid rgba(255, 107, 0, 0.3);
            border-radius: 16px;
            padding: 16px 18px;
            display: flex;
            gap: 14px;
            align-items: flex-start;
        }
        .notice-box .n-icon {
            width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
            background: rgba(255, 107, 0, 0.25); color: var(--primary-orange);
            display: flex; align-items: center; justify-content: center; font-size: 15px;
        }
        .notice-box h4 { font-size: 14.5px; font-weight: 800; margin-bottom: 5px; }
        .notice-box p { font-size: 13px; color: rgba(255,255,255,0.65); line-height: 1.6; font-weight: 500; }

        .role-list { display: flex; flex-direction: column; gap: 12px; margin-top: 18px; }
        .role-item { display: flex; align-items: center; gap: 12px; }
        .role-item .r-icon {
            width: 30px; height: 30px; border-radius: 8px; flex-shrink: 0;
            background: rgba(255,255,255,0.08); color: #60A5FA;
            display: flex; align-items: center; justify-content: center; font-size: 13px;
        }
        .role-item p { font-size: 13.5px; color: rgba(255,255,255,0.6); font-weight: 600; }

        .brand-footer-note {
            font-size: 12.5px; color: rgba(255,255,255,0.45); font-weight: 500;
            border-top: 1px solid rgba(255,255,255,0.1); padding-top: 16px;
        }

        /* ============ RIGHT: FORM PANEL ============ */
        .form-panel {
            display: flex; align-items: flex-start; justify-content: center;
            padding: 48px 30px; background: var(--bg-light);
        }

        .register-card {
            width: 100%; max-width: 560px;
            background: var(--white); border-radius: 24px; padding: 42px 40px;
            border: 1px solid var(--border-color);
            box-shadow: 0 20px 45px rgba(10, 25, 47, 0.08);
        }

        .mobile-brand { display: none; align-items: center; gap: 12px; margin-bottom: 24px; }
        .mobile-brand .logo-icon { width: 44px; height: 44px; font-size: 20px; border-radius: 12px; }
        .mobile-brand h1 { font-size: 18px; font-weight: 800; color: var(--navy-blue); }
        .mobile-brand h1 span { color: var(--primary-orange); }

        .card-header { margin-bottom: 24px; padding-top: 2px; overflow: visible; }
        .card-header .badge-shield {
            width: 54px; height: 54px; border-radius: 16px;
            background: var(--soft-blue-bg);
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; color: var(--light-blue); margin-bottom: 16px;
        }
        .card-header h2 {
            font-size: 23px;
            line-height: 1.4;
            font-weight: 800;
            color: var(--navy-blue);
            padding-top: 2px;
            overflow: visible;
        }
        .card-header p { font-size: 13px; color: var(--text-muted); margin-top: 5px; font-weight: 500; line-height: 1.4; }

        .form-section-label {
            font-size: 11px; font-weight: 800; color: var(--light-blue);
            text-transform: uppercase; letter-spacing: 0.6px;
            display: flex; align-items: center; gap: 8px;
            margin: 22px 0 14px 0;
        }
        .form-section-label:first-of-type { margin-top: 0; }
        .form-section-label::after { content: ''; flex: 1; height: 1px; background: var(--border-color); }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        .form-group { margin-bottom: 16px; }
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
            width: 100%; padding: 12px 16px 12px 42px;
            background: var(--bg-light);
            border: 1.5px solid var(--border-color); border-radius: 12px;
            color: var(--text-dark); font-size: 13.5px; font-weight: 600;
            outline: none; transition: 0.25s; appearance: none;
        }
        select.form-control {
            padding-right: 36px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%2364748B' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
        }
        .form-control::placeholder { color: #A0AEC0; font-weight: 500; }
        .form-control:focus {
            border-color: var(--primary-orange); background: var(--white);
            box-shadow: 0 0 0 4px rgba(255, 107, 0, 0.12);
        }

        .toggle-pass {
            position: absolute; right: 15px; top: 50%; transform: translateY(-50%);
            color: var(--text-muted); font-size: 14px; cursor: pointer; background: none; border: none;
        }

        /* Master key special field */
        .master-key-group {
            background: linear-gradient(135deg, rgba(255, 107, 0, 0.06), rgba(37, 99, 235, 0.05));
            border: 1.5px dashed rgba(255, 107, 0, 0.4);
            border-radius: 14px;
            padding: 14px 16px 16px 16px;
            margin-bottom: 22px;
        }
        .master-key-group .form-label { display: flex; align-items: center; gap: 6px; color: var(--primary-orange); }
        .master-key-group .form-control { background: var(--white); }

        .btn-submit {
            width: 100%; padding: 15px;
            background: linear-gradient(135deg, var(--primary-orange), var(--orange-light));
            border: none; border-radius: 12px; color: var(--white); font-size: 15px; font-weight: 700;
            cursor: pointer; transition: 0.3s; margin-top: 8px;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            box-shadow: 0 10px 25px rgba(255, 107, 0, 0.28);
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 14px 30px rgba(255, 107, 0, 0.4); }

        .alert {
            padding: 12px 14px; border-radius: 12px; font-size: 14px;
            display: flex; align-items: center; gap: 8px; margin-bottom: 20px; font-weight: 600;
            line-height: 1.5;
        }
        .alert-error { background: rgba(239, 68, 68, 0.08); color: #DC2626; border: 1px solid rgba(239, 68, 68, 0.25); }
        .alert-success { background: rgba(16, 185, 129, 0.08); color: #059669; border: 1px solid rgba(16, 185, 129, 0.25); }

        .back-site {
            display: inline-flex; align-items: center; gap: 8px; justify-content: center; width: 100%;
            margin-top: 20px; font-size: 12.5px; font-weight: 700;
            color: var(--text-muted); text-decoration: none;
        }
        .back-site:hover { color: var(--primary-orange); }

        @media (max-width: 980px) {
            body { grid-template-columns: 1fr; }
            .brand-panel { display: none; }
            .mobile-brand { display: flex; }
        }
        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
            .register-card { padding: 32px 24px; border-radius: 18px; }
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
                <p class="si">බුද්ධිමත් නාගරික වාහන නැවතුම් පද්ධතිය</p>
            </div>
        </div>

        <div class="illustration-wrap">
            <svg width="250" height="180" viewBox="0 0 250 180" xmlns="http://www.w3.org/2000/svg">
                <circle cx="125" cy="88" r="72" fill="rgba(255,255,255,0.04)"/>
                <circle cx="125" cy="88" r="72" fill="none" stroke="rgba(37,99,235,0.3)" stroke-width="2" stroke-dasharray="5 7"/>
                <g transform="translate(85,45)">
                    <circle cx="40" cy="24" r="24" fill="#FFFFFF"/>
                    <circle cx="40" cy="18" r="9" fill="#0A192F"/>
                    <path d="M20 40 C20 28 28 24 40 24 C52 24 60 28 60 40 Z" fill="#0A192F"/>
                    <g transform="translate(52,-6)">
                        <rect x="0" y="0" width="30" height="30" rx="9" fill="#FF6B00"/>
                        <path d="M15 8 L15 22 M8 15 L22 15" stroke="#FFFFFF" stroke-width="3.5" stroke-linecap="round"/>
                    </g>
                </g>
                <g transform="translate(40,130)">
                    <rect x="0" y="0" width="42" height="42" rx="11" fill="#2563EB"/>
                    <path d="M11 21 L18 28 L31 12" stroke="#FFFFFF" stroke-width="4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                </g>
                <g transform="translate(168,130)">
                    <rect x="0" y="0" width="42" height="42" rx="11" fill="#0F2A4E"/>
                    <rect x="12" y="18" width="18" height="14" rx="2" fill="#FFFFFF"/>
                    <path d="M15 18 V13 A6 6 0 0 1 27 13 V18" stroke="#FFFFFF" stroke-width="3" fill="none"/>
                </g>
            </svg>
        </div>

        <div class="notice-box">
            <div class="n-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <div>
                <h4 class="si">බලයලත් පිවිසීම් සඳහා පමණි</h4>
                <p class="si">නව Admin ගිණුමක් සෑදීමට Super Admin විසින් ලබාදෙන වලංගු Master Security Key එකක් අවශ්‍ය වේ. අනවසර උත්සාහයන් ප්‍රතික්ෂේප කරනු ලැබේ.</p>
            </div>
        </div>

        <div class="role-list">
            <div class="role-item">
                <div class="r-icon"><i class="fa-solid fa-gauge-high"></i></div>
                <p class="si">ඇතුළුවීම, පිටවීම සහ VIP කළමනාකරණය සඳහා පූර්ණ ප්‍රවේශය</p>
            </div>
            <div class="role-item">
                <div class="r-icon"><i class="fa-solid fa-lock"></i></div>
                <p class="si">මුරපද සහ ආරක්ෂක පිළිතුරු සංකේතනය කර ඇත</p>
            </div>
            <div class="role-item">
                <div class="r-icon"><i class="fa-solid fa-users-gear"></i></div>
                <p class="si">එක් ස්ථානයකට Admin කිහිප දෙනෙකු එකතු කළ හැක</p>
            </div>
        </div>

        <div class="brand-footer-note si">
            &copy; <?php echo date('Y'); ?> අම්බලන්ගොඩ විවිධ සේවා සමූපාකාර සමිතිය &middot; ParkSmart Control Panel
        </div>
    </div>

    <!-- ============ RIGHT FORM PANEL ============ -->
    <div class="form-panel">
        <div class="register-card">

            <div class="mobile-brand">
                <div class="logo-icon"><i class="fa-solid fa-square-parking"></i></div>
                <h1>PARK<span>SMART</span></h1>
            </div>

            <div class="card-header">
                <div class="badge-shield"><i class="fa-solid fa-user-shield"></i></div>
                <h2>Admin Registration</h2>
                <p>Authorized registration only — Master Key required</p>
            </div>

            <?php if ($error_msg): ?>
                <div class="alert alert-error si"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_msg; ?></div>
            <?php endif; ?>
            <?php if ($success_msg): ?>
                <div class="alert alert-success si"><i class="fa-solid fa-circle-check"></i> <?php echo $success_msg; ?></div>
            <?php endif; ?>

            <form action="" method="POST">

                <div class="master-key-group">
                    <label class="form-label"><i class="fa-solid fa-key"></i> Master Passkey (Authorization Code)</label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-shield-halved field-icon"></i>
                        <input type="password" name="master_key" class="form-control" placeholder="Enter system security key" required>
                    </div>
                </div>

                <div class="form-section-label"><i class="fa-solid fa-id-card"></i> Personal Details</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Full Name</label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-user field-icon"></i>
                            <input type="text" name="name" class="form-control" placeholder="John Doe" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-at field-icon"></i>
                            <input type="text" name="username" class="form-control" placeholder="admin_john" required>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-envelope field-icon"></i>
                        <input type="email" name="email" class="form-control" placeholder="john@parksmart.com" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-lock field-icon"></i>
                        <input type="password" name="password" id="passwordField" class="form-control" placeholder="••••••••" required>
                        <button type="button" class="toggle-pass" onclick="togglePassword()">
                            <i class="fa-solid fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="form-section-label"><i class="fa-solid fa-shield-halved"></i> Account Recovery</div>
                <div class="form-group">
                    <label class="form-label">Security Question</label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-circle-question field-icon"></i>
                        <select name="security_question" class="form-control" required>
                            <option value="First School">What was your first school name?</option>
                            <option value="Pet Name">What is your favorite pet's name?</option>
                            <option value="Birth City">In what city were you born?</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Security Answer</label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-comment-dots field-icon"></i>
                        <input type="text" name="security_answer" class="form-control" placeholder="Your secret answer" required>
                    </div>
                </div>

                <button type="submit" name="admin_register" class="btn-submit">
                    <i class="fa-solid fa-user-plus"></i> Register New Admin
                </button>
            </form>

            <a href="admin_login.php" class="back-site"><i class="fa-solid fa-arrow-left"></i> Back to Admin Login</a>
        </div>
    </div>

    <script>
        function togglePassword() {
            const field = document.getElementById('passwordField');
            const icon = document.getElementById('toggleIcon');
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
<?php
session_start();
include 'config/db.php';

// දැනටමත් Log වී ඇත්නම් Admin Dashboard එකට Redirect කිරීම
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: admin_dashboard.php");
    exit();
}

$error_msg = "";

if (isset($_POST['admin_login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (!empty($username) && !empty($password)) {
        $stmt = $conn->prepare("SELECT * FROM admins WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $admin = $result->fetch_assoc();
            
            if (password_verify($password, $admin['password']) || $password === $admin['password']) {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_name'] = $admin['name'];
                $_SESSION['admin_username'] = $admin['username'];

                header("Location: admin_dashboard.php");
                exit();
            } else {
                $error_msg = "මුරපදය (Password) අසාර්ථකයි! නැවත උත්සාහ කරන්න.";
            }
        } else {
            $error_msg = "ඇතුළත් කළ පරිශීලක නාමය (Username) හමු නොවීය!";
        }
    } else {
        $error_msg = "කරුණාකර Username සහ Password දෙකම ඇතුළත් කරන්න.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ParkSmart - Admin Login</title>
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

        .brand-logo-row {
            display: flex; align-items: center; gap: 14px;
        }
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
        .illustration-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 10px 0;
        }

        .feature-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .feature-item {
            display: flex;
            align-items: center;
            gap: 14px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.1);
            padding: 14px 16px;
            border-radius: 14px;
            backdrop-filter: blur(6px);
        }
        .feature-item .f-icon {
            width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: rgba(255, 107, 0, 0.18); color: var(--primary-orange); font-size: 16px;
        }
        .feature-item h4 { font-size: 13px; font-weight: 700; margin-bottom: 2px; }
        .feature-item p { font-size: 11px; color: rgba(255,255,255,0.55); font-weight: 500; }

        .brand-footer-note {
            font-size: 11px;
            color: rgba(255,255,255,0.4);
            font-weight: 500;
            border-top: 1px solid rgba(255,255,255,0.1);
            padding-top: 16px;
        }

        /* ============ RIGHT: LOGIN FORM PANEL ============ */
        .form-panel {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 30px;
            background: var(--bg-light);
        }

        .login-card {
            width: 100%;
            max-width: 400px;
            background: var(--white);
            border-radius: 24px;
            padding: 42px 36px;
            border: 1px solid var(--border-color);
            box-shadow: 0 20px 45px rgba(10, 25, 47, 0.08);
        }

        .mobile-brand {
            display: none;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
        }
        .mobile-brand .logo-icon { width: 44px; height: 44px; font-size: 20px; border-radius: 12px; }
        .mobile-brand h1 { font-size: 18px; font-weight: 800; color: var(--navy-blue); }
        .mobile-brand h1 span { color: var(--primary-orange); }

        .card-header { margin-bottom: 26px; }
        .card-header .badge-shield {
            width: 54px; height: 54px; border-radius: 16px;
            background: var(--soft-blue-bg);
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; color: var(--light-blue);
            margin-bottom: 16px;
        }
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
            border-color: var(--primary-orange);
            background: var(--white);
            box-shadow: 0 0 0 4px rgba(255, 107, 0, 0.12);
        }

        .toggle-pass {
            position: absolute; right: 15px; top: 50%; transform: translateY(-50%);
            color: var(--text-muted); font-size: 14px; cursor: pointer; background: none; border: none;
        }

        .btn-login {
            width: 100%; padding: 15px;
            background: linear-gradient(135deg, var(--primary-orange), var(--orange-light));
            border: none; border-radius: 12px; color: var(--white); font-size: 15px; font-weight: 700;
            cursor: pointer; transition: 0.3s; margin-top: 8px;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            box-shadow: 0 10px 25px rgba(255, 107, 0, 0.28);
        }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 14px 30px rgba(255, 107, 0, 0.4); }
        .btn-login:active { transform: translateY(0); }

        .links-bar {
            display: flex; justify-content: space-between; margin-top: 22px; font-size: 12.5px;
        }
        .links-bar a {
            color: var(--light-blue); text-decoration: none; font-weight: 700;
            display: inline-flex; align-items: center; gap: 6px; transition: 0.2s;
        }
        .links-bar a:hover { color: var(--primary-orange); }

        .alert-error {
            background: rgba(239, 68, 68, 0.08); color: #DC2626;
            border: 1px solid rgba(239, 68, 68, 0.25);
            padding: 12px 14px; border-radius: 12px; font-size: 13px;
            display: flex; align-items: center; gap: 8px;
            margin-bottom: 20px; font-weight: 600;
        }

        .back-site {
            display: inline-flex; align-items: center; gap: 8px;
            margin-top: 26px; font-size: 12.5px; font-weight: 700;
            color: var(--text-muted); text-decoration: none;
        }
        .back-site:hover { color: var(--navy-blue); }

        /* ============ RESPONSIVE ============ */
        @media (max-width: 980px) {
            body { grid-template-columns: 1fr; }
            .brand-panel { display: none; }
            .mobile-brand { display: flex; }
        }

        @media (max-width: 480px) {
            .login-card { padding: 32px 22px; border-radius: 18px; }
        }
    </style>
<?php include __DIR__ . "/includes/pwa.php"; ?></head>
<body>

    <!-- ============ LEFT BRAND / ILLUSTRATION PANEL ============ -->
    <div class="brand-panel">
        <div class="brand-logo-row">
            <div class="logo-icon"><i class="fa-solid fa-square-parking"></i></div>
            <div>
                <h1>PARK<span>SMART</span></h1>
                <p>Smart Urban Parking System</p>
            </div>
        </div>

        <div class="illustration-wrap">
            <svg width="300" height="220" viewBox="0 0 300 220" xmlns="http://www.w3.org/2000/svg">
                <!-- Ground / boom-gate lane -->
                <rect x="10" y="150" width="280" height="12" rx="6" fill="#FF6B00" opacity="0.9"/>
                <rect x="10" y="150" width="280" height="12" rx="6" fill="url(#stripes)" opacity="0.9"/>
                <defs>
                    <pattern id="stripes" width="24" height="12" patternUnits="userSpaceOnUse">
                        <rect width="12" height="12" fill="#0A192F"/>
                        <rect x="12" width="12" height="12" fill="#FF6B00"/>
                    </pattern>
                </defs>

                <!-- Boom gate post -->
                <rect x="24" y="70" width="14" height="90" rx="4" fill="#E2E8F0"/>
                <circle cx="31" cy="70" r="9" fill="#FF6B00"/>
                <!-- Boom arm (raised) -->
                <rect x="31" y="66" width="120" height="9" rx="4" fill="#FFFFFF" transform="rotate(-28 31 66)"/>

                <!-- Car body -->
                <g transform="translate(150,108)">
                    <rect x="0" y="18" width="120" height="34" rx="10" fill="#FFFFFF"/>
                    <path d="M18 18 Q30 -6 60 -6 Q92 -6 104 18 Z" fill="#FFFFFF"/>
                    <path d="M28 14 Q36 0 58 0 Q82 0 92 14 Z" fill="#0A192F" opacity="0.85"/>
                    <circle cx="26" cy="54" r="12" fill="#0A192F"/>
                    <circle cx="26" cy="54" r="5" fill="#94A3B8"/>
                    <circle cx="96" cy="54" r="12" fill="#0A192F"/>
                    <circle cx="96" cy="54" r="5" fill="#94A3B8"/>
                    <rect x="4" y="26" width="14" height="8" rx="3" fill="#FF6B00"/>
                    <rect x="102" y="26" width="14" height="8" rx="3" fill="#FF6B00"/>
                </g>

                <!-- QR / ticket floating card -->
                <g transform="translate(215,30)">
                    <rect x="0" y="0" width="62" height="62" rx="10" fill="#FFFFFF"/>
                    <rect x="8" y="8" width="18" height="18" rx="3" fill="#0A192F"/>
                    <rect x="36" y="8" width="18" height="18" rx="3" fill="#0A192F"/>
                    <rect x="8" y="36" width="18" height="18" rx="3" fill="#0A192F"/>
                    <rect x="38" y="38" width="14" height="14" rx="2" fill="#FF6B00"/>
                </g>

                <!-- P sign -->
                <g transform="translate(45,20)">
                    <rect x="0" y="0" width="40" height="40" rx="9" fill="#FF6B00"/>
                    <text x="20" y="28" font-family="Plus Jakarta Sans, sans-serif" font-size="22" font-weight="900" fill="#FFFFFF" text-anchor="middle">P</text>
                </g>
            </svg>
        </div>

        <div class="feature-list">
            <div class="feature-item">
                <div class="f-icon"><i class="fa-solid fa-qrcode"></i></div>
                <div>
                    <h4>QR-Based Entry &amp; Exit</h4>
                    <p>Fast, contactless ticket verification at every gate</p>
                </div>
            </div>
            <div class="feature-item">
                <div class="f-icon"><i class="fa-solid fa-crown"></i></div>
                <div>
                    <h4>VIP &amp; Official Access</h4>
                    <p>Manage complimentary passes and approvals with ease</p>
                </div>
            </div>
            <div class="feature-item">
                <div class="f-icon"><i class="fa-solid fa-chart-line"></i></div>
                <div>
                    <h4>Live Monitoring</h4>
                    <p>Track occupancy, revenue and requests in real time</p>
                </div>
            </div>
        </div>

        <div class="brand-footer-note">
            &copy; <?php echo date('Y'); ?> අම්බලන්ගොඩ විවිධ සේවා සමූපාකාර සමිතිය &middot; ParkSmart Control Panel
        </div>
    </div>

    <!-- ============ RIGHT LOGIN FORM PANEL ============ -->
    <div class="form-panel">
        <div class="login-card">

            <div class="mobile-brand">
                <div class="logo-icon"><i class="fa-solid fa-square-parking"></i></div>
                <h1>PARK<span>SMART</span></h1>
            </div>

            <div class="card-header">
                <div class="badge-shield"><i class="fa-solid fa-user-shield"></i></div>
                <h2>Admin Login</h2>
                <p>Sign in to the ParkSmart Control Panel</p>
            </div>

            <?php if ($error_msg): ?>
                <div class="alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_msg; ?></div>
            <?php endif; ?>

            <form action="" method="POST">
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-user field-icon"></i>
                        <input type="text" name="username" class="form-control" placeholder="Enter username" required autofocus>
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

                <button type="submit" name="admin_login" class="btn-login">
                    <i class="fa-solid fa-right-to-bracket"></i> Sign In
                </button>
            </form>

            <div class="links-bar">
                <a href="admin_forgot_password.php"><i class="fa-solid fa-key"></i> Forgot Password?</a>
                <a href="admin_register.php"><i class="fa-solid fa-user-plus"></i> Register Admin</a>
            </div>

            <a href="index.php" class="back-site"><i class="fa-solid fa-arrow-left"></i> Back to Entry Gate</a>
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
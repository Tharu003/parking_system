<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isAdminLoggedIn    = !empty($_SESSION['admin_logged_in']);
$isSecurityLoggedIn = !empty($_SESSION['security_logged_in']);
$loggedInName = $isAdminLoggedIn
    ? ($_SESSION['admin_name'] ?? 'Admin')
    : ($isSecurityLoggedIn ? ($_SESSION['security_name'] ?? 'Security') : null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Parking Management System</title>
    
    <!-- FontAwesome & Google Fonts -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <!-- jQuery + DataTables (used by pages with large searchable tables) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.11/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.11/js/jquery.dataTables.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>

    <style>
        :root {
            --primary-orange: #FF6B00;
            --orange-hover: #E05D00;
            --orange-light: #FFF1E6;
            --navy-blue: #0A192F;
            --navy-blue-soft: #13223D;
            --light-blue: #2563EB;
            --soft-blue-bg: #EFF6FF;
            --bg-light: #F8FAFC;
            --white: #FFFFFF;
            --text-dark: #1E293B;
            --text-muted: #64748B;
            --border-color: #E2E8F0;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Top Bar */
        .top-bar {
            background: linear-gradient(90deg, var(--navy-blue) 0%, var(--navy-blue-soft) 100%);
            color: rgba(255, 255, 255, 0.85);
            font-size: 13px;
            padding: 10px 6%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255, 107, 0, 0.25);
        }

        .top-bar-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .top-bar-info span {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }

        .top-bar-info i {
            color: var(--primary-orange);
        }

        /* Main Header */
        .main-header {
            background: var(--white);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 0;
            z-index: 1000;
            border-bottom: 1px solid var(--border-color);
        }

        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 6%;
            max-width: 1440px;
            margin: 0 auto;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .logo-icon {
            background: linear-gradient(135deg, var(--primary-orange), #FF9E00);
            color: var(--white);
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            box-shadow: 0 6px 15px rgba(255, 107, 0, 0.3);
            transition: transform 0.25s ease;
        }
        .brand-logo:hover .logo-icon { transform: translateY(-2px) rotate(-4deg); }

        .brand-text h1 {
            font-size: 22px;
            font-weight: 800;
            color: var(--navy-blue);
            line-height: 1.1;
        }

        .brand-text h1 span {
            color: var(--primary-orange);
        }

        .brand-text p {
            font-size: 11px;
            color: var(--text-muted);
            letter-spacing: 0.5px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .nav-menu {
            display: flex;
            align-items: center;
            gap: 8px;
            list-style: none;
        }

        .nav-link {
            text-decoration: none;
            color: var(--navy-blue);
            font-weight: 600;
            font-size: 14px;
            padding: 10px 18px;
            border-radius: 10px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-link i {
            color: var(--light-blue);
            font-size: 16px;
            transition: 0.3s;
        }

        .nav-link:hover {
            background-color: var(--soft-blue-bg);
            color: var(--light-blue);
        }

        .nav-link.active {
            background-color: var(--soft-blue-bg);
            color: var(--primary-orange);
            font-weight: 700;
        }

        .nav-link.active i {
            color: var(--primary-orange);
        }

        .nav-link.btn-admin {
            background: var(--navy-blue);
            color: var(--white);
            margin-left: 10px;
            box-shadow: 0 4px 12px rgba(10, 25, 47, 0.15);
        }

        .nav-link.btn-admin i {
            color: var(--primary-orange);
        }

        .nav-link.btn-admin:hover {
            background: var(--primary-orange);
            color: var(--white);
        }

        .nav-link.btn-admin:hover i {
            color: var(--white);
        }

        .mobile-toggle {
            display: none;
            font-size: 20px;
            color: var(--navy-blue);
            cursor: pointer;
            width: 44px;
            height: 44px;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: var(--soft-blue-bg);
            border: 1px solid var(--border-color);
            transition: 0.25s ease;
            z-index: 1101;
            position: relative;
        }
        .mobile-toggle:hover { background: var(--orange-light); color: var(--primary-orange); }
        .mobile-toggle i { pointer-events: none; transition: transform 0.25s ease; }
        .mobile-toggle.active { background: var(--primary-orange); color: var(--white); border-color: var(--primary-orange); }
        .mobile-toggle.active i { transform: rotate(90deg); }

        .nav-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(10, 25, 47, 0.55);
            backdrop-filter: blur(2px);
            z-index: 1050;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .nav-backdrop.active {
            display: block;
            opacity: 1;
        }

        body.menu-open { overflow: hidden; }

        .nav-user-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 10px;
            background: var(--soft-blue-bg);
            color: var(--navy-blue);
            font-weight: 700;
            font-size: 13px;
            margin-right: 4px;
        }
        .nav-user-badge i { color: var(--light-blue); }

        .nav-link.btn-logout {
            background: #FEF2F2;
            color: #DC2626;
            border: 1px solid rgba(220, 38, 38, 0.25);
            margin-left: 6px;
        }
        .nav-link.btn-logout i { color: #DC2626; }
        .nav-link.btn-logout:hover { background: #DC2626; color: var(--white); }
        .nav-link.btn-logout:hover i { color: var(--white); }

        .page-content {
            flex: 1;
            padding: 40px 6%;
            max-width: 1440px;
            margin: 0 auto;
            width: 100%;
        }

        @media (max-width: 1024px) {
            .top-bar { display: none; }
            .mobile-toggle { display: flex; }

            .nav-menu {
                position: fixed;
                top: 0;
                right: 0;
                left: auto;
                width: min(320px, 84%);
                height: 100vh;
                background: var(--white);
                flex-direction: column;
                align-items: stretch;
                padding: 100px 22px 30px;
                gap: 8px;
                transform: translateX(100%);
                transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
                box-shadow: -10px 0 30px rgba(10, 25, 47, 0.18);
                overflow-y: auto;
                z-index: 1100;
            }
            .nav-menu.active { transform: translateX(0); }

            .nav-menu li {
                width: 100%;
                opacity: 0;
                transform: translateX(16px);
                transition: opacity 0.3s ease, transform 0.3s ease;
            }
            .nav-menu.active li { opacity: 1; transform: translateX(0); }
            .nav-menu.active li:nth-child(1) { transition-delay: 0.06s; }
            .nav-menu.active li:nth-child(2) { transition-delay: 0.10s; }
            .nav-menu.active li:nth-child(3) { transition-delay: 0.14s; }
            .nav-menu.active li:nth-child(4) { transition-delay: 0.18s; }
            .nav-menu.active li:nth-child(5) { transition-delay: 0.22s; }
            .nav-menu.active li:nth-child(6) { transition-delay: 0.26s; }
            .nav-menu.active li:nth-child(7) { transition-delay: 0.30s; }

            .nav-link { width: 100%; padding: 14px 18px; font-size: 15px; }
            .nav-link.btn-admin { margin-left: 0; margin-top: 6px; }
            .nav-user-badge { width: 100%; justify-content: flex-start; }
        }

        @media (max-width: 480px) {
            .navbar { padding: 12px 5%; }
            .brand-text h1 { font-size: 18px; }
            .brand-text p { font-size: 9px; }
            .logo-icon { width: 40px; height: 40px; font-size: 18px; }
            .nav-menu { width: 88%; padding-top: 90px; }
        }
    </style>
<?php include __DIR__ . "/pwa.php"; ?></head>
<body>

<div class="top-bar">
    <div class="top-bar-info">
        <span><i class="fa-solid fa-clock"></i> 24/7 Automated Parking System</span>
        <span><i class="fa-solid fa-shield-halved"></i> Automated Vehicle Tariff System</span>
    </div>
    <div class="top-bar-info">
        <span><i class="fa-solid fa-headset"></i> Support Hotline: +94 11 234 5678</span>
    </div>
</div>

<header class="main-header">
    <nav class="navbar">
        <a href="dashboard.php" class="brand-logo">
            <div class="logo-icon">
                <i class="fa-solid fa-square-parking"></i>
            </div>
            <div class="brand-text">
                <h1>PARK<span>SMART</span></h1>
                <p>SMART URBAN PARKING SYSTEM</p>
            </div>
        </a>

        <div class="mobile-toggle" id="mobileMenuBtn" role="button" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="navMenu">
            <i class="fa-solid fa-bars" id="mobileMenuIcon"></i>
        </div>

        <ul class="nav-menu" id="navMenu">
            <li><a href="dashboard.php" class="nav-link"><i class="fa-solid fa-ticket"></i> Entry Gate</a></li>
            <li><a href="3D parking.php" class="nav-link"><i class="fa-solid fa-cubes"></i> Parking Visualizer</a></li>
            <li><a href="exit.php" class="nav-link"><i class="fa-solid fa-right-from-bracket"></i> Exit Gate</a></li>
            <li><a href="vip_request.php" class="nav-link"><i class="fa-solid fa-crown"></i> VIP Pass Request</a></li>

            <?php if ($isAdminLoggedIn): ?>
                <li><a href="admin_login.php" class="nav-link btn-admin"><i class="fa-solid fa-user-shield"></i> Admin Panel</a></li>
            <?php else: ?>
                
                <li><a href="admin_login.php" class="nav-link btn-admin"><i class="fa-solid fa-user-shield"></i> Admin Panel</a></li>
            <?php endif; ?>

            <?php if ($loggedInName): ?>
                <li>
                    <span class="nav-user-badge">
                        <i class="fa-solid <?php echo $isAdminLoggedIn ? 'fa-user-shield' : 'fa-shield-halved'; ?>"></i>
                        <?php echo htmlspecialchars($loggedInName); ?>
                    </span>
                </li>
                <li><a href="logout.php" class="nav-link btn-logout" onclick="return confirm('Logout වෙන්න ඕනද?');"><i class="fa-solid fa-right-from-bracket"></i> Logout</a></li>
            <?php endif; ?>
        </ul>
    </nav>
    <div class="nav-backdrop" id="navBackdrop"></div>
</header>

<main class="page-content">

<script>
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const mobileMenuIcon = document.getElementById('mobileMenuIcon');
    const navMenu = document.getElementById('navMenu');
    const navBackdrop = document.getElementById('navBackdrop');

    function openMobileMenu() {
        navMenu.classList.add('active');
        navBackdrop.classList.add('active');
        mobileMenuBtn.classList.add('active');
        mobileMenuBtn.setAttribute('aria-expanded', 'true');
        mobileMenuIcon.classList.remove('fa-bars');
        mobileMenuIcon.classList.add('fa-xmark');
        document.body.classList.add('menu-open');
    }

    function closeMobileMenu() {
        navMenu.classList.remove('active');
        navBackdrop.classList.remove('active');
        mobileMenuBtn.classList.remove('active');
        mobileMenuBtn.setAttribute('aria-expanded', 'false');
        mobileMenuIcon.classList.remove('fa-xmark');
        mobileMenuIcon.classList.add('fa-bars');
        document.body.classList.remove('menu-open');
    }

    mobileMenuBtn.addEventListener('click', () => {
        navMenu.classList.contains('active') ? closeMobileMenu() : openMobileMenu();
    });

    navBackdrop.addEventListener('click', closeMobileMenu);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && navMenu.classList.contains('active')) closeMobileMenu();
    });

    // Close the menu automatically when a link inside it is tapped
    navMenu.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', closeMobileMenu);
    });

    // Close menu if window is resized back to desktop width
    window.addEventListener('resize', () => {
        if (window.innerWidth > 1024 && navMenu.classList.contains('active')) closeMobileMenu();
    });

    const currentPath = window.location.pathname.split("/").pop();
    document.querySelectorAll('.nav-link').forEach(link => {
        if (link.getAttribute('href') === currentPath) {
            link.classList.add('active');
        }
    });
</script>
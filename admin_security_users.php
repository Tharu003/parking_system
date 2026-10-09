<?php
session_start();
require_once 'config/db.php';

// Session Security Check
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit;
}

// Extract Username & Format
$raw_username = $_SESSION['admin_username'] ?? ($_SESSION['admin_name'] ?? 'Admin');
$display_name = ucfirst(strtolower($raw_username)) . " Admin";

// Logout Handler
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: admin_login.php");
    exit();
}

$msg = '';
$type = '';

// Toggle Security User Status Action
if (isset($_GET['toggle'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    $new = $_GET['toggle'] === 'activate' ? 'ACTIVE' : 'INACTIVE';
    $s = $conn->prepare("UPDATE security_users SET status=? WHERE id=?");
    $s->bind_param("si", $new, $id);
    if ($s->execute()) {
        $msg = 'Security account status updated successfully.';
        $type = 'ok';
    }
    $s->close();
}

// Fetch All Security Users
$users = $conn->query("SELECT id, full_name, username, email, phone, status, created_at FROM security_users ORDER BY id DESC");
$rows = [];
while ($users && ($r = $users->fetch_assoc())) { $rows[] = $r; }
$total_staff    = count($rows);
$active_staff   = count(array_filter($rows, fn($r) => $r['status'] === 'ACTIVE'));
$inactive_staff = $total_staff - $active_staff;

function staff_initials($name) {
    $parts = preg_split('/\s+/', trim($name));
    $i = strtoupper(substr($parts[0] ?? '?', 0, 1));
    if (count($parts) > 1) $i .= strtoupper(substr(end($parts), 0, 1));
    return $i;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Security Staff Accounts | ParkSmart - Ambalangoda MPCS</title>
    
    <!-- FontAwesome & Google Fonts -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- jQuery + DataTables -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>

    <style>
        :root {
            --primary-orange: #FF6B00;
            --orange-hover: #E05D00;
            --navy-blue: #0A192F;
            --navy-blue-soft: #13223D;
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
        }

        .admin-dashboard-layout {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        /* Sidebar Styles */
        .dashboard-sidebar {
            width: 270px;
            flex-shrink: 0;
            background: var(--navy-blue);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 24px 16px;
            position: sticky;
            top: 0;
            height: 100vh;
            box-shadow: 6px 0 25px rgba(10, 25, 47, 0.15);
            z-index: 999;
            overflow-y: auto;
        }

        .sidebar-brand-box {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            margin-bottom: 18px;
            text-decoration: none;
        }

        .sidebar-brand-icon {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, var(--primary-orange), #FF9E00);
            color: var(--white);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .sidebar-brand-text h2 {
            font-size: 19px;
            font-weight: 800;
            color: var(--white);
            line-height: 1.1;
        }

        .sidebar-brand-text h2 span { color: var(--primary-orange); }
        .sidebar-brand-text p { font-size: 10px; color: #94A3B8; letter-spacing: 0.5px; font-weight: 700; text-transform: uppercase; }

        .menu-category-label {
            font-size: 11px;
            font-weight: 800;
            color: #64748B;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 14px 12px 8px;
        }

        .sidebar-menu-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .sidebar-menu-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 16px;
            color: #94A3B8;
            text-decoration: none;
            border-radius: 12px;
            font-size: 13.5px;
            font-weight: 600;
            transition: all 0.25s ease;
        }

        .sidebar-menu-link:hover {
            background-color: rgba(255, 255, 255, 0.06);
            color: var(--white);
        }

        .sidebar-menu-link.active {
            background: linear-gradient(135deg, var(--primary-orange), #FF8800);
            color: var(--white);
            font-weight: 700;
        }

        .sidebar-bottom-wrapper {
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding-top: 18px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .sidebar-user-card {
            background: var(--navy-blue-soft);
            padding: 12px 14px;
            border-radius: 14px;
            color: var(--white);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-user-avatar {
            width: 36px;
            height: 36px;
            background: var(--primary-orange);
            color: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .btn-sidebar-logout {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: rgba(220, 38, 38, 0.12);
            color: #EF4444;
            padding: 10px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
            transition: all 0.25s ease;
        }

        .btn-sidebar-logout:hover {
            background: #DC2626;
            color: var(--white);
        }

        /* Workspace Layout */
        .dashboard-workspace {
            flex: 1;
            padding: 24px 3%;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* Top Header */
        .mpcs-top-header {
            background: var(--white);
            border-radius: 16px;
            padding: 18px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid var(--border-color);
            border-left: 5px solid var(--primary-orange);
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        }

        .mpcs-brand-info h1 {
            font-size: 19px;
            font-weight: 800;
            color: var(--navy-blue);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .mpcs-brand-info p {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 600;
            margin-top: 2px;
        }

        .mpcs-clock-card {
            background: var(--navy-blue);
            color: var(--white);
            padding: 8px 16px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .clock-time { font-size: 15px; font-weight: 800; }
        .clock-date { font-size: 10px; color: #94A3B8; }

        /* ================= SECURITY TEAM - ID CARD THEME ================= */
        body { background-image: linear-gradient(rgba(248,250,252,.92), rgba(248,250,252,.92)), repeating-linear-gradient(45deg, #E2E8F0 0 1px, transparent 1px 14px); }

        .alert { animation: dropIn .5s cubic-bezier(.2,.9,.3,1.3) both; }
        .alert.ok { background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0; }
        @keyframes dropIn { from { opacity: 0; transform: translateY(-18px) scale(.96); } to { opacity: 1; transform: none; } }

        /* ---------- Hero ---------- */
        .team-hero {
            position: relative; overflow: hidden; border-radius: 24px; padding: 34px 36px;
            background: linear-gradient(135deg, var(--navy-blue) 0%, var(--navy-blue-soft) 60%, #1B2F52 100%);
            color: var(--white); display: flex; justify-content: space-between; align-items: center; gap: 24px; flex-wrap: wrap;
            box-shadow: 0 18px 45px rgba(10,25,47,.25);
        }
        .radar { position: absolute; right: 6%; top: 50%; width: 260px; height: 260px; transform: translateY(-50%); pointer-events: none; opacity: .9; }
        .radar span { position: absolute; inset: 0; border: 1.5px solid rgba(255,107,0,.45); border-radius: 50%; animation: radar 4s ease-out infinite; }
        .radar span:nth-child(2) { animation-delay: 1.3s; }
        .radar span:nth-child(3) { animation-delay: 2.6s; }
        .radar i { position: absolute; inset: 0; margin: auto; width: 84px; height: 84px; border-radius: 26px; background: linear-gradient(135deg, var(--primary-orange), #FF9E00); display: flex; align-items: center; justify-content: center; font-size: 38px; color: var(--white); box-shadow: 0 0 40px rgba(255,107,0,.55); animation: floaty 3.5s ease-in-out infinite; }
        @keyframes radar { 0% { transform: scale(.35); opacity: 1; } 100% { transform: scale(1.15); opacity: 0; } }
        @keyframes floaty { 0%,100% { transform: translateY(0) rotate(-3deg); } 50% { transform: translateY(-10px) rotate(3deg); } }

        .hero-text { position: relative; z-index: 2; max-width: 520px; }
        .hero-eyebrow { display: inline-flex; align-items: center; gap: 8px; font-size: 11px; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase; color: var(--primary-orange); background: rgba(255,107,0,.12); padding: 6px 12px; border-radius: 20px; }
        .hero-text h1 { font-size: 34px; font-weight: 800; margin: 14px 0 6px; line-height: 1.1; }
        .hero-text h1 span { background: linear-gradient(90deg, var(--primary-orange), #FFB347); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .hero-text p { font-size: 13.5px; color: #94A3B8; font-weight: 600; line-height: 1.6; }
        .hero-stats { display: flex; gap: 12px; margin-top: 22px; flex-wrap: wrap; }
        .hstat { background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.12); backdrop-filter: blur(6px); padding: 12px 20px; border-radius: 16px; min-width: 104px; animation: riseIn .7s ease both; }
        .hstat:nth-child(2) { animation-delay: .12s; } .hstat:nth-child(3) { animation-delay: .24s; }
        .hstat b { display: block; font-size: 26px; font-weight: 800; line-height: 1; }
        .hstat small { font-size: 10.5px; font-weight: 700; letter-spacing: .8px; text-transform: uppercase; color: #94A3B8; }
        .hstat.g b { color: #4ADE80; } .hstat.r b { color: #F87171; } .hstat.o b { color: var(--primary-orange); }
        .hero-clock { position: absolute; top: 20px; right: 24px; z-index: 3; background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12); padding: 8px 14px; border-radius: 12px; display: flex; align-items: center; gap: 10px; }
        @keyframes riseIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: none; } }

        /* ---------- Toolbar ---------- */
        .team-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 14px; flex-wrap: wrap; }
        .search-pill { position: relative; flex: 1; min-width: 260px; max-width: 420px; }
        .search-pill i { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: var(--primary-orange); }
        .search-pill input { width: 100%; padding: 14px 18px 14px 46px; border-radius: 30px; border: 2px solid var(--border-color); background: var(--white); font-size: 13.5px; font-weight: 600; color: var(--text-dark); outline: none; transition: all .3s ease; }
        .search-pill input:focus { border-color: var(--primary-orange); box-shadow: 0 0 0 5px rgba(255,107,0,.12); }
        .seg { display: flex; background: var(--white); border: 2px solid var(--border-color); border-radius: 30px; padding: 4px; position: relative; }
        .seg button { position: relative; z-index: 2; border: none; background: transparent; padding: 9px 20px; border-radius: 30px; font-size: 12.5px; font-weight: 800; color: var(--text-muted); cursor: pointer; transition: color .3s ease; }
        .seg button.on { color: var(--white); }
        .seg .glider { position: absolute; z-index: 1; top: 4px; bottom: 4px; left: 4px; border-radius: 30px; background: linear-gradient(135deg, var(--primary-orange), #FF8800); box-shadow: 0 6px 16px rgba(255,107,0,.35); transition: all .4s cubic-bezier(.2,.9,.3,1.2); }
        .result-count { font-size: 12.5px; font-weight: 700; color: var(--text-muted); }
        .result-count b { color: var(--primary-orange); }

        /* ---------- ID Cards ---------- */
        .id-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 26px; padding-top: 14px; perspective: 1200px; }
        .id-card {
            position: relative; background: var(--white); border-radius: 22px; border: 1px solid var(--border-color); overflow: hidden;
            box-shadow: 0 10px 30px rgba(10,25,47,.08); animation: cardIn .7s cubic-bezier(.2,.9,.3,1.1) both;
            transition: transform .25s ease, box-shadow .25s ease, opacity .3s ease; transform-style: preserve-3d; will-change: transform;
        }
        .id-card:hover { box-shadow: 0 24px 50px rgba(10,25,47,.18); }
        .id-card.hide { display: none; }
        @keyframes cardIn { from { opacity: 0; transform: translateY(40px) rotateX(12deg) scale(.94); } to { opacity: 1; transform: none; } }
        .id-card::after { content: ""; position: absolute; top: 0; left: -80%; width: 50%; height: 100%; background: linear-gradient(100deg, transparent, rgba(255,255,255,.55), transparent); transform: skewX(-20deg); pointer-events: none; }
        .id-card:hover::after { animation: shine .9s ease; }
        @keyframes shine { to { left: 140%; } }

        .id-top { position: relative; height: 104px; background: linear-gradient(135deg, var(--navy-blue), var(--navy-blue-soft)); }
        .id-top::before { content: ""; position: absolute; right: -40px; top: -40px; width: 160px; height: 160px; border-radius: 50%; background: radial-gradient(circle, rgba(255,107,0,.55), transparent 70%); }
        .id-top::after { content: ""; position: absolute; left: 0; right: 0; bottom: -1px; height: 26px; background: var(--white); clip-path: ellipse(60% 100% at 50% 100%); }
        .lanyard-slot { position: absolute; top: 12px; left: 50%; transform: translateX(-50%); width: 56px; height: 9px; border-radius: 6px; background: rgba(255,255,255,.18); box-shadow: inset 0 2px 3px rgba(0,0,0,.35); }
        .id-org { position: absolute; top: 28px; left: 20px; right: 20px; display: flex; justify-content: space-between; align-items: center; font-size: 9.5px; font-weight: 800; letter-spacing: 1.2px; text-transform: uppercase; color: #94A3B8; }
        .id-org b { color: var(--primary-orange); }

        .id-avatar-wrap { position: relative; width: 92px; height: 92px; margin: -50px auto 0; z-index: 3; }
        .id-avatar { width: 92px; height: 92px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 30px; font-weight: 800; color: var(--white); border: 5px solid var(--white); box-shadow: 0 10px 24px rgba(10,25,47,.25); }
        .av-0 { background: linear-gradient(135deg, var(--primary-orange), #FF9E00); }
        .av-1 { background: linear-gradient(135deg, var(--navy-blue), #2A4373); }
        .av-2 { background: linear-gradient(135deg, #FF8800, var(--navy-blue-soft)); }
        .status-ring { position: absolute; inset: -6px; border-radius: 50%; border: 3px solid #16A34A; animation: ringPulse 2s ease-out infinite; }
        .id-card.is-off .status-ring { border: 3px dashed #94A3B8; animation: none; }
        @keyframes ringPulse { 0% { box-shadow: 0 0 0 0 rgba(22,163,74,.5); } 100% { box-shadow: 0 0 0 16px rgba(22,163,74,0); } }
        .status-dot { position: absolute; right: 2px; bottom: 4px; width: 22px; height: 22px; border-radius: 50%; background: #16A34A; color: var(--white); border: 3px solid var(--white); font-size: 9px; display: flex; align-items: center; justify-content: center; }
        .id-card.is-off .status-dot { background: #DC2626; }
        .id-card.is-off .id-avatar { filter: grayscale(.85); }
        .id-card.is-off .id-body { opacity: .72; }

        .id-body { padding: 14px 24px 8px; text-align: center; }
        .id-name { font-size: 18px; font-weight: 800; color: var(--navy-blue); }
        .id-user { display: inline-flex; align-items: center; gap: 6px; margin-top: 6px; font-family: 'Courier New', monospace; font-weight: 800; font-size: 12px; color: var(--navy-blue); background: var(--soft-blue-bg); border: 1px dashed #BFDBFE; padding: 4px 12px; border-radius: 20px; }
        .id-user i { color: var(--primary-orange); }
        .id-info { list-style: none; margin-top: 18px; text-align: left; display: flex; flex-direction: column; gap: 10px; }
        .id-info li { display: flex; align-items: center; gap: 12px; font-size: 12.5px; font-weight: 600; color: var(--text-dark); word-break: break-all; }
        .id-info .ico { width: 30px; height: 30px; flex-shrink: 0; border-radius: 9px; background: #FFF1E6; color: var(--primary-orange); display: flex; align-items: center; justify-content: center; font-size: 12px; transition: all .3s ease; }
        .id-card:hover .id-info .ico { background: var(--primary-orange); color: var(--white); transform: rotate(-8deg) scale(1.1); }

        .id-foot { margin-top: 18px; padding: 16px 24px 20px; border-top: 2px dashed var(--border-color); position: relative; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .id-foot::before, .id-foot::after { content: ""; position: absolute; top: -11px; width: 20px; height: 20px; border-radius: 50%; background: var(--bg-light); border: 1px solid var(--border-color); }
        .id-foot::before { left: -11px; } .id-foot::after { right: -11px; }
        .id-since small { display: block; font-size: 9.5px; font-weight: 800; letter-spacing: .8px; text-transform: uppercase; color: var(--text-muted); }
        .id-since span { font-size: 12.5px; font-weight: 800; color: var(--navy-blue); }

        /* Toggle switch link */
        .sw { display: inline-flex; align-items: center; gap: 10px; text-decoration: none; font-size: 11.5px; font-weight: 800; }
        .sw .track { width: 52px; height: 28px; border-radius: 20px; position: relative; transition: all .3s ease; }
        .sw .track::after { content: ""; position: absolute; top: 3px; width: 22px; height: 22px; border-radius: 50%; background: var(--white); box-shadow: 0 3px 8px rgba(0,0,0,.25); transition: all .35s cubic-bezier(.2,.9,.3,1.4); }
        .sw.is-on { color: #15803D; } .sw.is-on .track { background: #16A34A; } .sw.is-on .track::after { left: 27px; }
        .sw.is-off { color: #B91C1C; } .sw.is-off .track { background: #CBD5E1; } .sw.is-off .track::after { left: 3px; }
        .sw:hover .track { transform: scale(1.08); box-shadow: 0 0 0 5px rgba(255,107,0,.15); }

        .barcode { height: 22px; margin: 0 24px 18px; border-radius: 3px; opacity: .55; background: repeating-linear-gradient(90deg, var(--navy-blue) 0 2px, transparent 2px 4px, var(--navy-blue) 4px 5px, transparent 5px 9px, var(--navy-blue) 9px 12px, transparent 12px 14px); }

        .empty-state { display: none; text-align: center; padding: 60px 20px; color: var(--text-muted); font-weight: 700; }
        .empty-state i { font-size: 46px; color: var(--primary-orange); margin-bottom: 14px; animation: floaty 3s ease-in-out infinite; }

        @media (max-width: 992px) { .radar { opacity: .25; right: -40px; } .hero-clock { position: static; } }
        /* ---- Mobile-only elements (hidden on desktop) ---- */
        .mobile-topbar, .sidebar-overlay, .sidebar-close-btn { display: none; }

        /* ---------- Print buttons ---------- */
        .id-org { top: 48px; }
        .print-btn {
            position: absolute; top: 12px; right: 12px; z-index: 5; width: 34px; height: 34px; border-radius: 50%;
            border: 1px solid rgba(255,255,255,.25); background: rgba(255,255,255,.12); color: var(--white);
            cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 13px; transition: all .3s ease;
        }
        .print-btn:hover { background: var(--primary-orange); border-color: var(--primary-orange); transform: rotate(-12deg) scale(1.12); box-shadow: 0 6px 16px rgba(255,107,0,.5); }
        .btn-print-all {
            border: none; cursor: pointer; background: var(--navy-blue); color: var(--white); padding: 12px 20px; border-radius: 30px;
            font-size: 12.5px; font-weight: 800; display: inline-flex; align-items: center; gap: 8px; transition: all .3s ease;
        }
        .btn-print-all i { color: var(--primary-orange); transition: transform .3s ease; }
        .btn-print-all:hover { background: var(--primary-orange); transform: translateY(-2px); box-shadow: 0 8px 18px rgba(255,107,0,.3); }
        .btn-print-all:hover i { color: var(--white); transform: scale(1.2); }

        /* =====================================================================
           RESPONSIVE LAYER  (tablet + mobile)  -  desktop look is untouched
           ===================================================================== */
        html { -webkit-text-size-adjust: 100%; }

        /* ---------- Tablet & below (<= 992px): sidebar becomes a slide-in drawer ---------- */
        @media (max-width: 992px) {
            .admin-dashboard-layout { flex-direction: column; }

            .dashboard-sidebar {
                position: fixed; top: 0; left: 0; bottom: 0;
                width: 280px; max-width: 85vw; height: 100%;
                transform: translateX(-100%); visibility: hidden;
                transition: transform .3s ease, visibility 0s linear .3s;
                z-index: 1001;
                -webkit-overflow-scrolling: touch;
                overscroll-behavior: contain;
            }
            .dashboard-sidebar.open {
                transform: translateX(0); visibility: visible;
                transition: transform .3s ease, visibility 0s;
            }
            .sidebar-menu-link { padding: 13px 16px; }

            .sidebar-close-btn {
                display: flex; align-items: center; justify-content: center;
                position: absolute; top: 14px; right: 12px;
                width: 36px; height: 36px; border: none; border-radius: 10px; cursor: pointer;
                background: rgba(255,255,255,.08); color: #CBD5E1; font-size: 16px;
            }
            .sidebar-close-btn:hover { background: rgba(255,255,255,.16); color: var(--white); }
            .sidebar-brand-box { padding-right: 52px; }

            .sidebar-overlay {
                display: block; position: fixed; inset: 0; z-index: 1000;
                background: rgba(10, 25, 47, .55); backdrop-filter: blur(2px);
                opacity: 0; pointer-events: none; transition: opacity .3s ease;
            }
            .sidebar-overlay.show { opacity: 1; pointer-events: auto; }
            body.nav-open { overflow: hidden; }

            /* Sticky mobile top bar with hamburger */
            .mobile-topbar {
                display: flex; align-items: center; gap: 12px;
                position: sticky; top: 10px; z-index: 900;
                background: var(--navy-blue); color: var(--white);
                padding: 10px 14px; border-radius: 14px;
                box-shadow: 0 8px 22px rgba(10,25,47,.25);
            }
            .mobile-menu-btn {
                width: 40px; height: 40px; flex-shrink: 0; border: none; cursor: pointer;
                border-radius: 11px; font-size: 17px; color: var(--white);
                background: linear-gradient(135deg, var(--primary-orange), #FF8800);
                display: flex; align-items: center; justify-content: center;
            }
            .mobile-brand h2 { font-size: 16px; font-weight: 800; line-height: 1.1; color: var(--white); }
            .mobile-brand h2 span { color: var(--primary-orange); }
            .mobile-brand p { font-size: 9.5px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; color: #94A3B8; }

            .dashboard-workspace { padding: 16px 20px 28px; gap: 16px; }

            /* Hero: stack cleanly, clock sits on top */
            .team-hero { flex-direction: column; align-items: flex-start; gap: 16px; padding: 28px 26px; }
            .hero-clock { order: -1; }
            .hero-text { max-width: 100%; }
        }

        /* ---------- Large phones / small tablets (<= 768px) ---------- */
        @media (max-width: 768px) {
            .team-hero { border-radius: 20px; padding: 22px 20px; }
            .hero-text h1 { font-size: 28px; }
            .hero-text p { font-size: 13px; }
            .hero-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 18px; }
            .hstat { min-width: 0; padding: 12px 14px; }
            .hstat b { font-size: 24px; }

            .team-toolbar { gap: 12px; }
            .search-pill { flex: 1 1 100%; min-width: 0; max-width: none; }
            .search-pill input { font-size: 16px; padding: 13px 18px 13px 46px; } /* stops iOS zoom-on-focus */
            .seg { flex: 1 1 100%; }
            .seg button { flex: 1; padding: 10px 8px; }
            .result-count { flex: 1 1 auto; }
            .btn-print-all { flex: 0 0 auto; padding: 11px 18px; }
        }

        /* ---------- Phones (<= 600px) ---------- */
        @media (max-width: 600px) {
            .dashboard-workspace { padding: 12px 12px 24px; gap: 14px; }
            .mobile-topbar { top: 8px; padding: 9px 12px; }

            .team-hero { padding: 20px 16px; gap: 14px; }
            .radar { width: 190px; height: 190px; right: -50px; opacity: .2; }
            .hero-text h1 { font-size: 26px; }
            .hero-eyebrow { font-size: 10px; letter-spacing: 1.1px; }
            .hstat { padding: 10px 10px; border-radius: 14px; }
            .hstat b { font-size: 22px; }
            .hstat small { font-size: 9.5px; letter-spacing: .4px; }

            .id-grid { grid-template-columns: repeat(auto-fill, minmax(min(290px, 100%), 1fr)); gap: 18px; padding-top: 6px; }
            .id-card { width: 100%; max-width: 440px; justify-self: center; }
            .id-body { padding: 14px 20px 8px; }
            .id-foot { padding: 16px 20px 18px; }
            .barcode { margin: 0 20px 16px; }
        }

        /* ---------- Very small phones (<= 380px) ---------- */
        @media (max-width: 380px) {
            .mobile-brand p { display: none; }
            .hero-text h1 { font-size: 24px; }
            .hstat small { font-size: 9px; letter-spacing: .2px; }
            .id-name { font-size: 16.5px; }
            .id-info li { font-size: 12px; }
            .btn-print-all { padding: 10px 14px; font-size: 12px; }
        }

        /* Touch screens: no sticky hover effects */
        @media (hover: none) {
            .id-card:hover { box-shadow: 0 10px 30px rgba(10,25,47,.08); }
            .id-card:hover::after { animation: none; }
            .id-card:hover .id-info .ico { background: #FFF1E6; color: var(--primary-orange); transform: none; }
            .sw:hover .track { transform: none; box-shadow: none; }
            .print-btn:hover { transform: none; box-shadow: none; }
            .btn-print-all:hover { transform: none; box-shadow: none; }
        }

        @media (prefers-reduced-motion: reduce) {
            .dashboard-sidebar, .sidebar-overlay { transition: none; }
        }

        /* ---------- Print layout ---------- */
        @media print {
            @page { size: A4; margin: 12mm; }
            html, body { background: #fff !important; }
            body { background-image: none !important; }
            .dashboard-sidebar, .mobile-topbar, .sidebar-overlay, .sidebar-close-btn, .team-hero, .team-toolbar, .alert, .empty-state, .print-btn, .sw, .id-card::after { display: none !important; }
            .admin-dashboard-layout, .dashboard-workspace { display: block !important; padding: 0 !important; }
            body.print-one .id-card:not(.printing) { display: none !important; }
            body.print-all .id-card.hide { display: none !important; }
            .id-grid { display: grid !important; grid-template-columns: repeat(2, 88mm); gap: 8mm; justify-content: center; padding: 0; perspective: none; }
            body.print-one .id-grid { grid-template-columns: 88mm; }
            .id-card {
                animation: none !important; transform: none !important; opacity: 1 !important; box-shadow: none !important;
                border: 1.5px solid #94A3B8; break-inside: avoid; page-break-inside: avoid;
                -webkit-print-color-adjust: exact; print-color-adjust: exact;
            }
            .id-card * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .id-foot::before, .id-foot::after { background: #fff; }
            .id-card.is-off .id-avatar { filter: none; }
            .status-ring { animation: none !important; }
        }
    </style>
<?php include __DIR__ . "/includes/pwa.php"; ?></head>
<body>

<div class="admin-dashboard-layout">

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Complete Sidebar -->
    <aside class="dashboard-sidebar" id="dashboardSidebar">
        <button type="button" class="sidebar-close-btn" id="sidebarClose" aria-label="Close menu"><i class="fa-solid fa-xmark"></i></button>
        <div>
            <a href="index.php" class="sidebar-brand-box">
                <div class="sidebar-brand-icon">
                    <i class="fa-solid fa-square-parking"></i>
                </div>
                <div class="sidebar-brand-text">
                    <h2>PARK<span>SMART</span></h2>
                    
                </div>
            </a>

            <!-- System Gates Navigation Section -->
            <div class="menu-category-label">Gate Terminals</div>
            <ul class="sidebar-menu-list">
                <li><a href="index.php" class="sidebar-menu-link"><i class="fa-solid fa-ticket"></i> Entry Gate</a></li>
                <li><a href="3D parking.php" class="sidebar-menu-link"><i class="fa-solid fa-cubes"></i> Parking Visualizer</a></li>
                <li><a href="exit.php" class="sidebar-menu-link"><i class="fa-solid fa-right-from-bracket"></i> Exit Gate</a></li>
                <li><a href="vip_request.php" class="sidebar-menu-link"><i class="fa-solid fa-crown"></i> VIP Pass Request</a></li>
            </ul>

            <!-- Admin Workspace Section -->
            <div class="menu-category-label">Admin Controls</div>
            <ul class="sidebar-menu-list">
                <li><a href="admin_dashboard.php" class="sidebar-menu-link"><i class="fa-solid fa-chart-pie"></i> Dashboard</a></li>
                <li><a href="admin_security_users.php" class="sidebar-menu-link active"><i class="fa-solid fa-user-shield"></i> Security Staff</a></li>
                <li><a href="admin_vip_requests.php" class="sidebar-menu-link"><i class="fa-solid fa-crown"></i> VIP Approvals</a></li>
                <li><a href="admin_reports.php" class="sidebar-menu-link"><i class="fa-solid fa-chart-column"></i> Revenue Reports</a></li>
                 <li><a href="vehicle_investigation.php" class="sidebar-menu-link"><i class="fa-solid fa-shield-halved"></i> CID & Trace</a></li>
            </ul>
        </div>

        <!-- Bottom User Profile Card & Logout -->
        <div class="sidebar-bottom-wrapper">
            <div class="sidebar-user-card">
                <div class="sidebar-user-avatar"><i class="fa-solid fa-user-shield"></i></div>
                <div>
                    <h4 style="font-size:12px; font-weight:800;"><?php echo htmlspecialchars($display_name); ?></h4>
                    <p style="font-size:10px; color:#FFB380;">Master Admin</p>
                </div>
            </div>
            <a href="?action=logout" class="btn-sidebar-logout" onclick="return confirm('Logout වෙනවද?');">
                <i class="fa-solid fa-right-from-bracket"></i> Logout System
            </a>
        </div>
    </aside>

    <!-- Main Workspace -->
    <main class="dashboard-workspace">

        <!-- Mobile / Tablet Top Bar (hidden on desktop) -->
        <div class="mobile-topbar">
            <button type="button" class="mobile-menu-btn" id="sidebarToggle" aria-label="Open menu" aria-controls="dashboardSidebar" aria-expanded="false"><i class="fa-solid fa-bars"></i></button>
            <div class="mobile-brand">
                <h2>PARK<span>SMART</span></h2>
              
            </div>
        </div>

        <!-- Hero -->
        <header class="team-hero">
            <div class="hero-clock">
                <i class="fa-regular fa-clock" style="color:var(--primary-orange);"></i>
                <div>
                    <div class="clock-time" id="liveClock">00:00:00 AM</div>
                    <div class="clock-date" id="liveDate">Loading...</div>
                </div>
            </div>
            <div class="radar"><span></span><span></span><span></span><i class="fa-solid fa-shield-halved"></i></div>
            <div class="hero-text">
              
                <h1>Security <span>Team</span></h1>
                <p>Manage terminal operator access &mdash; switch any officer on or off with a single tap.</p>
                <div class="hero-stats">
                    <div class="hstat o"><b class="count" data-to="<?= $total_staff ?>">0</b><small>Total Staff</small></div>
                    <div class="hstat g"><b class="count" data-to="<?= $active_staff ?>">0</b><small>Active</small></div>
                    <div class="hstat r"><b class="count" data-to="<?= $inactive_staff ?>">0</b><small>Inactive</small></div>
                </div>
            </div>
        </header>

        <!-- Toast Message -->
        <?php if ($msg): ?>
            <div class="alert <?= htmlspecialchars($type) ?>">
                <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <!-- Toolbar -->
        <div class="team-toolbar">
            <div class="search-pill">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="staffSearch" placeholder="Search name, username, email or phone...">
            </div>
            <div class="seg" id="staffSeg">
                <span class="glider"></span>
                <button type="button" class="on" data-f="ALL">All</button>
                <button type="button" data-f="ACTIVE">Active</button>
                <button type="button" data-f="INACTIVE">Inactive</button>
            </div>
            <div class="result-count">Showing <b id="shownCount"><?= $total_staff ?></b> officers</div>
            <button type="button" class="btn-print-all" id="printAll"><i class="fa-solid fa-print"></i> Print All IDs</button>
        </div>

        <!-- ID Card Grid -->
        <section class="id-grid" id="idGrid">
            <?php foreach ($rows as $i => $u):
                $is_on = ($u['status'] === 'ACTIVE');
                $search = strtolower($u['full_name'] . ' ' . $u['username'] . ' ' . $u['email'] . ' ' . $u['phone']);
            ?>
                <article class="id-card <?= $is_on ? '' : 'is-off' ?>" data-status="<?= htmlspecialchars($u['status']) ?>" data-search="<?= htmlspecialchars($search) ?>" style="animation-delay: <?= min($i, 12) * 0.07 ?>s;">
                    <div class="id-top">
                        <span class="lanyard-slot"></span>
                        <button type="button" class="print-btn" title="Print ID Card"><i class="fa-solid fa-print"></i></button>
                        <div class="id-org"><span><b>PARK</b>SMART</span><span>Security ID</span></div>
                    </div>

                    <div class="id-avatar-wrap">
                        <div class="id-avatar av-<?= $u['id'] % 3 ?>"><?= htmlspecialchars(staff_initials($u['full_name'])) ?></div>
                        <span class="status-ring"></span>
                        <span class="status-dot"><i class="fa-solid <?= $is_on ? 'fa-check' : 'fa-xmark' ?>"></i></span>
                    </div>

                    <div class="id-body">
                        <h3 class="id-name"><?= htmlspecialchars($u['full_name']) ?></h3>
                        <span class="id-user"><i class="fa-solid fa-at"></i><?= htmlspecialchars($u['username']) ?></span>
                        <ul class="id-info">
                            <li><span class="ico"><i class="fa-solid fa-envelope"></i></span><?= htmlspecialchars($u['email']) ?></li>
                            <li><span class="ico"><i class="fa-solid fa-phone"></i></span><?= htmlspecialchars($u['phone'] ?: '-') ?></li>
                        </ul>
                    </div>

                    <div class="id-foot">
                        <div class="id-since">
                            <small>Member Since</small>
                            <span><?= htmlspecialchars(date('d M Y', strtotime($u['created_at']))) ?></span>
                        </div>
                        <?php if ($is_on): ?>
                            <a class="sw is-on" href="?toggle=deactivate&id=<?= $u['id'] ?>" onclick="return confirm('Deactivate this security account?')">
                                <span>Active</span><span class="track"></span>
                            </a>
                        <?php else: ?>
                            <a class="sw is-off" href="?toggle=activate&id=<?= $u['id'] ?>">
                                <span>Inactive</span><span class="track"></span>
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="barcode"></div>
                </article>
            <?php endforeach; ?>
        </section>

        <div class="empty-state" id="emptyState">
            <i class="fa-solid fa-user-slash"></i>
            <div>No security officers match your search.</div>
        </div>

    </main>
</div>

<!-- Scripts -->
<script>
$(document).ready(function () {
    // Live clock
    function updateLiveClock() {
        const now = new Date();
        document.getElementById('liveClock').textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
        document.getElementById('liveDate').textContent = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
    }
    updateLiveClock();
    setInterval(updateLiveClock, 1000);

    // Count-up numbers
    $('.count').each(function () {
        const el = this, to = parseInt(el.dataset.to, 10) || 0, start = performance.now(), dur = 1100;
        (function step(t) {
            const p = Math.min((t - start) / dur, 1), e = 1 - Math.pow(1 - p, 3);
            el.textContent = Math.round(to * e);
            if (p < 1) requestAnimationFrame(step);
        })(start);
    });

    // Search + filter
    const $cards = $('.id-card');
    let filter = 'ALL';
    function applyFilter() {
        const q = $('#staffSearch').val().toLowerCase().trim();
        let shown = 0;
        $cards.each(function () {
            const ok = (filter === 'ALL' || this.dataset.status === filter) && this.dataset.search.indexOf(q) !== -1;
            $(this).toggleClass('hide', !ok);
            if (ok) { shown++; this.style.animation = 'none'; void this.offsetWidth; this.style.animation = 'cardIn .5s ease both'; this.style.animationDelay = (shown * 0.04) + 's'; }
        });
        $('#shownCount').text(shown);
        $('#emptyState').toggle(shown === 0);
    }
    function moveGlider($btn) {
        $('#staffSeg .glider').css({ left: $btn.position().left + 'px', width: $btn.outerWidth() + 'px' });
    }
    moveGlider($('#staffSeg button.on'));
    $('#staffSeg button').on('click', function () {
        $('#staffSeg button').removeClass('on'); $(this).addClass('on');
        moveGlider($(this)); filter = this.dataset.f; applyFilter();
    });
    $('#staffSearch').on('input', applyFilter);
    $(window).on('resize', function () { moveGlider($('#staffSeg button.on')); });

    // Printing
    $('.print-btn').on('click', function (e) {
        e.preventDefault();
        $('body').addClass('print-one');
        $(this).closest('.id-card').addClass('printing');
        window.print();
    });
    $('#printAll').on('click', function () {
        $('body').addClass('print-all');
        window.print();
    });
    window.addEventListener('afterprint', function () {
        $('body').removeClass('print-one print-all');
        $('.id-card').removeClass('printing');
    });

    // 3D tilt on hover (desktop only)
    if (window.matchMedia('(hover: hover)').matches) {
        $cards.on('mousemove', function (e) {
            const r = this.getBoundingClientRect();
            const x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
            this.style.transform = 'rotateY(' + (x * 10) + 'deg) rotateX(' + (-y * 10) + 'deg) translateY(-6px)';
        }).on('mouseleave', function () { this.style.transform = ''; });
    }
});

// Mobile / tablet sidebar drawer
(function () {
    const sidebar = document.getElementById('dashboardSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggle  = document.getElementById('sidebarToggle');
    const closeBt = document.getElementById('sidebarClose');

    function setOpen(open) {
        sidebar.classList.toggle('open', open);
        overlay.classList.toggle('show', open);
        document.body.classList.toggle('nav-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    toggle.addEventListener('click', function () { setOpen(!sidebar.classList.contains('open')); });
    closeBt.addEventListener('click', function () { setOpen(false); });
    overlay.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
    window.addEventListener('resize', function () { if (window.innerWidth > 992) setOpen(false); });
})();
</script>

</body>
</html>

<?php
session_start();
include 'config/db.php';

// Session Security Check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
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

$msg = "";
$msg_type = "";

// Handle Request Status Actions (APPROVE / REJECT)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $request_id = intval($_GET['id']);
    $action_status = $_GET['action'];

    if ($action_status === 'approve') {
        $update_sql = "UPDATE vip_requests SET status = 'APPROVED' WHERE id = ? AND status = 'PENDING'";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("i", $request_id);
        if ($stmt->execute()) {
            $msg = "VIP Request successfully approved!";
            $msg_type = "success";
        }
    } elseif ($action_status === 'reject') {
        $update_sql = "UPDATE vip_requests SET status = 'REJECTED' WHERE id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("i", $request_id);
        if ($stmt->execute()) {
            $msg = "VIP Request has been rejected!";
            $msg_type = "danger";
        }
    }
}

// Dynamic Metrics
$count_pending  = $conn->query("SELECT COUNT(*) AS total FROM vip_requests WHERE status = 'PENDING'")->fetch_assoc()['total'] ?? 0;
$count_approved = $conn->query("SELECT COUNT(*) AS total FROM vip_requests WHERE status = 'APPROVED'")->fetch_assoc()['total'] ?? 0;
$count_rejected = $conn->query("SELECT COUNT(*) AS total FROM vip_requests WHERE status = 'REJECTED'")->fetch_assoc()['total'] ?? 0;
$count_used     = $conn->query("SELECT COUNT(*) AS total FROM vip_requests WHERE status = 'USED'")->fetch_assoc()['total'] ?? 0;
$count_total    = $conn->query("SELECT COUNT(*) AS total FROM vip_requests")->fetch_assoc()['total'] ?? 0;

// Pagination Setup
$limit = 10; 
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Search and Status Filter Logic
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';

$where_clauses = [];
if (!empty($search)) {
    $safe_search = $conn->real_escape_string($search);
    $where_clauses[] = "(vip_requests.driver_name LIKE '%$safe_search%' OR vip_requests.vehicle_number LIKE '%$safe_search%' OR vip_requests.contact_no LIKE '%$safe_search%' OR vip_requests.reason LIKE '%$safe_search%' OR vehicle_types.type_name LIKE '%$safe_search%')";
}
if (!empty($status_filter)) {
    $safe_status = $conn->real_escape_string($status_filter);
    $where_clauses[] = "vip_requests.status = '$safe_status'";
}

$where_sql = "";
if (count($where_clauses) > 0) {
    $where_sql = " WHERE " . implode(' AND ', $where_clauses);
}

// Total Count Query matched with Table Structure
$count_query = "SELECT COUNT(*) AS total 
                FROM vip_requests 
                LEFT JOIN vehicle_types ON vip_requests.vehicle_type_id = vehicle_types.id 
                $where_sql";

$total_requests = $conn->query($count_query)->fetch_assoc()['total'] ?? 0;
$total_pages = ceil($total_requests / $limit);

// Fetch VIP Requests using updated Column Names
$requests_query = "SELECT vip_requests.id, 
                          vip_requests.driver_name, 
                          vip_requests.vehicle_number, 
                          vip_requests.contact_no,
                          vip_requests.reason, 
                          vip_requests.status, 
                          vip_requests.requested_date, 
                          vehicle_types.type_name 
                  FROM vip_requests 
                  LEFT JOIN vehicle_types ON vip_requests.vehicle_type_id = vehicle_types.id 
                  $where_sql 
                  ORDER BY vip_requests.id DESC LIMIT $limit OFFSET $offset";
$requests_result = $conn->query($requests_query);

// UI helpers
function vip_meta($st) {
    $m = [
        'PENDING'  => ['pending',  'fa-hourglass-half', 'Pending'],
        'APPROVED' => ['approved', 'fa-circle-check',   'Approved'],
        'REJECTED' => ['rejected', 'fa-circle-xmark',   'Rejected'],
        'USED'     => ['used',     'fa-crown',          'Used'],
    ];
    return $m[$st] ?? $m['PENDING'];
}
function tab_url($status, $search) {
    $q = [];
    if ($status !== '') $q[] = 'status_filter=' . urlencode($status);
    if ($search !== '') $q[] = 'search=' . urlencode($search);
    return 'admin_vip_requests.php' . ($q ? '?' . implode('&', $q) : '');
}
$approval_rate = ($count_total > 0) ? round(($count_approved / $count_total) * 100) : 0;
$ring_c = 339.292; $ring_off = 0; $ring_segs = [];
foreach ([['APPROVED', $count_approved, '#16A34A'], ['USED', $count_used, '#FF6B00'], ['PENDING', $count_pending, '#FBBF24'], ['REJECTED', $count_rejected, '#DC2626']] as $sg) {
    if ($count_total > 0 && $sg[1] > 0) {
        $len = ($sg[1] / $count_total) * $ring_c;
        $ring_segs[] = ['len' => round($len, 2), 'off' => round($ring_off, 2), 'col' => $sg[2]];
        $ring_off += $len;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>VIP Approvals Terminal | ParkSmart - Ambalangoda MPCS</title>
    
    <!-- FontAwesome & Google Fonts -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <!-- jQuery -->
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

        /* Sidebar Navigation Styles */
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

        /* Workspace Main Layout */
        .dashboard-workspace {
            flex: 1;
            padding: 24px 3%;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* Top Bar Header */
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

        /* Notification Banners */
        .alert-banner {
            padding: 14px 18px;
            border-radius: 12px;
            font-size: 13.5px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success { background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0; }
        .alert-danger { background: #FEE2E2; color: #B91C1C; border: 1px solid #FECACA; }

        /* ================= VIP TICKET FEED THEME ================= */
        body::before { content: ""; position: fixed; inset: 0; z-index: -1; pointer-events: none;
            background: radial-gradient(600px 400px at 90% -5%, rgba(255,107,0,.10), transparent 70%), radial-gradient(500px 400px at 0% 100%, rgba(10,25,47,.06), transparent 70%); }
        .alert-banner { animation: dropIn .5s cubic-bezier(.2,.9,.3,1.3) both; }
        @keyframes dropIn { from { opacity: 0; transform: translateY(-18px) scale(.96); } to { opacity: 1; transform: none; } }
        @keyframes riseIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: none; } }

        /* Hero with donut */
        .vip-hero { position: relative; overflow: hidden; border-radius: 26px; padding: 30px 38px; color: var(--white);
            background: linear-gradient(120deg, var(--navy-blue), var(--navy-blue-soft) 55%, #22375F);
            display: grid; grid-template-columns: 1fr auto; gap: 30px; align-items: center; box-shadow: 0 18px 45px rgba(10,25,47,.25); }
        .vip-hero::before { content: "\f521"; font-family: "Font Awesome 6 Free"; font-weight: 900; position: absolute; left: -30px; bottom: -70px; font-size: 260px; color: rgba(255,107,0,.07); transform: rotate(-15deg); }
        .vip-hero .hero-clock { position: absolute; top: 18px; right: 22px; background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12); padding: 8px 14px; border-radius: 12px; display: flex; align-items: center; gap: 10px; z-index: 3; }
        .hero-copy { position: relative; z-index: 2; }
        .eyebrow { display: inline-flex; gap: 8px; align-items: center; font-size: 11px; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase; color: var(--primary-orange); background: rgba(255,107,0,.12); padding: 6px 12px; border-radius: 20px; }
        .hero-copy h1 { font-size: 34px; font-weight: 900; margin: 14px 0 6px; line-height: 1.1; }
        .gold { background: linear-gradient(100deg, #FF9E00 20%, #FFE08A 45%, #FF6B00 70%); background-size: 200% auto; -webkit-background-clip: text; background-clip: text; color: transparent; animation: shimmer 3.5s linear infinite; }
        @keyframes shimmer { to { background-position: 200% center; } }
        .hero-copy p { color: #94A3B8; font-size: 13.5px; font-weight: 600; line-height: 1.6; max-width: 440px; }
        .legend { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px; }
        .lg { display: flex; align-items: center; gap: 9px; background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.1); padding: 9px 15px; border-radius: 14px; animation: riseIn .6s ease both; }
        .lg:nth-child(2){animation-delay:.1s} .lg:nth-child(3){animation-delay:.2s} .lg:nth-child(4){animation-delay:.3s}
        .lg i { width: 10px; height: 10px; border-radius: 50%; }
        .lg b { font-size: 18px; font-weight: 800; } .lg small { font-size: 10.5px; font-weight: 700; color: #94A3B8; text-transform: uppercase; letter-spacing: .6px; }
        .donut { position: relative; width: 190px; height: 190px; margin-top: 26px; }
        .donut svg { width: 100%; height: 100%; transform: rotate(-90deg); }
        .donut circle { fill: none; stroke-width: 15; stroke-linecap: butt; }
        .donut .trk { stroke: rgba(255,255,255,.09); }
        .donut .seg { stroke-dasharray: var(--len) 339.292; stroke-dashoffset: calc(var(--off) * -1); animation: draw 1.4s cubic-bezier(.3,.8,.3,1) both; }
        @keyframes draw { from { stroke-dasharray: 0 339.292; } }
        .donut-mid { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .donut-mid b { font-size: 34px; font-weight: 900; line-height: 1; } .donut-mid small { font-size: 10px; font-weight: 800; color: #94A3B8; letter-spacing: 1px; text-transform: uppercase; margin-top: 4px; }

        /* Tabs + search */
        .vip-controls { display: flex; justify-content: space-between; gap: 14px; flex-wrap: wrap; align-items: center; }
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; }
        .tab { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 14px; text-decoration: none; font-size: 12.5px; font-weight: 800; color: var(--text-muted); background: var(--white); border: 2px solid var(--border-color); transition: all .25s ease; }
        .tab em { font-style: normal; font-size: 11px; padding: 2px 8px; border-radius: 10px; background: var(--bg-light); color: var(--navy-blue); }
        .tab:hover { border-color: var(--primary-orange); color: var(--primary-orange); transform: translateY(-2px); }
        .tab.on { background: var(--navy-blue); border-color: var(--navy-blue); color: var(--white); box-shadow: 0 8px 18px rgba(10,25,47,.25); }
        .tab.on em { background: var(--primary-orange); color: var(--white); }
        .tab.pend:not(.on) em { background: #FEF3C7; color: #D97706; animation: blink 1.6s infinite; }
        @keyframes blink { 50% { box-shadow: 0 0 0 5px rgba(217,119,6,.18); } }
        .vsearch { position: relative; display: flex; gap: 8px; }
        .vsearch i.si { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--primary-orange); }
        .vsearch input { width: 320px; max-width: 100%; padding: 12px 16px 12px 42px; border-radius: 30px; border: 2px solid var(--border-color); background: var(--white); font-size: 13px; font-weight: 600; outline: none; transition: all .3s ease; }
        .vsearch input:focus { border-color: var(--primary-orange); box-shadow: 0 0 0 5px rgba(255,107,0,.12); }
        .vsearch button, .vsearch a { border: none; cursor: pointer; text-decoration: none; padding: 0 18px; border-radius: 30px; font-size: 12.5px; font-weight: 800; display: inline-flex; align-items: center; gap: 6px; }
        .vsearch button { background: var(--primary-orange); color: var(--white); } .vsearch button:hover { background: var(--orange-hover); }
        .vsearch a { background: var(--white); color: var(--navy-blue); border: 2px solid var(--border-color); }

        /* Ticket feed */
        .feed { display: flex; flex-direction: column; gap: 18px; }
        .vip-ticket { --c1: #F59E0B; --c2: #FBBF24; display: grid; grid-template-columns: 96px 1fr 0 240px; background: var(--white); border-radius: 20px; border: 1px solid var(--border-color); position: relative;
            box-shadow: 0 8px 26px rgba(10,25,47,.07); animation: slideIn .6s cubic-bezier(.2,.9,.3,1.1) both; transition: transform .3s ease, box-shadow .3s ease; }
        @keyframes slideIn { from { opacity: 0; transform: translateX(-50px); } to { opacity: 1; transform: none; } }
        .vip-ticket:hover { transform: translateY(-4px) scale(1.005); box-shadow: 0 18px 42px rgba(10,25,47,.14); }
        .vip-ticket.approved { --c1: #16A34A; --c2: #4ADE80; } .vip-ticket.rejected { --c1: #DC2626; --c2: #F87171; } .vip-ticket.used { --c1: var(--primary-orange); --c2: #FF9E00; }
        .vip-ticket.pending { border-color: #FCD34D; animation: slideIn .6s cubic-bezier(.2,.9,.3,1.1) both, glow 2.4s ease-in-out infinite .8s; }
        @keyframes glow { 50% { box-shadow: 0 8px 30px rgba(245,158,11,.35); } }
        .tk-stub { border-radius: 20px 0 0 20px; background: linear-gradient(160deg, var(--c1), var(--c2)); color: var(--white); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; position: relative; overflow: hidden; }
        .tk-stub::before { content: ""; position: absolute; inset: 0; background: repeating-linear-gradient(-45deg, rgba(255,255,255,.12) 0 6px, transparent 6px 14px); }
        .tk-ico { position: relative; width: 50px; height: 50px; border-radius: 50%; background: rgba(255,255,255,.22); display: flex; align-items: center; justify-content: center; font-size: 21px; transition: transform .4s ease; }
        .vip-ticket:hover .tk-ico { transform: rotate(360deg) scale(1.1); }
        .tk-no { position: relative; font-family: 'Courier New', monospace; font-size: 11px; font-weight: 800; letter-spacing: 1px; }
        .tk-main { padding: 20px 26px; display: flex; flex-direction: column; gap: 12px; min-width: 0; }
        .tk-row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .tk-name { font-size: 17px; font-weight: 800; color: var(--navy-blue); }
        .tk-phone { font-size: 12px; font-weight: 700; color: var(--text-muted); display: inline-flex; align-items: center; gap: 6px; }
        .tk-phone i { color: var(--primary-orange); }
        .plate { display: inline-flex; background: #FFFBEB; border: 2px solid var(--navy-blue); border-radius: 8px; overflow: hidden; box-shadow: 0 2px 0 rgba(10,25,47,.15); }
        .plate span:first-child { background: var(--navy-blue); color: var(--primary-orange); font-size: 8px; font-weight: 800; padding: 0 6px; display: flex; align-items: center; }
        .plate span:last-child { padding: 4px 12px; font-family: 'Courier New', monospace; font-weight: 800; font-size: 13px; letter-spacing: 1.5px; color: var(--navy-blue); text-transform: uppercase; }
        .vt-chip { display: inline-flex; align-items: center; gap: 7px; font-size: 12px; font-weight: 700; color: var(--text-dark); background: #FFF1E6; padding: 5px 12px; border-radius: 20px; }
        .vt-chip i { color: var(--primary-orange); }
        .tk-reason { position: relative; font-size: 12.5px; font-weight: 600; color: #334155; line-height: 1.55; background: var(--bg-light); border-left: 4px solid var(--c1); border-radius: 0 12px 12px 0; padding: 10px 14px 10px 38px; }
        .tk-reason::before { content: "\f10d"; font-family: "Font Awesome 6 Free"; font-weight: 900; position: absolute; left: 13px; top: 11px; color: var(--c1); font-size: 13px; opacity: .8; }
        .tk-date { font-size: 11.5px; font-weight: 700; color: var(--text-muted); display: inline-flex; align-items: center; gap: 6px; }
        .tk-perf { position: relative; border-left: 2px dashed var(--border-color); }
        .tk-perf::before, .tk-perf::after { content: ""; position: absolute; left: -12px; width: 22px; height: 22px; border-radius: 50%; background: var(--bg-light); border: 1px solid var(--border-color); }
        .tk-perf::before { top: -12px; clip-path: inset(50% 0 0 0); } .tk-perf::after { bottom: -12px; clip-path: inset(0 0 50% 0); }
        .tk-side { padding: 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; }
        .side-label { font-size: 10px; font-weight: 800; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-muted); }
        .stamp { display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px; border: 3px solid var(--c1); color: var(--c1); border-radius: 12px; font-size: 15px; font-weight: 900; letter-spacing: 2px; text-transform: uppercase; transform: rotate(-6deg); animation: stampIn .6s cubic-bezier(.2,1.6,.4,1) both .3s; background: rgba(255,255,255,.7); }
        @keyframes stampIn { from { opacity: 0; transform: rotate(-20deg) scale(2.2); } to { opacity: 1; transform: rotate(-6deg) scale(1); } }
        .lock-note { font-size: 11px; font-weight: 700; color: var(--text-muted); display: flex; align-items: center; gap: 6px; }
        .act-row { display: flex; flex-direction: column; gap: 8px; width: 100%; }
        .act { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 14px; border-radius: 12px; text-decoration: none; font-size: 12.5px; font-weight: 800; transition: all .25s ease; position: relative; overflow: hidden; }
        .act.ok { background: linear-gradient(135deg, #16A34A, #22C55E); color: var(--white); box-shadow: 0 6px 16px rgba(22,163,74,.3); }
        .act.no { background: #FEE2E2; color: #B91C1C; border: 1px solid #FECACA; }
        .act:hover { transform: translateY(-2px); } .act.ok:hover { box-shadow: 0 10px 22px rgba(22,163,74,.45); } .act.no:hover { background: #DC2626; color: var(--white); }

        .empty-feed { text-align: center; padding: 60px 20px; color: var(--text-muted); font-weight: 700; background: var(--white); border-radius: 20px; border: 2px dashed var(--border-color); }
        .empty-feed i { font-size: 48px; color: var(--primary-orange); display: block; margin-bottom: 12px; animation: float 3s ease-in-out infinite; }
        @keyframes float { 50% { transform: translateY(-10px); } }

        /* Pagination */
        .pager { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .pager-info { font-size: 12.5px; font-weight: 700; color: var(--text-muted); }
        .pagination-btn-group { display: flex; gap: 6px; flex-wrap: wrap; }
        .p-btn { min-width: 40px; height: 40px; padding: 0 14px; border-radius: 12px; border: 2px solid var(--border-color); background: var(--white); color: var(--navy-blue); font-size: 13px; font-weight: 800; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all .2s ease; }
        .p-btn:hover { border-color: var(--primary-orange); color: var(--primary-orange); transform: translateY(-2px); }
        .p-btn.active { background: linear-gradient(135deg, var(--primary-orange), #FF8800); border-color: transparent; color: var(--white); box-shadow: 0 6px 14px rgba(255,107,0,.35); }
        .p-btn.disabled { opacity: .4; pointer-events: none; }

        /* Confirm modal */
        .modal-bg { position: fixed; inset: 0; z-index: 2000; background: rgba(10,25,47,.65); backdrop-filter: blur(5px); display: none; align-items: center; justify-content: center; padding: 20px; }
        .modal-bg.show { display: flex; animation: fade .25s ease; }
        @keyframes fade { from { opacity: 0; } }
        .modal { --mc: #16A34A; background: var(--white); border-radius: 24px; padding: 34px 30px 26px; width: 100%; max-width: 380px; text-align: center; animation: pop .45s cubic-bezier(.2,1.5,.4,1); }
        .modal.reject { --mc: #DC2626; }
        @keyframes pop { from { opacity: 0; transform: scale(.7) translateY(30px); } }
        .modal-ico { width: 74px; height: 74px; border-radius: 50%; margin: 0 auto 16px; background: var(--mc); color: var(--white); font-size: 30px; display: flex; align-items: center; justify-content: center; box-shadow: 0 0 0 10px color-mix(in srgb, var(--mc) 15%, transparent); }
        .modal h3 { font-size: 20px; font-weight: 800; color: var(--navy-blue); }
        .modal p { font-size: 13px; color: var(--text-muted); font-weight: 600; margin: 8px 0 20px; line-height: 1.6; }
        .modal p b { color: var(--navy-blue); }
        .modal-btns { display: flex; gap: 10px; }
        .modal-btns button { flex: 1; padding: 12px; border: none; border-radius: 12px; font-size: 13px; font-weight: 800; cursor: pointer; transition: all .2s ease; }
        .m-cancel { background: var(--bg-light); color: var(--navy-blue); } .m-cancel:hover { background: var(--border-color); }
        .m-go { background: var(--mc); color: var(--white); } .m-go:hover { transform: translateY(-2px); filter: brightness(1.1); }

        .confetti { position: fixed; top: -14px; width: 10px; height: 14px; z-index: 3000; pointer-events: none; animation: fall linear forwards; }
        @keyframes fall { to { transform: translateY(105vh) rotate(720deg); opacity: .9; } }

        @media (max-width: 1100px) { .vip-hero { grid-template-columns: 1fr; } .donut { margin: 0 auto; } }
        @media (max-width: 820px) {
            .vip-ticket { grid-template-columns: 1fr; }
            .tk-stub { border-radius: 20px 20px 0 0; flex-direction: row; padding: 12px; }
            .tk-perf { border-left: none; border-top: 2px dashed var(--border-color); height: 0; }
            .tk-perf::before { left: -12px; top: -12px; clip-path: inset(0 50% 0 0); } .tk-perf::after { left: auto; right: -12px; bottom: auto; top: -12px; clip-path: inset(0 0 0 50%); }
            .vip-hero .hero-clock { position: static; }
        }
        @media (max-width: 1100px) {
            .content-split-grid { grid-template-columns: 1fr; }
        }

        /* ---- Mobile-only elements (hidden on desktop) ---- */
        .mobile-topbar, .sidebar-overlay, .sidebar-close-btn { display: none; }

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

            /* Hero */
            .vip-hero { padding: 26px 26px; gap: 22px; }
            .vip-hero .hero-clock { position: static; justify-self: start; }
            .hero-copy p { max-width: 100%; }
        }

        /* ---------- Large phones / small tablets (<= 768px) ---------- */
        @media (max-width: 768px) {
            .vip-hero { border-radius: 20px; }
            .hero-copy h1 { font-size: 30px; }
            .legend { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .lg { min-width: 0; }

            .vip-controls { flex-direction: column; align-items: stretch; gap: 12px; }
            .tabs .tab { flex: 1 1 auto; justify-content: center; padding: 10px 12px; }
            .vsearch { width: 100%; flex-wrap: wrap; }
            .vsearch input { flex: 1 1 160px; min-width: 0; width: auto; font-size: 16px; padding: 13px 16px 13px 42px; } /* stops iOS zoom-on-focus */
            .vsearch button, .vsearch a { min-height: 46px; }

            .tk-side { padding: 16px 18px 18px; }
            .act-row { flex-direction: row; }
            .act { flex: 1; padding: 12px 14px; }

            .pager { justify-content: center; text-align: center; }
            .pagination-btn-group { justify-content: center; }
        }

        /* ---------- Phones (<= 600px) ---------- */
        @media (max-width: 600px) {
            .dashboard-workspace { padding: 12px 12px 24px; gap: 14px; }
            .mobile-topbar { top: 8px; padding: 9px 12px; }

            .vip-hero { padding: 20px 16px; gap: 18px; }
            .vip-hero::before { font-size: 200px; left: -40px; bottom: -60px; }
            .hero-copy h1 { font-size: 26px; }
            .eyebrow { font-size: 10px; letter-spacing: 1.1px; }
            .lg { padding: 9px 12px; gap: 8px; }
            .lg b { font-size: 17px; }
            .donut { width: 160px; height: 160px; }
            .donut-mid b { font-size: 28px; }

            .tabs { gap: 6px; }
            .tabs .tab { font-size: 12px; padding: 9px 10px; gap: 6px; }

            .vip-ticket { border-radius: 18px; }
            .tk-stub { border-radius: 18px 18px 0 0; }
            .tk-ico { width: 40px; height: 40px; font-size: 17px; }
            .tk-main { padding: 16px 16px; gap: 10px; }
            .tk-row { gap: 10px; }
            .tk-name { font-size: 16px; }
            .tk-reason { padding: 10px 12px 10px 34px; }
            .stamp { font-size: 13px; padding: 8px 14px; }

            .p-btn { min-width: 38px; height: 38px; padding: 0 12px; }

            .modal-bg { padding: 14px; }
            .modal { padding: 28px 22px 22px; border-radius: 22px; }
            .modal-btns button { min-height: 46px; }
        }

        /* ---------- Very small phones (<= 380px) ---------- */
        @media (max-width: 380px) {
            .mobile-brand p { display: none; }
            .hero-copy h1 { font-size: 24px; }
            .tk-phone, .tk-date { font-size: 11px; }
            .act { font-size: 12px; padding: 11px 8px; }
        }

        /* Touch screens: no sticky hover effects */
        @media (hover: none) {
            .vip-ticket:hover { transform: none; box-shadow: 0 8px 26px rgba(10,25,47,.07); }
            .vip-ticket:hover .tk-ico { transform: none; }
            .tab:hover { transform: none; }
            .act:hover, .p-btn:hover, .m-go:hover { transform: none; }
        }

        @media (prefers-reduced-motion: reduce) {
            .dashboard-sidebar, .sidebar-overlay { transition: none; }
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
                    <p>Ambalangoda MPCS</p>
                </div>
            </a>

            <!-- System Terminals Section -->
            <div class="menu-category-label">Gate Terminals</div>
            <ul class="sidebar-menu-list">
                <li><a href="index.php" class="sidebar-menu-link"><i class="fa-solid fa-ticket"></i> Entry Gate</a></li>
                <li><a href="3D parking.php" class="sidebar-menu-link"><i class="fa-solid fa-cubes"></i> Parking Visualizer</a></li>
                <li><a href="exit.php" class="sidebar-menu-link"><i class="fa-solid fa-right-from-bracket"></i> Exit Gate</a></li>
                <li><a href="vip_request.php" class="sidebar-menu-link"><i class="fa-solid fa-crown"></i> VIP Pass Request</a></li>
            </ul>

            <!-- Admin Section -->
            <div class="menu-category-label">Admin Controls</div>
            <ul class="sidebar-menu-list">
                <li><a href="admin_dashboard.php" class="sidebar-menu-link"><i class="fa-solid fa-chart-pie"></i> Dashboard</a></li>
                <li><a href="admin_security_users.php" class="sidebar-menu-link"><i class="fa-solid fa-user-shield"></i> Security Staff</a></li>
                <li><a href="admin_vip_requests.php" class="sidebar-menu-link active"><i class="fa-solid fa-crown"></i> VIP Approvals</a></li>
                <li><a href="admin_reports.php" class="sidebar-menu-link"><i class="fa-solid fa-chart-column"></i> Revenue Reports</a></li>
                <li><a href="vehicle_investigation.php" class="sidebar-menu-link"><i class="fa-solid fa-shield-halved"></i> CID & Trace</a></li>
            </ul>
        </div>

        <!-- User Profile Footer Card & Logout -->
        <div class="sidebar-bottom-wrapper">
            <div class="sidebar-user-card">
                <div class="sidebar-user-avatar"><i class="fa-solid fa-user-shield"></i></div>
                <div>
                    <h4 style="font-size:12px; font-weight:800;"><?php echo htmlspecialchars($display_name); ?></h4>
                    <p style="font-size:10px; color:#FFB380;">Master Admin</p>
                </div>
            </div>
            <a href="?action=logout" class="btn-sidebar-logout" onclick="return confirm('Logout system?');">
                <i class="fa-solid fa-right-from-bracket"></i> Logout System
            </a>
        </div>
    </aside>

    <!-- Workspace Panel -->
    <main class="dashboard-workspace">

        <!-- Mobile / Tablet Top Bar (hidden on desktop) -->
        <div class="mobile-topbar">
            <button type="button" class="mobile-menu-btn" id="sidebarToggle" aria-label="Open menu" aria-controls="dashboardSidebar" aria-expanded="false"><i class="fa-solid fa-bars"></i></button>
            <div class="mobile-brand">
                <h2>PARK<span>SMART</span></h2>
                <p>Ambalangoda MPCS</p>
            </div>
        </div>

        <!-- Hero -->
        <header class="vip-hero">
            <div class="hero-clock">
                <i class="fa-regular fa-clock" style="color:var(--primary-orange);"></i>
                <div><div class="clock-time" id="liveClock">00:00:00 AM</div><div class="clock-date" id="liveDate">Loading...</div></div>
            </div>
            <div class="hero-copy">
                <div class="eyebrow"><i class="fa-solid fa-crown"></i> Ambalangoda MPCS</div>
                <h1>VIP <span class="gold">Approvals</span></h1>
                <p>Review special parking requests and grant golden passes with a single tap.</p>
                <div class="legend">
                    <div class="lg"><i style="background:#FBBF24"></i><div><b class="count" data-to="<?= (int)$count_pending ?>">0</b><br><small>Pending</small></div></div>
                    <div class="lg"><i style="background:#16A34A"></i><div><b class="count" data-to="<?= (int)$count_approved ?>">0</b><br><small>Approved</small></div></div>
                    <div class="lg"><i style="background:#FF6B00"></i><div><b class="count" data-to="<?= (int)$count_used ?>">0</b><br><small>Used</small></div></div>
                    <div class="lg"><i style="background:#DC2626"></i><div><b class="count" data-to="<?= (int)$count_rejected ?>">0</b><br><small>Rejected</small></div></div>
                </div>
            </div>
            <div class="donut">
                <svg viewBox="0 0 130 130">
                    <circle class="trk" cx="65" cy="65" r="54"></circle>
                    <?php foreach ($ring_segs as $sg): ?>
                        <circle class="seg" cx="65" cy="65" r="54" style="stroke:<?= $sg['col'] ?>; --len:<?= $sg['len'] ?>; --off:<?= $sg['off'] ?>;"></circle>
                    <?php endforeach; ?>
                </svg>
                <div class="donut-mid"><b><span class="count" data-to="<?= $approval_rate ?>">0</span>%</b><small>Approval Rate</small></div>
            </div>
        </header>

        <!-- Notification Banner -->
        <?php if (!empty($msg)): ?>
            <div class="alert-banner alert-<?php echo $msg_type; ?>" id="flashMsg" data-type="<?php echo $msg_type; ?>">
                <i class="fa-solid <?php echo ($msg_type === 'success') ? 'fa-circle-check' : 'fa-circle-xmark'; ?>"></i>
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <!-- Tabs + Search -->
        <div class="vip-controls">
            <nav class="tabs">
                <a class="tab <?= $status_filter === '' ? 'on' : '' ?>" href="<?= tab_url('', $search) ?>"><i class="fa-solid fa-layer-group"></i> All <em><?= (int)$count_total ?></em></a>
                <a class="tab pend <?= $status_filter === 'PENDING' ? 'on' : '' ?>" href="<?= tab_url('PENDING', $search) ?>"><i class="fa-solid fa-hourglass-half"></i> Pending <em><?= (int)$count_pending ?></em></a>
                <a class="tab <?= $status_filter === 'APPROVED' ? 'on' : '' ?>" href="<?= tab_url('APPROVED', $search) ?>"><i class="fa-solid fa-circle-check"></i> Approved <em><?= (int)$count_approved ?></em></a>
                <a class="tab <?= $status_filter === 'USED' ? 'on' : '' ?>" href="<?= tab_url('USED', $search) ?>"><i class="fa-solid fa-crown"></i> Used <em><?= (int)$count_used ?></em></a>
                <a class="tab <?= $status_filter === 'REJECTED' ? 'on' : '' ?>" href="<?= tab_url('REJECTED', $search) ?>"><i class="fa-solid fa-circle-xmark"></i> Rejected <em><?= (int)$count_rejected ?></em></a>
            </nav>
            <form method="GET" action="admin_vip_requests.php" class="vsearch">
                <i class="fa-solid fa-magnifying-glass si"></i>
                <input type="text" name="search" placeholder="Search driver, phone, vehicle, reason..." value="<?php echo htmlspecialchars($search); ?>">
                <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">
                <button type="submit"><i class="fa-solid fa-filter"></i> Go</button>
                <?php if (!empty($search) || !empty($status_filter)): ?>
                    <a href="admin_vip_requests.php"><i class="fa-solid fa-rotate-left"></i> Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- VIP Ticket Feed -->
        <section class="feed">
            <?php if ($requests_result && $requests_result->num_rows > 0): $n = 0; ?>
                <?php while ($row = $requests_result->fetch_assoc()):
                    [$cls, $ico, $label] = vip_meta($row['status']);
                    $type_low = strtolower($row['type_name'] ?? '');
                    $vico = preg_match('/bike|motor|cycle/', $type_low) ? 'fa-motorcycle' : (strpos($type_low, 'bus') !== false ? 'fa-bus' : (preg_match('/lorry|truck/', $type_low) ? 'fa-truck' : (strpos($type_low, 'van') !== false ? 'fa-van-shuttle' : 'fa-car-side')));
                ?>
                    <article class="vip-ticket <?= $cls ?>" style="animation-delay: <?= $n++ * 0.08 ?>s;">
                        <div class="tk-stub">
                            <div class="tk-ico"><i class="fa-solid <?= $ico ?>"></i></div>
                            <div class="tk-no">#<?= str_pad($row['id'], 4, '0', STR_PAD_LEFT) ?></div>
                        </div>

                        <div class="tk-main">
                            <div class="tk-row">
                                <span class="tk-name"><?php echo htmlspecialchars($row['driver_name']); ?></span>
                                <span class="tk-phone"><i class="fa-solid fa-phone"></i><?php echo htmlspecialchars($row['contact_no']); ?></span>
                            </div>
                            <div class="tk-row">
                                <span class="plate"><span>LK</span><span><?php echo htmlspecialchars($row['vehicle_number']); ?></span></span>
                                <span class="vt-chip"><i class="fa-solid <?= $vico ?>"></i><?php echo htmlspecialchars($row['type_name'] ?? 'General'); ?></span>
                                <span class="tk-date"><i class="fa-regular fa-calendar"></i><?php echo !empty($row['requested_date']) ? date('M d, Y | h:i A', strtotime($row['requested_date'])) : 'N/A'; ?></span>
                            </div>
                            <div class="tk-reason"><?php echo htmlspecialchars($row['reason']); ?></div>
                        </div>

                        <div class="tk-perf"></div>

                        <div class="tk-side">
                            <?php if ($row['status'] === 'PENDING'): ?>
                                <span class="side-label">Needs your decision</span>
                                <div class="act-row">
                                    <a href="admin_vip_requests.php?action=approve&id=<?php echo $row['id']; ?>" class="act ok"
                                       data-act="approve" data-name="<?php echo htmlspecialchars($row['driver_name']); ?>" data-plate="<?php echo htmlspecialchars($row['vehicle_number']); ?>">
                                        <i class="fa-solid fa-check"></i> Approve
                                    </a>
                                    <a href="admin_vip_requests.php?action=reject&id=<?php echo $row['id']; ?>" class="act no"
                                       data-act="reject" data-name="<?php echo htmlspecialchars($row['driver_name']); ?>" data-plate="<?php echo htmlspecialchars($row['vehicle_number']); ?>">
                                        <i class="fa-solid fa-xmark"></i> Reject
                                    </a>
                                </div>
                            <?php else: ?>
                                <span class="stamp"><i class="fa-solid <?= $ico ?>"></i><?= $label ?></span>
                                <span class="lock-note"><i class="fa-solid fa-lock"></i> Decision locked</span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-feed">
                    <i class="fa-solid fa-ticket"></i>
                    No VIP pass requests matched your search criteria.
                </div>
            <?php endif; ?>
        </section>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pager">
                <div class="pager-info">Page <?php echo $page; ?> of <?php echo $total_pages; ?> &middot; <?php echo $total_requests; ?> requests</div>
                <div class="pagination-btn-group">
                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status_filter=<?php echo urlencode($status_filter); ?>" class="p-btn <?php echo ($page <= 1) ? 'disabled' : ''; ?>"><i class="fa-solid fa-chevron-left"></i> Prev</a>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status_filter=<?php echo urlencode($status_filter); ?>" class="p-btn <?php echo ($page == $i) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status_filter=<?php echo urlencode($status_filter); ?>" class="p-btn <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">Next <i class="fa-solid fa-chevron-right"></i></a>
                </div>
            </div>
        <?php endif; ?>

    </main>
</div>

<!-- Confirm Modal -->
<div class="modal-bg" id="modalBg">
    <div class="modal" id="modalBox">
        <div class="modal-ico"><i class="fa-solid fa-crown" id="modalIcon"></i></div>
        <h3 id="modalTitle">Approve VIP Pass?</h3>
        <p id="modalText"></p>
        <div class="modal-btns">
            <button type="button" class="m-cancel" id="modalCancel">Cancel</button>
            <button type="button" class="m-go" id="modalGo">Confirm</button>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    function updateLiveClock() {
        const now = new Date();
        document.getElementById('liveClock').textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
        document.getElementById('liveDate').textContent = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
    }
    updateLiveClock();
    setInterval(updateLiveClock, 1000);

    // Count-up numbers
    $('.count').each(function () {
        const el = this, to = parseInt(el.dataset.to, 10) || 0, t0 = performance.now(), dur = 1200;
        (function step(t) {
            const p = Math.min((t - t0) / dur, 1);
            el.textContent = Math.round(to * (1 - Math.pow(1 - p, 3)));
            if (p < 1) requestAnimationFrame(step);
        })(t0);
    });

    // Custom confirm modal for Approve / Reject
    let goHref = '';
    $('.act').on('click', function (e) {
        e.preventDefault();
        const approve = this.dataset.act === 'approve';
        goHref = this.getAttribute('href');
        $('#modalBox').toggleClass('reject', !approve);
        $('#modalIcon').attr('class', 'fa-solid ' + (approve ? 'fa-crown' : 'fa-ban'));
        $('#modalTitle').text(approve ? 'Approve VIP Pass?' : 'Reject this request?');
        $('#modalText').html((approve ? 'Grant a VIP pass to ' : 'Decline the request from ') + '<b></b> (<b></b>).')
            .find('b').eq(0).text(this.dataset.name).end().eq(1).text(this.dataset.plate);
        $('#modalGo').text(approve ? 'Yes, Approve' : 'Yes, Reject');
        $('#modalBg').addClass('show');
    });
    $('#modalCancel').on('click', function () { $('#modalBg').removeClass('show'); });
    $('#modalBg').on('click', function (e) { if (e.target === this) $(this).removeClass('show'); });
    $('#modalGo').on('click', function () { window.location.href = goHref; });
    $(document).on('keydown', function (e) { if (e.key === 'Escape') $('#modalBg').removeClass('show'); });

    // Confetti after a successful approval
    if ($('#flashMsg').data('type') === 'success') {
        const cols = ['#FF6B00', '#FF9E00', '#FBBF24', '#16A34A', '#0A192F'];
        for (let i = 0; i < 70; i++) {
            $('<i class="confetti"></i>').css({
                left: Math.random() * 100 + 'vw', background: cols[i % cols.length],
                animationDuration: (2 + Math.random() * 2.5) + 's', animationDelay: (Math.random() * .6) + 's',
                borderRadius: i % 3 ? '2px' : '50%'
            }).appendTo('body').on('animationend', function () { $(this).remove(); });
        }
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
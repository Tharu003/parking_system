<?php
session_start();
include 'config/db.php';

// Session Security Check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}

// Admin Display Name Format
$raw_username = $_SESSION['admin_username'] ?? ($_SESSION['admin_name'] ?? 'Admin');
$display_name = ucfirst(strtolower($raw_username)) . " Admin";

// Logout Action Logic
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: admin_login.php");
    exit();
}

// Dynamic Metrics for Vehicle Investigation
$count_parked    = $conn->query("SELECT COUNT(*) AS total FROM tickets WHERE status = 'PARKED'")->fetch_assoc()['total'] ?? 0;
$count_completed = $conn->query("SELECT COUNT(*) AS total FROM tickets WHERE status = 'COMPLETED'")->fetch_assoc()['total'] ?? 0;
$count_today     = $conn->query("SELECT COUNT(*) AS total FROM tickets WHERE DATE(entry_time) = CURDATE()")->fetch_assoc()['total'] ?? 0;
$count_total     = $conn->query("SELECT COUNT(*) AS total FROM tickets")->fetch_assoc()['total'] ?? 0;

// Search Inputs
$search_vehicle = isset($_GET['vehicle_number']) ? trim($_GET['vehicle_number']) : '';
$start_time     = isset($_GET['start_time']) ? $_GET['start_time'] : '';
$end_time       = isset($_GET['end_time']) ? $_GET['end_time'] : '';
$search_mode    = isset($_GET['search_mode']) ? $_GET['search_mode'] : 'vehicle'; // 'vehicle' or 'time_range'

$results = null;
$total_entries = 0;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['search_vehicle_btn']) || isset($_GET['search_time_btn']))) {
    
    // MODE 1: Search by Specific Vehicle Number
    if ($search_mode === 'vehicle' && !empty($search_vehicle)) {
        $safe_vehicle = $conn->real_escape_string($search_vehicle);
        $query = "SELECT t.*, v.type_name 
                  FROM tickets t 
                  LEFT JOIN vehicle_types v ON t.vehicle_type_id = v.id 
                  WHERE t.vehicle_number LIKE '%$safe_vehicle%' 
                  ORDER BY t.entry_time DESC";
    } 
    // MODE 2: Search Vehicles Present within Specific Time Window
    else if ($search_mode === 'time_range' && !empty($start_time) && !empty($end_time)) {
        $safe_start = $conn->real_escape_string($start_time);
        $safe_end   = $conn->real_escape_string($end_time);

        // Logic: Entry time before range end AND (Exit time after range start OR Still parked)
        $query = "SELECT t.*, v.type_name 
                  FROM tickets t 
                  LEFT JOIN vehicle_types v ON t.vehicle_type_id = v.id 
                  WHERE t.entry_time <= '$safe_end' 
                  AND (t.exit_time >= '$safe_start' OR t.exit_time IS NULL OR t.status = 'PARKED')
                  ORDER BY t.entry_time DESC";
    }

    if (isset($query)) {
        $results = $conn->query($query);
        $total_entries = $results ? $results->num_rows : 0;
    }
}

// ---- Prepare rows + summary stats for the timeline UI / print sheet ----
$rows = array();
if ($results) { while ($r_ = $results->fetch_assoc()) { $rows[] = $r_; } }
$inside_cnt = 0; $dur_sum = 0; $dur_cnt = 0; $plates = array(); $first_seen = null; $last_seen = null;
foreach ($rows as $r_) {
    if (empty($r_['exit_time']) || $r_['status'] === 'PARKED') { $inside_cnt++; }
    if (!empty($r_['duration_hours'])) { $dur_sum += (float)$r_['duration_hours']; $dur_cnt++; }
    $plates[$r_['vehicle_number']] = true;
    $t_ = strtotime($r_['entry_time']);
    if ($first_seen === null || $t_ < $first_seen) $first_seen = $t_;
    if ($last_seen === null || $t_ > $last_seen) $last_seen = $t_;
}
$exited_cnt = count($rows) - $inside_cnt;
$avg_dur = $dur_cnt > 0 ? $dur_sum / $dur_cnt : 0;
$unique_plates = count($plates);
$scope_text = ($search_mode === 'vehicle')
    ? 'Vehicle trace: ' . strtoupper($search_vehicle)
    : 'Time window: ' . ($start_time ? date('M d, Y h:i A', strtotime($start_time)) : '-') . '  to  ' . ($end_time ? date('M d, Y h:i A', strtotime($end_time)) : '-');
$case_id = 'CID-' . date('Ymd-His');
function trace_icon($name) {
    $n = strtolower($name ?? '');
    if (preg_match('/bike|motor|cycle/', $n)) return 'fa-motorcycle';
    if (strpos($n, 'bus') !== false)          return 'fa-bus';
    if (preg_match('/lorry|truck/', $n))      return 'fa-truck';
    if (strpos($n, 'van') !== false)          return 'fa-van-shuttle';
    if (preg_match('/three|tuk|wheel/', $n))  return 'fa-taxi';
    return 'fa-car-side';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CID Security Investigation - Ambalangoda MPCS</title>
    
    <!-- FontAwesome & Google Fonts -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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

        /* ================= CID INVESTIGATION - TRACE CONSOLE THEME ================= */
        body::before { content: ""; position: fixed; inset: 0; z-index: -1; pointer-events: none;
            background: radial-gradient(650px 400px at 0% 0%, rgba(255,107,0,.09), transparent 70%), radial-gradient(600px 420px at 100% 100%, rgba(10,25,47,.08), transparent 70%); }
        @keyframes riseIn { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: none; } }
        @keyframes blink { 50% { opacity: 0; } }
        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes floaty { 50% { transform: translateY(-9px); } }
        .rise { animation: riseIn .7s cubic-bezier(.2,.9,.3,1.1) both; }

        /* Hero */
        .cid-hero { position: relative; overflow: hidden; border-radius: 28px; padding: 34px 40px; color: var(--white); min-height: 250px;
            background: linear-gradient(125deg, #070F1D, var(--navy-blue) 50%, var(--navy-blue-soft));
            box-shadow: 0 20px 50px rgba(10,25,47,.3); }
        .cid-hero::before { content: ""; position: absolute; inset: 0; opacity: .5;
            background-image: linear-gradient(rgba(255,255,255,.05) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.05) 1px, transparent 1px);
            background-size: 38px 38px; mask-image: linear-gradient(90deg, transparent, #000 40%, #000); -webkit-mask-image: linear-gradient(90deg, transparent, #000 40%, #000); }
        .scan-line { position: absolute; left: 0; right: 0; height: 90px; top: -90px; background: linear-gradient(180deg, transparent, rgba(255,107,0,.16), transparent); animation: scan 5s linear infinite; }
        @keyframes scan { to { top: 100%; } }
        .radar { position: absolute; right: 60px; top: 50%; width: 230px; height: 230px; transform: translateY(-50%); border-radius: 50%; border: 2px solid rgba(255,107,0,.5);
            background: repeating-radial-gradient(circle, transparent 0 28px, rgba(255,107,0,.22) 28px 29px); box-shadow: 0 0 60px rgba(255,107,0,.25), inset 0 0 40px rgba(255,107,0,.12); }
        .radar::before { content: ""; position: absolute; inset: 0; border-radius: 50%; background: conic-gradient(from 0deg, rgba(255,107,0,.55), transparent 28%); animation: spin 3.6s linear infinite; }
        .radar::after { content: ""; position: absolute; left: 50%; top: 0; bottom: 0; width: 1px; background: rgba(255,107,0,.25); box-shadow: 0 0 0 0 transparent; }
        .blip { position: absolute; width: 9px; height: 9px; border-radius: 50%; background: #FF9E00; box-shadow: 0 0 12px #FF6B00; animation: blipPulse 3.6s ease-out infinite; z-index: 2; }
        @keyframes blipPulse { 0%, 55% { opacity: 0; transform: scale(.3); } 60% { opacity: 1; transform: scale(1.3); } 100% { opacity: 0; transform: scale(1); } }
        .hero-copy { position: relative; z-index: 3; max-width: 560px; }
        .restricted { display: inline-flex; align-items: center; gap: 8px; font-size: 11px; font-weight: 800; letter-spacing: 1.6px; text-transform: uppercase; color: #F87171; background: rgba(220,38,38,.14); border: 1px solid rgba(220,38,38,.35); padding: 6px 13px; border-radius: 20px; }
        .restricted i { animation: blink 1.4s infinite; }
        .hero-copy h1 { font-size: 36px; font-weight: 900; margin: 16px 0 8px; line-height: 1.1; }
        .hero-copy h1 span { background: linear-gradient(90deg, var(--primary-orange), #FFB347); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .hero-copy p { color: #94A3B8; font-size: 13.5px; font-weight: 600; line-height: 1.6; }
        .hero-stats { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 22px; }
        .hs { background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12); backdrop-filter: blur(6px); padding: 10px 18px; border-radius: 14px; animation: riseIn .7s ease both; }
        .hs:nth-child(2){animation-delay:.1s} .hs:nth-child(3){animation-delay:.2s} .hs:nth-child(4){animation-delay:.3s}
        .hs b { display: block; font-size: 22px; font-weight: 900; line-height: 1; } .hs small { font-size: 10px; font-weight: 700; letter-spacing: .8px; text-transform: uppercase; color: #94A3B8; }
        .hero-clock { position: absolute; top: 20px; right: 24px; z-index: 4; background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12); padding: 8px 14px; border-radius: 12px; display: flex; align-items: center; gap: 10px; }

        /* Console */
        .console { background: linear-gradient(160deg, #0A192F, #101F3A); border-radius: 24px; padding: 28px; border: 1px solid rgba(255,107,0,.35); box-shadow: 0 0 0 4px rgba(255,107,0,.06), 0 24px 50px rgba(10,25,47,.3); position: relative; overflow: hidden; }
        .console::after { content: ""; position: absolute; right: -60px; top: -60px; width: 200px; height: 200px; border-radius: 50%; background: radial-gradient(circle, rgba(255,107,0,.25), transparent 70%); }
        .con-top { display: flex; justify-content: space-between; align-items: center; gap: 14px; flex-wrap: wrap; position: relative; z-index: 2; margin-bottom: 20px; }
        .con-title { color: var(--white); font-size: 15px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .con-title i { color: var(--primary-orange); }
        .con-seg { display: flex; background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.12); border-radius: 14px; padding: 4px; position: relative; }
        .con-seg button { position: relative; z-index: 2; border: none; background: transparent; padding: 10px 18px; border-radius: 10px; font-size: 12.5px; font-weight: 800; color: #94A3B8; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: color .3s ease; }
        .con-seg button.on { color: var(--white); }
        .con-seg .glider { position: absolute; z-index: 1; top: 4px; bottom: 4px; left: 4px; border-radius: 10px; background: linear-gradient(135deg, var(--primary-orange), #FF8800); box-shadow: 0 6px 16px rgba(255,107,0,.4); transition: all .4s cubic-bezier(.2,.9,.3,1.2); }
        .con-form { position: relative; z-index: 2; display: none; }
        .con-form.on { display: block; animation: riseIn .5s ease both; }
        .prompt { font-family: 'Courier New', monospace; font-size: 12px; font-weight: 700; color: #FF9E00; letter-spacing: 1px; margin-bottom: 10px; }
        .prompt::after { content: "_"; animation: blink 1s steps(2) infinite; }
        .con-row { display: flex; gap: 14px; align-items: stretch; flex-wrap: wrap; }
        .con-input { flex: 1; min-width: 220px; position: relative; }
        .con-input > i { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: var(--primary-orange); }
        .con-input input, .dt-group input { width: 100%; padding: 16px 18px 16px 46px; border-radius: 14px; border: 2px solid rgba(255,255,255,.14); background: rgba(255,255,255,.06); color: var(--white); font-size: 16px; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase; outline: none; font-family: 'Courier New', monospace; transition: all .3s ease; caret-color: var(--primary-orange); }
        .con-input input::placeholder { color: #475569; letter-spacing: 1px; }
        .con-input input:focus, .dt-group input:focus { border-color: var(--primary-orange); box-shadow: 0 0 0 5px rgba(255,107,0,.16), 0 0 26px rgba(255,107,0,.2); background: rgba(255,255,255,.09); }
        .btn-trace { border: none; cursor: pointer; padding: 0 30px; min-height: 54px; border-radius: 14px; background: linear-gradient(135deg, var(--primary-orange), #FF8800); color: var(--white); font-size: 14px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; gap: 10px; box-shadow: 0 10px 26px rgba(255,107,0,.4); transition: all .3s ease; position: relative; overflow: hidden; }
        .btn-trace:hover { transform: translateY(-3px); box-shadow: 0 16px 34px rgba(255,107,0,.55); }
        .btn-trace::after { content: ""; position: absolute; top: 0; left: -80%; width: 50%; height: 100%; background: linear-gradient(100deg, transparent, rgba(255,255,255,.4), transparent); transform: skewX(-20deg); }
        .btn-trace:hover::after { animation: shine .8s ease; } @keyframes shine { to { left: 140%; } }
        .plate-preview { margin-top: 18px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .plate-preview small { font-size: 10.5px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; color: #64748B; }
        .plate-big { display: inline-flex; background: #FFFBEB; border: 3px solid #E2E8F0; border-radius: 12px; overflow: hidden; box-shadow: 0 6px 0 rgba(0,0,0,.25), 0 14px 30px rgba(0,0,0,.3); transition: transform .3s cubic-bezier(.2,1.5,.4,1); }
        .plate-big.bump { transform: scale(1.06); }
        .plate-big .side { background: var(--navy-blue); color: var(--primary-orange); font-size: 9px; font-weight: 800; padding: 0 9px; display: flex; align-items: center; border-right: 2px solid #E2E8F0; }
        .plate-big .no { padding: 8px 22px; font-family: 'Courier New', monospace; font-weight: 900; font-size: 24px; letter-spacing: 3px; color: var(--navy-blue); min-width: 170px; text-align: center; }
        .plate-big .no.ghost { color: #CBD5E1; }
        .dt-row { display: grid; grid-template-columns: 1fr 1fr auto; gap: 14px; align-items: end; }
        .dt-group label { display: block; font-size: 10.5px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; color: #94A3B8; margin-bottom: 8px; }
        .dt-group input { padding: 15px 16px; font-size: 14px; letter-spacing: .5px; text-transform: none; color-scheme: dark; }
        .quick { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px; align-items: center; }
        .quick span { font-size: 10.5px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; color: #64748B; margin-right: 4px; }
        .quick button { border: 1px solid rgba(255,255,255,.16); background: rgba(255,255,255,.05); color: #CBD5E1; padding: 8px 15px; border-radius: 20px; font-size: 12px; font-weight: 700; cursor: pointer; transition: all .25s ease; }
        .quick button:hover { background: var(--primary-orange); border-color: var(--primary-orange); color: var(--white); transform: translateY(-2px); }

        /* Results */
        .res-wrap { display: flex; flex-direction: column; gap: 20px; scroll-margin-top: 20px; }
        .case-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
        .case-head .case-tag { font-family: 'Courier New', monospace; font-size: 11px; font-weight: 800; letter-spacing: 1.2px; color: var(--primary-orange); background: #FFF1E6; padding: 5px 12px; border-radius: 8px; display: inline-block; }
        .case-head h2 { font-size: 21px; font-weight: 900; color: var(--navy-blue); margin: 10px 0 4px; }
        .case-head p { font-size: 12.5px; color: var(--text-muted); font-weight: 600; }
        .btn-print { border: none; cursor: pointer; background: var(--navy-blue); color: var(--white); padding: 13px 22px; border-radius: 14px; font-size: 13px; font-weight: 800; display: inline-flex; align-items: center; gap: 9px; transition: all .3s ease; }
        .btn-print i { color: var(--primary-orange); } .btn-print:hover { background: var(--primary-orange); transform: translateY(-3px); box-shadow: 0 12px 24px rgba(255,107,0,.35); } .btn-print:hover i { color: var(--white); }
        .sum-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; }
        .sum { background: var(--white); border: 1px solid var(--border-color); border-radius: 18px; padding: 16px 18px; display: flex; align-items: center; gap: 14px; box-shadow: 0 6px 22px rgba(10,25,47,.05); transition: all .3s ease; animation: riseIn .6s ease both; }
        .sum:nth-child(2){animation-delay:.07s} .sum:nth-child(3){animation-delay:.14s} .sum:nth-child(4){animation-delay:.21s}
        .sum:hover { transform: translateY(-4px); box-shadow: 0 14px 30px rgba(10,25,47,.1); }
        .sum-ico { width: 44px; height: 44px; border-radius: 14px; background: color-mix(in srgb, var(--k) 14%, white); color: var(--k); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .sum small { display: block; font-size: 10px; font-weight: 800; letter-spacing: .9px; text-transform: uppercase; color: var(--text-muted); }
        .sum b { font-size: 21px; font-weight: 900; color: var(--navy-blue); }
        .flt { display: flex; gap: 8px; flex-wrap: wrap; }
        .flt button { border: 2px solid var(--border-color); background: var(--white); cursor: pointer; padding: 9px 18px; border-radius: 30px; font-size: 12.5px; font-weight: 800; color: var(--text-muted); transition: all .25s ease; }
        .flt button:hover { border-color: var(--primary-orange); color: var(--primary-orange); }
        .flt button.on { background: var(--navy-blue); border-color: var(--navy-blue); color: var(--white); }

        /* Timeline */
        .timeline { position: relative; padding-left: 62px; display: flex; flex-direction: column; gap: 22px; }
        .timeline::before { content: ""; position: absolute; left: 21px; top: 10px; bottom: 10px; width: 3px; border-radius: 3px; background: linear-gradient(180deg, var(--primary-orange), #FBBF24 50%, var(--border-color)); }
        .tl-item { position: relative; animation: tlIn .6s cubic-bezier(.2,.9,.3,1.1) both; }
        .tl-item.hide { display: none; }
        @keyframes tlIn { from { opacity: 0; transform: translateX(-36px); } to { opacity: 1; transform: none; } }
        .tl-node { position: absolute; left: -62px; top: 18px; width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 17px; color: var(--white); background: linear-gradient(135deg, #16A34A, #4ADE80); border: 4px solid var(--bg-light); box-shadow: 0 6px 16px rgba(22,163,74,.35); z-index: 2; }
        .tl-item.inside .tl-node { background: linear-gradient(135deg, #DC2626, #F87171); box-shadow: 0 6px 16px rgba(220,38,38,.4); animation: nodePulse 1.8s infinite; }
        @keyframes nodePulse { 0% { box-shadow: 0 0 0 0 rgba(220,38,38,.55); } 100% { box-shadow: 0 0 0 16px rgba(220,38,38,0); } }
        .tl-card { background: var(--white); border-radius: 20px; border: 1px solid var(--border-color); padding: 20px 24px; box-shadow: 0 8px 26px rgba(10,25,47,.06); transition: all .3s ease; position: relative; overflow: hidden; }
        .tl-card::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 5px; background: #16A34A; }
        .tl-item.inside .tl-card { border-color: #FCA5A5; } .tl-item.inside .tl-card::before { background: #DC2626; }
        .tl-card:hover { transform: translateX(6px); box-shadow: 0 16px 38px rgba(10,25,47,.12); }
        .tl-top { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
        .plate { display: inline-flex; background: #FFFBEB; border: 2px solid var(--navy-blue); border-radius: 8px; overflow: hidden; box-shadow: 0 2px 0 rgba(10,25,47,.15); }
        .plate span:first-child { background: var(--navy-blue); color: var(--primary-orange); font-size: 8px; font-weight: 800; padding: 0 6px; display: flex; align-items: center; }
        .plate span:last-child { padding: 5px 13px; font-family: 'Courier New', monospace; font-weight: 900; font-size: 15px; letter-spacing: 2px; color: var(--navy-blue); text-transform: uppercase; }
        .code-chip { font-family: 'Courier New', monospace; font-weight: 800; font-size: 12px; color: var(--navy-blue); background: var(--soft-blue-bg); border: 1px dashed #BFDBFE; padding: 5px 11px; border-radius: 8px; }
        .vt-chip { display: inline-flex; align-items: center; gap: 7px; font-size: 12px; font-weight: 700; background: #FFF1E6; padding: 5px 12px; border-radius: 20px; } .vt-chip i { color: var(--primary-orange); }
        .st-pill { margin-left: auto; display: inline-flex; align-items: center; gap: 7px; padding: 6px 14px; border-radius: 20px; font-size: 11.5px; font-weight: 800; }
        .st-pill .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
        .st-in { background: #FEE2E2; color: #DC2626; } .st-in .dot { animation: nodePulse 1.5s infinite; } .st-out { background: #DCFCE7; color: #16A34A; }
        .route { display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 18px; }
        .stop small { display: block; font-size: 10px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; color: var(--text-muted); }
        .stop b { display: block; font-size: 20px; font-weight: 900; color: #0284C7; margin: 2px 0; }
        .stop span { font-size: 12px; font-weight: 700; color: var(--text-muted); }
        .stop.end { text-align: right; } .stop.end b { color: #16A34A; } .stop.end.alert b { color: #DC2626; font-size: 16px; }
        .track { position: relative; height: 34px; }
        .track::before { content: ""; position: absolute; left: 0; right: 0; top: 50%; border-top: 3px dashed #CBD5E1; }
        .track .runner { position: absolute; top: 50%; left: 0; transform: translateY(-50%); font-size: 18px; color: var(--primary-orange); animation: drive 3.4s ease-in-out infinite; }
        .tl-item.inside .runner { animation: driveStop 2.2s ease-out infinite; color: #DC2626; }
        @keyframes drive { 0% { left: 0; opacity: 0; } 12% { opacity: 1; } 88% { opacity: 1; } 100% { left: calc(100% - 22px); opacity: 0; } }
        @keyframes driveStop { 0% { left: 0; } 100% { left: calc(100% - 22px); } }
        .track .dur { position: absolute; left: 50%; top: -14px; transform: translateX(-50%); background: var(--white); padding: 2px 12px; border-radius: 20px; font-size: 11.5px; font-weight: 800; color: var(--navy-blue); border: 1px solid var(--border-color); white-space: nowrap; z-index: 2; }
        .empty-res { text-align: center; padding: 60px 20px; background: var(--white); border: 2px dashed var(--border-color); border-radius: 22px; color: var(--text-muted); font-weight: 700; }
        .empty-res i { font-size: 50px; color: var(--primary-orange); display: block; margin-bottom: 12px; animation: floaty 3s ease-in-out infinite; }

        /* Print sheet (only visible when printing) */
        .print-sheet { display: none; }
        @media print {
            @page { size: A4; margin: 12mm; }
            html, body { background: #fff !important; } body::before { display: none; }
            .dashboard-sidebar, .mobile-topbar, .sidebar-overlay, .sidebar-close-btn, .cid-hero, .console, .res-wrap, .toast { display: none !important; }
            .admin-dashboard-layout, .dashboard-workspace { display: block !important; padding: 0 !important; }
            .print-sheet { display: block !important; color: #0F172A; font-size: 10.5px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .ps-band { background: #0A192F; color: #fff; padding: 14px 18px; border-bottom: 5px solid #FF6B00; display: flex; justify-content: space-between; align-items: center; border-radius: 8px; }
            .ps-band h1 { font-size: 16px; margin: 0; } .ps-band h1 span { color: #FF6B00; } .ps-band p { margin: 3px 0 0; font-size: 9px; letter-spacing: 1px; text-transform: uppercase; color: #94A3B8; }
            .ps-band .cid { text-align: right; font-size: 9px; color: #CBD5E1; } .ps-band .cid b { display: block; color: #FF9E00; font-family: 'Courier New', monospace; font-size: 11px; }
            .ps-meta { display: flex; gap: 8px; margin: 12px 0; } .ps-meta div { flex: 1; border: 1px solid #CBD5E1; border-radius: 6px; padding: 7px 9px; background: #F8FAFC; }
            .ps-meta small { display: block; font-size: 7.5px; font-weight: 800; letter-spacing: .8px; text-transform: uppercase; color: #64748B; } .ps-meta strong { font-size: 9.5px; }
            .ps-table { width: 100%; border-collapse: collapse; }
            .ps-table th { background: #0A192F; color: #fff; font-size: 8px; text-transform: uppercase; letter-spacing: .5px; padding: 7px 6px; text-align: left; }
            .ps-table td { border-bottom: 1px solid #E2E8F0; padding: 6px; font-size: 9px; }
            .ps-table tr { page-break-inside: avoid; } .ps-table tr:nth-child(even) td { background: #F8FAFC; } .ps-table thead { display: table-header-group; }
            .ps-in { color: #DC2626; font-weight: 800; } .ps-out { color: #15803D; font-weight: 700; }
            .ps-sign { display: flex; justify-content: space-between; gap: 30px; margin-top: 40px; page-break-inside: avoid; }
            .ps-sign div { flex: 1; text-align: center; font-size: 9px; font-weight: 700; color: #475569; } .ps-sign span { display: block; border-top: 1px solid #0F172A; margin-top: 28px; padding-top: 4px; }
            .ps-foot { margin-top: 14px; font-size: 8px; color: #94A3B8; text-align: center; }
        }

        /* ---- Mobile-only elements (hidden on desktop) ---- */
        .mobile-topbar, .sidebar-overlay, .sidebar-close-btn { display: none; }

        /* =====================================================================
           RESPONSIVE LAYER  (tablet + mobile)  -  desktop look is untouched
           (the print sheet keeps its own rules inside @media print above)
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
            .res-wrap { scroll-margin-top: 84px; } /* keeps results clear of the sticky top bar */

            /* kept from the original 992px rule */
            .radar { opacity: .2; right: -50px; }
            .hero-clock { position: relative; top: auto; right: auto; margin-bottom: 14px; display: inline-flex; }
            .timeline { padding-left: 54px; } .tl-node { left: -54px; width: 38px; height: 38px; } .timeline::before { left: 17px; }
            .plate-big .no { font-size: 18px; min-width: 120px; padding: 8px 14px; }

            .cid-hero { padding: 28px 28px; border-radius: 24px; min-height: 0; }
            .console { padding: 22px; border-radius: 22px; }
            .dt-row { grid-template-columns: 1fr 1fr; }
            .dt-row .btn-trace { grid-column: 1 / -1; }
        }

        /* ---------- Large phones / small tablets (<= 768px) ---------- */
        @media (max-width: 768px) {
            .hero-copy h1 { font-size: 30px; }
            .hero-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
            .hs { min-width: 0; }

            .con-top { flex-direction: column; align-items: stretch; }
            .con-seg { width: 100%; }
            .con-seg button { flex: 1; justify-content: center; padding: 11px 8px; }

            .case-head { flex-direction: column; align-items: stretch; }
            .btn-print { width: 100%; justify-content: center; }
        }

        /* ---------- Phones (<= 600px) ---------- */
        @media (max-width: 600px) {
            .dashboard-workspace { padding: 12px 12px 24px; gap: 14px; }
            .mobile-topbar { top: 8px; padding: 9px 12px; }

            .cid-hero { padding: 20px 18px; border-radius: 22px; }
            .restricted { font-size: 10px; letter-spacing: 1.2px; }
            .hero-copy h1 { font-size: 27px; margin: 14px 0 6px; }
            .hero-copy p { font-size: 12.5px; }
            .hero-stats { margin-top: 18px; gap: 8px; }
            .hs { padding: 10px 14px; }
            .hs b { font-size: 20px; }

            .console { padding: 18px; border-radius: 20px; }
            .con-title { font-size: 14px; }
            .con-seg button { font-size: 12px; gap: 6px; padding: 11px 6px; }
            .con-input { flex: 1 1 100%; min-width: 0; }
            .con-input input { padding: 15px 16px 15px 44px; letter-spacing: 1px; }
            .btn-trace { flex: 1 1 100%; min-height: 52px; }
            .dt-row { grid-template-columns: 1fr; }
            .dt-group input { font-size: 16px; min-width: 0; max-width: 100%; min-height: 50px; } /* stops iOS zoom-on-focus */
            .plate-big { max-width: 100%; }
            .plate-big .no { font-size: 17px; min-width: 0; padding: 8px 12px; letter-spacing: 2px; }
            .quick { gap: 6px; }
            .quick span { width: 100%; margin-bottom: 2px; }
            .quick button { padding: 9px 14px; }

            .case-head h2 { font-size: 18px; }
            .sum-row { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
            .sum { flex-direction: column; align-items: flex-start; gap: 10px; padding: 14px; }
            .sum b { font-size: 19px; }
            .flt button { flex: 1 1 auto; padding: 9px 12px; font-size: 12px; }

            /* Timeline */
            .timeline { padding-left: 44px; gap: 16px; }
            .timeline::before { left: 14px; }
            .tl-node { left: -44px; width: 32px; height: 32px; font-size: 14px; border-width: 3px; top: 16px; }
            .tl-card { padding: 16px 14px 16px 18px; border-radius: 16px; }
            .tl-top { gap: 8px; margin-bottom: 14px; }
            .plate span:last-child { font-size: 14px; padding: 5px 10px; }
            .st-pill { margin-left: 0; }

            /* Route: Entry | Exit side by side, journey line underneath */
            .route { grid-template-columns: 1fr 1fr; gap: 14px 10px; }
            .route .stop:first-child { grid-column: 1; grid-row: 1; }
            .route .stop.end { grid-column: 2; grid-row: 1; text-align: right; }
            .route .track { grid-column: 1 / -1; grid-row: 2; height: 40px; }
            .stop b { font-size: 17px; }
            .stop.end.alert b { font-size: 13px; }
        }

        /* ---------- Very small phones (<= 380px) ---------- */
        @media (max-width: 380px) {
            .mobile-brand p { display: none; }
            .hero-copy h1 { font-size: 24px; }
            .con-seg button i { display: none; }
            .stop b { font-size: 15px; }
        }

        /* Touch screens: no sticky hover effects */
        @media (hover: none) {
            .sum:hover { transform: none; box-shadow: 0 6px 22px rgba(10,25,47,.05); }
            .tl-card:hover { transform: none; box-shadow: 0 8px 26px rgba(10,25,47,.06); }
            .btn-trace:hover, .btn-print:hover, .quick button:hover { transform: none; }
            .btn-trace:hover::after { animation: none; }
        }

        @media (prefers-reduced-motion: reduce) {
            .dashboard-sidebar, .sidebar-overlay { transition: none; }
        }
    </style>
<?php include __DIR__ . "/includes/pwa.php"; ?></head>
<body>

<div class="admin-dashboard-layout">

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- DASHBOARD SIDEBAR -->
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

            <div class="menu-category-label">Gate Terminals</div>
            <ul class="sidebar-menu-list">
                <li><a href="index.php" class="sidebar-menu-link"><i class="fa-solid fa-ticket"></i> Entry Gate</a></li>
                <li><a href="3D parking.php" class="sidebar-menu-link"><i class="fa-solid fa-cubes"></i> Parking Visualizer</a></li>
                <li><a href="exit.php" class="sidebar-menu-link"><i class="fa-solid fa-right-from-bracket"></i> Exit Gate</a></li>
                <li><a href="vip_request.php" class="sidebar-menu-link"><i class="fa-solid fa-crown"></i> VIP Pass Request</a></li>
            </ul>

            <div class="menu-category-label">Admin Controls</div>
            <ul class="sidebar-menu-list">
                <li><a href="admin_dashboard.php" class="sidebar-menu-link"><i class="fa-solid fa-chart-pie"></i> Dashboard</a></li>
                <li><a href="admin_security_users.php" class="sidebar-menu-link"><i class="fa-solid fa-user-shield"></i> Security Staff</a></li>
                <li><a href="admin_vip_requests.php" class="sidebar-menu-link"><i class="fa-solid fa-crown"></i> VIP Approvals</a></li>
                <li><a href="admin_reports.php" class="sidebar-menu-link"><i class="fa-solid fa-chart-column"></i> Revenue Reports</a></li>
                <li><a href="vehicle_investigation.php" class="sidebar-menu-link active"><i class="fa-solid fa-shield-halved"></i> Security Trace</a></li>
            </ul>
        </div>

        <div class="sidebar-bottom-wrapper">
            <div class="sidebar-user-card">
                <div class="sidebar-user-avatar"><i class="fa-solid fa-user-shield"></i></div>
                <div>
                    <h4 style="font-size:12px; font-weight:800;"><?php echo htmlspecialchars($display_name); ?></h4>
                    <p style="font-size:10px; color:#FFB380;">Master Admin</p>
                </div>
            </div>
            <a href="?action=logout" class="btn-sidebar-logout" onclick="return confirm('Logout වෙනවද?');">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </aside>

    <!-- WORKSPACE -->
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
        <header class="cid-hero rise">
            <span class="scan-line"></span>
            <div class="hero-clock">
                <i class="fa-regular fa-clock" style="color:var(--primary-orange);"></i>
                <div><div class="clock-time" id="liveClock">00:00:00 AM</div><div class="clock-date" id="liveDate">Loading...</div></div>
            </div>
            <div class="radar"><span class="blip" style="left:22%;top:30%;animation-delay:.4s"></span><span class="blip" style="left:68%;top:24%;animation-delay:1.7s"></span><span class="blip" style="left:58%;top:70%;animation-delay:2.6s"></span><span class="blip" style="left:30%;top:64%;animation-delay:3.1s"></span></div>
            <div class="hero-copy">
                <div class="restricted"><i class="fa-solid fa-lock"></i> CID &middot; Restricted Access</div>
                <h1>Vehicle <span>Trace Console</span></h1>
                <p>Ambalangoda MPCS security &amp; investigation portal &mdash; track any vehicle's history or see everything that was inside during a time window.</p>
                <div class="hero-stats">
                    <div class="hs"><b class="count" data-to="<?= (int)$count_parked ?>">0</b><small>Parked Now</small></div>
                    <div class="hs"><b class="count" data-to="<?= (int)$count_completed ?>">0</b><small>Exited</small></div>
                    <div class="hs"><b class="count" data-to="<?= (int)$count_today ?>">0</b><small>Today Entries</small></div>
                    <div class="hs"><b class="count" data-to="<?= (int)$count_total ?>">0</b><small>Total Logs</small></div>
                </div>
            </div>
        </header>

        <!-- Console -->
        <section class="console rise" style="animation-delay:.1s;">
            <div class="con-top">
                <div class="con-title"><i class="fa-solid fa-terminal"></i> Execute Security Query</div>
                <div class="con-seg" id="modeSeg">
                    <span class="glider"></span>
                    <button type="button" data-m="vehicle" class="<?php echo ($search_mode === 'vehicle') ? 'on' : ''; ?>"><i class="fa-solid fa-car"></i> Single Vehicle</button>
                    <button type="button" data-m="time_range" class="<?php echo ($search_mode === 'time_range') ? 'on' : ''; ?>"><i class="fa-solid fa-clock-rotate-left"></i> Time Window</button>
                </div>
            </div>

            <!-- FORM 1 -->
            <form method="GET" action="vehicle_investigation.php" id="form_vehicle" class="con-form <?php echo ($search_mode === 'vehicle') ? 'on' : ''; ?>">
                <input type="hidden" name="search_mode" value="vehicle">
                <div class="prompt">&gt; ENTER TARGET VEHICLE NUMBER</div>
                <div class="con-row">
                    <div class="con-input">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" name="vehicle_number" id="plateInput" autocomplete="off" placeholder="e.g. CAD-1234 or WP CAA-5566" value="<?php echo htmlspecialchars($search_vehicle); ?>" required>
                    </div>
                    <button type="submit" name="search_vehicle_btn" class="btn-trace"><i class="fa-solid fa-satellite-dish"></i> Trace Vehicle</button>
                </div>
                <div class="plate-preview">
                    <small>Live plate preview</small>
                    <div class="plate-big" id="plateBig"><span class="side">LK</span><span class="no ghost" id="plateText">CAD-1234</span></div>
                </div>
            </form>

            <!-- FORM 2 -->
            <form method="GET" action="vehicle_investigation.php" id="form_time_range" class="con-form <?php echo ($search_mode === 'time_range') ? 'on' : ''; ?>">
                <input type="hidden" name="search_mode" value="time_range">
                <div class="prompt">&gt; SET INVESTIGATION TIME WINDOW</div>
                <div class="dt-row">
                    <div class="dt-group"><label>From (Start Window)</label><input type="datetime-local" name="start_time" id="startTime" value="<?php echo htmlspecialchars($start_time); ?>" required></div>
                    <div class="dt-group"><label>To (End Window)</label><input type="datetime-local" name="end_time" id="endTime" value="<?php echo htmlspecialchars($end_time); ?>" required></div>
                    <button type="submit" name="search_time_btn" class="btn-trace"><i class="fa-solid fa-satellite-dish"></i> Trace Vehicles</button>
                </div>
                <div class="quick">
                    <span>Quick range</span>
                    <button type="button" data-q="1h">Last 1 hour</button>
                    <button type="button" data-q="6h">Last 6 hours</button>
                    <button type="button" data-q="today">Today</button>
                    <button type="button" data-q="24h">Last 24 hours</button>
                    <button type="button" data-q="7d">Last 7 days</button>
                </div>
            </form>
        </section>

        <!-- Results -->
        <?php if ($results !== null): ?>
        <section class="res-wrap" id="results">
            <div class="case-head">
                <div>
                    <span class="case-tag"><i class="fa-solid fa-folder-open"></i> CASE FILE &middot; <?php echo $case_id; ?></span>
                    <h2>
                        <?php if ($search_mode === 'vehicle'): ?>
                            Investigation Logs for <span style="color: var(--primary-orange);"><?php echo htmlspecialchars($search_vehicle); ?></span>
                        <?php else: ?>
                            Vehicles present between <span style="color:#0284C7;"><?php echo date('M d, h:i A', strtotime($start_time)); ?></span> &rarr; <span style="color:#0284C7;"><?php echo date('M d, h:i A', strtotime($end_time)); ?></span>
                        <?php endif; ?>
                    </h2>
                    <p>Identified <strong style="color: var(--text-dark);"><?php echo $total_entries; ?></strong> record(s) in system archive.</p>
                </div>
                <?php if ($total_entries > 0): ?>
                    <button type="button" onclick="window.print()" class="btn-print"><i class="fa-solid fa-print"></i> Print Security Report</button>
                <?php endif; ?>
            </div>

            <?php if ($total_entries > 0): ?>
                <div class="sum-row">
                    <div class="sum" style="--k:#FF6B00;"><div class="sum-ico"><i class="fa-solid fa-folder-tree"></i></div><div><small>Records Found</small><b class="count" data-to="<?= $total_entries ?>">0</b></div></div>
                    <div class="sum" style="--k:#DC2626;"><div class="sum-ico"><i class="fa-solid fa-triangle-exclamation"></i></div><div><small>Still Inside</small><b class="count" data-to="<?= $inside_cnt ?>">0</b></div></div>
                    <div class="sum" style="--k:#16A34A;"><div class="sum-ico"><i class="fa-solid fa-right-from-bracket"></i></div><div><small>Exited</small><b class="count" data-to="<?= $exited_cnt ?>">0</b></div></div>
                    <?php if ($search_mode === 'time_range'): ?>
                        <div class="sum" style="--k:#2563EB;"><div class="sum-ico"><i class="fa-solid fa-car-on"></i></div><div><small>Unique Vehicles</small><b class="count" data-to="<?= $unique_plates ?>">0</b></div></div>
                    <?php else: ?>
                        <div class="sum" style="--k:#2563EB;"><div class="sum-ico"><i class="fa-solid fa-hourglass-half"></i></div><div><small>Avg Stay</small><b><?php echo number_format($avg_dur, 1); ?> hrs</b></div></div>
                    <?php endif; ?>
                </div>

                <div class="flt" id="resFilter">
                    <button type="button" class="on" data-f="all">All (<?= $total_entries ?>)</button>
                    <button type="button" data-f="inside">Still Inside (<?= $inside_cnt ?>)</button>
                    <button type="button" data-f="done">Exited (<?= $exited_cnt ?>)</button>
                </div>

                <div class="timeline" id="timeline">
                    <?php foreach ($rows as $ri => $row):
                        $is_in = (empty($row['exit_time']) || $row['status'] === 'PARKED');
                    ?>
                        <div class="tl-item <?= $is_in ? 'inside' : 'done' ?>" data-k="<?= $is_in ? 'inside' : 'done' ?>" style="animation-delay: <?= min($ri, 12) * 0.07 ?>s;">
                            <div class="tl-node"><i class="fa-solid <?= trace_icon($row['type_name']) ?>"></i></div>
                            <div class="tl-card">
                                <div class="tl-top">
                                    <span class="plate"><span>LK</span><span><?php echo htmlspecialchars($row['vehicle_number']); ?></span></span>
                                    <span class="code-chip">#<?php echo htmlspecialchars($row['ticket_code']); ?></span>
                                    <span class="vt-chip"><i class="fa-solid <?= trace_icon($row['type_name']) ?>"></i><?php echo htmlspecialchars($row['type_name'] ?? 'General'); ?></span>
                                    <span class="st-pill <?= $is_in ? 'st-in' : 'st-out' ?>"><i class="dot"></i><?php echo htmlspecialchars($row['status']); ?></span>
                                </div>
                                <div class="route">
                                    <div class="stop"><small><i class="fa-solid fa-arrow-right-to-bracket"></i> Entry (IN)</small><b><?php echo date('h:i A', strtotime($row['entry_time'])); ?></b><span><?php echo date('D, M d Y', strtotime($row['entry_time'])); ?></span></div>
                                    <div class="track"><span class="dur"><i class="fa-regular fa-clock"></i> <?php echo $row['duration_hours'] ? htmlspecialchars($row['duration_hours']) . ' Hours' : 'Active'; ?></span><i class="fa-solid fa-car-side runner"></i></div>
                                    <?php if ($row['exit_time']): ?>
                                        <div class="stop end"><small>Exit (OUT) <i class="fa-solid fa-arrow-right-from-bracket"></i></small><b><?php echo date('h:i A', strtotime($row['exit_time'])); ?></b><span><?php echo date('D, M d Y', strtotime($row['exit_time'])); ?></span></div>
                                    <?php else: ?>
                                        <div class="stop end alert"><small>Exit (OUT)</small><b>STILL INSIDE PARK</b><span>No exit recorded</span></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-res"><i class="fa-solid fa-folder-open"></i>No records found for the specified search parameters.</div>
            <?php endif; ?>
        </section>

        <!-- Print-only official sheet -->
        <?php if ($total_entries > 0): ?>
        <section class="print-sheet">
            <div class="ps-band">
                <div><h1>PARK<span>SMART</span> &middot; Ambalangoda MPCS Ltd</h1><p>CID Security Investigation Report</p></div>
                <div class="cid">Case No<b><?php echo $case_id; ?></b></div>
            </div>
            <div class="ps-meta">
                <div style="flex:2;"><small>Query Scope</small><strong><?php echo htmlspecialchars($scope_text); ?></strong></div>
                <div><small>Records</small><strong><?php echo $total_entries; ?> (<?php echo $inside_cnt; ?> inside)</strong></div>
                <div><small>Generated</small><strong><?php echo date('Y-m-d h:i A'); ?></strong></div>
                <div><small>By</small><strong><?php echo htmlspecialchars($display_name); ?></strong></div>
            </div>
            <table class="ps-table">
                <thead><tr><th>Ticket</th><th>Vehicle No</th><th>Type</th><th>Entry (IN)</th><th>Exit (OUT)</th><th>Duration</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>#<?php echo htmlspecialchars($row['ticket_code']); ?></td>
                        <td><strong><?php echo htmlspecialchars($row['vehicle_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['type_name'] ?? 'General'); ?></td>
                        <td><?php echo date('Y-m-d h:i A', strtotime($row['entry_time'])); ?></td>
                        <td><?php echo $row['exit_time'] ? '<span class="ps-out">' . date('Y-m-d h:i A', strtotime($row['exit_time'])) . '</span>' : '<span class="ps-in">STILL INSIDE</span>'; ?></td>
                        <td><?php echo $row['duration_hours'] ? htmlspecialchars($row['duration_hours']) . ' Hrs' : 'Active'; ?></td>
                        <td><?php echo htmlspecialchars($row['status']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="ps-sign"><div><span>Investigating Officer</span></div><div><span>Checked By</span></div><div><span>Authorized By</span></div></div>
            <div class="ps-foot">Confidential &mdash; for official investigation use only &middot; <?php echo $case_id; ?></div>
        </section>
        <?php endif; ?>
        <?php endif; ?>

    </main>
</div>

<script>
// ---------- Live clock ----------
function updateLiveClock() {
    const now = new Date();
    document.getElementById('liveClock').textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
    document.getElementById('liveDate').textContent = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
}
updateLiveClock();
setInterval(updateLiveClock, 1000);

// ---------- Count-up ----------
document.querySelectorAll('.count').forEach(function (el) {
    var to = parseInt(el.dataset.to, 10) || 0, t0 = performance.now(), dur = 1200;
    (function step(t) {
        var p = Math.min((t - t0) / dur, 1);
        el.textContent = Math.round(to * (1 - Math.pow(1 - p, 3))).toLocaleString('en-US');
        if (p < 1) requestAnimationFrame(step);
    })(t0);
});

// ---------- Mode switch (keeps original switchTab name) ----------
function moveGlider() {
    var seg = document.getElementById('modeSeg'), on = seg.querySelector('button.on'), g = seg.querySelector('.glider');
    g.style.left = on.offsetLeft + 'px'; g.style.width = on.offsetWidth + 'px';
}
function switchTab(mode) {
    document.getElementById('form_vehicle').classList.toggle('on', mode === 'vehicle');
    document.getElementById('form_time_range').classList.toggle('on', mode === 'time_range');
    document.querySelectorAll('#modeSeg button').forEach(function (b) { b.classList.toggle('on', b.dataset.m === mode); });
    moveGlider();
}
document.querySelectorAll('#modeSeg button').forEach(function (b) { b.addEventListener('click', function () { switchTab(b.dataset.m); }); });
moveGlider(); window.addEventListener('load', moveGlider); window.addEventListener('resize', moveGlider);

// ---------- Live plate preview ----------
var plateInput = document.getElementById('plateInput'), plateText = document.getElementById('plateText'), plateBig = document.getElementById('plateBig');
function updatePlate() {
    var v = plateInput.value.trim().toUpperCase();
    plateText.textContent = v || 'CAD-1234';
    plateText.classList.toggle('ghost', !v);
    plateBig.classList.add('bump'); setTimeout(function () { plateBig.classList.remove('bump'); }, 120);
}
plateInput.addEventListener('input', updatePlate);
if (plateInput.value.trim()) { plateText.textContent = plateInput.value.trim().toUpperCase(); plateText.classList.remove('ghost'); }

// ---------- Quick time ranges ----------
function toLocalInput(d) { d = new Date(d.getTime() - d.getTimezoneOffset() * 60000); return d.toISOString().slice(0, 16); }
document.querySelectorAll('.quick button').forEach(function (b) {
    b.addEventListener('click', function () {
        var end = new Date(), start = new Date(), q = b.dataset.q;
        if (q === '1h') start.setHours(end.getHours() - 1);
        else if (q === '6h') start.setHours(end.getHours() - 6);
        else if (q === '24h') start.setHours(end.getHours() - 24);
        else if (q === '7d') start.setDate(end.getDate() - 7);
        else if (q === 'today') start.setHours(0, 0, 0, 0);
        document.getElementById('startTime').value = toLocalInput(start);
        document.getElementById('endTime').value = toLocalInput(end);
    });
});

// ---------- Result filter + scroll ----------
document.querySelectorAll('#resFilter button').forEach(function (b) {
    b.addEventListener('click', function () {
        document.querySelectorAll('#resFilter button').forEach(function (x) { x.classList.toggle('on', x === b); });
        var f = b.dataset.f, n = 0;
        document.querySelectorAll('#timeline .tl-item').forEach(function (it) {
            var ok = (f === 'all' || it.dataset.k === f);
            it.classList.toggle('hide', !ok);
            if (ok) { it.style.animation = 'none'; void it.offsetWidth; it.style.animation = 'tlIn .5s ease both'; it.style.animationDelay = (n++ * 0.05) + 's'; }
        });
    });
});
var resBox = document.getElementById('results');
if (resBox) { setTimeout(function () { resBox.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 500); }
</script>

<script>
// Mobile / tablet sidebar drawer
(function () {
    var sidebar = document.getElementById('dashboardSidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var toggle  = document.getElementById('sidebarToggle');
    var closeBt = document.getElementById('sidebarClose');

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
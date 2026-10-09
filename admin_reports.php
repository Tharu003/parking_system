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

// Export to CSV Handler (Only Completed/Paid Transactions)
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=paid_parking_revenue_report_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, array('Ticket Code', 'Vehicle Number', 'Vehicle Type', 'Entry Time', 'Exit Time', 'Duration (Hrs)', 'Total Fee (LKR)', 'Status', 'VIP Status'));
    
    $rows = $conn->query("SELECT t.ticket_code, t.vehicle_number, vt.type_name, t.entry_time, t.exit_time, t.duration_hours, t.total_fee, t.status, t.is_vip 
                          FROM tickets t 
                          LEFT JOIN vehicle_types vt ON t.vehicle_type_id = vt.id 
                          WHERE t.status = 'COMPLETED' 
                          ORDER BY t.exit_time DESC");
    while ($row = $rows->fetch_assoc()) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit();
}

// Filter inputs for Reports
$period_type    = isset($_GET['period_type']) ? $_GET['period_type'] : 'daily';
if (!in_array($period_type, ['daily','weekly','monthly','yearly'])) { $period_type = 'daily'; }
$filter_date    = isset($_GET['report_date']) ? $_GET['report_date'] : date('Y-m-d');
$filter_week    = isset($_GET['report_week']) ? $_GET['report_week'] : date('Y-\WW');
$filter_month   = isset($_GET['report_month']) ? $_GET['report_month'] : date('Y-m');
$filter_year    = isset($_GET['report_year']) ? $_GET['report_year'] : date('Y');
$search_vehicle = isset($_GET['search_vehicle']) ? trim($_GET['search_vehicle']) : '';

// IMPORTANT: Filter ONLY paid/completed transactions
$where_clauses = array("t.status = 'COMPLETED'");
$param_types   = "";
$params        = array();

// Time Period Logic
if ($period_type == 'daily') {
    $where_clauses[] = "DATE(t.exit_time) = ?";
    $param_types .= "s";
    $params[] = $filter_date;
    $report_label = "Daily Paid Revenue (" . $filter_date . ")";
} elseif ($period_type == 'weekly') {
    $parts = explode('-W', $filter_week);
    $year = $parts[0] ?? date('Y');
    $week = $parts[1] ?? date('W');
    $where_clauses[] = "YEAR(t.exit_time) = ? AND WEEK(t.exit_time, 1) = ?";
    $param_types .= "ii";
    $params[] = (int)$year;
    $params[] = (int)$week;
    $report_label = "Weekly Paid Revenue (Year: " . $year . ", Week: " . $week . ")";
} elseif ($period_type == 'monthly') {
    $where_clauses[] = "DATE_FORMAT(t.exit_time, '%Y-%m') = ?";
    $param_types .= "s";
    $params[] = $filter_month;
    $report_label = "Monthly Paid Revenue (" . $filter_month . ")";
} elseif ($period_type == 'yearly') {
    $where_clauses[] = "YEAR(t.exit_time) = ?";
    $param_types .= "s";
    $params[] = $filter_year;
    $report_label = "Yearly Paid Revenue (" . $filter_year . ")";
}

// Additional Vehicle Number Filter Logic
if (!empty($search_vehicle)) {
    $where_clauses[] = "t.vehicle_number LIKE ?";
    $param_types .= "s";
    $params[] = "%" . $search_vehicle . "%";
    $report_label .= " | Vehicle: " . strtoupper($search_vehicle);
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// 1. Overall Paid Revenue & Vehicle Count
$rev_sql = "SELECT SUM(t.total_fee) as rev, COUNT(t.id) as cnt FROM tickets t $where_sql";
$stmt = $conn->prepare($rev_sql);
if (!empty($param_types)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$filtered_data = $stmt->get_result()->fetch_assoc();

// VIP activity logic
$vip_where = [];
$vip_params = [];
$vip_types = "";
if ($period_type == 'daily') {
    $vip_where[] = "DATE(v.exit_time) = ?";
    $vip_types .= "s"; $vip_params[] = $filter_date;
} elseif ($period_type == 'weekly') {
    $vip_where[] = "YEAR(v.exit_time) = ? AND WEEK(v.exit_time,1) = ?";
    $vip_types .= "ii"; $vip_params[] = (int)$year; $vip_params[] = (int)$week;
} elseif ($period_type == 'monthly') {
    $vip_where[] = "DATE_FORMAT(v.exit_time,'%Y-%m') = ?";
    $vip_types .= "s"; $vip_params[] = $filter_month;
} elseif ($period_type == 'yearly') {
    $vip_where[] = "YEAR(v.exit_time) = ?";
    $vip_types .= "s"; $vip_params[] = $filter_year;
}
if (!empty($search_vehicle)) {
    $vip_where[] = "v.vehicle_number LIKE ?";
    $vip_types .= "s"; $vip_params[] = "%".$search_vehicle."%";
}
$vip_where_sql = $vip_where ? "WHERE ".implode(" AND ",$vip_where) : "";
$vip_report_sql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(v.duration_minutes),0) AS mins
                   FROM vip_parking_sessions v $vip_where_sql " . ($vip_where_sql ? " AND " : " WHERE ") . "v.status='COMPLETED'";
$vip_stmt = $conn->prepare($vip_report_sql);
if (!empty($vip_types)) $vip_stmt->bind_param($vip_types,...$vip_params);
$vip_stmt->execute();
$vip_summary = $vip_stmt->get_result()->fetch_assoc();
$vip_stmt->close();

$vip_details_sql = "SELECT v.* FROM vip_parking_sessions v
                    $vip_where_sql " . ($vip_where_sql ? " AND " : " WHERE ") . "v.status='COMPLETED'
                    ORDER BY v.exit_time DESC LIMIT 100";
$vip_details_stmt = $conn->prepare($vip_details_sql);
if (!empty($vip_types)) $vip_details_stmt->bind_param($vip_types,...$vip_params);
$vip_details_stmt->execute();
$vip_details_res = $vip_details_stmt->get_result();

$vip_data = array();
if ($vip_details_res && $vip_details_res->num_rows > 0) {
    while($vrow = $vip_details_res->fetch_assoc()) {
        $vip_data[] = $vrow;
    }
}

// 2. Vehicle Category Breakdown
$vehicle_breakdown_sql = "SELECT vt.type_name, COUNT(t.id) as total_vehicles, SUM(t.total_fee) as total_income 
                          FROM tickets t 
                          LEFT JOIN vehicle_types vt ON t.vehicle_type_id = vt.id 
                          $where_sql 
                          GROUP BY t.vehicle_type_id";
$v_stmt = $conn->prepare($vehicle_breakdown_sql);
if (!empty($param_types)) {
    $v_stmt->bind_param($param_types, ...$params);
}
$v_stmt->execute();
$vehicle_breakdown_res = $v_stmt->get_result();

// 3. Detailed Paid Vehicle Activity Log
$details_sql = "SELECT t.*, vt.type_name 
                FROM tickets t 
                LEFT JOIN vehicle_types vt ON t.vehicle_type_id = vt.id 
                $where_sql 
                ORDER BY t.exit_time DESC LIMIT 200";
$d_stmt = $conn->prepare($details_sql);
if (!empty($param_types)) {
    $d_stmt->bind_param($param_types, ...$params);
}
$d_stmt->execute();
$details_res = $d_stmt->get_result();

// ---- Data prep for UI + PDF (fetched once so both can use it) ----
$total_v_cnt = 0; $total_v_inc = 0; $vehicle_data = array();
if ($vehicle_breakdown_res) {
    while ($vr_ = $vehicle_breakdown_res->fetch_assoc()) {
        $vehicle_data[] = $vr_;
        $total_v_cnt += $vr_['total_vehicles'];
        $total_v_inc += $vr_['total_income'];
    }
}
usort($vehicle_data, function ($a, $b) { return $b['total_income'] <=> $a['total_income']; });
$detail_rows = array();
if ($details_res) { while ($dr_ = $details_res->fetch_assoc()) { $detail_rows[] = $dr_; } }

$rev_total = (float)($filtered_data['rev'] ?? 0);
$veh_total = (int)($filtered_data['cnt'] ?? 0);
$avg_fee   = $veh_total > 0 ? $rev_total / $veh_total : 0;
$vip_cnt   = (int)($vip_summary['cnt'] ?? 0);
$vip_mins  = (int)($vip_summary['mins'] ?? 0);
$top_cat   = !empty($vehicle_data) ? ($vehicle_data[0]['type_name'] ?? 'General') : '-';
$cat_palette = ['#FF6B00', '#0A192F', '#FBBF24', '#16A34A', '#2563EB', '#9333EA', '#DC2626'];

function cat_icon($name) {
    $n = strtolower($name ?? '');
    if (preg_match('/bike|motor|cycle/', $n)) return 'fa-motorcycle';
    if (strpos($n, 'bus') !== false)          return 'fa-bus';
    if (preg_match('/lorry|truck/', $n))      return 'fa-truck';
    if (strpos($n, 'van') !== false)          return 'fa-van-shuttle';
    if (preg_match('/three|tuk|wheel/', $n))  return 'fa-taxi';
    return 'fa-car-side';
}

$ring_c = 339.292; $ring_off = 0; $ring_segs = [];
foreach ($vehicle_data as $ci => $cv) {
    if ($total_v_inc > 0 && $cv['total_income'] > 0) {
        $len = ($cv['total_income'] / $total_v_inc) * $ring_c;
        $ring_segs[] = ['len' => round($len, 2), 'off' => round($ring_off, 2), 'col' => $cat_palette[$ci % count($cat_palette)]];
        $ring_off += $len;
    }
}
$report_id = 'RPT-' . date('Ymd-His');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue Reports - Ambalangoda MPCS</title>
    
    <!-- FontAwesome & Google Fonts -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <!-- jQuery & html2pdf -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

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

        /* ================= REVENUE REPORTS - FINANCE STUDIO THEME ================= */
        body::before { content: ""; position: fixed; inset: 0; z-index: -1; pointer-events: none;
            background: radial-gradient(700px 420px at 100% 0%, rgba(255,107,0,.10), transparent 70%), radial-gradient(520px 380px at 0% 100%, rgba(10,25,47,.07), transparent 70%); }
        @keyframes riseIn { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: none; } }
        @keyframes floaty { 50% { transform: translateY(-9px); } }
        .rise { animation: riseIn .7s cubic-bezier(.2,.9,.3,1.1) both; }

        /* Hero */
        .rev-hero { position: relative; overflow: hidden; border-radius: 28px; padding: 34px 40px 74px; color: var(--white);
            background: linear-gradient(125deg, var(--navy-blue), var(--navy-blue-soft) 55%, #22375F); box-shadow: 0 20px 50px rgba(10,25,47,.28);
            display: flex; justify-content: space-between; gap: 26px; flex-wrap: wrap; }
        .rev-hero::before { content: ""; position: absolute; right: -80px; top: -80px; width: 320px; height: 320px; border-radius: 50%; background: radial-gradient(circle, rgba(255,107,0,.5), transparent 68%); }
        .rev-hero .wave { position: absolute; left: 0; bottom: 0; width: 200%; height: 70px; opacity: .55; animation: waveMove 9s linear infinite; }
        .rev-hero .wave.w2 { opacity: .25; height: 58px; animation-duration: 14s; animation-direction: reverse; }
        @keyframes waveMove { to { transform: translateX(-50%); } }
        .hero-left { position: relative; z-index: 2; }
        .eyebrow { display: inline-flex; gap: 8px; align-items: center; font-size: 11px; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase; color: var(--primary-orange); background: rgba(255,107,0,.13); padding: 6px 13px; border-radius: 20px; }
        .hero-label { margin-top: 20px; font-size: 12px; font-weight: 800; letter-spacing: 1.2px; text-transform: uppercase; color: #94A3B8; }
        .hero-amount { font-size: 54px; font-weight: 900; line-height: 1.05; margin: 6px 0 10px; letter-spacing: -1px; }
        .hero-amount small { font-size: 20px; font-weight: 800; color: var(--primary-orange); margin-right: 8px; letter-spacing: 0; }
        .scope-chip { display: inline-flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 700; color: #CBD5E1; background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12); padding: 8px 14px; border-radius: 12px; max-width: 100%; }
        .scope-chip i { color: var(--primary-orange); }
        .hero-right { position: relative; z-index: 2; display: flex; flex-direction: column; align-items: flex-end; gap: 14px; }
        .hero-clock { background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12); padding: 8px 14px; border-radius: 12px; display: flex; align-items: center; gap: 10px; }
        .export-row { display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end; margin-top: 6px; }
        .btn-pdf, .btn-csv { border: none; cursor: pointer; text-decoration: none; padding: 13px 22px; border-radius: 14px; font-size: 13px; font-weight: 800; display: inline-flex; align-items: center; gap: 9px; transition: all .3s ease; position: relative; overflow: hidden; }
        .btn-pdf { background: linear-gradient(135deg, var(--primary-orange), #FF8800); color: var(--white); box-shadow: 0 10px 24px rgba(255,107,0,.4); }
        .btn-csv { background: rgba(255,255,255,.1); color: var(--white); border: 1px solid rgba(255,255,255,.2); }
        .btn-pdf:hover, .btn-csv:hover { transform: translateY(-3px); }
        .btn-pdf:hover { box-shadow: 0 16px 30px rgba(255,107,0,.55); } .btn-csv:hover { background: rgba(255,255,255,.2); }
        .btn-pdf.busy { pointer-events: none; opacity: .85; }
        .btn-pdf.busy i { animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Filter studio */
        .filter-studio { background: var(--white); border-radius: 20px; padding: 20px 24px; border: 1px solid var(--border-color); box-shadow: 0 8px 30px rgba(10,25,47,.06); margin-top: -46px; position: relative; z-index: 5; margin-left: 24px; margin-right: 24px; }
        .filter-studio form { display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap; }
        .fs-group { display: flex; flex-direction: column; gap: 7px; }
        .fs-group > label { font-size: 10.5px; font-weight: 800; letter-spacing: .9px; text-transform: uppercase; color: var(--text-muted); }
        .fs-group input { padding: 11px 16px; border-radius: 12px; border: 2px solid var(--border-color); background: var(--bg-light); font-size: 13px; font-weight: 700; color: var(--navy-blue); outline: none; transition: all .25s ease; }
        .fs-group input:focus { border-color: var(--primary-orange); background: var(--white); box-shadow: 0 0 0 4px rgba(255,107,0,.12); }
        .seg { display: flex; background: var(--bg-light); border: 2px solid var(--border-color); border-radius: 14px; padding: 4px; position: relative; }
        .seg button { position: relative; z-index: 2; border: none; background: transparent; padding: 8px 16px; border-radius: 10px; font-size: 12.5px; font-weight: 800; color: var(--text-muted); cursor: pointer; transition: color .3s ease; }
        .seg button.on { color: var(--white); }
        .seg .glider { position: absolute; z-index: 1; top: 4px; bottom: 4px; left: 4px; border-radius: 10px; background: linear-gradient(135deg, var(--navy-blue), #22375F); box-shadow: 0 6px 14px rgba(10,25,47,.3); transition: all .4s cubic-bezier(.2,.9,.3,1.2); }
        .btn-gen { border: none; cursor: pointer; padding: 13px 24px; border-radius: 14px; background: var(--primary-orange); color: var(--white); font-size: 13px; font-weight: 800; display: inline-flex; align-items: center; gap: 8px; transition: all .3s ease; }
        .btn-gen:hover { background: var(--orange-hover); transform: translateY(-2px); box-shadow: 0 10px 22px rgba(255,107,0,.35); }

        /* KPI cards */
        .kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 18px; }
        .kpi { position: relative; overflow: hidden; background: var(--white); border-radius: 20px; padding: 22px; border: 1px solid var(--border-color); box-shadow: 0 8px 26px rgba(10,25,47,.06); transition: all .3s ease; animation: riseIn .7s cubic-bezier(.2,.9,.3,1.1) both; }
        .kpi:nth-child(2){animation-delay:.08s} .kpi:nth-child(3){animation-delay:.16s} .kpi:nth-child(4){animation-delay:.24s}
        .kpi::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 5px; background: var(--k); }
        .kpi::after { content: ""; position: absolute; right: -30px; bottom: -30px; width: 110px; height: 110px; border-radius: 50%; background: var(--k); opacity: .08; transition: all .4s ease; }
        .kpi:hover { transform: translateY(-6px); box-shadow: 0 18px 40px rgba(10,25,47,.13); }
        .kpi:hover::after { transform: scale(1.6); opacity: .14; }
        .kpi-ico { width: 44px; height: 44px; border-radius: 14px; background: color-mix(in srgb, var(--k) 14%, white); color: var(--k); display: flex; align-items: center; justify-content: center; font-size: 18px; margin-bottom: 14px; }
        .kpi small { display: block; font-size: 10.5px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; color: var(--text-muted); }
        .kpi b { display: block; margin-top: 5px; font-size: 25px; font-weight: 900; color: var(--navy-blue); line-height: 1.1; word-break: break-word; }
        .kpi b em { font-style: normal; font-size: 12px; font-weight: 800; color: var(--text-muted); margin-left: 4px; }

        /* Category analytics */
        .analytics-grid { display: grid; grid-template-columns: 1.7fr 1fr; gap: 20px; }
        .panel { background: var(--white); border-radius: 22px; padding: 26px; border: 1px solid var(--border-color); box-shadow: 0 8px 26px rgba(10,25,47,.06); }
        .panel-head { display: flex; align-items: center; gap: 12px; margin-bottom: 22px; }
        .panel-head .ph-ico { width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(135deg, var(--primary-orange), #FF9E00); color: var(--white); display: flex; align-items: center; justify-content: center; }
        .panel-head h3 { font-size: 16px; font-weight: 800; color: var(--navy-blue); } .panel-head p { font-size: 11.5px; color: var(--text-muted); font-weight: 600; }
        .cat-list { display: flex; flex-direction: column; gap: 18px; }
        .cat-row { display: grid; grid-template-columns: 44px 1fr auto; gap: 14px; align-items: center; }
        .cat-ico { width: 44px; height: 44px; border-radius: 14px; background: color-mix(in srgb, var(--cc) 14%, white); color: var(--cc); display: flex; align-items: center; justify-content: center; font-size: 17px; transition: transform .3s ease; }
        .cat-row:hover .cat-ico { transform: rotate(-10deg) scale(1.12); }
        .cat-top { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; margin-bottom: 8px; }
        .cat-top b { font-size: 13.5px; font-weight: 800; color: var(--navy-blue); } .cat-top span { font-size: 11.5px; font-weight: 700; color: var(--text-muted); }
        .bar-track { height: 11px; border-radius: 10px; background: var(--bg-light); overflow: hidden; }
        .bar-fill { height: 100%; width: var(--w); border-radius: 10px; background: linear-gradient(90deg, var(--cc), color-mix(in srgb, var(--cc) 55%, white)); animation: grow 1.3s cubic-bezier(.2,.9,.3,1) both .3s; }
        @keyframes grow { from { width: 0; } }
        .cat-amt { font-size: 14px; font-weight: 900; color: var(--navy-blue); text-align: right; white-space: nowrap; }
        .cat-amt small { display: block; font-size: 10.5px; font-weight: 800; color: var(--text-muted); }
        .donut-box { display: flex; flex-direction: column; align-items: center; gap: 20px; }
        .donut { position: relative; width: 200px; height: 200px; }
        .donut svg { width: 100%; height: 100%; transform: rotate(-90deg); }
        .donut circle { fill: none; stroke-width: 16; }
        .donut .trk { stroke: var(--bg-light); }
        .donut .seg2 { stroke-dasharray: var(--len) 339.292; stroke-dashoffset: calc(var(--off) * -1); animation: draw 1.4s cubic-bezier(.3,.8,.3,1) both .2s; }
        @keyframes draw { from { stroke-dasharray: 0 339.292; } }
        .donut-mid { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; }
        .donut-mid small { font-size: 10px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; color: var(--text-muted); }
        .donut-mid b { font-size: 18px; font-weight: 900; color: var(--navy-blue); margin-top: 3px; }
        .dl { display: flex; flex-wrap: wrap; gap: 8px 14px; justify-content: center; }
        .dl span { display: inline-flex; align-items: center; gap: 7px; font-size: 11.5px; font-weight: 700; color: var(--text-dark); }
        .dl i { width: 10px; height: 10px; border-radius: 3px; background: var(--cc); }
        .empty-note { text-align: center; padding: 40px 10px; color: var(--text-muted); font-weight: 700; }
        .empty-note i { font-size: 40px; color: var(--primary-orange); display: block; margin-bottom: 10px; animation: floaty 3s ease-in-out infinite; }

        /* Ledger tabs */
        .ledger-head { display: flex; justify-content: space-between; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; }
        .tabs { display: flex; gap: 8px; }
        .tab { border: 2px solid var(--border-color); background: var(--white); cursor: pointer; padding: 10px 18px; border-radius: 14px; font-size: 12.5px; font-weight: 800; color: var(--text-muted); display: inline-flex; align-items: center; gap: 8px; transition: all .25s ease; }
        .tab em { font-style: normal; font-size: 11px; padding: 2px 8px; border-radius: 10px; background: var(--bg-light); color: var(--navy-blue); }
        .tab:hover { border-color: var(--primary-orange); color: var(--primary-orange); transform: translateY(-2px); }
        .tab.on { background: var(--navy-blue); border-color: var(--navy-blue); color: var(--white); box-shadow: 0 8px 18px rgba(10,25,47,.25); }
        .tab.on em { background: var(--primary-orange); color: var(--white); }
        .lsearch { position: relative; } .lsearch i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: var(--primary-orange); font-size: 13px; }
        .lsearch input { width: 280px; max-width: 100%; padding: 11px 16px 11px 38px; border-radius: 30px; border: 2px solid var(--border-color); background: var(--bg-light); font-size: 13px; font-weight: 600; outline: none; transition: all .25s ease; }
        .lsearch input:focus { border-color: var(--primary-orange); background: var(--white); box-shadow: 0 0 0 4px rgba(255,107,0,.12); }
        .tab-panel { display: none; } .tab-panel.on { display: block; animation: riseIn .5s ease both; }
        .ledger-scroll { max-height: 560px; overflow: auto; border-radius: 16px; border: 1px solid var(--border-color); }
        .ledger { width: 100%; border-collapse: collapse; }
        .ledger thead th { position: sticky; top: 0; z-index: 2; background: var(--navy-blue); color: #CBD5E1; padding: 14px 18px; text-align: left; font-size: 10.5px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; white-space: nowrap; }
        .ledger tbody tr { transition: all .2s ease; animation: rowIn .45s ease both; }
        @keyframes rowIn { from { opacity: 0; transform: translateX(-16px); } }
        .ledger tbody td { padding: 13px 18px; border-bottom: 1px dotted #CBD5E1; font-size: 13px; font-weight: 600; white-space: nowrap; }
        .ledger tbody tr:hover { background: #FFF7F0; box-shadow: inset 4px 0 0 var(--primary-orange); }
        .code-chip { font-family: 'Courier New', monospace; font-weight: 800; font-size: 12px; color: var(--navy-blue); background: var(--soft-blue-bg); border: 1px dashed #BFDBFE; padding: 4px 10px; border-radius: 8px; }
        .plate { display: inline-flex; background: #FFFBEB; border: 2px solid var(--navy-blue); border-radius: 7px; overflow: hidden; }
        .plate span:first-child { background: var(--navy-blue); color: var(--primary-orange); font-size: 7.5px; font-weight: 800; padding: 0 5px; display: flex; align-items: center; }
        .plate span:last-child { padding: 3px 10px; font-family: 'Courier New', monospace; font-weight: 800; font-size: 12.5px; letter-spacing: 1.2px; color: var(--navy-blue); text-transform: uppercase; }
        .t-in { color: #0284C7; font-size: 12px; } .t-out { color: #15803D; font-size: 12px; font-weight: 700; }
        .fee-pill { display: inline-block; background: #DCFCE7; color: #15803D; font-weight: 900; padding: 5px 12px; border-radius: 20px; font-size: 12.5px; }
        .free-pill { display: inline-block; background: #FEF3C7; color: #B45309; font-weight: 900; padding: 5px 12px; border-radius: 20px; font-size: 12px; }
        .slot-tag { color: #B45309; font-weight: 800; }
        .ledger-foot { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-top: 14px; font-size: 12.5px; font-weight: 700; color: var(--text-muted); }
        .ledger-foot b { color: var(--navy-blue); }

        /* Toast */
        .toast { position: fixed; bottom: 28px; right: 28px; z-index: 3000; background: var(--navy-blue); color: var(--white); padding: 14px 20px; border-radius: 14px; font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 10px; box-shadow: 0 16px 40px rgba(10,25,47,.35); transform: translateY(30px); opacity: 0; pointer-events: none; transition: all .4s cubic-bezier(.2,.9,.3,1.2); }
        .toast.show { transform: none; opacity: 1; } .toast i { color: #4ADE80; }

        @media (max-width: 1100px) { .analytics-grid { grid-template-columns: 1fr; } }
        /* ---- Mobile-only elements (hidden on desktop) ---- */
        .mobile-topbar, .sidebar-overlay, .sidebar-close-btn { display: none; }

        /* =====================================================================
           RESPONSIVE LAYER  (tablet + mobile)  -  desktop look is untouched
           (the hidden PDF template is intentionally not touched by any rule below)
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

            /* kept from the original 992px rule */
            .hero-right { align-items: flex-start; } .export-row { justify-content: flex-start; }
            .filter-studio { margin-left: 8px; margin-right: 8px; } .hero-amount { font-size: 38px; }

            .rev-hero { padding: 28px 28px 74px; border-radius: 24px; }
        }

        /* ---------- Large phones / small tablets (<= 768px) ---------- */
        @media (max-width: 768px) {
            .hero-left { min-width: 0; max-width: 100%; }
            .hero-right { width: 100%; }
            .export-row { width: 100%; }
            .btn-pdf, .btn-csv { flex: 1 1 140px; justify-content: center; padding: 13px 14px; }

            /* Filter studio: stacked, full-width controls */
            .filter-studio { padding: 18px 16px; }
            .filter-studio form { flex-direction: column; align-items: stretch; gap: 14px; }
            .fs-group input { width: 100%; min-height: 46px; font-size: 16px; } /* stops iOS zoom-on-focus */
            .seg { width: 100%; }
            .seg button { flex: 1; padding: 10px 4px; font-size: 12px; }
            .btn-gen { justify-content: center; padding: 14px 24px; }

            .panel { padding: 20px; border-radius: 20px; }
            .cat-top { flex-wrap: wrap; gap: 2px 10px; }

            /* Ledger header */
            .tabs { width: 100%; flex-wrap: wrap; }
            .tab { flex: 1 1 auto; justify-content: center; }
            .lsearch { width: 100%; }
            .lsearch input { width: 100%; font-size: 16px; padding: 13px 16px 13px 38px; }

            /* Ledger tables become cards */
            .ledger-scroll { max-height: 70vh; border: none; border-radius: 0; padding: 2px; }
            .ledger, .ledger tbody { display: block; width: 100%; }
            .ledger thead { display: none; }
            .ledger tbody { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 12px; }
            .ledger tbody tr { display: block; padding: 6px 16px; background: var(--white); border: 1px solid var(--border-color); border-radius: 14px; box-shadow: 0 4px 14px rgba(10,25,47,.05); }
            .ledger tbody tr:has(td[colspan]) { grid-column: 1 / -1; box-shadow: none; }
            .ledger tbody tr:hover { background: var(--white); box-shadow: 0 4px 14px rgba(10,25,47,.05); }
            .ledger tbody td { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 10px 0; text-align: right; white-space: normal; }
            .ledger tbody td:last-child { border-bottom: none; }
            .ledger tbody td[data-label]::before { content: attr(data-label); flex-shrink: 0; text-align: left; font-size: 10.5px; font-weight: 800; letter-spacing: .8px; text-transform: uppercase; color: var(--text-muted); }
            .ledger tbody td[colspan] { display: block; text-align: center; }
            .ledger-foot { flex-direction: column; gap: 4px; }
        }

        /* ---------- Phones (<= 600px) ---------- */
        @media (max-width: 600px) {
            .dashboard-workspace { padding: 12px 12px 24px; gap: 14px; }
            .mobile-topbar { top: 8px; padding: 9px 12px; }

            .rev-hero { padding: 22px 18px 74px; border-radius: 22px; gap: 18px; }
            .rev-hero::before { width: 240px; height: 240px; right: -90px; top: -90px; }
            .eyebrow { font-size: 10px; letter-spacing: 1.1px; max-width: 100%; }
            .hero-label { margin-top: 16px; }
            .hero-amount { font-size: 32px; letter-spacing: -.5px; }
            .hero-amount small { font-size: 16px; margin-right: 6px; }
            .scope-chip { align-items: flex-start; line-height: 1.4; font-size: 11.5px; }
            .scope-chip i { margin-top: 2px; }

            .filter-studio { margin-top: -44px; margin-left: 4px; margin-right: 4px; border-radius: 18px; }

            .kpi-row { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
            .kpi { padding: 16px 14px 16px 18px; border-radius: 16px; }
            .kpi-ico { width: 38px; height: 38px; font-size: 16px; margin-bottom: 10px; border-radius: 12px; }
            .kpi b { font-size: 18px; }
            .kpi b em { display: block; margin: 2px 0 0; }

            .panel { padding: 16px; border-radius: 18px; }
            .panel-head { margin-bottom: 18px; }
            .cat-row { grid-template-columns: 38px minmax(0, 1fr) auto; gap: 10px; }
            .cat-ico { width: 38px; height: 38px; font-size: 15px; border-radius: 12px; }
            .cat-amt { font-size: 13px; }
            .donut { width: 180px; height: 180px; }
            .donut-mid b { font-size: 15px; }

            .tab { font-size: 12px; padding: 10px 12px; }

            .toast { left: 12px; right: 12px; bottom: 16px; }
        }

        /* ---------- Very small phones (<= 380px) ---------- */
        @media (max-width: 380px) {
            .mobile-brand p { display: none; }
            .hero-amount { font-size: 28px; }
            .kpi b { font-size: 16px; }
            .ledger tbody { grid-template-columns: 1fr; }
        }

        /* Touch screens: no sticky hover effects */
        @media (hover: none) {
            .kpi:hover { transform: none; box-shadow: 0 8px 26px rgba(10,25,47,.06); }
            .kpi:hover::after { transform: none; opacity: .08; }
            .tab:hover, .btn-pdf:hover, .btn-csv:hover, .btn-gen:hover { transform: none; }
            .cat-row:hover .cat-ico { transform: none; }
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
                <li><a href="admin_reports.php" class="sidebar-menu-link active"><i class="fa-solid fa-chart-column"></i> Revenue Reports</a></li>
             <li><a href="vehicle_investigation.php" class="sidebar-menu-link"><i class="fa-solid fa-shield-halved"></i> CID & Trace</a></li>
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
             
            </div>
        </div>

        <!-- Hero -->
        <header class="rev-hero rise">
            <div class="hero-left">
                <div class="eyebrow"><i class="fa-solid fa-file-invoice-dollar"></i> Revenue &amp; Financial Settlement Hub</div>
                <div class="hero-label">Total Paid Revenue</div>
                <div class="hero-amount"><small>LKR</small><span class="count" data-to="<?= $rev_total ?>" data-dec="2">0.00</span></div>
                <div class="scope-chip"><i class="fa-solid fa-calendar-check"></i><?php echo htmlspecialchars($report_label); ?></div>
            </div>
            <div class="hero-right">
                <div class="hero-clock">
                    <i class="fa-regular fa-clock" style="color:var(--primary-orange);"></i>
                    <div><div class="clock-time" id="liveClock">00:00:00 AM</div><div class="clock-date" id="liveDate">Loading...</div></div>
                </div>
                <div class="export-row">
                    <button type="button" id="btnPdf" class="btn-pdf" onclick="downloadCleanPDF()"><i class="fa-solid fa-file-pdf"></i> <span>Download PDF</span></button>
                    <a href="admin_reports.php?export=csv" class="btn-csv"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
                </div>
            </div>
            <svg class="wave" viewBox="0 0 1200 70" preserveAspectRatio="none"><path fill="#F8FAFC" d="M0 40 Q150 0 300 40 T600 40 T900 40 T1200 40 V70 H0Z"/></svg>
            <svg class="wave w2" viewBox="0 0 1200 70" preserveAspectRatio="none"><path fill="#FF6B00" d="M0 30 Q150 70 300 30 T600 30 T900 30 T1200 30 V70 H0Z"/></svg>
        </header>

        <!-- Filter studio -->
        <div class="filter-studio rise" style="animation-delay:.1s;">
            <form method="GET" action="admin_reports.php" id="filterForm">
                <input type="hidden" name="period_type" id="period_type" value="<?php echo htmlspecialchars($period_type); ?>">
                <div class="fs-group">
                    <label>Period</label>
                    <div class="seg" id="periodSeg">
                        <span class="glider"></span>
                        <button type="button" data-p="daily">Daily</button>
                        <button type="button" data-p="weekly">Weekly</button>
                        <button type="button" data-p="monthly">Monthly</button>
                        <button type="button" data-p="yearly">Yearly</button>
                    </div>
                </div>
                <div class="fs-group" id="group_daily"><label>Select Date</label><input type="date" name="report_date" value="<?php echo htmlspecialchars($filter_date); ?>"></div>
                <div class="fs-group" id="group_weekly"><label>Select Week</label><input type="week" name="report_week" value="<?php echo htmlspecialchars($filter_week); ?>"></div>
                <div class="fs-group" id="group_monthly"><label>Select Month</label><input type="month" name="report_month" value="<?php echo htmlspecialchars($filter_month); ?>"></div>
                <div class="fs-group" id="group_yearly"><label>Select Year</label><input type="number" name="report_year" min="2020" max="2030" value="<?php echo htmlspecialchars($filter_year); ?>"></div>
                <div class="fs-group"><label>Vehicle Number (Optional)</label><input type="text" name="search_vehicle" placeholder="e.g. CAB-1234" value="<?php echo htmlspecialchars($search_vehicle); ?>"></div>
                <button type="submit" class="btn-gen"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Report</button>
            </form>
        </div>

        <!-- KPIs -->
        <div class="kpi-row">
            <div class="kpi" style="--k:#16A34A;"><div class="kpi-ico"><i class="fa-solid fa-wallet"></i></div><small>Paid Vehicles</small><b><span class="count" data-to="<?= $veh_total ?>">0</span><em>vehicles</em></b></div>
            <div class="kpi" style="--k:#FF6B00;"><div class="kpi-ico"><i class="fa-solid fa-coins"></i></div><small>Average Fee</small><b>LKR <span class="count" data-to="<?= $avg_fee ?>" data-dec="2">0.00</span></b></div>
            <div class="kpi" style="--k:#D97706;"><div class="kpi-ico"><i class="fa-solid fa-crown"></i></div><small>VIP Exits</small><b><span class="count" data-to="<?= $vip_cnt ?>">0</span><em><?= $vip_mins ?> mins</em></b></div>
            <div class="kpi" style="--k:#2563EB;"><div class="kpi-ico"><i class="fa-solid fa-trophy"></i></div><small>Top Category</small><b><?php echo htmlspecialchars($top_cat); ?></b></div>
        </div>

        <!-- Category analytics -->
        <div class="analytics-grid">
            <section class="panel rise">
                <div class="panel-head"><div class="ph-ico"><i class="fa-solid fa-chart-simple"></i></div><div><h3>Revenue by Vehicle Category</h3><p>Share of collected revenue in this period</p></div></div>
                <?php if (!empty($vehicle_data)): ?>
                    <div class="cat-list">
                        <?php foreach ($vehicle_data as $ci => $cv):
                            $share = $total_v_inc > 0 ? ($cv['total_income'] / $total_v_inc) * 100 : 0;
                            $col = $cat_palette[$ci % count($cat_palette)];
                        ?>
                            <div class="cat-row" style="--cc:<?= $col ?>;">
                                <div class="cat-ico"><i class="fa-solid <?= cat_icon($cv['type_name']) ?>"></i></div>
                                <div>
                                    <div class="cat-top"><b><?php echo htmlspecialchars($cv['type_name'] ?? 'General Vehicle'); ?></b><span><?php echo number_format($cv['total_vehicles']); ?> vehicles &middot; <?php echo number_format($share, 1); ?>%</span></div>
                                    <div class="bar-track"><div class="bar-fill" style="--w:<?= round($share, 1) ?>%;"></div></div>
                                </div>
                                <div class="cat-amt"><small>LKR</small><?php echo number_format($cv['total_income'], 2); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-note"><i class="fa-solid fa-chart-column"></i>No settled records found for the selected scope.</div>
                <?php endif; ?>
            </section>

            <section class="panel rise" style="animation-delay:.12s;">
                <div class="panel-head"><div class="ph-ico"><i class="fa-solid fa-chart-pie"></i></div><div><h3>Revenue Share</h3><p>Grand total split</p></div></div>
                <div class="donut-box">
                    <div class="donut">
                        <svg viewBox="0 0 130 130">
                            <circle class="trk" cx="65" cy="65" r="54"></circle>
                            <?php foreach ($ring_segs as $sg): ?>
                                <circle class="seg2" cx="65" cy="65" r="54" style="stroke:<?= $sg['col'] ?>; --len:<?= $sg['len'] ?>; --off:<?= $sg['off'] ?>;"></circle>
                            <?php endforeach; ?>
                        </svg>
                        <div class="donut-mid"><small>Grand Total</small><b>LKR <?php echo number_format($total_v_inc, 2); ?></b><small style="margin-top:4px;"><?php echo number_format($total_v_cnt); ?> vehicles</small></div>
                    </div>
                    <div class="dl">
                        <?php foreach ($vehicle_data as $ci => $cv): ?>
                            <span style="--cc:<?= $cat_palette[$ci % count($cat_palette)] ?>;"><i></i><?php echo htmlspecialchars($cv['type_name'] ?? 'General'); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </div>

        <!-- Ledger -->
        <section class="panel rise">
            <div class="ledger-head">
                <div class="tabs">
                    <button type="button" class="tab on" data-t="tx"><i class="fa-solid fa-receipt"></i> Transactions <em><?= count($detail_rows) ?></em></button>
                    <button type="button" class="tab" data-t="vip"><i class="fa-solid fa-crown"></i> VIP Activity <em><?= count($vip_data) ?></em></button>
                </div>
                <div class="lsearch"><i class="fa-solid fa-magnifying-glass"></i><input type="text" id="ledgerSearch" placeholder="Search in this list..."></div>
            </div>

            <div class="tab-panel on" id="panel-tx">
                <div class="ledger-scroll">
                    <table class="ledger">
                        <thead><tr><th>Ticket Code</th><th>Vehicle</th><th>Category</th><th>Entry (IN)</th><th>Exit (OUT)</th><th>Paid Fee</th></tr></thead>
                        <tbody>
                        <?php if (!empty($detail_rows)): foreach ($detail_rows as $ri => $drow): ?>
                            <tr style="animation-delay: <?= min($ri, 14) * 0.03 ?>s;">
                                <td data-label="Ticket Code"><span class="code-chip">#<?php echo htmlspecialchars($drow['ticket_code']); ?></span></td>
                                <td data-label="Vehicle"><span class="plate"><span>LK</span><span><?php echo htmlspecialchars($drow['vehicle_number']); ?></span></span></td>
                                <td data-label="Category"><?php echo htmlspecialchars($drow['type_name'] ?? 'General'); ?></td>
                                <td class="t-in" data-label="Entry (IN)"><?php echo date('Y-m-d h:i A', strtotime($drow['entry_time'])); ?></td>
                                <td class="t-out" data-label="Exit (OUT)"><?php echo date('Y-m-d h:i A', strtotime($drow['exit_time'])); ?></td>
                                <td data-label="Paid Fee"><span class="fee-pill">LKR <?php echo number_format($drow['total_fee'], 2); ?></span></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="6" style="text-align:center; color:#64748B; padding:34px;">No completed payment transactions found for this scope.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="ledger-foot"><span>Latest <b><?= count($detail_rows) ?></b> settled transactions (max 200)</span><span>Total: <b>LKR <?php echo number_format($total_v_inc, 2); ?></b></span></div>
            </div>

            <div class="tab-panel" id="panel-vip">
                <div class="ledger-scroll">
                    <table class="ledger">
                        <thead><tr><th>Vehicle</th><th>VIP Slot</th><th>Entry</th><th>Exit</th><th>Duration</th><th>Fee</th></tr></thead>
                        <tbody>
                        <?php if (!empty($vip_data)): foreach ($vip_data as $vr): ?>
                            <tr>
                                <td data-label="Vehicle"><span class="plate"><span>LK</span><span><?php echo htmlspecialchars($vr['vehicle_number']); ?></span></span></td>
                                <td data-label="VIP Slot"><span class="slot-tag"><i class="fa-solid fa-crown"></i> <?php echo htmlspecialchars($vr['slot_label'] ?: 'VIP'); ?></span></td>
                                <td class="t-in" data-label="Entry"><?php echo date('Y-m-d h:i A', strtotime($vr['entry_time'])); ?></td>
                                <td class="t-out" data-label="Exit"><?php echo date('Y-m-d h:i A', strtotime($vr['exit_time'])); ?></td>
                                <td data-label="Duration"><?php echo (int)$vr['duration_minutes']; ?> mins</td>
                                <td data-label="Fee"><span class="free-pill">FREE</span></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="6" style="text-align:center; color:#64748B; padding:34px;">No completed VIP activity recorded.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="ledger-foot"><span><b><?= $vip_cnt ?></b> VIP exits</span><span>Total VIP time: <b><?= $vip_mins ?> mins</b></span></div>
            </div>
        </section>

    </main>
</div>

<div class="toast" id="toast"><i class="fa-solid fa-circle-check"></i> <span id="toastText">PDF downloaded</span></div>

<!-- ============ PDF TEMPLATE (kept in a hidden host; html2pdf clones it) ============ -->
<style>
    .pdf-doc { font-family: 'Plus Jakarta Sans', Helvetica, Arial, sans-serif; color: #0F172A; background: #fff; font-size: 10.5px; line-height: 1.45; }
    .pdf-doc * { box-sizing: border-box; }
    .pdf-band { background: #0A192F; color: #fff; padding: 16px 20px; border-radius: 10px; border-bottom: 5px solid #FF6B00; display: flex; justify-content: space-between; align-items: center; }
    .pdf-band h1 { font-size: 17px; font-weight: 800; letter-spacing: .5px; margin: 0; }
    .pdf-band h1 span { color: #FF6B00; }
    .pdf-band p { margin: 3px 0 0; font-size: 9.5px; color: #94A3B8; letter-spacing: 1px; text-transform: uppercase; font-weight: 700; }
    .pdf-band .rid { text-align: right; font-size: 9.5px; color: #CBD5E1; } .pdf-band .rid b { display: block; color: #FF9E00; font-size: 11px; font-family: 'Courier New', monospace; }
    .pdf-meta { display: flex; gap: 10px; margin: 12px 0; }
    .pdf-meta div { flex: 1; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 8px 10px; }
    .pdf-meta small { display: block; font-size: 8px; font-weight: 800; letter-spacing: .8px; text-transform: uppercase; color: #64748B; }
    .pdf-meta strong { font-size: 10px; }
    .pdf-kpis { display: flex; gap: 10px; margin-bottom: 16px; }
    .pdf-kpi { flex: 1; border: 1.5px solid #0A192F; border-top: 4px solid var(--k); border-radius: 8px; padding: 10px; text-align: center; }
    .pdf-kpi small { display: block; font-size: 8px; font-weight: 800; letter-spacing: .8px; text-transform: uppercase; color: #475569; }
    .pdf-kpi b { display: block; margin-top: 3px; font-size: 15px; font-weight: 900; color: var(--k); }
    .pdf-h { font-size: 11px; font-weight: 800; color: #0A192F; border-bottom: 2px solid #FF6B00; padding-bottom: 4px; margin: 16px 0 8px; text-transform: uppercase; letter-spacing: .6px; }
    .pdf-doc table { width: 100%; border-collapse: collapse; }
    .pdf-doc th { background: #0A192F; color: #fff; padding: 7px 8px; font-size: 8.5px; text-align: left; text-transform: uppercase; letter-spacing: .5px; }
    .pdf-doc td { padding: 6px 8px; border-bottom: 1px solid #E2E8F0; font-size: 9.5px; }
    .pdf-doc tr:nth-child(even) td { background: #F8FAFC; }
    .pdf-doc .r { text-align: right; } .pdf-doc .c { text-align: center; }
    .pdf-doc .money { font-weight: 800; color: #15803D; }
    .pdf-doc tfoot td { background: #FFF1E6 !important; font-weight: 800; border-top: 2px solid #0A192F; font-size: 10.5px; }
    .pdf-bar { height: 7px; border-radius: 6px; background: #E2E8F0; overflow: hidden; } .pdf-bar i { display: block; height: 100%; background: #FF6B00; }
    .pdf-sign { display: flex; justify-content: space-between; gap: 30px; margin-top: 46px; }
    .pdf-sign div { flex: 1; text-align: center; font-size: 9px; font-weight: 700; color: #475569; }
    .pdf-sign div span { display: block; border-top: 1px solid #0F172A; margin-top: 30px; padding-top: 4px; }
    .pdf-avoid { page-break-inside: avoid; break-inside: avoid; }
</style>

<div style="display:none;">
<div id="pdfReportContainer" class="pdf-doc">

    <div class="pdf-band">
        <div>
            <h1>PARK<span>SMART</span> </h1>
            <p>Official Parking Revenue Report</p>
        </div>
        <div class="rid">Report No<b><?php echo $report_id; ?></b></div>
    </div>

    <div class="pdf-meta">
        <div style="flex:2;"><small>Report Scope</small><strong><?php echo htmlspecialchars($report_label); ?></strong></div>
        <div><small>Generated On</small><strong><?php echo date('Y-m-d h:i A'); ?></strong></div>
        <div><small>Generated By</small><strong><?php echo htmlspecialchars($display_name); ?></strong></div>
    </div>

    <div class="pdf-kpis pdf-avoid">
        <div class="pdf-kpi" style="--k:#16A34A;"><small>Total Revenue</small><b>LKR <?php echo number_format($rev_total, 2); ?></b></div>
        <div class="pdf-kpi" style="--k:#0284C7;"><small>Paid Vehicles</small><b><?php echo number_format($veh_total); ?></b></div>
        <div class="pdf-kpi" style="--k:#FF6B00;"><small>Average Fee</small><b>LKR <?php echo number_format($avg_fee, 2); ?></b></div>
        <div class="pdf-kpi" style="--k:#D97706;"><small>VIP Exits</small><b><?php echo number_format($vip_cnt); ?></b></div>
    </div>

    <div class="pdf-h">1. Revenue Breakdown by Vehicle Category</div>
    <table class="pdf-avoid">
        <thead><tr><th>Category</th><th class="c">Vehicles</th><th style="width:26%;">Share</th><th class="r">Revenue (LKR)</th></tr></thead>
        <tbody>
        <?php if (!empty($vehicle_data)): foreach ($vehicle_data as $cv): $sh = $total_v_inc > 0 ? ($cv['total_income'] / $total_v_inc) * 100 : 0; ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($cv['type_name'] ?? 'General Vehicle'); ?></strong></td>
                <td class="c"><?php echo number_format($cv['total_vehicles']); ?></td>
                <td><div class="pdf-bar"><i style="width:<?= round($sh) ?>%;"></i></div></td>
                <td class="r money"><?php echo number_format($cv['total_income'], 2); ?> <span style="color:#64748B;font-weight:600;">(<?php echo number_format($sh, 1); ?>%)</span></td>
            </tr>
        <?php endforeach; else: ?>
            <tr><td colspan="4" class="c" style="color:#64748B; padding:12px;">No data available for this scope.</td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot><tr><td>GRAND TOTAL</td><td class="c"><?php echo number_format($total_v_cnt); ?></td><td></td><td class="r" style="color:#15803D;">LKR <?php echo number_format($total_v_inc, 2); ?></td></tr></tfoot>
    </table>

    <div class="pdf-h">2. Settled Transactions Log (latest <?= count($detail_rows) ?>)</div>
    <table>
        <thead><tr><th>Ticket</th><th>Vehicle</th><th>Category</th><th>Entry</th><th>Exit</th><th class="r">Fee (LKR)</th></tr></thead>
        <tbody>
        <?php if (!empty($detail_rows)): foreach ($detail_rows as $drow): ?>
            <tr class="pdf-avoid">
                <td>#<?php echo htmlspecialchars($drow['ticket_code']); ?></td>
                <td><strong><?php echo htmlspecialchars($drow['vehicle_number']); ?></strong></td>
                <td><?php echo htmlspecialchars($drow['type_name'] ?? 'General'); ?></td>
                <td><?php echo date('m-d h:i A', strtotime($drow['entry_time'])); ?></td>
                <td><?php echo date('m-d h:i A', strtotime($drow['exit_time'])); ?></td>
                <td class="r money"><?php echo number_format($drow['total_fee'], 2); ?></td>
            </tr>
        <?php endforeach; else: ?>
            <tr><td colspan="6" class="c" style="color:#64748B; padding:12px;">No settled transactions in this scope.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="pdf-h">3. VIP Vehicle Activity</div>
    <table>
        <thead><tr><th>Vehicle</th><th>VIP Slot</th><th>Entry</th><th>Exit</th><th class="r">Duration</th><th class="r">Fee</th></tr></thead>
        <tbody>
        <?php if (!empty($vip_data)): foreach ($vip_data as $vr): ?>
            <tr class="pdf-avoid">
                <td><strong><?php echo htmlspecialchars($vr['vehicle_number']); ?></strong></td>
                <td><?php echo htmlspecialchars($vr['slot_label'] ?: 'VIP'); ?></td>
                <td><?php echo date('m-d h:i A', strtotime($vr['entry_time'])); ?></td>
                <td><?php echo date('m-d h:i A', strtotime($vr['exit_time'])); ?></td>
                <td class="r"><?php echo (int)$vr['duration_minutes']; ?> mins</td>
                <td class="r" style="font-weight:800; color:#B45309;">FREE</td>
            </tr>
        <?php endforeach; else: ?>
            <tr><td colspan="6" class="c" style="color:#64748B; padding:12px;">No completed VIP activity recorded.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="pdf-sign pdf-avoid">
        <div><span>Prepared By</span></div>
        <div><span>Checked By</span></div>
        <div><span>Approved By</span></div>
    </div>
</div>
</div>

<script>
function showToast(msg) {
    var t = document.getElementById('toast');
    document.getElementById('toastText').textContent = msg;
    t.classList.add('show');
    setTimeout(function () { t.classList.remove('show'); }, 3200);
}

// ---------- Live clock ----------
function updateLiveClock() {
    const now = new Date();
    document.getElementById('liveClock').textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
    document.getElementById('liveDate').textContent = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
}
updateLiveClock();
setInterval(updateLiveClock, 1000);

// ---------- Count-up numbers ----------
document.querySelectorAll('.count').forEach(function (el) {
    var to = parseFloat(el.dataset.to) || 0, dec = parseInt(el.dataset.dec || '0', 10), t0 = performance.now(), dur = 1400;
    (function step(t) {
        var p = Math.min((t - t0) / dur, 1), v = to * (1 - Math.pow(1 - p, 3));
        el.textContent = v.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
        if (p < 1) requestAnimationFrame(step);
    })(t0);
});

// ---------- Period selector ----------
function toggleFilterInputs() {
    var type = document.getElementById('period_type').value;
    ['daily', 'weekly', 'monthly', 'yearly'].forEach(function (k) {
        document.getElementById('group_' + k).style.display = (type === k) ? 'flex' : 'none';
    });
    var seg = document.getElementById('periodSeg'), btn = seg.querySelector('button[data-p="' + type + '"]');
    seg.querySelectorAll('button').forEach(function (b) { b.classList.toggle('on', b === btn); });
    var g = seg.querySelector('.glider');
    g.style.left = btn.offsetLeft + 'px'; g.style.width = btn.offsetWidth + 'px';
}
document.querySelectorAll('#periodSeg button').forEach(function (b) {
    b.addEventListener('click', function () { document.getElementById('period_type').value = b.dataset.p; toggleFilterInputs(); });
});
toggleFilterInputs();
window.addEventListener('load', toggleFilterInputs);
window.addEventListener('resize', toggleFilterInputs);

// ---------- Tabs + ledger search ----------
var activeTab = 'tx';
document.querySelectorAll('.tab').forEach(function (tb) {
    tb.addEventListener('click', function () {
        activeTab = tb.dataset.t;
        document.querySelectorAll('.tab').forEach(function (x) { x.classList.toggle('on', x === tb); });
        document.querySelectorAll('.tab-panel').forEach(function (pn) { pn.classList.toggle('on', pn.id === 'panel-' + activeTab); });
        document.getElementById('ledgerSearch').dispatchEvent(new Event('input'));
    });
});
document.getElementById('ledgerSearch').addEventListener('input', function () {
    var q = this.value.toLowerCase().trim();
    document.querySelectorAll('#panel-' + activeTab + ' tbody tr').forEach(function (tr) {
        tr.style.display = tr.textContent.toLowerCase().indexOf(q) === -1 ? 'none' : '';
    });
});

// ---------- PDF export ----------
function downloadCleanPDF() {
    var btn = document.getElementById('btnPdf'), label = btn.querySelector('span'), icon = btn.querySelector('i');
    if (typeof html2pdf === 'undefined') { alert('PDF library could not be loaded. Please check your internet connection and try again.'); return; }
    btn.classList.add('busy'); label.textContent = 'Generating...'; icon.className = 'fa-solid fa-spinner';

    var opt = {
        margin:      [10, 10, 14, 10],
        filename:    'Parking_Revenue_Report_<?php echo date('Y-m-d'); ?>.pdf',
        image:       { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true, scrollX: 0, scrollY: 0, backgroundColor: '#ffffff' },
        jsPDF:       { unit: 'mm', format: 'a4', orientation: 'portrait' },
        pagebreak:   { mode: ['css', 'legacy'], avoid: ['.pdf-avoid', 'tr'] }
    };

    window.scrollTo(0, 0);
    html2pdf().set(opt).from(document.getElementById('pdfReportContainer')).toPdf().get('pdf').then(function (pdf) {
        var n = pdf.internal.getNumberOfPages(), w = pdf.internal.pageSize.getWidth(), h = pdf.internal.pageSize.getHeight();
        for (var i = 1; i <= n; i++) {
            pdf.setPage(i);
            pdf.setDrawColor(255, 107, 0); pdf.setLineWidth(0.5); pdf.line(10, h - 11, w - 10, h - 11);
            pdf.setFontSize(8); pdf.setTextColor(100, 116, 139);
            pdf.text('ParkSmart - Ambalangoda MPCS Ltd | <?php echo $report_id; ?>', 10, h - 6.5);
            pdf.text('Page ' + i + ' of ' + n, w - 10, h - 6.5, { align: 'right' });
        }
    }).save().then(function () {
        showToast('PDF report downloaded successfully');
    }).catch(function (e) {
        console.error(e); alert('Could not generate the PDF. Please try again.');
    }).then(function () {
        btn.classList.remove('busy'); label.textContent = 'Download PDF'; icon.className = 'fa-solid fa-file-pdf';
    });
}
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

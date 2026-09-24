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

// --- HANDLE VOID / CANCEL ACTION DIRECTLY FROM DASHBOARD ---
$action_msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_void_action'])) {
    $ticket_id    = intval($_POST['ticket_id']);
    $action_type  = $_POST['action_type']; // 'APPROVE' or 'REJECT'
    $admin_notes  = $conn->real_escape_string(trim($_POST['admin_notes']));
    $reviewed_by  = $conn->real_escape_string($raw_username);

    // Fetch Ticket Status
    $t_check = $conn->query("SELECT status FROM tickets WHERE id = $ticket_id")->fetch_assoc();
    
    if ($t_check) {
        $current_status = $t_check['status'];
        $new_status = '';

        if ($action_type === 'APPROVE') {
            $new_status = ($current_status === 'VOID_REQUESTED') ? 'VOIDED' : 'CANCELLED';
        } else {
            $new_status = 'REJECTED';
        }

        $update_q = "UPDATE tickets SET 
                        status = '$new_status', 
                        reviewed_by = '$reviewed_by', 
                        reviewed_at = NOW(), 
                        admin_notes = '$admin_notes' 
                    WHERE id = $ticket_id";

        if ($conn->query($update_q)) {
            $action_msg = "<div class='alert-toast success-toast'>
                            <i class='fa-solid fa-circle-check'></i> Ticket request successfully processed as <strong>" . $new_status . "</strong>!
                          </div>";
        }
    }
}

// Dynamic Database Analytics
$ticket_parked   = $conn->query("SELECT COUNT(*) AS total FROM tickets WHERE status = 'PARKED'")->fetch_assoc()['total'] ?? 0;
$vip_parked      = $conn->query("SELECT COUNT(*) AS total FROM vip_parking_sessions WHERE status = 'PARKED'")->fetch_assoc()['total'] ?? 0;
$total_parked    = (int)$ticket_parked + (int)$vip_parked;
$ticket_completed= $conn->query("SELECT COUNT(*) AS total FROM tickets WHERE status = 'COMPLETED'")->fetch_assoc()['total'] ?? 0;
$vip_completed   = $conn->query("SELECT COUNT(*) AS total FROM vip_parking_sessions WHERE status = 'COMPLETED'")->fetch_assoc()['total'] ?? 0;
$total_completed = (int)$ticket_completed + (int)$vip_completed;
$pending_vip     = $conn->query("SELECT COUNT(*) AS total FROM vip_requests WHERE status = 'PENDING'")->fetch_assoc()['total'] ?? 0;
$total_revenue   = $conn->query("SELECT SUM(total_fee) AS total FROM tickets WHERE status = 'COMPLETED'")->fetch_assoc()['total'] ?? 0.00;

// Fetch Pending Void / Cancellation Requests
$pending_void_query = "SELECT t.*, v.type_name 
                       FROM tickets t 
                       LEFT JOIN vehicle_types v ON t.vehicle_type_id = v.id 
                       WHERE t.status IN ('VOID_REQUESTED', 'CANCEL_REQUESTED') 
                       ORDER BY t.requested_at DESC";
$pending_void_res   = $conn->query($pending_void_query);
$pending_void_count = $pending_void_res ? $pending_void_res->num_rows : 0;

// Fetch All Live Tickets for Client-Side DataTable
$tickets_query = "SELECT t.*, v.type_name 
                  FROM tickets t 
                  LEFT JOIN vehicle_types v ON t.vehicle_type_id = v.id 
                  ORDER BY t.id DESC";
$tickets_result = $conn->query($tickets_query);
$tickets_total  = $tickets_result ? $tickets_result->num_rows : 0;

// UI helpers
function vehicle_icon($name) {
    $n = strtolower($name ?? '');
    if (preg_match('/bike|motor|cycle/', $n)) return 'fa-motorcycle';
    if (strpos($n, 'bus') !== false)          return 'fa-bus';
    if (preg_match('/lorry|truck/', $n))      return 'fa-truck';
    if (strpos($n, 'van') !== false)          return 'fa-van-shuttle';
    if (preg_match('/three|tuk|wheel/', $n))  return 'fa-taxi';
    return 'fa-car-side';
}
function status_badge($status) {
    $map = [
        'PARKED'           => ['st-parked',  'Parked'],
        'COMPLETED'        => ['st-done',    'Completed'],
        'VOID_REQUESTED'   => ['st-warn',    'Void Requested'],
        'CANCEL_REQUESTED' => ['st-warn',    'Cancel Requested'],
        'VOIDED'           => ['st-danger',  'Voided'],
        'CANCELLED'        => ['st-danger',  'Cancelled'],
        'REJECTED'         => ['st-muted',   'Rejected'],
    ];
    $m = $map[$status] ?? ['st-muted', ucfirst(strtolower(str_replace('_', ' ', $status))) ];
    return "<span class='status-pill {$m[0]}'><i class='dot'></i>{$m[1]}</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ambalangoda MPCS - Parking Management System</title>
    
    <!-- FontAwesome & Google Fonts -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- jQuery + DataTables -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.11/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.11/js/jquery.dataTables.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>

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
        }

        .mpcs-brand-info h1 {
            font-size: 19px;
            font-weight: 800;
            color: var(--navy-blue);
        }

        .mpcs-brand-info p {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 600;
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

        /* NEW NO-CARD SLEEK UNIFIED ANALYTICS BAR */
        .analytics-strip-container {
            background: var(--white);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 16px 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        }

        .analytics-segment {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 8px 12px;
            border-right: 1px dashed var(--border-color);
        }

        .analytics-segment:last-child {
            border-right: none;
        }

        .segment-icon-pill {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .segment-details span {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .segment-details h3 {
            font-size: 20px;
            font-weight: 800;
            color: var(--navy-blue);
            margin-top: 2px;
            line-height: 1;
        }

        /* Color accents for icon pills */
        .pill-blue { background: #EFF6FF; color: #2563EB; }
        .pill-amber { background: #FEF3C7; color: #D97706; }
        .pill-emerald { background: #DCFCE7; color: #16A34A; }
        .pill-orange { background: #FFF1E6; color: var(--primary-orange); }
        .pill-purple { background: #F3E8FF; color: #9333EA; }

        /* Action Toolbar Row */
        .toolbar-action-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn-toolbar-action {
            background: var(--white);
            border: 1px solid var(--border-color);
            padding: 10px 18px;
            border-radius: 12px;
            text-decoration: none;
            color: var(--navy-blue);
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .btn-toolbar-action:hover {
            border-color: var(--primary-orange);
            color: var(--primary-orange);
            background: #FFFDFB;
        }

        /* DataTables Container */
        .table-container-card {
            background: var(--white);
            border-radius: 16px;
            padding: 22px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        }

        .table-title-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }

        .table-title-bar h3 {
            font-size: 16px;
            font-weight: 800;
            color: var(--navy-blue);
        }

        .badge-tag {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
        }

        .badge-parked-active { background: #E0F2FE; color: #0284C7; }
        .badge-completed-out { background: #DCFCE7; color: #16A34A; }
        .badge-vip-pass { background: #FEF3C7; color: #D97706; }

        /* ---- Mobile-only elements (hidden on desktop) ---- */
        .mobile-topbar, .sidebar-overlay, .sidebar-close-btn { display: none; }

        /* ================= UI POLISH (same colour palette) ================= */
        body {
            background-image: radial-gradient(#E2E8F0 1px, transparent 1px);
            background-size: 22px 22px;
        }
        .sidebar-menu-link:hover { transform: translateX(4px); }
        .sidebar-menu-link.active { box-shadow: 0 8px 20px rgba(255,107,0,0.35); }
        .mpcs-top-header { box-shadow: 0 6px 24px rgba(10,25,47,0.05); }
        .analytics-strip-container { box-shadow: 0 6px 24px rgba(10,25,47,0.05); }
        .analytics-segment { border-radius: 12px; transition: all .25s ease; }
        .analytics-segment:hover { background: var(--bg-light); transform: translateY(-3px); }
        .segment-icon-pill { transition: transform .3s ease; }
        .analytics-segment:hover .segment-icon-pill { transform: rotate(-8deg) scale(1.1); }
        .btn-toolbar-action:hover { transform: translateY(-2px); box-shadow: 0 8px 18px rgba(255,107,0,0.12); }

        /* ---------- Table card ---------- */
        .table-container-card {
            padding: 0;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(10,25,47,0.07);
            position: relative;
        }
        .table-container-card::before {
            content: ""; display: block; height: 4px;
            background: linear-gradient(90deg, var(--primary-orange), #FF9E00, var(--navy-blue));
        }
        .table-title-bar {
            padding: 22px 26px 8px; margin: 0; flex-wrap: wrap; gap: 14px;
        }
        .tt-left { display: flex; align-items: center; gap: 14px; }
        .tt-icon {
            width: 46px; height: 46px; border-radius: 14px;
            background: linear-gradient(135deg, var(--primary-orange), #FF9E00);
            color: var(--white); display: flex; align-items: center; justify-content: center;
            font-size: 19px; box-shadow: 0 8px 18px rgba(255,107,0,0.3);
        }
        .table-title-bar h3 { display: flex; align-items: center; gap: 10px; font-size: 18px; }
        .table-title-bar p { font-size: 12px; color: var(--text-muted); font-weight: 600; margin-top: 3px; }
        .live-pill {
            display: inline-flex; align-items: center; gap: 6px; font-size: 10px; font-weight: 800;
            letter-spacing: .8px; padding: 4px 9px; border-radius: 20px;
            background: #DCFCE7; color: #16A34A;
        }
        .live-dot { width: 7px; height: 7px; border-radius: 50%; background: #16A34A; animation: pulse 1.6s infinite; }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(22,163,74,.6); }
            70% { box-shadow: 0 0 0 8px rgba(22,163,74,0); }
            100% { box-shadow: 0 0 0 0 rgba(22,163,74,0); }
        }

        /* Filter chips */
        .filter-chips { display: flex; gap: 6px; background: var(--bg-light); padding: 5px; border-radius: 14px; border: 1px solid var(--border-color); }
        .chip {
            border: none; background: transparent; cursor: pointer; padding: 8px 16px; border-radius: 10px;
            font-size: 12.5px; font-weight: 700; color: var(--text-muted); display: flex; align-items: center; gap: 7px;
            transition: all .25s ease;
        }
        .chip:hover { color: var(--navy-blue); background: var(--white); }
        .chip.active { background: var(--navy-blue); color: var(--white); box-shadow: 0 6px 14px rgba(10,25,47,.25); }
        .chip.active i { color: var(--primary-orange); }

        .table-responsive { padding: 6px 26px 22px; }

        /* ---------- DataTables overrides ---------- */
        .dataTables_wrapper .dt-top { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 14px 0; }
        .dataTables_wrapper .dataTables_filter { margin: 0; float: none; }
        .dataTables_wrapper .dataTables_filter label { position: relative; display: block; }
        .dataTables_wrapper .dataTables_filter label::before {
            content: "\f002"; font-family: "Font Awesome 6 Free"; font-weight: 900;
            position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
            color: var(--primary-orange); font-size: 13px; pointer-events: none;
        }
        .dataTables_wrapper .dataTables_filter input {
            margin: 0; width: 320px; max-width: 100%; padding: 11px 16px 11px 40px;
            border: 1.5px solid var(--border-color); border-radius: 12px; background: var(--bg-light);
            font-size: 13px; font-weight: 600; color: var(--text-dark); outline: none; transition: all .25s ease;
        }
        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: var(--primary-orange); background: var(--white); box-shadow: 0 0 0 4px rgba(255,107,0,.12);
        }
        .dataTables_wrapper .dataTables_length { float: none; font-size: 12.5px; font-weight: 700; color: var(--text-muted); }
        .dataTables_wrapper .dataTables_length select {
            margin: 0 6px; padding: 8px 12px; border: 1.5px solid var(--border-color); border-radius: 10px;
            background: var(--bg-light); font-weight: 700; color: var(--navy-blue); outline: none; cursor: pointer;
        }

        table#ticketsTable { border-collapse: separate; border-spacing: 0 8px; border: none; margin: 0 !important; }
        table#ticketsTable thead th {
            background: var(--navy-blue); color: #CBD5E1; border: none !important;
            font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px;
            padding: 14px 18px;
        }
        table#ticketsTable thead th:first-child { border-radius: 12px 0 0 12px; }
        table#ticketsTable thead th:last-child  { border-radius: 0 12px 12px 0; }
        table#ticketsTable thead .sorting:before, table#ticketsTable thead .sorting_asc:before, table#ticketsTable thead .sorting_desc:before { color: var(--primary-orange); }
        table#ticketsTable thead .sorting_asc:before, table#ticketsTable thead .sorting_desc:after { opacity: 1; color: var(--primary-orange); }

        table#ticketsTable tbody tr { background: var(--white); transition: all .25s ease; animation: rowIn .4s ease both; }
        table#ticketsTable tbody td {
            padding: 14px 18px; border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color);
            font-size: 13px; font-weight: 600; vertical-align: middle; background: transparent;
        }
        table#ticketsTable tbody td:first-child { border-left: 4px solid transparent; border-radius: 12px 0 0 12px; border-left-color: var(--border-color); border-left-width: 1px; border-left-style: solid; }
        table#ticketsTable tbody td:last-child  { border-right: 1px solid var(--border-color); border-radius: 0 12px 12px 0; }
        table#ticketsTable tbody tr:hover { transform: translateY(-2px) scale(1.003); box-shadow: 0 10px 26px rgba(10,25,47,.10); }
        table#ticketsTable tbody tr:hover td { border-color: rgba(255,107,0,.45); background: #FFFDFB; }
        table#ticketsTable tbody tr:hover td:first-child { border-left: 4px solid var(--primary-orange); }
        table#ticketsTable tbody tr.row-parked td:first-child { border-left: 4px solid #0284C7; }
        table#ticketsTable tbody tr.row-vip td:first-child { border-left: 4px solid #D97706; }
        table#ticketsTable tbody tr.row-parked:hover td:first-child, table#ticketsTable tbody tr.row-vip:hover td:first-child { border-left-color: var(--primary-orange); }
        table.dataTable.display tbody tr.odd > .sorting_1, table.dataTable.display tbody tr.even > .sorting_1 { background: transparent; }
        @keyframes rowIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }

        /* Ticket code */
        .ticket-chip {
            display: inline-flex; align-items: center; gap: 8px; font-family: 'Courier New', monospace;
            font-weight: 800; font-size: 12.5px; color: var(--navy-blue); letter-spacing: .5px;
            background: var(--soft-blue-bg); padding: 7px 12px; border-radius: 10px; border: 1px dashed #BFDBFE;
        }
        .ticket-chip i { color: var(--primary-orange); font-size: 12px; }

        /* Number plate */
        .plate {
            display: inline-flex; align-items: stretch; background: #FFFBEB; color: var(--navy-blue);
            border: 2px solid var(--navy-blue); border-radius: 8px; overflow: hidden;
            font-weight: 800; font-size: 13px; letter-spacing: 1.5px; text-transform: uppercase;
            box-shadow: 0 2px 0 rgba(10,25,47,.15);
        }
        .plate-side {
            background: var(--navy-blue); color: var(--primary-orange); font-size: 8px; letter-spacing: 0;
            padding: 0 6px; display: flex; align-items: center; justify-content: center;
        }
        .plate-no { padding: 5px 12px; font-family: 'Courier New', monospace; }

        /* Vehicle type */
        .vtype { display: inline-flex; align-items: center; gap: 10px; font-weight: 700; color: var(--text-dark); }
        .vtype-ico {
            width: 34px; height: 34px; border-radius: 50%; background: #FFF1E6; color: var(--primary-orange);
            display: flex; align-items: center; justify-content: center; font-size: 14px; transition: all .3s ease;
        }
        tr:hover .vtype-ico { background: var(--primary-orange); color: var(--white); transform: rotate(-10deg) scale(1.08); }

        /* Duration & fee */
        .fee-box { display: flex; flex-direction: column; gap: 3px; }
        .fee-amount { font-size: 14px; font-weight: 800; color: var(--navy-blue); }
        .fee-amount small { font-size: 10px; color: var(--text-muted); font-weight: 700; margin-right: 3px; }
        .fee-dur { font-size: 11.5px; color: var(--text-muted); font-weight: 700; display: flex; align-items: center; gap: 5px; }
        .fee-dur i { color: var(--primary-orange); }
        .parked-now { display: inline-flex; align-items: center; gap: 8px; color: #0284C7; font-weight: 800; font-size: 12.5px; }
        .parked-now .dot-live { width: 8px; height: 8px; border-radius: 50%; background: #0284C7; animation: pulseBlue 1.6s infinite; }
        @keyframes pulseBlue {
            0% { box-shadow: 0 0 0 0 rgba(2,132,199,.55); } 70% { box-shadow: 0 0 0 8px rgba(2,132,199,0); } 100% { box-shadow: 0 0 0 0 rgba(2,132,199,0); }
        }

        /* Pass type */
        .vip-crown {
            display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 20px;
            font-size: 11px; font-weight: 800; color: var(--white);
            background: linear-gradient(135deg, #D97706, #FBBF24); box-shadow: 0 4px 12px rgba(217,119,6,.35);
        }
        .std-pass { color: var(--text-muted); font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }

        /* Status pills */
        .status-pill {
            display: inline-flex; align-items: center; gap: 7px; padding: 6px 13px; border-radius: 20px;
            font-size: 11.5px; font-weight: 800; white-space: nowrap;
        }
        .status-pill .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; display: inline-block; }
        .st-parked { background: #E0F2FE; color: #0284C7; }
        .st-done   { background: #DCFCE7; color: #16A34A; }
        .st-warn   { background: #FEF3C7; color: #D97706; }
        .st-danger { background: #FEE2E2; color: #DC2626; }
        .st-muted  { background: #F1F5F9; color: var(--text-muted); }

        /* Bottom bar & pagination */
        .dataTables_wrapper .dt-bottom { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; padding-top: 14px; }
        .dataTables_wrapper .dataTables_info { padding: 0; font-size: 12.5px; font-weight: 700; color: var(--text-muted); }
        .dataTables_wrapper .dataTables_paginate { padding: 0; display: flex; gap: 6px; }
        .table-container-card .dataTables_wrapper .dataTables_paginate .paginate_button {
            min-width: 38px; height: 38px; padding: 0 12px !important; margin: 0 !important; border-radius: 10px !important;
            border: 1.5px solid var(--border-color) !important; background: var(--white) !important; color: var(--navy-blue) !important;
            font-size: 13px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; box-shadow: none !important; transition: all .2s ease;
        }
        .table-container-card .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: var(--soft-blue-bg) !important; border-color: var(--primary-orange) !important; color: var(--primary-orange) !important; transform: translateY(-2px);
        }
        .table-container-card .dataTables_wrapper .dataTables_paginate .paginate_button.current,
        .table-container-card .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
            background: linear-gradient(135deg, var(--primary-orange), #FF8800) !important; border-color: transparent !important;
            color: var(--white) !important; box-shadow: 0 6px 14px rgba(255,107,0,.35) !important;
        }
        .table-container-card .dataTables_wrapper .dataTables_paginate .paginate_button.disabled,
        .table-container-card .dataTables_wrapper .dataTables_paginate .paginate_button.disabled:hover {
            opacity: .4; cursor: not-allowed; background: var(--bg-light) !important; color: var(--text-muted) !important; border-color: var(--border-color) !important; transform: none;
        }
        table.dataTable.dtr-inline.collapsed > tbody > tr > td.dtr-control:before { background-color: var(--primary-orange); box-shadow: none; border: 2px solid var(--white); }
        table.dataTable > tbody > tr.child ul.dtr-details { width: 100%; }

        @media (max-width: 768px) {
            .table-responsive { padding: 6px 12px 16px; }
            .filter-chips { width: 100%; overflow-x: auto; }
            .dataTables_wrapper .dataTables_filter input { width: 100%; }
            .dataTables_wrapper .dataTables_filter { width: 100%; }
        }

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
            .sidebar-menu-link:hover { transform: none; }

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
            .mobile-brand { display: flex; align-items: center; gap: 10px; min-width: 0; }
            .mobile-brand h2 { font-size: 16px; font-weight: 800; line-height: 1.1; color: var(--white); }
            .mobile-brand h2 span { color: var(--primary-orange); }
            .mobile-brand p { font-size: 9.5px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; color: #94A3B8; }

            .dashboard-workspace { padding: 16px 20px 28px; gap: 16px; }

            /* Analytics: 3 + 2 tiles on tablet */
            .analytics-strip-container { grid-template-columns: repeat(6, 1fr); gap: 10px; padding: 12px; }
            .analytics-segment {
                grid-column: span 2; min-width: 0;
                border: 1px solid var(--border-color); background: var(--bg-light);
                padding: 12px 14px;
            }
            .analytics-segment:nth-child(4), .analytics-segment:nth-child(5) { grid-column: span 3; }
            .analytics-segment:last-child { border-right: 1px solid var(--border-color); }
            .analytics-segment:hover { transform: none; }
            .segment-details { min-width: 0; }
            .segment-details h3 { white-space: nowrap; }

            /* Table rows: no hover-jump on touch screens */
            table#ticketsTable tbody tr:hover { transform: none; box-shadow: none; }
        }

        /* ---------- Large phones / small tablets (<= 768px) ---------- */
        @media (max-width: 768px) {
            .mpcs-top-header { padding: 14px 16px; gap: 12px; flex-wrap: wrap; }
            .mpcs-brand-info h1 { font-size: 17px; }

            .toolbar-action-row { gap: 10px; }
            .btn-toolbar-action { flex: 1 1 calc(50% - 10px); justify-content: center; padding: 12px 14px; }

            .table-title-bar { padding: 18px 16px 6px; }
            .table-title-bar h3 { font-size: 16px; flex-wrap: wrap; gap: 8px; }
            .tt-left { min-width: 0; }
            .tt-icon { width: 42px; height: 42px; font-size: 17px; flex-shrink: 0; }
            .chip { flex: 1 0 auto; justify-content: center; white-space: nowrap; padding: 9px 14px; }

            .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .dataTables_wrapper .dt-top { flex-direction: column; align-items: stretch; padding: 10px 0; }
            .dataTables_wrapper .dataTables_filter input { font-size: 16px; } /* stops iOS zoom-on-focus */
            .dataTables_wrapper .dataTables_length { display: flex; align-items: center; justify-content: space-between; }
            .dataTables_wrapper .dataTables_length label { display: flex; align-items: center; gap: 4px; }

            table#ticketsTable thead th { padding: 12px 12px; }
            table#ticketsTable tbody td { padding: 12px 12px; }
            table#ticketsTable tbody tr.child td.child { padding: 10px 14px; border-left: 1px solid var(--border-color); }
            table.dataTable > tbody > tr.child ul.dtr-details > li { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; padding: 9px 0; }
            table.dataTable > tbody > tr.child span.dtr-title { min-width: 96px; color: var(--text-muted); font-size: 11px; text-transform: uppercase; letter-spacing: .6px; }

            .dataTables_wrapper .dt-bottom { flex-direction: column; justify-content: center; text-align: center; }
            .dataTables_wrapper .dataTables_paginate { flex-wrap: wrap; justify-content: center; }
        }

        /* ---------- Phones (<= 600px) ---------- */
        @media (max-width: 600px) {
            .dashboard-workspace { padding: 12px 12px 24px; gap: 14px; }
            .mobile-topbar { top: 8px; padding: 9px 12px; }

            .mpcs-top-header { flex-direction: column; align-items: stretch; border-left-width: 4px; }
            .mpcs-clock-card { justify-content: center; }

            .analytics-strip-container { grid-template-columns: repeat(2, 1fr); padding: 10px; gap: 8px; }
            .analytics-segment,
            .analytics-segment:nth-child(4),
            .analytics-segment:nth-child(5) { grid-column: span 1; gap: 10px; padding: 10px 12px; }
            .analytics-segment:last-child { grid-column: 1 / -1; }
            .segment-icon-pill { width: 36px; height: 36px; font-size: 14px; }
            .segment-details span { font-size: 10px; }
            .segment-details h3 { font-size: 18px; }

            .btn-toolbar-action { font-size: 12.5px; padding: 12px 10px; }

            .table-container-card { border-radius: 14px; }
            .table-title-bar p { font-size: 11.5px; }
            .table-responsive { padding: 4px 10px 14px; }
            .ticket-chip { font-size: 12px; padding: 6px 10px; }
            .plate-no { padding: 4px 10px; }
            .table-container-card .dataTables_wrapper .dataTables_paginate .paginate_button { min-width: 36px; height: 36px; padding: 0 10px !important; }
        }

        /* ---------- Very small phones (<= 380px) ---------- */
        @media (max-width: 380px) {
            .mobile-brand p { display: none; }
            .chip { padding: 9px 10px; font-size: 12px; }
            .chip i { display: none; }
            .segment-details h3 { font-size: 16px; }
        }

        /* Respect reduced-motion preference on touch devices */
        @media (prefers-reduced-motion: reduce) {
            .dashboard-sidebar, .sidebar-overlay { transition: none; }
        }
    </style>
<?php include __DIR__ . "/includes/pwa.php"; ?></head>
<body>

<div class="admin-dashboard-layout">

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

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
                <li><a href="admin_dashboard.php" class="sidebar-menu-link active"><i class="fa-solid fa-chart-pie"></i> Dashboard</a></li>
                <li><a href="admin_security_users.php" class="sidebar-menu-link"><i class="fa-solid fa-user-shield"></i> Security Staff</a></li>
                <li><a href="admin_vip_requests.php" class="sidebar-menu-link"><i class="fa-solid fa-crown"></i> VIP Approvals</a></li>
                <li><a href="admin_reports.php" class="sidebar-menu-link"><i class="fa-solid fa-chart-column"></i> Revenue Reports</a></li>
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

    <main class="dashboard-workspace">

        <!-- Mobile / Tablet Top Bar (hidden on desktop) -->
        <div class="mobile-topbar">
            <button type="button" class="mobile-menu-btn" id="sidebarToggle" aria-label="Open menu" aria-controls="dashboardSidebar" aria-expanded="false"><i class="fa-solid fa-bars"></i></button>
            <div class="mobile-brand">
                <div>
                    <h2>PARK<span>SMART</span></h2>
                    <p>Ambalangoda MPCS</p>
                </div>
            </div>
        </div>

        <!-- Top Header -->
        <header class="mpcs-top-header">
            <div class="mpcs-brand-info">
                <h1>Ambalangoda MPCS Ltd</h1>
                <p><i class="fa-solid fa-location-dot" style="color:var(--primary-orange);"></i> Co-operative Parking Control Hub</p>
            </div>
            <div class="mpcs-clock-card">
                <i class="fa-regular fa-clock" style="color:var(--primary-orange);"></i>
                <div>
                    <div class="clock-time" id="liveClock">00:00:00 AM</div>
                    <div class="clock-date" id="liveDate">Loading...</div>
                </div>
            </div>
        </header>

        <!-- NO-CARD SLEEK METRICS STRIP -->
        <div class="analytics-strip-container">
            <div class="analytics-segment">
                <div class="segment-icon-pill pill-blue"><i class="fa-solid fa-car"></i></div>
                <div class="segment-details">
                    <span>Parked Now</span>
                    <h3><?php echo number_format($total_parked); ?></h3>
                </div>
            </div>

            <div class="analytics-segment">
                <div class="segment-icon-pill pill-amber"><i class="fa-solid fa-crown"></i></div>
                <div class="segment-details">
                    <span>VIP Active</span>
                    <h3><?php echo number_format($vip_parked); ?></h3>
                </div>
            </div>

            <div class="analytics-segment">
                <div class="segment-icon-pill pill-emerald"><i class="fa-solid fa-circle-check"></i></div>
                <div class="segment-details">
                    <span>Completed</span>
                    <h3><?php echo number_format($total_completed); ?></h3>
                </div>
            </div>

            <div class="analytics-segment">
                <div class="segment-icon-pill pill-orange"><i class="fa-solid fa-clock-rotate-left"></i></div>
                <div class="segment-details">
                    <span>Pending VIP</span>
                    <h3><?php echo number_format($pending_vip); ?></h3>
                </div>
            </div>

            <div class="analytics-segment">
                <div class="segment-icon-pill pill-purple"><i class="fa-solid fa-wallet"></i></div>
                <div class="segment-details">
                    <span>Revenue</span>
                    <h3>LKR <?php echo number_format($total_revenue, 0); ?></h3>
                </div>
            </div>
        </div>

        <!-- Sleek Toolbar Action Row -->
        <div class="toolbar-action-row">
            <a href="index.php" class="btn-toolbar-action"><i class="fa-solid fa-plus" style="color:var(--primary-orange);"></i> New Entry</a>
            <a href="exit.php" class="btn-toolbar-action"><i class="fa-solid fa-qrcode" style="color:#16A34A;"></i> Exit Scan</a>
            <a href="admin_vip_requests.php" class="btn-toolbar-action"><i class="fa-solid fa-crown" style="color:#D97706;"></i> Review VIPs</a>
            <a href="admin_reports.php" class="btn-toolbar-action"><i class="fa-solid fa-file-invoice" style="color:#9333EA;"></i> Accounts Report</a>
        </div>

        <!-- Terminal Operations Table -->
        <section class="table-container-card">
            <div class="table-title-bar">
                <div class="tt-left">
                    <div class="tt-icon"><i class="fa-solid fa-list-check"></i></div>
                    <div>
                        <h3>Live Parking Log <span class="live-pill"><span class="live-dot"></span>LIVE</span></h3>
                        <p><?php echo number_format($tickets_total); ?> tickets recorded &middot; latest first</p>
                    </div>
                </div>
                <div class="filter-chips" id="statusChips">
                    <button type="button" class="chip active" data-filter="ALL"><i class="fa-solid fa-layer-group"></i> All</button>
                    <button type="button" class="chip" data-filter="Parked"><i class="fa-solid fa-square-parking"></i> Parked</button>
                    <button type="button" class="chip" data-filter="Completed"><i class="fa-solid fa-circle-check"></i> Completed</button>
                    <button type="button" class="chip" data-filter="VIP"><i class="fa-solid fa-crown"></i> VIP</button>
                </div>
            </div>

            <div class="table-responsive">
                <table id="ticketsTable" class="display responsive nowrap" style="width:100%">
                    <thead>
                        <tr>
                            <th>Ticket Code</th>
                            <th>Vehicle No</th>
                            <th>Vehicle Type</th>
                            <th>Duration &amp; Fee</th>
                            <th>Pass Type</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($tickets_result && $tickets_result->num_rows > 0): ?>
                            <?php while ($row = $tickets_result->fetch_assoc()):
                                $row_class = ($row['status'] === 'PARKED') ? 'row-parked' : '';
                                if ($row['is_vip'] == 1) $row_class = 'row-vip';
                                $fee_order = ($row['status'] === 'COMPLETED') ? (float)$row['total_fee'] : 0;
                            ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td><span class="ticket-chip"><i class="fa-solid fa-ticket"></i><?php echo htmlspecialchars($row['ticket_code']); ?></span></td>
                                    <td>
                                        <span class="plate">
                                            <span class="plate-side">LK</span>
                                            <span class="plate-no"><?php echo htmlspecialchars($row['vehicle_number']); ?></span>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="vtype">
                                            <span class="vtype-ico"><i class="fa-solid <?php echo vehicle_icon($row['type_name']); ?>"></i></span>
                                            <?php echo htmlspecialchars($row['type_name'] ?? 'General'); ?>
                                        </span>
                                    </td>
                                    <td data-order="<?php echo $fee_order; ?>">
                                        <?php if ($row['status'] === 'COMPLETED'): ?>
                                            <div class="fee-box">
                                                <span class="fee-amount"><small>LKR</small><?php echo number_format($row['total_fee'], 2); ?></span>
                                                <span class="fee-dur"><i class="fa-regular fa-hourglass-half"></i><?php echo $row['duration_hours']; ?> Hrs</span>
                                            </div>
                                        <?php elseif ($row['status'] === 'PARKED'): ?>
                                            <span class="parked-now"><span class="dot-live"></span>Parked Currently</span>
                                        <?php else: ?>
                                            <span class="std-pass">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['is_vip'] == 1): ?>
                                            <span class="vip-crown"><i class="fa-solid fa-crown"></i> VIP Pass</span>
                                        <?php else: ?>
                                            <span class="std-pass"><i class="fa-regular fa-id-card"></i> Standard</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo status_badge($row['status']); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>
</div>

<script>
$(document).ready(function() {
    const table = $('#ticketsTable').DataTable({
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        responsive: true,
        order: [],
        dom: '<"dt-top"fl>rt<"dt-bottom"ip>',
        language: {
            search: '',
            searchPlaceholder: 'Search ticket, vehicle number, type...',
            lengthMenu: 'Show _MENU_ rows',
            info: 'Showing <b>_START_–_END_</b> of <b>_TOTAL_</b> tickets',
            infoEmpty: 'No tickets to show',
            infoFiltered: '(filtered from _MAX_)',
            zeroRecords: 'No matching tickets found',
            paginate: {
                previous: '<i class="fa-solid fa-chevron-left"></i>',
                next: '<i class="fa-solid fa-chevron-right"></i>'
            }
        }
    });

    // Quick filter chips
    $('#statusChips .chip').on('click', function () {
        $('#statusChips .chip').removeClass('active');
        $(this).addClass('active');
        const f = $(this).data('filter');
        table.column(4).search('');
        table.column(5).search('');
        if (f === 'VIP') table.column(4).search('VIP');
        else if (f !== 'ALL') table.column(5).search(f);
        table.draw();
    });

    function updateLiveClock() {
        const now = new Date();
        document.getElementById('liveClock').textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
        document.getElementById('liveDate').textContent = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
    }
    updateLiveClock();
    setInterval(updateLiveClock, 1000);
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
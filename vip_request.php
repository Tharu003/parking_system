<?php
include 'config/db.php';
include 'includes/header.php';

$success_msg = "";
$error_msg = "";

// 1. SUBMIT VIP REQUEST WITH DUPLICATE VALIDATION
if (isset($_POST['submit_vip_request'])) {
    $vehicle_number = strtoupper(trim($_POST['vehicle_number']));
    $driver_name    = trim($_POST['driver_name']);
    $contact_no     = trim($_POST['contact_no']);
    $reason         = trim($_POST['reason']);

    if (!empty($vehicle_number) && !empty($driver_name) && !empty($reason)) {
        
        // VALIDATION: එකම වාහන අංකයට PENDING Request එකක් දැනටමත් තිබේදැයි පරීක්ෂා කිරීම
        $check_stmt = $conn->prepare("SELECT id FROM vip_requests WHERE vehicle_number = ? AND status = 'PENDING'");
        $check_stmt->bind_param("s", $vehicle_number);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $error_msg = "මෙම වාහන අංකය ($vehicle_number) සඳහා දැනටමත් Pending VIP Request එකක් පද්ධතියේ පවතී!";
        } else {
            // Request එක Insert කිරීම
            $stmt = $conn->prepare("INSERT INTO vip_requests (vehicle_number, driver_name, contact_no, reason) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $vehicle_number, $driver_name, $contact_no, $reason);

            if ($stmt->execute()) {
                $success_msg = "ඔබගේ VIP/Official Request එක Admin වෙත සාර්ථකව යොමු කරන ලදී!";
            } else {
                $error_msg = "Request එක යැවීමට නොහැකි විය. නැවත උත්සාහ කරන්න.";
            }
        }
    } else {
        $error_msg = "කරුණාකර සියලුම අනිවාර්ය තොරතුරු ඇතුළත් කරන්න.";
    }
}

// 2. FETCH ALL VIP REQUESTS FOR THE RIGHT-SIDE TABLE
// session_status / slot_label tell us whether an already-used request is still
// inside the parking (PARKED) or has left (anything else).
$requests_query = "SELECT r.*,
        (SELECT s.status FROM vip_parking_sessions s WHERE s.vip_request_id = r.id ORDER BY s.id DESC LIMIT 1) AS session_status,
        (SELECT s.slot_label FROM vip_parking_sessions s WHERE s.vip_request_id = r.id ORDER BY s.id DESC LIMIT 1) AS session_slot
    FROM vip_requests r
    ORDER BY r.id DESC LIMIT 15";
$requests_result = $conn->query($requests_query);

// STATS CALCULATION FOR QUICK DASHBOARD BADGES
$total_requests = 0;
$pending_requests = 0;
$approved_requests = 0;

$stats_query = "SELECT status, COUNT(*) as count FROM vip_requests GROUP BY status";
$stats_res = $conn->query($stats_query);
if($stats_res) {
    while($st = $stats_res->fetch_assoc()) {
        $total_requests += $st['count'];
        if($st['status'] == 'PENDING') $pending_requests = $st['count'];
        if($st['status'] == 'APPROVED') $approved_requests = $st['count'];
    }
}
?>

<style>
    :root {
        --app-bg: #F0F4F8;
        --card-bg: #FFFFFF;
        --text-color: #0A192F;
        --sub-text: #64748B;
        --accent-orange: #FF6B00;
        --accent-blue: #1E3A8A;
        --accent-red: #EF4444;
        --accent-green: #10B981;
        --border-color: #E2E8F0;
    }

    /* Header Styling */
    .vip-header-bar {
        background: linear-gradient(135deg, #0A192F, #1E3A8A);
        color: #FFFFFF; 
        padding: 24px 30px; 
        border-radius: 24px;
        margin-bottom: 25px; 
        display: flex; 
        justify-content: space-between; 
        align-items: center;
        box-shadow: 0 15px 35px rgba(10, 25, 47, 0.18);
        flex-wrap: wrap;
        gap: 15px;
    }
    .vip-avatar {
        width: 56px; 
        height: 56px; 
        background: linear-gradient(135deg, var(--accent-orange), #FF9E00);
        color: white; 
        border-radius: 16px; 
        display: flex; 
        align-items: center; 
        justify-content: center;
        font-size: 24px; 
        box-shadow: 0 6px 20px rgba(255, 107, 0, 0.4); 
        flex-shrink: 0;
    }

    /* Counter Badges Top Header */
    .header-stats {
        display: flex;
        gap: 12px;
        align-items: center;
    }
    .stat-chip {
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(8px);
        padding: 8px 16px;
        border-radius: 12px;
        border: 1px solid rgba(255, 255, 255, 0.15);
        font-size: 12px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .stat-chip span {
        font-weight: 800;
        font-size: 14px;
    }

    /* Responsive Grid Setup */
    .vip-container {
        display: grid; 
        grid-template-columns: 380px 1fr; 
        gap: 25px;
        max-width: 1300px; 
        margin: 0 auto 35px auto;
    }

    @media (max-width: 1024px) {
        .vip-container { grid-template-columns: 1fr; }
    }
    @media (max-width: 576px) {
        .vip-header-bar { padding: 20px; }
        .vip-card { padding: 20px !important; }
        .header-stats { width: 100%; justify-content: space-between; }
    }

    /* Card Styling */
    .vip-card {
        background: var(--card-bg); 
        border-radius: 24px; 
        padding: 28px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.04); 
        border: 1px solid var(--border-color);
        position: relative; 
        overflow: hidden;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .vip-card::before {
        content: ''; 
        position: absolute; 
        top: 0; 
        left: 0; 
        width: 100%; 
        height: 6px;
        background: linear-gradient(90deg, var(--accent-blue), var(--accent-orange));
    }

    /* Form Fields */
    .form-group { margin-bottom: 18px; }
    .form-group label {
        display: block; 
        font-size: 12px; 
        font-weight: 700; 
        color: var(--text-color);
        margin-bottom: 7px; 
        text-transform: uppercase; 
        letter-spacing: 0.5px;
    }
    .form-control {
        width: 100%; 
        padding: 13px 16px; 
        border: 2px solid var(--border-color);
        border-radius: 14px; 
        font-size: 14px; 
        font-weight: 600; 
        background: #F8FAFC;
        color: var(--text-color); 
        transition: all 0.3s ease; 
        box-sizing: border-box;
    }
    .form-control:focus {
        border-color: var(--accent-orange); 
        background: #FFFFFF; 
        outline: none;
        box-shadow: 0 0 0 4px rgba(255, 107, 0, 0.15);
    }

    .btn-vip-submit {
        width: 100%; 
        background: linear-gradient(135deg, var(--accent-orange), #FF8800);
        color: white; 
        border: none; 
        padding: 16px; 
        border-radius: 14px; 
        font-size: 15px; 
        font-weight: 700;
        cursor: pointer; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        gap: 10px;
        box-shadow: 0 8px 25px rgba(255, 107, 0, 0.3); 
        transition: all 0.3s ease; 
        margin-top: 10px;
    }
    .btn-vip-submit:hover { 
        transform: translateY(-2px); 
        box-shadow: 0 12px 30px rgba(255, 107, 0, 0.45); 
    }
    .btn-vip-submit:disabled { 
        background: #CBD5E1; 
        cursor: not-allowed; 
        box-shadow: none; 
        transform: none; 
    }

    /* Table Search Input */
    .search-box {
        position: relative;
        width: 220px;
    }
    .search-box input {
        padding: 8px 12px 8px 32px;
        font-size: 12px;
        border-radius: 10px;
        border: 1px solid var(--border-color);
        background: #F8FAFC;
        width: 100%;
        outline: none;
        font-weight: 600;
    }
    .search-box i {
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--sub-text);
        font-size: 12px;
    }

    /* Responsive Table Styling */
    .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; container-type: inline-size; }
    .vip-table { width: 100%; border-collapse: separate; border-spacing: 0 10px; min-width: 650px; }
    .vip-table th {
        background: #F8FAFC; 
        color: var(--sub-text); 
        font-size: 11px;
        font-weight: 700; 
        text-transform: uppercase; 
        padding: 14px;
        border-bottom: 2px solid var(--border-color); 
        text-align: left;
    }
    .vip-table td {
        background: #FFFFFF; 
        padding: 14px; 
        font-size: 13px; 
        font-weight: 600;
        color: var(--text-color); 
        border-top: 1px solid var(--border-color);
        border-bottom: 1px solid var(--border-color);
        transition: background 0.2s ease;
    }
    .vip-table tr td:first-child { border-left: 1px solid var(--border-color); border-radius: 14px 0 0 14px; }
    .vip-table tr td:last-child { border-right: 1px solid var(--border-color); border-radius: 0 14px 14px 0; }
    .vip-table tr:hover td { background: #F8FAFC; }

    /* Status Badges */
    .status-badge {
        display: inline-flex; 
        align-items: center; 
        gap: 6px; 
        padding: 6px 12px;
        border-radius: 20px; 
        font-weight: 700; 
        font-size: 11px; 
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .badge-pending { background: rgba(255, 167, 38, 0.15); color: #F57C00; border: 1px solid rgba(255, 167, 38, 0.3); }
    .badge-approved { background: rgba(16, 185, 129, 0.15); color: var(--accent-green); border: 1px solid rgba(16, 185, 129, 0.3); }
    .badge-rejected { background: rgba(239, 68, 68, 0.15); color: var(--accent-red); border: 1px solid rgba(239, 68, 68, 0.3); }
    .badge-used { background: rgba(30, 58, 138, 0.10); color: var(--accent-blue); border: 1px solid rgba(30, 58, 138, 0.25); }
    .badge-done { background: rgba(100, 116, 139, 0.12); color: #475569; border: 1px solid rgba(100, 116, 139, 0.28); }
    .badge-unknown { background: #F1F5F9; color: var(--sub-text); border: 1px solid var(--border-color); }

    /* Table cell helpers */
    .td-guest { overflow-wrap: anywhere; }
    .td-guest small { line-height: 1.45; }
    .td-action { text-align: center; }
    .date-line .date-day { display: block; font-size: 12px; color: var(--sub-text); }
    .date-line .date-time { display: block; font-size: 11px; color: var(--text-color); }

    /* Narrow table area -> each request becomes a small card, nothing is cut off.
       Works off the width of the table's own box (not the screen), so it also
       applies on laptops where the form column squeezes the table. */
    @container (max-width: 680px) {
        .vip-table { display: block; min-width: 0; border-spacing: 0; }
        .vip-table thead { display: none; }
        .vip-table tbody { display: block; }

        .vip-table tbody tr {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            grid-template-areas:
                "vehicle status"
                "guest   guest"
                "date    action";
            gap: 10px 12px;
            align-items: center;
            background: #FFFFFF;
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 14px 16px;
            margin-bottom: 12px;
        }
        .vip-table tbody tr:hover { background: #F8FAFC; }

        .vip-table td,
        .vip-table tr td:first-child,
        .vip-table tr td:last-child,
        .vip-table tr:hover td {
            background: transparent;
            border: 0;
            border-radius: 0;
            padding: 0;
        }

        .td-vehicle { grid-area: vehicle; }
        .td-status  { grid-area: status; justify-self: end; }
        .td-guest   { grid-area: guest; }
        .td-date    { grid-area: date; }
        .td-action  { grid-area: action; justify-self: end; text-align: right; }
        .td-empty   { grid-column: 1 / -1; grid-area: auto; }

        .date-line { display: flex; flex-wrap: wrap; gap: 2px 8px; align-items: baseline; }
        .date-line .date-day,
        .date-line .date-time { display: inline; }
        .action-na { display: none; }
    }

    /* Action Print Button */
    .btn-print-mini {
        background: linear-gradient(135deg, var(--accent-green), #059669); 
        color: white; 
        border: none; 
        padding: 8px 14px;
        border-radius: 10px; 
        font-size: 12px; 
        font-weight: 700; 
        text-decoration: none;
        display: inline-flex; 
        align-items: center; 
        gap: 6px; 
        transition: all 0.25s ease;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
        cursor: pointer;
    }
    .btn-print-mini:hover { 
        background: #047857; 
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(16, 185, 129, 0.35);
    }

    /* Alerts */
    .alert-box { 
        padding: 16px; 
        border-radius: 16px; 
        margin-bottom: 22px; 
        font-weight: 600; 
        text-align: center; 
        font-size: 14px; 
        animation: fadeIn 0.4s ease;
    }
    .alert-error { background: rgba(239, 68, 68, 0.12); color: var(--accent-red); border: 1px solid rgba(239, 68, 68, 0.25); }
    .alert-success { background: rgba(16, 185, 129, 0.12); color: var(--accent-green); border: 1px solid rgba(16, 185, 129, 0.25); }

    /* ===== MODAL TICKET DESIGN (Premium VIP Pass) ===== */
    .modal-overlay {
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(10, 25, 47, 0.7);
        backdrop-filter: blur(5px);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 9999;
    }

    .ticket-card {
        background: linear-gradient(180deg, #ffffff 0%, #fbf9f4 100%);
        width: 280px;
        border-radius: 14px;
        padding: 0 0 14px 0;
        box-shadow: 0 20px 50px rgba(0,0,0,0.3);
        border: 1.5px solid #0A192F;
        position: relative;
        text-align: center;
        overflow: hidden;
    }

    .ticket-card::before,
    .ticket-card::after {
        content: '';
        position: absolute;
        left: 0;
        width: 100%;
        height: 3px;
        background: linear-gradient(90deg, #FF6B00, #FFC46B, #FF6B00);
    }
    .ticket-card::before { top: 0; }
    .ticket-card::after { bottom: 0; }

    .ticket-header {
        background: #0A192F;
        color: #fff;
        padding: 14px 10px 10px 10px;
        margin-top: 3px;
    }
    .ticket-header h3 {
        font-size: 14px !important;
        color: #fff !important;
        letter-spacing: 1px;
        text-transform: uppercase;
    }
    .ticket-header span {
        color: rgba(255,255,255,0.65) !important;
        font-size: 8px !important;
        display: block;
        margin-top: 3px;
        letter-spacing: 1.5px;
    }

    .ticket-vip-badge {
        background: #FF6B00;
        color: #fff;
        text-align: center;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 2.5px;
        padding: 6px 0;
        text-transform: uppercase;
    }
    .ticket-vip-badge i { margin-right: 6px; }

    .ticket-body {
        text-align: left;
        font-size: 13px;
        padding: 14px 18px 0 18px;
    }
    .ticket-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 9px;
        gap: 10px;
    }
    .ticket-label { color: var(--sub-text); font-size: 9.5px; text-transform: uppercase; font-weight: 700; letter-spacing: 0.4px; white-space: nowrap; }
    .ticket-val { font-weight: 800; color: var(--text-color); text-align: right; }
    .ticket-vehicle-highlight {
        font-size: 14px;
        font-weight: 900;
        background: #0A192F;
        color: #fff;
        padding: 2px 8px;
        border-radius: 5px;
        letter-spacing: 1px;
    }

    .ticket-divider {
        border-bottom: 1.5px dashed #CBD5E1;
        margin: 10px 18px;
    }

    .ticket-qr-box {
        text-align: center;
        margin: 2px 0 4px 0;
    }
    .ticket-qr-box .qr-frame {
        display: inline-block;
        padding: 6px;
        background: #fff;
        border: 1.5px solid #0A192F;
        border-radius: 8px;
    }
    .ticket-qr-box .qr-code-text {
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 1.5px;
        margin-top: 6px;
        color: #0A192F;
    }

    .ticket-footer-note {
        text-align: center;
        padding: 0 18px;
        margin-top: 4px;
    }
    .ticket-footer-note .wish {
        font-size: 10.5px;
        font-weight: 800;
        color: #FF6B00;
        letter-spacing: 1px;
        text-transform: uppercase;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* ===== PRINT STYLES — only the VIP ticket, on one small page ===== */
    @media print {
        /* Hide the site chrome (from header.php / footer.php) and the
           whole request page — this is what was causing extra pages and
           the ticket repeating on every page. */
        .top-bar,
        .main-header,
        .main-footer,
        .mobile-bottom-nav,
        .vip-header-bar,
        .vip-container,
        .alert-box,
        .no-print {
            display: none !important;
        }

        html, body {
            height: auto !important;
            min-height: 0 !important;
            overflow: visible !important;
            background: #fff !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .modal-overlay {
            display: block !important;
            position: static !important;
            width: auto !important;
            height: auto !important;
            background: none !important;
            backdrop-filter: none !important;
            padding: 0 !important;
        }

        .ticket-card {
            width: 76mm !important;
            max-width: 76mm !important;
            margin: 0 auto !important;
            box-shadow: none !important;
            border: 1.5px solid #000 !important;
            page-break-inside: avoid !important;
            page-break-after: avoid !important;
            break-inside: avoid !important;
        }
    }

    @page {
        size: 80mm auto;
        margin: 0;
    }
</style>

<!-- Top Title Bar -->
<div class="vip-header-bar">
    <div style="display: flex; align-items: center; gap: 16px;">
        <div class="vip-avatar"><i class="fa-solid fa-crown"></i></div>
        <div>
            <h2 style="font-size: 20px; font-weight: 800; margin: 0; letter-spacing: -0.3px;">VIP & Official Parking Request</h2>
            <p style="font-size: 12px; color: rgba(255,255,255,0.75); margin: 3px 0 0 0;">Request VIP Access & Track Approval Status</p>
        </div>
    </div>
    <div class="header-stats">
        <div class="stat-chip"><i class="fa-solid fa-folder-open" style="color: #60A5FA;"></i> Total: <span><?php echo $total_requests; ?></span></div>
        <div class="stat-chip"><i class="fa-solid fa-hourglass-half" style="color: #FBBF24;"></i> Pending: <span><?php echo $pending_requests; ?></span></div>
        <div class="stat-chip"><i class="fa-solid fa-circle-check" style="color: #34D399;"></i> Approved: <span><?php echo $approved_requests; ?></span></div>
    </div>
</div>

<!-- Alerts -->
<?php if ($error_msg): ?>
    <div class="alert-box alert-error"><i class="fa-solid fa-triangle-exclamation" style="margin-right: 6px;"></i> <?php echo $error_msg; ?></div>
<?php endif; ?>

<?php if ($success_msg): ?>
    <div class="alert-box alert-success"><i class="fa-solid fa-circle-check" style="margin-right: 6px;"></i> <?php echo $success_msg; ?></div>
<?php endif; ?>

<div class="vip-container">
    
    <!-- LEFT SIDE: REQUEST FORM -->
    <div class="vip-card">
        <h3 style="font-size: 16px; font-weight: 800; margin-bottom: 20px; color: var(--text-color); display: flex; align-items: center; gap: 10px;">
            <i class="fa-solid fa-paper-plane" style="color: var(--accent-orange);"></i> New VIP Access Request
        </h3>

        <form action="" method="POST" id="vipForm" onsubmit="preventDoubleSubmit()">
            <div class="form-group">
                <label>Vehicle Plate Number *</label>
                <input type="text" name="vehicle_number" class="form-control" placeholder="e.g. CAD-1234" required style="text-transform: uppercase;" autocomplete="off">
            </div>

            <div class="form-group">
                <label>Guest / Driver Name *</label>
                <input type="text" name="driver_name" class="form-control" placeholder="Enter guest name" required autocomplete="off">
            </div>

            <div class="form-group">
                <label>Contact Number</label>
                <input type="text" name="contact_no" class="form-control" placeholder="07X XXXXXXX" autocomplete="off">
            </div>

            <div class="form-group">
                <label>Reason for VIP Pass *</label>
                <textarea name="reason" class="form-control" rows="3" placeholder="Official Meeting / Guest Visit / Delivery..." required></textarea>
            </div>

            <button type="submit" name="submit_vip_request" id="submitBtn" class="btn-vip-submit">
                <i class="fa-solid fa-paper-plane"></i> Submit Request
            </button>
        </form>
    </div>

    <!-- RIGHT SIDE: LIVE REQUESTS TABLE -->
    <div class="vip-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 12px;">
            <h3 style="font-size: 16px; font-weight: 800; color: var(--text-color); margin: 0; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-list-check" style="color: var(--accent-blue);"></i> Submitted Requests
            </h3>
            
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="tableSearch" onkeyup="filterRequestsTable()" placeholder="Search vehicle or name...">
            </div>
        </div>

        <div class="table-responsive">
            <table class="vip-table" id="requestsTable">
                <thead>
                    <tr>
                        <th>Vehicle No</th>
                        <th>Guest & Reason</th>
                        <th>Requested Date</th>
                        <th>Status</th>
                        <th style="text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($requests_result && $requests_result->num_rows > 0): ?>
                        <?php while ($row = $requests_result->fetch_assoc()): ?>
                            <tr>
                                <td class="td-vehicle">
                                    <strong style="color: var(--accent-blue); font-size: 14px; letter-spacing: 0.5px;">
                                        <?php echo htmlspecialchars($row['vehicle_number']); ?>
                                    </strong>
                                </td>
                                <td class="td-guest">
                                    <div style="font-weight: 700; font-size: 13px; color: var(--text-color);">
                                        <?php echo htmlspecialchars($row['driver_name']); ?>
                                    </div>
                                    <small style="color: var(--sub-text); font-size: 11px; display: block; margin-top: 2px;">
                                        <?php echo htmlspecialchars($row['reason']); ?>
                                    </small>
                                </td>
                                <td class="td-date">
                                    <div class="date-line">
                                        <span class="date-day"><?php echo date('M d, Y', strtotime($row['requested_date'])); ?></span>
                                        <strong class="date-time"><?php echo date('h:i A', strtotime($row['requested_date'])); ?></strong>
                                    </div>
                                </td>
                                <td class="td-status">
                                    <?php $st = strtoupper(trim($row['status'] ?? '')); ?>
                                    <?php if ($st === 'PENDING'): ?>
                                        <span class="status-badge badge-pending"><i class="fa-solid fa-clock"></i> Pending</span>
                                    <?php elseif ($st === 'APPROVED'): ?>
                                        <span class="status-badge badge-approved"><i class="fa-solid fa-circle-check"></i> Approved</span>
                                    <?php elseif ($st === 'USED' && strtoupper($row['session_status'] ?? '') === 'PARKED'): ?>
                                        <!-- Approved, vehicle is inside the parking right now -->
                                        <span class="status-badge badge-used"><i class="fa-solid fa-square-parking"></i> Parked<?php echo !empty($row['session_slot']) ? ' · ' . htmlspecialchars($row['session_slot']) : ''; ?></span>
                                    <?php elseif (in_array($st, ['USED', 'COMPLETED', 'EXITED', 'CLOSED', 'EXPIRED'], true)): ?>
                                        <!-- Approved, vehicle came in and has already left -->
                                        <span class="status-badge badge-done"><i class="fa-solid fa-flag-checkered"></i> Completed</span>
                                    <?php elseif ($st === 'REJECTED'): ?>
                                        <span class="status-badge badge-rejected"><i class="fa-solid fa-circle-xmark"></i> Rejected</span>
                                    <?php else: ?>
                                        <span class="status-badge badge-unknown"><?php echo htmlspecialchars($st !== '' ? $st : 'Unknown'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="td-action">
                                    <?php if ($row['status'] == 'APPROVED'): ?>
                                        <!-- Open Ticket Modal via JavaScript -->
                                        <button type="button" class="btn-print-mini" onclick="openTicketModal('<?php echo htmlspecialchars($row['vehicle_number']); ?>', '<?php echo htmlspecialchars($row['driver_name']); ?>', '<?php echo date('Y-m-d h:i A', strtotime($row['requested_date'])); ?>', '<?php echo isset($row['ticket_code']) ? htmlspecialchars($row['ticket_code']) : 'VIP-'.rand(1000,9999); ?>')">
                                            <i class="fa-solid fa-print"></i> Ticket
                                        </button>
                                    <?php else: ?>
                                        <span class="action-na" style="color: #94A3B8; font-size: 11px; font-weight: 600;">N/A</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td class="td-empty" colspan="5" style="text-align: center; color: var(--sub-text); padding: 35px 15px;">
                                <i class="fa-solid fa-inbox" style="font-size: 32px; display: block; margin-bottom: 10px; opacity: 0.4;"></i>
                                තවම කිසිදු VIP Request එකක් යොමු කර නැත.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- TICKET PRINT POPUP MODAL -->
<div class="modal-overlay" id="ticketModal">
    <div class="ticket-card" id="printableTicket">
        <div class="ticket-header">
            <h3><i class="fa-solid fa-square-parking"></i> PARKSMART</h3>
            <span>Express Highway Parking</span>
        </div>

        <div class="ticket-vip-badge">
            <i class="fa-solid fa-crown"></i>VIP Access Pass
        </div>

        <div class="ticket-body">
            <div class="ticket-row">
                <span class="ticket-label">Pass Code</span>
                <span class="ticket-val" id="modalTicketCode" style="color: var(--accent-orange);">VIP-0000</span>
            </div>
            <div class="ticket-row">
                <span class="ticket-label">Vehicle No</span>
                <span class="ticket-val ticket-vehicle-highlight" id="modalVehicle">CAD-1234</span>
            </div>
            <div class="ticket-row">
                <span class="ticket-label">Guest Name</span>
                <span class="ticket-val" id="modalDriver">John Doe</span>
            </div>
            <div class="ticket-row">
                <span class="ticket-label">Date & Time</span>
                <span class="ticket-val" id="modalTime" style="font-size: 10.5px;">2026-09-13 10:00 AM</span>
            </div>
            <div class="ticket-row">
                <span class="ticket-label">Status</span>
                <span class="ticket-val" style="color: var(--accent-green);">APPROVED</span>
            </div>
        </div>

        <div class="ticket-divider"></div>

        <div class="ticket-qr-box">
            <div class="qr-frame">
                <div id="modalQrCode"></div>
            </div>
            <div class="qr-code-text">* <span id="modalTicketCodeQr">VIP-0000</span> *</div>
        </div>

        <div class="ticket-divider"></div>

        <div class="ticket-footer-note">
            <div style="font-size: 8.5px; font-weight: 600; color: #475569; line-height: 1.4;">
                කරුණාකර පිටවන විට මෙම VIP PASS එක පෙන්වන්න.
            </div>
            <div class="wish" style="margin-top: 4px;">Have a Safe Journey</div>
        </div>

        <!-- Buttons (Hidden when Printing) -->
        <div style="margin-top: 16px; padding: 0 18px; display: flex; gap: 10px;" class="no-print">
            <button onclick="window.print()" class="btn-vip-submit" style="margin: 0; padding: 10px; font-size: 13px;">
                <i class="fa-solid fa-print"></i> Print Now
            </button>
            <button onclick="closeTicketModal()" style="background: #E2E8F0; color: var(--text-color); border: none; padding: 10px 15px; border-radius: 12px; font-weight: 700; cursor: pointer;">
                Close
            </button>
        </div>
    </div>
</div>

<script>
    // Prevent Multiple Submissions (Double Click Guard)
    function preventDoubleSubmit() {
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending Request...';
    }

    // Modal Control Functions
    function openTicketModal(vehicle, driver, time, code) {
        document.getElementById('modalVehicle').innerText = vehicle;
        document.getElementById('modalDriver').innerText = driver;
        document.getElementById('modalTime').innerText = time;
        document.getElementById('modalTicketCode').innerText = code;
        document.getElementById('modalTicketCodeQr').innerText = code;

        // Clear and regenerate the QR code each time (modal is reused for
        // every row, so the previous ticket's QR must not stick around).
        const qrContainer = document.getElementById('modalQrCode');
        qrContainer.innerHTML = '';
        if (typeof QRCode !== 'undefined' && code) {
            new QRCode(qrContainer, {
                text: code,
                width: 80,
                height: 80,
                colorDark: "#0A192F",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.H
            });
        }

        document.getElementById('ticketModal').style.display = 'flex';
    }

    function closeTicketModal() {
        document.getElementById('ticketModal').style.display = 'none';
    }

    // Search Filter for Table Rows
    function filterRequestsTable() {
        const input = document.getElementById('tableSearch');
        const filter = input.value.toUpperCase();
        const table = document.getElementById('requestsTable');
        const tr = table.getElementsByTagName('tr');

        for (let i = 1; i < tr.length; i++) {
            let showRow = false;
            const tdVehicle = tr[i].getElementsByTagName('td')[0];
            const tdDriver = tr[i].getElementsByTagName('td')[1];

            if (tdVehicle && tdDriver) {
                const txtVehicle = tdVehicle.textContent || tdVehicle.innerText;
                const txtDriver = tdDriver.textContent || tdDriver.innerText;
                if (txtVehicle.toUpperCase().indexOf(filter) > -1 || txtDriver.toUpperCase().indexOf(filter) > -1) {
                    showRow = true;
                }
            }
            tr[i].style.display = showRow ? '' : 'none';
        }
    }
</script>

<?php include 'includes/footer.php'; ?>
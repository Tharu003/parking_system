<?php
/* =========================================================================
   ParkSmart - Entry Terminal (index.php)
   Matched to `parking_db` schema:
     admins, audit_logs, tickets, vehicle_types, vip_requests
   ========================================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/db.php';

/* -------------------------------------------------------------------------
   HELPERS
   ------------------------------------------------------------------------- */

function park_category_key($type_name) {
    $n = strtolower($type_name);
    if (strpos($n, 'bike') !== false || strpos($n, 'wheel') !== false)  return 'bike';
    if (strpos($n, 'bus')  !== false || strpos($n, 'lorry') !== false
        || strpos($n, 'heavy') !== false)                              return 'bus';
    return 'car';
}

$category_limits = [
    'bike' => 20,
    'car'  => 10,
    'bus'  => 5,
    'vip'  => 5,
];

function park_limit($type_name) {
    global $category_limits;
    $key = park_category_key($type_name);
    return isset($category_limits[$key]) ? $category_limits[$key] : 10;
}

function park_icon($type_name) {
    switch (park_category_key($type_name)) {
        case 'bike': return 'fa-motorcycle';
        case 'bus':  return 'fa-bus';
        default:     return 'fa-car';
    }
}

function park_row_icon($type_name, $is_vip) {
    return $is_vip ? 'fa-crown' : park_icon($type_name);
}

function park_valid_plate($plate, $cat_key) {
    $common = '([A-Z]{2}\s)?[A-Z]{2,3}-\d{4}|\d{1,3}\sSRI\s\d{4}|\d{2,3}-\d{4}';
    $pattern = ($cat_key === 'bike')
        ? '/^(' . $common . '|[A-Z]-\d{4})$/i'
        : '/^(' . $common . ')$/i';
    return (bool) preg_match($pattern, $plate);
}

function park_valid_any_plate($plate) {
    $pattern = '/^(([A-Z]{2}\s)?[A-Z]{2,3}-\d{4}|\d{1,3}\sSRI\s\d{4}|\d{2,3}-\d{4}|[A-Z]-\d{4})$/i';
    return (bool) preg_match($pattern, $plate);
}

function park_new_ticket_code($conn) {
    $check = $conn->prepare("SELECT id FROM tickets WHERE ticket_code = ? LIMIT 1");
    for ($i = 0; $i < 8; $i++) {
        $code = "PK-" . time() . "-" . rand(100, 999);
        $check->bind_param("s", $code);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            $check->close();
            return $code;
        }
        usleep(150000);
    }
    $check->close();
    return "PK-" . time() . "-" . substr(strtoupper(bin2hex(random_bytes(2))), 0, 4);
}

function park_audit($conn, $user, $action) {
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_name, action) VALUES (?, ?)");
    $stmt->bind_param("ss", $user, $action);
    $stmt->execute();
    $stmt->close();
}

/* -------------------------------------------------------------------------
   STATE
   ------------------------------------------------------------------------- */
$ticket_generated = false;
$ticket_data      = null;
$error_msg        = "";
$success_msg      = "";

if (!empty($_SESSION['flash_error']))   { $error_msg   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }
if (!empty($_SESSION['flash_success'])) { $success_msg = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }

/* -------------------------------------------------------------------------
   1. ENTRY REGISTRATION
   ------------------------------------------------------------------------- */
$vip_entry_created = false;
$vip_entry_data = null;

if (isset($_POST['generate_ticket'])) {

    $raw_plate   = strtoupper(trim($_POST['vehicle_number'] ?? ''));
    $vehicle_num = preg_replace('/\s+/', ' ', $raw_plate);
    $selected_type = $_POST['vehicle_type'] ?? '';
    $entry_time  = date('Y-m-d H:i:s');

    if ($selected_type === 'vip') {

        if ($vehicle_num === '') {
            $_SESSION['flash_error'] = "කරුණාකර VIP වාහන අංකය ඇතුළත් කරන්න!";
        } elseif (!park_valid_any_plate($vehicle_num)) {
            $_SESSION['flash_error'] = "ඇතුළත් කළ VIP වාහන අංකය වලංගු ශ්‍රී ලාංකීය අංක තහඩු රටාවක් නොවේ!";
        } else {

            $dup = $conn->prepare(
                "SELECT id FROM tickets WHERE vehicle_number=? AND status='PARKED'
                 UNION ALL
                 SELECT id FROM vip_parking_sessions WHERE vehicle_number=? AND status='PARKED'
                 LIMIT 1"
            );
            $dup->bind_param("ss", $vehicle_num, $vehicle_num);
            $dup->execute();
            $already_in = $dup->get_result()->num_rows > 0;
            $dup->close();

            if ($already_in) {
                $_SESSION['flash_error'] = "මෙම VIP වාහනය (" . htmlspecialchars($vehicle_num) . ") දැනටමත් Parking එක තුළ පවතී!";
            } else {

                $vip_stmt = $conn->prepare(
                    "SELECT id, vehicle_number, driver_name, contact_no, reason
                     FROM vip_requests
                     WHERE vehicle_number=? AND status='APPROVED'
                     ORDER BY id DESC LIMIT 1"
                );
                $vip_stmt->bind_param("s", $vehicle_num);
                $vip_stmt->execute();
                $vip_request = $vip_stmt->get_result()->fetch_assoc();
                $vip_stmt->close();

                if (!$vip_request) {
                    $_SESSION['flash_error'] =
                        "VIP authorization එකක් හමු නොවීය. Admin විසින් APPROVED කළ VIP request එකක් අවශ්‍යයි.";
                } else {

                    $vip_limit = $category_limits['vip'] ?? 5;
                    $legacy_vip = $conn->query(
                        "SELECT COUNT(*) AS c FROM tickets WHERE status='PARKED' AND is_vip=1"
                    )->fetch_assoc()['c'] ?? 0;
                    $session_vip = $conn->query(
                        "SELECT COUNT(*) AS c FROM vip_parking_sessions WHERE status='PARKED'"
                    )->fetch_assoc()['c'] ?? 0;
                    $vip_occupied = (int)$legacy_vip + (int)$session_vip;

                    if ($vip_occupied >= $vip_limit) {
                        $_SESSION['flash_error'] =
                            "VIP Parking Zone එකේ වෙන් කළ Slots $vip_limit ම සම්පූර්ණයෙන්ම පිරී ඇත!";
                    } else {

                        $slot_no = $vip_occupied + 1;
                        $slot_label = "VIP-" . sprintf('%02d', $slot_no);

                        $conn->begin_transaction();
                        try {
                            $ins = $conn->prepare(
                                "INSERT INTO vip_parking_sessions
                                 (vip_request_id,vehicle_number,driver_name,contact_no,reason,entry_time,status,slot_label,authorized_by)
                                 VALUES (?,?,?,?,?,?,'PARKED',?,?)"
                            );
                            $authorized_by = 'Entry Terminal';
                            $ins->bind_param(
                                "isssssss",
                                $vip_request['id'],
                                $vehicle_num,
                                $vip_request['driver_name'],
                                $vip_request['contact_no'],
                                $vip_request['reason'],
                                $entry_time,
                                $slot_label,
                                $authorized_by
                            );
                            if (!$ins->execute()) {
                                throw new Exception("VIP session insert failed.");
                            }
                            $vip_session_id = $ins->insert_id;
                            $ins->close();

                            $use = $conn->prepare(
                                "UPDATE vip_requests SET status='USED' WHERE id=? AND status='APPROVED'"
                            );
                            $use->bind_param("i", $vip_request['id']);
                            $use->execute();
                            if ($use->affected_rows !== 1) {
                                $use->close();
                                throw new Exception("VIP authorization could not be consumed.");
                            }
                            $use->close();

                            park_audit(
                                $conn,
                                'Entry Terminal',
                                "VIP vehicle $vehicle_num admitted to $slot_label. NO TICKET ISSUED. VIP Request ID: {$vip_request['id']}"
                            );

                            $conn->commit();

                            $_SESSION['last_vip_entry'] = [
                                'id' => $vip_session_id,
                                'v_num' => $vehicle_num,
                                'driver' => $vip_request['driver_name'],
                                'reason' => $vip_request['reason'],
                                'slot' => $slot_label,
                                'time' => $entry_time
                            ];

                            header("Location: dashboard.php?vip_issued=1");
                            exit;

                        } catch (Throwable $e) {
                            $conn->rollback();
                            $_SESSION['flash_error'] =
                                "VIP Entry එක සටහන් කිරීමට නොහැකි විය. නැවත උත්සාහ කරන්න.";
                        }
                    }
                }
            }
        }

        header("Location: dashboard.php");
        exit;
    }

    $type_id = intval($selected_type);

    $type_stmt = $conn->prepare("SELECT type_name, hourly_rate FROM vehicle_types WHERE id = ?");
    $type_stmt->bind_param("i", $type_id);
    $type_stmt->execute();
    $type_row = $type_stmt->get_result()->fetch_assoc();
    $type_stmt->close();

    if (!$type_row) {
        $_SESSION['flash_error'] = "වලංගු වාහන කාණ්ඩයක් තෝරා නොමැත!";
        header("Location: dashboard.php");
        exit;
    }

    $cat_name = $type_row['type_name'];
    $cat_key  = park_category_key($cat_name);

    if ($vehicle_num === '') {
        $_SESSION['flash_error'] = "කරුණාකර වාහන අංකය ඇතුළත් කරන්න!";

    } elseif (!park_valid_plate($vehicle_num, $cat_key)) {
        $_SESSION['flash_error'] = "ඇතුළත් කළ අංක තහඩුව (" . htmlspecialchars($vehicle_num) . ") "
            . htmlspecialchars($cat_name) . " කාණ්ඩයට ගැළපෙන වලංගු ශ්‍රී ලාංකීය අංක රටාවක් නොවේ!";

    } else {

        $vip_check = $conn->prepare(
            "SELECT id FROM vip_requests
             WHERE vehicle_number=? AND status='APPROVED'
             ORDER BY id DESC LIMIT 1"
        );
        $vip_check->bind_param("s", $vehicle_num);
        $vip_check->execute();
        $is_approved_vip = $vip_check->get_result()->num_rows > 0;
        $vip_check->close();

        if ($is_approved_vip) {
            $_SESSION['flash_error'] =
                "මෙම වාහනය VIP ලෙස Admin විසින් APPROVE කර ඇත. Entry එක සඳහා 'VIP Vehicle' Category එක තෝරන්න.";
        } else {

            $check_stmt = $conn->prepare(
                "SELECT id FROM tickets WHERE vehicle_number = ? AND status = 'PARKED'
                 UNION ALL
                 SELECT id FROM vip_parking_sessions WHERE vehicle_number = ? AND status = 'PARKED'
                 LIMIT 1"
            );
            $check_stmt->bind_param("ss", $vehicle_num, $vehicle_num);
            $check_stmt->execute();
            $already_in = ($check_stmt->get_result()->num_rows > 0);
            $check_stmt->close();

            if ($already_in) {
                $_SESSION['flash_error'] = "මෙම වාහනය (" . htmlspecialchars($vehicle_num) . ") දැනටමත් Parking එක ඇතුලත පවතී!";
            } else {

                $space_stmt = $conn->prepare(
                    "SELECT COUNT(*) AS parked_count
                     FROM tickets
                     WHERE vehicle_type_id = ? AND status = 'PARKED' AND is_vip = 0"
                );
                $space_stmt->bind_param("i", $type_id);
                $space_stmt->execute();
                $parked_count = (int)$space_stmt->get_result()->fetch_assoc()['parked_count'];
                $space_stmt->close();

                $max_limit = $category_limits[$cat_key] ?? 10;

                if ($parked_count >= $max_limit) {
                    $_SESSION['flash_error'] =
                        "සමාවෙන්න! " . htmlspecialchars($cat_name)
                        . " සඳහා වෙන් කර ඇති Parking Space (" . $max_limit . ") සම්පූර්ණයෙන්ම පිරී ඇත!";
                } else {

                    $ticket_code = park_new_ticket_code($conn);

                    $stmt = $conn->prepare(
                        "INSERT INTO tickets (ticket_code, vehicle_number, vehicle_type_id, entry_time, status, is_vip)
                         VALUES (?, ?, ?, ?, 'PARKED', 0)"
                    );
                    $stmt->bind_param("ssis", $ticket_code, $vehicle_num, $type_id, $entry_time);

                    if ($stmt->execute()) {
                        $new_id = $stmt->insert_id;
                        $stmt->close();

                        park_audit(
                            $conn,
                            'Entry Terminal',
                            "Entry ticket $ticket_code issued for $vehicle_num ($cat_name)"
                        );

                        $_SESSION['last_ticket'] = [
                            'id'     => $new_id,
                            'code'   => $ticket_code,
                            'v_num'  => $vehicle_num,
                            'v_type' => $cat_name,
                            'rate'   => $type_row['hourly_rate'],
                            'time'   => $entry_time,
                            'is_vip' => 0,
                        ];
                        header("Location: dashboard.php?issued=1");
                        exit;

                    } else {
                        $stmt->close();
                        $_SESSION['flash_error'] = "Database එකට ඇතුළත් කිරීමට අපොහොසත් විය. නැවත උත්සාහ කරන්න.";
                    }
                }
            }
        }
    }

    header("Location: dashboard.php");
    exit;
}

if (isset($_GET['vip_issued']) && !empty($_SESSION['last_vip_entry'])) {
    $vip_entry_created = true;
    $vip_entry_data = $_SESSION['last_vip_entry'];
    $success_msg = "VIP Entry සාර්ථකව සටහන් විය — සාමාන්‍ය Parking Ticket එකක් නිකුත් කර නැත.";
    unset($_SESSION['last_vip_entry']);
}

if (isset($_GET['issued']) && !empty($_SESSION['last_ticket'])) {
    $ticket_generated = true;
    $ticket_data      = $_SESSION['last_ticket'];
    $success_msg      = "ටිකට්පත සාර්ථකව Generate විය!";
    unset($_SESSION['last_ticket']);
}

/* -------------------------------------------------------------------------
   2. VOID / CANCELLATION REQUEST
   ------------------------------------------------------------------------- */
if (isset($_POST['process_ticket_action'])) {

    $target_id   = intval($_POST['ticket_id'] ?? 0);
    $reason      = trim($_POST['action_reason'] ?? '');
    $guard_name  = trim($_POST['requested_by'] ?? '');
    $action_type = $_POST['action_type'] ?? '';

    if ($guard_name === '') { $guard_name = 'Security Officer'; }

    $allowed = ['VOID_REQUESTED', 'CANCEL_REQUESTED'];

    if ($target_id <= 0 || $reason === '') {
        $_SESSION['flash_error'] = "කරුණාකර අදාළ හේතුව සටහන් කරන්න!";

    } elseif (!in_array($action_type, $allowed, true)) {
        $_SESSION['flash_error'] = "වලංගු නොවන Action එකකි!";

    } else {
        $new_status = $action_type;
        $log_msg = ($new_status === 'VOID_REQUESTED')
            ? "Void (Mistake/No-Show) requested for Ticket ID: $target_id. Reason: $reason"
            : "Fee Cancellation requested for Ticket ID: $target_id. Reason: $reason";

        $stmt = $conn->prepare(
            "UPDATE tickets
                SET status = ?, action_reason = ?, requested_by = ?, requested_at = NOW()
              WHERE id = ? AND status = 'PARKED'"
        );
        $stmt->bind_param("sssi", $new_status, $reason, $guard_name, $target_id);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        if ($changed > 0) {
            park_audit($conn, $guard_name, $log_msg);
            $_SESSION['flash_success'] = "ඉල්ලීම සාර්ථකයි! Parking Slot එක නිදහස් වූ අතර Admin Review වෙත යොමු විය.";
        } else {
            $_SESSION['flash_error'] = "මෙම ටිකට්පත දැනටමත් PARKED තත්ත්වයේ නොමැත. Request එක යැවීමට නොහැකි විය.";
        }
    }

    header("Location: dashboard.php#parkedTableSection");
    exit;
}

include 'includes/header.php';
?>

<!-- Libraries -->
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@4/dist/tesseract.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

<style>
    :root {
        --primary: #FF6B00;
        --primary-dark: #E05D00;
        --primary-light: #FFF1E6;
        --accent-orange: #FF6B00;
        --orange-gradient: linear-gradient(135deg, #FF6B00, #FF8800);
        --accent-blue: #1E3A8A;
        --dark-navy: #0A192F;
        --bg-main: #F4F7FA;
        --card-bg: #ffffff;
        --text-dark: #0A192F;
        --text-muted: #64748b;
        --border: #e2e8f0;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
        --shadow-md: 0 6px 20px rgba(0,0,0,0.03);
        --shadow-lg: 0 10px 30px rgba(0,0,0,0.05);
    }

    body {
        background-color: var(--bg-main);
        color: var(--text-dark);
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
    }

    /* Terminal Header Bar */
    .terminal-header {
        background: linear-gradient(135deg, var(--dark-navy), var(--accent-blue));
        color: #FFFFFF;
        border-radius: 20px;
        padding: 24px 30px;
        margin-bottom: 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        box-shadow: 0 10px 30px rgba(10, 25, 47, 0.15);
        flex-wrap: wrap;
        gap: 16px;
    }
    .terminal-info { display: flex; align-items: center; gap: 16px; }
    .terminal-avatar {
        width: 55px; height: 55px;
        background: var(--orange-gradient);
        color: #FFFFFF;
        border-radius: 16px;
        display: flex; align-items: center; justify-content: center;
        font-size: 24px;
        box-shadow: 0 4px 15px rgba(255, 107, 0, 0.4);
    }
    .live-clock-badge {
        background: rgba(255, 255, 255, 0.12);
        color: #FFFFFF;
        padding: 8px 18px; border-radius: 30px;
        font-size: 13px; font-weight: 600;
        border: 1px solid rgba(255,255,255,0.2);
        display: flex; align-items: center; gap: 8px;
    }

    /* Category Cards */
    .slots-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px; margin-bottom: 30px;
    }
    .slot-card {
        background: var(--card-bg); border-radius: 20px; padding: 22px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow-md);
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .slot-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
    .slot-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
    .slot-card-title { font-weight: 700; color: var(--text-dark); font-size: 15px; display: flex; align-items: center; gap: 10px; }
    .slot-card-title i { color: var(--primary); font-size: 18px; }
    .slot-progress-bg { background: var(--border); height: 8px; border-radius: 10px; overflow: hidden; margin-top: 12px; }
    .slot-progress-fill { height: 100%; border-radius: 10px; transition: width 0.5s ease-in-out; }

    /* Entry Card Panel */
    .entry-card {
        background: var(--card-bg); border-radius: 24px; padding: 32px;
        box-shadow: var(--shadow-lg); max-width: 650px;
        margin: 0 auto 32px auto; border: 1px solid var(--border);
        position: relative; overflow: hidden;
    }
    .entry-card::before {
        content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 6px;
        background: linear-gradient(90deg, var(--primary), #FF9E00);
    }

    .plate-input-container { position: relative; margin-bottom: 6px; }
    .plate-input-wrapper { position: relative; display: flex; align-items: center; }
    .plate-input-wrapper i.fa-car-side {
        position: absolute; left: 16px; color: var(--text-muted); font-size: 18px; z-index: 2;
    }
    .plate-input {
        width: 100%; padding: 16px 130px 16px 48px; border: 2px solid var(--border);
        border-radius: 16px; font-size: 18px; font-weight: 700; letter-spacing: 1px;
        background: var(--bg-main); color: var(--text-dark); text-transform: uppercase;
        transition: all 0.2s;
    }
    .plate-input:focus { border-color: var(--primary); background: #ffffff; outline: none; box-shadow: 0 0 0 4px rgba(255, 107, 0, 0.15); }

    .btn-scan-trigger {
        position: absolute; right: 6px; background: var(--accent-blue); color: #fff;
        border: none; padding: 10px 16px; border-radius: 10px; font-weight: 600;
        font-size: 12px; cursor: pointer; display: flex; align-items: center; gap: 6px;
        transition: 0.2s; z-index: 2;
    }
    .btn-scan-trigger:hover { background: var(--dark-navy); }

    .format-hint { font-size: 12px; color: var(--text-muted); margin-bottom: 20px; display: block; }

    .scanner-box {
        display: none; background: #000; border-radius: 12px; overflow: hidden;
        margin-bottom: 20px; position: relative; text-align: center; border: 2px solid var(--primary);
    }
    .scanner-box video { width: 100%; max-height: 240px; object-fit: cover; }
    .scanner-overlay {
        position: absolute; top: 0; left: 0; width: 100%; height: 100%;
        border: 2px dashed var(--primary); box-sizing: border-box; pointer-events: none;
    }
    .scan-status {
        position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%);
        background: rgba(0,0,0,0.8); color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 11px;
    }
    .btn-close-scanner {
        position: absolute; top: 8px; right: 8px; background: rgba(239, 68, 68, 0.9);
        color: white; border: none; width: 28px; height: 28px; border-radius: 50%;
        cursor: pointer; font-weight: bold; z-index: 10;
    }

    /* Vehicle Types Selector */
    .vehicle-selector { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; margin-bottom: 24px; }
    .vehicle-option input[type="radio"] { position: absolute; opacity: 0; }
    .vehicle-box {
        border: 2px solid var(--border); border-radius: 14px; padding: 16px 8px;
        text-align: center; cursor: pointer; background: var(--bg-main); transition: 0.2s;
    }
    .vehicle-box i { font-size: 22px; color: var(--text-muted); margin-bottom: 6px; display: block; transition: 0.2s; }
    .vehicle-option input[type="radio"]:checked + .vehicle-box {
        border-color: var(--primary); background: var(--primary-light);
        box-shadow: 0 4px 14px rgba(255, 107, 0, 0.15);
        transform: translateY(-2px);
    }
    .vehicle-option input[type="radio"]:checked + .vehicle-box i { color: var(--primary); transform: scale(1.05); }
    .vehicle-option input[value="vip"]:checked + .vehicle-box { border-color: var(--warning); background: #fffbeb; }
    .vehicle-option input[value="vip"]:checked + .vehicle-box i { color: var(--warning); }

    .btn-issue {
        width: 100%; background: var(--orange-gradient);
        color: white; border: none; padding: 16px; border-radius: 16px; font-size: 16px; font-weight: 700;
        cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
        transition: 0.3s; box-shadow: 0 8px 25px rgba(255, 107, 0, 0.3);
    }
    .btn-issue:hover { transform: translateY(-2px); }

    /* Ticket Display */
    .ticket-display {
        background: var(--card-bg); border: 1px solid var(--border); border-radius: 20px;
        padding: 28px; max-width: 420px; margin: 0 auto 32px auto; text-align: center; color: var(--text-dark);
        box-shadow: var(--shadow-lg);
    }
    .ticket-badge {
        background: rgba(16, 185, 129, 0.1); color: var(--success);
        padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700;
        display: inline-flex; align-items: center; gap: 6px; margin-bottom: 12px;
    }
    .vip-badge {
        background: rgba(245, 158, 11, 0.1); color: var(--warning);
        padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700;
        display: inline-flex; align-items: center; gap: 6px; margin-bottom: 12px; margin-left: 6px;
    }
    .ticket-info-grid { background: var(--bg-main); border-radius: 12px; padding: 14px; text-align: left; margin: 16px 0; border: 1px solid var(--border); }
    .ticket-info-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px; }
    .ticket-info-row:last-child { margin-bottom: 0; }

    /* Action Box */
    .cancel-sec-box {
        background: var(--bg-main); border-radius: 14px; padding: 16px; margin-top: 20px; text-align: left; border: 1px solid var(--border);
    }
    .action-btn-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
    .btn-action-card {
        padding: 10px 12px; border-radius: 10px; border: 1px solid var(--border); cursor: pointer; transition: 0.2s; text-align: left; background: #fff;
    }
    .btn-action-card .btn-title { font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 6px; }
    .btn-action-card .btn-subtitle { font-size: 10px; color: var(--text-muted); }
    .btn-card-void:hover { background: #fffbeb; border-color: var(--warning); }
    .btn-card-cancel:hover { background: #fef2f2; border-color: var(--danger); }

    .btn-print {
        width: 100%; background: var(--text-dark); color: white; font-size: 14px; padding: 12px; border-radius: 10px;
        font-weight: 600; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
    }

    /* Table Component Style */
    .parking-table-card {
        background: var(--card-bg); border-radius: 20px; padding: 24px;
        box-shadow: var(--shadow-sm); border: 1px solid var(--border); margin-top: 24px;
    }
    .table-header-flex {
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 16px; flex-wrap: wrap; gap: 12px;
    }
    .table-search-box { position: relative; width: 100%; max-width: 320px; }
    .table-search-box i {
        position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
        color: var(--text-muted); font-size: 14px;
    }
    .table-search-input {
        width: 100%; padding: 8px 12px 8px 36px; border: 1px solid var(--border);
        border-radius: 10px; font-size: 13px; background: var(--bg-main); transition: 0.2s;
    }
    .table-search-input:focus { border-color: var(--primary); outline: none; background: #FFF; }

    .custom-table { width: 100%; border-collapse: collapse; }
    .custom-table th {
        background: var(--bg-main); color: var(--text-muted); font-weight: 600;
        padding: 12px 14px; font-size: 12px; text-transform: uppercase; text-align: left;
        border-bottom: 1px solid var(--border);
    }
    .custom-table td {
        padding: 12px 14px; color: var(--text-dark); font-size: 13px;
        border-bottom: 1px solid var(--border); vertical-align: middle;
    }
    .custom-table tr:hover td { background: #f8fafc; }

    .btn-tbl-void {
        background: #fffbeb; color: var(--warning); border: 1px solid #fde68a;
        padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer;
    }
    .btn-tbl-void:hover { background: var(--warning); color: #fff; }

    .btn-tbl-cancel {
        background: #fef2f2; color: var(--danger); border: 1px solid #fecaca;
        padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer;
    }
    .btn-tbl-cancel:hover { background: var(--danger); color: #fff; }

    .pagination-footer {
        display: flex; justify-content: space-between; align-items: center;
        margin-top: 16px; font-size: 12px; color: var(--text-muted);
    }

    .alert-box {
        padding: 12px 16px; border-radius: 10px; margin-bottom: 20px;
        font-size: 13px; font-weight: 500; display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .alert-error { background: #fef2f2; color: var(--danger); border: 1px solid #fecaca; }
    .alert-success { background: #ecfdf5; color: var(--success); border: 1px solid #a7f3d0; }

    /* Modal Form Container */
    .table-action-card {
        background: var(--card-bg); border-radius: 16px; border: 1px solid var(--border);
        box-shadow: var(--shadow-lg); margin-top: 20px; overflow: hidden;
    }
    .action-modal-top { padding: 16px 20px; color: #fff; }
    .action-modal-top.modal-void { background: var(--warning); }
    .action-modal-top.modal-cancel { background: var(--danger); }
    .action-modal-title { margin: 0; color: #fff !important; font-size: 15px; font-weight: 600; }
    .action-modal-body { padding: 20px; }
    .action-ticket-preview {
        display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 12px;
        margin-bottom: 16px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg-main);
    }
    .action-form-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 12px; }
    .action-modal-input {
        width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px;
        font-size: 13px; outline: none; background: #fff;
    }
    .action-modal-footer {
        display: flex; align-items: center; justify-content: flex-end; gap: 8px; padding: 12px 20px;
        background: var(--bg-main); border-top: 1px solid var(--border);
    }
    .action-btn-submit { padding: 8px 16px; border-radius: 8px; font-size: 12px; font-weight: 600; border: none; color: #fff; cursor: pointer; }
    .action-btn-submit.void-submit { background: var(--warning); }
    .action-btn-submit.cancel-submit { background: var(--danger); }

    @media print {
        body * { visibility: hidden; }
        #printableTicket, #printableTicket * { visibility: visible; }
        #printableTicket { position: absolute; left: 0; top: 0; width: 100%; border: none; box-shadow: none; }
        .no-print { display: none !important; }
    }
</style>

<!-- Terminal Modern Header -->
<div class="terminal-header no-print">
    <div class="terminal-info">
        <div class="terminal-avatar"><i class="fa-solid fa-square-parking"></i></div>
        <div>
            <h2 style="font-size: 18px; font-weight: 700; margin: 0;">ParkSmart Terminal</h2>
            <p style="font-size: 13px; color: rgba(255,255,255,0.75);"> Checkout & Gate Control</p>
        </div>
    </div>
    <div class="live-clock-badge">
        <i class="fa-regular fa-clock" style="color: var(--primary);"></i>
        <span id="liveClock"><?php echo date('Y-m-d H:i:s'); ?></span>
    </div>
</div>

<!-- Alert Messages -->
<?php if ($error_msg !== ''): ?>
    <div class="alert-box alert-error no-print">
        <i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_msg; ?>
    </div>
<?php endif; ?>

<?php if ($success_msg !== '' && !$ticket_generated): ?>
    <div class="alert-box alert-success no-print">
        <i class="fa-solid fa-circle-check"></i> <?php echo $success_msg; ?>
    </div>
<?php endif; ?>

<!-- PARKING CATEGORY CARDS -->
<div class="slots-grid no-print">
    <?php
    $counts = [];
    $cnt_res = $conn->query("SELECT vehicle_type_id, COUNT(*) AS c FROM tickets WHERE status = 'PARKED' AND is_vip = 0 GROUP BY vehicle_type_id");
    while ($c = $cnt_res->fetch_assoc()) {
        $counts[(int) $c['vehicle_type_id']] = (int) $c['c'];
    }

    $types_res = $conn->query("SELECT * FROM vehicle_types ORDER BY id ASC");
    while ($t_row = $types_res->fetch_assoc()):
        $t_id    = (int) $t_row['id'];
        $t_name  = $t_row['type_name'];
        $p_count = isset($counts[$t_id]) ? $counts[$t_id] : 0;
        $limit   = park_limit($t_name);

        $percentage = $limit > 0 ? min(100, round(($p_count / $limit) * 100)) : 0;
        $bar_color  = "var(--success)";
        if ($percentage >= 80)      { $bar_color = "var(--danger)"; }
        elseif ($percentage >= 50)  { $bar_color = "var(--warning)"; }

        $icon      = park_icon($t_name);
        $remaining = max(0, $limit - $p_count);
    ?>
    <div class="slot-card">
        <div class="slot-card-header">
            <span class="slot-card-title">
                <i class="fa-solid <?php echo $icon; ?>"></i>
                <?php echo htmlspecialchars($t_name); ?>
            </span>
            <span style="font-size: 12px; font-weight: 700; color: <?php echo $bar_color; ?>;">
                <?php echo $p_count; ?> / <?php echo $limit; ?>
            </span>
        </div>
        <div style="font-size: 11px; color: var(--text-muted); display: flex; justify-content: space-between;">
            <span>ඉතිරි Slots:</span>
            <strong style="color: var(--text-dark);"><?php echo $remaining; ?></strong>
        </div>
        <div class="slot-progress-bg">
            <div class="slot-progress-fill" style="width: <?php echo $percentage; ?>%; background: <?php echo $bar_color; ?>;"></div>
        </div>
    </div>
    <?php endwhile; ?>

    <?php
    $vip_legacy_res = $conn->query("SELECT COUNT(*) AS c FROM tickets WHERE status='PARKED' AND is_vip=1");
    $vip_session_res = $conn->query("SELECT COUNT(*) AS c FROM vip_parking_sessions WHERE status='PARKED'");
    $vip_count = (int)($vip_legacy_res->fetch_assoc()['c'] ?? 0) + (int)($vip_session_res->fetch_assoc()['c'] ?? 0);
    $vip_limit   = isset($category_limits['vip']) ? $category_limits['vip'] : 5;

    $vip_percentage = $vip_limit > 0 ? min(100, round(($vip_count / $vip_limit) * 100)) : 0;
    $vip_bar_color  = "var(--success)";
    if ($vip_percentage >= 80)      { $vip_bar_color = "var(--danger)"; }
    elseif ($vip_percentage >= 50)  { $vip_bar_color = "var(--warning)"; }
    $vip_remaining = max(0, $vip_limit - $vip_count);
    ?>
    <div class="slot-card">
        <div class="slot-card-header">
            <span class="slot-card-title">
                <i class="fa-solid fa-crown" style="color: var(--warning);"></i>
                VIP Zone
            </span>
            <span style="font-size: 12px; font-weight: 700; color: <?php echo $vip_bar_color; ?>;">
                <?php echo $vip_count; ?> / <?php echo $vip_limit; ?>
            </span>
        </div>
        <div style="font-size: 11px; color: var(--text-muted); display: flex; justify-content: space-between;">
            <span>ඉතිරි Slots:</span>
            <strong style="color: var(--text-dark);"><?php echo $vip_remaining; ?></strong>
        </div>
        <div class="slot-progress-bg">
            <div class="slot-progress-fill" style="width: <?php echo $vip_percentage; ?>%; background: <?php echo $vip_bar_color; ?>;"></div>
        </div>
    </div>
</div>

<?php if ($vip_entry_created): ?>
<!-- VIP CONFIRMATION -->
<div class="ticket-display" id="vipEntryConfirmation">
    <div class="ticket-badge" style="background:rgba(245,158,11,0.1);color:var(--warning);">
        <i class="fa-solid fa-circle-check"></i> VIP AUTHORIZED
    </div>
    <h3 style="margin: 4px 0; font-weight: 700;">VIP ENTRY REGISTERED</h3>
    <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 12px;">No ticket issued for this vehicle.</p>

    <div class="ticket-info-grid">
        <div class="ticket-info-row"><span>Vehicle No:</span><strong><?php echo htmlspecialchars($vip_entry_data['v_num']); ?></strong></div>
        <div class="ticket-info-row"><span>Slot:</span><strong style="color:var(--warning);"><?php echo htmlspecialchars($vip_entry_data['slot']); ?></strong></div>
        <div class="ticket-info-row"><span>Entry Time:</span><span><?php echo date('Y-m-d | h:i A', strtotime($vip_entry_data['time'])); ?></span></div>
        <div class="ticket-info-row"><span>Fee:</span><strong style="color:var(--success);">FREE</strong></div>
    </div>

    <div class="no-print" style="margin-top:16px;">
        <a href="dashboard.php" class="btn-print" style="text-decoration:none;">
            Next Vehicle
        </a>
    </div>
</div>

<?php elseif (!$ticket_generated): ?>
<!-- ENTRY FORM -->
<div class="entry-card no-print">
    <div style="margin-bottom: 20px;">
        <h3 style="font-size: 16px; font-weight: 700; color: var(--text-dark); margin: 0 0 4px 0;"><i class="fa-solid fa-ticket" style="color: var(--primary);"></i> Issue Entry Ticket</h3>
        <p style="font-size: 12px; color: var(--text-muted); margin: 0;">වාහන කාණ්ඩය හා ලියාපදිංචි අංකය ඇතුළත් කරන්න.</p>
    </div>

    <form action="dashboard.php" method="POST" id="entryForm" onsubmit="return validatePlateFormat();">
        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 8px;">1. Select Vehicle Category:</label>
        <div class="vehicle-selector">
            <?php
            $res   = $conn->query("SELECT * FROM vehicle_types ORDER BY id ASC");
            $first = true;
            while ($row = $res->fetch_assoc()):
                $cat_type = park_category_key($row['type_name']);
                $v_icon   = park_icon($row['type_name']);
            ?>
            <label class="vehicle-option">
                <input type="radio" name="vehicle_type" value="<?php echo (int) $row['id']; ?>" data-cat="<?php echo $cat_type; ?>" <?php if ($first) { echo "checked"; $first = false; } ?> onchange="updatePlaceholder();">
                <div class="vehicle-box">
                    <i class="fa-solid <?php echo $v_icon; ?>"></i>
                    <span style="font-size: 12px; font-weight: 600; display: block; color: var(--text-dark);"><?php echo htmlspecialchars($row['type_name']); ?></span>
                    <small style="color: var(--primary); font-weight: 600;">LKR <?php echo number_format($row['hourly_rate'], 0); ?></small>
                </div>
            </label>
            <?php endwhile; ?>

            <label class="vehicle-option">
                <input type="radio" name="vehicle_type" value="vip" data-cat="vip" onchange="updatePlaceholder();">
                <div class="vehicle-box">
                    <i class="fa-solid fa-crown" style="color: var(--warning);"></i>
                    <span style="font-size: 12px; font-weight: 600; display: block; color: var(--text-dark);">VIP</span>
                    <small style="color: var(--warning); font-weight: 600;">FREE</small>
                </div>
            </label>
        </div>

        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">2. Vehicle Number Plate:</label>
        <div class="plate-input-container">
            <div class="plate-input-wrapper">
                <i class="fa-solid fa-car-side"></i>
                <input type="text" id="vehicle_number" name="vehicle_number" class="plate-input" placeholder="e.g. WP CAB-1234" required autofocus autocomplete="off">
                <button type="button" class="btn-scan-trigger" onclick="startCameraScanner()">
                    <i class="fa-solid fa-camera"></i> Scan
                </button>
            </div>
            <span class="format-hint" id="formatHint">Formats: Modern (WP CAB-1234) or Vintage (19-1234)</span>
        </div>

        <div class="scanner-box" id="scannerBox">
            <button type="button" class="btn-close-scanner" onclick="stopCameraScanner()">&times;</button>
            <video id="scannerVideo" autoplay playsinline muted></video>
            <div class="scanner-overlay"></div>
            <div class="scan-status" id="scanStatus"><i class="fa-solid fa-spinner fa-spin"></i> Camera Active...</div>
        </div>

        <button type="submit" name="generate_ticket" class="btn-issue" id="entrySubmitBtn">
            <i class="fa-solid fa-print" id="entrySubmitIcon"></i>
            <span id="entrySubmitText">Generate &amp; Print Ticket</span>
        </button>
    </form>
</div>

<?php else: ?>

<!-- TICKET GENERATED DISPLAY -->
<div class="ticket-display" id="printableTicket">
    <div class="ticket-badge"><i class="fa-solid fa-circle-check"></i> TICKET CREATED</div>
    <?php if (!empty($ticket_data['is_vip'])): ?>
        <div class="vip-badge"><i class="fa-solid fa-crown"></i> VIP</div>
    <?php endif; ?>
    <h3 style="margin: 4px 0 2px 0; font-weight: 700;">PARK SMART</h3>
    <p style="font-size: 11px; color: var(--text-muted); margin-bottom: 12px;"></p>

    <div class="ticket-info-grid">
        <div class="ticket-info-row"><span>Code:</span><strong style="color: var(--primary);"><?php echo htmlspecialchars($ticket_data['code']); ?></strong></div>
        <div class="ticket-info-row"><span>Plate:</span><strong><?php echo htmlspecialchars($ticket_data['v_num']); ?></strong></div>
        <div class="ticket-info-row"><span>Category:</span><span><?php echo htmlspecialchars($ticket_data['v_type']); ?></span></div>
        <div class="ticket-info-row">
            <span>Rate:</span>
            <span>
                <?php if (!empty($ticket_data['is_vip'])): ?>
                    <strong style="color: var(--success);">FREE</strong>
                <?php else: ?>
                    LKR <?php echo number_format($ticket_data['rate'], 2); ?>
                <?php endif; ?>
            </span>
        </div>
        <div class="ticket-info-row"><span>Entry Time:</span><span><?php echo date('Y-m-d | h:i A', strtotime($ticket_data['time'])); ?></span></div>
    </div>

    <div id="qrcode" style="display: flex; justify-content: center; margin: 12px 0;"></div>

    <div class="no-print" style="margin-top: 16px;">
        <button onclick="window.print();" class="btn-print">
            <i class="fa-solid fa-print"></i> Print Ticket
        </button>

        <div class="cancel-sec-box">
            <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Guard Action</div>
            <div class="action-btn-grid">
                <button type="button" class="btn-action-card btn-card-void" onclick="triggerActionModal(<?php echo (int) $ticket_data['id']; ?>, 'VOID_REQUESTED', '<?php echo htmlspecialchars($ticket_data['code']); ?>', '<?php echo htmlspecialchars($ticket_data['v_num']); ?>')">
                    <span class="btn-title"><i class="fa-solid fa-ban"></i> Void</span>
                    <span class="btn-subtitle">Mistake Entry</span>
                </button>

                <button type="button" class="btn-action-card btn-card-cancel" onclick="triggerActionModal(<?php echo (int) $ticket_data['id']; ?>, 'CANCEL_REQUESTED', '<?php echo htmlspecialchars($ticket_data['code']); ?>', '<?php echo htmlspecialchars($ticket_data['v_num']); ?>')">
                    <span class="btn-title"><i class="fa-solid fa-xmark"></i> Waive</span>
                    <span class="btn-subtitle">Cancel Fee</span>
                </button>
            </div>
        </div>
    </div>

    <div class="no-print" style="margin-top: 16px;">
        <a href="dashboard.php" style="font-size: 12px; font-weight: 600; color: var(--text-muted); text-decoration: none;">
            <i class="fa-solid fa-arrow-left"></i> Back to Terminal
        </a>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        var box = document.getElementById("qrcode");
        if (box && typeof QRCode !== "undefined") {
            new QRCode(box, {
                text: <?php echo json_encode($ticket_data['code']); ?>,
                width: 100,
                height: 100
            });
        }
    });
</script>

<?php endif; ?>

<!-- CURRENTLY PARKED VEHICLES TABLE WITH PAGINATION & CLIENT SEARCH -->
<div class="parking-table-card no-print" id="parkedTableSection">
    <?php
    $active_vehicles = $conn->query(
        "SELECT t.*, vt.type_name
           FROM tickets t
           JOIN vehicle_types vt ON t.vehicle_type_id = vt.id
          WHERE t.status = 'PARKED'
          ORDER BY t.entry_time DESC"
    );
    $active_vip_vehicles = $conn->query(
        "SELECT v.*, 'VIP Vehicle' AS type_name
           FROM vip_parking_sessions v
          WHERE v.status = 'PARKED'
          ORDER BY v.entry_time DESC"
    );
    $regular_parked_count = $active_vehicles ? $active_vehicles->num_rows : 0;
    $vip_parked_count = $active_vip_vehicles ? $active_vip_vehicles->num_rows : 0;
    $total_parked = $regular_parked_count + $vip_parked_count;
    ?>
    <div class="table-header-flex">
        <div>
            <h3 style="font-size: 15px; color: var(--text-dark); font-weight: 700; margin: 0 0 2px 0;">
                <i class="fa-solid fa-square-parking" style="color: var(--primary);"></i> Parked Vehicles
            </h3>
            <span style="font-size: 12px; color: var(--text-muted);">Search and view active sessions.</span>
        </div>

        <div style="display: flex; align-items: center; gap: 10px;">
            <div class="table-search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="parkedSearchInput" class="table-search-input" placeholder="Search plate or code..." onkeyup="filterParkedTable()">
            </div>
            <span style="background: var(--primary); color: white; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;">
                <?php echo $total_parked; ?> Active
            </span>
        </div>
    </div>

    <div style="overflow-x: auto;">
        <table class="custom-table" id="parkedTable">
            <thead>
                <tr>
                    <th>Slot</th>
                    <th>Ticket Code</th>
                    <th>Vehicle Plate</th>
                    <th>Category</th>
                    <th>Entry Time</th>
                    <th>Status</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                    $slot_index = 1;
                    while ($active_vehicles && ($row = $active_vehicles->fetch_assoc())):
                ?>
                <tr class="parked-row">
                    <td>
                        <span style="background: var(--bg-main); color: var(--text-dark); padding: 2px 8px; border-radius: 6px; font-weight: 600; font-size: 11px; border: 1px solid var(--border);">
                            S-<?php echo sprintf('%02d', $slot_index++); ?>
                        </span>
                    </td>
                    <td>
                        <strong style="color: var(--primary); font-size: 13px;"><?php echo htmlspecialchars($row['ticket_code']); ?></strong>
                    </td>
                    <td style="text-transform: uppercase; font-weight: 700; font-size: 13px;"><?php echo htmlspecialchars($row['vehicle_number']); ?></td>
                    <td><i class="fa-solid <?php echo park_row_icon($row['type_name'], $row['is_vip']); ?>" style="color: var(--text-muted); margin-right: 4px;"></i><?php echo htmlspecialchars($row['type_name']); ?></td>
                    <td><?php echo date('Y-m-d | h:i A', strtotime($row['entry_time'])); ?></td>
                    <td>
                        <span style="color: var(--success); font-size: 11px; font-weight: 700;">
                            PARKED
                        </span>
                    </td>
                    <td style="text-align: right;">
                        <button type="button" class="btn-tbl-void" onclick="triggerActionModal(<?php echo (int) $row['id']; ?>, 'VOID_REQUESTED', '<?php echo htmlspecialchars($row['ticket_code']); ?>', '<?php echo htmlspecialchars($row['vehicle_number']); ?>')">
                            Void
                        </button>
                        <button type="button" class="btn-tbl-cancel" onclick="triggerActionModal(<?php echo (int) $row['id']; ?>, 'CANCEL_REQUESTED', '<?php echo htmlspecialchars($row['ticket_code']); ?>', '<?php echo htmlspecialchars($row['vehicle_number']); ?>')">
                            Waive
                        </button>
                    </td>
                </tr>
                <?php endwhile; ?>

                <?php if ($vip_parked_count > 0):
                    while ($vip = $active_vip_vehicles->fetch_assoc()):
                ?>
                <tr class="parked-row">
                    <td>
                        <span style="background: #fffbeb; color: var(--warning); padding: 2px 8px; border-radius: 6px; font-weight: 600; font-size: 11px; border: 1px solid #fde68a;">
                            <?php echo htmlspecialchars($vip['slot_label'] ?: 'VIP'); ?>
                        </span>
                    </td>
                    <td><strong style="color: var(--warning); font-size: 12px;">VIP SESSION</strong></td>
                    <td style="text-transform: uppercase; font-weight: 700; font-size: 13px;"><?php echo htmlspecialchars($vip['vehicle_number']); ?></td>
                    <td><i class="fa-solid fa-crown" style="color: var(--warning); margin-right: 4px;"></i>VIP</td>
                    <td><?php echo date('Y-m-d | h:i A', strtotime($vip['entry_time'])); ?></td>
                    <td><span style="color: var(--warning); font-size: 11px; font-weight: 700;">VIP PARKED</span></td>
                    <td style="text-align: right;"><span style="font-size: 11px; color: var(--text-muted);">Exit via Plate</span></td>
                </tr>
                <?php endwhile; endif; ?>

                <?php if ($total_parked === 0): ?>
                <tr id="noDataRow">
                    <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 24px;">
                        දැනට Parking එකෙහි වාහන කිසිවක් නොමැත.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination-footer">
        <span id="showingRecordCount">Showing entries</span>
        <div style="display: flex; gap: 4px;" id="paginationBtns"></div>
    </div>

    <!-- ACTION FORM CARD -->
    <div id="tableActionBox" class="table-action-card" style="display: none;">
        <form method="POST" action="dashboard.php">
            <div class="action-modal-top modal-void" id="modalHeaderBg">
                <button type="button" onclick="closeActionForm()" style="float: right; background: none; border: none; color: white; cursor: pointer; font-size: 16px;">&times;</button>
                <h5 class="action-modal-title" id="modalActionTitle">ටිකට් අවලංගු කිරීමේ ඉල්ලීම</h5>
            </div>

            <div class="action-modal-body">
                <input type="hidden" name="ticket_id" id="modal_ticket_id">
                <input type="hidden" name="action_type" id="modal_action_type">

                <div class="action-ticket-preview">
                    <div>
                        <span style="font-size: 10px; color: var(--text-muted); display: block;">TICKET CODE</span>
                        <strong id="modal_preview_ticket" style="color: var(--primary);">—</strong>
                    </div>
                    <div>
                        <span style="font-size: 10px; color: var(--text-muted); display: block;">VEHICLE NUMBER</span>
                        <strong id="modal_preview_vehicle">—</strong>
                    </div>
                </div>

                <div class="action-form-grid">
                    <div>
                        <label style="font-size: 12px; font-weight: 600; display: block; margin-bottom: 4px;">Guard Name / ID *</label>
                        <input type="text" name="requested_by" id="modal_requested_by" class="action-modal-input" required autocomplete="off">
                    </div>
                    <div>
                        <label style="font-size: 12px; font-weight: 600; display: block; margin-bottom: 4px;">Reason *</label>
                        <input type="text" name="action_reason" id="modal_action_reason" class="action-modal-input" required autocomplete="off">
                    </div>
                </div>
            </div>

            <div class="action-modal-footer">
                <button type="button" onclick="closeActionForm()" style="background: none; border: none; font-size: 12px; color: var(--text-muted); cursor: pointer; padding: 6px 12px;">Cancel</button>
                <button type="submit" name="process_ticket_action" id="modalSubmitBtn" class="action-btn-submit void-submit">
                    <span id="modalSubmitText">Submit Request</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    let currentPage = 1;
    const recordsPerPage = 2; // Data set 2 with pagination

    function triggerActionModal(ticketId, actionType, ticketCode, vehicleNumber) {
        const actionBox = document.getElementById('tableActionBox');
        document.getElementById('modal_ticket_id').value = ticketId;
        document.getElementById('modal_action_type').value = actionType;

        document.getElementById('modal_preview_ticket').textContent = ticketCode || '—';
        document.getElementById('modal_preview_vehicle').textContent = vehicleNumber || '—';

        const headerBg = document.getElementById('modalHeaderBg');
        const title = document.getElementById('modalActionTitle');
        const submitBtn = document.getElementById('modalSubmitBtn');

        if (actionType === 'VOID_REQUESTED') {
            headerBg.className = 'action-modal-top modal-void';
            title.textContent = 'Void Ticket Request';
            submitBtn.className = 'action-btn-submit void-submit';
        } else {
            headerBg.className = 'action-modal-top modal-cancel';
            title.textContent = 'Fee Waiver Request';
            submitBtn.className = 'action-btn-submit cancel-submit';
        }

        actionBox.style.display = 'block';
        actionBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function closeActionForm() {
        document.getElementById('tableActionBox').style.display = 'none';
    }

    function filterParkedTable() {
        currentPage = 1;
        renderPaginatedTable();
    }

    function renderPaginatedTable() {
        const inputEl = document.getElementById('parkedSearchInput');
        const input = inputEl ? inputEl.value.toUpperCase().trim() : '';
        const rows = Array.from(document.querySelectorAll('#parkedTable tbody tr.parked-row'));
        const recordCounter = document.getElementById('showingRecordCount');
        const paginationBtns = document.getElementById('paginationBtns');

        let filteredRows = rows.filter(row => {
            return input === '' || row.innerText.toUpperCase().includes(input);
        });

        rows.forEach(row => row.style.display = 'none');

        const totalPages = Math.ceil(filteredRows.length / recordsPerPage) || 1;
        if (currentPage > totalPages) currentPage = totalPages;

        const start = (currentPage - 1) * recordsPerPage;
        const end = start + recordsPerPage;
        const pageRows = filteredRows.slice(start, end);

        pageRows.forEach(row => row.style.display = '');

        if (recordCounter) {
            recordCounter.textContent = `Showing ${filteredRows.length > 0 ? start + 1 : 0}-${Math.min(end, filteredRows.length)} of ${filteredRows.length} entries`;
        }

        // Render Pagination Control
        if (paginationBtns) {
            paginationBtns.innerHTML = '';
            for (let i = 1; i <= totalPages; i++) {
                const btn = document.createElement('button');
                btn.textContent = i;
                btn.style.padding = '4px 8px';
                btn.style.border = '1px solid var(--border)';
                btn.style.borderRadius = '4px';
                btn.style.background = (i === currentPage) ? 'var(--primary)' : '#fff';
                btn.style.color = (i === currentPage) ? '#fff' : 'var(--text-dark)';
                btn.style.cursor = 'pointer';
                btn.style.fontSize = '11px';
                btn.onclick = function() {
                    currentPage = i;
                    renderPaginatedTable();
                };
                paginationBtns.appendChild(btn);
            }
        }
    }

    function updateClock() {
        const clockElem = document.getElementById('liveClock');
        if (!clockElem) return;
        const now = new Date();
        const p = (n) => String(n).padStart(2, '0');
        clockElem.textContent = `${now.getFullYear()}-${p(now.getMonth() + 1)}-${p(now.getDate())} `
            + `${p(now.getHours())}:${p(now.getMinutes())}:${p(now.getSeconds())}`;
    }
    setInterval(updateClock, 1000);

    function updatePlaceholder() {
        const selectedRadio = document.querySelector('input[name="vehicle_type"]:checked');
        const plateInput = document.getElementById('vehicle_number');
        const formatHint = document.getElementById('formatHint');

        if (!selectedRadio || !plateInput || !formatHint) return;
        const cat = selectedRadio.getAttribute('data-cat');

        if (cat === 'vip') {
            plateInput.placeholder = "e.g. WP CAB-1234";
            formatHint.textContent = "VIP: Must match APPROVED authorization.";
        } else if (cat === 'bike') {
            plateInput.placeholder = "e.g. WP AB-1234";
            formatHint.textContent = "Bike Formats: Modern or Vintage";
        } else {
            plateInput.placeholder = "e.g. WP CAB-1234";
            formatHint.textContent = "Car/Bus Formats: Modern or Vintage";
        }
    }

    function validatePlateFormat() {
        const selectedRadio = document.querySelector('input[name="vehicle_type"]:checked');
        const plateInput = document.getElementById('vehicle_number');
        if (!selectedRadio || !plateInput) return true;

        const plateVal = plateInput.value.trim().toUpperCase().replace(/\s+/g, ' ');
        plateInput.value = plateVal;

        const cat = selectedRadio.getAttribute('data-cat');
        const common = "([A-Z]{2}\\s)?[A-Z]{2,3}-\\d{4}|\\d{1,3}\\sSRI\\s\\d{4}|\\d{2,3}-\\d{4}";
        const regex = new RegExp("^(" + common + "|[A-Z]-\\d{4})$", "i");

        if (!regex.test(plateVal)) {
            alert("අවලංගු අංක තහඩු රටාවකි!");
            plateInput.focus();
            return false;
        }
        return true;
    }

    let videoStream = null;
    let scanInterval = null;
    let scanBusy = false;

    function startCameraScanner() {
        const scannerBox = document.getElementById('scannerBox');
        const video = document.getElementById('scannerVideo');
        const scanStatus = document.getElementById('scanStatus');

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert("කැමරා පහසුකම සහාය නොදක්වයි.");
            return;
        }

        scannerBox.style.display = 'block';
        scanStatus.innerHTML = 'Initializing Camera...';

        navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } })
            .then(function (stream) {
                videoStream = stream;
                video.srcObject = stream;
                scanStatus.innerHTML = 'Scanning plate...';
                scanInterval = setInterval(() => { captureAndScanFrame(video); }, 2500);
            })
            .catch(function () {
                alert("කැමරාව ආරම්භ කිරීමට නොහැකි විය.");
                stopCameraScanner();
            });
    }

    function stopCameraScanner() {
        const scannerBox = document.getElementById('scannerBox');
        if (videoStream) {
            videoStream.getTracks().forEach(track => track.stop());
            videoStream = null;
        }
        if (scanInterval) { clearInterval(scanInterval); scanInterval = null; }
        if (scannerBox) scannerBox.style.display = 'none';
        scanBusy = false;
    }

    function captureAndScanFrame(video) {
        if (!videoStream || scanBusy || !video.videoWidth) return;
        scanBusy = true;

        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

        Tesseract.recognize(canvas.toDataURL('image/png'), 'eng')
            .then(({ data: { text } }) => {
                const cleaned = text.replace(/[^A-Z0-9\s-]/gi, '').replace(/\s+/g, ' ').trim().toUpperCase();
                const match = cleaned.match(/(([A-Z]{2}\s)?[A-Z]{2,3}-\d{4}|\d{1,3}\sSRI\s\d{4}|\d{2,3}-\d{4})/);

                if (match) {
                    document.getElementById('vehicle_number').value = match[0];
                    document.getElementById('scanStatus').innerHTML = 'Plate Scanned!';
                    setTimeout(stopCameraScanner, 800);
                }
            })
            .catch(() => { })
            .finally(() => { scanBusy = false; });
    }

    document.addEventListener("DOMContentLoaded", function () {
        updatePlaceholder();
        renderPaginatedTable();
        updateClock();
    });
</script>

<?php include 'includes/footer.php'; ?>

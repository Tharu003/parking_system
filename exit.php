<?php
session_start();
require_once 'config/db.php';
require_once 'config/auth.php';
require_security_login();

$checkout_success=false;
$error_msg='';
$success_msg='';
$calculated_data=null;
$printed_ticket=null;
$printed_vip=null;

define('GRACE_PERIOD_MINUTES',15);
define('ADDITIONAL_HOUR_RATE',15.00);

function calculate_checkout(mysqli $conn,int $ticket_id,bool $lost): ?array {
    $stmt=$conn->prepare(
        "SELECT t.*,vt.type_name,vt.hourly_rate
         FROM tickets t
         JOIN vehicle_types vt ON t.vehicle_type_id=vt.id
         WHERE t.id=? AND t.status='PARKED' LIMIT 1"
    );
    $stmt->bind_param("i",$ticket_id);
    $stmt->execute();
    $t=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$t) return null;

    $entry=new DateTime($t['entry_time']);
    $now=new DateTime();
    $diff=$entry->diff($now);
    $minutes=($diff->days*1440)+($diff->h*60)+$diff->i;
    $hours=max(1,(int)ceil($minutes/60));
    $grace=$minutes<=GRACE_PERIOD_MINUTES&&!$lost;
    $extra_hours=max(0,$hours-1);
    $fee=$grace?0.0:(float)$t['hourly_rate']+($extra_hours*ADDITIONAL_HOUR_RATE);

    if((int)$t['is_vip']===1&&!$lost) $fee=0.0;

    return [
        'record_type'=>'TICKET',
        'ticket_id'=>(int)$t['id'],
        'ticket_code'=>$t['ticket_code'],
        'vehicle_number'=>$t['vehicle_number'],
        'category'=>$t['type_name'],
        'hourly_rate'=>(float)$t['hourly_rate'],
        'entry_time'=>$t['entry_time'],
        'exit_time'=>$now->format('Y-m-d H:i:s'),
        'total_minutes'=>$minutes,
        'duration_hours'=>$hours,
        'calculated_fee'=>$fee,
        'is_grace'=>$grace,
        'is_lost'=>$lost,
        'is_vip'=>(int)$t['is_vip']
    ];
}

function calculate_vip_checkout(mysqli $conn,int $session_id): ?array {
    $stmt=$conn->prepare(
        "SELECT * FROM vip_parking_sessions
         WHERE id=? AND status='PARKED' LIMIT 1"
    );
    $stmt->bind_param("i",$session_id);
    $stmt->execute();
    $v=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$v) return null;

    $entry=new DateTime($v['entry_time']);
    $now=new DateTime();
    $diff=$entry->diff($now);
    $minutes=($diff->days*1440)+($diff->h*60)+$diff->i;
    $hours=max(1,(int)ceil($minutes/60));

    return [
        'record_type'=>'VIP',
        'session_id'=>(int)$v['id'],
        'ticket_code'=>'',
        'vehicle_number'=>$v['vehicle_number'],
        'category'=>'VIP Vehicle',
        'hourly_rate'=>0.0,
        'entry_time'=>$v['entry_time'],
        'exit_time'=>$now->format('Y-m-d H:i:s'),
        'total_minutes'=>$minutes,
        'duration_hours'=>$hours,
        'calculated_fee'=>0.0,
        'is_grace'=>false,
        'is_lost'=>false,
        'is_vip'=>1,
        'slot_label'=>$v['slot_label'],
        'driver_name'=>$v['driver_name'],
        'reason'=>$v['reason']
    ];
}

/* ---------------------------------------------------------------
   SEARCH AT EXIT GATE
   - Ticket QR/code/plate -> normal ticket flow
   - VIP has NO ticket/QR -> plate lookup finds the active VIP session
   --------------------------------------------------------------- */
if(isset($_POST['search_ticket'])||isset($_POST['qr_ticket_code'])){
    $q=strtoupper(trim($_POST['search_query']??$_POST['qr_ticket_code']??''));
    $lost=!empty($_POST['is_lost_mode']);

    if($q!=='') {

        /* VIP plate is checked first when the input is a vehicle number. */
        $vip_stmt=$conn->prepare(
            "SELECT id FROM vip_parking_sessions
             WHERE vehicle_number=? AND status='PARKED' LIMIT 1"
        );
        $vip_stmt->bind_param("s",$q);
        $vip_stmt->execute();
        $vip_row=$vip_stmt->get_result()->fetch_assoc();
        $vip_stmt->close();

        if($vip_row) {
            $calculated_data=calculate_vip_checkout($conn,(int)$vip_row['id']);
        } else {
            $stmt=$conn->prepare(
                "SELECT id FROM tickets
                 WHERE (ticket_code=? OR vehicle_number=?) AND status='PARKED'
                 LIMIT 1"
            );
            $stmt->bind_param("ss",$q,$q);
            $stmt->execute();
            $row=$stmt->get_result()->fetch_assoc();
            $stmt->close();

            if($row) {
                $calculated_data=calculate_checkout($conn,(int)$row['id'],$lost);
            } else {
                $error_msg='No active parking record was found for the ticket code or vehicle number.';
            }
        }
    } else {
        $error_msg='Please enter a ticket code or vehicle number.';
    }
}

/* ---------------------------------------------------------------
   COMPLETE EXIT
   --------------------------------------------------------------- */
if(isset($_POST['process_checkout'])){
    $record_type=$_POST['record_type']??'TICKET';

    if($record_type==='VIP') {

        $session_id=(int)($_POST['session_id']??0);
        $calc=calculate_vip_checkout($conn,$session_id);

        if(!$calc) {
            $error_msg='This VIP vehicle is no longer active or has already exited.';
        } else {
            $exit=$calc['exit_time'];
            $minutes=$calc['total_minutes'];
            $guard_name=security_user_name();

            $up=$conn->prepare(
                "UPDATE vip_parking_sessions
                 SET exit_time=?,duration_minutes=?,status='COMPLETED',exited_by=?
                 WHERE id=? AND status='PARKED'"
            );
            $up->bind_param("sisi",$exit,$minutes,$guard_name,$session_id);

            if($up->execute()&&$up->affected_rows===1) {
                $checkout_success=true;
                $success_msg='VIP vehicle exit completed. No parking fee is payable.';
                security_audit(
                    $conn,
                    'VIP vehicle '.$calc['vehicle_number'].' exited. NO FEE. VIP Session ID: '.$session_id
                );

                $pr=$conn->prepare("SELECT * FROM vip_parking_sessions WHERE id=?");
                $pr->bind_param("i",$session_id);
                $pr->execute();
                $printed_vip=$pr->get_result()->fetch_assoc();
                $pr->close();
            } else {
                $error_msg='VIP checkout could not be completed. The record may have already been processed.';
            }
            $up->close();
        }

    } else {

        $ticket_id=(int)($_POST['ticket_id']??0);
        $lost=!empty($_POST['is_lost_mode']);
        $calc=calculate_checkout($conn,$ticket_id,$lost);

        if(!$calc) {
            $error_msg='This ticket is no longer active or has already been completed.';
        } else {
            $exit=$calc['exit_time'];
            $hours=$calc['duration_hours'];
            $fee=$calc['calculated_fee'];

            $up=$conn->prepare(
                "UPDATE tickets
                 SET exit_time=?,duration_hours=?,total_fee=?,status='COMPLETED'
                 WHERE id=? AND status='PARKED'"
            );
            $up->bind_param("sidi",$exit,$hours,$fee,$ticket_id);

            if($up->execute()&&$up->affected_rows===1){
                $checkout_success=true;
                $success_msg='Vehicle exit completed. Amount collected: LKR '.number_format($fee,2);

                security_audit(
                    $conn,
                    'Vehicle '.$calc['vehicle_number'].' exited. Ticket '.$calc['ticket_code']
                    .' | Fee LKR '.number_format($fee,2)
                );

                $pr=$conn->prepare(
                    "SELECT t.*,vt.type_name
                     FROM tickets t
                     JOIN vehicle_types vt ON t.vehicle_type_id=vt.id
                     WHERE t.id=?"
                );
                $pr->bind_param("i",$ticket_id);
                $pr->execute();
                $printed_ticket=$pr->get_result()->fetch_assoc();
                $pr->close();
            } else {
                $error_msg='Checkout could not be completed. Please try again.';
            }
            $up->close();
        }
    }
}

include 'includes/header.php';
?>

<!-- Html5 QR Code Scanner Library -->
<script src="https://unpkg.com/html5-qrcode"></script>

<style>
    :root {
        --app-bg: #F1EEFB;
        --card-bg: #FFFFFF;
        --text-color: #17123A;
        --sub-text: #6b6489;
        --accent-orange: #FF6B00;
        --orange-gradient: linear-gradient(135deg, #FF7A1A, #FFB800);
        --accent-blue: #4C3AF5;
        --dark-navy: #120B2E;
        --accent-red: #F0455F;
        --accent-green: #17C785;
        --border-color: #E6E1F7;
    }

    @keyframes bgDrift {
        0%   { background-position: 0% 50%; }
        50%  { background-position: 100% 50%; }
        100% { background-position: 0% 50%; }
    }
    @keyframes fadeSlideUp {
        from { opacity: 0; transform: translateY(14px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes popIn {
        0%   { opacity: 0; transform: scale(0.7); }
        70%  { opacity: 1; transform: scale(1.06); }
        100% { opacity: 1; transform: scale(1); }
    }
    @keyframes softPulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(23,199,133,0.45); }
        50%      { box-shadow: 0 0 0 8px rgba(23,199,133,0); }
    }
    @keyframes shimmer {
        0%   { background-position: -200px 0; }
        100% { background-position: calc(200px + 100%) 0; }
    }
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after { animation-duration: 0.001ms !important; animation-iteration-count: 1 !important; }
    }

    body {
        background-color: var(--app-bg);
        background-image:
            radial-gradient(circle at 15% 10%, rgba(76,58,245,0.10), transparent 40%),
            radial-gradient(circle at 85% 0%, rgba(255,122,26,0.10), transparent 38%);
    }

    /* Terminal Header Bar */
    .terminal-header {
        background: linear-gradient(135deg, var(--dark-navy), var(--accent-blue) 60%, #6B4CF6);
        background-size: 180% 180%;
        animation: fadeSlideUp 0.5s ease both, bgDrift 12s ease-in-out infinite;
        color: #FFFFFF;
        padding: 24px 30px;
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(23, 18, 58, 0.2);
        margin-bottom: 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }
    .terminal-info { display: flex; align-items: center; gap: 16px; }
    .exit-avatar {
        width: 55px; height: 55px;
        background: var(--orange-gradient);
        color: white; border-radius: 16px;
        display: flex; align-items: center; justify-content: center;
        font-size: 24px; box-shadow: 0 4px 15px rgba(255, 122, 26, 0.45);
        animation: popIn 0.5s ease 0.1s both;
    }
    .live-clock-badge {
        background: rgba(255, 255, 255, 0.14);
        padding: 8px 18px; border-radius: 30px;
        font-size: 13px; font-weight: 600;
        border: 1px solid rgba(255,255,255,0.22);
        display: flex; align-items: center; gap: 8px;
        animation: fadeSlideUp 0.5s ease 0.15s both;
    }
    .live-clock-badge::before {
        content: ''; width: 7px; height: 7px; border-radius: 50%;
        background: var(--accent-green); display: inline-block;
        animation: softPulse 1.8s ease-in-out infinite;
    }

    /* Slot Progress Cards */
    .slots-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px; margin-bottom: 30px;
    }
    .slot-card {
        background: var(--card-bg); border-radius: 20px; padding: 22px;
        border: 1px solid var(--border-color);
        box-shadow: 0 6px 20px rgba(76,58,245,0.06);
        transition: transform 0.25s ease, box-shadow 0.25s ease;
        animation: fadeSlideUp 0.5s ease both;
    }
    .slot-card:nth-child(1) { animation-delay: 0.05s; }
    .slot-card:nth-child(2) { animation-delay: 0.12s; }
    .slot-card:nth-child(3) { animation-delay: 0.19s; }
    .slot-card:nth-child(4) { animation-delay: 0.26s; }
    .slot-card:nth-child(5) { animation-delay: 0.33s; }
    .slot-card:hover { transform: translateY(-3px); box-shadow: 0 10px 26px rgba(76,58,245,0.12); }
    .slot-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
    .slot-card-title { font-weight: 700; color: var(--text-color); font-size: 15px; display: flex; align-items: center; gap: 10px; }
    .slot-card-title i { color: var(--accent-orange); font-size: 18px; }
    .slot-progress-bg { background: var(--border-color); height: 8px; border-radius: 10px; overflow: hidden; margin-top: 10px; }
    .slot-progress-fill { height: 100%; border-radius: 10px; transition: 0.5s ease-in-out; }

    /* Main Terminal Layout (Grid Structure) */
    .terminal-main-layout {
        display: grid;
        grid-template-columns: 1.6fr 1fr;
        gap: 30px;
        margin-bottom: 30px;
    }
    @media (max-width: 992px) {
        .terminal-main-layout { grid-template-columns: 1fr; }
    }

    /* Exit Card */
    .exit-card {
        background: var(--card-bg); border-radius: 24px; padding: 32px;
        box-shadow: 0 10px 30px rgba(23,18,58,0.06); border: 1px solid var(--border-color);
        position: relative; overflow: hidden;
        animation: fadeSlideUp 0.5s ease both;
    }
    .exit-card::before {
        content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 6px;
        background: linear-gradient(90deg, var(--accent-green), var(--accent-orange), var(--accent-blue), var(--accent-green));
        background-size: 300% 100%;
        animation: bgDrift 6s linear infinite;
    }

    .search-mode-selector { display: flex; gap: 10px; margin-bottom: 20px; }
    .mode-btn {
        flex: 1; padding: 12px; border-radius: 12px; border: 1px solid var(--border-color);
        background: #F8FAFC; color: var(--sub-text); font-weight: 700; cursor: pointer; text-align: center;
        transition: 0.3s; font-size: 14px;
    }
    .mode-btn.active { background: var(--accent-blue); color: white; border-color: var(--accent-blue); }
    .mode-btn.lost-active { background: var(--accent-red); color: white; border-color: var(--accent-red); }

    .search-input-wrapper { position: relative; margin-bottom: 20px; }
    .search-input {
        width: 100%; padding: 16px 140px 16px 50px; border: 2px solid var(--border-color);
        border-radius: 16px; font-size: 18px; font-weight: 700; background: #F8FAFC;
        text-transform: uppercase; transition: 0.3s; color: var(--text-color);
    }
    .search-input:focus { border-color: var(--accent-orange); background: #FFFFFF; outline: none; box-shadow: 0 0 0 4px rgba(255, 107, 0, 0.15); }

    .btn-qr-scan {
        position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
        background: var(--accent-blue); color: #fff; border: none; padding: 10px 16px;
        border-radius: 12px; font-weight: 600; cursor: pointer; font-size: 13px; transition: 0.3s;
    }
    .btn-qr-scan:hover { background: var(--dark-navy); }

    .receipt-box {
        background: #F8FAFC; border: 2px dashed var(--border-color); border-radius: 20px;
        padding: 22px; margin-top: 20px;
    }
    .receipt-row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 14px; font-weight: 600; }
    
    .total-fee-badge {
        background: rgba(255, 107, 0, 0.1); border: 1px solid rgba(255, 107, 0, 0.3);
        border-radius: 16px; padding: 15px; text-align: center; margin-top: 15px;
    }
    .total-fee-amount { font-size: 30px; font-weight: 800; color: var(--accent-orange); }

    .btn-submit {
        width: 100%; background: var(--orange-gradient);
        color: white; border: none; padding: 16px; border-radius: 16px; font-size: 16px; font-weight: 700; cursor: pointer;
        box-shadow: 0 8px 25px rgba(255, 122, 26, 0.3); transition: transform 0.25s ease, box-shadow 0.25s ease;
        position: relative; overflow: hidden;
    }
    .btn-submit::after, .btn-complete::after {
        content: ''; position: absolute; inset: 0;
        background: linear-gradient(120deg, transparent 30%, rgba(255,255,255,0.35) 50%, transparent 70%);
        background-size: 200px 100%; background-repeat: no-repeat;
        animation: shimmer 2.6s ease-in-out infinite;
    }
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(255, 122, 26, 0.4); }

    .btn-complete {
        width: 100%; background: linear-gradient(135deg, var(--accent-green), #0FA968);
        color: white; border: none; padding: 18px; border-radius: 16px; font-size: 18px; font-weight: 700; cursor: pointer; margin-top: 15px;
        box-shadow: 0 8px 25px rgba(23, 199, 133, 0.3); transition: transform 0.25s ease, box-shadow 0.25s ease;
        position: relative; overflow: hidden;
    }
    .btn-complete:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(23, 199, 133, 0.4); }

    /* Side Panel Elements (Rates & Quick Tools) */
    .side-panel { display: flex; flex-direction: column; gap: 20px; }
    
    .panel-card {
        background: var(--card-bg); border-radius: 20px; padding: 22px;
        border: 1px solid var(--border-color); box-shadow: 0 6px 20px rgba(0,0,0,0.03);
    }
    .panel-title {
        font-size: 15px; font-weight: 700; color: var(--text-color); margin-bottom: 15px;
        display: flex; align-items: center; gap: 10px;
    }

    .rate-item {
        display: flex; justify-content: space-between; align-items: center;
        padding: 10px 0; border-bottom: 1px dashed var(--border-color); font-size: 13px; font-weight: 600;
    }
    .rate-item:last-child { border-bottom: none; }

    .quick-action-btn {
        width: 100%; display: flex; align-items: center; gap: 12px; padding: 12px 15px;
        background: #F8FAFC; border: 1px solid var(--border-color); border-radius: 12px;
        color: var(--text-color); font-weight: 600; font-size: 13px; cursor: pointer; transition: 0.3s; margin-bottom: 10px;
    }
    .quick-action-btn:hover { background: var(--accent-blue); color: white; border-color: var(--accent-blue); }

    /* Recent Prints List */
    .recent-print-item {
        background: #F8FAFC; border-radius: 12px; padding: 12px; margin-bottom: 10px;
        display: flex; justify-content: space-between; align-items: center; font-size: 13px;
    }

    /* Alert Boxes */
    .alert-box {
        padding: 16px 20px; border-radius: 16px; margin-bottom: 25px;
        font-weight: 600; text-align: center; display: flex; align-items: center; justify-content: center; gap: 10px;
        animation: fadeSlideUp 0.4s ease both;
    }
    .alert-error { background: rgba(240, 69, 95, 0.1); color: var(--accent-red); border: 1px solid rgba(240, 69, 95, 0.2); }
    .alert-success { background: rgba(23, 199, 133, 0.1); color: var(--accent-green); border: 1px solid rgba(23, 199, 133, 0.2); }

    /* Thank-You Checkout Card */
    .thankyou-card {
        background: linear-gradient(160deg, #ffffff, #F6F4FE);
        border: 1px solid var(--border-color); border-radius: 24px;
        padding: 30px 28px; margin-bottom: 24px; text-align: center;
        box-shadow: 0 16px 40px rgba(23,18,58,0.10);
        position: relative; overflow: hidden;
        animation: popIn 0.5s ease both;
    }
    .thankyou-card::before {
        content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 6px;
        background: linear-gradient(90deg, var(--accent-green), var(--accent-blue), var(--accent-orange), var(--accent-green));
        background-size: 300% 100%;
        animation: bgDrift 6s linear infinite;
    }
    .thankyou-check {
        width: 64px; height: 64px; border-radius: 50%; margin: 4px auto 14px auto;
        background: linear-gradient(135deg, var(--accent-green), #0FA968);
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-size: 28px; box-shadow: 0 8px 22px rgba(23,199,133,0.4);
        animation: popIn 0.5s ease 0.1s both, softPulse 2.2s ease-in-out 0.6s infinite;
    }
    .thankyou-title { font-size: 21px; font-weight: 800; color: var(--text-color); margin: 0 0 4px 0; }
    .thankyou-sub { font-size: 13px; color: var(--sub-text); margin: 0 0 20px 0; }
    .thankyou-grid {
        background: #F8FAFC; border: 1px solid var(--border-color); border-radius: 16px;
        padding: 16px 18px; text-align: left; margin: 0 auto 18px auto; max-width: 380px;
    }
    .thankyou-row { display: flex; justify-content: space-between; gap: 10px; padding: 6px 0; font-size: 13px; }
    .thankyou-row:not(:last-child) { border-bottom: 1px dashed var(--border-color); }
    .thankyou-row span:first-child { color: var(--sub-text); }
    .thankyou-row span:last-child, .thankyou-row strong { font-weight: 700; color: var(--text-color); }
    .thankyou-fee {
        display: inline-block; margin: 0 auto 20px auto; padding: 14px 28px; border-radius: 16px;
        background: rgba(23,199,133,0.10); border: 1px solid rgba(23,199,133,0.25);
    }
    .thankyou-fee .label { display: block; font-size: 11px; font-weight: 700; color: var(--sub-text); letter-spacing: .4px; }
    .thankyou-fee .amount { font-size: 26px; font-weight: 800; color: var(--accent-green); }
    .thankyou-actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
    .thankyou-actions .btn-print-receipt {
        background: var(--dark-navy); color: #fff; border: none; padding: 12px 20px; border-radius: 12px;
        font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
        transition: transform 0.2s ease;
    }
    .thankyou-actions .btn-print-receipt:hover { transform: translateY(-2px); }
    .thankyou-actions .btn-next-vehicle {
        background: var(--orange-gradient); color: #fff; text-decoration: none; border: none;
        padding: 12px 20px; border-radius: 12px; font-weight: 700; font-size: 13px;
        display: inline-flex; align-items: center; gap: 8px; transition: transform 0.2s ease;
    }
    .thankyou-actions .btn-next-vehicle:hover { transform: translateY(-2px); }

    /* Print Styling */
    .receipt-print-area { display: none; }
    @media print {
        @page { size: 80mm 120mm; margin: 0; }
        html, body { margin: 0 !important; padding: 0 !important; height: auto !important; overflow: hidden !important; }
        body > *:not(.receipt-print-area) { display: none !important; }
        .receipt-print-area {
            display: block !important; position: static; width: 80mm;
            font-family: monospace; page-break-after: avoid; page-break-inside: avoid; break-inside: avoid;
        }
    }

    /* Thermal Receipt Design (Highway-ticket sized) */
    .receipt-print-area {
        padding: 4mm 4mm; color: #000; font-family: 'Courier New', monospace;
        box-sizing: border-box;
    }
    .rcpt-logo {
        width: 24px; height: 24px; border-radius: 50%; border: 1.5px solid #000;
        display: flex; align-items: center; justify-content: center; margin: 0 auto 4px auto;
        font-size: 12px;
    }
    .rcpt-brand { text-align: center; font-size: 13px; font-weight: 700; letter-spacing: 1.5px; margin: 0; }
    .rcpt-sub { text-align: center; font-size: 8px; margin: 2px 0 0 0; }
    .rcpt-meta { text-align: center; font-size: 7.5px; margin: 1px 0 0 0; color: #333; }
    .rcpt-divider { border: none; border-top: 1px dashed #000; margin: 5px 0; }
    .rcpt-divider.dotted { border-top: 1px dotted #000; }
    .rcpt-status {
        text-align: center; font-size: 9px; font-weight: 700; letter-spacing: 0.5px;
        border: 1px solid #000; border-radius: 14px; padding: 2px 0; margin-bottom: 5px;
    }
    .rcpt-row { display: flex; justify-content: space-between; font-size: 9.5px; padding: 1.5px 0; }
    .rcpt-row .rcpt-label { color: #333; }
    .rcpt-row .rcpt-value { font-weight: 700; text-align: right; }
    .rcpt-total-box {
        border: 1px solid #000; border-radius: 4px; text-align: center;
        padding: 4px 0; margin: 6px 0 5px 0;
    }
    .rcpt-total-label { font-size: 8px; letter-spacing: 1px; }
    .rcpt-total-amount { font-size: 15px; font-weight: 700; margin-top: 1px; }
    .rcpt-thanks { text-align: center; font-size: 9.5px; font-weight: 700; margin: 3px 0 1px 0; }
    .rcpt-thanks-sub { text-align: center; font-size: 7.5px; margin: 0; }
</style>

<!-- Terminal Header Bar -->
<div class="terminal-header no-print">
    <div class="terminal-info">
        <div class="exit-avatar"><i class="fa-solid fa-right-from-bracket"></i></div>
        <div>
            <h2 style="font-size: 20px; font-weight: 700;">ParkSmart Exit Gate Terminal</h2>
            <p style="font-size: 13px; color: rgba(255,255,255,0.75);">අම්බලන්ගොඩ විවිධ සේවා සමූපාකාර සමිතිය - Checkout & Gate Control</p>
        </div>
    </div>
    <div class="live-clock-badge">
        <i class="fa-regular fa-clock" style="color: var(--accent-orange);"></i>
        <span id="liveClock"><?php echo date('Y-m-d H:i:s'); ?></span>
    </div>
</div>

<!-- Alert Messages -->
<?php if ($error_msg): ?>
    <div class="alert-box alert-error no-print">
        <i class="fa-solid fa-triangle-exclamation"></i> <?php echo $error_msg; ?>
    </div>
<?php endif; ?>

<!-- LIVE SLOT AVAILABILITY CARDS -->
<div class="slots-grid no-print">
    <?php
    $types_res = $conn->query("SELECT * FROM vehicle_types");
    while($t_row = $types_res->fetch_assoc()):
        $t_id   = $t_row['id'];
        $t_name = $t_row['type_name'];

        $p_count = $conn->query("SELECT COUNT(*) as c FROM tickets WHERE vehicle_type_id = $t_id AND status = 'PARKED' AND is_vip=0")->fetch_assoc()['c'];

        $limit = 10;
        if (stripos($t_name, 'bike') !== false) $limit = 20;
        elseif (stripos($t_name, 'bus') !== false || stripos($t_name, 'lorry') !== false) $limit = 5;

        $percentage = round(($p_count / $limit) * 100);
        $bar_color = "var(--accent-green)";
        if($percentage >= 80) $bar_color = "var(--accent-red)";
        elseif($percentage >= 50) $bar_color = "var(--accent-orange)";

        $icon = "fa-car";
        if (stripos($t_name, 'bike') !== false) $icon = "fa-motorcycle";
        elseif (stripos($t_name, 'bus') !== false || stripos($t_name, 'lorry') !== false) $icon = "fa-bus";
    ?>
    <div class="slot-card">
        <div class="slot-card-header">
            <span class="slot-card-title">
                <i class="fa-solid <?php echo $icon; ?>"></i>
                <?php echo htmlspecialchars($t_name); ?>
            </span>
            <span style="font-size: 13px; font-weight: 700; color: <?php echo $bar_color; ?>;">
                <?php echo $p_count; ?> / <?php echo $limit; ?>
            </span>
        </div>
        <div style="font-size: 12px; color: var(--sub-text); display: flex; justify-content: space-between;">
            <span>ඉතිරි ඉඩ ප්‍රමාණය:</span>
            <strong style="color: var(--text-color);"><?php echo ($limit - $p_count); ?> Slots</strong>
        </div>
        <div class="slot-progress-bg">
            <div class="slot-progress-fill" style="width: <?php echo $percentage; ?>%; background: <?php echo $bar_color; ?>;"></div>
        </div>
    </div>
    <?php endwhile; ?>

    <?php
    $vip_legacy = $conn->query("SELECT COUNT(*) AS c FROM tickets WHERE status='PARKED' AND is_vip=1")->fetch_assoc()['c'] ?? 0;
    $vip_active = $conn->query("SELECT COUNT(*) AS c FROM vip_parking_sessions WHERE status='PARKED'")->fetch_assoc()['c'] ?? 0;
    $vip_count = (int)$vip_legacy + (int)$vip_active;
    $vip_limit = 5;
    $vip_percentage = min(100, round(($vip_count / $vip_limit) * 100));
    $vip_bar_color = $vip_percentage >= 80 ? 'var(--accent-red)' : ($vip_percentage >= 50 ? 'var(--accent-orange)' : 'var(--accent-green)');
    ?>
    <div class="slot-card">
        <div class="slot-card-header">
            <span class="slot-card-title"><i class="fa-solid fa-crown"></i> VIP Vehicle Zone</span>
            <span style="font-size:13px;font-weight:700;color:<?php echo $vip_bar_color; ?>;"><?php echo $vip_count; ?> / <?php echo $vip_limit; ?></span>
        </div>
        <div style="font-size:12px;color:var(--sub-text);display:flex;justify-content:space-between;">
            <span>Reserved VIP capacity:</span>
            <strong style="color:var(--text-color);"><?php echo max(0,$vip_limit-$vip_count); ?> Slots Free</strong>
        </div>
        <div class="slot-progress-bg">
            <div class="slot-progress-fill" style="width:<?php echo $vip_percentage; ?>%;background:<?php echo $vip_bar_color; ?>;"></div>
        </div>
    </div>
</div>

<!-- MAIN WORKSPACE GRID -->
<div class="terminal-main-layout no-print">
    
    <!-- LEFT: EXIT PROCESS CARD -->
    <div class="exit-card">

        <?php if ($checkout_success): ?>
        <div class="thankyou-card">
            <div class="thankyou-check"><i class="fa-solid fa-check"></i></div>
            <h3 class="thankyou-title">ස්තුතියි! Thank You!</h3>
            <p class="thankyou-sub">නැවත එන්න 🙏 &nbsp;|&nbsp; Drive safe, see you again</p>

            <?php if ($printed_vip): ?>
                <div class="thankyou-grid">
                    <div class="thankyou-row"><span>Vehicle Number:</span><strong><?php echo htmlspecialchars($printed_vip['vehicle_number']); ?></strong></div>
                    <div class="thankyou-row"><span>Parking Zone:</span><strong><?php echo htmlspecialchars($printed_vip['slot_label']); ?></strong></div>
                    <div class="thankyou-row"><span>Entry Time:</span><span><?php echo date('Y-m-d | h:i A', strtotime($printed_vip['entry_time'])); ?></span></div>
                    <div class="thankyou-row"><span>Exit Time:</span><span><?php echo date('Y-m-d | h:i A', strtotime($printed_vip['exit_time'])); ?></span></div>
                    <div class="thankyou-row"><span>Duration:</span><span><?php echo (int)$printed_vip['duration_minutes']; ?> Mins</span></div>
                </div>
                <div class="thankyou-fee">
                    <span class="label">TOTAL PAYABLE</span>
                    <div class="amount">FREE</div>
                </div>
            <?php elseif ($printed_ticket): ?>
                <div class="thankyou-grid">
                    <div class="thankyou-row"><span>Ticket Code:</span><strong><?php echo htmlspecialchars($printed_ticket['ticket_code']); ?></strong></div>
                    <div class="thankyou-row"><span>Vehicle Number:</span><strong><?php echo htmlspecialchars($printed_ticket['vehicle_number']); ?></strong></div>
                    <div class="thankyou-row"><span>Category:</span><span><?php echo htmlspecialchars($printed_ticket['type_name']); ?></span></div>
                    <div class="thankyou-row"><span>Entry Time:</span><span><?php echo date('Y-m-d | h:i A', strtotime($printed_ticket['entry_time'])); ?></span></div>
                    <div class="thankyou-row"><span>Exit Time:</span><span><?php echo date('Y-m-d | h:i A', strtotime($printed_ticket['exit_time'])); ?></span></div>
                    <div class="thankyou-row"><span>Duration:</span><span><?php echo (int)$printed_ticket['duration_hours']; ?> Hrs</span></div>
                </div>
                <div class="thankyou-fee">
                    <span class="label">TOTAL PAID</span>
                    <div class="amount">LKR <?php echo number_format($printed_ticket['total_fee'], 2); ?></div>
                </div>
            <?php endif; ?>

            <div class="thankyou-actions no-print">
                <?php if ($printed_ticket || $printed_vip): ?>
                <button type="button" class="btn-print-receipt" onclick="printReceipt();">
                    <i class="fa-solid fa-print"></i> Print Receipt
                </button>
                <?php endif; ?>
                <a href="exit.php" class="btn-next-vehicle">
                    <i class="fa-solid fa-arrow-right"></i> Next Vehicle
                </a>
            </div>
        </div>
        <?php endif; ?>

        <div class="search-mode-selector">
            <div class="mode-btn active" id="btnNormalMode" onclick="setMode('normal')">
                <i class="fa-solid fa-qrcode"></i> QR / Ticket Scan
            </div>
            <div class="mode-btn" id="btnLostMode" onclick="setMode('lost')">
                <i class="fa-solid fa-file-circle-exclamation"></i> Lost Ticket Option
            </div>
        </div>

        <form action="" method="POST" id="qrAutoForm">
            <input type="hidden" name="qr_ticket_code" id="qr_ticket_code_input">
        </form>

        <form action="" method="POST">
            <input type="hidden" name="is_lost_mode" id="is_lost_mode" value="0">

            <div class="search-input-wrapper">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: var(--accent-orange);"></i>
                <input type="text" name="search_query" id="search_input_field" class="search-input" placeholder="Ticket Code OR Vehicle Number (VIP = Plate Only)" required autofocus autocomplete="off">
                
                <button type="button" class="btn-qr-scan" id="qrScanBtn" onclick="toggleCameraScanner()">
                    <i class="fa-solid fa-camera"></i> Scan QR
                </button>
            </div>

            <div style="display: none; background: #000; border-radius: 16px; overflow: hidden; margin-bottom: 20px;" id="qrScannerBox">
                <div id="reader"></div>
            </div>

            <button type="submit" name="search_ticket" class="btn-submit">
                <i class="fa-solid fa-calculator"></i> Calculate Fee
            </button>
        </form>

        <!-- Fee Calculation Result & Final Checkout -->
        <?php if ($calculated_data && !$checkout_success): ?>
        <form action="" method="POST">
            <input type="hidden" name="record_type" value="<?php echo htmlspecialchars($calculated_data['record_type']); ?>">
            <?php if ($calculated_data['record_type'] === 'VIP'): ?>
                <input type="hidden" name="session_id" value="<?php echo (int)$calculated_data['session_id']; ?>">
            <?php else: ?>
                <input type="hidden" name="ticket_id" value="<?php echo (int)$calculated_data['ticket_id']; ?>">
                <input type="hidden" name="duration_hours" value="<?php echo (int)$calculated_data['duration_hours']; ?>">
                <input type="hidden" name="final_fee" value="<?php echo htmlspecialchars($calculated_data['calculated_fee']); ?>">
            <?php endif; ?>

            <div class="receipt-box">
                <?php if ($calculated_data['record_type'] === 'VIP'): ?>

                    <div style="background:linear-gradient(135deg,rgba(245,158,11,.12),rgba(255,255,255,.9));border:1px solid rgba(245,158,11,.35);border-radius:16px;padding:16px;margin-bottom:16px;text-align:center;">
                        <div style="font-size:13px;font-weight:900;color:#B45309;letter-spacing:.5px;">
                            <i class="fa-solid fa-crown"></i> VIP VEHICLE VERIFIED
                        </div>
                        <div style="font-size:11px;color:#64748B;margin-top:5px;">
                            No ticket / QR is required. Verify the vehicle by its number plate.
                        </div>
                    </div>

                    <div class="receipt-row">
                        <span style="color:var(--sub-text);">Vehicle Number:</span>
                        <strong style="font-size:17px;"><?php echo htmlspecialchars($calculated_data['vehicle_number']); ?></strong>
                    </div>
                    <div class="receipt-row">
                        <span style="color:var(--sub-text);">Parking Zone:</span>
                        <strong style="color:#B45309;"><?php echo htmlspecialchars($calculated_data['slot_label'] ?: 'VIP Zone'); ?></strong>
                    </div>
                    <div class="receipt-row">
                        <span style="color:var(--sub-text);">Vehicle Category:</span>
                        <span>VIP Vehicle</span>
                    </div>
                    <div class="receipt-row">
                        <span style="color:var(--sub-text);">Entry Time:</span>
                        <span><?php echo date('Y-m-d | h:i A',strtotime($calculated_data['entry_time'])); ?></span>
                    </div>
                    <div class="receipt-row">
                        <span style="color:var(--sub-text);">Duration:</span>
                        <span><?php echo $calculated_data['total_minutes']; ?> Mins (~<?php echo $calculated_data['duration_hours']; ?> Hrs)</span>
                    </div>

                    <div style="background:rgba(16,185,129,.10);color:#047857;border:1px solid rgba(16,185,129,.20);padding:12px;border-radius:12px;font-size:13px;font-weight:800;text-align:center;margin-top:12px;">
                        <i class="fa-solid fa-shield-halved"></i> AUTHORIZED VIP — PARKING FEE IS FREE
                    </div>

                    <div class="total-fee-badge" style="border-color:rgba(16,185,129,.3);background:rgba(16,185,129,.08);">
                        <span style="font-size:12px;color:var(--sub-text);font-weight:700;">TOTAL PAYABLE</span>
                        <div class="total-fee-amount" style="color:var(--accent-green);">LKR 0.00</div>
                    </div>

                    <button type="submit" name="process_checkout" class="btn-complete">
                        <i class="fa-solid fa-shield-check"></i> Verify VIP &amp; Open Gate
                    </button>

                <?php else: ?>

                    <div class="receipt-row">
                        <span style="color: var(--sub-text);">Ticket Code:</span>
                        <strong style="color: var(--accent-orange);"><?php echo htmlspecialchars($calculated_data['ticket_code']); ?></strong>
                    </div>
                    <div class="receipt-row">
                        <span style="color: var(--sub-text);">Vehicle Number:</span>
                        <span style="font-size: 16px; font-weight: 800;"><?php echo htmlspecialchars($calculated_data['vehicle_number']); ?></span>
                    </div>
                    <div class="receipt-row">
                        <span style="color: var(--sub-text);">Vehicle Category:</span>
                        <span><?php echo htmlspecialchars($calculated_data['category']); ?></span>
                    </div>
                    <div class="receipt-row">
                        <span style="color: var(--sub-text);">Duration:</span>
                        <span><?php echo $calculated_data['total_minutes']; ?> Mins (~<?php echo $calculated_data['duration_hours']; ?> Hrs)</span>
                    </div>

                    <?php if (!$calculated_data['is_grace']): ?>
                    <div class="receipt-row" style="font-size:12px;color:var(--sub-text);">
                        <span>Fee Breakdown:</span>
                        <span>
                            1st Hr @ LKR <?php echo number_format($calculated_data['hourly_rate'], 2); ?>
                            <?php if ($calculated_data['duration_hours'] > 1): ?>
                                + <?php echo $calculated_data['duration_hours'] - 1; ?> Hr(s) @ LKR <?php echo number_format(ADDITIONAL_HOUR_RATE, 2); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>

                    <?php if ($calculated_data['is_grace']): ?>
                        <div style="background: rgba(16, 185, 129, 0.1); color: var(--accent-green); padding: 10px; border-radius: 10px; font-size: 13px; font-weight: 700; text-align: center; margin-top: 10px;">
                            <i class="fa-solid fa-circle-check"></i> GRACE PERIOD APPLIED (FREE UNDER 15 MINS)
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($calculated_data['is_vip']) && !$calculated_data['is_lost']): ?>
                        <div style="background: rgba(245, 158, 11, 0.1); color: #D97706; padding: 10px; border-radius: 10px; font-size: 13px; font-weight: 700; text-align: center; margin-top: 10px;">
                            <i class="fa-solid fa-crown"></i> VIP FREE PASS APPLIED
                        </div>
                    <?php endif; ?>

                    <?php if ($calculated_data['is_lost']): ?>
                        <div style="background: rgba(239, 68, 68, 0.1); color: var(--accent-red); padding: 10px; border-radius: 10px; font-size: 13px; font-weight: 700; text-align: center; margin-top: 10px;">
                            <i class="fa-solid fa-circle-exclamation"></i> LOST TICKET — NO FINE, STANDARD PARKING FEE APPLIES
                        </div>
                    <?php endif; ?>

                    <div class="total-fee-badge">
                        <span style="font-size: 12px; color: var(--sub-text); font-weight: 700;">TOTAL PAYABLE</span>
                        <div class="total-fee-amount">LKR <?php echo number_format($calculated_data['calculated_fee'], 2); ?></div>
                    </div>

                    <button type="submit" name="process_checkout" class="btn-complete">
                        <i class="fa-solid fa-door-open"></i> Complete &amp; Open Gate
                    </button>

                <?php endif; ?>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <!-- RIGHT: HELPFUL SIDE PANELS FOR OPERATOR -->
    <div class="side-panel">
        
        <!-- PARKING RATES CARD -->
        <div class="panel-card">
            <div class="panel-title">
                <i class="fa-solid fa-tags" style="color: var(--accent-orange);"></i> Standard Rate Card
            </div>
            <?php
            $rates_q = $conn->query("SELECT * FROM vehicle_types");
            while($r = $rates_q->fetch_assoc()):
            ?>
            <div class="rate-item">
                <span><?php echo $r['type_name']; ?></span>
                <span style="color: var(--accent-blue);">LKR <?php echo number_format($r['hourly_rate'], 2); ?> / 1st hr</span>
            </div>
            <?php endwhile; ?>
            <div class="rate-item" style="color: var(--accent-orange);">
                <span>Each Additional Hour</span>
                <span>LKR <?php echo number_format(ADDITIONAL_HOUR_RATE, 2); ?> / hr</span>
            </div>
            <div class="rate-item" style="color: var(--accent-green);">
                <span>Free Grace Period</span>
                <span><?php echo GRACE_PERIOD_MINUTES; ?> Mins</span>
            </div>
        </div>

        <!-- QUICK ASSISTANCE & SHORTCUTS -->
        <div class="panel-card">
            <div class="panel-title">
                <i class="fa-solid fa-bolt" style="color: var(--accent-orange);"></i> Quick Gate Actions
            </div>
            <button class="quick-action-btn" onclick="alert('Gate Barrier Override Triggered! Opening gate manually...');">
                <i class="fa-solid fa-torii-gate" style="color: var(--accent-green);"></i> Emergency Gate Open
            </button>
            <button class="quick-action-btn" onclick="setMode('lost'); document.getElementById('search_input_field').focus();">
                <i class="fa-solid fa-file-circle-exclamation" style="color: var(--accent-red);"></i> Process Lost Ticket
            </button>
        </div>

        <!-- RECENT EXIT LOG (MINI VIEW FOR QUICK RE-PRINT) -->
        <div class="panel-card">
            <div class="panel-title">
                <i class="fa-solid fa-history" style="color: var(--accent-orange);"></i> Recent Gate Exits
            </div>
            <?php
            $today_date = date('Y-m-d');
            $recent_exits = $conn->query("SELECT t.*, vt.type_name FROM tickets t JOIN vehicle_types vt ON t.vehicle_type_id = vt.id WHERE t.status = 'COMPLETED' AND DATE(t.exit_time) = '$today_date' ORDER BY t.exit_time DESC LIMIT 3");
            
            if($recent_exits && $recent_exits->num_rows > 0):
                while($rx = $recent_exits->fetch_assoc()):
            ?>
            <div class="recent-print-item">
                <div>
                    <strong style="display:block;"><?php echo $rx['vehicle_number']; ?></strong>
                    <span style="font-size: 11px; color: var(--sub-text);"><?php echo date('h:i A', strtotime($rx['exit_time'])); ?> | LKR <?php echo number_format($rx['total_fee'], 2); ?></span>
                </div>
                <span style="font-size: 11px; background: rgba(16, 185, 129, 0.1); color: var(--accent-green); padding: 3px 8px; border-radius: 6px; font-weight: 700;">PASSED</span>
            </div>
            <?php endwhile; else: ?>
                <div style="font-size: 12px; color: var(--sub-text); text-align: center; padding: 10px;">නෑවත Exit වූ වාහන සටහන් නොමැත.</div>
            <?php endif; ?>
        </div>

    </div>

</div>

<!-- PRINTABLE THERMAL RECEIPT (FOR COMPLETED CHECKOUT) -->
<?php if($printed_ticket): ?>
<div class="receipt-print-area" id="printableReceipt">
    <p class="rcpt-brand">PARK SMART</p>
    <p class="rcpt-sub">අම්බලන්ගොඩ විවිධ සේවා සමූපාකාර සමිතිය</p>
    <p class="rcpt-meta">Exit Receipt &nbsp;•&nbsp; <?php echo date('Y-m-d h:i A'); ?></p>

    <hr class="rcpt-divider">

    <div class="rcpt-status">EXIT AUTHORIZED</div>

    <div class="rcpt-row"><span class="rcpt-label">Ticket Code</span><span class="rcpt-value"><?php echo $printed_ticket['ticket_code']; ?></span></div>
    <div class="rcpt-row"><span class="rcpt-label">Vehicle No</span><span class="rcpt-value"><?php echo $printed_ticket['vehicle_number']; ?></span></div>
    <div class="rcpt-row"><span class="rcpt-label">Category</span><span class="rcpt-value"><?php echo $printed_ticket['type_name']; ?></span></div>

    <hr class="rcpt-divider dotted">

    <div class="rcpt-row"><span class="rcpt-label">Entry</span><span class="rcpt-value"><?php echo date('m-d h:i A', strtotime($printed_ticket['entry_time'])); ?></span></div>
    <div class="rcpt-row"><span class="rcpt-label">Exit</span><span class="rcpt-value"><?php echo date('m-d h:i A', strtotime($printed_ticket['exit_time'])); ?></span></div>
    <div class="rcpt-row"><span class="rcpt-label">Duration</span><span class="rcpt-value"><?php echo $printed_ticket['duration_hours']; ?> Hour(s)</span></div>

    <div class="rcpt-total-box">
        <div class="rcpt-total-label">TOTAL PAID</div>
        <div class="rcpt-total-amount">LKR <?php echo number_format($printed_ticket['total_fee'], 2); ?></div>
    </div>

    <p class="rcpt-thanks">ස්තුතියි! &nbsp;Thank You!</p>
    <p class="rcpt-thanks-sub">නැවත එන්න &nbsp;•&nbsp; Drive Safe &nbsp;•&nbsp; See You Again</p>
</div>
<?php endif; ?>

<?php if($printed_vip): ?>
<div class="receipt-print-area" id="printableReceipt">
    <p class="rcpt-brand">PARK SMART</p>
    <p class="rcpt-sub">අම්බලන්ගොඩ විවිධ සේවා සමූපාකාර සමිතිය</p>
    <p class="rcpt-meta">VIP Exit Receipt &nbsp;•&nbsp; <?php echo date('Y-m-d h:i A'); ?></p>

    <hr class="rcpt-divider">

    <div class="rcpt-status">VIP EXIT AUTHORIZED</div>

    <div class="rcpt-row"><span class="rcpt-label">Vehicle No</span><span class="rcpt-value"><?php echo $printed_vip['vehicle_number']; ?></span></div>
    <div class="rcpt-row"><span class="rcpt-label">Category</span><span class="rcpt-value">VIP Vehicle</span></div>
    <div class="rcpt-row"><span class="rcpt-label">Zone</span><span class="rcpt-value"><?php echo $printed_vip['slot_label']; ?></span></div>

    <hr class="rcpt-divider dotted">

    <div class="rcpt-row"><span class="rcpt-label">Entry</span><span class="rcpt-value"><?php echo date('m-d h:i A', strtotime($printed_vip['entry_time'])); ?></span></div>
    <div class="rcpt-row"><span class="rcpt-label">Exit</span><span class="rcpt-value"><?php echo date('m-d h:i A', strtotime($printed_vip['exit_time'])); ?></span></div>
    <div class="rcpt-row"><span class="rcpt-label">Duration</span><span class="rcpt-value"><?php echo (int)$printed_vip['duration_minutes']; ?> Mins</span></div>

    <div class="rcpt-total-box">
        <div class="rcpt-total-label">TOTAL PAID</div>
        <div class="rcpt-total-amount">FREE</div>
    </div>

    <p class="rcpt-thanks">ස්තුතියි! &nbsp;Thank You!</p>
    <p class="rcpt-thanks-sub">නැවත එන්න &nbsp;•&nbsp; Drive Safe &nbsp;•&nbsp; See You Again</p>
</div>
<?php endif; ?>

<script>
    // Moves the printable receipt to be a direct child of <body> right before
    // printing, so the print stylesheet can reliably hide everything else
    // (page header/sidebar/etc.) without leftover blank pages.
    function printReceipt() {
        var receipt = document.getElementById('printableReceipt');
        if (receipt) {
            document.body.appendChild(receipt);
        }
        window.print();
    }

    function setMode(mode) {
        const btnNormal = document.getElementById('btnNormalMode');
        const btnLost = document.getElementById('btnLostMode');
        const inputField = document.getElementById('search_input_field');
        const qrBtn = document.getElementById('qrScanBtn');
        const isLostMode = document.getElementById('is_lost_mode');

        if (mode === 'lost') {
            btnNormal.className = 'mode-btn';
            btnLost.className = 'mode-btn lost-active';
            inputField.placeholder = "Enter Vehicle Plate No (e.g. CAB-1234)";
            qrBtn.style.display = 'none';
            isLostMode.value = '1';
        } else {
            btnNormal.className = 'mode-btn active';
            btnLost.className = 'mode-btn';
            inputField.placeholder = "Ticket Code OR Vehicle Number";
            qrBtn.style.display = 'block';
            isLostMode.value = '0';
        }
    }

    let html5QrcodeScanner = null;
    function toggleCameraScanner() {
        const scannerBox = document.getElementById('qrScannerBox');
        if (scannerBox.style.display === 'none' || scannerBox.style.display === '') {
            scannerBox.style.display = 'block';
            html5QrcodeScanner = new Html5Qrcode("reader");
            html5QrcodeScanner.start({ facingMode: "environment" }, { fps: 10, qrbox: 250 }, onScanSuccess);
        } else {
            scannerBox.style.display = 'none';
            if (html5QrcodeScanner) html5QrcodeScanner.stop();
        }
    }

    function onScanSuccess(decodedText) {
        if (html5QrcodeScanner) html5QrcodeScanner.stop();
        document.getElementById('qr_ticket_code_input').value = decodedText;
        document.getElementById('qrAutoForm').submit();
    }

    // Dynamic Clock
    function updateClock() {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');
        
        const clockElem = document.getElementById('liveClock');
        if(clockElem) {
            clockElem.textContent = `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        setInterval(updateClock, 1000);
        updateClock();
    });
</script>

<?php include 'includes/footer.php'; ?>
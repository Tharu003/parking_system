<?php
session_start();
include 'config/db.php';
if (empty($_SESSION['security_logged_in']) && empty($_SESSION['admin_logged_in'])) {
    header('Location: security_login.php');
    exit;
}

// Ticket ID හෝ Ticket Code මගින් Ticket විස්තර ලබා ගැනීම
$ticket_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$ticket_code = isset($_GET['code']) ? trim($_GET['code']) : '';

if ($ticket_id > 0) {
    $stmt = $conn->prepare("SELECT t.*, vt.type_name, vt.hourly_rate 
                            FROM tickets t 
                            LEFT JOIN vehicle_types vt ON t.vehicle_type_id = vt.id 
                            WHERE t.id = ?");
    $stmt->bind_param("i", $ticket_id);
} elseif (!empty($ticket_code)) {
    $stmt = $conn->prepare("SELECT t.*, vt.type_name, vt.hourly_rate 
                            FROM tickets t 
                            LEFT JOIN vehicle_types vt ON t.vehicle_type_id = vt.id 
                            WHERE t.ticket_code = ?");
    $stmt->bind_param("s", $ticket_code);
} else {
    die("<h3 style='font-family:sans-serif; text-align:center; color:red; margin-top:50px;'>වලංගු Ticket අංකයක් සපයා නැත!</h3>");
}

$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();

if (!$ticket) { 
    die("<h3 style='font-family:sans-serif; text-align:center; color:red; margin-top:50px;'>ටිකට් පත හමු නොවීය! (Ticket Not Found)</h3>"); 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VIP PASS - <?php echo htmlspecialchars($ticket['ticket_code']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800;900&family=Cinzel:wght@600;700&display=swap');

        /* ===== THERMAL / RECEIPT PAGE SETUP ===== */
        /* Explicit fixed height (not "auto") avoids the ghost-second-page bug
           on printers/drivers that mis-handle "auto" height custom paper. */
        @page {
            size: 80mm 150mm;
            margin: 0;
        }

        *, *::before, *::after {
            box-sizing: border-box;
        }

        html, body {
            margin: 0 !important;
            padding: 0 !important;
            background: #ECEFF3;
            color: #000;
            font-family: 'Montserrat', 'Courier New', sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        body {
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
            padding: 16px 0 !important;
        }

        /* ===== VIP TICKET CARD ===== */
        .vip-ticket-wrapper {
            width: 280px;
            background: linear-gradient(180deg, #ffffff 0%, #fbf9f4 100%);
            border: 1.5px solid #0A192F;
            border-radius: 10px;
            padding: 0 0 10px 0;
            position: relative;
            box-shadow: 0 8px 24px rgba(10, 25, 47, 0.18);
            overflow: hidden;
            page-break-inside: avoid;
            page-break-after: avoid;
            break-inside: avoid;
            break-after: avoid;
        }

        /* Decorative gold corner accents */
        .vip-ticket-wrapper::before,
        .vip-ticket-wrapper::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 3px;
            left: 0;
            background: linear-gradient(90deg, #FF6B00, #FFC46B, #FF6B00);
        }
        .vip-ticket-wrapper::before { top: 0; }
        .vip-ticket-wrapper::after { bottom: 0; }

        /* ===== Brand Header (navy ribbon) ===== */
        .brand-header {
            background: #0A192F;
            color: #fff;
            text-align: center;
            padding: 12px 10px 10px 10px;
            margin-top: 3px;
        }

        .brand-header .title {
            font-size: 15px;
            font-weight: 900;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
        }

        .brand-header .title i {
            color: #FF6B00;
        }

        .brand-header .subtitle {
            font-size: 8px;
            font-weight: 600;
            color: rgba(255,255,255,0.65);
            letter-spacing: 1.5px;
            margin-top: 3px;
            text-transform: uppercase;
        }

        /* ===== VIP Ribbon Badge ===== */
        .vip-badge {
            background: #FF6B00;
            color: #fff;
            text-align: center;
            font-family: 'Cinzel', serif;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 3px;
            padding: 6px 0;
            text-transform: uppercase;
            position: relative;
        }

        .vip-badge span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* ===== Body Content ===== */
        .ticket-body {
            padding: 14px 16px 0 16px;
        }

        .divider {
            border-bottom: 1.5px dashed #C9CDD3;
            margin: 10px 0;
        }

        .info-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            line-height: 1.3;
            gap: 10px;
        }

        .info-label {
            font-size: 9.5px;
            font-weight: 700;
            color: #64748B;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }

        .info-value {
            font-weight: 800;
            color: #0A192F;
            text-align: right;
        }

        .vehicle-highlight {
            font-size: 14px;
            font-weight: 900;
            background: #0A192F;
            color: #fff;
            padding: 2px 8px;
            border-radius: 5px;
            letter-spacing: 1px;
        }

        .fee-value {
            font-size: 10.5px;
            font-weight: 800;
            color: #16A34A;
            background: rgba(22, 163, 74, 0.1);
            padding: 2px 8px;
            border-radius: 4px;
        }

        /* ===== QR Section ===== */
        .qr-box {
            text-align: center;
            margin: 4px 0 2px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .qr-frame {
            padding: 6px;
            background: #fff;
            border: 1.5px solid #0A192F;
            border-radius: 8px;
            display: inline-block;
        }

        #qrcode img {
            display: block;
        }

        .code-text {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.5px;
            margin-top: 6px;
            color: #0A192F;
        }

        /* ===== Footer Note ===== */
        .ticket-footer {
            text-align: center;
            padding: 0 16px;
        }

        .ticket-footer .note {
            font-size: 8.5px;
            font-weight: 600;
            color: #475569;
            line-height: 1.4;
        }

        .ticket-footer .wish {
            font-family: 'Cinzel', serif;
            font-size: 10.5px;
            font-weight: 700;
            color: #FF6B00;
            margin-top: 4px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        /* ===== On-Screen Controls (hidden on print) ===== */
        .no-print-controls {
            position: fixed;
            bottom: 15px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 10px;
            background: rgba(255, 255, 255, 0.95);
            padding: 10px 15px;
            border-radius: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            backdrop-filter: blur(5px);
            z-index: 100;
        }

        .btn {
            border: none;
            padding: 8px 16px;
            font-size: 12px;
            font-weight: 800;
            border-radius: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-print { background: #FF6B00; color: #fff; }
        .btn-print:hover { background: #e05e00; }
        .btn-close { background: #e2e8f0; color: #333; }
        .btn-close:hover { background: #cbd5e1; }

        /* ===== PRINT MEDIA — the actual page-2 fix ===== */
        @media print {
            html, body {
                width: 80mm !important;
                height: auto !important;
                min-height: 0 !important;
                overflow: hidden !important;
                background: #fff !important;
                padding: 0 !important;
                display: block !important;
            }
            .vip-ticket-wrapper {
                width: 100% !important;
                max-width: 80mm !important;
                margin: 0 auto !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                page-break-inside: avoid !important;
                page-break-before: avoid !important;
                page-break-after: avoid !important;
                break-inside: avoid !important;
            }
            .no-print-controls {
                display: none !important;
            }
        }
    </style>
</head>
<body>

    <div class="vip-ticket-wrapper">
        <!-- Brand Header -->
        <div class="brand-header">
            <h1 class="title"><i class="fa-solid fa-square-parking"></i> PARKSMART</h1>
            <div class="subtitle">Express Highway Parking</div>
        </div>

        <!-- VIP Badge -->
        <div class="vip-badge">
            <span><i class="fa-solid fa-crown"></i> VIP Access Pass</span>
        </div>

        <div class="ticket-body">
            <div class="info-group">
                <div class="info-row">
                    <span class="info-label">Pass Code</span>
                    <span class="info-value"><?php echo htmlspecialchars($ticket['ticket_code']); ?></span>
                </div>

                <div class="info-row">
                    <span class="info-label">Vehicle No</span>
                    <span class="info-value vehicle-highlight"><?php echo htmlspecialchars($ticket['vehicle_number']); ?></span>
                </div>

                <?php if (!empty($ticket['driver_name'])): ?>
                <div class="info-row">
                    <span class="info-label">Guest Name</span>
                    <span class="info-value"><?php echo htmlspecialchars($ticket['driver_name']); ?></span>
                </div>
                <?php endif; ?>

                <div class="info-row">
                    <span class="info-label">Category</span>
                    <span class="info-value"><?php echo !empty($ticket['type_name']) ? htmlspecialchars($ticket['type_name']) : 'VIP GUEST'; ?></span>
                </div>

                <div class="info-row">
                    <span class="info-label">Issued</span>
                    <span class="info-value" style="font-size: 10px;"><?php echo date('d-M-Y h:i A', strtotime($ticket['requested_date'] ?? $ticket['entry_time'] ?? date('Y-m-d H:i:s'))); ?></span>
                </div>

                <div class="info-row">
                    <span class="info-label">Access Fee</span>
                    <span class="fee-value">COMPLIMENTARY</span>
                </div>
            </div>

            <div class="divider"></div>

            <!-- QR Section -->
            <div class="qr-box">
                <div class="qr-frame">
                    <div id="qrcode"></div>
                </div>
                <div class="code-text">* <?php echo htmlspecialchars($ticket['ticket_code']); ?> *</div>
            </div>

            <div class="divider"></div>
        </div>

        <!-- Footer Info -->
        <div class="ticket-footer">
            <div class="note">කරුණාකර පිටවන විට මෙම VIP PASS එක පෙන්වන්න.</div>
            <div class="wish">Have a Safe Journey</div>
        </div>
    </div>

    <!-- On-Screen Controls -->
    <div class="no-print-controls">
        <button class="btn btn-print" onclick="window.print();"><i class="fa-solid fa-print"></i> Print</button>
        <a href="javascript:window.close();" class="btn btn-close"><i class="fa-solid fa-xmark"></i> Close</a>
    </div>

    <!-- QR Code Script -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        const ticketCode = "<?php echo $ticket['ticket_code']; ?>";
        if (ticketCode) {
            new QRCode(document.getElementById("qrcode"), {
                text: ticketCode,
                width: 80,
                height: 80,
                colorDark: "#0A192F",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.H
            });
        }

        // IMPORTANT: print only after the QR code has actually finished
        // rendering. Printing on window.onload (before the QR canvas/img is
        // painted) is what was causing the browser to re-measure the page
        // mid-print and spit out a duplicate/blank page 2.
        window.addEventListener('load', function () {
            setTimeout(function () {
                window.print();
            }, 350);
        });
    </script>
</body>
</html>
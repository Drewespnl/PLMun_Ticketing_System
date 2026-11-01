
<?php
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../config/db.php';

require_admin();

$user = current_user();
$adminId = (int)($user['id'] ?? 0);

if ($adminId <= 0) {
    redirect('../login.php');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CSRF protection
if (empty($_SESSION['verify_ticket_csrf'])) {
    $_SESSION['verify_ticket_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['verify_ticket_csrf'];

$ticketCode = '';
$result = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ticketCode = trim($_POST['ticket_code'] ?? '');
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $submittedToken)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } elseif ($ticketCode === '') {
        $error = 'Please enter a ticket code.';
    } else {
        try {
            $pdo->beginTransaction();

            /*
             * Lock the ticket and its transaction while verifying.
             * This helps prevent two staff members from using
             * the same ticket at the same time.
             */
            $stmt = $pdo->prepare("
                SELECT
                    t.id AS ticket_id,
                    t.ticket_code,
                    t.status AS ticket_status,
                    t.user_id,
                    t.event_id,
                    t.seat_id,
                    tr.id AS transaction_id,
                    tr.status AS payment_status,
                    e.event_name,
                    u.firstName,
                    u.lastName
                FROM tickets t
                INNER JOIN transactions tr
                    ON tr.id = t.transaction_id
                LEFT JOIN events e
                    ON e.id = t.event_id
                LEFT JOIN users u
                    ON u.id = t.user_id
                WHERE t.ticket_code = ?
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([$ticketCode]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ticket) {
                $pdo->rollBack();
                $error = 'Ticket not found. Please check the ticket code.';
            } else {
                $verificationStatus = 'invalid';
                $message = '';
                $ticketId = (int)$ticket['ticket_id'];

                if ($ticket['ticket_status'] === 'used') {
                    $verificationStatus = 'already_used';
                    $message = 'This ticket has already been used.';
                } elseif ($ticket['ticket_status'] === 'cancelled') {
                    $verificationStatus = 'invalid';
                    $message = 'This ticket has been cancelled.';
                } elseif ($ticket['payment_status'] !== 'paid') {
                    $verificationStatus = 'invalid';
                    $message = 'Payment has not been approved. Entry is not allowed.';
                } elseif ($ticket['ticket_status'] !== 'valid') {
                    $verificationStatus = 'invalid';
                    $message = 'This ticket is not valid.';
                } else {
                    // Paid transaction + valid ticket:
                    // mark ticket as used.
                    $update = $pdo->prepare("
                        UPDATE tickets
                        SET status = 'used'
                        WHERE id = ?
                          AND status = 'valid'
                    ");
                    $update->execute([$ticketId]);

                    if ($update->rowCount() === 1) {
                        $verificationStatus = 'valid';
                        $message = 'Ticket verified successfully. Entry allowed.';
                    } else {
                        $verificationStatus = 'already_used';
                        $message = 'This ticket has already been used.';
                    }
                }

                // Record verification using existing table columns.
                $log = $pdo->prepare("
                    INSERT INTO ticket_verification
                        (ticket_id, verified_by, verification_status)
                    VALUES (?, ?, ?)
                ");

                $log->execute([
                    $ticketId,
                    $adminId,
                    $verificationStatus
                ]);

                $pdo->commit();

                $result = [
                    'status' => $verificationStatus,
                    'message' => $message,
                    'ticket_code' => $ticket['ticket_code'],
                    'ticket_status' => (
                        $verificationStatus === 'valid'
                            ? 'used'
                            : $ticket['ticket_status']
                    ),
                    'payment_status' => $ticket['payment_status'],
                    'event_name' => $ticket['event_name'] ?? 'Event information unavailable',
                    'attendee' => trim(
                        ($ticket['firstName'] ?? '') . ' ' .
                        ($ticket['lastName'] ?? '')
                    )
                ];

                // Rotate token after processing.
                $_SESSION['verify_ticket_csrf'] =
                    bin2hex(random_bytes(32));
                $csrfToken = $_SESSION['verify_ticket_csrf'];
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Ticket verification error: ' . $e->getMessage());
            $error = 'Unable to verify the ticket right now. Please try again.';
        }
    }
}

function escapeHtml($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Verification | PLMun Events</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px 16px;
            min-height: 100vh;
            background: #f4f5f7;
            color: #202124;
            font-family: Arial, Helvetica, sans-serif;
        }

        .container {
            width: 100%;
            max-width: 720px;
            margin: 0 auto;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 24px;
        }

        .brand {
            font-size: 20px;
            font-weight: 700;
        }

        .back-link {
            color: #222;
            text-decoration: none;
            font-size: 14px;
        }

        .card {
            padding: 28px;
            background: #fff;
            border: 1px solid #e3e5e8;
            border-radius: 14px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, .04);
        }

        h1 {
            margin: 0 0 10px;
            font-size: 26px;
        }

        .subtitle {
            margin: 0 0 24px;
            color: #666;
            line-height: 1.6;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 700;
        }

        input {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 16px;
            outline: none;
        }

        input:focus {
            border-color: #222;
        }

        button {
            width: 100%;
            margin-top: 14px;
            padding: 13px;
            border: 0;
            border-radius: 8px;
            background: #171717;
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover {
            background: #333;
        }

        .notice {
            margin-top: 22px;
            padding: 16px;
            border-radius: 9px;
            line-height: 1.5;
        }

        .notice h2 {
            margin: 0 0 8px;
            font-size: 19px;
        }

        .success {
            background: #e8f7ed;
            color: #176534;
            border: 1px solid #b9e5c6;
        }

        .danger {
            background: #fff0f0;
            color: #a32121;
            border: 1px solid #f0c4c4;
        }

        .details {
            margin: 16px 0 0;
            padding: 0;
            list-style: none;
        }

        .details li {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            padding: 10px 0;
            border-bottom: 1px solid rgba(0, 0, 0, .08);
        }

        .details li:last-child {
            border-bottom: 0;
        }

        .details span:first-child {
            color: #666;
        }

        .details span:last-child {
            font-weight: 600;
            text-align: right;
            overflow-wrap: anywhere;
        }

        .error {
            margin-top: 18px;
            padding: 13px;
            color: #a32121;
            background: #fff0f0;
            border: 1px solid #f0c4c4;
            border-radius: 8px;
        }

        .help {
            margin-top: 22px;
            color: #777;
            font-size: 13px;
            line-height: 1.6;
        }

        @media (max-width: 480px) {
            .card {
                padding: 20px;
            }

            .topbar {
                align-items: flex-start;
            }

            .details li {
                flex-direction: column;
                gap: 5px;
            }

            .details span:last-child {
                text-align: left;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="topbar">
        <div class="brand">PLMun Events</div>
        <a class="back-link" href="dashboard.php">
            &larr; Back to Admin
        </a>
    </div>

    <main class="card">
        <h1>Ticket Verification</h1>
        <p class="subtitle">
            Enter the ticket code to check payment and ticket validity.
        </p>

        <form method="POST" action="">
            
            <div class="scanner-section">
                <h2>Scan Digital Ticket</h2>
                <p>Itapat ang camera sa QR code ng ticket.</p>

                <div id="qr-reader"></div>

                <button type="button" id="startScanner">
                    Start QR Scanner
                </button>

                <button type="button" id="stopScanner" hidden>
                    Stop Scanner
                </button>

                <p id="scanner-message" aria-live="polite"></p>
            </div>

            <input
                type="hidden"
                name="csrf_token"
                value="<?= escapeHtml($csrfToken) ?>"
            >

            <label for="ticket_code">Ticket Code</label>
            <input
                type="text"
                id="ticket_code"
                name="ticket_code"
                placeholder="Enter the ticket code"
                value="<?= escapeHtml($ticketCode) ?>"
                maxlength="100"
                autocomplete="off"
                required
                autofocus
            >

            <button type="submit">Verify Ticket</button>
        </form>

        <?php if ($error !== ''): ?>
            <div class="error" role="alert">
                <?= escapeHtml($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($result !== null): ?>
            <?php
                $isValid = $result['status'] === 'valid';
            ?>

            <section
                class="notice <?= $isValid ? 'success' : 'danger' ?>"
                aria-live="polite"
            >
                <h2>
                    <?= $isValid ? '✓ Entry Allowed' : '✕ Entry Denied' ?>
                </h2>

                <p><?= escapeHtml($result['message']) ?></p>

                <ul class="details">
                    <li>
                        <span>Ticket Code</span>
                        <span><?= escapeHtml($result['ticket_code']) ?></span>
                    </li>
                    <li>
                        <span>Attendee</span>
                        <span><?= escapeHtml($result['attendee'] ?: 'N/A') ?></span>
                    </li>
                    <li>
                        <span>Event</span>
                        <span><?= escapeHtml($result['event_name']) ?></span>
                    </li>
                    <li>
                        <span>Payment</span>
                        <span><?= escapeHtml(ucfirst($result['payment_status'])) ?></span>
                    </li>
                    <li>
                        <span>Ticket Status</span>
                        <span><?= escapeHtml(ucfirst($result['ticket_status'])) ?></span>
                    </li>
                    <li>
                        <span>Verification</span>
                        <span><?= escapeHtml(ucfirst(str_replace('_', ' ', $result['status']))) ?></span>
                    </li>
                </ul>
            </section>
        <?php endif; ?>

        <p class="help">
            Only tickets with an approved payment and a valid ticket status
            can be used for entry. A successfully verified ticket is marked
            as used and cannot be used again.
        </p>
    </main>
</div>

<script src="https://unpkg.com/html5-qrcode"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const startButton = document.getElementById('startScanner');
    const stopButton = document.getElementById('stopScanner');
    const message = document.getElementById('scanner-message');
    const ticketInput = document.getElementById('ticket_code');

    let scanner = null;
    let scanning = false;
    let submitting = false;

    startButton.addEventListener('click', async function () {
        if (scanning) return;

        try {
            message.textContent = 'Binubuksan ang camera...';

            if (typeof Html5Qrcode === 'undefined') {
                message.textContent =
                    'Hindi na-load ang QR scanner. Suriin ang internet connection.';
                return;
            }

            scanner = new Html5Qrcode('qr-reader');

            await scanner.start(
                { facingMode: 'environment' },
                {
                    fps: 10,
                    qrbox: { width: 250, height: 250 }
                },
                async function (decodedText) {
                    if (submitting) return;
                    submitting = true;

                    // Ilagay ang na-scan na value sa manual input.
                    ticketInput.value = decodedText.trim();
                    message.textContent =
                        'QR code detected. Handa nang i-verify.';

                    try {
                        await scanner.stop();
                    } catch (e) {
                        console.warn(e);
                    }

                    scanning = false;
                    startButton.hidden = false;
                    stopButton.hidden = true;

                    // Isumite sa existing PHP verification form.
                    ticketInput.form.requestSubmit();
                },
                function () {
                    // Normal lang kapag wala pang nababasang QR.
                }
            );

            scanning = true;
            startButton.hidden = true;
            stopButton.hidden = false;
            message.textContent =
                'Camera active. Itapat ang camera sa QR code.';
        } catch (error) {
            console.error(error);
            message.textContent =
                'Hindi mabuksan ang camera. Payagan ang camera access at subukan muli.';
        }
    });

    stopButton.addEventListener('click', async function () {
        if (scanner && scanning) {
            try {
                await scanner.stop();
                message.textContent = 'Scanner stopped.';
            } catch (error) {
                console.error(error);
                message.textContent = 'Hindi maihinto ang scanner.';
            }
        }

        scanning = false;
        startButton.hidden = false;
        stopButton.hidden = true;
    });
});
</script>
</body>
</html>
<?php
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../config/db.php';

require_login();

$eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
}

if (!$eventId || $eventId < 1) {
    http_response_code(400);
    exit('Invalid event ID.');
}

// Match the actual status ENUM in your events table.
date_default_timezone_set('Asia/Manila');

$stmt = $pdo->prepare("
    SELECT *
    FROM events
    WHERE id = ?
      AND status IN ('upcoming', 'ongoing')
    LIMIT 1
");
$stmt->execute([$eventId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(404);
    exit('Event not found or not available for booking.');
}

$eventTimestamp = strtotime(
    $event['event_date'] . ' ' .
    (($event['event_time'] ?? '') ?: '23:59:59')
);

if ($eventTimestamp === false || $eventTimestamp <= time()) {
    flash('This event has ended. Ticket purchases are closed.', 'error');
    redirect('../index.php#events');
    exit;
}

$error = '';

$price = (float)($event['ticket_price'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qty = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);

    if ($qty === false || $qty === null || $qty < 1 || $qty > 5) {
        $error = 'Choose between 1 and 5 tickets per booking.';
    } else {
        $eventTimestamp = strtotime(
            $event['event_date'] . ' ' . ($event['event_time'] ?? '00:00:00')
        );

        if ($eventTimestamp === false || $eventTimestamp <= time()) {
            $error = 'This event has already started or ended.';
        } else {
            try {
                $pdo->beginTransaction();

                $lockEvent = $pdo->prepare("
    SELECT id, event_date, event_time, status
    FROM events
    WHERE id = ?
    FOR UPDATE
");
$lockEvent->execute([$eventId]);

$lockedEvent = $lockEvent->fetch(PDO::FETCH_ASSOC);

if (!$lockedEvent) {
    throw new RuntimeException('Event is no longer available.');
}

$lockedTimestamp = strtotime(
    $lockedEvent['event_date'] . ' ' .
    (($lockedEvent['event_time'] ?? '') ?: '23:59:59')
);

if (
    !in_array(
        strtolower($lockedEvent['status']),
        ['upcoming', 'ongoing'],
        true
    ) ||
    $lockedTimestamp === false ||
    $lockedTimestamp <= time()
) {
    throw new RuntimeException(
        'This event has ended. Ticket purchases are closed.'
    );
}

                // Lock and collect available seats for this event.
                $seatStmt = $pdo->prepare("
                    SELECT id, seat_id
                    FROM event_seats
                    WHERE event_id = ?
                      AND status = 'available'
                    ORDER BY id ASC
                    LIMIT 5
                    FOR UPDATE
                ");
                $seatStmt->execute([$eventId]);
                $availableSeats = $seatStmt->fetchAll(PDO::FETCH_ASSOC);

                if (count($availableSeats) < $qty) {
                    throw new RuntimeException(
                        'Not enough available seats. Please choose a smaller quantity or try again later.'
                    );
                }

                $selectedSeats = array_slice($availableSeats, 0, $qty);

                // Reserve each selected seat. Reservation expires in 10 minutes.
                $reserveSeat = $pdo->prepare("
                    UPDATE event_seats
                    SET status = 'reserved',
                        reserved_by = ?,
                        reserved_at = NOW(),
                        reservation_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE)
                    WHERE id = ?
                      AND status = 'available'
                ");

                $insertTransaction = $pdo->prepare("
                    INSERT INTO transactions
                        (user_id, event_id, seat_id, amount, payment_method, transaction_reference, payment_group_ref, status)
                    VALUES (?, ?, ?, ?, 'GCash', ?, ?, 'pending')
                ");

                $userId = (int)current_user()['id'];
                $references = [];
                $paymentGroupRef = 'PAY-' . strtoupper(bin2hex(random_bytes(8)));

                foreach ($selectedSeats as $seat) {
                    $seatId = (int)$seat['seat_id'];
                    $eventSeatId = (int)$seat['id'];

                    $reserveSeat->execute([$userId, $eventSeatId]);

                    if ($reserveSeat->rowCount() !== 1) {
                        throw new RuntimeException(
                            'A selected seat is no longer available. Please try again.'
                        );
                    }

                    // Each transaction is linked to one seat because the
                    // current transactions schema requires a seat_id.
                    $reference = 'PLM-' . strtoupper(bin2hex(random_bytes(6)));
                    $insertTransaction->execute([
                        $userId, $eventId, $seatId, $price, $reference, $paymentGroupRef
                    ]);

                    $references[] = $reference;
                }

                $pdo->commit();

                flash('Seat(s) reserved. Complete GCash checkout to submit your payment reference.', 'success');
                redirect('checkout.php?group=' . urlencode($paymentGroupRef));
                exit;

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($e instanceof RuntimeException) {
                    $error = $e->getMessage();
                } else {
                    error_log('Booking error: ' . $e->getMessage());
                    $error = 'Booking could not be created. Please check seat availability and try again.';
                }
            }
        }
    }
}

$eventTimestamp = strtotime(
    $event['event_date'] . ' ' .
    (($event['event_time'] ?? '') ?: '23:59:59')
);

$eventDateTime = strtotime(
    $event['event_date'] . ' ' . $event['event_time']
);

?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reserve ticket | PLMun Events</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="dashboard-page">

<header class="site-header">
    <a class="brand" href="../index.php">
        <span class="brand-mark">P</span>
        <span>
            <strong>PLMun Events</strong>
            <small>Digital Ticketing Portal</small>
        </span>
    </a>
    <div class="header-actions">
        <a class="text-link" href="dashboard.php">My bookings</a>
    </div>
</header>

<main class="dashboard-main narrow-main">
    <a class="back-link" href="../index.php#events">← All events</a>

    <section class="booking-layout">
        <div class="booking-event cover-<?= (int)$event['id'] % 4 ?>">
            <span class="event-type"><?= e(ucfirst($event['status'])) ?></span>
            <span class="cover-monogram">P<span>L</span></span>
            <span class="cover-caption">PLMun <strong>Campus Events</strong></span>
        </div>

        <div class="panel booking-panel">
            <div class="eyebrow dark-eyebrow">RESERVE YOUR PLACE</div>

            <h1><?= e($event['event_name']) ?></h1>
            <p class="muted"><?= e($event['description'] ?? '') ?></p>

            <div class="booking-info">
                <div>
                    <small>DATE &amp; TIME</small>
                    <strong>
                        <?= $eventDateTime !== false
                            ? e(date('M d, Y · g:i A', $eventDateTime))
                            : e($event['event_date']) ?>
                    </strong>
                </div>

                <div>
                    <small>VENUE</small>
                    <strong><?= e($event['venue']) ?></strong>
                </div>

                <div>
                    <small>PRICE PER TICKET</small>
                    <strong>
                        <?= $price > 0
                            ? '₱' . number_format($price, 2)
                            : 'Free' ?>
                    </strong>
                </div>

                <div>
                    <small>CAPACITY</small>
                    <strong><?= (int)($event['capacity'] ?? 0) ?> tickets</strong>
                </div>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" class="form-stack">
                <input
                    type="hidden"
                    name="event_id"
                    value="<?= (int)$event['id'] ?>"
                >

                <label>
                    Number of tickets
                    <select name="quantity" id="quantity" required>
                        <option value="1">1 ticket</option>
                        <option value="2">2 tickets</option>
                        <option value="3">3 tickets</option>
                        <option value="4">4 tickets</option>
                        <option value="5">5 tickets</option>
                    </select>
                </label>

                <div class="total-row">
                    <span>Estimated total</span>
                    <strong id="total" data-price="<?= $price ?>">
                        ₱<?= number_format($price, 2) ?>
                    </strong>
                </div>

                <div class="notice-box">
                    Reservations are pending for 10 minutes. This page does
                    not collect real payments. The administrator must confirm
                    payment before the ticket can be accepted for entry.
                </div>

                <button class="btn btn-dark btn-full" type="submit">
                    Reserve ticket(s) ↗
                </button>
            </form>
        </div>
    </section>
</main>

<script>
const quantity = document.getElementById('quantity');
const total = document.getElementById('total');

function calculateTotal() {
    const price = Number(total.dataset.price || 0);
    const qty = Number(quantity.value || 1);

    total.textContent = '₱' + (price * qty).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

quantity.addEventListener('change', calculateTotal);
calculateTotal();
</script>

</body>
</html>
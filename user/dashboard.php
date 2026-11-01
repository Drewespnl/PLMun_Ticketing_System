
<?php
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../config/db.php';

require_login();

$user = current_user();

// Get the logged-in user's tickets and related transaction/event details.
$sql = "
    SELECT
        t.id AS ticket_id,
        t.ticket_code,
        t.status AS ticket_status,
        t.created_at AS ticket_created_at,
        e.event_name AS title,
        e.event_date,
        e.event_time,
        e.venue,
        tr.amount AS ticket_price,
        tr.status AS payment_status,
        tr.transaction_reference
    FROM tickets t
    INNER JOIN events e
        ON e.id = t.event_id
    LEFT JOIN transactions tr
        ON tr.id = t.transaction_id
        AND tr.user_id = t.user_id
    WHERE t.user_id = ?
    ORDER BY t.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$user['id']]);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pendingStmt = $pdo->prepare("
    SELECT
        tr.id,
        tr.transaction_reference,
        tr.amount,
        tr.status,
        tr.created_at,
        e.event_name,
        e.event_date
    FROM transactions tr
    INNER JOIN events e
        ON e.id = tr.event_id
    WHERE tr.user_id = ?
      AND tr.status = 'pending'
    ORDER BY tr.created_at DESC
");

$pendingStmt->execute([$user['id']]);
$pendingPayments = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

$flash = take_flash();

$upcomingCount = count(array_filter(
    $bookings,
    fn($b) => strtotime($b['event_date']) >= strtotime(date('Y-m-d'))
));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Tickets | PLMun Events</title>
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
        <a class="text-link" href="../index.php">Browse events</a>
        <a class="text-link" href="../logout.php">Sign out</a>
    </div>
</header>

<main class="dashboard-main">

    <div class="dashboard-welcome">
        <div>
            <div class="eyebrow dark-eyebrow">USER PORTAL</div>
            <h1>Good to see you, <?= e($user['firstName']) ?>.</h1>
            <p class="muted">
                Your campus moments and tickets, all in one place.
            </p>
        </div>

        <a class="btn btn-dark" href="../index.php#events">
            Explore events ↗
        </a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>">
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="metric-grid">
        <div class="metric-card">
            <span>MY TICKETS</span>
            <strong><?= count($bookings) ?></strong>
            <small>All tickets issued to you</small>
        </div>

        <div class="metric-card">
            <span>UPCOMING EVENTS</span>
            <strong><?= $upcomingCount ?></strong>
            <small>Based on your ticket records</small>
        </div>

        <div class="metric-card">
            <span>ACCOUNT</span>
            <strong class="metric-word">Active</strong>
            <small><?= e($user['email']) ?></small>
        </div>
    </div>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>My tickets</h2>
                <p>Keep your ticket code ready for event verification.</p>
            </div>
        </div>

        <?php if (!$bookings): ?>

            <div class="empty-state compact">
                <div class="empty-icon">✳</div>
                <h3>No tickets yet</h3>
                <p>
                    Find an event that interests you and reserve your seat.
                </p>
                <a class="btn btn-dark" href="../index.php#events">
                    Browse events
                </a>
            </div>

        <?php else: ?>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Date &amp; venue</th>
                            <th>Ticket code</th>
                            <th>Ticket status</th>
                            <th>Payment</th>
                            <th>Amount</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($bookings as $b): ?>
                            <tr>
                                <td>
                                    <strong><?= e($b['title']) ?></strong>
                                </td>

                                <td>
                                    <?= e(date(
                                        'M d, Y',
                                        strtotime($b['event_date'])
                                    )) ?>

                                    <small class="cell-sub">
                                        <?= e(date(
                                            'g:i A',
                                            strtotime($b['event_time'])
                                        )) ?>
                                        · <?= e($b['venue']) ?>
                                    </small>
                                </td>

                                <td>
                                    <code><?= e($b['ticket_code']) ?></code>
                                </td>

                                <td>
                                    <span class="status status-<?= e($b['ticket_status']) ?>">
                                        <?= e(ucfirst($b['ticket_status'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php
                                    $paymentStatus = $b['payment_status'] ?? 'unknown';
                                    ?>
                                    <?= e(ucfirst($paymentStatus)) ?>
                                </td>

                                <td>
                                    ₱<?= number_format(
                                        (float)($b['ticket_price'] ?? 0),
                                        2
                                    ) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </section>

    
<section class="panel">
    <div class="panel-heading">
        <div>
            <h2>Payment status</h2>
            <p>
                Pending payments must be reviewed by the administrator
                before a ticket is approved.
            </p>
        </div>
    </div>

    <?php if (!$pendingPayments): ?>

        <p class="muted">No pending payments.</p>

    <?php else: ?>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>System reference</th>
                        <th>Amount</th>
                        <th>Payment status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($pendingPayments as $payment): ?>
                        <tr>
                            <td>
                                <strong>
                                    <?= e($payment['event_name']) ?>
                                </strong>
                                <small class="cell-sub">
                                    <?= e(date(
                                        'M d, Y',
                                        strtotime($payment['event_date'])
                                    )) ?>
                                </small>
                            </td>

                            <td>
                                <code>
                                    <?= e($payment['transaction_reference']) ?>
                                </code>
                            </td>

                            <td>
                                ₱<?= number_format(
                                    (float) $payment['amount'],
                                    2
                                ) ?>
                            </td>

                            <td>
                                <span class="status status-pending">
                                    Pending
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>
</section>

</main>

</body>
</html>
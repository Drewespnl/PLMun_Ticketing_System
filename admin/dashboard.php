<?php
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../config/db.php';

require_admin();

/*
|--------------------------------------------------------------------------
| CREATE EVENT
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_event') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $venue = trim($_POST['venue'] ?? '');
        $datetime = trim($_POST['event_date'] ?? '');

        $price = filter_var(
            $_POST['ticket_price'] ?? '',
            FILTER_VALIDATE_FLOAT
        );

        $capacity = filter_var(
            $_POST['capacity'] ?? '',
            FILTER_VALIDATE_INT
        );

        $allowedStatuses = ['draft', 'published', 'upcoming'];
        $submittedStatus = $_POST['status'] ?? 'draft';

        $status = in_array($submittedStatus, $allowedStatuses, true)
            ? $submittedStatus
            : 'draft';

        $timestamp = $datetime !== '' ? strtotime($datetime) : false;

        if (
            $title === '' ||
            $venue === '' ||
            $timestamp === false ||
            $price === false ||
            $price < 0 ||
            $capacity === false ||
            $capacity < 1
        ) {
            flash('Please enter valid event details.', 'error');
        
        } else {
            try {
                $eventDate = date('Y-m-d', $timestamp);
                $eventTime = date('H:i:s', $timestamp);

                // Simulan ang database transaction
                $pdo->beginTransaction();

                // 1. I-save ang event
                $stmt = $pdo->prepare(
                    'INSERT INTO events
                    (
                        event_name,
                        description,
                        event_date,
                        event_time,
                        venue,
                        ticket_price,
                        capacity,
                        status,
                        created_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
                );

                $stmt->execute([
                    $title,
                    $description,
                    $eventDate,
                    $eventTime,
                    $venue,
                    $price,
                    $capacity,
                    $status
                ]);

                // Kunin ang ID ng bagong event
                $eventId = (int) $pdo->lastInsertId();
                
                // 2. Kunin ang existing seats
                $existingSeats = $pdo->query(
                    'SELECT seat_number FROM seats'
                )->fetchAll(PDO::FETCH_COLUMN);

                $usedSeatNumbers = array_fill_keys(
                    array_map('strval', $existingSeats),
                    true
                );

                $totalSeats = count($existingSeats);
                $nextSeatNumber = 1;

                // Automatic na gumawa ng seats kapag kulang
                while ($totalSeats < $capacity) {
                    $seatNumber = 'S' . str_pad(
                        (string) $nextSeatNumber,
                        3,
                        '0',
                        STR_PAD_LEFT
                    );

                    $nextSeatNumber++;

                    if (isset($usedSeatNumbers[$seatNumber])) {
                        continue;
                    }

                    $rowNumber = (int) ceil($totalSeats / 10) + 1;

                    $seatStmt = $pdo->prepare(
                        'INSERT INTO seats
                            (seat_number, row_name, section)
                         VALUES (?, ?, ?)'
                    );

                    $seatStmt->execute([
                        $seatNumber,
                        'Row ' . $rowNumber,
                        'General'
                    ]);

                    $usedSeatNumbers[$seatNumber] = true;
                    $totalSeats++;
                }

                // 3. I-assign ang seats sa bagong event
                $seatStmt = $pdo->prepare(
                    "INSERT INTO event_seats
                        (event_id, seat_id, status)
                     SELECT ?, s.id, 'available'
                     FROM seats s
                     WHERE NOT EXISTS (
                         SELECT 1
                         FROM event_seats es
                         WHERE es.event_id = ?
                           AND es.seat_id = s.id
                     )
                     ORDER BY s.id ASC
                     LIMIT ?"
                );

                $seatStmt->bindValue(1, $eventId, PDO::PARAM_INT);
                $seatStmt->bindValue(2, $eventId, PDO::PARAM_INT);
                $seatStmt->bindValue(3, $capacity, PDO::PARAM_INT);
                $seatStmt->execute();

                if ($seatStmt->rowCount() !== $capacity) {
                    throw new RuntimeException(
                        'The assigned seat count does not match the event capacity.'
                    );
                }


                // Tiyaking sapat ang na-assign na seats
                if ($seatStmt->rowCount() !== $capacity) {
                    throw new RuntimeException(
                        'Not enough seats were assigned to this event. Please check the seats table.'
                    );
                }
                $pdo->commit();

                flash(
                    'Event created successfully. ' .
                    $capacity . ' seats assigned to the event.',
                    'success'
                );

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Event creation or seat assignment failed: ' .
                    $e->getMessage()
                );

                flash(
                    'Unable to create the event or assign seats. ' .
                    'Check the PHP error log for details.',
                    'error'
                );
            }
        }
    }
}
/*
|--------------------------------------------------------------------------
| DASHBOARD STATISTICS
|--------------------------------------------------------------------------
*/
$stats = [
    'events' => (int) $pdo->query(
        'SELECT COUNT(*) FROM events'
    )->fetchColumn(),

    'users' => (int) $pdo->query(
        "SELECT COUNT(*)
        FROM users 
        WHERE is_verified = 1
        AND role = 'user'"
    )->fetchColumn(),

    'tickets' => (int) $pdo->query(
        'SELECT COUNT(*) FROM tickets'
    )->fetchColumn(),

    'revenue' => (float) $pdo->query(
        "SELECT COALESCE(SUM(amount), 0)
         FROM transactions
         WHERE status = 'paid'"
    )->fetchColumn()
];

/*
|--------------------------------------------------------------------------
| EVENT INVENTORY
|--------------------------------------------------------------------------
*/
$events = $pdo->query(
    'SELECT
        e.*,
        (
            SELECT COUNT(*)
            FROM tickets t
            WHERE t.event_id = e.id
              AND t.status <> \'cancelled\'
        ) AS booked
     FROM events e
     ORDER BY e.event_date DESC, e.event_time DESC
     LIMIT 50'
)->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| RECENT TICKETS AND TRANSACTIONS
|--------------------------------------------------------------------------
*/
$bookings = $pdo->query(
    'SELECT
        t.id AS ticket_id,
        t.ticket_code,
        t.status AS ticket_status,
        t.created_at AS ticket_created_at,
        u.firstName,
        u.lastName,
        u.email,
        e.event_name,
        tr.id AS transaction_id,
        tr.transaction_reference,
        tr.amount,
        tr.status AS transaction_status
     FROM tickets t
     LEFT JOIN users u
        ON u.id = t.user_id
     LEFT JOIN events e
        ON e.id = t.event_id
     LEFT JOIN transactions tr
        ON tr.id = t.transaction_id
     ORDER BY t.created_at DESC
     LIMIT 30'
)->fetchAll(PDO::FETCH_ASSOC);

$flash = take_flash();
$admin = current_user();
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Admin Dashboard | PLMun Events</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/displayProfile.css">
    <link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

</head>

<body class="dashboard-page admin-page">

<aside class="sidebar">

    
<?php
$currentUser = current_user();
$adminId = (int) ($currentUser['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT firstName, lastName, profile_picture
     FROM users
     WHERE id = ? AND role = 'admin'
     LIMIT 1"
);
$stmt->execute([$adminId]);
$adminProfile = $stmt->fetch(PDO::FETCH_ASSOC);

$adminName = trim(
    ($adminProfile['firstName'] ?? '') . ' ' .
    ($adminProfile['lastName'] ?? '')
);

$profilePicture = $adminProfile['profile_picture'] ?? '';
$profilePath = __DIR__ . '/../assets/uploads/admin/' .
    basename($profilePicture);

$hasProfilePicture = !empty($profilePicture)
    && is_file($profilePath);
?>

<a class="brand sidebar-brand">
    <span class="brand-logo">
        <?php if ($hasProfilePicture): ?>
            <img
                src="../assets/uploads/admin/<?= htmlspecialchars(
                    basename($profilePicture),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                alt="Admin Profile"
            >
        <?php else: ?>
            <span>P</span>
        <?php endif; ?>
    </span>

    <span class="brand-text">
        <strong><?= htmlspecialchars(
            $adminName ?: 'PLMun Events',
            ENT_QUOTES,
            'UTF-8'
        ) ?></strong>
        <small>Dashboard</small>
    </span>
</a>


    <div class="sidebar-label">WORKSPACE</div>

    <a class="side-link active" href="dashboard.php">
        <span>▦</span> Overview
    </a>

    <a class="side-link" href="#events">
        <span>◷</span> Event management
    </a>

    <a class="side-link" href="seat-management.php">
    <span>♙</span>
    Seat Management</a>

    <a class="side-link" href="#bookings">
        <span>▤</span> Tickets & transactions
    </a>

    <a href="verify-ticket.php" class="side-link">
        <span><i class="fas fa-qrcode"></i></span>
        Ticket Verification
    </a>

    <a class="side-link" href="reports.php">
        <span>↗</span> Reports
    </a>

    <a class="side-link" href="users.php">
        <span><i class="fa-solid fa-users"></i></span>
        User Management
    </a>

    <a class="side-link" href="profile.php">
        <span><i class="fa-solid fa-user"></i></span>
        Admin Profile
    </a>

    <div class="sidebar-bottom">
        <div><strong><?= e(trim(($admin['firstName'] ?? '') . ' ' . ($admin['lastName'] ?? ''))) ?></strong><small>Sign out</small></div>
        <a class="side-logout" href="../logout.php" title="Sign out">↗</a>
    </div>
</aside>

<main class="admin-content">

    <header class="admin-topbar">
        <div>
            <h1>OVERVIEW</h1>
            <span class="muted">PLMUN / ADMIN</span>
        </div>

        <div class="admin-top-actions">
            <span class="today-label">
                <?= date('l, F j, Y') ?>
            </span>

            <a class="btn btn-dark btn-small"
               href="../index.php">
                View public site ↗
            </a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>">
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="dashboard-welcome admin-welcome">
        <div>
            <div class="eyebrow dark-eyebrow">
                CAMPUS EVENT OPERATIONS
            </div>

            <h2>
                Good day, <?= e($admin['firstName'] ?? 'Administrator') ?>.
            </h2>

            <p class="muted">
                Here is what's happening across your ticketing portal.
            </p>
        </div>

        <a href="#create-event" class="btn btn-outline">
            + Create event
        </a>
    </div>

    <!-- DASHBOARD METRICS -->
    <div class="metric-grid">

        <div class="metric-card">
            <span>TOTAL EVENTS</span>
            <strong><?= $stats['events'] ?></strong>
            <small>All event records</small>
        </div>

        <div class="metric-card">
            <span>REGISTERED USERS</span>
            <strong><?= $stats['users'] ?></strong>
            <small>Regular accounts</small>
        </div>

        <div class="metric-card">
            <span>TOTAL TICKETS</span>
            <strong><?= $stats['tickets'] ?></strong>
            <small>Ticket records</small>
        </div>

        <div class="metric-card">
            <span>CONFIRMED SALES</span>
            <strong>₱<?= number_format($stats['revenue'], 2) ?></strong>
            <small>Transactions marked paid</small>
        </div>

    </div>

    <!-- CREATE EVENT -->
    <section class="panel" id="create-event">

        <div class="panel-heading">
            <div>
                <h2>Create an event</h2>
                <p>Add a PLMun school activity to the system.</p>
            </div>

            <span class="panel-index">01 / EVENTS</span>
        </div>

        <form method="post" class="admin-event-form">

            <input type="hidden" name="action" value="create_event">

            <label>
                Event title
                <input
                    name="title"
                    required
                    maxlength="180"
                    placeholder="e.g. University Week Opening"
                >
            </label>

            <label>
                Venue
                <input
                    name="venue"
                    required
                    maxlength="180"
                    placeholder="e.g. PLMun Main Campus"
                >
            </label>

            <label>
                Date & time
                <input
                    type="datetime-local"
                    name="event_date"
                    required
                >
            </label>

            <label>
                Ticket price (₱)
                <input
                    type="number"
                    name="ticket_price"
                    min="0"
                    step="0.01"
                    required
                    value="0"
                >
            </label>

            <label>
                Capacity
                <input
                    type="number"
                    name="capacity"
                    min="1"
                    required
                    placeholder="e.g. 250"
                >
            </label>

            <label>
                Status
                <select name="status" required>
                    <option value="draft">Draft</option>
                    <option value="published">Published</option>
                    <option value="upcoming">Upcoming</option>
                </select>
            </label>

            <label class="form-wide">
                Description
                <textarea
                    name="description"
                    rows="3"
                    placeholder="Tell students what to expect..."
                ></textarea>
            </label>

            <div class="form-wide form-submit-row">
                <small class="muted">
                    Confirm the event details before saving.
                </small>

                <button class="btn btn-dark" type="submit">
                    Save event ↗
                </button>
            </div>

        </form>
    </section>

    <!-- EVENT INVENTORY -->
    <section class="panel" id="events">

        <div class="panel-heading">
            <div>
                <h2>Event inventory</h2>
                <p>Review event details, capacity, and status.</p>
            </div>

            <span class="panel-index">
                <?= count($events) ?> RECORDS
            </span>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Date & time</th>
                        <th>Price</th>
                        <th>Capacity</th>
                        <th>Tickets</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td>
                                <strong>
                                    <?= e($event['event_name']) ?>
                                </strong>

                                <small class="cell-sub">
                                    <?= e($event['venue']) ?>
                                </small>
                            </td>

                            <td>
                                <?= e(date('M d, Y', strtotime($event['event_date']))) ?>

                                <small class="cell-sub">
                                    <?= e(date('h:i A', strtotime($event['event_time']))) ?>
                                </small>
                            </td>

                            <td>
                                ₱<?= number_format((float)$event['ticket_price'], 2) ?>
                            </td>

                            <td>
                                <?= (int)$event['capacity'] ?>
                            </td>

                            <td>
                                <?= (int)$event['booked'] ?>
                            </td>

                            <td>
                                <span class="status status-<?= e($event['status']) ?>">
                                    <?= e(ucfirst($event['status'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$events): ?>
                        <tr>
                            <td colspan="6">
                                No events yet. Create your first event above.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- RECENT TICKETS -->
    <section class="panel" id="bookings">

        <div class="panel-heading">
            <div>
                <h2>Recent tickets & transactions</h2>
                <p>Review ticket records and their linked transactions.</p>
            </div>

            <span class="panel-index">LATEST 30</span>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Attendee</th>
                        <th>Event</th>
                        <th>Ticket code</th>
                        <th>Reference</th>
                        <th>Amount</th>
                        <th>Transaction</th>
                        <th>Ticket status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($bookings as $b): ?>
                        <tr>
                            <td>
                                <strong>
                                    <?= e(trim(($b['firstName'] ?? '') . ' ' . ($b['lastName'] ?? ''))) ?>
                                </strong>

                                <small class="cell-sub">
                                    <?= e($b['email'] ?? '') ?>
                                </small>
                            </td>

                            <td>
                                <?= e($b['event_name'] ?? 'Event unavailable') ?>
                            </td>

                            <td>
                                <code><?= e($b['ticket_code'] ?? '') ?></code>
                            </td>

                            <td>
                                <?= e($b['transaction_reference'] ?? '—') ?>
                            </td>

                            <td>
                                <?php if ($b['amount'] !== null): ?>
                                    ₱<?= number_format((float)$b['amount'], 2) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= e(ucfirst($b['transaction_status'] ?? 'No transaction')) ?>
                            </td>

                            <td>
                                <span class="status status-<?= e($b['ticket_status'] ?? 'unknown') ?>">
                                    <?= e(ucfirst($b['ticket_status'] ?? 'Unknown')) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$bookings): ?>
                        <tr>
                            <td colspan="7">
                                No tickets yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <p class="footnote">
            Transaction status is displayed from the database.
            This dashboard does not verify or process real payments.
        </p>

    </section>

    <footer class="admin-footer">
        PLMun Events · Academic project prototype
        <span>Built for campus event operations</span>
    </footer>

</main>
</body>
</html>

<?php
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../config/db.php';

require_admin();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Get currently logged-in admin
$user = current_user();
$userId = (int)($user['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT firstName, lastName, profile_picture
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$userId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

$adminName = trim(
    ($admin['firstName'] ?? '') . ' ' .
    ($admin['lastName'] ?? '')
);

$profilePicture = '';

if (!empty($admin['profile_picture'])) {

    $filename = basename($admin['profile_picture']);

    // Actual image location
    $imagePath = dirname(__DIR__) .
        '/assets/uploads/admin/' . $filename;

    // Browser image location
    if (is_file($imagePath)) {
        $profilePicture = '../assets/uploads/admin/' .
            rawurlencode($filename);
    }
}

if (empty($_SESSION['seat_csrf_token'])) {
    $_SESSION['seat_csrf_token'] = bin2hex(random_bytes(32));
}

function seatRowLabel(int $number): string
{
    $label = '';

    while ($number > 0) {
        $number--;
        $label = chr(65 + ($number % 26)) . $label;
        $number = intdiv($number, 26);
    }

    return $label;
}

$error = '';

$events = $pdo->query("
    SELECT id, event_name, event_date, event_time, venue, status
    FROM events
    ORDER BY event_date ASC, id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);

if ($eventId < 1 && !empty($events)) {
    $eventId = (int)$events[0]['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');

    if (
        !hash_equals(
            (string)$_SESSION['seat_csrf_token'],
            $token
        )
    ) {
        $error = 'Invalid form token. Please refresh the page and try again.';
    } else {
        $rows = filter_input(INPUT_POST, 'rows', FILTER_VALIDATE_INT);
        $seatsPerRow = filter_input(
            INPUT_POST,
            'seats_per_row',
            FILTER_VALIDATE_INT
        );

        $section = trim((string)($_POST['section'] ?? ''));

        if (
            !$rows || !$seatsPerRow ||
            $rows < 1 || $rows > 100 ||
            $seatsPerRow < 1 || $seatsPerRow > 50
        ) {
            $error = 'Rows must be 1–100 and seats per row must be 1–50.';
        } elseif ($section === '' || strlen($section) > 100) {
            $error = 'Enter a section name of up to 100 characters.';
        } else {
            $capacity = $rows * $seatsPerRow;

            try {
                $pdo->beginTransaction();

                // Lock the event during configuration.
                $lockEvent = $pdo->prepare(
                    'SELECT id FROM events WHERE id = ? FOR UPDATE'
                );
                $lockEvent->execute([$eventId]);

                if (!$lockEvent->fetchColumn()) {
                    throw new RuntimeException('Event not found.');
                }

                // Lock current assignments and identify protected seats.
                $existingStmt = $pdo->prepare("
                    SELECT id, seat_id, status
                    FROM event_seats
                    WHERE event_id = ?
                    FOR UPDATE
                ");
                $existingStmt->execute([$eventId]);
                $existingAssignments = $existingStmt->fetchAll(
                    PDO::FETCH_ASSOC
                );

                $protectedSeatIds = [];

                foreach ($existingAssignments as $assignment) {
                    if (in_array(
                        $assignment['status'],
                        ['reserved', 'sold'],
                        true
                    )) {
                        $protectedSeatIds[] = (int)$assignment['seat_id'];
                    }
                }

                $protectedSeatIds = array_values(
                    array_unique($protectedSeatIds)
                );

                if (count($protectedSeatIds) > $capacity) {
                    throw new RuntimeException(
                        'Capacity cannot be lower than the number of reserved and sold seats.'
                    );
                }

                // Generate the requested layout.
                // Example: A-01, A-02 ... B-01, B-02 ...
                $targetSeatIds = [];

                $findSeat = $pdo->prepare("
                    SELECT id
                    FROM seats
                    WHERE seat_number = ?
                      AND row_name = ?
                      AND section = ?
                    LIMIT 1
                    FOR UPDATE
                ");

                $createSeat = $pdo->prepare("
                    INSERT INTO seats (seat_number, row_name, section)
                    VALUES (?, ?, ?)
                ");

                for ($r = 1; $r <= $rows; $r++) {
                    $rowName = seatRowLabel($r);

                    for ($n = 1; $n <= $seatsPerRow; $n++) {
                        $seatNumber = $rowName . '-' .
                            str_pad((string)$n, 2, '0', STR_PAD_LEFT);

                        $findSeat->execute([
                            $seatNumber,
                            $rowName,
                            $section
                        ]);

                        $seatId = $findSeat->fetchColumn();

                        if ($seatId === false) {
                            $createSeat->execute([
                                $seatNumber,
                                $rowName,
                                $section
                            ]);

                            $seatId = $pdo->lastInsertId();
                        }

                        $targetSeatIds[] = (int)$seatId;
                    }
                }

                $targetSeatIds = array_values(
                    array_unique($targetSeatIds)
                );

                $targetSet = array_fill_keys($targetSeatIds, true);

                // Never remove or rename a reserved/sold seat.
                foreach ($protectedSeatIds as $protectedId) {
                    if (!isset($targetSet[$protectedId])) {
                        throw new RuntimeException(
                            'The new layout would remove a reserved or sold seat. Keep its existing row, seat number and section, or contact the administrator before changing the layout.'
                        );
                    }
                }

                $existingBySeat = [];

                foreach ($existingAssignments as $assignment) {
                    $existingBySeat[(int)$assignment['seat_id']] = $assignment;
                }

                // Remove only available assignments not in the new layout.
                $deleteAvailable = $pdo->prepare("
                    DELETE FROM event_seats
                    WHERE id = ?
                      AND event_id = ?
                      AND status = 'available'
                ");

                foreach ($existingAssignments as $assignment) {
                    $seatId = (int)$assignment['seat_id'];

                    if (
                        $assignment['status'] === 'available' &&
                        !isset($targetSet[$seatId])
                    ) {
                        $deleteAvailable->execute([
                            (int)$assignment['id'],
                            $eventId
                        ]);
                    }
                }

                // Add assignments for new seats without changing
                // existing reserved or sold statuses.
                $addAssignment = $pdo->prepare("
                    INSERT INTO event_seats
                        (event_id, seat_id, status)
                    VALUES (?, ?, 'available')
                ");

                foreach ($targetSeatIds as $seatId) {
                    if (!isset($existingBySeat[$seatId])) {
                        $addAssignment->execute([
                            $eventId,
                            $seatId
                        ]);
                    }
                }

                // Keep event capacity synchronized with the layout.
                $updateEvent = $pdo->prepare("
                    UPDATE events
                    SET capacity = ?
                    WHERE id = ?
                ");
                $updateEvent->execute([$capacity, $eventId]);

                $pdo->commit();

                flash(
                    'Seat layout saved successfully. Capacity: ' .
                    $capacity . ' seats.'
                );

                redirect('seat-management.php?event_id=' . $eventId);
                exit;

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($e instanceof RuntimeException) {
                    $error = $e->getMessage();
                } else {
                    error_log('Seat management error: ' . $e->getMessage());
                    $error = 'Could not save the seat layout. Check the database configuration and try again.';
                }
            }
        }
    }
}

$selectedEvent = null;

foreach ($events as $item) {
    if ((int)$item['id'] === $eventId) {
        $selectedEvent = $item;
        break;
    }
}

$seatCounts = [
    'available' => 0,
    'reserved' => 0,
    'sold' => 0
];

if ($selectedEvent) {
    $countStmt = $pdo->prepare("
        SELECT status, COUNT(*) AS total
        FROM event_seats
        WHERE event_id = ?
        GROUP BY status
    ");
    $countStmt->execute([$eventId]);

    foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $count) {
        $seatCounts[$count['status']] = (int)$count['total'];
    }
}

$currentCapacity = (int)($selectedEvent['capacity'] ?? 100);
$defaultSeatsPerRow = 10;
$defaultRows = max(
    1,
    (int)ceil($currentCapacity / $defaultSeatsPerRow)
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Seat Management | PLMun Ticketing</title>
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .seat-admin {
            max-width: 1050px;
            margin: 30px auto;
            padding: 0 20px 40px;
        }

        .seat-admin .panel {
            margin-bottom: 20px;
        }

        .seat-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin: 20px 0;
        }

        .seat-stat {
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 16px;
        }

        .seat-stat strong {
            display: block;
            font-size: 25px;
            margin-top: 6px;
        }

        .seat-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .seat-form-grid label {
            display: block;
        }

        .seat-form-grid input,
        .seat-form-grid select {
            width: 100%;
            box-sizing: border-box;
            margin-top: 7px;
        }

        .seat-help {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }

        @media (max-width: 600px) {
            .seat-stats {
                grid-template-columns: 1fr;
            }

            .seat-form-grid {
                grid-template-columns: 1fr;
            }
        }

        .admin-header-profile {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
    color: #171717;
    transition: opacity 0.2s ease;
}

.admin-header-profile:hover {
    opacity: 0.8;
}

.admin-header-avatar {
    width: 48px;
    height: 48px;
    min-width: 48px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #e5e5e5;
}

.admin-avatar-fallback {
    display: flex;
    align-items: center;
    justify-content: center;
    background: #eeeeee;
    color: #555;
    font-size: 20px;
}

.admin-header-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.admin-header-info strong {
    font-size: 16px;
    font-weight: 700;
}

.admin-header-info small {
    font-size: 12px;
    color: #777;
}
    </style>
</head>
<body class="dashboard-page">

<header class="site-header">

    <a class="admin-header-profile">

        <?php if ($profilePicture !== ''): ?>

            <img
                src="<?= e($profilePicture) ?>"
                alt="Admin Profile"
                class="admin-header-avatar"
            >

        <?php else: ?>

            <div class="admin-header-avatar admin-avatar-fallback">
                <i class="fa-solid fa-user"></i>
            </div>

        <?php endif; ?>

        <div class="admin-header-info">
            <strong><?= e($adminName ?: 'Administrator') ?></strong>
            <small>Seat Management</small>
        </div>

    </a>

    <div class="header-actions">
        <a class="text-link" href="dashboard.php">
            Dashboard
        </a>
    </div>

</header>

<main class="seat-admin">
    <a class="back-link" href="dashboard.php">← Back to dashboard</a>

    <h1>Seat Management</h1>
    <p class="muted">
        Configure event capacity, rows, and seats per row.
    </p>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$selectedEvent): ?>
        <div class="panel">
            <p>No events found. Create an event first.</p>
        </div>
    <?php else: ?>

        <section class="panel">
            <h2>Select event</h2>

            <form method="get" class="form-stack">
                <label>
                    Event
                    <select name="event_id" onchange="this.form.submit()">
                        <?php foreach ($events as $item): ?>
                            <option
                                value="<?= (int)$item['id'] ?>"
                                <?= (int)$item['id'] === $eventId
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e($item['event_name']) ?>
                                (<?= e($item['status']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>

            <h3><?= e($selectedEvent['event_name']) ?></h3>
            <p class="muted">
                Current capacity: <?= $currentCapacity ?> seats
            </p>

            <div class="seat-stats">
                <div class="seat-stat">
                    <span>Available</span>
                    <strong><?= $seatCounts['available'] ?></strong>
                </div>
                <div class="seat-stat">
                    <span>Reserved</span>
                    <strong><?= $seatCounts['reserved'] ?></strong>
                </div>
                <div class="seat-stat">
                    <span>Sold</span>
                    <strong><?= $seatCounts['sold'] ?></strong>
                </div>
            </div>
        </section>

        <section class="panel">
            <h2>Configure seat layout</h2>

            <p class="seat-help">
                Capacity is calculated as rows × seats per row.
                Saving creates missing seats and removes only obsolete
                available assignments. Reserved and sold seats are protected.
            </p>

            <form method="post" class="form-stack">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['seat_csrf_token']) ?>"
                >

                <input
                    type="hidden"
                    name="event_id"
                    value="<?= $eventId ?>"
                >

                <div class="seat-form-grid">
                    <label>
                        Number of rows
                        <input
                            type="number"
                            name="rows"
                            id="rows"
                            min="1"
                            max="100"
                            value="<?= (int)($_POST['rows'] ?? $defaultRows) ?>"
                            required
                        >
                    </label>

                    <label>
                        Seats per row
                        <input
                            type="number"
                            name="seats_per_row"
                            id="seats_per_row"
                            min="1"
                            max="50"
                            value="<?= (int)($_POST['seats_per_row'] ?? $defaultSeatsPerRow) ?>"
                            required
                        >
                    </label>

                    <label>
                        Section name
                        <input
                            type="text"
                            name="section"
                            maxlength="100"
                            value="<?= e($_POST['section'] ?? 'GENERAL') ?>"
                            required
                        >
                    </label>

                    <label>
                        Calculated capacity
                        <input
                            type="number"
                            id="capacity_preview"
                            value="<?= $defaultRows * $defaultSeatsPerRow ?>"
                            readonly
                        >
                    </label>
                </div>

                <p class="seat-help" id="layout_preview"></p>

                <button
                    class="btn btn-dark btn-full"
                    type="submit"
                    onclick="return confirm('Save this seat layout? Reserved and sold seats will not be deleted.')"
                >
                    Save Seat Layout
                </button>
            </form>
        </section>

    <?php endif; ?>
</main>

<script>
const rowsInput = document.getElementById('rows');
const seatsInput = document.getElementById('seats_per_row');
const capacityPreview = document.getElementById('capacity_preview');
const layoutPreview = document.getElementById('layout_preview');

function updateLayoutPreview() {
    const rows = Number(rowsInput.value || 0);
    const seats = Number(seatsInput.value || 0);
    const capacity = rows * seats;

    capacityPreview.value = capacity;

    layoutPreview.textContent =
        'This layout will contain ' + capacity +
        ' seats across ' + rows + ' rows, with ' +
        seats + ' seats per row.';
}

if (rowsInput && seatsInput) {
    rowsInput.addEventListener('input', updateLayoutPreview);
    seatsInput.addEventListener('input', updateLayoutPreview);
    updateLayoutPreview();
}
</script>

</body>
</html>

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

    $imagePath = dirname(__DIR__) .
        '/assets/uploads/admin/' . $filename;

    if (is_file($imagePath)) {
        $profilePicture = '../assets/uploads/admin/' .
            rawurlencode($filename);
    }
}

/*
 * Reports based on the existing events and tickets tables.
 * Confirmed sales are calculated from paid transactions.
 */

$sql = "
    SELECT
        e.id,
        e.event_name,
        e.event_date,
        e.event_time,
        e.venue,
        COUNT(DISTINCT t.id) AS tickets_issued,
        COUNT(DISTINCT CASE
            WHEN t.status = 'valid' THEN t.id
        END) AS valid_tickets,
        COUNT(DISTINCT CASE
            WHEN t.status = 'used' THEN t.id
        END) AS used_tickets,
        COALESCE((
            SELECT SUM(tr.amount)
            FROM transactions tr
            WHERE tr.status = 'paid'
              AND tr.event_id = e.id
        ), 0) AS sales
    FROM events e
    LEFT JOIN tickets t ON t.event_id = e.id
    GROUP BY
        e.id,
        e.event_name,
        e.event_date,
        e.event_time,
        e.venue
    ORDER BY e.event_date DESC
";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

/* CSV export */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="plmun-ticket-report.csv"'
    );

    $out = fopen('php://output', 'w');

    fputcsv($out, [
        'Event',
        'Event Date',
        'Venue',
        'Tickets Issued',
        'Valid Tickets',
        'Used Tickets',
        'Confirmed Sales'
    ]);

    foreach ($rows as $row) {
        fputcsv($out, [
            $row['event_name'],
            $row['event_date'],
            $row['venue'],
            $row['tickets_issued'],
            $row['valid_tickets'],
            $row['used_tickets'],
            $row['sales']
        ]);
    }

    fclose($out);
    exit;
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reports | PLMun Events</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<style>
    /* Consistent Admin Profile Size */
.admin-brand {
    display: flex;
    align-items: center;
    gap: 10px;
}

.admin-brand .sidebar-admin-photo,
.admin-brand .sidebar-admin-initial {
    width: 38px !important;
    height: 38px !important;
    min-width: 38px !important;
    min-height: 38px !important;
    max-width: 38px !important;
    max-height: 38px !important;
    border-radius: 50% !important;
    object-fit: cover;
    flex-shrink: 0;
}

.admin-brand .sidebar-admin-info {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.admin-brand .sidebar-admin-info strong {
    font-size: 13px;
    line-height: 1.3;
}

.admin-brand .sidebar-admin-info small {
    font-size: 10px;
    letter-spacing: 1px;
}
</style>
<body class="dashboard-page">


<header class="site-header">

    <a class="brand admin-brand">

        <?php if (!empty($profilePicture)): ?>

            <img
                src="<?= htmlspecialchars($profilePicture) ?>"
                alt="Admin Profile"
                class="sidebar-admin-photo"
            >

        <?php else: ?>

            <span class="sidebar-admin-initial">
                <?= e(strtoupper(substr($adminName ?: 'A', 0, 1))) ?>
            </span>

        <?php endif; ?>

        <span class="sidebar-admin-info">
            <strong>
                <?= e($adminName ?: 'Administrator') ?>
            </strong>
            <small>ADMIN</small>
        </span>

    </a>

    <div class="header-actions">
        <a class="text-link" href="dashboard.php">← Dashboard</a>
        <a class="text-link" href="../logout.php">Sign out</a>
    </div>

</header>

<main class="dashboard-main">
    <div class="dashboard-welcome">
        <div>
            <div class="eyebrow dark-eyebrow">ANALYTICS & EXPORTS</div>
            <h1>Event reports</h1>
            <p class="muted">
                Summary of ticket records and paid transactions.
            </p>
        </div>

        <a class="btn btn-dark" href="?export=csv">
            Export CSV ↗
        </a>
    </div>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Performance by event</h2>
                <p>
                    Ticket counts are based on ticket records.
                    Sales are based on paid transactions.
                </p>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Date</th>
                        <th>Venue</th>
                        <th>Tickets Issued</th>
                        <th>Valid</th>
                        <th>Used</th>
                        <th>Confirmed Sales</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <strong><?= e($r['event_name']) ?></strong>
                        </td>
                        <td>
                            <?= e(
                                $r['event_date']
                                    ? date('M d, Y', strtotime($r['event_date']))
                                    : '—'
                            ) ?>
                        </td>
                        <td><?= e($r['venue'] ?? '') ?></td>
                        <td><?= (int)$r['tickets_issued'] ?></td>
                        <td><?= (int)$r['valid_tickets'] ?></td>
                        <td><?= (int)$r['used_tickets'] ?></td>
                        <td>
                            ₱<?= number_format((float)$r['sales'], 2) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if (!$rows): ?>
                    <tr>
                        <td colspan="7">No report data available yet.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

</body>
</html>

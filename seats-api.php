
<?php
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../config/db.php';

require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);

if (!$eventId || $eventId < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid event ID.']);
    exit;
}

try {
    // Release reservations that have expired.
    $expire = $pdo->prepare("
        UPDATE event_seats
        SET status = 'available',
            reserved_by = NULL,
            reserved_at = NULL,
            reservation_expires_at = NULL
        WHERE event_id = ?
          AND status = 'reserved'
          AND reservation_expires_at IS NOT NULL
          AND reservation_expires_at <= NOW()
    ");
    $expire->execute([$eventId]);

    // Return the latest seat status for this event.
    $stmt = $pdo->prepare("
        SELECT
            es.id AS event_seat_id,
            s.id AS seat_id,
            s.seat_number,
            s.row_name,
            s.section,
            es.status
        FROM event_seats es
        INNER JOIN seats s ON s.id = es.seat_id
        WHERE es.event_id = ?
        ORDER BY s.id ASC
    ");

    $stmt->execute([$eventId]);
    $seats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'event_id' => $eventId,
        'seats' => $seats
    ]);

} catch (Throwable $e) {
    error_log('Seat map API error: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode(['error' => 'Unable to load seats.']);
}

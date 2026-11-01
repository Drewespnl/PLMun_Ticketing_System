<?php
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../config/db.php';
require_admin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = filter_input(INPUT_POST, 'transaction_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    if (!$id || !in_array($action, ['approve', 'reject'], true)) {
        flash('Invalid payment action.', 'error');
        redirect('payments.php');
    }
    try {
        $pdo->beginTransaction();
        $one = $pdo->prepare("SELECT * FROM transactions WHERE id=? AND status='pending' AND gcash_reference IS NOT NULL FOR UPDATE");
        $one->execute([$id]);
        $first = $one->fetch(PDO::FETCH_ASSOC);
        if (!$first) throw new RuntimeException('Payment is not pending or has no submitted GCash reference.');
        $group = $first['payment_group_ref'] ?? '';
        if ($group === '') throw new RuntimeException('Missing checkout group reference.');
        $q = $pdo->prepare("SELECT * FROM transactions WHERE payment_group_ref=? AND user_id=? AND gcash_reference=? AND status='pending' FOR UPDATE");
        $q->execute([$group, $first['user_id'], $first['gcash_reference']]);
        $items = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) throw new RuntimeException('No pending transactions found for this checkout.');

        if ($action === 'approve') {
            // Validate every seat first; never approve a partially available checkout.
            $seatLookup = $pdo->prepare("SELECT id FROM event_seats WHERE event_id=? AND seat_id=? AND status='reserved' FOR UPDATE");
            $seatIds = [];
            foreach ($items as $item) {
                $seatLookup->execute([$item['event_id'], $item['seat_id']]);
                $seatId = $seatLookup->fetchColumn();
                if (!$seatId) throw new RuntimeException('A reserved seat is no longer available. Check seat management before approving.');
                $seatIds[(int)$item['id']] = (int)$seatId;
            }
            $markPaid = $pdo->prepare("UPDATE transactions SET status='paid' WHERE id=? AND status='pending'");
            $findTicket = $pdo->prepare("SELECT id FROM tickets WHERE transaction_id=? LIMIT 1");
            $insertTicket = $pdo->prepare("INSERT INTO tickets (transaction_id,user_id,event_id,seat_id,ticket_code,qr_code,status) VALUES (?,?,?,?,?,?, 'valid')");
            $sellSeat = $pdo->prepare("UPDATE event_seats SET status='sold', reserved_by=NULL, reservation_expires_at=NULL WHERE id=? AND status='reserved'");
            foreach ($items as $item) {
                $markPaid->execute([$item['id']]);
                $findTicket->execute([$item['id']]);
                if (!$findTicket->fetchColumn()) {
                    $code = 'TKT-' . strtoupper(bin2hex(random_bytes(8)));
                    $insertTicket->execute([$item['id'], $item['user_id'], $item['event_id'], $item['seat_id'], $code, $code]);
                }
                $sellSeat->execute([$seatIds[(int)$item['id']]]);
            }
            flash('Payment approved. All tickets in this checkout are now valid.');
        } else {
            $markFailed = $pdo->prepare("UPDATE transactions SET status='failed' WHERE id=? AND status='pending'");
            $release = $pdo->prepare("UPDATE event_seats SET status='available', reserved_by=NULL, reserved_at=NULL, reservation_expires_at=NULL WHERE event_id=? AND seat_id=? AND status='reserved'");
            foreach ($items as $item) {
                $markFailed->execute([$item['id']]);
                $release->execute([$item['event_id'], $item['seat_id']]);
            }
            flash('Payment rejected. No valid tickets were issued and reserved seats were released.', 'error');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e instanceof RuntimeException ? $e->getMessage() : 'Unable to process payment. Check the database schema.', 'error');
        if (!$e instanceof RuntimeException) error_log('Payment approval error: ' . $e->getMessage());
    }
    redirect('payments.php');
}
$rows=$pdo->query("SELECT tr.*, u.firstName, u.lastName, u.email, e.event_name, e.event_date, e.venue, s.seat_number FROM transactions tr LEFT JOIN users u ON u.id=tr.user_id LEFT JOIN events e ON e.id=tr.event_id LEFT JOIN seats s ON s.id=tr.seat_id WHERE tr.status='pending' AND tr.gcash_reference IS NOT NULL ORDER BY tr.payment_submitted_at ASC")->fetchAll();
$flash=take_flash(); $admin=current_user();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Payment Review | PLMun Admin</title><link rel="stylesheet" href="../assets/css/style.css"></head><body class="dashboard-page admin-page"><aside class="sidebar"><a class="brand sidebar-brand" href="dashboard.php"><span class="brand-mark">P</span><span><strong>PLMun Events</strong><small>ADMIN</small></span></a><div class="sidebar-label">WORKSPACE</div><a class="side-link" href="dashboard.php">▦ Overview</a><a class="side-link active" href="payments.php">₱ Payment review</a><a class="side-link" href="seat-management.php">Seat Management</a><a class="side-link" href="reports.php">↗ Reports</a><div class="sidebar-bottom"><div><strong><?=e(($admin['firstName']??'').' '.($admin['lastName']??''))?></strong><small>Administrator</small></div><a class="side-logout" href="../logout.php">↗</a></div></aside><main class="admin-content"><header class="admin-topbar"><div><span class="muted">PLMUN / ADMIN</span><h1>Payment review</h1></div><a class="btn btn-outline" href="dashboard.php">Back to dashboard</a></header><?php if($flash): ?><div class="alert alert-<?=e($flash['type'])?>"><?=e($flash['message'])?></div><?php endif; ?><section class="panel"><div class="panel-heading"><div><h2>GCash submissions</h2><p>Verify the actual received payment before approving.</p></div><span class="panel-index"><?=count($rows)?> PENDING</span></div><?php if(!$rows): ?><div class="empty-state compact"><h3>No payments awaiting review</h3><p>Submitted GCash references will appear here.</p></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>User</th><th>Event</th><th>Amount</th><th>GCash reference</th><th>System reference</th><th>Submitted</th><th>Action</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><strong><?=e(trim(($r['firstName']??'').' '.($r['lastName']??'')))?></strong><small class="cell-sub"><?=e($r['email']??'')?></small></td><td><?=e($r['event_name']??'Event unavailable')?><small class="cell-sub"><?=e($r['venue']??'')?></small></td><td>₱<?=number_format((float)$r['amount'],2)?></td><td><code><?=e($r['gcash_reference'])?></code></td><td><code><?=e($r['transaction_reference'])?></code></td><td><?=e($r['payment_submitted_at']??'—')?></td><td><form method="post" class="form-stack"><input type="hidden" name="transaction_id" value="<?=(int)$r['id']?>"><button class="btn btn-dark btn-small" name="action" value="approve" type="submit">Approve</button><button class="btn btn-outline btn-small" name="action" value="reject" type="submit" onclick="return confirm('Reject this payment submission?')">Reject</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><p class="footnote">Approve only after checking the actual GCash receipt/payment. The reference number alone does not prove payment.</p></main></body></html>
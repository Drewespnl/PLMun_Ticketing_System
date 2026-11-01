<?php
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/payment.php';
require_login();
$user = current_user();
$group = trim($_GET['group'] ?? $_POST['group'] ?? '');
if (!preg_match('/^PAY-[A-F0-9]{16}$/', $group)) { http_response_code(400); exit('Invalid checkout reference.'); }
$stmt = $pdo->prepare("SELECT tr.*, e.event_name, e.event_date, e.event_time, e.venue FROM transactions tr JOIN events e ON e.id=tr.event_id WHERE tr.payment_group_ref=? AND tr.user_id=? ORDER BY tr.id");
$stmt->execute([$group, $user['id']]);
$items = $stmt->fetchAll();
if (!$items) { http_response_code(404); exit('Checkout not found.'); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ref = strtoupper(trim($_POST['gcash_reference'] ?? ''));
    if (strlen($ref) < 6 || strlen($ref) > 100 || !preg_match('/^[A-Z0-9-]+$/', $ref)) {
        $error = 'Enter the GCash reference number shown in your transaction receipt.';
    } else {
        try {
            $pdo->beginTransaction();
            $check = $pdo->prepare("SELECT id FROM transactions WHERE payment_group_ref=? AND user_id=? AND status='pending' AND gcash_reference IS NULL FOR UPDATE");
            $check->execute([$group, $user['id']]);
            $ids = $check->fetchAll(PDO::FETCH_COLUMN);
            if (!$ids) throw new RuntimeException('This payment has already been submitted or is no longer pending.');
            $update = $pdo->prepare("UPDATE transactions SET gcash_reference=?, payment_submitted_at=NOW(), payment_method='GCash' WHERE payment_group_ref=? AND user_id=? AND status='pending' AND gcash_reference IS NULL");
            $update->execute([$ref, $group, $user['id']]);
            // Keep these seats reserved until the admin verifies/rejects the submitted payment.
            $releaseExpiry = $pdo->prepare("UPDATE event_seats es JOIN transactions tr ON tr.event_id=es.event_id AND tr.seat_id=es.seat_id SET es.reservation_expires_at=NULL WHERE tr.payment_group_ref=? AND tr.user_id=? AND es.status='reserved' AND es.reserved_by=?");
            $releaseExpiry->execute([$group, $user['id'], $user['id']]);
            $pdo->commit();
            flash('GCash reference submitted. Your payment is awaiting admin verification; your ticket is not valid yet.');
            redirect('dashboard.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not submit payment. Please try again.';
            if (!$e instanceof RuntimeException) error_log('Checkout submit error: '.$e->getMessage());
        }
    }
}
$total = array_sum(array_map(fn($r)=>(float)$r['amount'], $items));
$alreadySubmitted = !empty($items[0]['gcash_reference']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>GCash Checkout | PLMun Events</title><link rel="stylesheet" href="../assets/css/style.css"></head>
<body class="dashboard-page"><header class="site-header"><a class="brand" href="../index.php"><span class="brand-mark">P</span><span><strong>PLMun Events</strong><small>Digital Ticketing Portal</small></span></a><div class="header-actions"><a class="text-link" href="dashboard.php">My bookings</a></div></header>
<main class="dashboard-main narrow-main"><a class="back-link" href="../index.php#events">← All events</a><section class="panel booking-panel"><div class="eyebrow dark-eyebrow">CHECKOUT · GCASH</div><h1>Complete your payment</h1><p class="muted">Send the exact amount to the official account below, then enter the reference number shown on your GCash receipt. Admin approval is required.</p>
<?php if ($error): ?><div class="alert alert-error"><?=e($error)?></div><?php endif; ?>
<div class="booking-info"><div><small>GCASH ACCOUNT NAME</small><strong><?=e($gcashAccountName)?></strong></div><div><small>GCASH NUMBER</small><strong><?=e($gcashAccountNumber)?></strong></div><div><small>TOTAL AMOUNT</small><strong>₱<?=number_format($total,2)?></strong></div><div><small>SYSTEM PAYMENT REFERENCE</small><strong><?=e($group)?></strong></div></div>
<div class="table-wrap"><table><thead><tr><th>Event</th><th>System reference</th><th>Amount</th></tr></thead><tbody><?php foreach($items as $item): ?><tr><td><?=e($item['event_name'])?></td><td><code><?=e($item['transaction_reference'])?></code></td><td>₱<?=number_format((float)$item['amount'],2)?></td></tr><?php endforeach; ?></tbody></table></div>
<?php if ($alreadySubmitted): ?><div class="alert alert-success">Reference <?=e($items[0]['gcash_reference'])?> was submitted. Please wait for admin verification.</div><?php else: ?><form method="post" class="form-stack"><input type="hidden" name="group" value="<?=e($group)?>"><label>GCash reference number<input name="gcash_reference" required minlength="6" maxlength="100" pattern="[A-Za-z0-9-]+" placeholder="Enter reference from GCash receipt"></label><p class="muted">Do not enter your MPIN or OTP. Never share these with anyone.</p><button class="btn btn-dark" type="submit">Submit payment reference</button></form><?php endif; ?></section></main></body></html>
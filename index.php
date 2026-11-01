<?php
date_default_timezone_set('Asia/Manila');

require __DIR__ . '/includes/functions.php';
require __DIR__ . '/config/db.php';

$events = $pdo->query("
    SELECT
        id,
        event_name,
        description,
        event_date,
        event_time,
        venue,
        ticket_price,
        capacity,
        status,
        created_at
    FROM events
    WHERE status IN ('published', 'upcoming', 'ongoing', 'completed', 'cancelled')
    ORDER BY event_date ASC, event_time ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Automatic event status based on the event date and time.
foreach ($events as &$event) {
    $eventDateTime = strtotime(
        $event['event_date'] . ' ' . ($event['event_time'] ?: '23:59:59')
    );

    $originalStatus = strtolower($event['status']);

    if ($originalStatus === 'cancelled') {
        $event['display_status'] = 'Cancelled';
        $event['can_book'] = false;
    } elseif (
        $originalStatus === 'completed' ||
        $eventDateTime === false ||
        $eventDateTime < time()
    ) {
        $event['display_status'] = 'Ended';
        $event['can_book'] = false;
    } else {
        $event['display_status'] = ucfirst($originalStatus);
        $event['can_book'] = in_array(
            $originalStatus,
            ['upcoming', 'ongoing'],
            true
        );
    }
}
unset($event);

$flash = take_flash();
$notice = $_GET['notice'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PLMun Events | Digital Ticketing</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <script defer src="assets/js/app.js"></script>
</head>
<body>
<header class="site-header">
  <a class="brand"><span><strong>PLMun Events</strong><small>Digital Ticketing Portal</small></span></a>
  <nav class="main-nav"><a href="#events">Explore events</a><a href="#how">How it works</a></nav>
  <div class="header-actions">
    <?php if (current_user()): ?>
      <span class="welcome">Hi, <?= e(current_user()['firstName']) ?></span>
      <a class="btn btn-dark btn-small" href="<?= current_user()['role'] === 'admin' ? 'admin/dashboard.php' : 'user/dashboard.php' ?>">Dashboard</a>
      <a class="text-link" href="logout.php">Sign out</a>
    <?php else: ?>
      <a class="text-link" href="login.php">Sign in</a>
      <a class="btn btn-dark btn-small" href="register.php">Create account</a>
    <?php endif; ?>
  </div>
</header>

<main>
  <section class="hero">
    <div class="hero-copy">
      <div class="eyebrow"><span class="eyebrow-dot"></span> PAMANTASAN NG LUNGSOD NG MUNTINLUPA</div>
      <h1>Your campus.<br><span>Your moments.</span></h1>
      <p class="hero-lede">Discover school events, choose your seats, and keep your tickets in one secure place.</p>
      <div class="hero-actions"><a class="btn btn-light" href="#events">Explore events <span aria-hidden="true">↗</span></a><a class="hero-secondary" href="#how">How it works <span>↓</span></a></div>
      <div class="hero-proof"><div class="avatar-stack"><span>PL</span><span>✓</span><span>+</span></div><span>Made for the PLMun community</span></div>
    </div>
    <div class="hero-art" aria-label="Event ticket illustration">
      <div class="orbit orbit-one"></div><div class="orbit orbit-two"></div>
      <div class="floating-label label-top"><span class="live-dot"></span> CAMPUS LIFE, CONNECTED</div>
      <div class="ticket-card">
        <div class="ticket-top"><span class="ticket-tag">DIGITAL PASS</span></div>
        <div class="ticket-event">Make every<br>moment <em>count.</em></div>
        <div class="ticket-line"></div>
        <div class="ticket-meta"><span><small>ISSUED BY</small><strong>PLMun Events</strong></span><span><small>ACCESS</small><strong>Student / Guest</strong></span></div>
        <div class="ticket-bottom"><div class="fake-qr"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div><span>YOUR NEXT CAMPUS<br>MEMORY STARTS HERE</span><span class="ticket-arrow">↗</span></div>
      </div>
      <div class="floating-label label-bottom"><span class="label-icon">✓</span><span><strong>One simple place</strong><small>Events, seats & tickets</small></span></div>
    </div>
  </section>

  <section class="stats-strip"><div><strong>01</strong><span>Discover events</span></div><div><strong>02</strong><span>Reserve a seat</span></div><div><strong>03</strong><span>Get your ticket</span></div><div class="stats-note">A smoother way to show up.</div></section>

  <section class="section events-section" id="events">
    <div class="section-heading"><div><div class="eyebrow dark-eyebrow">WHAT'S HAPPENING</div><h2>Find your next <span>campus moment.</span></h2><p>Explore upcoming activities organized for the PLMun community.</p></div>
      <div class="event-tools"><label class="search-box"><span>⌕</span><input id="eventSearch" type="search" placeholder="Search events..." aria-label="Search events"></label><select id="eventFilter" aria-label="Filter events"><option value="all">All events</option><option value="free">Free events</option><option value="paid">With ticket fee</option></select></div>
    </div>
    <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
    <?php if ($notice === 'login'): ?><div class="alert alert-info">Please sign in to continue booking.</div><?php endif; ?>
    <div class="event-grid" id="eventGrid">
      <?php foreach ($events as $event): ?>
        <article
          class="event-card"
          data-title="<?= e(mb_strtolower($event['event_name'] . ' ' . $event['venue'])) ?>"
          data-price="<?= (float)($event['ticket_price'] ?? 0) ?>"
        >
          <div class="event-cover cover-<?= (int)$event['id'] % 4 ?>">
            <span class="event-type">
              <?= e($event['display_status']) ?>
            </span>
            <span class="cover-monogram">P<span>L</span></span>
            <span class="cover-caption">PLMun <strong>Campus Events</strong></span>
          </div>

          <div class="event-card-body">
            <div class="event-date">
              <span><?= e(date('M', strtotime($event['event_date']))) ?></span>
              <strong><?= e(date('d', strtotime($event['event_date']))) ?></strong>
            </div>

            <div class="event-details">
              <h3><?= e($event['event_name']) ?></h3>
              <p><?= e(mb_strimwidth($event['description'] ?? '', 0, 105, '…')) ?></p>
              <div class="event-location">⌖ <?= e($event['venue']) ?></div>
            </div>

            <div class="event-card-footer">
              <span class="price">
                <?= (float)($event['ticket_price'] ?? 0) > 0
                  ? '₱' . number_format((float)$event['ticket_price'], 2)
                  : 'Free' ?>
              </span>
              <?php if ($event['can_book']): ?>
                <a class="round-arrow"
                href="<?= current_user()
                ? 'user/book.php?event_id=' . (int)$event['id']
                : 'login.php?notice=login' ?>"
                aria-label="Book <?= e($event['event_name']) ?>"
                >↗</a>
                <?php else: ?>
                  <span class="booking-closed">Booking closed</span>
                  <?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>

      <?php if (!$events): ?>
        <div class="empty-state">
          <div class="empty-icon">✳</div>
          <h3>Events are on the way.</h3>
          <p>There are no published events yet. Check back soon or ask an administrator to publish an event.</p>
        </div>
    <?php endif; ?>
  </div>
  </section>

  <section class="how-section" id="how"><div class="how-intro"><div class="eyebrow">SIMPLE BY DESIGN</div><h2>From browsing to<br><span>being there.</span></h2><p>Less time in line. More time making memories.</p></div><div class="how-steps"><article><span>01</span><div class="step-icon">⌕</div><h3>Discover</h3><p>Browse upcoming PLMun school events and check the details before booking.</p></article><article><span>02</span><div class="step-icon">▦</div><h3>Choose & reserve</h3><p>Select an available seat and complete the booking process securely.</p></article><article><span>03</span><div class="step-icon">↗</div><h3>Show your ticket</h3><p>Keep your booking reference ready for event staff to verify at the venue.</p></article></div></section>
</main>
<footer class="site-footer"><span><strong>PLMun Events</strong><small> | Digital Ticketing Events</small></span><p>School events at Pamantasan ng Lungsod ng Muntinlupa.</p><span>© <?= date('Y') ?> PLMun Events</span></footer>
</body>
</html>


<?php
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/mail.php';
require __DIR__ . '/config/db.php';

$message = '';
$error = '';
$prefill = strtolower(trim($_GET['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $prefill = $email;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, firstName,
                    (otp_sent_at IS NOT NULL AND
                     otp_sent_at > DATE_SUB(
                         NOW(),
                         INTERVAL ' . VERIFICATION_RESEND_COOLDOWN_SECONDS . ' SECOND
                     )) AS cooling_down
             FROM users
             WHERE email = ?
               AND is_verified = 0
             LIMIT 1'
        );

        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && (int)$user['cooling_down'] === 0) {
            try {
                $token = issue_verification_token($pdo, (int)$user['id']);

                if (send_verification_email($email, $user['firstName'], $token)) {
                    $message = 'If that email belongs to an unverified account, a new verification link is on its way.';
                } else {
                    $error = 'We could not send the verification email. Please try again later.';
                }
            } catch (Throwable $e) {
                error_log('Resend verification failed: ' . $e->getMessage());
                $error = 'We could not process your request. Please try again later.';
            }
        } else {
            // Keep the response generic to avoid revealing registered emails.
            $message = 'If that email belongs to an unverified account, a new verification link is on its way. You can request another one after ' .
                VERIFICATION_RESEND_COOLDOWN_SECONDS . ' seconds.';
        }

        if ($message === '') {
            $message = 'If that email belongs to an unverified account, a new verification link is on its way. You can request another one after ' .
                VERIFICATION_RESEND_COOLDOWN_SECONDS . ' seconds.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Resend verification | PLMun Events</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-page">
<a class="auth-back" href="index.php">← Back to events</a>

<main class="auth-layout">
    <section class="auth-brand-panel">
        <div class="eyebrow">
            <span class="eyebrow-dot"></span>
            PLMUN DIGITAL TICKETING
        </div>
        <h1>Didn't get<br><em>the email?</em></h1>
        <p>Enter your email and we'll send you a fresh verification link. Check your spam folder too.</p>
        <div class="auth-decor">P<span>L</span></div>
    </section>

    <section class="auth-form-panel">
        <div class="auth-form-wrap">
            <div class="eyebrow dark-eyebrow">EMAIL VERIFICATION</div>
            <h2>Resend verification link</h2>
            <p class="muted">We'll email you a new link that works for <?= VERIFICATION_TTL_HOURS ?> hours.</p>

            <?php if ($message): ?>
                <div class="alert alert-success"><?= e($message) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" class="form-stack">
                <label>
                    Email address
                    <input
                        type="email"
                        name="email"
                        required
                        autocomplete="email"
                        placeholder="you@example.com"
                        value="<?= e($prefill) ?>"
                    >
                </label>
                <button class="btn btn-dark btn-full" type="submit">
                    Send verification link <span>↗</span>
                </button>
            </form>

            <p class="auth-switch">
                Already verified? <a href="login.php">Sign in</a>
            </p>
        </div>
    </section>
</main>
</body>
</html>
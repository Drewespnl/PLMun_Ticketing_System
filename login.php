
<?php
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/config/db.php';

$error = '';
$unverifiedEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare(
        'SELECT id, firstName, lastName, email, password, role,
                is_verified, account_status
         FROM users
         WHERE email = ?
         LIMIT 1'
    );

    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {

        // Block deactivated accounts.
        if (($user['account_status'] ?? 'active') !== 'active') {
            $error = 'Your account has been deactivated. Please contact the administrator.';

        // Block unverified accounts.
        } elseif ((int)$user['is_verified'] !== 1) {
            $error = 'Please verify your email before signing in. Check your inbox for the verification link.';
            $unverifiedEmail = $user['email'];

        } else {

            // UPDATE LAST LOGIN DATE AND TIME
            $updateLogin = $pdo->prepare("
                UPDATE users
                SET last_login = NOW()
                WHERE id = ?
            ");

            $updateLogin->execute([(int)$user['id']]);

            // Regenerate session after successful login.
            session_regenerate_id(true);

            unset(
                $user['password'],
                $user['is_verified'],
                $user['account_status']
            );

            $_SESSION['user'] = $user;

            redirect(
                $user['role'] === 'admin'
                    ? 'admin/dashboard.php'
                    : 'user/dashboard.php'
            );
        }

    } else {
        $error = 'Incorrect email or password.';
    }
}

$flash = take_flash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | PLMun Events</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/eyeicon.css">
    <link rel="stylesheet" href="assets/css/background.css">
    <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>

<body class="auth-page">

<a class="auth-back" href="index.php">← Back to events</a>

<main class="auth-layout">

    <section class="auth-brand-panel">
        <div class="eyebrow">
            <span class="eyebrow-dot"></span>
            PLMUN DIGITAL TICKETING
        </div>

        <h1>Be part of<br><em>something.</em></h1>

        <p>
            Your campus events, bookings, and tickets—together in one place.
        </p>

        <div class="auth-decor">P<span>L</span></div>
    </section>

    <section class="auth-form-panel">
        <div class="auth-form-wrap">

            <div class="eyebrow dark-eyebrow">WELCOME BACK</div>

            <h2>Sign in to your account</h2>
            <p class="muted">Pick up where your campus life left off.</p>

            <?php if ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?= e($error) ?>

                    <?php if ($unverifiedEmail): ?>
                        <a href="resend-verification.php?email=<?= urlencode($unverifiedEmail) ?>">
                            <strong>Resend verification email</strong>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (($_GET['notice'] ?? '') === 'login'): ?>
                <div class="alert alert-info">
                    Sign in first to reserve a ticket.
                </div>
            <?php endif; ?>

            <form method="post" class="form-stack">

                <label>
                    Email address
                    <input
                        type="email"
                        name="email"
                        required
                        autocomplete="email"
                        placeholder="Email"
                    >
                </label>

                <label>
                    Password
                    <div class="password-wrapper">
                        <input
                            type="password"
                            name="password"
                            id="loginPassword"
                            required
                            autocomplete="current-password"
                            placeholder="Enter your password"
                        >

                        <button
                            type="button"
                            class="toggle-password"
                            data-target="loginPassword"
                            aria-label="Show password"
                        >
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </label>

                <button class="btn btn-dark btn-full" type="submit">
                    Sign in <span>↗</span>
                </button>

            </form>

            <p class="auth-switch">
                New to PLMun Events?
                <a href="register.php">Create an account</a>
            </p>

        </div>
    </section>

</main>
<script src="assets/js/eyeicon.js"></script>
</body>
</html>
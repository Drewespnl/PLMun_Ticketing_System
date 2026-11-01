
<?php
session_start();

require __DIR__ . '/config/db.php';

$error = '';
$success = '';
$pending = $_SESSION['pending_registration'] ?? null;

// The verification page only works for a pending registration.
if (!is_array($pending)) {
    $error = 'No pending registration found. Please register again.';
} elseif (
    empty($pending['email']) ||
    empty($pending['passwordHash']) ||
    empty($pending['otpHash']) ||
    !isset($pending['expiresAt'])
) {
    unset($_SESSION['pending_registration']);
    $pending = null;
    $error = 'Your registration session is invalid. Please register again.';
} elseif (time() >= (int) $pending['expiresAt']) {
    unset($_SESSION['pending_registration']);
    $pending = null;
    $error = 'Your verification code has expired. Please register again.';
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    is_array($pending)
) {
    $otp = trim($_POST['otp'] ?? '');

    if (!preg_match('/^[0-9]{6}$/', $otp)) {
        $error = 'Enter the 6-digit verification code.';
    } else {
        // Limit incorrect attempts for this pending registration.
        $_SESSION['pending_registration']['attempts'] =
            (int) ($_SESSION['pending_registration']['attempts'] ?? 0);

        if (
            $_SESSION['pending_registration']['attempts'] >= 5
        ) {
            unset($_SESSION['pending_registration']);
            $pending = null;
            $error = 'Too many incorrect attempts. Please register again.';
        } elseif (
            !hash_equals(
                $pending['otpHash'],
                hash('sha256', $otp)
            )
        ) {
            $_SESSION['pending_registration']['attempts']++;

            if (
                $_SESSION['pending_registration']['attempts'] >= 5
            ) {
                unset($_SESSION['pending_registration']);
                $pending = null;
                $error = 'Too many incorrect attempts. Please register again.';
            } else {
                $remaining = 5 -
                    $_SESSION['pending_registration']['attempts'];

                $error = 'Incorrect verification code. Attempts remaining: '
                    . $remaining . '.';
            }
        } else {
            try {
                // Create the account ONLY after the correct OTP.
                $stmt = $pdo->prepare(
                    "INSERT INTO users
                    (firstName, lastName, email, password, role,
                     is_verified, email_verified_at)
                    VALUES (?, ?, ?, ?, 'user', 1, NOW())"
                );

                $stmt->execute([
                    $pending['firstName'],
                    $pending['lastName'],
                    $pending['email'],
                    $pending['passwordHash']
                ]);

                // Clear pending registration after successful insertion.
                unset($_SESSION['pending_registration']);
                session_regenerate_id(true);

                $pending = null;
                $success = 'Email verified successfully! You can now sign in.';
            } catch (PDOException $e) {
                error_log(
                    'OTP verification/database error: ' . $e->getMessage()
                );

                $mysqlErrorCode = (int) ($e->errorInfo[1] ?? 0);

                if ($mysqlErrorCode === 1062) {
                    unset($_SESSION['pending_registration']);
                    $pending = null;
                    $error = 'This email is already registered. Please sign in.';
                } else {
                    $error = 'Unable to create your account right now. Please try again.';
                }
            }
        }
    }
}

$email = is_array($pending)
    ? ($pending['email'] ?? '')
    : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Email | PLMun Ticketing System</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-page">
    <main class="auth-form-panel">
        <div class="auth-form-wrap">
            <h2>Verify your email</h2>

            <?php if ($email !== ''): ?>
                <p class="muted">
                    Enter the 6-digit code sent to
                    <?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>.
                </p>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <p><a href="login.php">Continue to login</a></p>

            <?php elseif (is_array($pending)): ?>
                <form method="post" class="form-stack">
                    <label>
                        6-digit code
                        <input
                            name="otp"
                            type="text"
                            inputmode="numeric"
                            pattern="[0-9]{6}"
                            minlength="6"
                            maxlength="6"
                            autocomplete="one-time-code"
                            required
                        >
                    </label>

                    <button class="btn btn-dark btn-full" type="submit">
                        Verify email
                    </button>
                </form>
            <?php else: ?>
                <p><a href="register.php">Register again</a></p>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
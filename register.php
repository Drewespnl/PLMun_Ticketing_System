
<?php
session_start();

require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/brevo-mail.php';

$error = '';
$first = '';
$last = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($first === '' || $last === '' || $email === '' || $password === '') {
        $error = 'Please complete all fields.';
    } elseif (
        strlen($first) > 80 ||
        strlen($last) > 80 ||
        strlen($email) > 254
    ) {
        $error = 'One or more fields exceed the allowed length.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!str_ends_with($email, '@gmail.com')) {
        $error = 'Only @gmail.com email addresses are allowed.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        try {
            // Check for an existing account.
            $check = $pdo->prepare(
                'SELECT id FROM users WHERE email = ? LIMIT 1'
            );
            $check->execute([$email]);

            if ($check->fetch(PDO::FETCH_ASSOC)) {
                $error = 'This email is already registered. Please sign in or use another email.';
            } else {
                // Generate a one-time verification code.
                $otp = (string) random_int(100000, 999999);
                $otpHash = hash('sha256', $otp);
                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                // Send the email BEFORE creating a database account.
                if (sendBrevoOtpEmail($email, $first, $otp)) {
                    // Keep pending registration details in the session.
                    $_SESSION['pending_registration'] = [
                        'firstName' => $first,
                        'lastName' => $last,
                        'email' => $email,
                        'passwordHash' => $passwordHash,
                        'otpHash' => $otpHash,
                        'expiresAt' => time() + 600
                    ];

                    header('Location: verify-otp.php');
                    exit;
                }

                $error = 'We could not send the verification email. Please check the address or try again later.';
            }
        } catch (PDOException $e) {
            error_log('Registration error: ' . $e->getMessage());

            $mysqlErrorCode = (int) ($e->errorInfo[1] ?? 0);

            if ($mysqlErrorCode === 1062) {
                $error = 'This email is already registered. Please sign in or use another email.';
            } else {
                $error = 'Unable to complete registration. Please try again later.';
            }
        } catch (Throwable $e) {
            error_log('Registration or email error: ' . $e->getMessage());
            $error = 'Unable to process your registration. Please try again later.';
        }
    }
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create Account | PLMun Ticketing System</title>
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
            <h1>Your next<br><em>memory awaits.</em></h1>
            <p>Create your account to book seats and manage your PLMun event tickets.</p>
        </section>

        <section class="auth-form-panel">
            <div class="auth-form-wrap">
                <h2>Create your account</h2>
                <p class="muted">We'll email you a verification code.</p>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-error">
                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="form-stack">
                    <div class="form-row">
                        <label>
                            First name
                            <input name="first_name" required maxlength="80"
                                   autocomplete="given-name"
                                   value="<?= htmlspecialchars($first, ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>
                            Last name
                            <input name="last_name" required maxlength="80"
                                   autocomplete="family-name"
                                   value="<?= htmlspecialchars($last, ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                    </div>
                    
                    <label>
                        Email address
                        <input
                            type="email"
                            name="email"
                            required
                            maxlength="254"
                            pattern="[a-zA-Z0-9._%+-]+@gmail\.com"
                            title="Please use an email address ending in @gmail.com"
                            placeholder="Email"
                            autocomplete="email"
                            value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                        >
                    </label>

                    
            <label for="password">Password</label>
            <div class="password-wrapper">
                <input
                    type="password"
                    name="password"
                    id="password"
                    placeholder="Enter password"
                    required
                >
                <button type="button" class="toggle-password"
                        data-target="password" aria-label="Show password">
                    <i class="fa-solid fa-eye"></i>
                </button>
            </div>

            <label for="confirm_password">Confirm Password</label>
            <div class="password-wrapper">
                <input
                    type="password"
                    name="confirm_password"
                    id="confirm_password"
                    placeholder="Confirm password"
                    required
                >
                <button type="button" class="toggle-password"
                        data-target="confirm_password" aria-label="Show password">
                    <i class="fa-solid fa-eye"></i>
                </button>
            </div>

            <p id="password-match-message" aria-live="polite"></p>


                    <button class="btn btn-dark btn-full" type="submit">
                        Create account
                    </button>
                </form>

                <p class="auth-switch">
                    Already have an account? <a href="login.php">Sign in</a>
                </p>
            </div>
        </section>
    </main>
</body>
<script src="assets/js/eyeicon.js"></script>
</html>

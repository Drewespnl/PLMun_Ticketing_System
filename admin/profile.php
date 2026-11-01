
<?php
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../config/db.php';

require_admin();

$user = current_user();
$userId = (int) ($user['id'] ?? 0);

if ($userId <= 0) {
    redirect('../login.php');
}

$error = '';
$success = '';
$activeForm = 'profile';

if (empty($_SESSION['profile_csrf'])) {
    $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
}

function profile_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| LOAD ADMIN
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare(
    "SELECT id, firstName, lastName, email, password,
            role, profile_picture
     FROM users
     WHERE id = :id AND role = 'admin'
     LIMIT 1"
);
$stmt->execute(['id' => $userId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    http_response_code(403);
    exit('Access denied.');
}

/*
|--------------------------------------------------------------------------
| PROCESS PROFILE OR PASSWORD FORM
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $activeForm = $_POST['form_type'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['profile_csrf'], $token)
    ) {
        $error = 'Invalid request. Please refresh the page.';
    } elseif (!in_array($activeForm, ['profile', 'password'], true)) {
        $error = 'Invalid form submission.';
    } else {
        try {
            /*
            |--------------------------------------------------------------------------
            | PROFILE INFORMATION ONLY
            |--------------------------------------------------------------------------
            */
            if ($activeForm === 'profile') {
                $firstName = trim($_POST['firstName'] ?? '');
                $lastName = trim($_POST['lastName'] ?? '');

                if ($firstName === '' || $lastName === '') {
                    throw new RuntimeException(
                        'Please enter your first and last name.'
                    );
                }

                if (
                    strlen($firstName) > 100 ||
                    strlen($lastName) > 100
                ) {
                    throw new RuntimeException(
                        'Your name is too long.'
                    );
                }

                $newPicture = null;
                $uploadDirectory =
                    __DIR__ . '/../assets/uploads/admin/';

                /*
                |--------------------------------------------------------------------------
                | VALIDATE PROFILE IMAGE
                |--------------------------------------------------------------------------
                */
                if (
                    isset($_FILES['profile_picture']) &&
                    $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE
                ) {
                    $file = $_FILES['profile_picture'];

                    if ($file['error'] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException(
                            'Profile picture upload failed.'
                        );
                    }

                    if ($file['size'] > 2 * 1024 * 1024) {
                        throw new RuntimeException(
                            'Image must not exceed 2 MB.'
                        );
                    }

                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($file['tmp_name']);

                    $allowedTypes = [
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/webp' => 'webp',
                    ];

                    if (
                        !isset($allowedTypes[$mime]) ||
                        @getimagesize($file['tmp_name']) === false
                    ) {
                        throw new RuntimeException(
                            'Use a valid JPG, PNG, or WebP image.'
                        );
                    }

                    if (
                        !is_dir($uploadDirectory) &&
                        !mkdir($uploadDirectory, 0755, true) &&
                        !is_dir($uploadDirectory)
                    ) {
                        throw new RuntimeException(
                            'Unable to create the upload folder.'
                        );
                    }

                    $newPicture =
                        bin2hex(random_bytes(16)) .
                        '.' . $allowedTypes[$mime];

                    if (!move_uploaded_file(
                        $file['tmp_name'],
                        $uploadDirectory . $newPicture
                    )) {
                        $newPicture = null;

                        throw new RuntimeException(
                            'Unable to save the profile picture.'
                        );
                    }
                }

                try {
                    $sql = "UPDATE users
                            SET firstName = :firstName,
                                lastName = :lastName";

                    $params = [
                        'firstName' => $firstName,
                        'lastName'  => $lastName,
                        'id'        => $userId,
                    ];

                    if ($newPicture !== null) {
                        $sql .= ", profile_picture = :picture";
                        $params['picture'] = $newPicture;
                    }

                    $sql .= " WHERE id = :id AND role = 'admin'";

                    $update = $pdo->prepare($sql);
                    $update->execute($params);

                    if ($update->rowCount() === 0) {
                        $check = $pdo->prepare(
                            "SELECT id FROM users
                             WHERE id = :id AND role = 'admin'"
                        );
                        $check->execute(['id' => $userId]);

                        if (!$check->fetch()) {
                            throw new RuntimeException(
                                'Admin account was not found.'
                            );
                        }
                    }

                    // Update session data used by the application.
                    if (isset($_SESSION['user']) &&
                        is_array($_SESSION['user'])) {
                        $_SESSION['user']['firstName'] = $firstName;
                        $_SESSION['user']['lastName'] = $lastName;
                    }

                    $_SESSION['firstName'] = $firstName;
                    $_SESSION['lastName'] = $lastName;

                    // Delete old image only after the database update.
                    if ($newPicture !== null) {
                        $oldPicture = $admin['profile_picture'] ?? '';

                        if ($oldPicture !== '') {
                            $oldPath = $uploadDirectory .
                                basename($oldPicture);

                            if (is_file($oldPath)) {
                                @unlink($oldPath);
                            }
                        }
                    }

                    header('Location: profile.php?updated=profile');
                    exit;
                } catch (Throwable $e) {
                    if ($newPicture !== null) {
                        $newPath = $uploadDirectory . $newPicture;

                        if (is_file($newPath)) {
                            @unlink($newPath);
                        }
                    }

                    throw $e;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | CHANGE PASSWORD ONLY
            |--------------------------------------------------------------------------
            */
            if ($activeForm === 'password') {
                $currentPassword = $_POST['current_password'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';

                if (
                    $currentPassword === '' ||
                    $newPassword === '' ||
                    $confirmPassword === ''
                ) {
                    throw new RuntimeException(
                        'Please complete all password fields.'
                    );
                }

                if (!password_verify(
                    $currentPassword,
                    $admin['password']
                )) {
                    throw new RuntimeException(
                        'Your current password is incorrect.'
                    );
                }

                if (strlen($newPassword) < 8) {
                    throw new RuntimeException(
                        'New password must be at least 8 characters.'
                    );
                }

                if ($newPassword !== $confirmPassword) {
                    throw new RuntimeException(
                        'New passwords do not match.'
                    );
                }

                if (password_verify(
                    $newPassword,
                    $admin['password']
                )) {
                    throw new RuntimeException(
                        'Your new password must be different from your current password.'
                    );
                }

                $hashedPassword = password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );

                $update = $pdo->prepare(
                    "UPDATE users
                     SET password = :password
                     WHERE id = :id AND role = 'admin'"
                );

                $update->execute([
                    'password' => $hashedPassword,
                    'id' => $userId,
                ]);

                if ($update->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Unable to update the password.'
                    );
                }

                header('Location: profile.php?updated=password');
                exit;
            }
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $error = 'Unable to save your changes. Please try again.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| REFRESH ADMIN DETAILS
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare(
    "SELECT id, firstName, lastName, email, password,
            role, profile_picture
     FROM users
     WHERE id = :id AND role = 'admin'
     LIMIT 1"
);
$stmt->execute(['id' => $userId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    http_response_code(403);
    exit('Access denied.');
}

if (isset($_GET['updated'])) {
    if ($_GET['updated'] === 'profile') {
        $success = 'Profile information updated successfully.';
    } elseif ($_GET['updated'] === 'password') {
        $success = 'Password changed successfully.';
    }
}

$pictureUrl = !empty($admin['profile_picture'])
    ? '../assets/uploads/admin/' .
        rawurlencode(basename($admin['profile_picture']))
    : '';

$displayName = trim(
    ($admin['firstName'] ?? '') . ' ' .
    ($admin['lastName'] ?? '')
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile | PLMun Events</title>

    <link rel="stylesheet" href="../assets/css/profile.css">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body>

<main class="profile-page">

    <h1>Admin Profile</h1>

    <p class="subtitle">
        Manage your profile information and password separately.
    </p>

    <section class="profile-card">

        <div class="profile-header">
            <div class="profile-picture">
                <?php if ($pictureUrl !== ''): ?>
                    <img src="<?= profile_h($pictureUrl) ?>"
                         alt="Admin profile picture">
                <?php else: ?>
                    <i class="fa-solid fa-user" aria-hidden="true"></i>
                <?php endif; ?>
            </div>

            <div>
                <h2><?= profile_h($displayName) ?></h2>
                <p>Administrator</p>
                <p><?= profile_h($admin['email']) ?></p>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="message error">
                <?= profile_h($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="message success">
                <?= profile_h($success) ?>
            </div>
        <?php endif; ?>

        <!-- PROFILE INFORMATION FORM -->
        <section class="profile-section">
            <h2>Profile Information</h2>
            <p class="section-description">
                Update your name and profile picture without changing your password.
            </p>

            <form method="POST"
                  action="profile.php"
                  enctype="multipart/form-data">

                <input type="hidden" name="csrf_token"
                       value="<?= profile_h($_SESSION['profile_csrf']) ?>">
                <input type="hidden" name="form_type" value="profile">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="firstName">First Name</label>
                        <input type="text"
                               id="firstName"
                               name="firstName"
                               maxlength="100"
                               value="<?= profile_h(
                                   $activeForm === 'profile' &&
                                   $_SERVER['REQUEST_METHOD'] === 'POST'
                                       ? ($_POST['firstName'] ?? $admin['firstName'])
                                       : $admin['firstName']
                               ) ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="lastName">Last Name</label>
                        <input type="text"
                               id="lastName"
                               name="lastName"
                               maxlength="100"
                               value="<?= profile_h(
                                   $activeForm === 'profile' &&
                                   $_SERVER['REQUEST_METHOD'] === 'POST'
                                       ? ($_POST['lastName'] ?? $admin['lastName'])
                                       : $admin['lastName']
                               ) ?>"
                               required>
                    </div>

                    <div class="form-group full">
                        <label for="email">Email Address</label>
                        <input type="email"
                               id="email"
                               value="<?= profile_h($admin['email']) ?>"
                               readonly>
                        <p class="hint">Email address cannot be changed here.</p>
                    </div>

                    <div class="form-group full">
                        <label for="profile_picture">Profile Picture</label>
                        <input type="file"
                               id="profile_picture"
                               name="profile_picture"
                               accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                        <p class="hint">
                            JPG, PNG, or WebP. Maximum 2 MB.
                            Leave blank to keep your current picture.
                        </p>
                    </div>
                </div>

                <div class="actions">
                    <button type="submit" class="button button-primary">
                        <i class="fa-solid fa-floppy-disk"></i>
                        Save Profile
                    </button>
                </div>
            </form>
        </section>

        <hr>

        <!-- PASSWORD FORM -->
        <section class="profile-section">
            <h2>Change Password</h2>
            <p class="section-description">
                Verify your current password before choosing a new one.
            </p>

            <form method="POST" action="profile.php">

                <input type="hidden" name="csrf_token"
                       value="<?= profile_h($_SESSION['profile_csrf']) ?>">
                <input type="hidden" name="form_type" value="password">

                <div class="form-group full">
                    <label for="current_password">Current Password</label>
                    <div class="password-wrapper">
                        <input type="password"
                               id="current_password"
                               name="current_password"
                               autocomplete="current-password"
                               required>
                        <button type="button"
                                class="password-toggle"
                                data-target="current_password"
                                aria-label="Show current password"
                                aria-pressed="false">
                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <div class="password-wrapper">
                            <input type="password"
                                   id="new_password"
                                   name="new_password"
                                   minlength="8"
                                   autocomplete="new-password"
                                   required>
                            <button type="button"
                                    class="password-toggle"
                                    data-target="new_password"
                                    aria-label="Show new password"
                                    aria-pressed="false">
                                <i class="fa-solid fa-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <div class="password-wrapper">
                            <input type="password"
                                   id="confirm_password"
                                   name="confirm_password"
                                   minlength="8"
                                   autocomplete="new-password"
                                   required>
                            <button type="button"
                                    class="password-toggle"
                                    data-target="confirm_password"
                                    aria-label="Show confirm password"
                                    aria-pressed="false">
                                <i class="fa-solid fa-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="actions">
                    <button type="submit" class="button button-primary">
                        <i class="fa-solid fa-lock"></i>
                        Update Password
                    </button>
                </div>
            </form>
        </section>

    </section>
    <a href="dashboard.php" class="back-dashboard">
        <i class="fa-solid fa-arrow-left"></i>
        Back to Dashboard
    </a>
</main>
<script>
document.querySelectorAll('.password-toggle').forEach(button => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.target);
        const icon = button.querySelector('i');

        if (!input || !icon) return;

        const showPassword = input.type === 'password';
        input.type = showPassword ? 'text' : 'password';

        icon.classList.toggle('fa-eye', !showPassword);
        icon.classList.toggle('fa-eye-slash', showPassword);

        button.setAttribute('aria-pressed', String(showPassword));
        button.setAttribute(
            'aria-label',
            showPassword ? 'Hide password' : 'Show password'
        );
    });
});
</script>
</body>
</html>
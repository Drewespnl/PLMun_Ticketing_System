<?php
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../config/db.php';

require_admin();

// Get logged-in admin profile
$loggedInAdmin = current_user();
$adminId = (int)($loggedInAdmin['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT firstName, lastName, profile_picture
    FROM users
    WHERE id = ? AND role = 'admin'
    LIMIT 1
");

$stmt->execute([$adminId]);
$sidebarAdmin = $stmt->fetch(PDO::FETCH_ASSOC);

$sidebarName = trim(
    ($sidebarAdmin['firstName'] ?? '') . ' ' .
    ($sidebarAdmin['lastName'] ?? '')
);

$sidebarFilename = basename(
    (string)($sidebarAdmin['profile_picture'] ?? '')
);

$sidebarImage = '';

if (
    $sidebarFilename !== '' &&
    file_exists(__DIR__ . '/../assets/uploads/admin/' . $sidebarFilename)
) {
    $sidebarImage = '../assets/uploads/admin/' .
                    rawurlencode($sidebarFilename);
}

// CSRF token for all account-status changes.
if (empty($_SESSION['csrf_users_token'])) {
    $_SESSION['csrf_users_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_users_token'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $action = (string)($_POST['action'] ?? '');

    if (!hash_equals($csrfToken, $submittedToken)) {
        $error = 'Invalid or expired request. Please refresh the page and try again.';
    } elseif (!$userId || !in_array($action, ['deactivate', 'activate'], true)) {
        $error = 'Invalid account action.';
    } else {
        try {
            // Only verified regular users are manageable here. Admin accounts
            // cannot be deactivated from this page.
            $stmt = $pdo->prepare(
                "SELECT id, role, is_verified, account_status
                 FROM users WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$userId]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$target || $target['role'] !== 'user' || (int)$target['is_verified'] !== 1) {
                $error = 'This account cannot be changed from user management.';
            } else {
                $newStatus = $action === 'deactivate' ? 'deactivated' : 'active';
                $update = $pdo->prepare(
                    "UPDATE users SET account_status = ? WHERE id = ? AND role = 'user'"
                );
                $update->execute([$newStatus, $userId]);

                flash(
                    $newStatus === 'active'
                        ? 'User account activated successfully.'
                        : 'User account deactivated successfully. Ticket and transaction history were preserved.',
                    'success'
                );
                redirect('users.php');
            }
        } catch (PDOException $e) {
            error_log('User account status update failed: ' . $e->getMessage());
            $error = 'Unable to update the account. Confirm that the account_status migration has been applied.';
        }
    }
}

$flash = take_flash();

// As requested for this project, display verified user accounts only.
$users = $pdo->query(
    "SELECT id, firstName, lastName, email, account_status, created_at, last_login
     FROM users
     WHERE role = 'user' AND is_verified = 1
     ORDER BY created_at DESC, id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$admin = current_user() ?? [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Management | PLMun Events</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .user-status-active{background:#eaf5df;color:#42652d}
        .user-status-deactivated{background:#fcebe8;color:#9b5146}
        .user-action-form{display:inline-block;margin:0}
        .user-action-btn{border:0;border-radius:7px;padding:9px 12px;font-size:10px;font-weight:800;cursor:pointer}
        .user-action-deactivate{background:#fff0ee;color:#a33d32}
        .user-action-activate{background:#eaf5df;color:#42652d}
        .user-name{font-weight:800;color:#222923}
        .user-toolbar{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
        @media(max-width:760px){.sidebar{position:static;width:100%;height:auto}.admin-content{margin-left:0;padding:0 15px 25px}.sidebar-bottom{margin-top:18px}.dashboard-page{display:block}}
        
        .user-search {
            position: relative;
            width: 300px;
            max-width: 100%;
        }

        .user-search input {
            width: 100%;
            padding: 12px 15px 12px 40px;
            border: 1px solid #e2e5e0;
            border-radius: 9px;
            background: #fff;
            font-size: 13px;
            outline: none;
            transition: .2s ease;
        }

        .user-search input:focus {
            border-color: #303a32;
            box-shadow: 0 0 0 3px rgba(48,58,50,.08);
        }

        .user-search i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #8a918b;
        }
        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .sidebar-brand .sidebar-admin-photo,
        .sidebar-brand .sidebar-admin-initial {
            width: 38px !important;
            height: 38px !important;
            min-width: 38px !important;
            max-width: 38px !important;
            min-height: 38px !important;
            max-height: 38px !important;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
        }

        .sidebar-brand .sidebar-admin-info {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .sidebar-brand .sidebar-admin-info strong {
            font-size: 13px;
            line-height: 1.3;
        }

        .sidebar-brand .sidebar-admin-info small {
            font-size: 10px;
        }
    </style>
</head>
<body class="dashboard-page admin-page">
<aside class="sidebar">
    <a class="brand sidebar-brand">

        <?php if (!empty($sidebarImage)): ?>

            <img src="<?= e($sidebarImage) ?>"
                class="sidebar-admin-photo"
                alt="Admin Profile">

        <?php else: ?>

            <span class="sidebar-admin-initial">
                <?= e(strtoupper(substr($sidebarName ?: 'A', 0, 1))) ?>
            </span>

        <?php endif; ?>

        <span class="sidebar-admin-info">
            <strong><?= e($sidebarName ?: 'Administrator') ?></strong>
            <small>ADMIN</small>
        </span>

    </a>
    <div class="sidebar-label">WORKSPACE</div>
    <a class="side-link" href="dashboard.php"><span>▦</span> Overview</a>
    <a class="side-link" href="dashboard.php#events"><span>◷</span> Event management</a>
    <a class="side-link" href="seat-management.php"><span>▤</span> Seat Management</a>
    <a class="side-link" href="dashboard.php#bookings"><span>▤</span> Tickets &amp; transactions</a>
    <a class="side-link" href="payments.php"><span>₱</span> Payment review</a>
    <a class="side-link active" href="users.php"><span>♙</span> User management</a>
    <a class="side-link" href="reports.php"><span>↗</span> Reports</a>
    <div class="sidebar-bottom">
        <div><strong><?= e(trim(($admin['firstName'] ?? '') . ' ' . ($admin['lastName'] ?? ''))) ?></strong><small>Sign out</small></div>
        <a class="side-logout" href="../logout.php" title="Sign out">↗</a>
    </div>
</aside>

<main class="admin-content">
    <header class="admin-topbar">
        <div><div class="muted">PLMUN EVENTS / ADMIN</div><h1>User management</h1></div>
        <div class="admin-top-actions"><span class="today-label"><?= e(date('F j, Y')) ?></span></div>
    </header>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-heading user-toolbar">
            <div>
                <h2>Registered users</h2>
                <p>Only verified user accounts are shown. Admin accounts are protected.</p>
            </div>

            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <div class="user-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input
                        type="search"
                        id="userSearch"
                        placeholder="Search name, email or ID..."
                        autocomplete="off"
                    >
                </div>

                <span class="status" id="userCount">
                    <?= count($users) ?> verified user(s)
                </span>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>User</th><th>Email</th><th>Registered</th><th>Account status</th><th>Action</th><th>Last Login</th></tr></thead>
                <tbody>
                <?php if (!$users): ?>
                    <tr><td colspan="6">No verified user accounts found.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <?php $isActive = ($user['account_status'] ?? 'active') === 'active'; ?>
                        <tr class="user-row"
                            data-search="<?= e(strtolower(
                                $user['firstName'] . ' ' .
                                $user['lastName'] . ' ' .
                                $user['email'] . ' ' .
                                $user['id']
                            )) ?>">
                            <td><span class="user-name"><?= e(trim($user['firstName'] . ' ' . $user['lastName'])) ?></span><span class="cell-sub">User ID: <?= (int)$user['id'] ?></span></td>
                            <td><?= e($user['email']) ?></td>
                            <td><?= e($user['created_at'] ?? '—') ?></td>
                            <td><span class="status <?= $isActive ? 'user-status-active' : 'user-status-deactivated' ?>"><?= $isActive ? 'Active' : 'Deactivated' ?></span></td>
                            <td>
                                <form class="user-action-form" method="post" onsubmit="return confirm('<?= $isActive ? 'Deactivate' : 'Activate' ?> this user account?');">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                    <input type="hidden" name="action" value="<?= $isActive ? 'deactivate' : 'activate' ?>">
                                    <button class="user-action-btn <?= $isActive ? 'user-action-deactivate' : 'user-action-activate' ?>" type="submit"><?= $isActive ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                            </td>
                            <td>
                                <?= !empty($user['last_login'])
                                    ? date('M d, Y - h:i A', strtotime($user['last_login']))
                                    : 'Never logged in' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>

                <tr id="noSearchResults" style="display:none;">
                    <td colspan="6" style="text-align:center; padding:25px; color:#888;">
                        <i class="fa-solid fa-user-slash"></i>
                        No matching user account found.
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
        <p class="footnote">Deactivation blocks future logins and protected user-page requests. Existing tickets and transaction records are not deleted.</p>
    </section>
    <footer class="admin-footer"><span>PLMun Events</span><span>Secure digital ticketing portal</span></footer>
</main>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('userSearch');
    const userRows = document.querySelectorAll('.user-row');
    const noResults = document.getElementById('noSearchResults');
    const userCount = document.getElementById('userCount');

    searchInput.addEventListener('input', function () {
        const searchValue = this.value.toLowerCase().trim();

        let visibleCount = 0;

        userRows.forEach(function (row) {
            const searchData = row.dataset.search;

            const matches = searchData.includes(searchValue);

            row.style.display = matches ? '' : 'none';

            if (matches) {
                visibleCount++;
            }
        });

        noResults.style.display =
            visibleCount === 0 ? '' : 'none';

        userCount.textContent =
            visibleCount + ' user(s) found';
    });
});
</script>
</body>
</html>
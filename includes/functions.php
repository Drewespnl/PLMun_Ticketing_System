<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never {
    header('Location: ' . $path);
    exit;
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_login(): void {
    if (!current_user()) {
        redirect('index.php?notice=login');
    }

    // Re-check the account status on protected requests so a user who is
    // already signed in is blocked after an administrator deactivates them.
    // Admin accounts are managed separately and cannot be deactivated here.
    $user = current_user();
    if (($user['role'] ?? '') === 'user') {
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare('SELECT account_status FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([(int)($user['id'] ?? 0)]);
                $status = $stmt->fetchColumn();

                if ($status !== 'active') {
                    unset($_SESSION['user']);
                    session_regenerate_id(true);
                    flash('Your account has been deactivated. Please contact the administrator.', 'error');
                    redirect('../login.php');
                }
            } catch (PDOException $e) {
                error_log('Account status check failed: ' . $e->getMessage());
                http_response_code(503);
                exit('Unable to verify account status. Please try again later.');
            }
        }
    }
}

function require_admin(): void {
    if (!current_user() || current_user()['role'] !== 'admin') {
        http_response_code(403);
        exit('403 Forbidden — administrator access required.');
    }
}

function flash(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function take_flash(): ?array {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}
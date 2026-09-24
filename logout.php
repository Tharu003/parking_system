<?php
session_start();
require_once 'config/db.php';

// Figure out who is logging out (before we wipe the session) so we can audit-log it.
$name = null;
$action = null;

if (!empty($_SESSION['admin_logged_in'])) {
    $name = $_SESSION['admin_name'] ?? 'Admin';
    $action = 'Admin logged out';
} elseif (!empty($_SESSION['security_logged_in'])) {
    $name = $_SESSION['security_name'] ?? 'Security staff';
    $action = 'Security staff logged out';
}

if ($name !== null && isset($conn) && $conn instanceof mysqli) {
    $log = $conn->prepare("INSERT INTO audit_logs (user_name, action) VALUES (?, ?)");
    if ($log) {
        $log->bind_param("ss", $name, $action);
        $log->execute();
        $log->close();
    }
}

// Fully clear the session (covers admin_*, security_*, and anything else stored).
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

header('Location: index.php');
exit;
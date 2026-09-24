<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_security_login(): void {
    if (empty($_SESSION['security_logged_in']) || $_SESSION['security_logged_in'] !== true) {
        header('Location: security_login.php');
        exit;
    }
}

function security_user_name(): string {
    return $_SESSION['security_name'] ?? $_SESSION['security_username'] ?? 'Security Officer';
}

function security_user_id(): int {
    return (int)($_SESSION['security_id'] ?? 0);
}

function security_audit(mysqli $conn, string $action): void {
    $name = security_user_name();
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_name, action) VALUES (?, ?)");
    if ($stmt) {
        $stmt->bind_param("ss", $name, $action);
        $stmt->execute();
        $stmt->close();
    }
}

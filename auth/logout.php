<?php
require_once __DIR__ . '/../includes/bootstrap.php';

// Delete remember token if exists
if (isset($_COOKIE['remember_token'])) {
    $pdo = \App\Database\Database::getInstance()->getConnection();
    $token = $_COOKIE['remember_token'];
    $pdo->prepare("DELETE FROM remember_tokens WHERE token = ?")->execute([$token]);
    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
}

// Destroy session
$_SESSION = [];
session_destroy();

// Redirect to login
setFlashMessage('success', 'You have been logged out successfully.');
redirect(APP_URL . '/auth/login');

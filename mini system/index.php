<?php
session_start();
if (isset($_SESSION['user'])) {
    $role = $_SESSION['user']['role'] ?? '';
    header('Location: pages/' . ($role === 'admin' ? 'dashboard.php' : 'treasurer.php'));
} else {
    header('Location: pages/login.php');
}
exit;

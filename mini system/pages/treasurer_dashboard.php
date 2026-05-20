<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: /mini%20system/pages/login.php');
    exit;
}
$role = $_SESSION['user']['role'] ?? '';
if ($role === 'admin') {
    header('Location: /mini%20system/pages/dashboard.php');
    exit;
}
if ($role !== 'officer') {
    header('Location: /mini%20system/pages/login.php');
    exit;
}
header('Location: /mini%20system/pages/treasurer.php');
exit;
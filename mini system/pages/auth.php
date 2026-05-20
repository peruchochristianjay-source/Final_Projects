<?php
// Shared helpers - included by all pages in /pages/
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../api/db.php';

// Base URL for assets - handles the space in folder name
define('ASSET', '/mini%20system');

function requireAdmin() {
    if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
        header('Location: /mini%20system/pages/login.php'); exit;
    }
    return $_SESSION['user'];
}

function requireOfficer() {
    if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'officer') {
        header('Location: /mini%20system/pages/login.php'); exit;
    }
    return $_SESSION['user'];
}

function fmt($v) { return '&#8369;' . number_format((float)$v, 0, '.', ','); }

function css($file)  { return ASSET . '/CSS/' . $file; }
function img($file)  { return ASSET . '/IMG/' . $file; }

function sidebar($active, $role) {
    $links = [
        ['dashboard.php',    'fa-chart-line',   'SAS Dashboard'],
        ['organization.php', 'fa-building',      'Organizations'],
        ['clubs.php',        'fa-user-graduate', 'Clubs'],
        ['students.php',     'fa-id-card',       'Students'],
        ['accredited.php',   'fa-certificate',   'Accredited List'],
        ['financial.php',    'fa-wallet',        'Financial'],
        ['transaction.php',  'fa-receipt',       'Transactions'],
    ];
    $user = $_SESSION['user'];
    $name = htmlspecialchars($user['fullName']);
    echo '<aside class="sidebar" id="sidebar">';
    echo '<div class="logo" style="flex-shrink:0;">';
    echo '<img src="/mini%20system/IMG/E-CFUNDS logo.png" alt="Logo">';
    echo '<h2>E-CFund\'s <br><span>Monitoring System</span></h2>';
    echo '</div>';
    echo '<nav class="nav-menu" style="flex:1;overflow-y:auto;margin-top:18px;display:flex;flex-direction:column;gap:4px;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,0.2) transparent;">';
    foreach ($links as $l) {
        $cls = (strpos($active, $l[0]) !== false) ? 'nav-active' : '';
        echo '<a href="' . $l[0] . '" class="' . $cls . '"><i class="fas ' . $l[1] . '"></i> ' . $l[2] . '</a>';
    }
    echo '</nav>';
    echo '<div class="user" style="flex-shrink:0;margin-top:auto;padding-top:12px;border-top:1px solid rgba(255,255,255,0.1);">';
    echo '<p><strong>' . $name . '</strong></p>';
    echo '<small>SAS Admin</small>';
    echo '<form method="POST" action="logout.php" style="margin:0">';
    echo '<button class="logout" type="submit">&#x27F2; Log out</button>';
    echo '</form>';
    echo '</div>';
    echo '</aside>';
}

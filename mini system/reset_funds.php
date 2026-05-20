<?php
require_once __DIR__ . '/api/db.php';
$pdo = db();

$pdo->exec("DELETE FROM fund_releases");
$pdo->exec("DELETE FROM remit_requests");
$pdo->exec("DELETE FROM financial_records");
$pdo->exec("DELETE FROM transactions");
$pdo->exec("UPDATE entity_funds SET collections=0, expenses=0, previous_funds=10000");

header('Location: /mini%20system/pages/dashboard.php');
?>

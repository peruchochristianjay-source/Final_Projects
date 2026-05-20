<?php
require_once __DIR__ . '/api/db.php';
$pdo = db();

$tables = ['entities','users','students','entity_funds','transactions','financial_records','remit_requests','fund_releases','accredited_entries'];

echo "<style>body{font-family:sans-serif;margin:20px;} table{border-collapse:collapse;width:100%;margin-bottom:20px;font-size:12px;} th{background:#0b5d8b;color:white;padding:8px 10px;text-align:left;} td{padding:7px 10px;border-bottom:1px solid #eee;} tr:hover{background:#f9f9f9;} h3{color:#0b5d8b;margin:16px 0 6px;}</style>";

foreach ($tables as $t) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        echo "<h3>$t — $count rows</h3>";
        $rows = $pdo->query("SELECT * FROM $t LIMIT 5")->fetchAll();
        if ($rows) {
            echo "<table><tr>";
            foreach (array_keys($rows[0]) as $col) echo "<th>$col</th>";
            echo "</tr>";
            foreach ($rows as $r) {
                echo "<tr>";
                foreach ($r as $v) echo "<td>".htmlspecialchars(substr((string)$v,0,60))."</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    } catch (PDOException $e) {
        echo "<p style='color:red'>$t — NOT FOUND</p>";
    }
}
?>

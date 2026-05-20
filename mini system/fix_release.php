<?php
require_once __DIR__ . '/api/db.php';
$pdo = db();

// Get all existing fund releases and deduct from entity_funds.previous_funds
$releases = $pdo->query("SELECT entity_id, SUM(amount) AS total FROM fund_releases WHERE status='Released' GROUP BY entity_id")->fetchAll();

foreach ($releases as $r) {
    $eid   = $r['entity_id'];
    $total = $r['total'];

    // Get original previous_funds (before any deduction)
    // Reset to 10000 first then deduct
    $pdo->prepare("UPDATE entity_funds SET previous_funds = 10000 - :a WHERE entity_id = :e")
        ->execute([':a' => $total, ':e' => $eid]);

    echo "<p>Entity ID $eid: previous_funds set to ₱" . number_format(10000 - $total) . " (deducted ₱" . number_format($total) . ")</p>";
}

// Show updated entity_funds
echo "<h3>Updated entity_funds:</h3><table border='1' cellpadding='8' style='border-collapse:collapse;font-size:13px;'>";
echo "<tr><th>Entity</th><th>Previous Funds</th><th>Released by SAS</th></tr>";
$rows = $pdo->query(
    "SELECT e.name, ef.previous_funds,
            COALESCE((SELECT SUM(amount) FROM fund_releases WHERE entity_id=e.id AND status='Released'),0) AS released
     FROM entities e
     LEFT JOIN entity_funds ef ON ef.entity_id=e.id
     WHERE e.is_active=1 ORDER BY e.name"
)->fetchAll();
foreach ($rows as $r) {
    echo "<tr><td>{$r['name']}</td><td>₱".number_format($r['previous_funds'])."</td><td>₱".number_format($r['released'])."</td></tr>";
}
echo "</table>";
echo "<br><a href='/mini%20system/pages/financial.php' style='background:#0b5d8b;color:white;padding:9px 18px;border-radius:8px;text-decoration:none;'>→ Financial</a>";
?>

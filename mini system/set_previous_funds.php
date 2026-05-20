<?php
require_once __DIR__ . '/api/db.php';
$pdo = db();

// Set previous_funds = 10000 for all entities (carried over from last year's officers)
$pdo->exec("UPDATE entity_funds SET previous_funds = 10000");

// Also insert entity_funds row if missing for any entity
$entities = $pdo->query("SELECT id FROM entities WHERE is_active=1")->fetchAll();
foreach ($entities as $e) {
    $pdo->prepare("INSERT INTO entity_funds (entity_id, collections, expenses, previous_funds)
                   VALUES (:e, 0, 0, 10000)
                   ON DUPLICATE KEY UPDATE previous_funds = 10000")
        ->execute([':e' => $e['id']]);
}

// Also add a transaction record for each entity showing the carried-over funds
// First remove old previous fund transactions to avoid duplicates
$pdo->exec("DELETE FROM transactions WHERE description LIKE 'Previous fund carried over%'");

foreach ($entities as $e) {
    $pdo->prepare("INSERT INTO transactions (entity_id, tx_date, type, description, amount, source, created_by)
                   VALUES (:e, '2024-06-01', 'Income', 'Previous fund carried over from last year', 10000, 'system', 1)")
        ->execute([':e' => $e['id']]);
}

// Show summary
$rows = $pdo->query(
    "SELECT e.name, e.category, ef.previous_funds,
            COALESCE(SUM(CASE WHEN s.payment_status='Paid' THEN s.amount_due ELSE 0 END),0) AS student_collections
     FROM entities e
     LEFT JOIN entity_funds ef ON ef.entity_id = e.id
     LEFT JOIN students s ON s.entity_id = e.id
     WHERE e.is_active = 1
     GROUP BY e.id, e.name, e.category, ef.previous_funds
     ORDER BY e.category, e.name"
)->fetchAll();
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Previous Funds Set</title>
<style>
body{font-family:sans-serif;max-width:750px;margin:40px auto;padding:0 20px;}
h2{color:#0b5d8b;} table{width:100%;border-collapse:collapse;font-size:13px;}
th{background:#0b5d8b;color:white;padding:9px 12px;text-align:left;}
td{padding:8px 12px;border-bottom:1px solid #eee;} tr:hover{background:#f9f9f9;}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;}
.organization{background:#dbeafe;color:#1d4ed8;} .club{background:#ede9fe;color:#7c3aed;}
a{display:inline-block;margin:6px 6px 0 0;background:#0b5d8b;color:white;padding:9px 18px;border-radius:8px;text-decoration:none;}
</style>
</head><body>
<h2>✅ Previous Funds Updated</h2>
<p>All organizations and clubs now have <strong>₱10,000</strong> previous fund carried over from last year's officers.</p>

<table>
  <tr><th>Entity</th><th>Type</th><th>Previous Fund</th><th>Student Collections</th><th>Total Available</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
    <td><span class="badge <?= $r['category'] ?>"><?= ucfirst($r['category']) ?></span></td>
    <td style="color:#0b5d8b;font-weight:700;">₱<?= number_format($r['previous_funds']) ?></td>
    <td style="color:#16a34a;font-weight:700;">₱<?= number_format($r['student_collections']) ?></td>
    <td style="font-weight:700;">₱<?= number_format($r['previous_funds'] + $r['student_collections']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<a href="/mini%20system/pages/dashboard.php">→ Dashboard</a>
<a href="/mini%20system/pages/organization.php">→ Organizations</a>
<a href="/mini%20system/pages/clubs.php">→ Clubs</a>
</body></html>

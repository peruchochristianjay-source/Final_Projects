<?php
require_once __DIR__ . '/api/db.php';
$pdo = db();

$password = 'Officer@123';
$hash     = password_hash($password, PASSWORD_BCRYPT);

// Update all treasurer passwords
$pdo->prepare("UPDATE users SET password_hash=:h WHERE role='officer'")
    ->execute([':h' => $hash]);

// Show all treasurers
$rows = $pdo->query("SELECT u.id, u.full_name, u.email, e.name AS entity FROM users u LEFT JOIN entities e ON e.id=u.entity_id WHERE u.role='officer' ORDER BY e.id")->fetchAll();
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Treasurer Credentials</title>
<style>
body{font-family:sans-serif;max-width:700px;margin:40px auto;padding:0 20px;}
h2{color:#0b5d8b;} table{width:100%;border-collapse:collapse;font-size:13px;}
th{background:#0b5d8b;color:white;padding:9px 12px;text-align:left;}
td{padding:8px 12px;border-bottom:1px solid #eee;}
.ok{color:#16a34a;font-weight:700;}
a{display:inline-block;margin-top:16px;background:#0b5d8b;color:white;padding:9px 18px;border-radius:8px;text-decoration:none;}
</style>
</head><body>
<h2>✅ Treasurer Passwords Reset</h2>
<p>All treasurer passwords have been set to: <strong>Officer@123</strong></p>
<table>
  <tr><th>Entity</th><th>Full Name</th><th>Email</th><th>Password</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= htmlspecialchars($r['entity'] ?? '—') ?></td>
    <td><?= htmlspecialchars($r['full_name']) ?></td>
    <td><?= htmlspecialchars($r['email']) ?></td>
    <td class="ok">Officer@123</td>
  </tr>
  <?php endforeach; ?>
</table>
<a href="/mini%20system/pages/login.php">→ Go to Login</a>
</body></html>

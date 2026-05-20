<?php
require_once __DIR__ . '/auth.php';
$user = requireAdmin(); $pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? ''); $short = trim($_POST['short'] ?? '') ?: $name;
        if ($name) { $pdo->prepare("INSERT INTO entities (name,short_name,category) VALUES (:n,:s,'club')")->execute([':n'=>$name,':s'=>$short]); $eid=$pdo->lastInsertId(); $pdo->prepare("INSERT INTO entity_funds (entity_id,collections,expenses,previous_funds) VALUES (:e,0,0,0)")->execute([':e'=>$eid]); }
    } elseif ($action === 'delete') { $pdo->prepare("UPDATE entities SET is_active=0 WHERE id=:i AND category='club'")->execute([':i'=>(int)($_POST['id']??0)]); }
    elseif ($action === 'edit_funds') { $id=(int)($_POST['id']??0); $pdo->prepare("INSERT INTO entity_funds (entity_id,collections,expenses,previous_funds) VALUES (:e,:c,:ex,:p) ON DUPLICATE KEY UPDATE collections=:c,expenses=:ex,previous_funds=:p")->execute([':e'=>$id,':c'=>(float)($_POST['collections']??0),':ex'=>(float)($_POST['expenses']??0),':p'=>(float)($_POST['previous']??0)]); }
    header('Location: clubs.php'); exit;
}
$clubs = $pdo->query(
    "SELECT e.*,
            COALESCE(f.previous_funds,0) AS previous_funds,
            COALESCE(SUM(CASE WHEN s.payment_status='Paid' THEN s.amount_due ELSE 0 END),0) AS collections,
            COALESCE((SELECT SUM(fr.amount) FROM financial_records fr WHERE fr.entity_id=e.id AND fr.type='Expense'),0) AS expenses
     FROM entities e
     LEFT JOIN entity_funds f ON f.entity_id=e.id
     LEFT JOIN students s ON s.entity_id=e.id
     WHERE e.category='club' AND e.is_active=1
     GROUP BY e.id, e.name, e.short_name, e.category, e.photo_path, e.is_active, e.created_at, f.previous_funds
     ORDER BY e.name"
)->fetchAll();
$detailId=(int)($_GET['id']??0); $detail=null; $detailTxns=[];
if ($detailId) {
    $s=$pdo->prepare(
        "SELECT e.*,
                COALESCE(f.previous_funds,0) AS previous_funds,
                COALESCE(SUM(CASE WHEN s.payment_status='Paid' THEN s.amount_due ELSE 0 END),0) AS collections,
                COALESCE((SELECT SUM(fr.amount) FROM financial_records fr WHERE fr.entity_id=e.id AND fr.type='Expense'),0) AS expenses
         FROM entities e
         LEFT JOIN entity_funds f ON f.entity_id=e.id
         LEFT JOIN students s ON s.entity_id=e.id
         WHERE e.id=:i
         GROUP BY e.id, e.name, e.short_name, e.category, e.photo_path, e.is_active, e.created_at, f.previous_funds
         LIMIT 1"
    );
    $s->execute([':i'=>$detailId]); $detail=$s->fetch();
    $t=$pdo->prepare("SELECT * FROM transactions WHERE entity_id=:i ORDER BY tx_date DESC LIMIT 20");
    $t->execute([':i'=>$detailId]); $detailTxns=$t->fetchAll();
}
?>
<link rel="stylesheet" href="/mini%20system/CSS/clubs.css">
<link rel="stylesheet" href="/mini%20system/CSS/professional_ui.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<body><div class="container"><?php sidebar('clubs.php','admin'); ?>
<main class="main">
<?php if ($detail): $c=(float)$detail['collections'];$e=(float)$detail['expenses'];$p=(float)$detail['previous_funds']; ?>
  <div class="detail-header">
    <a href="clubs.php" class="back-btn" style="text-decoration:none;"><i class="fas fa-arrow-left"></i> Back</a>
    <div><h1><?= htmlspecialchars($detail['name']) ?></h1><p>Club Details</p></div>
    <button class="edit-funds-btn" onclick="document.getElementById('fundsPopup').style.display='flex'"><i class="fas fa-edit"></i> Edit Funds</button>
  </div>
  <div class="summary-row">
    <div class="sum-card blue"><p class="sum-label">Total Collections</p><h2><?= fmt($c) ?></h2></div>
    <div class="sum-card red"><p class="sum-label">Total Expenses</p><h2><?= fmt($e) ?></h2></div>
    <div class="sum-card green"><p class="sum-label">Remaining Funds</p><h2><?= fmt($c-$e) ?></h2></div>
    <div class="sum-card navy"><p class="sum-label">Previous Funds</p><h2><?= fmt($p) ?></h2></div>
  </div>
  <div class="detail-card"><h3><i class="fas fa-receipt"></i> Fund Receipt</h3>
    <table><thead><tr><th>Category</th><th>Amount</th><th>Status</th></tr></thead><tbody>
      <tr><td>Previous Funds</td><td><?= fmt($p) ?></td><td><span class="badge navy">Carried Over</span></td></tr>
      <tr><td>Total Collections</td><td><?= fmt($c) ?></td><td><span class="badge green">Received</span></td></tr>
      <tr><td>Total Expenses</td><td><?= fmt($e) ?></td><td><span class="badge red">Spent</span></td></tr>
      <tr class="grand-row"><td><strong>Grand Total Remaining</strong></td><td><?= fmt($p+$c-$e) ?></td><td><span class="badge blue">Available</span></td></tr>
    </tbody></table>
  </div>
  <div class="detail-card"><h3><i class="fas fa-list"></i> Transaction Log</h3>
    <table><thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Remarks</th></tr></thead><tbody>
    <?php if ($detailTxns): foreach ($detailTxns as $t): $color=$t['type']==='Income'?'#16a34a':'#dc2626'; ?>
      <tr><td><?= $t['tx_date'] ?></td><td style="color:<?= $color ?>"><?= $t['type'] ?></td><td style="color:<?= $color ?>"><?= fmt($t['amount']) ?></td><td><?= htmlspecialchars($t['description']??'—') ?></td></tr>
    <?php endforeach; else: ?><tr><td colspan="4" style="text-align:center;color:#aaa;">No transactions yet</td></tr><?php endif; ?>
    </tbody></table>
  </div>
  <div class="popup-overlay" id="fundsPopup" onclick="if(event.target===this)this.style.display='none'"><div class="popup-card"><h2>Edit Fund Data</h2>
    <form method="POST"><input type="hidden" name="action" value="edit_funds"><input type="hidden" name="id" value="<?= $detailId ?>">
      <label class="field-label">Total Collections (₱)</label><input type="number" name="collections" min="0" step="0.01" value="<?= $detail['collections'] ?>">
      <label class="field-label">Total Expenses (₱)</label><input type="number" name="expenses" min="0" step="0.01" value="<?= $detail['expenses'] ?>">
      <label class="field-label">Previous Funds (₱)</label><input type="number" name="previous" min="0" step="0.01" value="<?= $detail['previous_funds'] ?>">
      <div class="popup-buttons"><button class="save-btn" type="submit">Save</button><button class="cancel-btn" type="button" onclick="document.getElementById('fundsPopup').style.display='none'">Cancel</button></div>
    </form></div></div>
<?php else: ?>
  <div class="header"><div><h1>Clubs</h1><p>Student Affairs and Services - Club Fund Monitor</p></div></div>
  <div class="item-list">
  <?php foreach ($clubs as $o): ?>
    <div class="item-row">
      <a href="clubs.php?id=<?= $o['id'] ?>" class="item-btn" style="text-decoration:none;">
        <img src="<?= htmlspecialchars($o['photo_path']??'/mini%20system/IMG/omsc logo.jpg') ?>" onerror="this.src='/mini%20system/IMG/omsc logo.jpg'">
        <span class="item-name"><?= htmlspecialchars($o['name']) ?></span>
        <span style="margin-left:auto;font-size:11px;color:#888">Col: <?= fmt($o['collections']) ?> | Rem: <?= fmt((float)$o['collections']-(float)$o['expenses']) ?></span>
      </a>
      <form method="POST" style="display:inline"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $o['id'] ?>"><button class="del-btn" type="submit" onclick="return confirm('Delete this club?')"><i class="fas fa-trash"></i></button></form>
    </div>
  <?php endforeach; if (!$clubs): ?><p style="text-align:center;color:#aaa;padding:40px;">No clubs yet.</p><?php endif; ?>
  </div>
  <div class="popup-overlay" id="addPopup" onclick="if(event.target===this)this.style.display='none'"><div class="popup-card"><h2>Add New Club</h2><p>Fill in club details</p>
    <form method="POST"><input type="hidden" name="action" value="add"><input type="text" name="name" placeholder="Club Name *" required><input type="text" name="short" placeholder="Abbreviation (optional)">
      <div class="popup-buttons"><button class="save-btn" type="submit">Save</button><button class="cancel-btn" type="button" onclick="document.getElementById('addPopup').style.display='none'">Cancel</button></div>
    </form></div></div>
<?php endif; ?>
</main></div>
<button class="toggle" onclick="document.getElementById('sidebar').classList.toggle('hidden')">☰</button>
</body></html>

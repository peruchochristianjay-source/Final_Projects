<?php
require_once __DIR__ . '/auth.php';
$user = requireAdmin();
$pdo  = db();

// ── HANDLE POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name  = trim($_POST['name'] ?? '');
        $short = trim($_POST['short'] ?? '') ?: $name;
        if ($name) {
            $pdo->prepare("INSERT INTO entities (name,short_name,category) VALUES (:n,:s,'organization')")
                ->execute([':n'=>$name,':s'=>$short]);
            $eid = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO entity_funds (entity_id,collections,expenses,previous_funds) VALUES (:e,0,0,0)")
                ->execute([':e'=>$eid]);
        }
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM entities WHERE id=:i AND category='organization'")
            ->execute([':i'=>(int)($_POST['id']??0)]);
    } elseif ($action === 'approve_remit') {
        $rid = (int)($_POST['remit_id']??0);
        $remarks = trim($_POST['remarks']??'');
        $row = $pdo->prepare("SELECT * FROM remit_requests WHERE id=:i AND status='Pending' LIMIT 1");
        $row->execute([':i'=>$rid]); $remit = $row->fetch();
        if ($remit) {
            $pdo->prepare("UPDATE remit_requests SET status='Approved',reviewed_by=:u,reviewed_at=NOW(),remarks=:r WHERE id=:i")
                ->execute([':u'=>$user['id'],':r'=>$remarks?:null,':i'=>$rid]);
            // Record as financial remit
            $pdo->prepare("INSERT INTO financial_records (entity_id,record_date,type,description,amount,created_by) VALUES (:e,CURDATE(),'Remit',:d,:a,:u)")
                ->execute([':e'=>$remit['entity_id'],':d'=>'Remittance approved: '.($remit['description']??'Membership fees'),':a'=>$remit['amount'],':u'=>$user['id']]);
            $pdo->prepare("INSERT INTO transactions (entity_id,tx_date,type,description,amount,source,created_by) VALUES (:e,CURDATE(),'Income',:d,:a,'financial',:u)")
                ->execute([':e'=>$remit['entity_id'],':d'=>'Remittance approved: '.($remit['description']??'Membership fees'),':a'=>$remit['amount'],':u'=>$user['id']]);
        }
        header('Location: organization.php'); exit;

    } elseif ($action === 'reject_remit') {
        $rid = (int)($_POST['remit_id']??0);
        $remarks = trim($_POST['remarks']??'');
        $pdo->prepare("UPDATE remit_requests SET status='Rejected',reviewed_by=:u,reviewed_at=NOW(),remarks=:r WHERE id=:i AND status='Pending'")
            ->execute([':u'=>$user['id'],':r'=>$remarks?:null,':i'=>$rid]);
        header('Location: organization.php'); exit;

    } elseif ($action === 'release_fund') {
        $eid    = (int)($_POST['entity_id']??0);
        $amt    = (float)($_POST['release_amount']??0);
        $purpose= trim($_POST['release_purpose']??'');
        $date   = $_POST['release_date']??date('Y-m-d');
        if ($eid && $amt > 0 && $purpose) {
            // Full available balance = previous + student fees + already released - remitted - expenses
            $entityPrev     = (float)$pdo->query("SELECT COALESCE(previous_funds,0) FROM entity_funds WHERE entity_id=$eid LIMIT 1")->fetchColumn();
            $entityFees     = (float)$pdo->query("SELECT COALESCE(SUM(amount_due),0) FROM students WHERE entity_id=$eid AND payment_status='Paid'")->fetchColumn();
            $entityReleased = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM fund_releases WHERE entity_id=$eid AND status='Released'")->fetchColumn();
            $entityRemitted = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM financial_records WHERE entity_id=$eid AND type='Remit'")->fetchColumn();
            $entityExpenses = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM financial_records WHERE entity_id=$eid AND type='Expense'")->fetchColumn();
            $entityBalance  = $entityPrev + $entityFees + $entityReleased - $entityRemitted - $entityExpenses;

            // Limit: max ₱5,000 total release per entity
            $releaseLimit   = 5000;
            $remainingLimit = $releaseLimit - $entityReleased;

            if ($remainingLimit <= 0) {
                $_SESSION['release_error'] = '⚠ Release limit reached. Maximum ₱5,000 per org/club has already been released.';
                header('Location: organization.php?id='.$eid); exit;
            }
            if ($amt > $remainingLimit) {
                $_SESSION['release_error'] = '⚠ Cannot release ₱'.number_format($amt,2).'. Remaining release limit for this org/club is only ₱'.number_format($remainingLimit,2).'.';
                header('Location: organization.php?id='.$eid); exit;
            }
            if ($amt > $entityBalance) {
                $_SESSION['release_error'] = '⚠ Cannot release ₱'.number_format($amt,2).'. Available balance is only ₱'.number_format($entityBalance,2).'.';
                header('Location: organization.php?id='.$eid); exit;
            }
            // Record fund release
            $pdo->prepare("INSERT INTO fund_releases (entity_id,released_by,amount,purpose,release_date) VALUES (:e,:u,:a,:p,:d)")
                ->execute([':e'=>$eid,':u'=>$user['id'],':a'=>$amt,':p'=>$purpose,':d'=>$date]);
            // Deduct from entity's previous_funds
            $pdo->prepare("UPDATE entity_funds SET previous_funds = previous_funds - :a WHERE entity_id = :e")
                ->execute([':a'=>$amt,':e'=>$eid]);
            // Add income transaction to org/club (shows in treasurer dashboard as Released by SAS)
            $pdo->prepare("INSERT INTO transactions (entity_id,tx_date,type,description,amount,source,created_by) VALUES (:e,:d,'Income',:ds,:a,'admin_release',:u)")
                ->execute([':e'=>$eid,':d'=>$date,':ds'=>'Fund Released by SAS: '.$purpose,':a'=>$amt,':u'=>$user['id']]);
        }
        header('Location: organization.php?id='.$eid); exit;

    } elseif ($action === 'add_txn') {
        $eid   = (int)($_POST['entity_id'] ?? 0);
        $type  = $_POST['txn_type']  ?? 'Expense';
        $amt   = (float)($_POST['txn_amount'] ?? 0);
        $date  = $_POST['txn_date']  ?? date('Y-m-d');
        $desc  = trim($_POST['txn_desc'] ?? '');
        if ($eid && $amt > 0) {
            // Get live remaining balance for this org
            $incRow   = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE entity_id=$eid AND type='Income'")->fetchColumn();
            $expRow   = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE entity_id=$eid AND type='Expense'")->fetchColumn();
            $stuRow   = (float)$pdo->query("SELECT COALESCE(SUM(amount_due),0) FROM students WHERE entity_id=$eid AND payment_status='Paid'")->fetchColumn();
            $remitRow = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM financial_records WHERE entity_id=$eid AND type='Remit'")->fetchColumn();
            $prevRow  = (float)$pdo->query("SELECT COALESCE(previous_funds,0) FROM entity_funds WHERE entity_id=$eid LIMIT 1")->fetchColumn();
            // Admin total = previous funds of all entities + all remitted
            $adminPrev     = (float)$pdo->query("SELECT COALESCE(SUM(previous_funds),0) FROM entity_funds")->fetchColumn();
            $adminRemit    = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM financial_records WHERE type='Remit'")->fetchColumn();
            $adminReleased = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE source='admin_release'")->fetchColumn();
            $adminExpenses = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM financial_records WHERE type='Expense'")->fetchColumn();
            $adminBalance  = $adminPrev + $adminRemit - $adminReleased - $adminExpenses;
            $orgBalance    = $prevRow + $stuRow + $incRow - $expRow - $remitRow;

            if ($type === 'Expense') {
                // RELEASE FUND: admin releases to org for events
                // Check admin has enough balance
                if ($amt > $adminBalance) {
                    $_SESSION['org_error_'.$eid] = '⚠ Cannot release ₱' . number_format($amt,2) . '. Admin available fund is only ₱' . number_format($adminBalance,2) . '.';
                    header('Location: organization.php?id='.$eid); exit;
                }
                // Add Income to org (fund received from admin)
                $pdo->prepare("INSERT INTO transactions (entity_id,tx_date,type,description,amount,source,created_by) VALUES (:e,:d,'Income',:ds,:a,'admin_release',:u)")
                    ->execute([':e'=>$eid,':d'=>$date,':ds'=>'Fund Release: '.($desc?:$pdo->query("SELECT name FROM entities WHERE id=$eid")->fetchColumn()),':a'=>$amt,':u'=>$user['id']]);
                // Record as admin expense (deducts from admin total)
                $pdo->prepare("INSERT INTO financial_records (entity_id,record_date,type,description,amount,created_by) VALUES (:e,:d,'Expense',:ds,:a,:u)")
                    ->execute([':e'=>$eid,':d'=>$date,':ds'=>'Fund Released to '.($desc?:$pdo->query("SELECT name FROM entities WHERE id=$eid")->fetchColumn()),':a'=>$amt,':u'=>$user['id']]);
            }
        }
        header('Location: organization.php?id='.$eid); exit;
    }

    header('Location: organization.php'); exit;
}

// ── HELPER: get live totals from transactions ────────────────────────────────
function getLiveTotals($pdo, $entityId) {
    // Collections = paid student membership fees only
    $stu      = (float)$pdo->query("SELECT COALESCE(SUM(amount_due),0) FROM students WHERE entity_id=$entityId AND payment_status='Paid'")->fetchColumn();
    // Released by SAS (from fund_releases table)
    $released = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM fund_releases WHERE entity_id=$entityId AND status='Released'")->fetchColumn();
    // Remitted to SAS by treasurer
    $remitted = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM financial_records WHERE entity_id=$entityId AND type='Remit'")->fetchColumn();
    // Previous funds (already deducted when SAS releases)
    $prev     = (float)$pdo->query("SELECT COALESCE(previous_funds,0) FROM entity_funds WHERE entity_id=$entityId LIMIT 1")->fetchColumn();
    $col      = $stu + $released;   // student fees + released by SAS
    $spent    = $remitted;           // remitted to SAS
    return ['collections'=>$col, 'expenses'=>$spent, 'remaining'=>$prev + $col - $spent, 'previous'=>$prev, 'student_fees'=>$stu, 'released'=>$released];
}

// ── LOAD ORGS with live totals ───────────────────────────────────────────────
$orgsRaw = $pdo->query(
    "SELECT e.*, COALESCE(f.previous_funds,0) AS previous_funds
     FROM entities e
     LEFT JOIN entity_funds f ON f.entity_id=e.id
     WHERE e.category='organization' AND e.is_active=1
     ORDER BY e.name"
)->fetchAll();

$orgs = [];
foreach ($orgsRaw as $o) {
    $totals = getLiveTotals($pdo, $o['id']);
    $o['collections'] = $totals['collections'];
    $o['expenses']    = $totals['expenses'];
    $o['remaining']   = $totals['remaining'];
    $orgs[] = $o;
}

// ── DETAIL VIEW ──────────────────────────────────────────────────────────────
$detailId  = (int)($_GET['id'] ?? 0);
$detail    = null;
$detailTxns = [];
if ($detailId) {
    foreach ($orgs as $o) {
        if ($o['id'] === $detailId) { $detail = $o; break; }
    }
    $t = $pdo->prepare("SELECT * FROM transactions WHERE entity_id=:i ORDER BY tx_date DESC, id DESC");
    $t->execute([':i'=>$detailId]);
    $detailTxns = $t->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>E-CFunds Organizations</title>
<link rel="stylesheet" href="/mini%20system/CSS/organization.css">
<link rel="stylesheet" href="/mini%20system/CSS/professional_ui.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
<div class="container">
<?php sidebar('organization.php','admin'); ?>
<main class="main">

<?php if ($detail): ?>
<!-- ═══ DETAIL VIEW ═══ -->
<div id="detailView">
  <div class="detail-header">
    <a href="organization.php" class="back-btn" style="text-decoration:none;"><i class="fas fa-arrow-left"></i> Back</a>
    <div><h1><?= htmlspecialchars($detail['name']) ?></h1><p>Organization Details</p></div>
    <button class="edit-funds-btn" onclick="document.getElementById('txnPopup').style.display='flex'">
      <i class="fas fa-hand-holding-usd"></i> Release Fund
    </button>
  </div>

  <?php if (!empty($_SESSION['org_error_'.$detailId])): ?>
  <div style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;margin-bottom:14px;color:#dc2626;font-size:12.5px;font-weight:600;display:flex;align-items:center;gap:8px;">
    <i class="fas fa-exclamation-triangle"></i>
    <?= $_SESSION['org_error_'.$detailId] ?>
  </div>
  <?php unset($_SESSION['org_error_'.$detailId]); endif; ?>

  <?php
    $c        = $detail['collections'];
    $e        = $detail['expenses'];
    $p        = (float)$detail['previous_funds'];
    $remitted = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM financial_records WHERE entity_id=$detailId AND type='Remit'")->fetchColumn();
    $stuFees  = (float)$pdo->query("SELECT COALESCE(SUM(amount_due),0) FROM students WHERE entity_id=$detailId AND payment_status='Paid'")->fetchColumn();
    $released = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM fund_releases WHERE entity_id=$detailId AND status='Released'")->fetchColumn();
    $expenses = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM financial_records WHERE entity_id=$detailId AND type='Expense'")->fetchColumn();
    $availableBalance = $p + $stuFees + $released - $remitted - $expenses;
    $releaseLimit     = 5000;
    $remainingLimit   = max(0, $releaseLimit - $released);
  ?>
  <div class="summary-row">
    <div class="sum-card blue"><p class="sum-label">Total Collections</p><h2><?= fmt($stuFees) ?></h2></div>
    <div class="sum-card red"><p class="sum-label">Remittance to SAS</p><h2><?= fmt($remitted) ?></h2></div>
    <div class="sum-card green"><p class="sum-label">Remaining Funds</p><h2><?= fmt($availableBalance) ?></h2></div>
    <div class="sum-card navy"><p class="sum-label">Previous Funds</p><h2><?= fmt($p) ?></h2></div>
  </div>

  <div class="detail-card">
    <h3><i class="fas fa-receipt"></i> Fund Receipt</h3>
    <table>
      <thead><tr><th>Category</th><th>Amount</th><th>Status</th></tr></thead>
      <tbody>
        <tr><td>Previous Funds</td><td><?= fmt($p) ?></td><td><span class="badge navy">Carried Over</span></td></tr>
        <tr><td>Student Collections</td><td><?= fmt($stuFees) ?></td><td><span class="badge green">Collected</span></td></tr>
        <tr><td>Released by SAS</td><td><?= fmt($released) ?></td><td><span class="badge blue">Received</span></td></tr>
        <tr><td>Remittance to SAS</td><td><?= fmt($remitted) ?></td><td><span class="badge red">Remitted</span></td></tr>
        <tr><td>Expenses</td><td><?= fmt($expenses) ?></td><td><span class="badge red">Spent</span></td></tr>
        <tr class="grand-row"><td><strong>Grand Total Remaining</strong></td><td><?= fmt($availableBalance) ?></td><td><span class="badge blue">Available</span></td></tr>
      </tbody>
    </table>
  </div>

  <div class="detail-card">
    <h3><i class="fas fa-list"></i> Transaction Log</h3>
    <table>
      <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Remarks</th></tr></thead>
      <tbody>
      <?php if ($detailTxns): foreach ($detailTxns as $t):
        $color = $t['type']==='Income' ? '#16a34a' : '#dc2626';
        $label = $t['type']==='Expense' ? 'Release' : 'Income';
        ?>
        <tr>
          <td><?= htmlspecialchars($t['tx_date']) ?></td>
          <td style="color:<?= $color ?>;font-weight:600"><?= $label ?></td>
          <td style="color:<?= $color ?>;font-weight:700"><?= $t['type']==='Income'?'+':'-' ?> <?= fmt($t['amount']) ?></td>
          <td><?= htmlspecialchars($t['description'] ?? '—') ?></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="4" style="text-align:center;color:#aaa;padding:20px;">No transactions yet</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- RELEASE FUND POPUP -->
<div class="popup-overlay" id="txnPopup" onclick="if(event.target===this)this.style.display='none'">
  <div class="popup-card">
    <h2><i class="fas fa-hand-holding-usd" style="color:#0b5d8b;"></i> Release Fund</h2>
    <p><?= htmlspecialchars($detail['name']) ?></p>
    <p style="font-size:12px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:8px 12px;margin-bottom:12px;color:#0369a1;">
      <strong>Available Balance:</strong> <?= fmt($availableBalance) ?>
      <br><small style="color:#666;">Previous Funds: <?= fmt($p) ?> | Student Fees: <?= fmt($stuFees) ?> | Released: <?= fmt($released) ?></small>
      <br><small style="color:<?= $remainingLimit > 0 ? '#0369a1' : '#dc2626' ?>;font-weight:600;">
        Release Limit: <?= fmt($releaseLimit) ?> per org/club &nbsp;|&nbsp; Remaining Limit: <?= fmt($remainingLimit) ?>
      </small>
    </p>
    <?php if ($remainingLimit <= 0): ?>
    <div style="background:#fee2e2;border:1px solid #fecaca;border-radius:6px;padding:8px 12px;margin-bottom:12px;color:#dc2626;font-size:12px;font-weight:600;">
      <i class="fas fa-ban"></i> Release limit of &#8369;5,000 has been reached for this org/club.
    </div>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="action" value="release_fund">
      <input type="hidden" name="entity_id" value="<?= $detailId ?>">
      <label class="field-label">Date</label>
      <input type="date" name="release_date" value="<?= date('Y-m-d') ?>">
      <label class="field-label">Purpose / Description <span style="color:#dc3545;">*</span></label>
      <input type="text" name="release_purpose" placeholder="e.g. Event supplies, Sports fest" required>
      <label class="field-label">Amount to Release (&#8369;) <span style="color:#dc3545;">*</span></label>
      <input type="number" name="release_amount" min="1" max="<?= min($availableBalance, $remainingLimit) ?>" step="0.01" placeholder="0" <?= $remainingLimit <= 0 ? 'disabled' : '' ?> required>
      <div class="popup-buttons" style="margin-top:12px;">
        <button class="save-btn" type="submit"><i class="fas fa-paper-plane"></i> Release</button>
        <button class="cancel-btn" type="button" onclick="document.getElementById('txnPopup').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ═══ LIST VIEW ═══ -->
<div id="listView">
  <div class="header">
    <div><h1>Organizations</h1><p>Student Affairs and Services - Organization Fund Monitor</p></div>
    <div style="display:flex;gap:8px;">
      <button class="add-btn" onclick="document.getElementById('releasePopup').style.display='flex'">
        <i class="fas fa-hand-holding-usd"></i> Release Fund
      </button>
      <button class="add-btn" style="background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;box-shadow:none;" onclick="document.getElementById('addPopup').style.display='flex'">
        <i class="fas fa-plus"></i> Add Org
      </button>
    </div>
  </div>

  <?php if (!empty($_SESSION['release_error'])): ?>
  <div style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;margin-bottom:14px;color:#dc2626;font-size:12.5px;font-weight:600;">
    <i class="fas fa-exclamation-triangle"></i> <?= $_SESSION['release_error'] ?>
  </div>
  <?php unset($_SESSION['release_error']); endif; ?>

  <?php
  // Load pending remit requests
  $pendingRemits = [];
  try {
    $pendingRemits = $pdo->query(
      "SELECT r.*, e.name AS entity_name, u.full_name AS treasurer_name
       FROM remit_requests r
       LEFT JOIN entities e ON e.id=r.entity_id
       LEFT JOIN users u ON u.id=r.treasurer_id
       WHERE r.status='Pending'
       ORDER BY r.created_at ASC"
    )->fetchAll();
  } catch (PDOException $ex) { $pendingRemits = []; }
  ?>

  <?php if ($pendingRemits): ?>
  <div style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;margin-bottom:18px;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
    <h3 style="font-size:14px;font-weight:700;color:#0f172a;margin-bottom:12px;display:flex;align-items:center;gap:8px;">
      <i class="fas fa-inbox" style="color:#ca8a04"></i>
      Pending Remittance Requests
      <span style="background:#fef9c3;color:#ca8a04;font-size:11px;padding:2px 8px;border-radius:10px;"><?= count($pendingRemits) ?></span>
    </h3>
    <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
      <thead><tr style="background:#f8fafc;">
        <th style="padding:9px 12px;text-align:left;color:#475569;font-size:11px;text-transform:uppercase;border-bottom:2px solid #e2e8f0;">Org / Club</th>
        <th style="padding:9px 12px;text-align:left;color:#475569;font-size:11px;text-transform:uppercase;border-bottom:2px solid #e2e8f0;">Treasurer</th>
        <th style="padding:9px 12px;text-align:left;color:#475569;font-size:11px;text-transform:uppercase;border-bottom:2px solid #e2e8f0;">Date</th>
        <th style="padding:9px 12px;text-align:left;color:#475569;font-size:11px;text-transform:uppercase;border-bottom:2px solid #e2e8f0;">Amount</th>
        <th style="padding:9px 12px;text-align:left;color:#475569;font-size:11px;text-transform:uppercase;border-bottom:2px solid #e2e8f0;">Description</th>
        <th style="padding:9px 12px;text-align:left;color:#475569;font-size:11px;text-transform:uppercase;border-bottom:2px solid #e2e8f0;">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($pendingRemits as $r): ?>
      <tr style="border-bottom:1px solid #f1f5f9;">
        <td style="padding:10px 12px;"><strong><?= htmlspecialchars($r['entity_name']) ?></strong></td>
        <td style="padding:10px 12px;color:#64748b;"><?= htmlspecialchars($r['treasurer_name']) ?></td>
        <td style="padding:10px 12px;color:#64748b;"><?= $r['request_date'] ?></td>
        <td style="padding:10px 12px;font-weight:700;color:#0b5d8b;"><?= fmt($r['amount']) ?></td>
        <td style="padding:10px 12px;color:#64748b;"><?= htmlspecialchars($r['description']??'—') ?></td>
        <td style="padding:10px 12px;">
          <div style="display:flex;gap:6px;">
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="approve_remit">
              <input type="hidden" name="remit_id" value="<?= $r['id'] ?>">
              <button type="submit" style="padding:5px 12px;background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0;border-radius:7px;cursor:pointer;font-size:12px;font-weight:600;" onclick="return confirm('Approve this remittance?')">
                <i class="fas fa-check"></i> Approve
              </button>
            </form>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="reject_remit">
              <input type="hidden" name="remit_id" value="<?= $r['id'] ?>">
              <button type="submit" style="padding:5px 12px;background:#fee2e2;color:#dc2626;border:1px solid #fecaca;border-radius:7px;cursor:pointer;font-size:12px;font-weight:600;" onclick="return confirm('Reject this remittance?')">
                <i class="fas fa-times"></i> Reject
              </button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <div class="item-list">
  <?php foreach ($orgs as $o): ?>
    <div class="item-row">
      <a href="organization.php?id=<?= $o['id'] ?>" class="item-btn" style="text-decoration:none;">
        <img src="<?= htmlspecialchars($o['photo_path'] ?? '/mini%20system/IMG/omsc logo.jpg') ?>"
             style="width:40px;height:40px;border-radius:50%;object-fit:cover;"
             onerror="this.src='/mini%20system/IMG/omsc logo.jpg'">
        <span class="item-name"><?= htmlspecialchars($o['name']) ?></span>
        <span style="margin-left:auto;font-size:11px;color:#888">
          Col: <?= fmt($o['collections']) ?> | Rem: <?= fmt($o['remaining']) ?>
        </span>
      </a>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= $o['id'] ?>">
        <button class="del-btn" type="submit" onclick="return confirm('Delete this organization?')">
          <i class="fas fa-trash"></i>
        </button>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (!$orgs): ?>
    <p style="text-align:center;color:#aaa;padding:40px;">No organizations yet.</p>
  <?php endif; ?>
  </div>
</div>

<!-- ADD ORG POPUP -->
<div class="popup-overlay" id="addPopup" onclick="if(event.target===this)this.style.display='none'">
  <div class="popup-card">
    <h2>Add New Organization</h2>
    <p>Fill in organization details</p>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <input type="text" name="name" placeholder="Organization Name *" required>
      <input type="text" name="short" placeholder="Abbreviation (optional)">
      <div class="popup-buttons">
        <button class="save-btn" type="submit">Save</button>
        <button class="cancel-btn" type="button" onclick="document.getElementById('addPopup').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

</main>
</div>
<!-- RELEASE FUND POPUP (SAS Admin only) -->
<div class="popup-overlay" id="releasePopup" onclick="if(event.target===this)this.style.display='none'">
  <div class="popup-card">
    <h2><i class="fas fa-hand-holding-usd" style="color:#0b5d8b"></i> Release Fund to Org / Club</h2>
    <p style="font-size:12px;color:#666;margin-bottom:14px;">SAS releases funds to support activities and events.</p>
    <form method="POST">
      <input type="hidden" name="action" value="release_fund">
      <label class="field-label">Organization / Club *</label>
      <select name="entity_id" style="width:100%;padding:9px 11px;margin-bottom:10px;border:1px solid #ddd;border-radius:7px;font-size:12px;outline:none;">
        <option value="">Select org / club</option>
        <?php foreach ($orgs as $o): ?>
        <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <label class="field-label">Release Date *</label>
      <input type="date" name="release_date" value="<?= date('Y-m-d') ?>">
      <label class="field-label">Purpose / Activity *</label>
      <input type="text" name="release_purpose" placeholder="e.g. Sports Fest, Leadership Training" required>
      <label class="field-label">Amount (&#8369;) *</label>
      <input type="number" name="release_amount" min="1" step="0.01" placeholder="0" required>
      <div class="popup-buttons" style="margin-top:12px;">
        <button class="save-btn" type="submit" onclick="return confirm('Release this fund to the selected org/club?')">
          <i class="fas fa-paper-plane"></i> Release
        </button>
        <button class="cancel-btn" type="button" onclick="document.getElementById('releasePopup').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<button class="toggle" onclick="document.getElementById('sidebar').classList.toggle('hidden')">☰</button>
</body>
</html>

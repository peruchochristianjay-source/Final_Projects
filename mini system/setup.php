<?php
require_once __DIR__ . '/api/db.php';
$pdo = db();

$log = [];

$tables = [

'remit_requests' => "CREATE TABLE IF NOT EXISTS remit_requests (
  id           INT           AUTO_INCREMENT PRIMARY KEY,
  entity_id    INT           NOT NULL,
  treasurer_id INT           NOT NULL,
  amount       DECIMAL(12,2) NOT NULL,
  description  VARCHAR(255)  NULL,
  status       ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  request_date DATE          NOT NULL,
  reviewed_at  TIMESTAMP     NULL,
  reviewed_by  INT           NULL,
  remarks      VARCHAR(255)  NULL,
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rr_entity   FOREIGN KEY (entity_id)    REFERENCES entities(id) ON DELETE CASCADE,
  CONSTRAINT fk_rr_user     FOREIGN KEY (treasurer_id) REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_rr_reviewer FOREIGN KEY (reviewed_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

'fund_releases' => "CREATE TABLE IF NOT EXISTS fund_releases (
  id           INT           AUTO_INCREMENT PRIMARY KEY,
  entity_id    INT           NOT NULL,
  released_by  INT           NOT NULL,
  amount       DECIMAL(12,2) NOT NULL,
  purpose      VARCHAR(255)  NOT NULL,
  release_date DATE          NOT NULL,
  status       ENUM('Released','Cancelled') NOT NULL DEFAULT 'Released',
  remarks      VARCHAR(255)  NULL,
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fr2_entity FOREIGN KEY (entity_id)   REFERENCES entities(id) ON DELETE CASCADE,
  CONSTRAINT fk_fr2_user   FOREIGN KEY (released_by) REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

];

foreach ($tables as $name => $sql) {
    try {
        $pdo->exec($sql);
        $log[] = ['table'=>$name, 'action'=>'Created/Verified', 'status'=>'ok'];
    } catch (PDOException $e) {
        $log[] = ['table'=>$name, 'action'=>'Create failed', 'status'=>'err', 'msg'=>$e->getMessage()];
    }
}

$entities = $pdo->query("SELECT id, name, short_name, category FROM entities ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$users    = $pdo->query("SELECT id, full_name, role, entity_id FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$adminId  = null;
$treasurerMap = []; 

foreach ($users as $u) {
    if ($u['role'] === 'admin') $adminId = $u['id'];
    if ($u['role'] === 'officer' && $u['entity_id']) $treasurerMap[$u['entity_id']] = $u['id'];
}

foreach ($entities as $e) {
    $exists = $pdo->prepare("SELECT COUNT(*) FROM entity_funds WHERE entity_id=:i");
    $exists->execute([':i'=>$e['id']]);
    if (!$exists->fetchColumn()) {
        $pdo->prepare("INSERT INTO entity_funds (entity_id,collections,expenses,previous_funds) VALUES (:i,0,0,0)")
            ->execute([':i'=>$e['id']]);
        $log[] = ['table'=>'entity_funds', 'action'=>'Inserted entity_id='.$e['id'], 'status'=>'ok'];
    }
}

$frCount = (int)$pdo->query("SELECT COUNT(*) FROM financial_records")->fetchColumn();
if ($frCount === 0) {
    
    $fees = [1=>15,2=>20,3=>35,4=>35,5=>10,6=>20,7=>20,8=>20,9=>20,10=>20];

    foreach ($entities as $e) {
        $eid  = $e['id'];
        $fee  = $fees[$eid] ?? 20;
        $paid = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE entity_id=$eid AND payment_status='Paid'")->fetchColumn();
        if ($paid === 0) continue;

        $totalCollected = $paid * $fee;
        $remitAmt = round($totalCollected * 0.8, 2);
        $expenseAmt = round($totalCollected * 0.1, 2);

        $createdBy = $treasurerMap[$eid] ?? $adminId;
        $pdo->prepare("INSERT INTO financial_records (entity_id,record_date,type,description,amount,created_by) VALUES (:e,'2024-09-15','Remit',:d,:a,:u)")
            ->execute([':e'=>$eid,':d'=>'Membership fee remittance - '.$e['short_name'],':a'=>$remitAmt,':u'=>$createdBy]);

        $pdo->prepare("INSERT INTO financial_records (entity_id,record_date,type,description,amount,created_by) VALUES (:e,'2024-10-10','Expense',:d,:a,:u)")
            ->execute([':e'=>$eid,':d'=>'Activity expense - '.$e['short_name'],':a'=>$expenseAmt,':u'=>$createdBy]);
    }
    $log[] = ['table'=>'financial_records', 'action'=>'Inserted remit + expense records per entity', 'status'=>'ok'];
}

$rrCount = (int)$pdo->query("SELECT COUNT(*) FROM remit_requests")->fetchColumn();
if ($rrCount === 0 && $adminId) {
    $fees = [1=>15,2=>20,3=>35,4=>35,5=>10,6=>20,7=>20,8=>20,9=>20,10=>20];
    foreach ($entities as $e) {
        $eid  = $e['id'];
        $fee  = $fees[$eid] ?? 20;
        $paid = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE entity_id=$eid AND payment_status='Paid'")->fetchColumn();
        if ($paid === 0) continue;

        $tId = $treasurerMap[$eid] ?? null;
        if (!$tId) continue;

        $totalCollected = $paid * $fee;
        $remitAmt   = round($totalCollected * 0.8, 2);
        $pendingAmt = round($totalCollected * 0.15, 2);

        // Approved remit
        $pdo->prepare("INSERT INTO remit_requests (entity_id,treasurer_id,amount,description,status,request_date,reviewed_at,reviewed_by,remarks) VALUES (:e,:t,:a,:d,'Approved','2024-09-10',NOW(),:r,'Verified and approved')")
            ->execute([':e'=>$eid,':t'=>$tId,':a'=>$remitAmt,':d'=>'Membership fee collection 2024-2025',':r'=>$adminId]);

        // Pending remit
        $pdo->prepare("INSERT INTO remit_requests (entity_id,treasurer_id,amount,description,status,request_date) VALUES (:e,:t,:a,:d,'Pending','2024-11-05')")
            ->execute([':e'=>$eid,':t'=>$tId,':a'=>$pendingAmt,':d'=>'2nd semester membership fee collection']);
    }
    $log[] = ['table'=>'remit_requests', 'action'=>'Inserted approved + pending per entity', 'status'=>'ok'];
}

// ── 6. FUND RELEASES — SAS releases funds for activities ─────────────────────
$frCount2 = (int)$pdo->query("SELECT COUNT(*) FROM fund_releases")->fetchColumn();
if ($frCount2 === 0 && $adminId) {
    $releases = [
        [1,  500,  'Sports Fest 2024',           '2024-10-15'],
        [2,  300,  'Leadership Training',         '2024-10-20'],
        [3,  800,  'Programming Contest',         '2024-11-01'],
        [4,  400,  'Mentoring Seminar',           '2024-10-25'],
        [5,  200,  'Library Week Activities',     '2024-11-05'],
        [6,  350,  'Finance Summit 2024',         '2024-11-10'],
        [7,  600,  'Intramurals 2024',            '2024-10-18'],
        [8,  250,  'English Proficiency Week',    '2024-10-22'],
        [9,  300,  'Math-Science Quiz Bee',       '2024-11-03'],
        [10, 200,  'Samahan ng Pilipino Festival','2024-11-08'],
    ];
    foreach ($releases as [$eid, $amt, $purpose, $date]) {
        $pdo->prepare("INSERT INTO fund_releases (entity_id,released_by,amount,purpose,release_date,status) VALUES (:e,:u,:a,:p,:d,'Released')")
            ->execute([':e'=>$eid,':u'=>$adminId,':a'=>$amt,':p'=>$purpose,':d'=>$date]);
        // Add income transaction to entity
        $pdo->prepare("INSERT INTO transactions (entity_id,tx_date,type,description,amount,source,created_by) VALUES (:e,:d,'Income',:ds,:a,'admin_release',:u)")
            ->execute([':e'=>$eid,':d'=>$date,':ds'=>'Fund Released by SAS: '.$purpose,':a'=>$amt,':u'=>$adminId]);
    }
    $log[] = ['table'=>'fund_releases', 'action'=>'Inserted 10 fund releases (1 per entity)', 'status'=>'ok'];
}

// ── 7. TRANSACTIONS — ensure student transactions exist ──────────────────────
$txCount = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE source='student'")->fetchColumn();
if ($txCount === 0) {
    $fees = [1=>15,2=>20,3=>35,4=>35,5=>10,6=>20,7=>20,8=>20,9=>20,10=>20];
    foreach ($entities as $e) {
        $eid = $e['id'];
        $fee = $fees[$eid] ?? 20;
        $paidStudents = $pdo->query("SELECT id, full_name, amount_due FROM students WHERE entity_id=$eid AND payment_status='Paid'")->fetchAll();
        foreach ($paidStudents as $s) {
            $pdo->prepare("INSERT INTO transactions (entity_id,tx_date,type,description,amount,source,created_by) VALUES (:e,CURDATE(),'Income',:d,:a,'student',1)")
                ->execute([':e'=>$eid,':d'=>'Membership fee - '.$s['full_name'],':a'=>$s['amount_due']]);
        }
    }
    $log[] = ['table'=>'transactions', 'action'=>'Inserted student payment transactions', 'status'=>'ok'];
}

// ── 8. ACCREDITED ENTRIES — ensure data exists ───────────────────────────────
$aeCount = (int)$pdo->query("SELECT COUNT(*) FROM accredited_entries")->fetchColumn();
if ($aeCount === 0) {
    $entries = [
        ['Supreme Student Government (SSG)',              'Organization','Dr. Santos',   '2024-2025','2024-01-10','2025-01-10','Accredited',''],
        ['Junior Operation Executive Society (JOES)',     'Organization','Ms. Reyes',    '2024-2025','2024-02-15','2025-02-15','Accredited',''],
        ['Programers, Animators, Developers Clan (PADC)','Organization','Mr. Cruz',     '2024-2025','2024-03-01','2025-03-01','Accredited',''],
        ['Young Mentors Organization (YMO)',              'Organization','Ms. Garcia',   '2023-2024','2023-06-20','2024-06-20','Expired',   ''],
        ['Library Student Council (LSC)',                 'Organization','Mr. Dela Cruz','2024-2025','2024-05-05','2025-05-05','Accredited',''],
        ['Junior Financial Management Society (JFMS)',    'Organization','Ms. Torres',   '2024-2025','2024-07-01','2025-07-01','Pending',   'Awaiting documents'],
        ['Sports Club',   'Club','Mr. Bautista','2024-2025','2024-01-20','2025-01-20','Accredited',''],
        ['English Club',  'Club','Ms. Lim',     '2024-2025','2024-03-10','2025-03-10','Accredited',''],
        ['Sci-Math Club', 'Club','Dr. Ramos',   '2023-2024','2023-09-01','2024-09-01','Expired',   ''],
        ['SamFilko Club', 'Club','Mr. Santos',  '2024-2025','2024-01-15','2025-01-15','Accredited',''],
    ];
    foreach ($entries as [$name,$type,$adviser,$year,$acc,$exp,$status,$remarks]) {
        $pdo->prepare("INSERT INTO accredited_entries (name,entry_type,adviser,academic_year,accredited_date,expiry_date,status,remarks) VALUES (:n,:t,:a,:y,:d,:e,:s,:r)")
            ->execute([':n'=>$name,':t'=>$type,':a'=>$adviser,':y'=>$year,':d'=>$acc,':e'=>$exp,':s'=>$status,':r'=>$remarks]);
    }
    $log[] = ['table'=>'accredited_entries', 'action'=>'Inserted 10 accredited entries', 'status'=>'ok'];
}

// ── SUMMARY ───────────────────────────────────────────────────────────────────
$summary = [];
$allTables = ['entities','users','students','entity_funds','transactions','financial_records','remit_requests','fund_releases','accredited_entries'];
foreach ($allTables as $t) {
    try { $summary[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(); }
    catch (PDOException $e) { $summary[$t] = 'MISSING'; }
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>DB Setup</title>
<style>
body{font-family:sans-serif;max-width:900px;margin:30px auto;padding:0 20px;}
h2{color:#0b5d8b;} h3{color:#1d3557;margin:20px 0 8px;}
table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px;}
th{background:#0b5d8b;color:white;padding:9px 12px;text-align:left;}
td{padding:8px 12px;border-bottom:1px solid #eee;}
.ok{color:#16a34a;font-weight:700;} .err{color:#dc2626;font-weight:700;}
.warn{color:#ca8a04;font-weight:700;}
a{display:inline-block;margin:6px 6px 0 0;background:#0b5d8b;color:white;padding:9px 18px;border-radius:8px;text-decoration:none;font-size:13px;}
</style>
</head><body>
<h2>🛠 E-CFunds — Database Setup Complete</h2>

<h3>Actions Performed</h3>
<table>
  <tr><th>Table</th><th>Action</th><th>Status</th></tr>
  <?php foreach ($log as $l): ?>
  <tr>
    <td><code><?= $l['table'] ?></code></td>
    <td><?= $l['action'] ?></td>
    <td class="<?= $l['status']==='ok'?'ok':'err' ?>"><?= $l['status']==='ok'?'✔ Done':'✘ '.$l['msg'] ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (empty($log)): ?>
  <tr><td colspan="3" class="ok">✔ All tables already up to date. No changes needed.</td></tr>
  <?php endif; ?>
</table>

<h3>Current Table Counts</h3>
<table>
  <tr><th>Table</th><th>Rows</th><th>Status</th></tr>
  <?php foreach ($summary as $t => $count): ?>
  <tr>
    <td><code><?= $t ?></code></td>
    <td><?= $count ?></td>
    <td class="<?= $count==='MISSING'?'err':($count==0?'warn':'ok') ?>">
      <?= $count==='MISSING'?'✘ Missing':($count==0?'⚠ Empty':'✔ OK') ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>

<h3>System Flow Summary</h3>
<table>
  <tr><th>Step</th><th>Who</th><th>Action</th><th>Table Affected</th></tr>
  <tr><td>1</td><td>Treasurer</td><td>Marks students as Paid</td><td>students, transactions</td></tr>
  <tr><td>2</td><td>Treasurer</td><td>Submits remit request to SAS</td><td>remit_requests</td></tr>
  <tr><td>3</td><td>SAS Admin</td><td>Approves remit → records it</td><td>remit_requests, financial_records, transactions</td></tr>
  <tr><td>4</td><td>SAS Admin</td><td>Releases fund for activities/events</td><td>fund_releases, transactions</td></tr>
  <tr><td>5</td><td>Treasurer</td><td>Records expenses from released funds</td><td>financial_records, transactions</td></tr>
</table>

<a href="/mini%20system/pages/dashboard.php">→ Dashboard</a>
<a href="/mini%20system/pages/organization.php">→ Organizations</a>
<a href="/mini%20system/pages/treasurer.php">→ Treasurer</a>
<a href="/mini%20system/dbcheck.php">→ Check DB Again</a>
</body></html>

<?php
require_once __DIR__ . '/api/db.php';
$pdo = db();

// Check entities exist
$entities = $pdo->query("SELECT id, name, short_name, category FROM entities ORDER BY id")->fetchAll();
if (empty($entities)) {
    echo "<h2 style='color:red'>❌ No entities found. Please run seed.sql first.</h2>";
    exit;
}

// Show entities
echo "<h3>Entities Found:</h3><pre>";
foreach ($entities as $e) echo $e['id']." | ".$e['short_name']." | ".$e['category']."\n";
echo "</pre>";

// Fees per entity
$fees = [
    1 => 15, // SSG
    2 => 20, // JOES
    3 => 35, // PADC
    4 => 35, // YMO
    5 => 10, // LSC
    6 => 20, // JFMS
    7 => 20, // Sports Club
    8 => 20, // English Club
    9 => 20, // Sci-Math Club
    10=> 20, // SamFilko Club
];

$firstNames = ['Juan','Maria','Jose','Ana','Pedro','Rosa','Carlo','Liza','Mark','Anna','Ben','Cris','Dan','Eva','Fred','Grace','Hans','Iris','Jay','Kim','Leo','Mia','Noel','Olive','Paul','Queen','Rex','Sue','Tim','Uma','Vic','Wes','Xia','Yam','Zoe','Ace','Bea','Coy','Dex','Eve','Fox','Gem','Hue','Ivy','Jed','Kay','Lou','Max','Nia','Oz'];
$lastNames  = ['Reyes','Santos','Cruz','Garcia','Lim','Torres','Bautista','Dela Cruz','Mendoza','Ramos','Villanueva','Aquino','Pascual','Flores','Castillo','Morales','Rivera','Navarro','Domingo','Aguilar','Salazar','Dizon','Ocampo','Soriano','Dela Torre','Manalo','Bernardo','Tolentino','Macaraeg','Buenaventura'];
$courses    = ['BSIT','BSBA(FM)','BSBA(OM)','BEED'];
$years      = ['1st Year','2nd Year','3rd Year','4th Year'];

// Course mapping per entity
$entityCourses = [
    1  => ['BSIT','BSBA(FM)','BSBA(OM)','BEED'], // SSG - randomize all
    2  => ['BSBA(OM)'],                            // JOES - OM only
    3  => ['BSIT'],                                // PADC - BSIT only
    4  => ['BEED'],                                // YMO - BEED only
    5  => ['BSIT','BSBA(FM)','BSBA(OM)','BEED'], // LSC - randomize all
    6  => ['BSBA(FM)'],                            // JFMS - BSBA(FM) only
    7  => ['BSIT','BSBA(FM)','BSBA(OM)','BEED'], // Sports Club - randomize
    8  => ['BSIT','BSBA(FM)','BSBA(OM)','BEED'], // English Club - randomize
    9  => ['BSIT','BSBA(FM)','BSBA(OM)','BEED'], // Sci-Math - randomize
    10 => ['BSIT','BSBA(FM)','BSBA(OM)','BEED'], // SamFilko - randomize
];

// Clear old data
$pdo->exec("DELETE FROM transactions WHERE source='student'");
$pdo->exec("DELETE FROM students");

$inserted = 0;
$txnCount = 0;

foreach ($entities as $ent) {
    $eid = $ent['id'];
    $fee = $fees[$eid] ?? 20;

    for ($i = 1; $i <= 50; $i++) {
        $num       = str_pad(($eid * 100) + $i, 5, '0', STR_PAD_LEFT);
        $studentId = "2024-$num";
        $firstName = $firstNames[($i - 1) % count($firstNames)];
        $lastName  = $lastNames[($i - 1) % count($lastNames)];
        $fullName  = "$lastName, $firstName";
        $course    = $entityCourses[$eid][($i - 1) % count($entityCourses[$eid])];
        $year      = $years[($i - 1) % 4];
        $status    = ($i % 5 !== 0 && $i % 3 !== 0) ? 'Paid' : 'Unpaid';

        $pdo->prepare("INSERT INTO students (student_id, full_name, course, year_level, entity_id, amount_due, payment_status) VALUES (:sid,:fn,:c,:y,:e,:a,:s)")
            ->execute([':sid'=>$studentId,':fn'=>$fullName,':c'=>$course,':y'=>$year,':e'=>$eid,':a'=>$fee,':s'=>$status]);

        if ($status === 'Paid') {
            $pdo->prepare("INSERT INTO transactions (entity_id, tx_date, type, description, amount, source, created_by) VALUES (:e, CURDATE(), 'Income', :d, :a, 'student', 1)")
                ->execute([':e'=>$eid,':d'=>'Membership fee - '.$fullName,':a'=>$fee]);
            $txnCount++;
        }
        $inserted++;
    }
}

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Done</title>
<style>body{font-family:sans-serif;max-width:750px;margin:40px auto;padding:0 20px;}
h2{color:#16a34a;} table{width:100%;border-collapse:collapse;font-size:13px;margin-top:16px;}
th{background:#0b5d8b;color:white;padding:9px 12px;text-align:left;}
td{padding:8px 12px;border-bottom:1px solid #eee;}
tr:hover{background:#f9f9f9;}
.paid{color:#16a34a;font-weight:700;} .unpaid{color:#dc2626;font-weight:700;}
a{display:inline-block;margin-top:20px;background:#0b5d8b;color:white;padding:10px 20px;border-radius:6px;text-decoration:none;margin-right:8px;}
</style></head><body>";

echo "<h2>✅ Database Updated Successfully!</h2>";
echo "<p>Students inserted: <b>$inserted</b> | Transactions: <b>$txnCount</b></p>";
echo "<table><tr><th>Entity</th><th>Category</th><th>Fee</th><th>Total</th><th>Paid</th><th>Unpaid</th><th>Collected</th></tr>";

foreach ($entities as $ent) {
    $eid     = $ent['id'];
    $fee     = $fees[$eid] ?? 20;
    $total   = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE entity_id=$eid")->fetchColumn();
    $paid    = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE entity_id=$eid AND payment_status='Paid'")->fetchColumn();
    $unpaid  = $total - $paid;
    $col     = (float)$pdo->query("SELECT COALESCE(SUM(amount_due),0) FROM students WHERE entity_id=$eid AND payment_status='Paid'")->fetchColumn();
    echo "<tr>
        <td><b>{$ent['name']}</b></td>
        <td>{$ent['category']}</td>
        <td>₱$fee</td>
        <td>$total</td>
        <td class='paid'>$paid</td>
        <td class='unpaid'>$unpaid</td>
        <td>₱".number_format($col)."</td>
    </tr>";
}
echo "</table>";
echo "<a href='/mini%20system/pages/students.php'>→ Students</a>";
echo "<a href='/mini%20system/pages/dashboard.php'>→ Dashboard</a>";
echo "<a href='/mini%20system/pages/organization.php'>→ Organizations</a>";
echo "</body></html>";

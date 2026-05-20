<?php
session_start();
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'officer') {
    header('Location: /mini%20system/pages/login');
    exit;
}

require_once __DIR__ . '/../api/db.php';

$pdo  = db();
$user = $_SESSION['user'];
$myEntityId = (int)($user['entityId'] ?? 0);

if ($myEntityId <= 0) {
    $_SESSION['treasurer_error'] = '⚠ Invalid treasurer account scope. Please sign in again.';
    header('Location: /mini%20system/pages/login');
    exit;
}

function getTreasurerTotals(PDO $pdo, int $entityId): array
{
    $studentCollectedStmt = $pdo->prepare("SELECT COALESCE(SUM(amount_due),0) FROM students WHERE entity_id=:e AND payment_status='Paid'");
    $studentCollectedStmt->execute([':e' => $entityId]);
    $studentCollected = (float)$studentCollectedStmt->fetchColumn();

    $approvedRemittedStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM remit_requests WHERE entity_id=:e AND status='Approved'");
    $approvedRemittedStmt->execute([':e' => $entityId]);
    $approvedRemitted = (float)$approvedRemittedStmt->fetchColumn();

    $pendingRemittedStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM remit_requests WHERE entity_id=:e AND status='Pending'");
    $pendingRemittedStmt->execute([':e' => $entityId]);
    $pendingRemitted = (float)$pendingRemittedStmt->fetchColumn();

    $pendingCountStmt = $pdo->prepare("SELECT COUNT(*) FROM remit_requests WHERE entity_id=:e AND status='Pending'");
    $pendingCountStmt->execute([':e' => $entityId]);
    $pendingCount = (int)$pendingCountStmt->fetchColumn();

    $releasedStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fund_releases WHERE entity_id=:e AND status='Released'");
    $releasedStmt->execute([':e' => $entityId]);
    $releasedFromSas = (float)$releasedStmt->fetchColumn();

    $previousStmt = $pdo->prepare("SELECT COALESCE(previous_funds,0) FROM entity_funds WHERE entity_id=:e LIMIT 1");
    $previousStmt->execute([':e' => $entityId]);
    $previousFunds = (float)$previousStmt->fetchColumn();

    $expenseStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM financial_records WHERE entity_id=:e AND type='Expense'");
    $expenseStmt->execute([':e' => $entityId]);
    $entityExpenses = (float)$expenseStmt->fetchColumn();

    $availableToRemit = max(0, $studentCollected - $approvedRemitted - $pendingRemitted);
    $availableBalance = $previousFunds + $studentCollected + $releasedFromSas - $approvedRemitted - $entityExpenses;

    return [
        'student_collected'   => $studentCollected,
        'approved_remitted'   => $approvedRemitted,
        'pending_remitted'    => $pendingRemitted,
        'pending_count'       => $pendingCount,
        'released_from_sas'   => $releasedFromSas,
        'previous_funds'      => $previousFunds,
        'entity_expenses'     => $entityExpenses,
        'available_to_remit'  => $availableToRemit,
        'available_balance'   => $availableBalance,
    ];
}

$allowedSections = ['overview', 'fund-status', 'remit', 'financial', 'transactions', 'students'];
$section = $_GET['s'] ?? 'overview';
if (!in_array($section, $allowedSections, true)) {
    $section = 'overview';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'request_remit') {
        $amount = round((float)($_POST['remit_amount'] ?? 0), 2);
        $description = trim($_POST['remit_description'] ?? '');
        if ($description === '') {
            $description = 'Membership fee remittance';
        }

        $totalsNow = getTreasurerTotals($pdo, $myEntityId);
        $maxAllowed = (float)$totalsNow['available_to_remit'];

        if ($amount <= 0) {
            $_SESSION['treasurer_notice'] = ['type' => 'error', 'text' => '⚠ Remittance amount must be greater than zero.'];
        } elseif ($maxAllowed <= 0) {
            $_SESSION['treasurer_notice'] = ['type' => 'error', 'text' => '⚠ No remaining collectible amount is available for remittance.'];
        } elseif ($amount > $maxAllowed) {
            $_SESSION['treasurer_notice'] = [
                'type' => 'error',
                'text' => '⚠ Amount exceeds available remittance balance of ₱' . number_format($maxAllowed, 2) . '.'
            ];
        } else {
            $insertRemit = $pdo->prepare(
                "INSERT INTO remit_requests (entity_id, treasurer_id, amount, description, status, request_date)
                 VALUES (:e, :t, :a, :d, 'Pending', CURDATE())"
            );
            $insertRemit->execute([
                ':e' => $myEntityId,
                ':t' => (int)$user['id'],
                ':a' => $amount,
                ':d' => $description,
            ]);
            $_SESSION['treasurer_notice'] = ['type' => 'success', 'text' => '✔ Remittance request submitted to SAS Admin.'];
        }
        header('Location: /mini%20system/pages/treasurer?s=remit');
        exit;
    }
    if ($action === 'add_student') {
        $studentId = trim((string)($_POST['student_id'] ?? ''));
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $course = trim((string)($_POST['course'] ?? ''));
        $yearLevel = trim((string)($_POST['year_level'] ?? ''));
        $amountDue = round((float)($_POST['amount_due'] ?? 0), 2);
        $paymentStatus = (($_POST['payment_status'] ?? '') === 'Paid') ? 'Paid' : 'Unpaid';

        if ($studentId === '' || $fullName === '' || $course === '' || $yearLevel === '') {
            $_SESSION['treasurer_notice'] = ['type' => 'error', 'text' => '⚠ Please complete all required student fields.'];
        } elseif ($amountDue <= 0) {
            $_SESSION['treasurer_notice'] = ['type' => 'error', 'text' => '⚠ Amount due must be greater than zero.'];
        } else {
            try {
                $checkStudent = $pdo->prepare("SELECT id FROM students WHERE student_id=:sid LIMIT 1");
                $checkStudent->execute([':sid' => $studentId]);
                if ($checkStudent->fetch()) {
                    $_SESSION['treasurer_notice'] = ['type' => 'error', 'text' => '⚠ Student ID already exists. Please use a unique Student ID.'];
                } else {
                    $insertStudent = $pdo->prepare(
                        "INSERT INTO students (student_id, full_name, course, year_level, entity_id, amount_due, payment_status)
                         VALUES (:sid, :fn, :course, :year, :entity, :amount, :status)"
                    );
                    $insertStudent->execute([
                        ':sid' => $studentId,
                        ':fn' => $fullName,
                        ':course' => $course,
                        ':year' => $yearLevel,
                        ':entity' => $myEntityId,
                        ':amount' => $amountDue,
                        ':status' => $paymentStatus,
                    ]);

                    if ($paymentStatus === 'Paid') {
                        $insertTxn = $pdo->prepare(
                            "INSERT INTO transactions (entity_id, tx_date, type, description, amount, source, created_by)
                             VALUES (:entity, CURDATE(), 'Income', :description, :amount, 'student', :createdBy)"
                        );
                        $insertTxn->execute([
                            ':entity' => $myEntityId,
                            ':description' => 'Membership fee - ' . $fullName,
                            ':amount' => $amountDue,
                            ':createdBy' => (int)$user['id'],
                        ]);
                    }

                    $_SESSION['treasurer_notice'] = ['type' => 'success', 'text' => '✔ Student added successfully.'];
                }
            } catch (PDOException $e) {
                $_SESSION['treasurer_notice'] = ['type' => 'error', 'text' => '⚠ Unable to add student right now. Please try again.'];
            }
        }
        header('Location: /mini%20system/pages/treasurer?s=students');
        exit;
    }
    if ($action === 'delete_student') {
        $studentRowId = (int)($_POST['student_row_id'] ?? 0);
        if ($studentRowId <= 0) {
            $_SESSION['treasurer_notice'] = ['type' => 'error', 'text' => '⚠ Invalid student selected for deletion.'];
        } else {
            try {
                $studentRowStmt = $pdo->prepare(
                    "SELECT id, full_name
                     FROM students
                     WHERE id=:id AND entity_id=:e
                     LIMIT 1"
                );
                $studentRowStmt->execute([
                    ':id' => $studentRowId,
                    ':e' => $myEntityId,
                ]);
                $studentRow = $studentRowStmt->fetch();

                if (!$studentRow) {
                    $_SESSION['treasurer_notice'] = ['type' => 'error', 'text' => '⚠ Student record not found in your organization list.'];
                } else {
                    $deleteStmt = $pdo->prepare("DELETE FROM students WHERE id=:id AND entity_id=:e LIMIT 1");
                    $deleteStmt->execute([
                        ':id' => $studentRowId,
                        ':e' => $myEntityId,
                    ]);

                    if ($deleteStmt->rowCount() > 0) {
                        $_SESSION['treasurer_notice'] = ['type' => 'success', 'text' => '✔ Student removed: ' . $studentRow['full_name']];
                    } else {
                        $_SESSION['treasurer_notice'] = ['type' => 'error', 'text' => '⚠ Unable to delete student right now. Please try again.'];
                    }
                }
            } catch (PDOException $e) {
                $_SESSION['treasurer_notice'] = ['type' => 'error', 'text' => '⚠ Unable to delete student right now. Please try again.'];
            }
        }
        header('Location: /mini%20system/pages/treasurer?s=students');
        exit;
    }
}

$entityStmt = $pdo->prepare("SELECT id, name, short_name, category, photo_path FROM entities WHERE id=:id AND is_active=1 LIMIT 1");
$entityStmt->execute([':id' => $myEntityId]);
$entity = $entityStmt->fetch();
if (!$entity) {
    $_SESSION['treasurer_error'] = '⚠ Your assigned organization or club is inactive. Contact SAS Admin.';
    header('Location: /mini%20system/pages/login');
    exit;
}

$studentsStmt = $pdo->prepare(
    "SELECT id, student_id, full_name, course, year_level, amount_due, payment_status, created_at
     FROM students
     WHERE entity_id=:e
     ORDER BY full_name ASC"
);
$studentsStmt->execute([':e' => $myEntityId]);
$students = $studentsStmt->fetchAll();

$remitRequestsStmt = $pdo->prepare(
    "SELECT rr.id, rr.amount, rr.description, rr.status, rr.request_date, rr.reviewed_at, rr.remarks,
            rv.full_name AS reviewed_by_name
     FROM remit_requests rr
     LEFT JOIN users rv ON rv.id = rr.reviewed_by
     WHERE rr.entity_id=:e
     ORDER BY rr.request_date DESC, rr.id DESC"
);
$remitRequestsStmt->execute([':e' => $myEntityId]);
$remitRequests = $remitRequestsStmt->fetchAll();

$financialRecordsStmt = $pdo->prepare(
    "SELECT id, record_date, type, description, amount
     FROM financial_records
     WHERE entity_id=:e
     ORDER BY record_date DESC, id DESC"
);
$financialRecordsStmt->execute([':e' => $myEntityId]);
$financialRecords = $financialRecordsStmt->fetchAll();

$fundReleasesStmt = $pdo->prepare(
    "SELECT fr.id, fr.release_date, fr.amount, fr.purpose, fr.status, fr.remarks, u.full_name AS released_by_name
     FROM fund_releases fr
     LEFT JOIN users u ON u.id = fr.released_by
     WHERE fr.entity_id=:e
     ORDER BY fr.release_date DESC, fr.id DESC"
);
$fundReleasesStmt->execute([':e' => $myEntityId]);
$fundReleases = $fundReleasesStmt->fetchAll();

$transactionsStmt = $pdo->prepare(
    "SELECT t.id, t.tx_date, t.type, t.amount, t.description, t.source, u.full_name AS created_by_name
     FROM transactions t
     LEFT JOIN users u ON u.id = t.created_by
     WHERE t.entity_id=:e
     ORDER BY t.tx_date DESC, t.id DESC"
);
$transactionsStmt->execute([':e' => $myEntityId]);
$transactions = $transactionsStmt->fetchAll();


$totals = getTreasurerTotals($pdo, $myEntityId);
$studentPaidCount = 0;
$studentUnpaidCount = 0;
foreach ($students as $s) {
    if (($s['payment_status'] ?? '') === 'Paid') {
        $studentPaidCount++;
    } else {
        $studentUnpaidCount++;
    }
}

$notice = $_SESSION['treasurer_notice'] ?? null;
if (!$notice && !empty($_SESSION['treasurer_error'])) {
    $notice = ['type' => 'error', 'text' => (string)$_SESSION['treasurer_error']];
}
unset($_SESSION['treasurer_notice'], $_SESSION['treasurer_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>E-CFunds — Treasurer Dashboard</title>
  <link rel="stylesheet" href="/mini%20system/CSS/dashboard.css">
  <link rel="stylesheet" href="/mini%20system/CSS/professional_ui.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
  <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
  <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
</head>
<body>
<div id="root"></div>
<script>
const PHP = {
  user: <?= json_encode(['id'=>(int)$user['id'], 'fullName'=>$user['fullName'], 'email'=>$user['email']]) ?>,
  entity: <?= json_encode([
    'id'=>(int)$entity['id'],
    'name'=>$entity['name'],
    'short_name'=>$entity['short_name'],
    'category'=>$entity['category'],
    'photo_path'=>$entity['photo_path']
  ]) ?>,
  section: <?= json_encode($section) ?>,
  totals: <?= json_encode($totals) ?>,
  students: <?= json_encode($students) ?>,
  remits: <?= json_encode($remitRequests) ?>,
  financialRecords: <?= json_encode($financialRecords) ?>,
  fundReleases: <?= json_encode($fundReleases) ?>,
  transactions: <?= json_encode($transactions) ?>,
  studentCounts: <?= json_encode(['paid'=>$studentPaidCount, 'unpaid'=>$studentUnpaidCount, 'total'=>count($students)]) ?>,
  notice: <?= json_encode($notice) ?>,
  BASE: '/mini%20system'
};
</script>
<script type="text/babel">
const { useEffect, useMemo, useRef, useState } = React;

const fmtMoney = (v) => '₱' + Number(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: '2-digit' }) : '—';

function Sidebar({ sidebarOpen }) {
  const entityName = PHP.entity.short_name || PHP.entity.name || 'Entity';
  const parts = entityName.split(/\s+/).filter(Boolean);
  const entityInitials = (parts.length > 1 ? parts.map((p) => p[0]).join('') : entityName.slice(0, 3)).toUpperCase();
  const roleLabel = PHP.entity.category === 'club' ? 'Club Treasurer' : 'Organization Treasurer';
  const rawLogoPath = (PHP.entity.photo_path || '').trim();
  const entityLogoPath = rawLogoPath
    ? (/^(https?:)?\/\//.test(rawLogoPath) || rawLogoPath.startsWith('/')
      ? rawLogoPath
      : `${PHP.BASE}/${rawLogoPath.replace(/^\/+/, '')}`)
    : '';
  const links = [
    { key: 'overview', label: 'Overview', icon: 'fa-chart-line' },
    { key: 'fund-status', label: 'Fund Status', icon: 'fa-coins' },
    { key: 'remit', label: 'Admin Remittance', icon: 'fa-paper-plane' },
    { key: 'financial', label: 'Financial', icon: 'fa-wallet' },
    { key: 'transactions', label: 'Transactions', icon: 'fa-receipt' },
    { key: 'students', label: 'Students', icon: 'fa-id-card' },
  ];

  return (
    <aside className={`sidebar${sidebarOpen ? '' : ' hidden'}`} id="sidebar">
      <div>
        <div className="logo">
          {entityLogoPath ? (
            <img src={entityLogoPath} alt={`${entityName} Logo`} />
          ) : (
            <div className="logo-fallback">{entityInitials}</div>
          )}
          <h2>
            {entityName}<br/>
            <span>{roleLabel}</span>
          </h2>
        </div>
        <nav className="nav-menu">
          {links.map((l) => (
            <a key={l.key} href={`treasurer?s=${l.key}`} className={PHP.section === l.key ? 'nav-active' : ''}>
              <i className={`fas ${l.icon}`}></i> {l.label}
            </a>
          ))}
        </nav>
      </div>
      <div className="user">
        <p><strong>{PHP.user.fullName}</strong></p>
        <small>{roleLabel} • Reports to SAS Admin</small>
        <button className="logout" onClick={() => { window.location.href = 'logout'; }}>
          ⟲ Log out
        </button>
      </div>
    </aside>
  );
}

function SummaryCard({ label, value, color }) {
  return (
    <div className={`sum-card ${color}`}>
      <p className="sum-label">{label}</p>
      <h2>{value}</h2>
    </div>
  );
}

function statusPill(status) {
  const colors = {
    Pending: { bg: '#fef9c3', text: '#a16207' },
    Approved: { bg: '#dcfce7', text: '#15803d' },
    Rejected: { bg: '#fee2e2', text: '#dc2626' },
    Released: { bg: '#dbeafe', text: '#1d4ed8' },
    Paid: { bg: '#dcfce7', text: '#15803d' },
    Unpaid: { bg: '#fee2e2', text: '#dc2626' },
  };
  const c = colors[status] || { bg: '#e2e8f0', text: '#475569' };
  return {
    background: c.bg,
    color: c.text,
    borderRadius: 12,
    padding: '3px 10px',
    fontWeight: 700,
    fontSize: 11,
    display: 'inline-block',
  };
}

function TreasurerInbox() {
  const [open, setOpen] = useState(false);
  const [messages, setMessages] = useState([]);
  const [input, setInput] = useState('');
  const [unread, setUnread] = useState(0);
  const [sending, setSending] = useState(false);
  const listRef = useRef(null);

  const loadUnread = async () => {
    try {
      const res = await fetch('message?action=unread');
      const data = await res.json();
      setUnread(parseInt(data.unread || 0, 10));
    } catch (e) {}
  };

  const loadMessages = async () => {
    try {
      const res = await fetch('message?action=fetch');
      const data = await res.json();
      setMessages(Array.isArray(data) ? data : []);
      loadUnread();
    } catch (e) {}
  };

  const sendMessage = async () => {
    const text = input.trim();
    if (!text || sending) return;
    setSending(true);
    try {
      const fd = new FormData();
      fd.append('action', 'send');
      fd.append('message', text);
      await fetch('message', { method: 'POST', body: fd });
      setInput('');
      await loadMessages();
    } finally {
      setSending(false);
    }
  };

  useEffect(() => {
    loadUnread();
    const t = setInterval(loadUnread, 10000);
    return () => clearInterval(t);
  }, []);

  useEffect(() => {
    if (!open) return undefined;
    loadMessages();
    const t = setInterval(loadMessages, 8000);
    return () => clearInterval(t);
  }, [open]);

  useEffect(() => {
    if (open && listRef.current) {
      listRef.current.scrollTop = listRef.current.scrollHeight;
    }
  }, [messages, open]);
  const canSend = !!input.trim() && !sending;

  return (
    <>
      <button
        title="Message SAS Admin"
        onClick={() => setOpen(!open)}
        style={{
          position: 'fixed', bottom: 24, right: 24, width: 56, height: 56,
          borderRadius: '50%', border: 'none', background: '#0b5d8b', color: 'white',
          boxShadow: '0 6px 18px rgba(11,93,139,0.35)', cursor: 'pointer', zIndex: 9998, fontSize: 20,
        }}
      >
        <i className="fas fa-comments"></i>
        {unread > 0 && (
          <span style={{
            position: 'absolute', top: -4, right: -4, background: '#dc2626', color: 'white',
            borderRadius: '50%', width: 18, height: 18, fontSize: 10, fontWeight: 700,
            display: 'flex', alignItems: 'center', justifyContent: 'center',
          }}>
            {unread}
          </span>
        )}
      </button>

      {open && (
        <div style={{
          position: 'fixed', bottom: 88, right: 16, width: 'min(420px, calc(100vw - 32px))', height: 'min(520px, calc(100vh - 120px))', background: 'white',
          borderRadius: 14, overflow: 'hidden', boxShadow: '0 12px 32px rgba(0,0,0,0.2)', zIndex: 9999,
          display: 'flex', flexDirection: 'column',
        }}>
          <div style={{
            background: 'linear-gradient(135deg,#0b5d8b,#1a7ab5)', color: 'white',
            padding: '12px 14px', display: 'flex', justifyContent: 'space-between', alignItems: 'center',
          }}>
            <div>
              <div style={{ fontWeight: 700, fontSize: 13 }}>SAS Admin Channel</div>
              <div style={{ fontSize: 10, opacity: 0.85 }}>Connected by entity scope</div>
            </div>
            <button onClick={() => setOpen(false)} style={{ background: 'none', border: 'none', color: 'white', fontSize: 15, cursor: 'pointer' }}>
              <i className="fas fa-times"></i>
            </button>
          </div>

          <div ref={listRef} style={{ flex: 1, overflowY: 'auto', background: '#f8fafc', padding: 10, display: 'flex', flexDirection: 'column', gap: 7 }}>
            {messages.length === 0 ? (
              <div style={{ textAlign: 'center', color: '#94a3b8', marginTop: 24, fontSize: 12 }}>
                No messages yet.
              </div>
            ) : messages.map((m) => {
              const isMe = m.sender_role === 'officer';
              return (
                <div key={m.id} style={{
                  alignSelf: isMe ? 'flex-end' : 'flex-start',
                  maxWidth: '84%',
                  background: isMe ? '#0b5d8b' : '#e2e8f0',
                  color: isMe ? 'white' : '#1e293b',
                  borderRadius: 12,
                  padding: '8px 10px',
                  fontSize: 12.5,
                  lineHeight: 1.45,
                  whiteSpace: 'pre-wrap',
                  wordBreak: 'break-word',
                  overflowWrap: 'anywhere',
                }}>
                  <div style={{ fontSize: 10, opacity: 0.75, marginBottom: 2, fontWeight: 700 }}>
                    {isMe ? 'You' : m.sender_name}
                  </div>
                  <div>{m.message}</div>
                </div>
              );
            })}
          </div>

          <div style={{ borderTop: '1px solid #e2e8f0', padding: 10, background: 'white' }}>
            <div style={{ display: 'flex', gap: 6, alignItems: 'flex-end' }}>
              <textarea
                value={input}
                onChange={(e) => setInput(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                  }
                }}
                rows={2}
                placeholder="Message SAS Admin..."
                style={{
                  flex: 1, minHeight: 44, maxHeight: 112, resize: 'vertical',
                  padding: '9px 12px', border: '1px solid #d1d5db', borderRadius: 12,
                  fontSize: 12.5, outline: 'none', fontFamily: 'inherit', lineHeight: 1.45,
                }}
              />
              <button
                disabled={!canSend}
                onClick={sendMessage}
                style={{
                  width: 36, height: 36, borderRadius: '50%', border: 'none',
                  background: canSend ? '#16a34a' : '#94a3b8', color: 'white',
                  cursor: canSend ? 'pointer' : 'not-allowed',
                  display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0,
                }}
              >
                <i className={`fas ${sending ? 'fa-spinner fa-spin' : 'fa-paper-plane'}`}></i>
              </button>
            </div>
            <div style={{ fontSize: 10, color: '#94a3b8', marginTop: 4 }}>
              Press Enter to send, Shift+Enter for new line.
            </div>
          </div>
        </div>
      )}
    </>
  );
}

function App() {
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const [remitPopupOpen, setRemitPopupOpen] = useState(false);
  const [studentPopupOpen, setStudentPopupOpen] = useState(false);
  const latestRemits = useMemo(() => PHP.remits.slice(0, 5), []);

  return (
    <div className="container">
      <Sidebar sidebarOpen={sidebarOpen} />

      <main className="main">
        <div className="header">
          <div>
            <h1>{PHP.entity.short_name || PHP.entity.name} Treasurer Dashboard</h1>
            <p>{PHP.entity.name} — official remittance and fund operations linked to SAS Admin</p>
          </div>
          <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
            <span className="live-badge"><i className="fas fa-circle"></i> Live</span>
            <span className="live-badge react-badge"><i className="fab fa-react"></i> React</span>
            {PHP.section === 'remit' && (
              <button className="add-btn" onClick={() => setRemitPopupOpen(true)}>
                <i className="fas fa-plus"></i> Submit Remittance
              </button>
            )}
            {PHP.section === 'students' && (
              <button className="add-btn" onClick={() => setStudentPopupOpen(true)}>
                <i className="fas fa-user-plus"></i> Add Student
              </button>
            )}
          </div>
        </div>

        {PHP.notice && (
          <div style={{
            marginBottom: 14, borderRadius: 12, padding: '11px 13px', fontSize: 12.5, fontWeight: 700,
            border: PHP.notice.type === 'error' ? '1px solid #fecaca' : '1px solid #bbf7d0',
            background: PHP.notice.type === 'error' ? '#fee2e2' : '#dcfce7',
            color: PHP.notice.type === 'error' ? '#b91c1c' : '#166534',
            whiteSpace: 'pre-wrap',
            wordBreak: 'break-word',
            overflowWrap: 'anywhere',
          }}>
            {PHP.notice.text}
          </div>
        )}

        {PHP.section === 'overview' && (
          <>
            <div className="summary-row">
              <SummaryCard label="Paid Students" value={PHP.studentCounts.paid} color="green" />
              <SummaryCard label="Unpaid Students" value={PHP.studentCounts.unpaid} color="red" />
              <SummaryCard label="Collected Fees" value={fmtMoney(PHP.totals.student_collected)} color="blue" />
              <SummaryCard label="Available Balance" value={fmtMoney(PHP.totals.available_balance)} color="navy" />
            </div>

            <div className="detail-card">
              <h3><i className="fas fa-inbox"></i> Recent Remittance Activity</h3>
              <table>
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Description</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {latestRemits.length === 0 ? (
                    <tr><td colSpan="4" style={{ textAlign: 'center', color: '#94a3b8' }}>No remittance records yet.</td></tr>
                  ) : latestRemits.map((r) => (
                    <tr key={r.id}>
                      <td>{fmtDate(r.request_date)}</td>
                      <td style={{ fontWeight: 700, color: '#0b5d8b' }}>{fmtMoney(r.amount)}</td>
                      <td>{r.description || '—'}</td>
                      <td><span style={statusPill(r.status)}>{r.status}</span></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}

        {PHP.section === 'fund-status' && (
          <>
            <div className="summary-row">
              <SummaryCard label="Previous Funds" value={fmtMoney(PHP.totals.previous_funds)} color="navy" />
              <SummaryCard label="Released by SAS" value={fmtMoney(PHP.totals.released_from_sas)} color="blue" />
              <SummaryCard label="Approved Remitted" value={fmtMoney(PHP.totals.approved_remitted)} color="red" />
              <SummaryCard label="Current Balance" value={fmtMoney(PHP.totals.available_balance)} color="green" />
            </div>

            <div className="detail-card">
              <h3><i className="fas fa-calculator"></i> Fund Breakdown</h3>
              <table>
                <thead>
                  <tr>
                    <th>Category</th>
                    <th>Amount</th>
                    <th>Notes</th>
                  </tr>
                </thead>
                <tbody>
                  <tr><td>Previous Funds</td><td>{fmtMoney(PHP.totals.previous_funds)}</td><td>Carry-over balance</td></tr>
                  <tr><td>Student Collections</td><td>{fmtMoney(PHP.totals.student_collected)}</td><td>Paid student membership fees</td></tr>
                  <tr><td>SAS Fund Releases</td><td>{fmtMoney(PHP.totals.released_from_sas)}</td><td>Funds released by SAS Admin</td></tr>
                  <tr><td>Approved Remittances</td><td>{fmtMoney(PHP.totals.approved_remitted)}</td><td>Transferred back to SAS Admin</td></tr>
                  <tr><td>Recorded Expenses</td><td>{fmtMoney(PHP.totals.entity_expenses)}</td><td>Entity expenses in financial records</td></tr>
                  <tr>
                    <td><strong>Available Balance</strong></td>
                    <td style={{ fontWeight: 800, color: Number(PHP.totals.available_balance) >= 0 ? '#15803d' : '#dc2626' }}>
                      {fmtMoney(PHP.totals.available_balance)}
                    </td>
                    <td>Current working balance</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </>
        )}

        {PHP.section === 'remit' && (
          <>
            <div className="summary-row">
              <SummaryCard label="Total Collected" value={fmtMoney(PHP.totals.student_collected)} color="green" />
              <SummaryCard label="Already Remitted" value={fmtMoney(PHP.totals.approved_remitted)} color="blue" />
              <SummaryCard label="Available to Remit" value={fmtMoney(PHP.totals.available_to_remit)} color="navy" />
              <SummaryCard label="Pending Requests" value={PHP.totals.pending_count} color="red" />
            </div>

            <div className="detail-card">
              <h3><i className="fas fa-receipt"></i> Remittance History</h3>
              <table>
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Remarks</th>
                  </tr>
                </thead>
                <tbody>
                  {PHP.remits.length === 0 ? (
                    <tr><td colSpan="6" style={{ textAlign: 'center', color: '#94a3b8' }}>No remittance requests yet.</td></tr>
                  ) : PHP.remits.map((r, idx) => (
                    <tr key={r.id}>
                      <td style={{ color: '#94a3b8' }}>{idx + 1}</td>
                      <td>{fmtDate(r.request_date)}</td>
                      <td style={{ color: '#0b5d8b', fontWeight: 700 }}>{fmtMoney(r.amount)}</td>
                      <td>{r.description || '—'}</td>
                      <td><span style={statusPill(r.status)}>{r.status}</span></td>
                      <td>
                        {r.remarks ? (
                          <span style={{ color: '#64748b' }}>
                            {r.remarks}{r.reviewed_by_name ? ` • ${r.reviewed_by_name}` : ''}
                          </span>
                        ) : '—'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}

        {PHP.section === 'financial' && (
          <>
            <div className="detail-card">
              <h3><i className="fas fa-hand-holding-usd"></i> SAS Fund Releases</h3>
              <table>
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Purpose</th>
                    <th>Released By</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {PHP.fundReleases.length === 0 ? (
                    <tr><td colSpan="5" style={{ textAlign: 'center', color: '#94a3b8' }}>No released funds yet.</td></tr>
                  ) : PHP.fundReleases.map((r) => (
                    <tr key={r.id}>
                      <td>{fmtDate(r.release_date)}</td>
                      <td style={{ color: '#0b5d8b', fontWeight: 700 }}>{fmtMoney(r.amount)}</td>
                      <td>{r.purpose || '—'}</td>
                      <td>{r.released_by_name || 'SAS Admin'}</td>
                      <td><span style={statusPill(r.status)}>{r.status}</span></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="detail-card">
              <h3><i className="fas fa-wallet"></i> Financial Records</h3>
              <table>
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th>Amount</th>
                  </tr>
                </thead>
                <tbody>
                  {PHP.financialRecords.length === 0 ? (
                    <tr><td colSpan="4" style={{ textAlign: 'center', color: '#94a3b8' }}>No financial records yet.</td></tr>
                  ) : PHP.financialRecords.map((r) => {
                    const typeColor = r.type === 'Remit' ? '#1d4ed8' : (r.type === 'Expense' ? '#dc2626' : '#16a34a');
                    return (
                      <tr key={r.id}>
                        <td>{fmtDate(r.record_date)}</td>
                        <td style={{ color: typeColor, fontWeight: 700 }}>{r.type}</td>
                        <td>{r.description || '—'}</td>
                        <td style={{ color: typeColor, fontWeight: 700 }}>
                          {r.type === 'Expense' ? '-' : '+'} {fmtMoney(r.amount)}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </>
        )}

        {PHP.section === 'transactions' && (
          <div className="detail-card">
            <h3><i className="fas fa-list"></i> Transaction Log</h3>
            <table>
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Type</th>
                  <th>Amount</th>
                  <th>Source</th>
                  <th>Description</th>
                </tr>
              </thead>
              <tbody>
                {PHP.transactions.length === 0 ? (
                  <tr><td colSpan="5" style={{ textAlign: 'center', color: '#94a3b8' }}>No transactions yet.</td></tr>
                ) : PHP.transactions.map((t) => {
                  const color = t.type === 'Income' ? '#16a34a' : '#dc2626';
                  return (
                    <tr key={t.id}>
                      <td>{fmtDate(t.tx_date)}</td>
                      <td style={{ color, fontWeight: 700 }}>{t.type}</td>
                      <td style={{ color, fontWeight: 700 }}>{t.type === 'Income' ? '+' : '-'} {fmtMoney(t.amount)}</td>
                      <td style={{ textTransform: 'capitalize' }}>{(t.source || 'manual').replace('_', ' ')}</td>
                      <td>{t.description || '—'}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        {PHP.section === 'students' && (
          <div className="detail-card">
            <h3><i className="fas fa-users"></i> Students Under {PHP.entity.short_name || PHP.entity.name}</h3>
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>Student ID</th>
                  <th>Name</th>
                  <th>Course</th>
                  <th>Year</th>
                  <th>Amount</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {PHP.students.length === 0 ? (
                  <tr><td colSpan="8" style={{ textAlign: 'center', color: '#94a3b8' }}>No students assigned yet.</td></tr>
                ) : PHP.students.map((s, idx) => (
                  <tr key={s.id}>
                    <td style={{ color: '#94a3b8' }}>{idx + 1}</td>
                    <td>{s.student_id}</td>
                    <td><strong>{s.full_name}</strong></td>
                    <td>{s.course}</td>
                    <td>{s.year_level}</td>
                    <td style={{ color: '#0b5d8b', fontWeight: 700 }}>{fmtMoney(s.amount_due)}</td>
                    <td><span style={statusPill(s.payment_status)}>{s.payment_status}</span></td>
                    <td>
                      <form
                        method="POST"
                        style={{ display: 'inline' }}
                        onSubmit={(e) => {
                          if (!window.confirm(`Remove ${s.full_name} from the student list?`)) {
                            e.preventDefault();
                          }
                        }}
                      >
                        <input type="hidden" name="action" value="delete_student" />
                        <input type="hidden" name="student_row_id" value={s.id} />
                        <button
                          type="submit"
                          style={{
                            background: '#fee2e2',
                            color: '#b91c1c',
                            border: '1px solid #fecaca',
                            borderRadius: 8,
                            padding: '4px 10px',
                            fontSize: 11,
                            fontWeight: 700,
                            cursor: 'pointer',
                          }}
                        >
                          <i className="fas fa-trash"></i> Delete
                        </button>
                      </form>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </main>

      <button className="toggle" onClick={() => setSidebarOpen(!sidebarOpen)}>☰</button>
      <TreasurerInbox />

      {remitPopupOpen && (
        <div className="popup-overlay" style={{ display: 'flex' }} onClick={(e) => { if (e.target === e.currentTarget) setRemitPopupOpen(false); }}>
          <div className="popup-card">
            <div className="popup-header">
              <h2>Submit Remittance</h2>
              <button className="popup-close" onClick={() => setRemitPopupOpen(false)}>
                <i className="fas fa-times"></i>
              </button>
            </div>
            <form method="POST" style={{ padding: '16px 22px 8px' }}>
              <input type="hidden" name="action" value="request_remit" />
              <label className="field-label">Available to Remit</label>
              <div style={{ marginBottom: 12, fontWeight: 800, color: '#0b5d8b' }}>{fmtMoney(PHP.totals.available_to_remit)}</div>
              <label className="field-label">Amount (₱)</label>
              <input
                type="number"
                name="remit_amount"
                min="0.01"
                max={Number(PHP.totals.available_to_remit)}
                step="0.01"
                required
                disabled={Number(PHP.totals.available_to_remit) <= 0}
              />
              <label className="field-label">Description</label>
              <input type="text" name="remit_description" placeholder="Membership fee collection" />
              <div style={{ fontSize: 11, color: '#64748b', marginBottom: 8 }}>
                Note: request goes to SAS Admin for approval.
              </div>
              <div className="popup-buttons" style={{ padding: '0', borderTop: 'none' }}>
                <button className="save-btn" type="submit" disabled={Number(PHP.totals.available_to_remit) <= 0}>
                  <i className="fas fa-paper-plane"></i> Submit
                </button>
                <button className="cancel-btn" type="button" onClick={() => setRemitPopupOpen(false)}>Cancel</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {studentPopupOpen && (
        <div className="popup-overlay" style={{ display: 'flex' }} onClick={(e) => { if (e.target === e.currentTarget) setStudentPopupOpen(false); }}>
          <div className="popup-card">
            <div className="popup-header">
              <h2>Add Student</h2>
              <button className="popup-close" onClick={() => setStudentPopupOpen(false)}>
                <i className="fas fa-times"></i>
              </button>
            </div>
            <form method="POST" style={{ padding: '16px 22px 8px' }}>
              <input type="hidden" name="action" value="add_student" />
              <label className="field-label">Student ID</label>
              <input type="text" name="student_id" required placeholder="2026-00001" />
              <label className="field-label">Full Name</label>
              <input type="text" name="full_name" required placeholder="Dela Cruz, Juan" />
              <label className="field-label">Course</label>
              <input type="text" name="course" required placeholder="BSIT" />
              <label className="field-label">Year Level</label>
              <select
                name="year_level"
                required
                defaultValue="1st Year"
                style={{
                  width: '100%',
                  padding: '10px 13px',
                  marginBottom: 12,
                  border: '1px solid #e2e8f0',
                  borderRadius: 10,
                  fontSize: 13,
                  color: '#0f172a',
                  fontFamily: 'Inter, sans-serif',
                  background: 'white',
                }}
              >
                <option value="1st Year">1st Year</option>
                <option value="2nd Year">2nd Year</option>
                <option value="3rd Year">3rd Year</option>
                <option value="4th Year">4th Year</option>
              </select>
              <label className="field-label">Amount Due (₱)</label>
              <input
                type="number"
                name="amount_due"
                min="0.01"
                step="0.01"
                defaultValue={PHP.students.length > 0 ? Number(PHP.students[0].amount_due || 20) : 20}
                required
              />
              <label className="field-label">Payment Status</label>
              <select
                name="payment_status"
                defaultValue="Unpaid"
                style={{
                  width: '100%',
                  padding: '10px 13px',
                  marginBottom: 12,
                  border: '1px solid #e2e8f0',
                  borderRadius: 10,
                  fontSize: 13,
                  color: '#0f172a',
                  fontFamily: 'Inter, sans-serif',
                  background: 'white',
                }}
              >
                <option value="Unpaid">Unpaid</option>
                <option value="Paid">Paid</option>
              </select>
              <div style={{ fontSize: 11, color: '#64748b', marginBottom: 8 }}>
                This student will be linked to {PHP.entity.short_name || PHP.entity.name}.
              </div>
              <div className="popup-buttons" style={{ padding: '0', borderTop: 'none' }}>
                <button className="save-btn" type="submit">
                  <i className="fas fa-user-plus"></i> Save Student
                </button>
                <button className="cancel-btn" type="button" onClick={() => setStudentPopupOpen(false)}>Cancel</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App />);
</script>
</body>
</html>


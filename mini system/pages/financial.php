<?php
require_once __DIR__ . '/auth.php';
$user = requireAdmin(); $pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM financial_records WHERE id=:i")->execute([':i'=>(int)($_POST['id']??0)]);
    }
    header('Location: financial.php'); exit;
}

// ── CORRECT TALLIES FROM ALL TABLES ──────────────────────────────────────────
// Total remitted by treasurers to SAS
$totalRemitted  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM financial_records WHERE type='Remit'")->fetchColumn();
// Total released by SAS to orgs/clubs
$totalReleased  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM fund_releases WHERE status='Released'")->fetchColumn();
// Total student collections across all entities
$totalCollected = (float)$pdo->query("SELECT COALESCE(SUM(amount_due),0) FROM students WHERE payment_status='Paid'")->fetchColumn();
// Total previous funds across all entities (after deductions from releases)
$totalPrevious  = (float)$pdo->query("SELECT COALESCE(SUM(previous_funds),0) FROM entity_funds")->fetchColumn();
// Total fund = current previous funds (already deducted) + all student collections - all remitted
$totalFund = $totalPrevious + $totalCollected - $totalRemitted;

// Per-entity summary using JOIN
$entitySummary = $pdo->query(
    "SELECT e.id, e.name, e.short_name, e.category,
            COALESCE(SUM(CASE WHEN s.payment_status='Paid' THEN s.amount_due ELSE 0 END),0) AS student_fees,
            COALESCE((SELECT SUM(fr.amount) FROM financial_records fr WHERE fr.entity_id=e.id AND fr.type='Remit'),0) AS remitted,
            COALESCE((SELECT SUM(frl.amount) FROM fund_releases frl WHERE frl.entity_id=e.id AND frl.status='Released'),0) AS released,
            COALESCE(ef.previous_funds,0) AS previous_funds
     FROM entities e
     LEFT JOIN students s ON s.entity_id=e.id
     LEFT JOIN entity_funds ef ON ef.entity_id=e.id
     WHERE e.is_active=1
     GROUP BY e.id, e.name, e.short_name, e.category, ef.previous_funds
     ORDER BY e.category, e.name"
)->fetchAll(PDO::FETCH_ASSOC);

// Financial records log
$records = $pdo->query(
    "SELECT f.*, e.name AS entity, e.category
     FROM financial_records f
     LEFT JOIN entities e ON e.id=f.entity_id
     ORDER BY f.record_date DESC, f.id DESC"
)->fetchAll();

// Fund releases log
$releases = [];
try {
    $releases = $pdo->query(
        "SELECT frl.*, e.name AS entity, u.full_name AS released_by_name
         FROM fund_releases frl
         LEFT JOIN entities e ON e.id=frl.entity_id
         LEFT JOIN users u ON u.id=frl.released_by
         ORDER BY frl.release_date DESC"
    )->fetchAll();
} catch (PDOException $ex) { $releases = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>E-CFunds — Financial</title>
<link rel="stylesheet" href="/mini%20system/CSS/Financial.css">
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
  records:       <?= json_encode($records) ?>,
  releases:      <?= json_encode($releases) ?>,
  entitySummary: <?= json_encode($entitySummary) ?>,
  totalRemitted:  <?= $totalRemitted ?>,
  totalReleased:  <?= $totalReleased ?>,
  totalCollected: <?= $totalCollected ?>,
  totalPrevious:  <?= $totalPrevious ?>,
  totalFund:      <?= $totalFund ?>,
  user: <?= json_encode(['fullName'=>$user['fullName']]) ?>
};
</script>
<script type="text/babel">
const { useState, useMemo } = React;
const fmt = v => '₱' + Number(v).toLocaleString('en-PH',{minimumFractionDigits:0});

function App() {
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const [tab, setTab] = useState('summary');
  const [q,   setQ]   = useState('');

  const links = [
    {href:'dashboard.php',icon:'fa-chart-line',label:'SAS Dashboard'},
    {href:'organization.php',icon:'fa-building',label:'Organizations'},
    {href:'clubs.php',icon:'fa-user-graduate',label:'Clubs'},
    {href:'students.php',icon:'fa-id-card',label:'Students'},
    {href:'accredited.php',icon:'fa-certificate',label:'Accredited List'},
    {href:'financial.php',icon:'fa-wallet',label:'Financial'},
    {href:'transaction.php',icon:'fa-receipt',label:'Transactions'},
  ];

  const filteredRecords = useMemo(() => PHP.records.filter(r =>
    !q || (r.entity||'').toLowerCase().includes(q.toLowerCase()) || (r.description||'').toLowerCase().includes(q.toLowerCase())
  ), [q]);

  const filteredReleases = useMemo(() => PHP.releases.filter(r =>
    !q || (r.entity||'').toLowerCase().includes(q.toLowerCase()) || (r.purpose||'').toLowerCase().includes(q.toLowerCase())
  ), [q]);

  return (
    <div className="container">
      <aside className={`sidebar${sidebarOpen?'':' hidden'}`}>
        <div>
          <div className="logo"><img src="/mini%20system/IMG/E-CFUNDS logo.png" alt="Logo"/><h2>E-CFund's <br/><span>Monitoring System</span></h2></div>
          <nav>{links.map(l=><a key={l.href} href={l.href} className={l.href==='financial.php'?'nav-active':''}><i className={`fas ${l.icon}`}></i> {l.label}</a>)}</nav>
        </div>
        <div className="user"><p><strong>{PHP.user.fullName}</strong></p><small>SAS Admin</small><button className="logout" onClick={()=>window.location.href='logout.php'}>⟲ Log out</button></div>
      </aside>

      <main className="main">
        <div className="page-header">
          <div className="page-header-left">
            <h1><i className="fas fa-wallet"></i> Financial Management</h1>
            <p>Fund summary — Remittances & Releases per Organization / Club</p>
          </div>
        </div>

        {/* SAS TOTALS */}
        <div className="stats-row">
          <div className="stat-pill green"><i className="fas fa-coins"></i><div><span className="pill-label">Total Student Collections</span><strong>{fmt(PHP.totalCollected)}</strong></div></div>
          <div className="stat-pill blue"><i className="fas fa-paper-plane"></i><div><span className="pill-label">Total Remitted to SAS</span><strong>{fmt(PHP.totalRemitted)}</strong></div></div>
          <div className="stat-pill yellow"><i className="fas fa-hand-holding-usd"></i><div><span className="pill-label">Total Released by SAS</span><strong>{fmt(PHP.totalReleased)}</strong></div></div>
          <div className="stat-pill" style={{background:'#f0fdf4',border:'1px solid #bbf7d0'}}><i className="fas fa-piggy-bank" style={{background:'#dcfce7',color:'#16a34a',borderRadius:12,padding:9,fontSize:20,flexShrink:0}}></i><div><span className="pill-label">Total Fund (All Orgs &amp; Clubs)</span><strong style={{color:'#16a34a'}}>{fmt(PHP.totalFund)}</strong></div></div>
        </div>

        {/* TABS */}
        <div style={{display:'flex',gap:6,marginBottom:16,borderBottom:'2px solid #e2e8f0',paddingBottom:0}}>
          {[['summary','fa-table','Fund Summary'],['remit','fa-paper-plane','Remittances'],['release','fa-hand-holding-usd','Fund Releases']].map(([key,icon,label])=>(
            <button key={key} onClick={()=>setTab(key)}
              style={{padding:'8px 16px',border:'none',background:'none',fontWeight:600,fontSize:13,cursor:'pointer',
                color:tab===key?'#0b5d8b':'#94a3b8',borderBottom:tab===key?'3px solid #0b5d8b':'3px solid transparent',
                marginBottom:-2,fontFamily:'inherit',display:'flex',alignItems:'center',gap:6}}>
              <i className={`fas ${icon}`}></i> {label}
            </button>
          ))}
        </div>

        {/* SEARCH */}
        <div className="toolbar" style={{marginBottom:14}}>
          <div className="search-box"><i className="fas fa-search"></i>
            <input type="text" placeholder="Search org/club, description..." value={q} onChange={e=>setQ(e.target.value)}/>
          </div>
        </div>

        {/* TAB: FUND SUMMARY */}
        {tab==='summary' && (
          <div className="table-card">
            <table>
              <thead><tr><th>Org / Club</th><th>Type</th><th>Previous Funds</th><th>Student Fees</th><th>Remitted to SAS</th><th>Released by SAS</th><th>Remaining</th></tr></thead>
              <tbody>
                {PHP.entitySummary.filter(e=>!q||(e.name||'').toLowerCase().includes(q.toLowerCase())).map(e=>{
                  const prev      = parseFloat(e.previous_funds) || 0;
                  const fees      = parseFloat(e.student_fees)   || 0;
                  const remitted  = parseFloat(e.remitted)       || 0;
                  const released  = parseFloat(e.released)       || 0;
                  // Remaining = previous + student fees - remitted (released already came from previous)
                  const remaining = prev + fees - remitted;
                  return (
                    <tr key={e.id}>
                      <td><strong>{e.name}</strong></td>
                      <td><span style={{background:e.category==='organization'?'#dbeafe':'#ede9fe',color:e.category==='organization'?'#1d4ed8':'#7c3aed',padding:'2px 8px',borderRadius:10,fontSize:11,fontWeight:700}}>{e.category==='organization'?'Org':'Club'}</span></td>
                      <td style={{color:'#0b5d8b',fontWeight:600}}>{fmt(prev)}</td>
                      <td style={{color:'#16a34a',fontWeight:600}}>{fmt(fees)}</td>
                      <td style={{color:'#dc2626',fontWeight:600}}>{fmt(remitted)}</td>
                      <td style={{color:'#0b5d8b',fontWeight:600}}>{fmt(released)}</td>
                      <td style={{fontWeight:700,color:remaining>=0?'#16a34a':'#dc2626'}}>{fmt(remaining)}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        {/* TAB: REMITTANCES */}
        {tab==='remit' && (
          <div className="table-card">
            <table>
              <thead><tr><th>#</th><th>Date</th><th>Org / Club</th><th>Type</th><th>Description</th><th>Amount</th><th>Actions</th></tr></thead>
              <tbody>
                {filteredRecords.length===0 ? (
                  <tr><td colSpan="7"><div className="empty-state"><i className="fas fa-folder-open"></i><p>No remittance records.</p></div></td></tr>
                ) : filteredRecords.map((r,i)=>{
                  const color = r.type==='Expense'?'#dc2626':'#16a34a';
                  return (
                    <tr key={r.id}>
                      <td style={{color:'#aaa',fontSize:11}}>{i+1}</td>
                      <td>{r.record_date}</td>
                      <td><strong>{r.entity||'—'}</strong></td>
                      <td><span className={`type-badge ${r.type.toLowerCase()}`}>{r.type}</span></td>
                      <td>{r.description||'—'}</td>
                      <td style={{color,fontWeight:700}}>{r.type==='Expense'?'-':'+'} {fmt(r.amount)}</td>
                      <td>
                        <form method="POST" style={{display:'inline'}}>
                          <input type="hidden" name="action" value="delete"/>
                          <input type="hidden" name="id" value={r.id}/>
                          <button className="btn-del" type="submit" onClick={e=>{if(!confirm('Delete?'))e.preventDefault()}}><i className="fas fa-trash"></i></button>
                        </form>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        {/* TAB: FUND RELEASES */}
        {tab==='release' && (
          <div className="table-card">
            <table>
              <thead><tr><th>#</th><th>Date</th><th>Org / Club</th><th>Purpose</th><th>Released By</th><th>Amount</th><th>Status</th></tr></thead>
              <tbody>
                {filteredReleases.length===0 ? (
                  <tr><td colSpan="7"><div className="empty-state"><i className="fas fa-folder-open"></i><p>No fund releases yet.</p></div></td></tr>
                ) : filteredReleases.map((r,i)=>(
                  <tr key={r.id}>
                    <td style={{color:'#aaa',fontSize:11}}>{i+1}</td>
                    <td>{r.release_date}</td>
                    <td><strong>{r.entity||'—'}</strong></td>
                    <td>{r.purpose||'—'}</td>
                    <td style={{color:'#64748b'}}>{r.released_by_name||'Admin'}</td>
                    <td style={{color:'#0b5d8b',fontWeight:700}}>+ {fmt(r.amount)}</td>
                    <td><span style={{background:'#dcfce7',color:'#16a34a',padding:'3px 10px',borderRadius:12,fontSize:11,fontWeight:700}}>Released</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

      </main>
      <button className="toggle" onClick={()=>setSidebarOpen(!sidebarOpen)}>☰</button>
    </div>
  );
}
ReactDOM.createRoot(document.getElementById('root')).render(<App/>);
</script>
</body>
</html>

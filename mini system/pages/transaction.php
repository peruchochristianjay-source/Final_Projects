<?php
require_once __DIR__ . '/auth.php';
$user = requireAdmin(); $pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $eid=(int)($_POST['entity_id']??0); $type=$_POST['type']??'Income'; $amt=(float)($_POST['amount']??0); $date=$_POST['date']??date('Y-m-d'); $desc=trim($_POST['desc']??'');
        if ($eid && $amt>0) $pdo->prepare("INSERT INTO transactions (entity_id,tx_date,type,description,amount,source,created_by) VALUES (:e,:d,:t,:ds,:a,'manual',:u)")->execute([':e'=>$eid,':d'=>$date,':t'=>$type,':ds'=>$desc?:null,':a'=>$amt,':u'=>$user['id']]);
    } elseif ($action === 'delete') { $pdo->prepare("DELETE FROM transactions WHERE id=:i")->execute([':i'=>(int)($_POST['id']??0)]); }
    header('Location: transaction.php'); exit;
}
// UNION: transactions + financial_records + fund_releases — full activity log
$txns = $pdo->query(
    "SELECT t.id, t.tx_date, e.name AS entity, e.category,
            t.type, t.amount, t.description, t.source,
            u.full_name AS created_by_name
     FROM transactions t
     LEFT JOIN entities e ON e.id = t.entity_id
     LEFT JOIN users    u ON u.id = t.created_by
     ORDER BY t.tx_date DESC, t.id DESC"
)->fetchAll();
$entities = $pdo->query("SELECT id, name, category FROM entities WHERE is_active=1 ORDER BY category, name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>E-CFunds — Transactions</title>
<link rel="stylesheet" href="/mini%20system/CSS/Transaction.css">
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
  txns:     <?= json_encode($txns) ?>,
  entities: <?= json_encode($entities) ?>,
  today:    '<?= date('Y-m-d') ?>',
  user:     <?= json_encode(['fullName'=>$user['fullName']]) ?>
};
</script>
<script type="text/babel">
const { useState, useMemo } = React;
const fmt = v => '₱' + Number(v).toLocaleString('en-PH',{minimumFractionDigits:0});

const typeLabel = t => t.source === 'student' ? 'Receipt' : t.source === 'admin_release' ? 'Fund Release' : t.type === 'Income' ? 'Receipt' : 'Expense';
const typeColor = t => t.type === 'Income' ? '#16a34a' : '#dc2626';

function App() {
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const [q,      setQ]      = useState('');
  const [ft,     setFt]     = useState('');
  const [entity, setEntity] = useState('');
  const [popup,  setPopup]  = useState(false);

  const filtered = useMemo(() => PHP.txns.filter(t => {
    const matchQ  = !q      || (t.entity||'').toLowerCase().includes(q.toLowerCase()) || (t.description||'').toLowerCase().includes(q.toLowerCase());
    const matchFt = !ft     || t.type === ft;
    const matchEn = !entity || (t.entity||'') === entity;
    return matchQ && matchFt && matchEn;
  }), [q, ft, entity]);

  const receipt = filtered.filter(t=>t.type==='Income').reduce((a,t)=>a+parseFloat(t.amount),0);
  const expense = filtered.filter(t=>t.type==='Expense').reduce((a,t)=>a+parseFloat(t.amount),0);

  // Unique entity names for filter
  const entityNames = [...new Set(PHP.txns.map(t=>t.entity).filter(Boolean))].sort();

  const links = [
    {href:'dashboard.php',icon:'fa-chart-line',label:'SAS Dashboard'},
    {href:'organization.php',icon:'fa-building',label:'Organizations'},
    {href:'clubs.php',icon:'fa-user-graduate',label:'Clubs'},
    {href:'students.php',icon:'fa-id-card',label:'Students'},
    {href:'accredited.php',icon:'fa-certificate',label:'Accredited List'},
    {href:'financial.php',icon:'fa-wallet',label:'Financial'},
    {href:'transaction.php',icon:'fa-receipt',label:'Transactions'},
  ];

  return (
    <div className="container">
      <aside className={`sidebar${sidebarOpen?'':' hidden'}`}>
        <div>
          <div className="logo"><img src="/mini%20system/IMG/E-CFUNDS logo.png" alt="Logo"/><h2>E-CFund's <br/><span>Monitoring System</span></h2></div>
          <nav>{links.map(l=><a key={l.href} href={l.href} className={l.href==='transaction.php'?'nav-active':''}><i className={`fas ${l.icon}`}></i> {l.label}</a>)}</nav>
        </div>
        <div className="user"><p><strong>{PHP.user.fullName}</strong></p><small>SAS Admin</small><button className="logout" onClick={()=>window.location.href='logout.php'}>⟲ Log out</button></div>
      </aside>

      <main className="main">
        <div className="page-header">
          <div><h1><i className="fas fa-receipt"></i> Transactions</h1><p>Complete transaction log per Organization / Club</p></div>
        </div>

        <div className="stats-row">
          <div className="stat-pill blue"><i className="fas fa-list-alt"></i><div><span className="pill-label">Total Transactions</span><strong>{filtered.length}</strong></div></div>
          <div className="stat-pill green"><i className="fas fa-arrow-down"></i><div><span className="pill-label">Total Receipt</span><strong>{fmt(receipt)}</strong></div></div>
          <div className="stat-pill red"><i className="fas fa-arrow-up"></i><div><span className="pill-label">Total Expense</span><strong>{fmt(expense)}</strong></div></div>
          <div className="stat-pill yellow"><i className="fas fa-balance-scale"></i><div><span className="pill-label">Net Balance</span><strong>{fmt(receipt-expense)}</strong></div></div>
        </div>

        <div className="toolbar">
          <div className="search-box"><i className="fas fa-search"></i><input type="text" placeholder="Search description..." value={q} onChange={e=>setQ(e.target.value)}/></div>
          <select value={entity} onChange={e=>setEntity(e.target.value)}>
            <option value="">All Orgs / Clubs</option>
            {entityNames.map(n=><option key={n} value={n}>{n}</option>)}
          </select>
          <select value={ft} onChange={e=>setFt(e.target.value)}>
            <option value="">All Types</option>
            <option value="Income">Receipt</option>
            <option value="Expense">Expense</option>
          </select>
        </div>

        <div className="table-card">
          <table>
            <thead><tr><th>#</th><th>Date</th><th>Org / Club</th><th>Type</th><th>Description</th><th>Amount</th><th>Actions</th></tr></thead>
            <tbody>
              {filtered.length===0 ? (
                <tr><td colSpan="7"><div className="empty-state"><i className="fas fa-folder-open"></i><p>No transactions found.</p></div></td></tr>
              ) : filtered.map((t,i) => {
                const color = typeColor(t);
                const label = typeLabel(t);
                return (
                  <tr key={t.id}>
                    <td style={{color:'#aaa',fontSize:11}}>{i+1}</td>
                    <td>{t.tx_date}</td>
                    <td><strong>{t.entity||'—'}</strong></td>
                    <td><span style={{display:'inline-block',padding:'3px 10px',borderRadius:12,fontSize:11,fontWeight:700,background:t.type==='Income'?'#dcfce7':'#fee2e2',color}}>{label}</span></td>
                    <td>{t.description||'—'}</td>
                    <td style={{color,fontWeight:700}}>{t.type==='Income'?'+':'-'} {fmt(t.amount)}</td>
                    <td>
                      <form method="POST" style={{display:'inline'}}>
                        <input type="hidden" name="action" value="delete"/>
                        <input type="hidden" name="id" value={t.id}/>
                        <button className="btn-del" type="submit" onClick={e=>{if(!confirm('Delete?'))e.preventDefault()}}><i className="fas fa-trash"></i></button>
                      </form>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </main>

      {popup && (
        <div className="popup-overlay" style={{display:'flex'}} onClick={e=>{if(e.target===e.currentTarget)setPopup(false)}}>
          <div className="popup-card" style={{width:500,maxWidth:'95vw'}}>
            <div className="popup-header"><h2>Add Transaction</h2><button className="popup-close" onClick={()=>setPopup(false)}><i className="fas fa-times"></i></button></div>
            <form method="POST"><input type="hidden" name="action" value="add"/>
              <div className="form-grid">
                <div className="form-group"><label>Date *</label><input type="date" name="date" defaultValue={PHP.today}/></div>
                <div className="form-group"><label>Type *</label><select name="type"><option value="Income">Receipt</option><option value="Expense">Expense</option></select></div>
                <div className="form-group full"><label>Org / Club *</label><select name="entity_id"><option value="">Select</option>{PHP.entities.map(e=><option key={e.id} value={e.id}>{e.name}</option>)}</select></div>
                <div className="form-group full"><label>Amount (₱) *</label><input type="number" name="amount" min="0" step="0.01" placeholder="0"/></div>
                <div className="form-group full"><label>Description</label><input type="text" name="desc" placeholder="Brief description"/></div>
              </div>
              <div className="popup-buttons"><button className="save-btn" type="submit"><i className="fas fa-save"></i> Save</button><button className="cancel-btn" type="button" onClick={()=>setPopup(false)}>Cancel</button></div>
            </form>
          </div>
        </div>
      )}

      <button className="toggle" onClick={()=>setSidebarOpen(!sidebarOpen)}>☰</button>
    </div>
  );
}
ReactDOM.createRoot(document.getElementById('root')).render(<App/>);
</script>
</body>
</html>

<?php
require_once __DIR__ . '/auth.php';
$user = requireAdmin(); $pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action=$_POST['action']??'';
    if ($action==='add'||$action==='edit') {
        $name=$_POST['name']??''; $type=$_POST['type']??''; $status=$_POST['status']??'Pending';
        $adviser=trim($_POST['adviser']??''); $year=trim($_POST['year']??''); $date=$_POST['date']??null; $expiry=$_POST['expiry']??null; $remarks=trim($_POST['remarks']??'');
        if ($name&&$type) {
            if ($action==='add') {
                $pdo->prepare("INSERT INTO accredited_entries (name,entry_type,adviser,academic_year,accredited_date,expiry_date,status,remarks) VALUES (:n,:t,:a,:y,:d,:e,:s,:r)")->execute([':n'=>$name,':t'=>$type,':a'=>$adviser?:null,':y'=>$year?:null,':d'=>$date?:null,':e'=>$expiry?:null,':s'=>$status,':r'=>$remarks?:null]);
            } else {
                $id=(int)($_POST['id']??0);
                $pdo->prepare("INSERT INTO accredited_history (accredited_entry_id,action,payload) VALUES (:i,'UPDATED',:p)")->execute([':i'=>$id,':p'=>json_encode(['id'=>$id,'name'=>$name])]);
                $pdo->prepare("UPDATE accredited_entries SET name=:n,entry_type=:t,adviser=:a,academic_year=:y,accredited_date=:d,expiry_date=:e,status=:s,remarks=:r,updated_at=NOW() WHERE id=:i")->execute([':n'=>$name,':t'=>$type,':a'=>$adviser?:null,':y'=>$year?:null,':d'=>$date?:null,':e'=>$expiry?:null,':s'=>$status,':r'=>$remarks?:null,':i'=>$id]);
            }
        }
    } elseif ($action==='delete') {
        $id=(int)($_POST['id']??0);
        $row=$pdo->prepare("SELECT * FROM accredited_entries WHERE id=:i LIMIT 1"); $row->execute([':i'=>$id]); $existing=$row->fetch();
        if ($existing) { $pdo->prepare("INSERT INTO accredited_history (accredited_entry_id,action,payload) VALUES (:i,'DELETED',:p)")->execute([':i'=>$id,':p'=>json_encode($existing)]); $pdo->prepare("DELETE FROM accredited_entries WHERE id=:i")->execute([':i'=>$id]); }
    }
    header('Location: accredited.php'); exit;
}
$entries = $pdo->query("SELECT * FROM accredited_entries ORDER BY updated_at DESC")->fetchAll();
$history = $pdo->query("SELECT h.*,e.name AS current_name FROM accredited_history h LEFT JOIN accredited_entries e ON e.id=h.accredited_entry_id ORDER BY h.created_at DESC")->fetchAll();
$editEntry = null;
if (isset($_GET['edit'])) { $s=$pdo->prepare("SELECT * FROM accredited_entries WHERE id=:i LIMIT 1"); $s->execute([':i'=>(int)$_GET['edit']]); $editEntry=$s->fetch(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>E-CFunds — Accredited List</title>
<link rel="stylesheet" href="/mini%20system/CSS/accredited.css">
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
  entries:   <?= json_encode($entries) ?>,
  history:   <?= json_encode($history) ?>,
  editEntry: <?= json_encode($editEntry) ?>,
  user:      <?= json_encode(['fullName'=>$user['fullName']]) ?>
};
</script>
<script type="text/babel">
const { useState, useMemo } = React;

function App() {
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const [tab,    setTab]    = useState('current');
  const [q,      setQ]      = useState('');
  const [fc,     setFc]     = useState('');
  const [fs,     setFs]     = useState('');
  const [addPopup,  setAddPopup]  = useState(false);
  const [editPopup, setEditPopup] = useState(!!PHP.editEntry);
  const [editData,  setEditData]  = useState(PHP.editEntry || {});

  const filtered = useMemo(() => PHP.entries.filter(e => {
    const matchQ  = !q  || e.name.toLowerCase().includes(q.toLowerCase()) || (e.adviser||'').toLowerCase().includes(q.toLowerCase()) || (e.academic_year||'').includes(q);
    const matchFc = !fc || e.entry_type === fc;
    const matchFs = !fs || e.status === fs;
    return matchQ && matchFc && matchFs;
  }), [q, fc, fs]);

  const acc  = filtered.filter(e=>e.status==='Accredited').length;
  const pend = filtered.filter(e=>e.status==='Pending').length;
  const exp  = filtered.filter(e=>e.status==='Expired').length;

  const links = [
    {href:'dashboard.php',icon:'fa-chart-line',label:'SAS Dashboard'},
    {href:'organization.php',icon:'fa-building',label:'Organizations'},
    {href:'clubs.php',icon:'fa-user-graduate',label:'Clubs'},
    {href:'students.php',icon:'fa-id-card',label:'Students'},
    {href:'accredited.php',icon:'fa-certificate',label:'Accredited List'},
    {href:'financial.php',icon:'fa-wallet',label:'Financial'},
    {href:'transaction.php',icon:'fa-receipt',label:'Transactions'},
  ];

  const EntryForm = ({ action, data={}, id=null }) => (
    <form method="POST">
      <input type="hidden" name="action" value={action}/>
      {id && <input type="hidden" name="id" value={id}/>}
      <div className="form-grid">
        <div className="form-group full"><label>Name *</label><input type="text" name="name" defaultValue={data.name||''} placeholder="Organization or Club name" required/></div>
        <div className="form-group"><label>Category *</label>
          <select name="type" defaultValue={data.entry_type||''}>
            <option value="">Select category</option>
            <option value="Organization">Organization</option>
            <option value="Club">Club</option>
          </select>
        </div>
        <div className="form-group"><label>Status *</label>
          <select name="status" defaultValue={data.status||'Accredited'}>
            <option value="Accredited">Accredited</option>
            <option value="Pending">Pending</option>
            <option value="Expired">Expired</option>
          </select>
        </div>
        <div className="form-group"><label>Adviser</label><input type="text" name="adviser" defaultValue={data.adviser||''} placeholder="Adviser name"/></div>
        <div className="form-group"><label>Academic Year</label><input type="text" name="year" defaultValue={data.academic_year||''} placeholder="e.g. 2024-2025"/></div>
        <div className="form-group"><label>Date Accredited</label><input type="date" name="date" defaultValue={data.accredited_date||''}/></div>
        <div className="form-group"><label>Expiry Date</label><input type="date" name="expiry" defaultValue={data.expiry_date||''}/></div>
        <div className="form-group full"><label>Remarks</label><input type="text" name="remarks" defaultValue={data.remarks||''} placeholder="Optional notes"/></div>
      </div>
      <div className="popup-buttons">
        <button className="save-btn" type="submit"><i className="fas fa-save"></i> Save</button>
        <button className="cancel-btn" type="button" onClick={()=>{ setAddPopup(false); setEditPopup(false); window.history.replaceState({},'','/mini%20system/pages/accredited.php'); }}>Cancel</button>
      </div>
    </form>
  );

  return (
    <div className="container">
      <aside className={`sidebar${sidebarOpen?'':' hidden'}`}>
        <div>
          <div className="logo"><img src="/mini%20system/IMG/E-CFUNDS logo.png" alt="Logo"/><h2>E-CFund's <br/><span>Monitoring System</span></h2></div>
          <nav>{links.map(l=><a key={l.href} href={l.href} className={l.href==='accredited.php'?'nav-active':''}><i className={`fas ${l.icon}`}></i> {l.label}</a>)}</nav>
        </div>
        <div className="user"><p><strong>{PHP.user.fullName}</strong></p><small>SAS Admin</small><button className="logout" onClick={()=>window.location.href='logout.php'}>⟲ Log out</button></div>
      </aside>

      <main className="main">
        <div className="page-header">
          <div className="page-header-left"><h1><i className="fas fa-certificate"></i> Accredited List</h1><p>SAS-Anchored Organizations & Clubs</p></div>
          <button className="add-btn" onClick={()=>setAddPopup(true)}><i className="fas fa-plus"></i> Add Entry</button>
        </div>

        <div className="stats-row">
          <div className="stat-pill blue"><i className="fas fa-layer-group"></i><div><span className="pill-label">Total</span><strong>{filtered.length}</strong></div></div>
          <div className="stat-pill green"><i className="fas fa-check-circle"></i><div><span className="pill-label">Accredited</span><strong>{acc}</strong></div></div>
          <div className="stat-pill yellow"><i className="fas fa-clock"></i><div><span className="pill-label">Pending</span><strong>{pend}</strong></div></div>
          <div className="stat-pill red"><i className="fas fa-times-circle"></i><div><span className="pill-label">Expired</span><strong>{exp}</strong></div></div>
        </div>

        <div className="tab-bar">
          <button className={`tab-btn${tab==='current'?' active':''}`} onClick={()=>setTab('current')}><i className="fas fa-list"></i> Current Accredited</button>
          <button className={`tab-btn${tab==='history'?' active':''}`} onClick={()=>setTab('history')}><i className="fas fa-history"></i> History / Archive</button>
        </div>

        {tab==='current' ? (
          <>
            <div className="toolbar">
              <div className="search-box"><i className="fas fa-search"></i><input type="text" placeholder="Search name, adviser, year..." value={q} onChange={e=>setQ(e.target.value)}/></div>
              <select value={fc} onChange={e=>setFc(e.target.value)}><option value="">All Categories</option><option value="Organization">Organizations</option><option value="Club">Clubs</option></select>
              <select value={fs} onChange={e=>setFs(e.target.value)}><option value="">All Status</option><option value="Accredited">Accredited</option><option value="Pending">Pending</option><option value="Expired">Expired</option></select>
            </div>
            <div className="table-card"><table>
              <thead><tr><th>#</th><th>Name</th><th>Category</th><th>Adviser</th><th>Year</th><th>Date Accredited</th><th>Expiry</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody>
                {filtered.length===0 ? (
                  <tr><td colSpan="9"><div className="empty-state"><i className="fas fa-folder-open"></i><p>No entries found.</p></div></td></tr>
                ) : filtered.map((e,i) => (
                  <tr key={e.id}>
                    <td style={{color:'#aaa',fontSize:11}}>{i+1}</td>
                    <td><strong>{e.name}</strong></td>
                    <td>{e.entry_type}</td>
                    <td>{e.adviser||'—'}</td>
                    <td>{e.academic_year||'—'}</td>
                    <td>{e.accredited_date||'—'}</td>
                    <td>{e.expiry_date||'—'}</td>
                    <td><span className={`status-badge ${e.status.toLowerCase()}`}>{e.status}</span></td>
                    <td><div className="action-btns">
                      <button className="btn-edit" onClick={()=>{ setEditData(e); setEditPopup(true); }}><i className="fas fa-pen"></i></button>
                      <form method="POST" style={{display:'inline'}}>
                        <input type="hidden" name="action" value="delete"/>
                        <input type="hidden" name="id" value={e.id}/>
                        <button className="btn-del" type="submit" onClick={ev=>{if(!confirm('Delete?'))ev.preventDefault()}}><i className="fas fa-trash"></i></button>
                      </form>
                    </div></td>
                  </tr>
                ))}
              </tbody>
            </table></div>
          </>
        ) : (
          <>
            <div className="history-note"><i className="fas fa-info-circle"></i> Archive shows all entries that were updated or deleted.</div>
            <div className="table-card"><table>
              <thead><tr><th>#</th><th>Action</th><th>Name</th><th>Category</th><th>Status</th><th>Date</th></tr></thead>
              <tbody>
                {PHP.history.length===0 ? (
                  <tr><td colSpan="6"><div className="empty-state"><i className="fas fa-archive"></i><p>No history records yet.</p></div></td></tr>
                ) : PHP.history.map((h,i) => {
                  const p = typeof h.payload === 'string' ? JSON.parse(h.payload) : h.payload;
                  return (
                    <tr key={h.id}>
                      <td style={{color:'#aaa',fontSize:11}}>{i+1}</td>
                      <td><span className={`status-badge ${h.action==='DELETED'?'expired':'pending'}`}>{h.action}</span></td>
                      <td>{p.name||'—'}</td>
                      <td>{p.entry_type||'—'}</td>
                      <td>{p.status||'—'}</td>
                      <td style={{fontSize:11,color:'#888'}}>{h.created_at}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table></div>
          </>
        )}
      </main>

      {addPopup && (
        <div className="popup-overlay" style={{display:'flex'}} onClick={e=>{if(e.target===e.currentTarget)setAddPopup(false)}}>
          <div className="popup-card">
            <div className="popup-header"><h2>Add New Entry</h2><button className="popup-close" onClick={()=>setAddPopup(false)}><i className="fas fa-times"></i></button></div>
            <EntryForm action="add"/>
          </div>
        </div>
      )}

      {editPopup && (
        <div className="popup-overlay" style={{display:'flex'}} onClick={e=>{if(e.target===e.currentTarget)setEditPopup(false)}}>
          <div className="popup-card">
            <div className="popup-header"><h2>Edit Entry</h2><button className="popup-close" onClick={()=>setEditPopup(false)}><i className="fas fa-times"></i></button></div>
            <EntryForm action="edit" data={editData} id={editData.id}/>
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

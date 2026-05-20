<?php
require_once __DIR__ . '/auth.php';
$user = requireAdmin(); $pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'set_status') {
        $id=(int)($_POST['id']??0); $st=$_POST['status']??'Unpaid';
        $row=$pdo->prepare("SELECT * FROM students WHERE id=:i LIMIT 1"); $row->execute([':i'=>$id]); $stu=$row->fetch();
        if ($stu) {
            $pdo->prepare("UPDATE students SET payment_status=:s WHERE id=:i")->execute([':s'=>$st,':i'=>$id]);
            if ($st==='Paid' && $stu['payment_status']!=='Paid')
                $pdo->prepare("INSERT INTO transactions (entity_id,tx_date,type,description,amount,source,created_by) VALUES (:e,CURDATE(),'Income',:d,:a,'student',:u)")->execute([':e'=>$stu['entity_id'],':d'=>'Membership fee - '.$stu['full_name'],':a'=>$stu['amount_due'],':u'=>$user['id']]);
        }
    }
    header('Location: students'); exit;
}
$students = $pdo->query("SELECT s.*,e.name AS org FROM students s LEFT JOIN entities e ON e.id=s.entity_id ORDER BY s.created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>E-CFunds — Students</title>
<link rel="stylesheet" href="/mini%20system/CSS/students.css">
<link rel="stylesheet" href="/mini%20system/CSS/professional_ui.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
<script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
<script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
</head>
<body>
<div id="root"></div>
<script>const PHP = { students: <?= json_encode($students) ?> };</script>
<script type="text/babel">
const { useState, useMemo } = React;
const fmt = v => '₱' + Number(v).toLocaleString('en-PH', {minimumFractionDigits:0});

function GroupRow({ groupName, students }) {
  const [open, setOpen] = useState(false);
  const paid      = students.filter(s => s.payment_status === 'Paid');
  const unpaid    = students.filter(s => s.payment_status === 'Unpaid');
  const collected = paid.reduce((a, s) => a + parseFloat(s.amount_due), 0);

  return (
    <>
      <tr style={{background:'#f0f6fb',cursor:'pointer'}} onClick={() => setOpen(!open)}>
        <td colSpan="9" style={{padding:'10px 14px'}}>
          <div style={{display:'flex',alignItems:'center',justifyContent:'space-between'}}>
            <div style={{display:'flex',alignItems:'center',gap:10}}>
              <i className={`fas fa-chevron-${open?'down':'right'}`} style={{color:'#0b5d8b',fontSize:11}}></i>
              <strong style={{color:'#1d3557',fontSize:13}}>{groupName}</strong>
              <span style={{fontSize:11,color:'#888'}}>({students.length} students)</span>
            </div>
            <div style={{display:'flex',gap:16,fontSize:12}}>
              <span style={{color:'#16a34a',fontWeight:600}}>✔ Paid: {paid.length}</span>
              <span style={{color:'#dc2626',fontWeight:600}}>✘ Unpaid: {unpaid.length}</span>
              <span style={{color:'#0b5d8b',fontWeight:600}}>Collected: {fmt(collected)}</span>
            </div>
          </div>
        </td>
      </tr>
      {open && students.map((s, i) => (
        <tr key={s.id} style={{background:'#fafcff'}}>
          <td style={{color:'#aaa',fontSize:11,paddingLeft:32}}>{i+1}</td>
          <td style={{fontSize:12,color:'#555'}}>{s.student_id}</td>
          <td><div className="name-cell"><div className="name-avatar">{s.full_name[0].toUpperCase()}</div><span className="name-text">{s.full_name}</span></div></td>
          <td>{s.course}</td>
          <td>{s.year_level}</td>
          <td>{s.org || '—'}</td>
          <td>{fmt(s.amount_due)}</td>
          <td><span className={`pay-badge ${s.payment_status.toLowerCase()}`}>{s.payment_status}</span></td>
          <td>
            <form method="POST" style={{display:'inline'}}>
              <input type="hidden" name="action" value="set_status"/>
              <input type="hidden" name="id" value={s.id}/>
              <input type="hidden" name="status" value={s.payment_status==='Paid'?'Unpaid':'Paid'}/>
              <button className="btn-paid" type="submit">{s.payment_status==='Paid'?'Mark Unpaid':'Mark Paid'}</button>
            </form>
          </td>
        </tr>
      ))}
    </>
  );
}

function App() {
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const [q,    setQ]    = useState('');
  const [yr,   setYr]   = useState('');
  const [st,   setSt]   = useState('');
  const [view, setView] = useState('group'); // 'group' or 'flat'

  const filtered = useMemo(() => PHP.students.filter(s => {
    const matchQ  = !q  || s.full_name.toLowerCase().includes(q.toLowerCase()) || s.student_id.toLowerCase().includes(q.toLowerCase()) || s.course.toLowerCase().includes(q.toLowerCase());
    const matchYr = !yr || s.year_level === yr;
    const matchSt = !st || s.payment_status === st;
    return matchQ && matchYr && matchSt;
  }), [q, yr, st]);

  // Group by org
  const grouped = useMemo(() => {
    const map = {};
    filtered.forEach(s => {
      const key = s.org || 'Unassigned';
      if (!map[key]) map[key] = [];
      map[key].push(s);
    });
    return map;
  }, [filtered]);

  const paid      = filtered.filter(s => s.payment_status === 'Paid');
  const collected = paid.reduce((a, s) => a + parseFloat(s.amount_due), 0);

  const links = [
    {href:'dashboard',icon:'fa-chart-line',label:'SAS Dashboard'},
    {href:'organization',icon:'fa-building',label:'Organizations'},
    {href:'clubs',icon:'fa-user-graduate',label:'Clubs'},
    {href:'students',icon:'fa-id-card',label:'Students'},
    {href:'accredited',icon:'fa-certificate',label:'Accredited List'},
    {href:'financial',icon:'fa-wallet',label:'Financial'},
    {href:'transaction',icon:'fa-receipt',label:'Transactions'},
  ];

  return (
    <div className="container">
      <aside className={`sidebar${sidebarOpen?'':' hidden'}`}>
        <div>
          <div className="logo">
            <img src="/mini%20system/IMG/E-CFUNDS logo.png" alt="Logo"/>
            <h2>E-CFund's <br/><span>Monitoring System</span></h2>
          </div>
          <nav>
            {links.map(l => <a key={l.href} href={l.href} className={l.href==='students'?'nav-active':''}><i className={`fas ${l.icon}`}></i> {l.label}</a>)}
          </nav>
        </div>
        <div className="user">
          <p><strong><?= htmlspecialchars($user['fullName']) ?></strong></p>
          <small>SAS Admin</small>
          <button className="logout" onClick={() => window.location.href='logout'}>⟲ Log out</button>
        </div>
      </aside>

      <main className="main">
        <div className="page-header">
          <div>
            <h1><i className="fas fa-id-card"></i> Student Management</h1>
            <p>SAS-Anchored Student Records — Payment Status Tracking</p>
          </div>
          <div className="view-toggle-wrap">
            <button className={`view-toggle-btn ${view==='group'?'active':''}`}
              onClick={() => setView('group')}><i className="fas fa-layer-group"></i> Group View</button>
            <button className={`view-toggle-btn ${view==='flat'?'active':''}`}
              onClick={() => setView('flat')}><i className="fas fa-list"></i> List View</button>
          </div>
        </div>

        <div className="stats-row">
          <div className="stat-pill blue"><i className="fas fa-users"></i><div><span className="pill-label">Total Students</span><strong>{filtered.length}</strong></div></div>
          <div className="stat-pill green"><i className="fas fa-check-circle"></i><div><span className="pill-label">Paid</span><strong>{paid.length}</strong></div></div>
          <div className="stat-pill red"><i className="fas fa-times-circle"></i><div><span className="pill-label">Unpaid</span><strong>{filtered.length - paid.length}</strong></div></div>
          <div className="stat-pill yellow"><i className="fas fa-coins"></i><div><span className="pill-label">Total Collected</span><strong>{fmt(collected)}</strong></div></div>
        </div>

        <div className="toolbar">
          <div className="search-box">
            <i className="fas fa-search"></i>
            <input type="text" placeholder="Search name, ID, course..." value={q} onChange={e => setQ(e.target.value)}/>
          </div>
          <select value={yr} onChange={e => setYr(e.target.value)}>
            <option value="">All Year Levels</option>
            {['1st Year','2nd Year','3rd Year','4th Year'].map(y => <option key={y}>{y}</option>)}
          </select>
          <select value={st} onChange={e => setSt(e.target.value)}>
            <option value="">All Status</option>
            <option value="Paid">Paid</option>
            <option value="Unpaid">Unpaid</option>
          </select>
        </div>

        <div className="table-card">
          <table>
            <thead>
              <tr><th>#</th><th>Student ID</th><th>Name</th><th>Course</th><th>Year Level</th><th>Org / Club</th><th>Amount</th><th>Payment Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
              {filtered.length === 0 ? (
                <tr><td colSpan="9"><div className="empty-state"><i className="fas fa-user-slash"></i><p>No students found.</p></div></td></tr>
              ) : view === 'group' ? (
                Object.entries(grouped).map(([groupName, students]) => (
                  <GroupRow key={groupName} groupName={groupName} students={students}/>
                ))
              ) : (
                filtered.map((s, i) => (
                  <tr key={s.id}>
                    <td style={{color:'#aaa',fontSize:11}}>{i+1}</td>
                    <td style={{fontSize:12,color:'#555'}}>{s.student_id}</td>
                    <td><div className="name-cell"><div className="name-avatar">{s.full_name[0].toUpperCase()}</div><span className="name-text">{s.full_name}</span></div></td>
                    <td>{s.course}</td>
                    <td>{s.year_level}</td>
                    <td>{s.org || '—'}</td>
                    <td>{fmt(s.amount_due)}</td>
                    <td><span className={`pay-badge ${s.payment_status.toLowerCase()}`}>{s.payment_status}</span></td>
                    <td>
                      <form method="POST" style={{display:'inline'}}>
                        <input type="hidden" name="action" value="set_status"/>
                        <input type="hidden" name="id" value={s.id}/>
                        <input type="hidden" name="status" value={s.payment_status==='Paid'?'Unpaid':'Paid'}/>
                        <button className="btn-paid" type="submit">{s.payment_status==='Paid'?'Mark Unpaid':'Mark Paid'}</button>
                      </form>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </main>
      <button className="toggle" onClick={() => setSidebarOpen(!sidebarOpen)}>☰</button>
    </div>
  );
}
ReactDOM.createRoot(document.getElementById('root')).render(<App/>);
</script>
</body>
</html>

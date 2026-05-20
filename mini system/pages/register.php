<?php
session_start();
if (isset($_SESSION['user'])) { header('Location: '.($_SESSION['user']['role']==='admin'?'dashboard.php':'treasurer.php')); exit; }
require_once __DIR__ . '/../api/db.php';
$bannerMsg = ''; $bannerType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userType=$_POST['userType']??''; $org=trim($_POST['org']??''); $fullname=trim($_POST['fullname']??''); $email=trim($_POST['email']??''); $password=$_POST['password']??''; $terms=$_POST['terms']??'';
    if (!$userType||!$org||!$fullname||!$email||!$password||!$terms) { $bannerMsg='⚠ All fields are required.'; $bannerType='error'; }
    elseif (!filter_var($email,FILTER_VALIDATE_EMAIL)) { $bannerMsg='⚠ Enter a valid email address.'; $bannerType='error'; }
    elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[A-Za-z\d]{8,}$/',$password)) { $bannerMsg='⚠ Min 8 chars — must include uppercase, lowercase & number.'; $bannerType='error'; }
    else {
        try {
            $chk=db()->prepare('SELECT id FROM users WHERE LOWER(email)=LOWER(:e) LIMIT 1'); $chk->execute([':e'=>$email]);
            if ($chk->fetch()) { $bannerMsg='⚠ An account with this email already exists.'; $bannerType='warn'; }
            else {
                $entStmt=db()->prepare('SELECT id FROM entities WHERE name=:n LIMIT 1'); $entStmt->execute([':n'=>$org]); $ent=$entStmt->fetch(); $entityId=$ent?$ent['id']:null;
                $ins=db()->prepare('INSERT INTO users (full_name,email,password_hash,role,entity_id,auth_provider,is_google_account) VALUES (:fn,:em,:pw,"officer",:ei,"local",0)');
                $ins->execute([':fn'=>$fullname,':em'=>$email,':pw'=>password_hash($password,PASSWORD_BCRYPT),':ei'=>$entityId]);
                $bannerMsg='✔ Registration successful! You can now log in.'; $bannerType='success';
            }
        } catch (Exception $e) { $bannerMsg='⚠ Database error: '.$e->getMessage(); $bannerType='error'; }
    }
}
// Load entities from DB grouped by category
$allEntities = db()->query("SELECT name, category FROM entities WHERE is_active=1 ORDER BY category, name")->fetchAll();
$orgList   = array_filter($allEntities, fn($e) => $e['category'] === 'organization');
$clubList  = array_filter($allEntities, fn($e) => $e['category'] === 'club');
$orgNames  = array_column(array_values($orgList),  'name');
$clubNames = array_column(array_values($clubList), 'name');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>E-CFund — Register</title>
<link rel="stylesheet" href="/mini%20system/CSS/Register.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
<script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
<script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
</head>
<body>
<img src="/mini%20system/IMG/omsc.jpg" class="background">
<div id="root"></div>
<script>
const PHP = { banner: <?= json_encode($bannerMsg) ?>, bannerType: <?= json_encode($bannerType) ?>, orgs: <?= json_encode($orgNames) ?>, clubs: <?= json_encode($clubNames) ?> };
</script>
<script type="text/babel">
const { useState } = React;
function RegisterForm() {
  const [userType, setUserType] = useState('');
  const [org,      setOrg]      = useState('');
  const [fullname, setFullname] = useState('');
  const [email,    setEmail]    = useState('');
  const [password, setPassword] = useState('');
  const [showPw,   setShowPw]   = useState(false);
  const [terms,    setTerms]    = useState(false);
  const [errors,   setErrors]   = useState({});
  const [banner,   setBanner]   = useState({ msg: PHP.banner, type: PHP.bannerType });

  const validate = () => {
    const e = {};
    if (!userType) e.userType = '⚠ Please select a user type';
    if (userType === 'treasurer' && !org) e.org = '⚠ Please select your organization or club';
    if (!fullname) e.fullname = '⚠ Full name is required';
    else if (!/^[A-Za-z\s]+$/.test(fullname)) e.fullname = '⚠ Name must contain letters and spaces only';
    if (!email) e.email = '⚠ Email address is required';
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) e.email = '⚠ Enter a valid email';
    if (!password) e.password = '⚠ Password is required';
    else if (!/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[A-Za-z\d]{8,}$/.test(password)) e.password = '⚠ Min 8 chars — uppercase, lowercase & number';
    if (!terms) e.terms = '⚠ You must agree to Terms';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  return (
    <div className="main-container">
      <div className="left-section">
        <img src="/mini%20system/IMG/E-CFUNDS logo.png" className="logo"/>
        <h1>E-CFund's</h1><h2>Monitoring System</h2>
      </div>
      <div className="register-card">
        <h1>Register</h1>
        {banner.msg && <div className={`notify-banner ${banner.type}`}>{banner.msg}</div>}
        <form method="POST" onSubmit={e => { if(!validate()) e.preventDefault(); }}>
          <select name="userType" value={userType} onChange={e => { setUserType(e.target.value); setErrors({}); setBanner({msg:'',type:''}); }}>
            <option value="">Select user type</option>
            <option value="treasurer">Treasurer</option>
          </select>
          {errors.userType && <small className="error">{errors.userType}</small>}

          {userType === 'treasurer' && (
            <>
              <select name="org" value={org} onChange={e => { setOrg(e.target.value); setErrors(p=>({...p,org:''})); }}>
                <option value="">Select your Organization / Club</option>
                <optgroup label="── Organizations ──">
                  {PHP.orgs.map(o => <option key={o} value={o}>{o}</option>)}
                </optgroup>
                <optgroup label="── Clubs ──">
                  {PHP.clubs.map(c => <option key={c} value={c}>{c}</option>)}
                </optgroup>
              </select>
              {errors.org && <small className="error">{errors.org}</small>}
            </>
          )}

          <input type="text" name="fullname" placeholder="Full Name" value={fullname}
            onChange={e => { setFullname(e.target.value); setErrors(p=>({...p,fullname:''})); }}/>
          {errors.fullname && <small className="error">{errors.fullname}</small>}

          <input type="text" name="email" placeholder="Email Address" value={email}
            onChange={e => { setEmail(e.target.value); setErrors(p=>({...p,email:''})); }}/>
          {errors.email && <small className="error">{errors.email}</small>}

          <div className="password-box">
            <input type={showPw?'text':'password'} name="password" placeholder="Password" value={password}
              onChange={e => { setPassword(e.target.value); setErrors(p=>({...p,password:''})); }}/>
            <i className={`fa ${showPw?'fa-eye-slash':'fa-eye'}`} onClick={() => setShowPw(!showPw)}></i>
          </div>
          {errors.password && <small className="error">{errors.password}</small>}

          <div className="terms">
            <input type="checkbox" name="terms" id="terms" value="1" checked={terms} onChange={e => { setTerms(e.target.checked); setErrors(p=>({...p,terms:''})); }}/>
            <label htmlFor="terms">I agree to the Terms and Privacy Policy</label>
          </div>
          {errors.terms && <small className="error">{errors.terms}</small>}

          <button type="submit"><i className="fas fa-user-plus"></i> Register</button>
          <p className="login">Already have an account? <a href="login.php">Sign In</a></p>
        </form>
      </div>
    </div>
  );
}
ReactDOM.createRoot(document.getElementById('root')).render(<RegisterForm/>);
</script>
</body>
</html>

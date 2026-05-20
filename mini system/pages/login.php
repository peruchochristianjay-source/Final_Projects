<?php
session_start();
if (isset($_SESSION['user'])) {
header('Location: ' . ($_SESSION['user']['role'] === 'admin' ? '/mini%20system/pages/dashboard.php' : '/mini%20system/pages/treasurer.php')); exit;
}
require_once __DIR__ . '/../api/db.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? ''); $password = $_POST['password'] ?? ''; $userType = $_POST['userType'] ?? ''; $terms = $_POST['terms'] ?? '';
    if (!$email || !$password || !$userType) { $error = '⚠ All fields are required.'; }
    elseif (!$terms) { $error = '⚠ You must agree to Terms.'; }
    else {
        try {
            $stmt = db()->prepare('SELECT * FROM users WHERE LOWER(email)=LOWER(:e) LIMIT 1');
            $stmt->execute([':e' => $email]); $row = $stmt->fetch();
            if ($row && password_verify($password, $row['password_hash']) && $row['role'] === $userType) {
                $org = ''; $entityType = 'organization';
                if ($row['entity_id']) {
                    $es = db()->prepare('SELECT name, category FROM entities WHERE id=:i LIMIT 1');
                    $es->execute([':i'=>$row['entity_id']]); $ent=$es->fetch();
                    $org = $ent ? $ent['name'] : '';
                    $entityType = $ent ? $ent['category'] : 'organization';
                }
                $_SESSION['user'] = ['id'=>$row['id'],'fullName'=>$row['full_name'],'email'=>$row['email'],'role'=>$row['role'],'entityId'=>$row['entity_id'],'org'=>$org,'entityType'=>$entityType];
header('Location: ' . ($row['role']==='admin'?'/mini%20system/pages/dashboard.php':'/mini%20system/pages/treasurer.php')); exit;
            } else { $error = '⚠ Invalid credentials or user type.'; }
        } catch (Exception $e) { $error = '⚠ Database error. Make sure the database is set up.'; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>E-CFund — Login</title>
<link rel="stylesheet" href="/mini%20system/CSS/Login.css">
<link rel="stylesheet" href="/mini%20system/CSS/professional_ui.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
<script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
<script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
</head>
<body>
<img src="/mini%20system/IMG/omsc.jpg" class="background">
<div id="root"></div>
<script>
const PHP = { error: <?= json_encode($error) ?>, post: <?= json_encode($_POST) ?> };
</script>
<script type="text/babel">
const { useState } = React;

function LoginForm() {
  const [userType, setUserType] = useState(PHP.post.userType || '');
  const [email,    setEmail]    = useState(PHP.post.email    || '');
  const [password, setPassword] = useState('');
  const [showPw,   setShowPw]   = useState(false);
  const [terms,    setTerms]    = useState(false);
  const [errors,   setErrors]   = useState({});
  const [banner,   setBanner]   = useState(PHP.error || '');

  const validate = () => {
    const e = {};
    if (!userType)       e.userType = '⚠ Please select a user type';
    if (!email.trim())   e.email    = '⚠ Email is required';
    if (!password.trim())e.password = '⚠ Password is required';
    if (!terms)          e.terms    = '⚠ You must agree to Terms';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  return (
    <div className="main-container">
      <div className="left-section">
        <img src="/mini%20system/IMG/E-CFUNDS logo.png" className="logo"/>
        <h1>E-CFund's</h1>
        <h2>Monitoring System</h2>
      </div>
      <div className="register-card">
        <h1>Log In</h1>
        {banner && <div className="notify-banner error">{banner}</div>}
        <form method="POST" onSubmit={e => { if(!validate()) e.preventDefault(); }}>
          <div className="input-group">
            <select name="userType" value={userType} onChange={e => { setUserType(e.target.value); setBanner(''); setErrors({}); }}>
              <option value="">Select user type</option>
              <option value="admin">Admin</option>
              <option value="officer">Treasurer / Officer</option>
            </select>
            {errors.userType && <small className="error">{errors.userType}</small>}
          </div>

          <div className="input-group">
            <input type="text" name="email" placeholder="Email Address" value={email}
              onChange={e => { setEmail(e.target.value); setBanner(''); setErrors(p=>({...p,email:''})); }}/>
            {errors.email && <small className="error">{errors.email}</small>}
          </div>

          <div className="input-group">
            <div className="password-box">
              <input type={showPw?'text':'password'} name="password" placeholder="Password" value={password}
                onChange={e => { setPassword(e.target.value); setErrors(p=>({...p,password:''})); }}/>
              <i className={`fa ${showPw?'fa-eye-slash':'fa-eye'}`} onClick={() => setShowPw(!showPw)}></i>
            </div>
            {errors.password && <small className="error">{errors.password}</small>}
          </div>

          <div className="terms">
            <input type="checkbox" name="terms" id="terms" value="1" checked={terms} onChange={e => { setTerms(e.target.checked); setErrors(p=>({...p,terms:''})); }}/>
            <label htmlFor="terms">I agree to the Terms and Privacy Policy</label>
          </div>
          {errors.terms && <small className="error">{errors.terms}</small>}

          <button type="submit"><i className="fas fa-sign-in-alt"></i> Log In</button>
          {userType === 'officer' && <p className="login">Don't have an account? <a href="register.php">Register here</a></p>}
        </form>
      </div>
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<LoginForm/>);
</script>
</body>
</html>

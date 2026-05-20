<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') { header('Location: /mini%20system/pages/login.php'); exit; }
require_once __DIR__ . '/../api/db.php';
$user = $_SESSION['user'];
$pdo  = db();

$orgCount      = (int)$pdo->query("SELECT COUNT(*) FROM entities WHERE category='organization' AND is_active=1")->fetchColumn();
$clubCount     = (int)$pdo->query("SELECT COUNT(*) FROM entities WHERE category='club' AND is_active=1")->fetchColumn();
// Collections = only paid student membership fees across all entities
$collections = (float)$pdo->query("SELECT COALESCE(SUM(amount_due),0) FROM students WHERE payment_status='Paid'")->fetchColumn();
// Expenses = only released funds by SAS admin
$expenses = [];
try { $expenses = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM fund_releases WHERE status='Released'")->fetchColumn(); }
catch (PDOException $ex) { $expenses = 0; }

// JOIN: transactions + entities — recent activity feed
$recentTxns = $pdo->query(
    "SELECT t.tx_date, e.name AS entity, e.category, t.type, t.amount, t.description, t.source
     FROM transactions t
     LEFT JOIN entities e ON e.id = t.entity_id
     ORDER BY t.tx_date DESC, t.id DESC LIMIT 10"
)->fetchAll();

// JOIN: remit_requests + entities + users — pending remits for admin
$pendingRemits = [];
try {
    $pendingRemits = $pdo->query(
        "SELECT rr.id, rr.amount, rr.request_date, rr.description,
                e.name AS entity_name, u.full_name AS treasurer_name
         FROM remit_requests rr
         JOIN entities e ON e.id = rr.entity_id
         JOIN users    u ON u.id = rr.treasurer_id
         WHERE rr.status = 'Pending'
         ORDER BY rr.request_date ASC"
    )->fetchAll();
} catch (PDOException $ex) { $pendingRemits = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>E-CFunds Dashboard</title>
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
  user:          <?= json_encode(['fullName' => $user['fullName']]) ?>,
  orgCount:      <?= $orgCount ?>,
  clubCount:     <?= $clubCount ?>,
  collections:   <?= $collections ?>,
  expenses:      <?= $expenses ?>,
  recentTxns:    <?= json_encode($recentTxns) ?>,
  pendingRemits: <?= json_encode($pendingRemits) ?>,
  BASE:          '/mini%20system'
};
</script>

<script type="text/babel">
const { useState } = React;

const fmt = v => '₱' + Number(v).toLocaleString('en-PH', {minimumFractionDigits:0});

function Sidebar({ active, user, sidebarOpen, setSidebarOpen }) {
  const links = [
    { href:'dashboard.php',    icon:'fa-chart-line',   label:'SAS Dashboard' },
    { href:'organization.php', icon:'fa-building',      label:'Organizations' },
    { href:'clubs.php',        icon:'fa-user-graduate', label:'Clubs' },
    { href:'students.php',     icon:'fa-id-card',       label:'Students' },
    { href:'accredited.php',   icon:'fa-certificate',   label:'Accredited List' },
    { href:'financial.php',    icon:'fa-wallet',        label:'Financial' },
    { href:'transaction.php',  icon:'fa-receipt',       label:'Transactions' },
  ];
  return (
    <aside className={`sidebar${sidebarOpen ? '' : ' hidden'}`} id="sidebar">
      <div>
        <div className="logo">
          <img src={`${PHP.BASE}/IMG/E-CFUNDS logo.png`} alt="Logo"/>
          <h2>E-CFund's <br/><span>Monitoring System</span></h2>
        </div>
        <nav className="nav-menu">
          {links.map(l => (
            <a key={l.href} href={l.href} className={l.href === active ? 'nav-active' : ''}>
              <i className={`fas ${l.icon}`}></i> {l.label}
            </a>
          ))}
        </nav>
      </div>
      <div className="user logout-wrap">
        <p><strong>{user.fullName}</strong></p>
        <small>SAS Admin</small>
        <button className="logout" onClick={() => window.location.href='logout.php'}>⟲ Log out</button>
      </div>
    </aside>
  );
}

function SummaryCard({ label, value, color, icon }) {
  return (
    <div className={`sum-card ${color}`}>
      <div className="sum-card-head">
        <p className="sum-label">{label}</p>
        <span className="sum-icon"><i className={`fas ${icon}`}></i></span>
      </div>
      <h2>{value}</h2>
    </div>
  );
}

function RecentTransactions({ txns }) {
  return (
    <div className="detail-card">
      <h3><i className="fas fa-receipt"></i> Recent Transactions</h3>
      <table>
        <thead>
          <tr><th>Date</th><th>Org / Club</th><th>Amount</th><th>Remarks</th></tr>
        </thead>
        <tbody>
          {txns.length === 0 ? (
            <tr><td colSpan="4" style={{textAlign:'center',color:'#aaa'}}>No transactions yet.</td></tr>
          ) : txns.map((t, i) => {
            const color = t.type === 'Income' ? '#16a34a' : '#dc2626';
            const sign  = t.type === 'Income' ? '+' : '-';
            return (
              <tr key={i}>
                <td>{t.tx_date}</td>
                <td><strong>{t.entity || '—'}</strong></td>
                <td style={{color, fontWeight:700}}>{sign} {fmt(t.amount)}</td>
                <td>{t.description || '—'}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function Chatbot() {
  const [open, setOpen]       = useState(false);
  const [messages, setMessages] = useState([{ type:'bot', text:'Welcome to E-CFunds support.\nAsk about paid/unpaid students, collections, or fund totals.' }]);
  const [input, setInput]     = useState('');
  const [sending, setSending] = useState(false);
  const [aiOnline, setAiOnline] = useState(false);
  const suggestions = ['Help', 'System Flow', 'Total Collections', 'Total Expenses', 'Paid Students', 'Unpaid Students', 'List Organizations', 'List Clubs'];
  const messagesRef = React.useRef(null);

  React.useEffect(() => {
    if (messagesRef.current) {
      messagesRef.current.scrollTop = messagesRef.current.scrollHeight;
    }
  }, [messages, open]);

  const checkAiStatus = async () => {
    try {
      const res = await fetch(`${PHP.BASE}/pages/chatbot.php`, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=status'
      });
      const data = await res.json();
      setAiOnline(!!data?.online);
    } catch {
      setAiOnline(false);
    }
  };

  React.useEffect(() => {
    if (open) checkAiStatus();
  }, [open]);

  const sendMsg = async (msg) => {
    if (!msg.trim() || sending) return;
    const userMsg = msg.trim();
    setInput('');
    setSending(true);
    setMessages(prev => [...prev, { type:'user', text:userMsg }, { type:'bot', text:'Loading response...', typing:true }]);
    try {
      const res  = await fetch(`${PHP.BASE}/pages/chatbot.php`, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'msg='+encodeURIComponent(userMsg) });
      if (!res.ok) throw new Error('Request failed');
      const data = await res.json();
      const reply = (data && typeof data.reply === 'string' && data.reply.trim()) ? data.reply : 'No response generated. Please try again.';
      setMessages(prev => [...prev.filter(m => !m.typing), { type:'bot', text:reply }]);
    } catch {
      setMessages(prev => [...prev.filter(m => !m.typing), { type:'bot', text:'Sorry, something went wrong.' }]);
    } finally {
      setSending(false);
    }
  };

  const resetChat = async () => {
    try {
      await fetch(`${PHP.BASE}/pages/chatbot.php`, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=clear_history'
      });
    } catch {}
    setMessages([{ type:'bot', text:'New chat started.\nGemini is ready for your next question.' }]);
    setInput('');
  };


  return (
    <>
      <button className="chat-btn" onClick={() => setOpen(!open)} title="E-CFunds Support">
        <i className="fas fa-comments"></i>
      </button>
      {open && (
        <div className="chat-window" style={{display:'flex'}}>
          <div className="chat-header">
            <div style={{display:'flex',alignItems:'center',gap:8}}>
              <i className="fas fa-comments" style={{fontSize:16}}></i>
              <div>
                <div style={{fontWeight:700,fontSize:13}}>E-CFunds Support</div>
                <div style={{fontSize:10,opacity:0.8}}>
                  {aiOnline ? 'Gemini AI Online' : 'Gemini AI Offline'}
                </div>
              </div>
            </div>
            <div style={{display:'flex',alignItems:'center',gap:8}}>
              <button onClick={resetChat} style={{background:'rgba(255,255,255,0.18)',border:'1px solid rgba(255,255,255,0.35)',color:'white',cursor:'pointer',fontSize:10,padding:'4px 8px',borderRadius:10,fontWeight:700}}>
                New Chat
              </button>
              <button onClick={() => setOpen(false)} style={{background:'none',border:'none',color:'white',cursor:'pointer',fontSize:16}}>
                <i className="fas fa-times"></i>
              </button>
            </div>
          </div>
          <div className="chat-messages" ref={messagesRef}>
            {messages.map((m, i) => (
              <div key={i} className={`chat-msg ${m.typing ? 'typing' : m.type}`}>
                {m.text}
              </div>
            ))}
          </div>
          <div className="chat-suggestions">
            {suggestions.map(s => (
              <button key={s} onClick={() => sendMsg(s)} disabled={sending}>{s}</button>
            ))}
          </div>
          <div className="chat-input-row">
            <input value={input} onChange={e => setInput(e.target.value)}
              onKeyDown={e => e.key==='Enter' && sendMsg(input)}
              placeholder="Ask your question..."
              disabled={sending}/>
            <button onClick={() => sendMsg(input)} disabled={sending}><i className={`fas ${sending ? 'fa-spinner fa-spin' : 'fa-paper-plane'}`}></i></button>
          </div>
        </div>
      )}
    </>
  );
}

function AdminInbox() {
  const [open, setOpen]         = React.useState(false);
  const [convos, setConvos]     = React.useState([]);
  const [selected, setSelected] = React.useState(null);
  const [messages, setMessages] = React.useState([]);
  const [input, setInput]       = React.useState('');
  const [unread, setUnread]     = React.useState(0);
  const [sending, setSending]   = React.useState(false);
  const [sendMode, setSendMode] = React.useState('selected');
  const [notice, setNotice]     = React.useState('');
  const [deletingConversation, setDeletingConversation] = React.useState(false);
  const [deletingMessageId, setDeletingMessageId] = React.useState(null);

  const loadConvos = async () => {
    try {
      const res  = await fetch('message.php?action=conversations');
      const data = await res.json();
      const rows = Array.isArray(data) ? data : [];
      setConvos(rows);
      const total = rows.reduce((a,c) => a + parseInt(c.unread_count || 0, 10), 0);
      setUnread(total);
    } catch (e) {}
  };

  const loadMessages = async (entityId) => {
    setSelected(entityId);
    try {
      const res  = await fetch(`message.php?action=fetch&entity_id=${entityId}`);
      const data = await res.json();
      setMessages(Array.isArray(data) ? data : []);
    } catch (e) {
      setMessages([]);
    }
    loadConvos();
  };

  const deleteConversation = async () => {
    if (!selectedConvo || deletingConversation) return;
    if (!confirm(`Delete all messages with ${selectedConvo.treasurer_name}?`)) return;

    setDeletingConversation(true);
    setNotice('');
    try {
      const fd = new FormData();
      fd.append('action', 'delete_conversation');
      fd.append('entity_id', selected);
      const res = await fetch('message.php', { method:'POST', body:fd });
      const data = await res.json();

      if (data && data.error) {
        setNotice(data.error);
        return;
      }

      setSelected(null);
      setMessages([]);
      setNotice('Conversation deleted.');
      await loadConvos();
    } catch (e) {
      setNotice('Failed to delete conversation.');
    } finally {
      setDeletingConversation(false);
    }
  };

  const deleteMessage = async (messageId) => {
    if (!messageId || deletingMessageId === messageId) return;
    if (!confirm('Delete this message?')) return;

    setDeletingMessageId(messageId);
    setNotice('');
    try {
      const fd = new FormData();
      fd.append('action', 'delete_message');
      fd.append('message_id', messageId);
      const res = await fetch('message.php', { method:'POST', body:fd });
      const data = await res.json();

      if (data && data.error) {
        setNotice(data.error);
        return;
      }

      setNotice('Message deleted.');
      if (selected) {
        await loadMessages(selected);
      } else {
        await loadConvos();
      }
    } catch (e) {
      setNotice('Failed to delete message.');
    } finally {
      setDeletingMessageId(null);
    }
  };

  const sendReply = async () => {
    const text = input.trim();
    if (!text || sending) return;
    if (sendMode === 'selected' && !selected) {
      setNotice('Select a treasurer conversation first.');
      return;
    }

    setSending(true);
    setNotice('');
    try {
      const fd = new FormData();
      fd.append('message', text);
      if (sendMode === 'all') {
        fd.append('action', 'send_all');
      } else {
        fd.append('action', 'send');
        fd.append('entity_id', selected);
      }

      const res = await fetch('message.php', { method:'POST', body:fd });
      const data = await res.json();

      if (data && data.error) {
        setNotice(data.error);
        return;
      }

      setInput('');
      if (sendMode === 'all') {
        const sentCount = parseInt(data?.sent || 0, 10);
        setNotice(`Broadcast sent to ${sentCount} treasurer${sentCount === 1 ? '' : 's'}.`);
        await loadConvos();
        if (selected) await loadMessages(selected);
      } else {
        await loadMessages(selected);
      }
    } catch (e) {
      setNotice('Failed to send message.');
    } finally {
      setSending(false);
    }
  };

  React.useEffect(() => {
    loadConvos();
    const t = setInterval(loadConvos, 10000);
    return () => clearInterval(t);
  }, []);

  const selectedConvo = convos.find(c => c.entity_id == selected);
  const canSend = !!input.trim() && !sending && (sendMode === 'all' || !!selected);

  return (
    <>
      <button onClick={() => { setOpen(!open); if(!open) loadConvos(); }}
        title="Treasurer Concerns"
        style={{position:'fixed',bottom:90,right:24,width:54,height:54,background:'#16a34a',color:'white',border:'none',borderRadius:'50%',fontSize:20,cursor:'pointer',boxShadow:'0 4px 16px rgba(22,163,74,0.4)',zIndex:9997,display:'flex',alignItems:'center',justifyContent:'center'}}>
        <i className="fas fa-inbox"></i>
        {unread > 0 && (
          <span style={{position:'absolute',top:-4,right:-4,background:'#dc2626',color:'white',borderRadius:'50%',width:18,height:18,fontSize:10,fontWeight:700,display:'flex',alignItems:'center',justifyContent:'center',animation:'pulse 1s infinite'}}>{unread}</span>
        )}
      </button>

      {open && (
        <div style={{position:'fixed',bottom:156,right:16,width:'min(520px, calc(100vw - 32px))',height:'min(560px, calc(100vh - 176px))',background:'white',borderRadius:16,boxShadow:'0 8px 32px rgba(0,0,0,0.18)',zIndex:9998,display:'flex',flexDirection:'column',overflow:'hidden'}}>

          {/* Header */}
          <div style={{background:'linear-gradient(135deg,#16a34a,#15803d)',color:'white',padding:'14px 16px',display:'flex',justifyContent:'space-between',alignItems:'center',flexShrink:0}}>
            <div style={{display:'flex',alignItems:'center',gap:8}}>
              <i className="fas fa-inbox" style={{fontSize:16}}></i>
              <div>
                <div style={{fontWeight:700,fontSize:13}}>Treasurer Concerns</div>
                <div style={{fontSize:10,opacity:0.8}}>{unread} unread · {convos.length} conversation{convos.length!==1?'s':''}</div>
              </div>
            </div>
            <button onClick={()=>setOpen(false)} style={{background:'none',border:'none',color:'white',cursor:'pointer',fontSize:16}}><i className="fas fa-times"></i></button>
          </div>

          <div style={{display:'flex',flex:1,overflow:'hidden'}}>

            {/* Conversation List */}
            <div style={{width:168,borderRight:'1px solid #eee',overflowY:'auto',background:'#f8fafc',flexShrink:0}}>
              {convos.length === 0 && <div style={{padding:12,fontSize:11,color:'#aaa',textAlign:'center',marginTop:20}}>No conversations yet</div>}
              {convos.map(c => (
                <div key={c.entity_id} onClick={()=>{ setSendMode('selected'); setNotice(''); loadMessages(c.entity_id); }}
                  style={{padding:'10px 12px',cursor:'pointer',borderBottom:'1px solid #eee',background:selected==c.entity_id?'#e0f0fa':'white',transition:'0.15s'}}>
                  <div style={{display:'flex',justifyContent:'space-between',alignItems:'center'}}>
                    <span style={{fontWeight:700,color:'#0f172a',fontSize:12}}>{c.short_name}</span>
                    {c.unread_count > 0 && (
                      <span style={{background:'#dc2626',color:'white',borderRadius:10,padding:'1px 6px',fontSize:10,fontWeight:700}}>{c.unread_count}</span>
                    )}
                  </div>
                  <div style={{fontSize:10,color:'#0b5d8b',fontWeight:600,marginTop:1}}>{c.treasurer_name}</div>
                  <div style={{fontSize:10,color:'#94a3b8',marginTop:2,whiteSpace:'nowrap',overflow:'hidden',textOverflow:'ellipsis'}}>
                    {c.last_message ? c.last_message.substring(0,28)+'...' : 'No messages yet'}
                  </div>
                </div>
              ))}
            </div>

            {/* Chat Area */}
            <div style={{flex:1,display:'flex',flexDirection:'column',overflow:'hidden'}}>
              {selectedConvo && (
                <div style={{padding:'10px 14px',borderBottom:'1px solid #eee',background:'#f8fafc',flexShrink:0,display:'flex',justifyContent:'space-between',alignItems:'center'}}>
                  <div>
                    <div style={{fontWeight:700,fontSize:13,color:'#0f172a'}}>{selectedConvo.treasurer_name}</div>
                    <div style={{fontSize:11,color:'#64748b'}}>{selectedConvo.entity_name} &middot; <span style={{color:'#0b5d8b'}}>{selectedConvo.treasurer_email}</span></div>
                  </div>
                  <button
                    title="Delete conversation"
                    onClick={deleteConversation}
                    disabled={deletingConversation}
                    className="inbox-del-btn"
                    style={{flexShrink:0}}>
                    <i className={`fas ${deletingConversation ? 'fa-spinner fa-spin' : 'fa-trash'}`}></i> Delete
                  </button>
                </div>
              )}

              <div style={{flex:1,overflowY:'auto',padding:10,display:'flex',flexDirection:'column',gap:6}}>
                {!selected ? (
                  <div style={{flex:1,display:'flex',flexDirection:'column',alignItems:'center',justifyContent:'center',color:'#94a3b8',gap:8}}>
                    <i className={`fas ${sendMode === 'all' ? 'fa-bullhorn' : 'fa-comments'}`} style={{fontSize:32,color:'#e2e8f0'}}></i>
                    <span style={{fontSize:12}}>
                      {sendMode === 'all' ? 'Broadcast mode is active. Type your concern below to notify all treasurers.' : 'Select a treasurer to view messages.'}
                    </span>
                  </div>
                ) : (
                  <>
                    {messages.length===0 && <div style={{textAlign:'center',color:'#aaa',fontSize:12,padding:20}}>No messages yet.</div>}
                    {messages.map((m,i) => {
                      const isAdmin = m.sender_role === 'admin';
                      const time    = new Date(m.created_at).toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'});
                      return (
                        <div key={i} style={{maxWidth:'88%',alignSelf:isAdmin?'flex-end':'flex-start',display:'flex',flexDirection:'column',gap:4}}>
                          <div style={{padding:'8px 12px',borderRadius:12,fontSize:12.5,lineHeight:1.5,background:isAdmin?'#0b5d8b':'#e0f0fa',color:isAdmin?'white':'#1d3557'}}>
                            <div style={{fontSize:10,fontWeight:700,opacity:0.75,marginBottom:3}}>{isAdmin?'You (Admin)':m.sender_name}</div>
                            <div>{m.message}</div>
                            <div style={{fontSize:10,opacity:0.55,marginTop:3,textAlign:isAdmin?'right':'left'}}>{time}</div>
                          </div>
                          {isAdmin && (
                            <div style={{display:'flex',justifyContent:'flex-end'}}>
                              <button
                                type="button"
                                onClick={() => deleteMessage(m.id)}
                                disabled={deletingMessageId === m.id}
                                className="inbox-del-btn inbox-del-btn-sm">
                                <i className={`fas ${deletingMessageId === m.id ? 'fa-spinner fa-spin' : 'fa-trash'}`}></i> Delete
                              </button>
                            </div>
                          )}
                        </div>
                      );
                    })}
                  </>
                )}
              </div>

              <div style={{padding:'8px 10px',borderTop:'1px solid #eee',flexShrink:0,background:'white'}}>
                <div style={{display:'flex',gap:6,marginBottom:6}}>
                  <button
                    type="button"
                    onClick={() => { setSendMode('selected'); setNotice(''); }}
                    style={{padding:'4px 10px',fontSize:11,fontWeight:700,borderRadius:14,border:'1px solid',borderColor:sendMode==='selected'?'#0b5d8b':'#d1d5db',background:sendMode==='selected'?'#e0f0fa':'#fff',color:sendMode==='selected'?'#0b5d8b':'#64748b',cursor:'pointer'}}>
                    Selected Treasurer
                  </button>
                  <button
                    type="button"
                    onClick={() => { setSendMode('all'); setNotice(''); }}
                    style={{padding:'4px 10px',fontSize:11,fontWeight:700,borderRadius:14,border:'1px solid',borderColor:sendMode==='all'?'#16a34a':'#d1d5db',background:sendMode==='all'?'#dcfce7':'#fff',color:sendMode==='all'?'#166534':'#64748b',cursor:'pointer'}}>
                    All Treasurers
                  </button>
                </div>

                {notice && (
                  <div style={{fontSize:11,fontWeight:600,color:'#0b5d8b',marginBottom:6}}>
                    {notice}
                  </div>
                )}

                <div style={{marginBottom:6,padding:'6px 9px',borderRadius:9,background:sendMode==='all'?'#ecfdf3':'#f8fafc',border:'1px solid',borderColor:sendMode==='all'?'#bbf7d0':'#e2e8f0'}}>
                  {sendMode === 'all' ? (
                    <span style={{fontSize:11.5,fontWeight:700,color:'#166534'}}>
                      Sending to: All Treasurers
                    </span>
                  ) : (
                    <span style={{fontSize:11.5,fontWeight:700,color:selectedConvo?'#0f172a':'#64748b'}}>
                      Sending to: {selectedConvo ? `${selectedConvo.treasurer_name} (${selectedConvo.entity_name})` : 'No recipient selected'}
                    </span>
                  )}
                </div>

                <div style={{display:'flex',gap:6,alignItems:'flex-end'}}>
                  <textarea
                    value={input}
                    onChange={e=>setInput(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        sendReply();
                      }
                    }}
                    rows={3}
                    placeholder={sendMode === 'all' ? 'Message all treasurers about the concern...' : `Reply to ${selectedConvo?.treasurer_name || 'treasurer'}...`}
                    style={{flex:1,minHeight:58,maxHeight:132,resize:'vertical',padding:'9px 12px',border:'1px solid #ddd',borderRadius:12,fontSize:12.5,outline:'none',fontFamily:'inherit',lineHeight:1.45}}
                  />
                  <button
                    onClick={sendReply}
                    disabled={!canSend}
                    style={{width:36,height:36,background:canSend?'#16a34a':'#94a3b8',color:'white',border:'none',borderRadius:'50%',cursor:canSend?'pointer':'not-allowed',fontSize:13,display:'flex',alignItems:'center',justifyContent:'center',flexShrink:0}}>
                    <i className={`fas ${sending ? 'fa-spinner fa-spin' : 'fa-paper-plane'}`}></i>
                  </button>
                </div>
                <div style={{fontSize:10,color:'#94a3b8',marginTop:4}}>
                  Press Enter to send, Shift+Enter for new line.
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

function App() {
  const [sidebarOpen, setSidebarOpen] = useState(true);
  return (
    <div className="container">
      <Sidebar active="dashboard.php" user={PHP.user} sidebarOpen={sidebarOpen} setSidebarOpen={setSidebarOpen}/>
      <main className="main">
        <div className="header">
          <div>
            <h1>SAS Dashboard</h1>
            <p>Student Affairs and Services - Administrative Control Panel</p>
          </div>
          <div className="header-actions">
            <span className="live-badge react-badge"><i className="fab fa-react"></i> React UI Runtime</span>
            <button className="add-btn" onClick={() => window.location.href='transaction.php'}>
              <i className="fas fa-receipt"></i> View Transactions
            </button>
          </div>
        </div>

        <div className="summary-row">
          <SummaryCard label="Organizations"     value={PHP.orgCount}          color="blue"  icon="fa-building"/>
          <SummaryCard label="Clubs"             value={PHP.clubCount}         color="green" icon="fa-user-graduate"/>
          <SummaryCard label="Total Collections" value={fmt(PHP.collections)}  color="navy"  icon="fa-wallet"/>
          <SummaryCard label="Total Expenses"    value={fmt(PHP.expenses)}     color="red"   icon="fa-chart-line"/>
        </div>

        <div className="detail-card">
          <h3><i className="fas fa-bolt"></i> Quick Actions</h3>
          <div className="quick-actions">
            <button className="admin-control-btn secondary" onClick={() => window.location.href='organization.php'}>
              <i className="fas fa-building"></i> Manage Organizations
            </button>
            <button className="admin-control-btn secondary" onClick={() => window.location.href='clubs.php'}>
              <i className="fas fa-users"></i> Manage Clubs
            </button>
            <button className="admin-control-btn secondary" onClick={() => window.location.href='students.php'}>
              <i className="fas fa-id-card"></i> Manage Students
            </button>
            <button className="admin-control-btn secondary" onClick={() => window.location.href='financial.php'}>
              <i className="fas fa-file-invoice-dollar"></i> Add Financial Record
            </button>
          </div>
        </div>

        <RecentTransactions txns={PHP.recentTxns}/>

        {PHP.pendingRemits.length > 0 && (
          <div className="detail-card" style={{borderLeft:'4px solid #ca8a04'}}>
            <h3><i className="fas fa-inbox" style={{color:'#ca8a04'}}></i> Pending Remittance Requests
              <span style={{marginLeft:8,background:'#fef9c3',color:'#ca8a04',fontSize:11,padding:'2px 8px',borderRadius:10}}>{PHP.pendingRemits.length}</span>
            </h3>
            <table>
              <thead><tr><th>Org / Club</th><th>Treasurer</th><th>Date</th><th>Amount</th><th>Description</th><th>Action</th></tr></thead>
              <tbody>
                {PHP.pendingRemits.map(r => (
                  <tr key={r.id}>
                    <td><strong>{r.entity_name}</strong></td>
                    <td style={{color:'#64748b'}}>{r.treasurer_name}</td>
                    <td style={{color:'#64748b'}}>{r.request_date}</td>
                    <td style={{fontWeight:700,color:'#0b5d8b'}}>{'₱'+Number(r.amount).toLocaleString()}</td>
                    <td style={{color:'#64748b'}}>{r.description||'—'}</td>
                    <td>
                      <a href="organization.php" style={{background:'#0b5d8b',color:'white',padding:'4px 10px',borderRadius:6,fontSize:12,textDecoration:'none',fontWeight:600}}>
                        Review
                      </a>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div className="detail-card">
          <h3><i className="fas fa-shield-alt"></i> SAS Administrative Controls</h3>
          <p style={{fontSize:12,color:'#666',marginBottom:2}}>Administrative tools are currently managed through the active modules in the sidebar.</p>
        </div>
      </main>

      <button className="toggle" onClick={() => setSidebarOpen(!sidebarOpen)}>☰</button>
      <Chatbot/>
      <AdminInbox/>
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App/>);
</script>

<style>
.chat-btn{position:fixed;bottom:24px;right:24px;width:54px;height:54px;background:#0b5d8b;color:white;border:none;border-radius:50%;font-size:22px;cursor:pointer;box-shadow:0 4px 16px rgba(11,93,139,0.4);z-index:9998;transition:0.2s;display:flex;align-items:center;justify-content:center;}
.chat-btn:hover{background:#094b73;transform:scale(1.08);}
.chat-window{position:fixed;bottom:90px;right:24px;width:340px;background:white;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,0.18);z-index:9999;flex-direction:column;overflow:hidden;}
.chat-header{background:linear-gradient(135deg,#0b5d8b,#0d6fa8);color:white;padding:14px 16px;display:flex;justify-content:space-between;align-items:center;}
.chat-messages{flex:1;max-height:320px;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px;background:#f8fafc;}
.chat-msg{max-width:85%;padding:9px 13px;border-radius:12px;font-size:12.5px;line-height:1.6;white-space:pre-line;word-break:break-word;}
.chat-msg.bot{background:#e0f0fa;color:#1d3557;border-bottom-left-radius:3px;align-self:flex-start;}
.chat-msg.user{background:#0b5d8b;color:white;border-bottom-right-radius:3px;align-self:flex-end;}
.chat-msg.typing{background:#e0f0fa;color:#888;font-style:italic;align-self:flex-start;}
.chat-suggestions{display:flex;flex-wrap:wrap;gap:6px;padding:10px 12px;border-top:1px solid #eee;background:white;}
.chat-suggestions button{font-size:10.5px;padding:5px 10px;border:1px solid #c5dff0;border-radius:999px;background:#f0f8ff;color:#0b5d8b;cursor:pointer;transition:0.15s;width:auto;margin:0;line-height:1.2;font-weight:600;}
.chat-suggestions button:hover{background:#0b5d8b;color:white;}
.chat-suggestions button:disabled{opacity:0.6;cursor:not-allowed;background:#eef2f7;color:#64748b;border-color:#d1d9e6;}
.chat-input-row{display:flex;gap:6px;padding:10px 12px;border-top:1px solid #eee;background:white;}
.chat-input-row input{flex:1;padding:8px 12px;border:1px solid #ddd;border-radius:20px;font-size:12.5px;outline:none;background:white;color:#333;}
.chat-input-row input:focus{border-color:#0b5d8b;}
.chat-input-row input:disabled{background:#f8fafc;color:#94a3b8;cursor:not-allowed;}
.chat-input-row button{width:36px;height:36px;background:#0b5d8b;color:white;border:none;border-radius:50%;cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin:0;padding:0;}
.chat-input-row button:hover{background:#094b73;}
.chat-input-row button:disabled{background:#8ca3b8;cursor:not-allowed;}
.inbox-del-btn{
  background:#fff0f0;color:#dc2626;border:1px solid #fecaca;border-radius:9px;
  padding:9px 12px;cursor:pointer;font-size:12px;transition:0.2s;
  display:inline-flex;align-items:center;gap:6px;font-weight:600;
}
.inbox-del-btn:hover{background:#dc2626;color:white;border-color:#dc2626;}
.inbox-del-btn:disabled{opacity:0.6;cursor:not-allowed;}
.inbox-del-btn-sm{padding:5px 9px;font-size:11px;border-radius:8px;}
</style>
</body>
</html>

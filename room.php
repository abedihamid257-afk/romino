<?php
require __DIR__ . '/config.php';

$room = clean($_GET['room'] ?? '', 64);
if ($room === '') { header('Location: index.php'); exit; }

$stmt = db()->prepare("SELECT type, name FROM rooms WHERE room = ?");
$stmt->execute([$room]);
$roomData = $stmt->fetch();
if (!$roomData) { header('Location: index.php'); exit; }

$roomType = $roomData['type'];
$roomName = $roomData['name'];

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$shareUrl = $scheme . '://' . $host . strtok($_SERVER['REQUEST_URI'], '?') . '?room=' . rawurlencode($room);

$appJsVer = @filemtime(__DIR__ . '/app.js') ?: time();
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<title><?= esc($roomName ?: 'چت') ?> — رومینو</title>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#6366f1">
<style>
@import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap');
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#eef1f8;--card:#fff;--text:#1a1d2e;--text2:#555a7a;--muted:#8a8fa8;--line:#e7e9f5;
--grad:linear-gradient(135deg,#6366f1 0%,#a855f7 50%,#ec4899 100%);
--green:#10b981;--red:#ef4444;--amber:#f59e0b;--radius:20px;
--shadow:0 4px 6px -1px rgba(15,23,42,.06),0 12px 32px rgba(15,23,42,.08);
--shadowLg:0 8px 16px -4px rgba(15,23,42,.08),0 24px 56px rgba(15,23,42,.12)}
html,body{height:100%}
body{font-family:'Vazirmatn',system-ui,sans-serif;background:var(--bg);color:var(--text);
direction:rtl;font-size:14px;line-height:1.6;-webkit-font-smoothing:antialiased;overflow:hidden}
.bg-orbs{position:fixed;inset:0;overflow:hidden;z-index:0;pointer-events:none}
.bg-orbs::before,.bg-orbs::after{content:'';position:absolute;border-radius:50%;filter:blur(90px);opacity:.55}
.bg-orbs::before{width:520px;height:520px;background:radial-gradient(circle,#a5b4fc,transparent 70%);top:-160px;right:-160px}
.bg-orbs::after{width:520px;height:520px;background:radial-gradient(circle,#f0abfc,transparent 70%);bottom:-160px;left:-160px}

.app{position:relative;z-index:1;max-width:1200px;margin:24px auto;height:calc(100vh - 48px);
height:calc(100dvh - 48px);display:grid;grid-template-columns:340px 1fr;gap:20px;padding:0 20px}

.sidebar{background:var(--card);border-radius:var(--radius);padding:16px;
border:1px solid rgba(231,233,245,.8);box-shadow:var(--shadow);
display:flex;flex-direction:column;gap:12px;overflow-y:auto;overflow-x:hidden;
-webkit-overflow-scrolling:touch;overscroll-behavior:contain;max-height:100%}
.sidebar::-webkit-scrollbar{width:6px}
.sidebar::-webkit-scrollbar-thumb{background:rgba(139,92,246,.3);border-radius:3px}

.drawer-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:800;
opacity:0;pointer-events:none;transition:opacity .25s}
.drawer-overlay.show{opacity:1;pointer-events:auto}

.brand{display:flex;align-items:center;gap:10px;padding:2px 0}
.logo{width:42px;height:42px;border-radius:12px;background:var(--grad);color:#fff;
display:grid;place-items:center;font-size:20px;box-shadow:0 8px 24px rgba(139,92,246,.4);flex-shrink:0}
.brand-info{flex:1;min-width:0}
.brand h1{font-size:15px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.brand p{font-size:11px;color:var(--muted);margin-top:2px}
.badge{display:inline-block;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:700;margin-inline-end:4px}
.badge.admin{background:#fef3c7;color:#d97706}
.badge.personal{background:#eef0fa;color:#6366f1}
.badge.public{background:#fef3c7;color:#d97706}

.card{background:#fafbff;border-radius:14px;padding:12px;border:1px solid var(--line);
display:flex;flex-direction:column;gap:8px}
.lbl{font-size:11px;color:var(--text2);font-weight:700;letter-spacing:.2px;text-transform:uppercase}
.muted{color:var(--muted)}.small{font-size:12px}.center{text-align:center}
code{background:#eef0fa;padding:2px 8px;border-radius:8px;font-size:11px;color:#6366f1;
font-weight:600;direction:ltr;display:inline-block}

input[type=text],input[type=password]{width:100%;padding:10px 12px;
border:1.5px solid var(--line);border-radius:10px;font-family:inherit;font-size:13px;
background:#fff;color:var(--text);outline:none;transition:.2s}
input[type=text]:focus,input[type=password]:focus{border-color:#a855f7;box-shadow:0 0 0 3px rgba(168,85,247,.12)}
input:disabled{background:#f7f8fc;color:var(--muted);cursor:not-allowed}

.btn{padding:10px 14px;border-radius:10px;border:1.5px solid var(--line);background:#fff;
color:var(--text);font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;
transition:.18s;display:inline-flex;align-items:center;justify-content:center;gap:6px;
white-space:nowrap;text-align:center}
.btn:hover:not(:disabled){background:#f7f8fc;border-color:#d8dbed}
.btn:active:not(:disabled){transform:translateY(1px)}
.btn:disabled{opacity:.55;cursor:not-allowed}
.btn.primary{background:var(--grad);color:#fff;border:none;box-shadow:0 6px 20px rgba(139,92,246,.35)}
.btn.primary:hover:not(:disabled){filter:brightness(1.08)}
.btn.danger{background:var(--red);color:#fff;border:none}
.btn.success{background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;
box-shadow:0 6px 20px rgba(16,185,129,.35)}
.btn.warn{background:linear-gradient(135deg,#f59e0b,#ec4899);color:#fff;border:none}
.btn.ghost{background:rgba(255,255,255,.7)}
.btn.full{width:100%}
.btn.sm{padding:6px 10px;font-size:11px;border-radius:8px}
.btn.link{background:transparent;border:none;color:var(--text2);padding:6px;font-size:12px;
text-decoration:underline;cursor:pointer}

.linkbox{display:flex;gap:6px}
.linkbox input{direction:ltr;font-size:11px;font-family:'Courier New',monospace;padding:8px}

.chat{display:flex;flex-direction:column;background:var(--card);border-radius:var(--radius);
box-shadow:var(--shadowLg);border:1px solid rgba(231,233,245,.8);overflow:hidden;min-height:0}
.chat-header{display:flex;align-items:center;gap:10px;padding:14px 16px;
border-bottom:1px solid var(--line);background:linear-gradient(180deg,#fff,#fbfcff);flex-shrink:0}
.avatar{width:42px;height:42px;border-radius:50%;background:var(--grad);color:#fff;
display:grid;place-items:center;font-weight:700;font-size:16px;box-shadow:0 4px 14px rgba(139,92,246,.4);
flex-shrink:0}
.chat-info{flex:1;min-width:0;overflow:hidden}
.chat-info .name{font-weight:700;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chat-info .state{font-size:12px;color:var(--muted);margin-top:2px}
.chat-info .state.error{color:var(--red);font-weight:600}
.chat-info .state.online{color:var(--green);font-weight:600}

.hamburger{width:42px;height:42px;border-radius:50%;border:1.5px solid var(--line);
background:#fff;color:var(--text);font-size:20px;cursor:pointer;display:grid;place-items:center;
flex-shrink:0;transition:.18s;font-family:inherit}
.hamburger:hover{background:#f7f8fc;border-color:#d8dbed}

.call-icon{width:42px;height:42px;padding:0;border-radius:50%;border:none;
background:linear-gradient(135deg,#10b981,#059669);color:#fff;font-size:18px;cursor:pointer;
flex-shrink:0;display:grid;place-items:center;box-shadow:0 4px 12px rgba(16,185,129,.35);
transition:.18s;font-family:inherit}
.call-icon:not(:disabled){animation:pulseBtn 2s infinite}
@keyframes pulseBtn{0%,100%{box-shadow:0 4px 12px rgba(16,185,129,.35),0 0 0 0 rgba(16,185,129,.5)}
50%{box-shadow:0 4px 12px rgba(16,185,129,.35),0 0 0 10px rgba(16,185,129,0)}}
.call-icon:hover:not(:disabled){filter:brightness(1.1);transform:scale(1.05)}
.call-icon:disabled{opacity:.35;cursor:not-allowed;background:#94a3b8;box-shadow:none;animation:none}

.lock-banner{background:#fef3c7;color:#92400e;padding:8px 14px;font-size:12px;font-weight:600;
text-align:center;border-bottom:1px solid #fcd34d;flex-shrink:0}

.talk-notif{background:linear-gradient(135deg,#1e293b,#0f172a);color:#fff;padding:10px 16px;
display:none;align-items:center;gap:10px;border-bottom:1px solid rgba(255,255,255,.1);
flex-shrink:0;font-size:13px;font-weight:600}
.talk-notif.show{display:flex}
.talk-notif .live-dot{width:8px;height:8px;border-radius:50%;background:#10b981;
animation:pulse 1.5s infinite;flex-shrink:0}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.talk-notif .txt{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.talk-notif button{padding:6px 12px;border-radius:8px;border:none;background:#10b981;color:#fff;
font-family:inherit;font-size:12px;font-weight:700;cursor:pointer;flex-shrink:0;transition:.15s}
.talk-notif button:hover{background:#059669}

/* ═══ Messages ═══ */
.messages{flex:1;overflow-y:auto;padding:18px;display:flex;flex-direction:column;gap:10px;
background:#fafbff;scroll-behavior:smooth;-webkit-overflow-scrolling:touch;
overscroll-behavior:contain;min-height:0}
.messages::-webkit-scrollbar{width:8px}
.messages::-webkit-scrollbar-thumb{background:rgba(139,92,246,.25);border-radius:4px}

/* 🆕 هر پیام ستونی: bubble بالا، meta وسط، دکمه حذف زیر */
.msg{display:flex;flex-direction:column;gap:3px;animation:msgIn .28s ease-out}
@keyframes msgIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.msg.mine{align-items:flex-end}
.msg.them{align-items:flex-start}
.msg.system{align-items:center}
.msg.system .bubble{background:#eef0fa;color:#6366f1;font-size:12px;font-weight:600;
padding:6px 14px;border-radius:20px;max-width:80%;text-align:center}
.bubble{max-width:72%;padding:11px 15px;border-radius:18px;line-height:1.7;font-size:14px;
word-wrap:break-word;word-break:break-word}
.msg.them .bubble{background:#fff;border:1px solid var(--line);border-bottom-right-radius:5px;
box-shadow:0 1px 2px rgba(15,23,42,.04)}
.msg.mine .bubble{background:var(--grad);color:#fff;border-bottom-left-radius:5px;
box-shadow:0 4px 14px rgba(139,92,246,.3)}
.meta{font-size:11px;color:var(--muted);margin-top:2px;padding:0 6px}
.msg.mine .meta{text-align:left}
.bubble img{max-width:100%;border-radius:12px;display:block;margin-top:4px;cursor:pointer}
.bubble a{color:inherit;text-decoration:none;display:block}
.link-title{font-weight:600;display:block;margin-bottom:3px}
.url{display:inline-block;font-size:11px;opacity:.85;direction:ltr;unicode-bidi:embed;
word-break:break-all;font-family:'Courier New',monospace}

/* 🆕 دکمه حذف — زیر پیام، برای ادمین همیشه دیده می‌شود */
.msg-del{
    display:none;
    align-items:center;
    gap:4px;
    padding:3px 10px;
    border-radius:8px;
    border:1px solid #fecaca;
    background:#fff5f5;
    color:var(--red);
    font-size:11px;
    font-weight:600;
    cursor:pointer;
    font-family:inherit;
    transition:background .15s,border-color .15s;
    margin-top:2px;
    line-height:1;
    align-self:center;
}
.msg.mine .msg-del{align-self:flex-end}
.msg.them .msg-del{align-self:flex-start}
.msg.system .msg-del{align-self:center}
#messages.admin-mode .msg .msg-del{display:inline-flex}
.msg-del:hover{background:#fee2e2;border-color:#fca5a5}

.composer{padding:12px 14px;border-top:1px solid var(--line);background:#fff;
display:flex;flex-direction:column;gap:8px;flex-shrink:0}
.search-row,.send-row{display:flex;gap:8px}

/* Talk overlay */
.talk-overlay{position:fixed;inset:0;background:#0b0d1a;z-index:500;display:none;
flex-direction:column;color:#fff;overflow:hidden}
.talk-overlay.show{display:flex}
.talk-top{padding:14px 18px;display:flex;align-items:center;gap:12px;
border-bottom:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.02);flex-shrink:0}
.talk-top h2{flex:1;font-size:16px;font-weight:700;color:#fff;white-space:nowrap;
overflow:hidden;text-overflow:ellipsis}
.talk-badge{background:rgba(16,185,129,.2);color:#34d399;font-size:11px;
padding:4px 10px;border-radius:12px;font-weight:700;border:1px solid rgba(16,185,129,.3);flex-shrink:0}
.talk-collapse{width:40px;height:40px;border-radius:50%;border:1.5px solid rgba(255,255,255,.2);
background:rgba(255,255,255,.05);color:#fff;cursor:pointer;font-size:18px;
display:grid;place-items:center;transition:.2s;flex-shrink:0;font-family:inherit}
.talk-collapse:hover{background:rgba(255,255,255,.15)}
.talk-users{flex:1;overflow-y:auto;padding:20px;display:grid;
grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:14px;
align-content:start;-webkit-overflow-scrolling:touch}
.talk-user{display:flex;flex-direction:column;align-items:center;gap:8px;
padding:14px 8px;background:rgba(255,255,255,.04);border-radius:16px;
border:1px solid rgba(255,255,255,.06);position:relative}
.talk-user.me{border-color:rgba(139,92,246,.4);background:rgba(139,92,246,.1)}
.talk-user.raised{border-color:rgba(245,158,11,.5);background:rgba(245,158,11,.08)}
.talk-avatar{width:60px;height:60px;border-radius:50%;background:var(--grad);color:#fff;
display:grid;place-items:center;font-size:22px;font-weight:700;position:relative}
.talk-avatar.muted::after{content:'🔇';position:absolute;bottom:-4px;left:-4px;
background:#1a1d2e;border-radius:50%;padding:2px;font-size:13px}
.talk-user .name{font-size:13px;font-weight:600;color:#fff;text-align:center;
max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.talk-user .role{font-size:10px;color:#8a8fa8;font-weight:600}
.talk-user .role.admin{color:#fbbf24}
.talk-user .admin-btn{padding:4px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.2);
background:rgba(255,255,255,.05);color:#fff;font-size:11px;cursor:pointer;
font-family:inherit;font-weight:600;transition:.15s}
.talk-user .admin-btn:hover{background:rgba(255,255,255,.15)}
.talk-user .hand-icon{position:absolute;top:8px;right:8px;font-size:18px;animation:shake 1s infinite}
@keyframes shake{0%,100%{transform:rotate(0)}25%{transform:rotate(-15deg)}75%{transform:rotate(15deg)}}
.talk-controls{padding:18px;display:flex;gap:20px;justify-content:center;
background:rgba(0,0,0,.4);border-top:1px solid rgba(255,255,255,.08);flex-wrap:wrap;flex-shrink:0}
.talk-ctrl-group{display:flex;flex-direction:column;align-items:center;gap:6px}
.talk-ctrl{width:56px;height:56px;border-radius:50%;border:none;cursor:pointer;
display:grid;place-items:center;font-size:22px;transition:.2s;color:#fff;font-family:inherit}
.talk-ctrl.mute{background:#ef4444}
.talk-ctrl.mute.on{background:#10b981}
.talk-ctrl.mute.locked{background:#374151;opacity:.5;cursor:not-allowed}
.talk-ctrl.hand{background:#f59e0b}
.talk-ctrl.hand.raised{background:#6366f1}
.talk-ctrl.leave{background:#7f1d1d}
.talk-ctrl:not(.locked):hover{filter:brightness(1.15);transform:scale(1.05)}
.talk-ctrl-label{font-size:10px;color:#8a8fa8;text-align:center}

/* Minimized talk pill */
.talk-mini{position:fixed;bottom:calc(20px + env(safe-area-inset-bottom));
left:50%;transform:translateX(-50%);background:linear-gradient(135deg,#1e293b,#0f172a);
color:#fff;padding:10px 14px;border-radius:30px;display:none;align-items:center;gap:10px;
z-index:600;box-shadow:0 12px 32px rgba(0,0,0,.5);border:1px solid rgba(255,255,255,.12);
max-width:calc(100% - 32px);animation:miniIn .3s ease-out}
.talk-mini.show{display:flex}
@keyframes miniIn{from{opacity:0;transform:translate(-50%,20px)}to{opacity:1;transform:translate(-50%,0)}}
.talk-mini-dot{width:9px;height:9px;border-radius:50%;background:#10b981;
animation:pulse 1.5s infinite;flex-shrink:0}
.talk-mini-txt{font-size:13px;font-weight:600;white-space:nowrap;
overflow:hidden;text-overflow:ellipsis;max-width:180px}
.talk-mini-people{background:rgba(255,255,255,.18);padding:2px 9px;
border-radius:10px;font-size:11px;font-weight:700;flex-shrink:0}
.talk-mini-expand{padding:7px 14px;border-radius:20px;border:none;
background:#10b981;color:#fff;font-family:inherit;font-size:12px;
font-weight:700;cursor:pointer;flex-shrink:0;transition:.15s;white-space:nowrap}
.talk-mini-expand:hover{background:#059669}
@media(max-width:900px){
  .talk-mini{bottom:calc(80px + env(safe-area-inset-bottom));
    max-width:calc(100% - 24px);padding:9px 12px;gap:8px}
  .talk-mini-txt{max-width:110px;font-size:12px}
}

/* Modals */
.modal{position:fixed;inset:0;background:rgba(15,23,42,.65);display:none;place-items:center;
z-index:999;backdrop-filter:blur(6px);padding:20px}
.modal.show{display:grid}
.modal-card{background:var(--card);border-radius:var(--radius);padding:24px;
width:100%;max-width:380px;box-shadow:0 24px 56px rgba(15,23,42,.3);
display:flex;flex-direction:column;gap:14px;animation:slideUp .3s ease-out}
@keyframes slideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.modal-card h3{font-size:17px;font-weight:700;color:var(--text);text-align:center}
.row{display:flex;gap:8px}
.row .btn{flex:1}

.participant{display:flex;align-items:center;gap:8px;padding:6px 0;font-size:13px}
.participant .dot{width:8px;height:8px;border-radius:50%;background:#cbd5e1;flex-shrink:0}
.participant .dot.on{background:var(--green)}
.participant .n{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:500}
.participant .tag{font-size:10px;padding:1px 6px;border-radius:6px;font-weight:700}
.participant .tag.admin{background:#fef3c7;color:#d97706}
.participant .tag.talk{background:#d1fae5;color:#065f46}

.empty{text-align:center;color:var(--muted);padding:40px 20px;font-size:13px}

@media (max-width: 900px) {
    html, body {
        height: auto !important;
        overflow-x: hidden !important;
        overflow-y: auto !important;
        -webkit-overflow-scrolling: touch;
    }
    body {
        display: block !important;
        padding: 0 !important;
        min-height: 100dvh;
    }
    .app {
        display: flex !important;
        flex-direction: column !important;
        height: auto !important;
        min-height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        max-width: 100% !important;
        gap: 0 !important;
    }
    .chat {
        order: 1;
        flex: 0 0 100dvh !important;
        height: 100dvh !important;
        min-height: 100dvh !important;
        border-radius: 0 !important;
        border: none !important;
        box-shadow: none !important;
    }
    .messages {
        flex: 1 !important;
        min-height: 0 !important;
        overflow-y: auto !important;
        overscroll-behavior: auto !important;
        padding: 14px;
        gap: 8px;
    }
    .sidebar {
        order: 2;
        position: static !important;
        width: auto !important;
        max-width: none !important;
        transform: none !important;
        transition: none !important;
        z-index: auto !important;
        border-radius: 16px !important;
        margin: 12px 12px calc(12px + env(safe-area-inset-bottom)) !important;
        border: 1px solid rgba(231,233,245,.8) !important;
        padding: 18px !important;
        box-shadow: var(--shadow) !important;
        max-height: none !important;
        overflow: visible !important;
        overscroll-behavior: auto !important;
    }
    .drawer-overlay { display: none !important; }
    .hamburger { display: none !important; }

    .chat-header { padding: 12px 14px; gap: 8px; }
    .avatar { width: 40px; height: 40px; font-size: 15px; }
    .call-icon { width: 40px; height: 40px; font-size: 17px; }
    .bubble { max-width: 85%; padding: 10px 13px; font-size: 13.5px; }
    .composer { padding: 10px 12px; gap: 6px; }
    .talk-users { padding: 16px; grid-template-columns: repeat(auto-fill, minmax(100px,1fr)); gap: 12px; }
    .talk-avatar { width: 54px; height: 54px; font-size: 20px; }
    .talk-controls { padding: 14px; gap: 14px; }
    .talk-ctrl { width: 52px; height: 52px; font-size: 20px; }
}

@media (min-width: 901px) {
    .drawer-overlay, .hamburger { display: none !important; }
}
</style>
</head>
<body>
<div class="bg-orbs"></div>

<div class="app">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <div class="logo">✦</div>
            <div class="brand-info">
                <h1 id="roomTitle"><?= esc($roomName ?: 'رومینو') ?></h1>
                <p>
                    <span class="badge <?= $roomType ?>"><?= $roomType === 'public' ? 'عمومی' : 'شخصی' ?></span>
                    <code><?= esc($room) ?></code>
                </p>
            </div>
        </div>

        <div class="card" id="nameCard">
            <label class="lbl">نام شما</label>
            <input id="nameInput" type="text" placeholder="مثلا علی" maxlength="32" autocomplete="off">
            <button class="btn primary full" id="enterBtn">ورود به اتاق</button>
        </div>

        <div class="card" id="activeTalkCard" style="display:none;background:linear-gradient(135deg,#ecfdf5,#d1fae5);border-color:#a7f3d0">
            <div class="center">
                <span class="badge" style="background:#10b981;color:#fff">🎙️ گفتگوی عمومی فعال</span>
            </div>
            <div class="small center muted" id="activeTalkInfo"></div>
            <button class="btn success full" id="joinTalkBtn">🎙️ پیوستن به گفتگو</button>
        </div>

        <div class="card" id="publicAdminPanel" style="display:none">
            <label class="lbl">👑 کنترل‌های مدیر</label>
            <button class="btn success full" id="startTalkBtn">🎙️ شروع گفتگوی جدید</button>
            <button class="btn danger full" id="endTalkBtn" style="display:none">⏹ پایان گفتگو</button>
            <button class="btn warn full" id="muteAllBtn" style="display:none">🔇 قطع میکروفن همه</button>
            <button class="btn full" id="lockChatBtn">🔒 قفل چت</button>
            <button class="btn danger full" id="deleteRoomBtn">🗑 حذف اتاق</button>
        </div>

        <div class="card" id="adminLinkCard" style="display:none">
            <label class="lbl">🔗 لینک اتاق</label>
            <div class="linkbox">
                <input id="shareInput" type="text" readonly value="<?= esc($shareUrl) ?>">
                <button class="btn sm" id="copyBtn">کپی</button>
            </div>
        </div>

        <div class="card" id="personalCallCard" style="display:none">
            <label class="lbl">📞 تماس صوتی</label>
            <button class="btn success full" id="callBtn" disabled>شروع تماس</button>
            <button class="btn danger full" id="hangBtn" style="display:none">قطع تماس</button>
            <div id="callStatus" class="muted small center"></div>
        </div>

        <div class="card">
            <label class="lbl">👥 حاضرین</label>
            <div id="participantsList" class="small"></div>
        </div>

        <button class="btn link full" id="adminLoginLink" style="display:none">🔑 ورود ادمین</button>
    </aside>

    <main class="chat">
        <header class="chat-header">
            <div class="avatar" id="avatar">?</div>
            <div class="chat-info">
                <div class="name" id="peerName">در انتظار طرف مقابل…</div>
                <div class="state" id="connState">نام خود را وارد کنید</div>
            </div>
            <button class="call-icon" id="callIconBtn" style="display:none" disabled>📞</button>
            <button class="hamburger" id="hamburgerBtn">☰</button>
        </header>

        <div class="talk-notif" id="talkNotif">
            <span class="live-dot"></span>
            <span class="txt" id="talkNotifTxt">گفتگوی فعال</span>
            <button id="talkNotifJoin">پیوستن</button>
        </div>

        <div class="lock-banner" id="lockBanner" style="display:none">🔒 چت توسط مدیر قفل شده است</div>

        <div class="messages" id="messages">
            <div class="empty" id="emptyState">هنوز پیامی رد و بدل نشده — سلام کن! 👋</div>
        </div>

        <form class="composer" id="composer">
            <div class="search-row" id="toolRow">
                <input id="searchInput" type="text" placeholder="جستجو در گوگل…" autocomplete="off" disabled>
                <button class="btn ghost" type="button" id="searchBtn" disabled>🔎</button>
                <button class="btn ghost" type="button" id="imgBtn" disabled>🖼</button>
                <input id="imgInput" type="file" accept="image/*" style="display:none">
            </div>
            <div class="send-row">
                <input id="msgInput" type="text" placeholder="پیام خود را بنویسید…" autocomplete="off" disabled>
                <button class="btn primary" type="submit" disabled id="sendBtn">ارسال</button>
            </div>
        </form>
    </main>
</div>

<div class="drawer-overlay" id="drawerOverlay"></div>

<div class="talk-mini" id="talkMini">
    <span class="talk-mini-dot"></span>
    <span class="talk-mini-txt" id="talkMiniTxt">گفتگو</span>
    <span class="talk-mini-people" id="talkMiniPeople">0</span>
    <button class="talk-mini-expand" id="talkMiniExpand">⬆ باز کردن</button>
</div>

<div class="talk-overlay" id="talkOverlay">
    <div class="talk-top">
        <span class="talk-badge" id="talkBadge">🔴 زنده</span>
        <h2 id="talkTitle">گفتگوی عمومی</h2>
        <button class="talk-collapse" id="talkCollapseBtn" title="کوچک کردن">⬇</button>
    </div>
    <div class="talk-users" id="talkUsers"></div>
    <div class="talk-controls">
        <div class="talk-ctrl-group">
            <button class="talk-ctrl mute" id="micToggle" title="میکروفن">🎙️</button>
            <div class="talk-ctrl-label">میکروفن</div>
        </div>
        <div class="talk-ctrl-group">
            <button class="talk-ctrl hand" id="handToggle" title="درخواست صحبت">✋</button>
            <div class="talk-ctrl-label">درخواست</div>
        </div>
        <div class="talk-ctrl-group">
            <button class="talk-ctrl leave" id="talkLeaveBtn" title="خروج">🚪</button>
            <div class="talk-ctrl-label">خروج</div>
        </div>
    </div>
</div>

<div class="modal" id="incoming">
    <div class="modal-card" style="text-align:center;align-items:center">
        <div style="font-size:52px">📞</div>
        <h3>تماس ورودی</h3>
        <p class="muted small" id="incomingFrom"></p>
        <div class="row">
            <button class="btn success" id="acceptBtn">پاسخ</button>
            <button class="btn danger" id="rejectBtn">رد</button>
        </div>
    </div>
</div>

<div class="modal" id="adminLoginModal">
    <div class="modal-card">
        <h3>🔑 ورود ادمین</h3>
        <p class="muted small center">رمز ادمین اتاق را وارد کنید</p>
        <input id="adminPassInput" type="password" placeholder="رمز ادمین" autocomplete="off">
        <div class="row">
            <button class="btn ghost" id="adminLoginCancel">انصراف</button>
            <button class="btn warn" id="adminLoginSubmit">ورود</button>
        </div>
    </div>
</div>

<div class="modal" id="nameGate">
    <div class="modal-card" style="text-align:center;align-items:center">
        <div style="font-size:44px">👋</div>
        <h3>خوش آمدید</h3>
        <p class="muted small">نام خود را وارد کنید</p>
        <input id="gateNameInput" type="text" placeholder="مثلا علی" maxlength="32" autocomplete="off">
        <button class="btn primary full" id="gateEnterBtn">ورود به اتاق</button>
    </div>
</div>

<audio id="remoteAudio" autoplay playsinline></audio>

<script>
window.ROOM_CONFIG = {
    room: <?= json_encode($room) ?>,
    roomType: <?= json_encode($roomType) ?>,
    roomName: <?= json_encode($roomName) ?>,
    clientId: (function(){
        let c = localStorage.getItem('chat_client_id');
        if (!c) {
            c = 'c_' + Math.random().toString(36).slice(2,10) + Date.now().toString(36);
            localStorage.setItem('chat_client_id', c);
        }
        return c;
    })()
};
</script>
<script src="app.js?v=<?= (int)$appJsVer ?>"></script>
</body>
</html>
<?php
require __DIR__ . '/config.php';
$creationEnabled = get_setting('room_creation_enabled', '1') === '1';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<title>رومینو — چت و تماس صوتی</title>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#6366f1">
<style>
@import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap');
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#eef1f8;--card:#fff;--text:#1a1d2e;--text2:#555a7a;--muted:#8a8fa8;--line:#e7e9f5;
--grad:linear-gradient(135deg,#6366f1 0%,#a855f7 50%,#ec4899 100%);
--radius:24px;--shadow:0 8px 16px -4px rgba(15,23,42,.08),0 24px 56px rgba(15,23,42,.12)}
html,body{height:100%}
body{font-family:'Vazirmatn',system-ui,sans-serif;background:var(--bg);color:var(--text);
direction:rtl;font-size:14px;line-height:1.6;min-height:100vh;min-height:100dvh;
display:grid;place-items:center;padding:20px;overflow-y:auto}
.bg-orbs{position:fixed;inset:0;overflow:hidden;z-index:0;pointer-events:none}
.bg-orbs::before,.bg-orbs::after{content:'';position:absolute;border-radius:50%;filter:blur(90px);opacity:.55}
.bg-orbs::before{width:520px;height:520px;background:radial-gradient(circle,#a5b4fc,transparent 70%);top:-160px;right:-160px}
.bg-orbs::after{width:520px;height:520px;background:radial-gradient(circle,#f0abfc,transparent 70%);bottom:-160px;left:-160px}
.wrap{position:relative;z-index:1;width:100%;max-width:720px;display:flex;flex-direction:column;gap:24px;align-items:center}
.hero{text-align:center}
.logo{width:88px;height:88px;border-radius:26px;background:var(--grad);color:#fff;display:grid;
place-items:center;font-size:44px;margin:0 auto 18px;box-shadow:0 14px 40px rgba(139,92,246,.45);
animation:float 4s ease-in-out infinite}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
h1{font-size:44px;font-weight:800;background:var(--grad);-webkit-background-clip:text;
background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:-.5px;line-height:1.2}
.tagline{font-size:15px;color:var(--muted);margin-top:8px;max-width:420px;margin-inline:auto}
.disabled-banner{background:#fef3c7;border:1px solid #fcd34d;color:#92400e;
padding:12px 18px;border-radius:14px;font-size:13px;font-weight:600;text-align:center;
width:100%;max-width:520px}
.cards{display:grid;grid-template-columns:1fr 1fr;gap:16px;width:100%}
@media(max-width:640px){.cards{grid-template-columns:1fr}}
.type-card{background:var(--card);border-radius:var(--radius);padding:28px 22px;border:1px solid rgba(231,233,245,.8);
box-shadow:var(--shadow);cursor:pointer;transition:.25s;text-align:center;display:flex;flex-direction:column;
align-items:center;gap:12px;position:relative}
.type-card:hover{transform:translateY(-4px);box-shadow:0 16px 40px rgba(15,23,42,.12);border-color:#c7b9f5}
.type-card.disabled{opacity:.5;cursor:not-allowed;pointer-events:none;filter:grayscale(.6)}
.type-icon{width:64px;height:64px;border-radius:18px;display:grid;place-items:center;font-size:30px;color:#fff}
.type-card.personal .type-icon{background:linear-gradient(135deg,#6366f1,#8b5cf6)}
.type-card.public .type-icon{background:linear-gradient(135deg,#f59e0b,#ec4899)}
.type-card h2{font-size:19px;font-weight:700}
.type-card p{font-size:13px;color:var(--muted);line-height:1.7}
.type-card .feats{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:6px}
.feat{font-size:11px;background:#eef0fa;color:#6366f1;padding:3px 10px;border-radius:20px;font-weight:600}
.type-card.public .feat{background:#fef3c7;color:#d97706}
.modal{position:fixed;inset:0;background:rgba(15,23,42,.65);display:none;place-items:center;z-index:999;
backdrop-filter:blur(6px);padding:20px}
.modal.show{display:grid}
.modal-card{background:var(--card);border-radius:var(--radius);padding:28px;width:100%;max-width:420px;
box-shadow:0 24px 56px rgba(15,23,42,.3);display:flex;flex-direction:column;gap:16px;animation:slideUp .4s ease-out}
@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.modal-card h3{font-size:19px;font-weight:700}
.modal-card .lbl{font-size:12px;color:var(--text2);font-weight:600;margin-bottom:4px;display:block}
.modal-card input{width:100%;padding:12px 14px;border:1.5px solid var(--line);border-radius:12px;
font-family:inherit;font-size:14px;outline:none;transition:.2s}
.modal-card input:focus{border-color:#a855f7;box-shadow:0 0 0 4px rgba(168,85,247,.12)}
.btn{padding:13px 20px;border-radius:12px;border:none;background:var(--grad);color:#fff;
font-family:inherit;font-size:15px;font-weight:700;cursor:pointer;transition:.2s;
box-shadow:0 6px 20px rgba(139,92,246,.35)}
.btn:hover{filter:brightness(1.08);transform:translateY(-1px)}
.btn.ghost{background:#f7f8fc;color:var(--text);border:1.5px solid var(--line);box-shadow:none}
.btn.ghost:hover{background:#eef0fa}
.row{display:flex;gap:10px}
.row .btn{flex:1}
.hint{font-size:12px;color:var(--muted);line-height:1.6}
</style>
</head>
<body>
<div class="bg-orbs"></div>
<div class="wrap">
  <div class="hero">
    <div class="logo">✦</div>
    <h1>رومینو</h1>
    <p class="tagline">چت خصوصی، گروهی و تماس صوتی — سریع، امن، بدون ثبت‌نام</p>
  </div>

  <?php if (!$creationEnabled): ?>
    <div class="disabled-banner">⚠️ ساخت اتاق جدید موقتاً توسط مدیر غیرفعال شده است</div>
  <?php endif; ?>

  <div class="cards">
    <div class="type-card personal <?= !$creationEnabled ? 'disabled' : '' ?>" data-type="personal">
      <div class="type-icon">💬</div>
      <h2>اتاق شخصی</h2>
      <p>چت دو نفره + تماس صوتی مستقیم</p>
      <div class="feats"><span class="feat">۲ نفره</span><span class="feat">تماس صوتی</span><span class="feat">جستجو</span></div>
    </div>
    <div class="type-card public <?= !$creationEnabled ? 'disabled' : '' ?>" data-type="public">
      <div class="type-icon">🎙️</div>
      <h2>اتاق عمومی</h2>
      <p>چت گروهی + گفتگوی صوتی با مدیریت</p>
      <div class="feats"><span class="feat">مدیر</span><span class="feat">گفتگوی گروهی</span><span class="feat">تصویر</span></div>
    </div>
  </div>
</div>

<div class="modal" id="createModal">
  <div class="modal-card">
    <h3 id="modalTitle">ساخت اتاق</h3>
    <div id="nameWrap" style="display:none">
      <label class="lbl">نام اتاق</label>
      <input id="roomName" placeholder="مثلا گفتگوی دوستان" maxlength="60">
    </div>
    <div id="passWrap" style="display:none">
      <label class="lbl">رمز ادمین (اختیاری)</label>
      <input id="roomPass" type="password" placeholder="حداقل ۳ کاراکتر" maxlength="60">
      <p class="hint">با این رمز، دیگران هم می‌توانند مدیر شوند.</p>
    </div>
    <div class="row">
      <button class="btn ghost" id="cancelBtn">انصراف</button>
      <button class="btn" id="createBtn">ساخت اتاق</button>
    </div>
  </div>
</div>

<script>
window.CREATION_ENABLED = <?= $creationEnabled ? 'true' : 'false' ?>;

function getClientId() {
  let cid = localStorage.getItem('chat_client_id');
  if (!cid) {
    cid = 'c_' + Math.random().toString(36).slice(2,10) + Date.now().toString(36);
    localStorage.setItem('chat_client_id', cid);
  }
  return cid;
}
const CLIENT_ID = getClientId();
let selectedType = null;

document.querySelectorAll('.type-card').forEach(card => {
  card.addEventListener('click', () => {
    if (!window.CREATION_ENABLED) {
      alert('ساخت اتاق جدید موقتاً توسط مدیر غیرفعال شده است');
      return;
    }
    selectedType = card.dataset.type;
    document.getElementById('modalTitle').textContent = 
      selectedType === 'personal' ? 'ساخت اتاق شخصی' : 'ساخت اتاق عمومی';
    document.getElementById('nameWrap').style.display = selectedType === 'public' ? 'block' : 'none';
    document.getElementById('passWrap').style.display = selectedType === 'public' ? 'block' : 'none';
    document.getElementById('createModal').classList.add('show');
    if (selectedType === 'public') setTimeout(() => document.getElementById('roomName').focus(), 100);
  });
});

document.getElementById('cancelBtn').addEventListener('click', () => {
  document.getElementById('createModal').classList.remove('show');
});

document.getElementById('createBtn').addEventListener('click', async () => {
  const btn = document.getElementById('createBtn');
  btn.disabled = true;
  btn.textContent = 'در حال ساخت…';
  try {
    const data = { action: 'room-create', type: selectedType, client_id: CLIENT_ID };
    if (selectedType === 'public') {
      data.name = document.getElementById('roomName').value.trim();
      data.password = document.getElementById('roomPass').value;
    }
    const r = await fetch('api.php', { method: 'POST', body: new URLSearchParams(data) });
    const j = await r.json();
    if (j.ok) {
      window.location.href = 'room.php?room=' + j.room;
    } else {
      alert('خطا: ' + (j.error || 'نامشخص'));
      btn.disabled = false;
      btn.textContent = 'ساخت اتاق';
    }
  } catch (e) {
    alert('خطای شبکه: ' + e.message);
    btn.disabled = false;
    btn.textContent = 'ساخت اتاق';
  }
});
</script>
</body>
</html>
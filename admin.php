<?php
require __DIR__ . '/config.php';

const SUPER_ADMIN_PASS = '5879';

if (isset($_GET['logout'])) {
    unset($_SESSION['super_admin']);
    header('Location: admin.php'); exit;
}

$loginError = '';
$isLoggedIn = !empty($_SESSION['super_admin']);

if (($_POST['action'] ?? '') === 'login') {
    if (hash_equals(SUPER_ADMIN_PASS, (string)($_POST['password'] ?? ''))) {
        $_SESSION['super_admin'] = true;
        header('Location: admin.php'); exit;
    }
    $loginError = 'رمز اشتباه است';
}

if ($isLoggedIn) {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete-room') {
        $r = clean($_POST['room'] ?? '', 64);
        if ($r !== '') {
            db()->prepare("DELETE FROM messages WHERE room = ?")->execute([$r]);
            db()->prepare("DELETE FROM presence WHERE room = ?")->execute([$r]);
            db()->prepare("DELETE FROM talks WHERE room = ?")->execute([$r]);
            db()->prepare("DELETE FROM admins WHERE room = ?")->execute([$r]);
            db()->prepare("DELETE FROM rooms WHERE room = ?")->execute([$r]);
        }
        header('Location: admin.php'); exit;
    }
    if ($action === 'toggle-creation') {
        $enabled = (($_POST['enabled'] ?? '0') === '1') ? '1' : '0';
        set_setting('room_creation_enabled', $enabled);
        header('Location: admin.php'); exit;
    }
}

$creationEnabled = get_setting('room_creation_enabled', '1') === '1';

$rooms = [];
$totalMessages = 0;
$totalRooms = 0;
$onlineUsers = 0;
$viewData = null;
$viewMessages = [];

if ($isLoggedIn) {
    $now = time() - 30;
    $stmt = db()->prepare(
        "SELECT r.room, r.type, r.name, r.owner_client_id, r.created_at, r.chat_locked,
            (SELECT COUNT(*) FROM messages m WHERE m.room = r.room) AS msg_count,
            (SELECT COUNT(*) FROM presence p WHERE p.room = r.room AND p.last_seen > ?) AS online_count
         FROM rooms r ORDER BY r.created_at DESC"
    );
    $stmt->execute([$now]);
    $rooms = $stmt->fetchAll();
    $totalRooms = count($rooms);
    $totalMessages = (int)db()->query("SELECT COUNT(*) FROM messages")->fetchColumn();
    $onlineUsers = (int)db()->query("SELECT COUNT(*) FROM presence WHERE last_seen > " . (int)(time() - 30))->fetchColumn();

    $viewRoom = clean($_GET['view'] ?? '', 64);
    if ($viewRoom !== '') {
        $stmt = db()->prepare("SELECT * FROM rooms WHERE room = ?");
        $stmt->execute([$viewRoom]);
        $viewData = $stmt->fetch();
        if ($viewData) {
            $stmt = db()->prepare("SELECT * FROM messages WHERE room = ? ORDER BY id DESC LIMIT 100");
            $stmt->execute([$viewRoom]);
            $viewMessages = array_reverse($stmt->fetchAll());
        }
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<title>پنل مدیریت — رومینو</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
@import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap');
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#eef1f8;--card:#fff;--text:#1a1d2e;--text2:#555a7a;--muted:#8a8fa8;--line:#e7e9f5;
--grad:linear-gradient(135deg,#6366f1 0%,#a855f7 50%,#ec4899 100%);
--green:#10b981;--red:#ef4444;--amber:#f59e0b;--radius:20px;
--shadow:0 4px 6px -1px rgba(15,23,42,.06),0 12px 32px rgba(15,23,42,.08)}
html,body{height:100%}
body{font-family:'Vazirmatn',system-ui,sans-serif;background:var(--bg);color:var(--text);
direction:rtl;font-size:14px;line-height:1.6;min-height:100vh;padding:24px}
.wrap{max-width:1180px;margin:0 auto}
.card{background:var(--card);border-radius:var(--radius);padding:22px;
border:1px solid rgba(231,233,245,.8);box-shadow:var(--shadow);margin-bottom:18px}
h1{font-size:24px;font-weight:800;margin-bottom:6px}
h2{font-size:16px;font-weight:700;margin-bottom:12px}
h3{font-size:15px;font-weight:700;margin-bottom:10px}
.muted{color:var(--muted)}.small{font-size:12px}
.btn{padding:10px 16px;border-radius:10px;border:1.5px solid var(--line);background:#fff;
color:var(--text);font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;
transition:.18s;display:inline-flex;align-items:center;gap:6px;text-decoration:none}
.btn:hover{background:#f7f8fc;border-color:#d8dbed}
.btn.primary{background:var(--grad);color:#fff;border:none;box-shadow:0 6px 20px rgba(139,92,246,.35)}
.btn.danger{background:var(--red);color:#fff;border:none}
.btn.danger:hover{filter:brightness(1.1)}
.btn.success{background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none}
.btn.sm{padding:6px 12px;font-size:12px;border-radius:8px}
input[type=password]{width:100%;padding:12px 14px;border:1.5px solid var(--line);
border-radius:12px;font-family:inherit;font-size:14px;outline:none;transition:.2s}
input[type=password]:focus{border-color:#a855f7;box-shadow:0 0 0 4px rgba(168,85,247,.12)}

.login-wrap{display:grid;place-items:center;min-height:80vh}
.login-card{max-width:380px;width:100%;text-align:center;display:flex;flex-direction:column;gap:14px}
.login-card .logo{width:72px;height:72px;border-radius:22px;background:var(--grad);color:#fff;
display:grid;place-items:center;font-size:34px;margin:0 auto 6px;box-shadow:0 12px 30px rgba(139,92,246,.4)}
.error{color:var(--red);font-weight:600;font-size:13px}

.topbar{display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap}
.topbar .spacer{flex:1}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:18px}
.stat{background:var(--card);border-radius:16px;padding:18px;border:1px solid var(--line)}
.stat .k{font-size:12px;color:var(--muted);font-weight:600}
.stat .v{font-size:26px;font-weight:800;margin-top:4px}
.stat.green .v{color:var(--green)}
.stat.red .v{color:var(--red)}
.stat.purple .v{background:var(--grad);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}

.toggle-row{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.toggle-row .info{flex:1;min-width:200px}
.toggle-row .info .t{font-weight:700}
.toggle-row .info .s{font-size:12px;color:var(--muted);margin-top:2px}
.switch{position:relative;display:inline-block;width:54px;height:30px;flex-shrink:0}
.switch input{opacity:0;width:0;height:0}
.slider{position:absolute;cursor:pointer;inset:0;background:#cbd5e1;border-radius:30px;transition:.2s}
.slider::before{content:'';position:absolute;height:24px;width:24px;right:3px;top:3px;
background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.switch input:checked + .slider{background:var(--green)}
.switch input:checked + .slider::before{transform:translateX(-24px)}

.rooms-table{width:100%;border-collapse:collapse;margin-top:8px;font-size:13px}
.rooms-table th,.rooms-table td{padding:11px 8px;text-align:right;border-bottom:1px solid var(--line)}
.rooms-table th{font-size:11px;font-weight:700;color:var(--text2);text-transform:uppercase;
letter-spacing:.3px;background:#fafbff}
.rooms-table tr:hover td{background:#fafbff}
.rooms-table code{background:#eef0fa;padding:3px 8px;border-radius:6px;
font-size:11px;color:#6366f1;font-weight:600;direction:ltr;display:inline-block}
.badge{display:inline-block;font-size:10px;padding:3px 9px;border-radius:10px;font-weight:700}
.badge.public{background:#fef3c7;color:#d97706}
.badge.personal{background:#eef0fa;color:#6366f1}
.badge.locked{background:#fee2e2;color:#dc2626}
.badge.online{background:#d1fae5;color:#059669}
.actions{display:flex;gap:6px;justify-content:flex-end}
.empty{text-align:center;color:var(--muted);padding:32px;font-size:13px}

/* View details */
.view-header{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.view-header h2{flex:1;margin:0}
.msgs-list{max-height:520px;overflow-y:auto;display:flex;flex-direction:column;gap:8px;
padding:14px;background:#fafbff;border-radius:14px;border:1px solid var(--line)}
.msg{display:flex;flex-direction:column;gap:3px;padding:8px 12px;background:#fff;
border-radius:12px;border:1px solid var(--line);font-size:13px}
.msg .h{display:flex;gap:8px;align-items:center;font-size:11px;color:var(--muted)}
.msg .h .u{font-weight:700;color:var(--text)}
.msg .c{word-break:break-word;white-space:pre-wrap}
.msg.system{background:#eef0fa;border-color:#c7d2fe}
.msg.system .c{color:#6366f1;font-weight:600;text-align:center;font-size:12px}
</style>
</head>
<body>

<?php if (!$isLoggedIn): ?>
<div class="login-wrap">
    <form class="card login-card" method="POST">
        <div class="logo">🔐</div>
        <h1>پنل مدیریت</h1>
        <p class="muted small">رمز سوپر ادمین را وارد کنید</p>
        <?php if ($loginError): ?><div class="error"><?= esc($loginError) ?></div><?php endif; ?>
        <input type="password" name="password" placeholder="رمز" autocomplete="off" autofocus>
        <input type="hidden" name="action" value="login">
        <button class="btn primary" type="submit" style="width:100%">ورود</button>
    </form>
</div>

<?php else: ?>
<div class="wrap">

    <div class="topbar">
        <div>
            <h1>پنل مدیریت</h1>
            <div class="muted small">مدیریت کامل اتاق‌ها و تنظیمات</div>
        </div>
        <div class="spacer"></div>
        <a class="btn" href="index.php">🏠 صفحه اصلی</a>
        <a class="btn danger" href="admin.php?logout=1">🚪 خروج</a>
    </div>

    <!-- Stats -->
    <div class="stats">
        <div class="stat purple"><div class="k">تعداد اتاق‌ها</div><div class="v"><?= $totalRooms ?></div></div>
        <div class="stat green"><div class="k">کاربران آنلاین</div><div class="v"><?= $onlineUsers ?></div></div>
        <div class="stat"><div class="k">کل پیام‌ها</div><div class="v"><?= $totalMessages ?></div></div>
        <div class="stat <?= $creationEnabled ? 'green' : 'red' ?>">
            <div class="k">وضعیت ساخت اتاق</div>
            <div class="v" style="font-size:18px"><?= $creationEnabled ? 'فعال' : 'غیرفعال' ?></div>
        </div>
    </div>

    <!-- Settings -->
    <div class="card">
        <h2>⚙️ تنظیمات کلی</h2>
        <form method="POST" class="toggle-row">
            <div class="info">
                <div class="t">اجازه ساخت اتاق جدید</div>
                <div class="s">اگر غیرفعال شود، هیچ کاربر جدیدی نمی‌تواند اتاق بسازد. اتاق‌های قبلی همچنان کار می‌کنند.</div>
            </div>
            <label class="switch">
                <input type="checkbox" name="enabled" value="1" <?= $creationEnabled ? 'checked' : '' ?> onchange="this.form.submit()">
                <span class="slider"></span>
            </label>
            <input type="hidden" name="action" value="toggle-creation">
            <input type="hidden" name="enabled" value="0" id="toggleHidden">
            <noscript><button class="btn primary" type="submit">ذخیره</button></noscript>
        </form>
    </div>

    <!-- View details -->
    <?php if ($viewData): ?>
    <div class="card">
        <div class="view-header">
            <h2>👁 مشاهده اتاق <code><?= esc($viewData['room']) ?></code></h2>
            <a class="btn" href="admin.php">← بازگشت</a>
            <form method="POST" onsubmit="return confirm('کل اتاق حذف شود؟')" style="margin:0">
                <input type="hidden" name="action" value="delete-room">
                <input type="hidden" name="room" value="<?= esc($viewData['room']) ?>">
                <button class="btn danger" type="submit">🗑 حذف اتاق</button>
            </form>
        </div>
        <div class="muted small" style="margin-bottom:10px">
            نام: <?= esc($viewData['name'] ?: '—') ?> ·
            نوع: <?= $viewData['type'] === 'public' ? 'عمومی' : 'شخصی' ?> ·
            سازنده: <code><?= esc($viewData['owner_client_id']) ?></code> ·
            ساخته‌شده: <?= esc($viewData['created_at']) ?>
        </div>
        <h3>📝 آخرین ۱۰۰ پیام</h3>
        <div class="msgs-list">
            <?php if (!$viewMessages): ?>
                <div class="empty">هیچ پیامی ثبت نشده</div>
            <?php else: foreach ($viewMessages as $m): ?>
                <div class="msg <?= $m['type'] === 'system' ? 'system' : '' ?>">
                    <?php if ($m['type'] !== 'system'): ?>
                    <div class="h">
                        <span class="u"><?= esc($m['user']) ?></span>
                        <span class="small"><?= esc($m['type']) ?></span>
                        <span class="small" style="margin-inline-start:auto"><?= esc($m['created_at']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="c"><?= esc($m['content']) ?></div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Rooms list -->
    <div class="card">
        <h2>📋 لیست اتاق‌ها</h2>
        <?php if (!$rooms): ?>
            <div class="empty">هیچ اتاقی ساخته نشده است</div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="rooms-table">
            <thead>
                <tr>
                    <th>اتاق</th>
                    <th>نام</th>
                    <th>نوع</th>
                    <th>آنلاین</th>
                    <th>پیام‌ها</th>
                    <th>وضعیت چت</th>
                    <th>تاریخ ساخت</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rooms as $r): ?>
                <tr>
                    <td><code><?= esc($r['room']) ?></code></td>
                    <td><?= esc($r['name'] ?: '—') ?></td>
                    <td><span class="badge <?= $r['type'] ?>"><?= $r['type'] === 'public' ? 'عمومی' : 'شخصی' ?></span></td>
                    <td><?php if ($r['online_count'] > 0): ?><span class="badge online"><?= $r['online_count'] ?> نفر</span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                    <td><?= (int)$r['msg_count'] ?></td>
                    <td><?php if ($r['chat_locked']): ?><span class="badge locked">قفل</span><?php else: ?><span class="badge online">باز</span><?php endif; ?></td>
                    <td class="small"><?= esc($r['created_at']) ?></td>
                    <td>
                        <div class="actions">
                            <a class="btn sm" href="admin.php?view=<?= urlencode($r['room']) ?>">👁 مشاهده</a>
                            <a class="btn sm" href="room.php?room=<?= urlencode($r['room']) ?>" target="_blank">↗ باز کردن</a>
                            <form method="POST" onsubmit="return confirm('اتاق «<?= esc($r['room']) ?>» و همه پیام‌هایش حذف شود؟')" style="display:inline;margin:0">
                                <input type="hidden" name="action" value="delete-room">
                                <input type="hidden" name="room" value="<?= esc($r['room']) ?>">
                                <button class="btn danger sm" type="submit">🗑 حذف</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<script>
// Fix toggle: only submit when checked value changes
(function(){
    const form = document.querySelector('form.toggle-row');
    if (!form) return;
    const cb = form.querySelector('input[type=checkbox][name=enabled]');
    const hidden = form.querySelector('#toggleHidden');
    if (cb && hidden) {
        cb.addEventListener('change', () => {
            hidden.value = cb.checked ? '0' : '0';
            // do nothing special — hidden is always 0; if checkbox is checked it submits 1 too
        });
    }
})();
</script>
<?php endif; ?>
</body>
</html>
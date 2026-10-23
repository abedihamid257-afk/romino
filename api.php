<?php
require __DIR__ . '/config.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$room   = clean($_POST['room'] ?? $_GET['room'] ?? '', 64);
$client_id = clean($_POST['client_id'] ?? $_GET['client_id'] ?? '', 64);

if ($action !== 'room-create' && $room === '') json_out(['ok'=>false,'error'=>'room required'], 400);

try {
    switch ($action) {

        case 'room-create': {
            if (get_setting('room_creation_enabled', '1') !== '1') {
                json_out(['ok'=>false,'error'=>'ساخت اتاق جدید موقتاً توسط مدیر غیرفعال شده است'], 403);
            }
            $type = $_POST['type'] ?? 'personal';
            $name = clean($_POST['name'] ?? '', 60);
            $password = (string)($_POST['password'] ?? '');
            if ($client_id === '') json_out(['ok'=>false,'error'=>'client_id required'], 400);
            if (!in_array($type, ['personal','public'], true)) json_out(['ok'=>false,'error'=>'invalid type'], 400);

            $newRoom = bin2hex(random_bytes(4));
            $hash = '';
            if ($type === 'public' && $password !== '') {
                if (strlen($password) < 3) json_out(['ok'=>false,'error'=>'رمز خیلی کوتاه است (حداقل ۳ کاراکتر)'], 400);
                $hash = password_hash($password, PASSWORD_DEFAULT);
            }

            db()->prepare("INSERT INTO rooms(room,type,name,owner_client_id,admin_password_hash,created_at) VALUES (?,?,?,?,?,?)")
                ->execute([$newRoom, $type, $name, $client_id, $hash, date('c')]);
            db()->prepare("INSERT INTO admins(room, client_id) VALUES (?,?)")
                ->execute([$newRoom, $client_id]);

            json_out(['ok'=>true, 'room'=>$newRoom]);
        }

        case 'admin-login': {
            $password = (string)($_POST['password'] ?? '');
            if ($password === '') json_out(['ok'=>false,'error'=>'رمز را وارد کنید'], 400);
            $stmt = db()->prepare("SELECT admin_password_hash FROM rooms WHERE room = ?");
            $stmt->execute([$room]);
            $row = $stmt->fetch();
            if (!$row || empty($row['admin_password_hash'])) json_out(['ok'=>false,'error'=>'رمز ادمین تنظیم نشده'], 400);
            if (!password_verify($password, $row['admin_password_hash'])) json_out(['ok'=>false,'error'=>'رمز اشتباه است'], 403);
            db()->prepare("INSERT OR IGNORE INTO admins(room, client_id) VALUES (?,?)")
                ->execute([$room, $client_id]);
            json_out(['ok'=>true]);
        }

        case 'send': {
            $user = clean($_POST['user'] ?? '', 32);
            $type = $_POST['type'] ?? 'text';
            $content = (string)($_POST['content'] ?? '');
            $allowed = ['text','image','link','talk-offer','talk-answer','talk-ice','system','call-offer','call-answer','call-ice','call-end'];
            if (!in_array($type, $allowed, true)) json_out(['ok'=>false,'error'=>'invalid type'], 400);
            if ($user === '' || $client_id === '') json_out(['ok'=>false,'error'=>'user and client_id required'], 400);

            if (in_array($type, ['text','image','link'], true)) {
                $stmt = db()->prepare("SELECT chat_locked FROM rooms WHERE room = ?");
                $stmt->execute([$room]);
                $row = $stmt->fetch();
                if ($row && $row['chat_locked'] && !is_admin($room, $client_id)) {
                    json_out(['ok'=>false,'error'=>'چت توسط مدیر قفل شده است'], 403);
                }
            }
            if (strlen($content) > 8000) json_out(['ok'=>false,'error'=>'too long'], 400);

            db()->prepare("INSERT INTO messages(room,user,client_id,type,content,created_at) VALUES (?,?,?,?,?,?)")
                ->execute([$room, $user, $client_id, $type, $content, date('c')]);
            json_out(['ok'=>true, 'id'=>(int)db()->lastInsertId()]);
        }

        case 'upload': {
            $user = clean($_POST['user'] ?? '', 32);
            if ($user === '' || $client_id === '') json_out(['ok'=>false,'error'=>'user required'], 400);

            $stmt = db()->prepare("SELECT chat_locked FROM rooms WHERE room = ?");
            $stmt->execute([$room]);
            $row = $stmt->fetch();
            if ($row && $row['chat_locked'] && !is_admin($room, $client_id)) {
                json_out(['ok'=>false,'error'=>'چت قفل است'], 403);
            }

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK)
                json_out(['ok'=>false,'error'=>'آپلود ناموفق'], 400);
            if ($_FILES['file']['size'] > MAX_IMAGE_SIZE)
                json_out(['ok'=>false,'error'=>'حجم فایل بیش از ۵ مگابایت است'], 400);

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['file']['tmp_name']);
            $map = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
            if (!isset($map[$mime])) json_out(['ok'=>false,'error'=>'فقط تصویر مجاز است'], 400);

            $fname = bin2hex(random_bytes(16)) . '.' . $map[$mime];
            if (!move_uploaded_file($_FILES['file']['tmp_name'], UPLOAD_DIR . '/' . $fname))
                json_out(['ok'=>false,'error'=>'ذخیره فایل ناموفق'], 500);

            $url = UPLOAD_URL . '/' . $fname;
            db()->prepare("INSERT INTO messages(room,user,client_id,type,content,created_at) VALUES (?,?,?,'image',?,?)")
                ->execute([$room, $user, $client_id, $url, date('c')]);
            json_out(['ok'=>true, 'id'=>(int)db()->lastInsertId(), 'url'=>$url]);
        }

        case 'message-delete': {
            if (!is_admin($room, $client_id)) json_out(['ok'=>false,'error'=>'فقط مدیر می‌تواند پیام حذف کند'], 403);
            $msgId = (int)($_POST['msg_id'] ?? 0);
            if ($msgId <= 0) json_out(['ok'=>false,'error'=>'شناسه پیام نامعتبر'], 400);
            $stmt = db()->prepare("UPDATE messages SET deleted = 1 WHERE id = ? AND room = ? AND deleted = 0");
            $stmt->execute([$msgId, $room]);
            json_out(['ok'=>true, 'deleted'=>(int)$stmt->rowCount()]);
        }

        case 'fetch': {
            $since = max(0, (int)($_POST['since'] ?? 0));
            $name = clean($_POST['name'] ?? '', 32);

            $stmt = db()->prepare("SELECT type, name, chat_locked FROM rooms WHERE room = ?");
            $stmt->execute([$room]);
            $room_info = $stmt->fetch();

            if (!$room_info) {
                json_out(['ok'=>true, 'room_exists'=>false, 'messages'=>[], 'peers'=>[], 'is_admin'=>false,
                    'chat_locked'=>false, 'room_type'=>'personal', 'room_name'=>'', 'active_talk'=>null,
                    'has_password'=>false, 'deleted_ids'=>[]]);
            }

            if ($name !== '') {
                $now = time();
                $stmt = db()->prepare("SELECT 1 FROM presence WHERE room = ? AND client_id = ?");
                $stmt->execute([$room, $client_id]);
                if ($stmt->fetch()) {
                    db()->prepare("UPDATE presence SET name = ?, last_seen = ? WHERE room = ? AND client_id = ?")
                        ->execute([$name, $now, $room, $client_id]);
                } else {
                    db()->prepare("INSERT INTO presence(room,client_id,name,in_talk,hand_raised,muted,forced_mute,last_seen) VALUES (?,?,?,0,0,1,0,?)")
                        ->execute([$room, $client_id, $name, $now]);
                }
                db()->prepare("DELETE FROM presence WHERE last_seen < ?")->execute([$now - 60]);
            }

            $stmt = db()->prepare("SELECT id,user,client_id,type,content,created_at FROM messages WHERE room = ? AND id > ? AND deleted = 0 ORDER BY id ASC LIMIT 300");
            $stmt->execute([$room, $since]);
            $messages = $stmt->fetchAll();

            // شناسه پیام‌های حذف‌شده برای همگام‌سازی زنده با کلاینت
            $stmt = db()->prepare("SELECT id FROM messages WHERE room = ? AND deleted = 1");
            $stmt->execute([$room]);
            $deleted_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            $stmt = db()->prepare("SELECT client_id,name,in_talk,hand_raised,muted,forced_mute FROM presence WHERE room = ? AND last_seen > ?");
            $stmt->execute([$room, time() - 30]);
            $peers = $stmt->fetchAll();

            $admins = [];
            $stmt = db()->prepare("SELECT client_id FROM admins WHERE room = ?");
            $stmt->execute([$room]);
            foreach ($stmt->fetchAll() as $a) $admins[$a['client_id']] = true;

            foreach ($peers as &$p) {
                $p['is_admin'] = isset($admins[$p['client_id']]);
                $p['is_me'] = ($p['client_id'] === $client_id);
            }
            unset($p);

            $stmt = db()->prepare("SELECT admin_password_hash FROM rooms WHERE room = ?");
            $stmt->execute([$room]);
            $has_password = !empty($stmt->fetchColumn());

            json_out([
                'ok'=>true,
                'room_exists'=>true,
                'messages'=>$messages,
                'deleted_ids'=>$deleted_ids,
                'peers'=>$peers,
                'is_admin'=>is_admin($room, $client_id),
                'chat_locked'=>(bool)$room_info['chat_locked'],
                'room_type'=>$room_info['type'],
                'room_name'=>$room_info['name'],
                'active_talk'=>active_talk($room),
                'has_password'=>$has_password,
            ]);
        }

        case 'talk-start': {
            if (!is_admin($room, $client_id)) json_out(['ok'=>false,'error'=>'فقط مدیر می‌تواند گفتگو بسازد'], 403);
            $title = clean($_POST['title'] ?? 'گفتگوی عمومی', 80);

            db()->prepare("UPDATE talks SET active = 0, ended_at = ? WHERE room = ? AND active = 1")
                ->execute([date('c'), $room]);
            db()->prepare("INSERT INTO talks(room,title,active,started_at) VALUES (?,?,1,?)")
                ->execute([$room, $title, date('c')]);
            db()->prepare("UPDATE presence SET in_talk = 0, hand_raised = 0, muted = 1, forced_mute = 0 WHERE room = ?")
                ->execute([$room]);
            db()->prepare("INSERT INTO messages(room,user,client_id,type,content,created_at) VALUES (?,'سیستم','','system',?,?)")
                ->execute([$room, 'گفتگوی «'.$title.'» آغاز شد — از منو پیوستن بزنید', date('c')]);

            json_out(['ok'=>true]);
        }

        case 'talk-end': {
            if (!is_admin($room, $client_id)) json_out(['ok'=>false,'error'=>'فقط مدیر'], 403);
            db()->prepare("UPDATE talks SET active = 0, ended_at = ? WHERE room = ? AND active = 1")
                ->execute([date('c'), $room]);
            db()->prepare("UPDATE presence SET in_talk = 0, hand_raised = 0, muted = 1, forced_mute = 0 WHERE room = ?")
                ->execute([$room]);
            db()->prepare("INSERT INTO messages(room,user,client_id,type,content,created_at) VALUES (?,'سیستم','','system',?,?)")
                ->execute([$room, 'گفتگو پایان یافت', date('c')]);
            json_out(['ok'=>true]);
        }

        case 'talk-join': {
            $talk = active_talk($room);
            if (!$talk) json_out(['ok'=>false,'error'=>'گفتگوی فعالی وجود ندارد'], 400);
            db()->prepare("UPDATE presence SET in_talk = 1, muted = 1, forced_mute = 0, hand_raised = 0 WHERE room = ? AND client_id = ?")
                ->execute([$room, $client_id]);
            json_out(['ok'=>true]);
        }

        case 'talk-leave': {
            db()->prepare("UPDATE presence SET in_talk = 0, hand_raised = 0 WHERE room = ? AND client_id = ?")
                ->execute([$room, $client_id]);
            json_out(['ok'=>true]);
        }

        case 'talk-toggle-hand': {
            $stmt = db()->prepare("SELECT hand_raised, in_talk FROM presence WHERE room = ? AND client_id = ?");
            $stmt->execute([$room, $client_id]);
            $row = $stmt->fetch();
            if (!$row || !$row['in_talk']) json_out(['ok'=>false,'error'=>'در گفتگو نیستید'], 400);
            $new = $row['hand_raised'] ? 0 : 1;
            db()->prepare("UPDATE presence SET hand_raised = ? WHERE room = ? AND client_id = ?")
                ->execute([$new, $room, $client_id]);
            json_out(['ok'=>true, 'raised'=>$new]);
        }

        case 'talk-toggle-self-mute': {
            $stmt = db()->prepare("SELECT muted, forced_mute, in_talk FROM presence WHERE room = ? AND client_id = ?");
            $stmt->execute([$room, $client_id]);
            $row = $stmt->fetch();
            if (!$row || !$row['in_talk']) json_out(['ok'=>false,'error'=>'در گفتگو نیستید'], 400);
            if ($row['forced_mute']) json_out(['ok'=>false,'error'=>'میکروفن شما توسط مدیر بسته شده'], 403);
            $new = $row['muted'] ? 0 : 1;
            db()->prepare("UPDATE presence SET muted = ? WHERE room = ? AND client_id = ?")
                ->execute([$new, $room, $client_id]);
            json_out(['ok'=>true, 'muted'=>$new]);
        }

        case 'talk-set-mute': {
            if (!is_admin($room, $client_id)) json_out(['ok'=>false,'error'=>'فقط مدیر'], 403);
            $target = clean($_POST['target'] ?? '', 64);
            $muted = (int)($_POST['muted'] ?? 1);
            db()->prepare("UPDATE presence SET muted = ?, forced_mute = ?, hand_raised = 0 WHERE room = ? AND client_id = ?")
                ->execute([$muted ? 1 : 0, $muted ? 1 : 0, $room, $target]);
            json_out(['ok'=>true]);
        }

        case 'talk-mute-all': {
            if (!is_admin($room, $client_id)) json_out(['ok'=>false,'error'=>'فقط مدیر'], 403);
            $muted = (int)($_POST['muted'] ?? 1);
            db()->prepare("UPDATE presence SET muted = ?, forced_mute = ?, hand_raised = 0 WHERE room = ? AND client_id <> ? AND in_talk = 1")
                ->execute([$muted ? 1 : 0, $muted ? 1 : 0, $room, $client_id]);
            json_out(['ok'=>true]);
        }

        case 'chat-lock': {
            if (!is_admin($room, $client_id)) json_out(['ok'=>false,'error'=>'فقط مدیر'], 403);
            $locked = (int)($_POST['locked'] ?? 1);
            db()->prepare("UPDATE rooms SET chat_locked = ? WHERE room = ?")->execute([$locked, $room]);
            db()->prepare("INSERT INTO messages(room,user,client_id,type,content,created_at) VALUES (?,'سیستم','','system',?,?)")
                ->execute([$room, $locked ? 'چت توسط مدیر قفل شد' : 'چت توسط مدیر باز شد', date('c')]);
            json_out(['ok'=>true]);
        }

        case 'room-delete': {
            if (!is_admin($room, $client_id)) json_out(['ok'=>false,'error'=>'فقط مدیر'], 403);
            db()->prepare("DELETE FROM messages WHERE room = ?")->execute([$room]);
            db()->prepare("DELETE FROM presence WHERE room = ?")->execute([$room]);
            db()->prepare("DELETE FROM talks WHERE room = ?")->execute([$room]);
            db()->prepare("DELETE FROM admins WHERE room = ?")->execute([$room]);
            db()->prepare("DELETE FROM rooms WHERE room = ?")->execute([$room]);
            json_out(['ok'=>true]);
        }

        default:
            json_out(['ok'=>false,'error'=>'unknown action'], 400);
    }
} catch (Exception $e) {
    json_out(['ok'=>false, 'error'=>'Server error: ' . $e->getMessage()], 500);
}
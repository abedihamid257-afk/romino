<?php
session_start();
date_default_timezone_set('Asia/Tehran');
header('X-Content-Type-Options: nosniff');

define('DB_FILE', __DIR__ . '/chat.db');
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('UPLOAD_URL', 'uploads');
define('MAX_IMAGE_SIZE', 5 * 1024 * 1024);

if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');

        $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
            room TEXT PRIMARY KEY,
            type TEXT NOT NULL DEFAULT 'personal',
            name TEXT DEFAULT '',
            owner_client_id TEXT NOT NULL,
            admin_password_hash TEXT DEFAULT '',
            chat_locked INTEGER DEFAULT 0,
            created_at TEXT NOT NULL)");

        $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
            room TEXT NOT NULL,
            client_id TEXT NOT NULL,
            PRIMARY KEY (room, client_id))");

        $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room TEXT NOT NULL,
            user TEXT NOT NULL,
            client_id TEXT NOT NULL DEFAULT '',
            type TEXT NOT NULL DEFAULT 'text',
            content TEXT NOT NULL,
            created_at TEXT NOT NULL,
            deleted INTEGER DEFAULT 0)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_room_id ON messages(room, id)");

        // migration برای دیتابیس‌های قدیمی
        $cols = $pdo->query("PRAGMA table_info(messages)")->fetchAll();
        if (!in_array('deleted', array_column($cols, 'name'), true)) {
            $pdo->exec("ALTER TABLE messages ADD COLUMN deleted INTEGER DEFAULT 0");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS presence (
            room TEXT NOT NULL,
            client_id TEXT NOT NULL,
            name TEXT NOT NULL,
            in_talk INTEGER DEFAULT 0,
            hand_raised INTEGER DEFAULT 0,
            muted INTEGER DEFAULT 1,
            forced_mute INTEGER DEFAULT 0,
            last_seen INTEGER NOT NULL,
            PRIMARY KEY (room, client_id))");

        $cols = $pdo->query("PRAGMA table_info(presence)")->fetchAll();
        if (!in_array('forced_mute', array_column($cols, 'name'), true)) {
            $pdo->exec("ALTER TABLE presence ADD COLUMN forced_mute INTEGER DEFAULT 0");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS talks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room TEXT NOT NULL,
            title TEXT DEFAULT '',
            active INTEGER DEFAULT 1,
            started_at TEXT NOT NULL,
            ended_at TEXT DEFAULT NULL)");

        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            k TEXT PRIMARY KEY,
            v TEXT)");
    }
    return $pdo;
}

function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function clean($s, $max = 2000) {
    $s = trim((string)$s);
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

function esc($s) { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

function is_admin($room, $client_id) {
    if ($client_id === '') return false;
    $stmt = db()->prepare("SELECT 1 FROM admins WHERE room = ? AND client_id = ?");
    $stmt->execute([$room, $client_id]);
    return (bool)$stmt->fetchColumn();
}

function active_talk($room) {
    $stmt = db()->prepare("SELECT * FROM talks WHERE room = ? AND active = 1 ORDER BY id DESC LIMIT 1");
    $stmt->execute([$room]);
    return $stmt->fetch() ?: null;
}

function get_setting($k, $default = null) {
    $stmt = db()->prepare("SELECT v FROM settings WHERE k = ?");
    $stmt->execute([$k]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : $v;
}

function set_setting($k, $v) {
    db()->prepare("INSERT INTO settings(k,v) VALUES (?,?)
        ON CONFLICT(k) DO UPDATE SET v = excluded.v")
        ->execute([$k, (string)$v]);
}
<?php
/** GET /api/notifications/list.php -> { success, unread, items:[{id,type,icon,title,body,url,is_read,ago}] } (solo estudiantes) */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notification_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_role('student');

$pdo = Database::getConnection();
$uid = (int) current_user_id();
notifications_sync_student($pdo, $uid);

$base = (parse_url(APP_URL, PHP_URL_PATH) ?: '');
$items = array_map(fn($n) => [
    'id' => (int) $n['id'], 'type' => $n['type'], 'icon' => notifications_icon($n['type']), 'title' => $n['title'], 'body' => $n['body'],
    'url' => $n['url'] ? $base . '/' . ltrim($n['url'], '/') : null, 'is_read' => (int) $n['is_read'] === 1, 'ago' => notifications_time_ago($n['created_at']),
], notifications_recent($pdo, $uid, 10));

json_response(['success' => true, 'unread' => notifications_unread_count($pdo, $uid), 'items' => $items]);

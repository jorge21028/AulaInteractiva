<?php
/** POST /api/notifications/read.php  { id | all:true, csrf_token } -> { success, unread } */
define('AULA_APP', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notification_helpers.php';

header('Content-Type: application/json; charset=utf-8');
require_role('student');

$raw = json_decode(file_get_contents('php://input'), true);
$input = is_array($raw) ? $raw : $_POST;
csrf_verify($input['csrf_token'] ?? null);

$pdo = Database::getConnection();
$uid = (int) current_user_id();
notifications_mark_read($pdo, $uid, !empty($input['all']) ? null : (int) ($input['id'] ?? 0));

json_response(['success' => true, 'unread' => notifications_unread_count($pdo, $uid)]);

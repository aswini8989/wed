<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$notificationSystem = new NotificationSystem($conn);
$user_id = $_SESSION['user_id'];

if ($_GET['action'] === 'count') {
    echo json_encode([
        'count' => $notificationSystem->getUnreadCount($user_id)
    ]);
} elseif ($_GET['action'] === 'check') {
    echo json_encode([
        'new' => $notificationSystem->checkNew($user_id)
    ]);
}
?>
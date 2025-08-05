<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';
redirectIfNotLoggedIn();

$notificationSystem = new NotificationSystem($conn);
$user_id = $_SESSION['user_id'];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'mark_read') {
        echo json_encode([
            'success' => $notificationSystem->markAsRead($_POST['id'], $user_id)
        ]);
        exit;
    } elseif ($_POST['action'] === 'mark_all_read') {
        echo json_encode([
            'success' => $notificationSystem->markAllAsRead($user_id)
        ]);
        exit;
    }
}

// Get notifications
$notifications = $notificationSystem->getRecent($user_id, 20);
$unread_count = $notificationSystem->getUnreadCount($user_id);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Notifications | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* CSS from previous example remains the same */
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="notification-center">
        <div class="notification-header">
            <h2>Notifications</h2>
            <?php if ($unread_count > 0): ?>
                <button class="mark-all-btn" id="markAllRead">
                    Mark All as Read
                </button>
            <?php endif; ?>
        </div>
        
        <div class="notification-list">
            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <i class="far fa-bell" style="font-size: 40px; margin-bottom: 15px;"></i>
                    <p>No notifications yet</p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                    <div class="notification-item <?= !$notification['is_read'] ? 'unread' : '' ?>" 
                         data-id="<?= $notification['id'] ?>">
                        <div class="actions">
                            <?php if (!$notification['is_read']): ?>
                                <i class="fas fa-check mark-read" title="Mark as read"></i>
                            <?php endif; ?>
                        </div>
                        
                        <h4><?= htmlspecialchars($notification['title']) ?>
                            <?php if (!$notification['is_read']): ?>
                                <span class="notification-dot"></span>
                            <?php endif; ?>
                        </h4>
                        <p><?= htmlspecialchars($notification['message']) ?></p>
                        <small class="time">
                            <?= date('M d, Y h:i A', strtotime($notification['created_at'])) ?>
                        </small>
                        
                        <?php if ($notification['link']): ?>
                            <div style="margin-top: 10px;">
                                <a href="<?= $notification['link'] ?>" class="btn-sm">View</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // JavaScript from previous example remains the same
    </script>
</body>
</html>
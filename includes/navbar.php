<?php if (isset($_SESSION['user_id'])): ?>
    <a href="chat-ui.php" class="nav-link">Messages</a>
    <a href="?action=logout" class="logout-btn">Logout</a>
<?php endif; ?>
<!-- Add to existing navbar -->
<?php if (isset($_SESSION['user_id'])): ?>
    <a href="dashboard.php" class="nav-link">My Orders</a>
<?php endif; ?>
<?php if (isset($_SESSION['user_id'])): ?>
    <a href="notifications-ui.php" class="nav-link">
        <i class="far fa-bell"></i>
        <span id="notificationCounter">
            <?php 
            $notificationSystem = new NotificationSystem($conn);
            $unread = $notificationSystem->getUnreadCount($_SESSION['user_id']);
            echo $unread > 0 ? $unread : '';
            ?>
        </span>
    </a>
<?php endif; ?>
<?php
class NotificationSystem {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    public function create($user_id, $title, $message, $link = null) {
        $stmt = $this->conn->prepare("
            INSERT INTO notifications (user_id, title, message, link)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("isss", $user_id, $title, $message, $link);
        return $stmt->execute();
    }
    
    public function getUnreadCount($user_id) {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE user_id = ? AND is_read = FALSE
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_row()[0];
    }
    
    public function getRecent($user_id, $limit = 5) {
        $stmt = $this->conn->prepare("
            SELECT * FROM notifications 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->bind_param("ii", $user_id, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    public function markAsRead($notification_id, $user_id) {
        $stmt = $this->conn->prepare("
            UPDATE notifications SET is_read = TRUE 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->bind_param("ii", $notification_id, $user_id);
        return $stmt->execute();
    }
    
    public function markAllAsRead($user_id) {
        $stmt = $this->conn->prepare("
            UPDATE notifications SET is_read = TRUE 
            WHERE user_id = ?
        ");
        $stmt->bind_param("i", $user_id);
        return $stmt->execute();
    }
    
    public function checkNew($user_id) {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE user_id = ? AND created_at > COALESCE(
                (SELECT last_notification_check FROM users WHERE id = ?), 
                '1970-01-01'
            )
        ");
        $stmt->bind_param("ii", $user_id, $user_id);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_row()[0];
        
        // Update last check time
        $this->conn->query("
            UPDATE users SET last_notification_check = CURRENT_TIMESTAMP() 
            WHERE id = $user_id
        ");
        
        return $count;
    }
}
?>
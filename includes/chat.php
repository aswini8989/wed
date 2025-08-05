<?php
require_once __DIR__ . '/config.php';

class ChatSystem {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    public function sendMessage($sender_id, $receiver_id, $message, $product_id = null) {
        $stmt = $this->conn->prepare("INSERT INTO messages (sender_id, receiver_id, product_id, message) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiis", $sender_id, $receiver_id, $product_id, $message);
        return $stmt->execute();
    }
    
    public function getConversation($user1_id, $user2_id, $limit = 50) {
        $stmt = $this->conn->prepare("
            SELECT m.*, u.name as sender_name 
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE (sender_id = ? AND receiver_id = ?) 
            OR (sender_id = ? AND receiver_id = ?)
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("iiiii", $user1_id, $user2_id, $user2_id, $user1_id, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    public function getChatList($user_id) {
        $stmt = $this->conn->prepare("
            SELECT 
                u.id as partner_id,
                u.name as partner_name,
                u.role as partner_role,
                MAX(m.created_at) as last_message_time,
                SUM(CASE WHEN m.receiver_id = ? AND m.is_read = FALSE THEN 1 ELSE 0 END) as unread_count
            FROM messages m
            JOIN users u ON (m.sender_id = u.id OR m.receiver_id = u.id) AND u.id != ?
            WHERE ? IN (m.sender_id, m.receiver_id)
            GROUP BY u.id
            ORDER BY last_message_time DESC
        ");
        $stmt->bind_param("iii", $user_id, $user_id, $user_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    public function markAsRead($message_ids) {
        $ids = implode(",", array_map('intval', $message_ids));
        $this->conn->query("UPDATE messages SET is_read = TRUE WHERE id IN ($ids)");
    }
}
?>
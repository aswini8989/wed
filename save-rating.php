<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$order_id = $_POST['order_id'] ?? null;
$rating = $_POST['rating'] ?? null;
$feedback = $_POST['feedback'] ?? null;

if (!$order_id || !$rating) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// Verify order ownership
$order = $conn->query("
    SELECT o.* FROM orders o
    WHERE o.id = $order_id AND o.buyer_id = {$_SESSION['user_id']}
    AND o.status = 'delivered'
")->fetch_assoc();

if (!$order) {
    echo json_encode(['success' => false, 'error' => 'Invalid order']);
    exit;
}

$conn->begin_transaction();
try {
    // Save rating
    $stmt = $conn->prepare("
        UPDATE orders SET 
            customer_rating = ?,
            customer_feedback = ?
        WHERE id = ?
    ");
    $stmt->bind_param("isi", $rating, $feedback, $order_id);
    $stmt->execute();
    
    // Calculate new average rating for agent
    $agent_id = $conn->query("
        SELECT agent_id FROM order_delivery_assignments
        WHERE order_id = $order_id
    ")->fetch_row()[0];
    
    if ($agent_id) {
        $avg_rating = $conn->query("
            SELECT AVG(customer_rating) FROM orders
            WHERE id IN (
                SELECT order_id FROM order_delivery_assignments
                WHERE agent_id = $agent_id
            )
            AND customer_rating IS NOT NULL
        ")->fetch_row()[0];
        
        $conn->query("
            UPDATE delivery_agents SET 
                average_rating = $avg_rating
            WHERE id = $agent_id
        ");
    }
    
    // Notify seller
    $notificationSystem = new NotificationSystem($conn);
    $notificationSystem->create(
        $conn->query("SELECT seller_id FROM products WHERE id = {$order['product_id']}")->fetch_row()[0],
        'New Rating #' . $order_id,
        "Buyer rated delivery: $rating stars",
        "order-tracking.php?id=$order_id"
    );
    
    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
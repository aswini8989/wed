<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$order_id = $_POST['order_id'] ?? null;
$signature = $_POST['signature'] ?? null;

if (!$order_id || !$signature) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// Verify order ownership
$order = $conn->query("
    SELECT o.* FROM orders o
    WHERE o.id = $order_id AND o.buyer_id = {$_SESSION['user_id']}
    AND o.status = 'shipped'
")->fetch_assoc();

if (!$order) {
    echo json_encode(['success' => false, 'error' => 'Invalid order']);
    exit;
}

$conn->begin_transaction();
try {
    // Save signature
    $stmt = $conn->prepare("
        UPDATE orders SET 
            signature_data = ?,
            signature_timestamp = NOW(),
            status = 'delivered',
            delivery_confirmed = TRUE,
            delivery_confirmed_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("si", $signature, $order_id);
    $stmt->execute();
    
    // Add status update
    $conn->query("
        INSERT INTO order_updates (order_id, status, update_text)
        VALUES ($order_id, 'delivered', 'Delivery confirmed with signature')
    ");
    
    // Notify seller
    $notificationSystem = new NotificationSystem($conn);
    $notificationSystem->create(
        $conn->query("SELECT seller_id FROM products WHERE id = {$order['product_id']}")->fetch_row()[0],
        'Delivery Confirmed #' . $order_id,
        "Buyer signed for delivery confirmation",
        "order-tracking.php?id=$order_id"
    );
    
    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
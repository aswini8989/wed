<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';
redirectIfNotLoggedIn();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

$order_id = $_POST['order_id'] ?? null;
if (!$order_id) {
    header("Location: dashboard.php");
    exit;
}

// Verify order ownership and status
$order = $conn->query("
    SELECT o.*, p.seller_id 
    FROM orders o
    JOIN products p ON o.product_id = p.id
    WHERE o.id = $order_id 
    AND o.buyer_id = {$_SESSION['user_id']}
    AND o.status = 'shipped'
    AND o.delivery_confirmed = FALSE
")->fetch_assoc();

if (!$order) {
    header("Location: dashboard.php");
    exit;
}

$conn->begin_transaction();
try {
    // Mark as delivered
    $conn->query("
        UPDATE orders SET 
            status = 'delivered',
            delivery_confirmed = TRUE,
            delivery_confirmed_at = NOW()
        WHERE id = $order_id
    ");
    
    // Add status update
    $conn->query("
        INSERT INTO order_updates (order_id, status, update_text)
        VALUES ($order_id, 'delivered', 'Buyer confirmed delivery and made COD payment')
    ");
    
    // Notify seller
    $notificationSystem = new NotificationSystem($conn);
    $notificationSystem->create(
        $order['seller_id'],
        'Delivery Confirmed #' . $order_id,
        "Buyer confirmed delivery and payment collection",
        "order-tracking.php?id=$order_id"
    );
    
    $conn->commit();
    $_SESSION['success'] = "Delivery confirmed successfully!";
    // After successful delivery confirmation, update metrics
$conn->query("
    INSERT INTO delivery_metrics (
        agent_id, date, deliveries_completed, on_time_deliveries, average_rating
    )
    VALUES (
        {$agent_id}, CURDATE(), 1, 
        " . (strtotime($order['delivery_confirmed_at']) <= strtotime($order['estimated_delivery']) ? 1 : 0) . ",
        NULL
    )
    ON DUPLICATE KEY UPDATE 
        deliveries_completed = deliveries_completed + 1,
        on_time_deliveries = on_time_deliveries + VALUES(on_time_deliveries)
");

// Update agent's overall stats
$conn->query("
    UPDATE delivery_agents SET 
        total_deliveries = total_deliveries + 1,
        on_time_percentage = (
            SELECT (SUM(CASE WHEN o.delivery_confirmed_at <= o.estimated_delivery THEN 1 ELSE 0 END) / COUNT(*)) * 100
            FROM order_delivery_assignments da
            JOIN orders o ON da.order_id = o.id
            WHERE da.agent_id = {$agent_id}
            AND o.status = 'delivered'
        )
    WHERE id = {$agent_id}
");

// Update daily analytics
$conn->query("
    INSERT INTO delivery_analytics (
        metric_date, total_orders, completed_orders, on_time_percentage, average_rating
    )
    VALUES (
        CURDATE(), 1, 1, 
        " . (strtotime($order['delivery_confirmed_at']) <= strtotime($order['estimated_delivery']) ? 100 : 0) . ",
        NULL
    )
    ON DUPLICATE KEY UPDATE 
        completed_orders = completed_orders + 1,
        on_time_percentage = (
            SELECT (SUM(CASE WHEN delivery_confirmed_at <= estimated_delivery THEN 1 ELSE 0 END) / COUNT(*)) * 100
            FROM orders
            WHERE status = 'delivered'
            AND DATE(delivery_confirmed_at) = CURDATE()
        )
");
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = "Failed to confirm delivery: " . $e->getMessage();
}

header("Location: order-tracking.php?id=$order_id");
exit;
?>
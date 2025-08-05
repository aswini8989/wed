<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';
redirectIfNotLoggedIn();

// Only sellers/delivery agents can access
if ($_SESSION['role'] !== 'seller' && $_SESSION['role'] !== 'delivery') {
    header("Location: dashboard.php");
    exit;
}

$order_id = $_GET['order_id'] ?? null;
if (!$order_id) {
    header("Location: dashboard.php");
    exit;
}

// Verify order access
if ($_SESSION['role'] === 'seller') {
    $order = $conn->query("
        SELECT o.* FROM orders o
        JOIN products p ON o.product_id = p.id
        WHERE o.id = $order_id AND p.seller_id = {$_SESSION['user_id']}
    ")->fetch_assoc();
} else {
    $order = $conn->query("
        SELECT o.* FROM orders o
        JOIN order_delivery_assignments da ON da.order_id = o.id
        JOIN delivery_agents ag ON da.agent_id = ag.id
        WHERE o.id = $order_id AND ag.phone = '{$_SESSION['phone']}'
    ")->fetch_assoc();
}

if (!$order) {
    header("Location: dashboard.php");
    exit;
}

// Handle delay notification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = $_POST['reason'];
    $estimated_delay = $_POST['estimated_delay'];
    
    $conn->begin_transaction();
    try {
        // Update order with delay info
        $stmt = $conn->prepare("
            UPDATE orders SET 
                delivery_delay_reason = ?,
                estimated_delivery = DATE_ADD(estimated_delivery, INTERVAL ? HOUR),
                delivery_delay_notified = TRUE
            WHERE id = ?
        ");
        $stmt->bind_param("ssi", $reason, $estimated_delay, $order_id);
        $stmt->execute();
        
        // Add status update
        $update_text = "Delivery delayed: $reason. New estimated time: +$estimated_delay hours";
        $conn->query("
            INSERT INTO order_updates (order_id, status, update_text)
            VALUES ($order_id, 'delayed', '$update_text')
        ");
        
        // Notify buyer
        $notificationSystem = new NotificationSystem($conn);
        $notificationSystem->create(
            $order['buyer_id'],
            'Delivery Delay #' . $order_id,
            "Your delivery is delayed. Reason: $reason. Expected delay: $estimated_delay hours",
            "order-tracking.php?id=$order_id"
        );
        
        $conn->commit();
        $_SESSION['success'] = "Delay notification sent!";
        header("Location: order-tracking.php?id=$order_id");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Failed to notify delay: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Report Delivery Delay | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <h1>Report Delivery Delay</h1>
        <p>Order #<?= $order_id ?></p>
        
        <?php if (isset($error)): ?>
            <div class="alert error"><?= $error ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="input-group">
                <label>Reason for Delay</label>
                <select name="reason" required>
                    <option value="traffic">Traffic Conditions</option>
                    <option value="weather">Bad Weather</option>
                    <option value="vehicle">Vehicle Issue</option>
                    <option value="other">Other</option>
                </select>
            </div>
            
            <div class="input-group">
                <label>Estimated Delay (hours)</label>
                <input type="number" name="estimated_delay" min="1" max="24" required>
            </div>
            
            <div class="input-group">
                <label>Additional Details</label>
                <textarea name="details" rows="3"></textarea>
            </div>
            
            <button type="submit" class="btn-warning">
                <i class="fas fa-exclamation-triangle"></i> Notify Customer
            </button>
        </form>
    </div>
</body>
</html>
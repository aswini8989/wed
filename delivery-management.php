<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';
redirectIfNotLoggedIn();

// Only sellers can access
if ($_SESSION['role'] !== 'seller') {
    header("Location: dashboard.php");
    exit;
}

$order_id = $_GET['order_id'] ?? null;
if (!$order_id) {
    header("Location: seller/dashboard.php");
    exit;
}

// Get order details
$order = $conn->query("
    SELECT o.*, p.name as product_name, u.name as buyer_name,
           (p.price_per_kg * o.quantity_kg) as total_amount
    FROM orders o
    JOIN products p ON o.product_id = p.id
    JOIN users u ON o.buyer_id = u.id
    WHERE o.id = $order_id AND p.seller_id = {$_SESSION['user_id']}
")->fetch_assoc();

// Get delivery attempts
$attempts = $conn->query("
    SELECT * FROM delivery_attempts
    WHERE order_id = $order_id
    ORDER BY attempt_date DESC
")->fetch_all(MYSQLI_ASSOC);

// Handle delivery updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    $conn->begin_transaction();
    
    try {
        $notificationSystem = new NotificationSystem($conn);
        $total = $order['total_amount'];
        
        switch ($action) {
            case 'delivered':
                // Mark as delivered and log payment
                $stmt = $conn->prepare("
                    UPDATE orders SET 
                        status = 'delivered',
                        delivery_attempts = delivery_attempts + 1
                    WHERE id = ?
                ");
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                
                // Log successful attempt
                $conn->query("
                    INSERT INTO delivery_attempts (order_id, status)
                    VALUES ($order_id, 'delivered')
                ");
                
                // Notifications
                $notificationSystem->create(
                    $order['buyer_id'],
                    'Order Delivered #' . $order_id,
                    "Thank you for your payment of ₹$total",
                    "order-tracking.php?id=$order_id"
                );
                $notificationSystem->create(
                    $_SESSION['user_id'],
                    'COD Collected #' . $order_id,
                    "Successfully collected ₹$total from {$order['buyer_name']}",
                    "delivery-management.php?order_id=$order_id"
                );
                break;
                
            case 'failed':
                // Record failed attempt
                $reason = $_POST['reason'];
                $reschedule_date = $_POST['reschedule_date'] ?? null;
                
                $stmt = $conn->prepare("
                    INSERT INTO delivery_attempts 
                    (order_id, status, reason, rescheduled_date)
                    VALUES (?, 'failed', ?, ?)
                ");
                $stmt->bind_param("iss", $order_id, $reason, $reschedule_date);
                $stmt->execute();
                
                // Update order attempts
                $conn->query("
                    UPDATE orders SET 
                        delivery_attempts = delivery_attempts + 1,
                        next_attempt_date = '$reschedule_date'
                    WHERE id = $order_id
                ");
                
                // Notifications
                $notificationSystem->create(
                    $order['buyer_id'],
                    'Delivery Attempt Failed #' . $order_id,
                    "We missed you! Reason: $reason. " . 
                    ($reschedule_date ? "Next attempt: " . date('M d, Y', strtotime($reschedule_date)) : ""),
                    "order-tracking.php?id=$order_id"
                );
                break;
        }
        
        $conn->commit();
        $_SESSION['success'] = "Delivery status updated!";
        header("Location: delivery-management.php?order_id=$order_id");
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Update failed: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Delivery Management | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .delivery-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        }
        .attempt-item {
            padding: 15px;
            border-left: 3px solid #ddd;
            margin-bottom: 10px;
        }
        .attempt-item.failed {
            border-color: #dc3545;
            background: #fff5f5;
        }
        .attempt-item.delivered {
            border-color: #28a745;
            background: #f5fff7;
        }
        .amount-verify {
            font-size: 24px;
            text-align: center;
            margin: 20px 0;
        }
        .verify-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <h1>Delivery Management</h1>
        <h2>Order #<?= $order_id ?> - <?= htmlspecialchars($order['product_name']) ?></h2>
        <p>Customer: <?= htmlspecialchars($order['buyer_name']) ?></p>
        <p class="amount-verify">
            Amount to Collect: <span class="amount-due">₹<?= number_format($order['total_amount'], 2) ?></span>
        </p>
        
        <?php if (isset($error)): ?>
            <div class="alert error"><?= $error ?></div>
        <?php endif; ?>
        
        <div class="delivery-card">
            <h3>Update Delivery Status</h3>
            
            <form method="POST" class="delivery-form">
                <div class="input-group">
                    <label>Action</label>
                    <select name="action" id="actionSelect" required>
                        <option value="delivered">Mark as Delivered (Payment Collected)</option>
                        <option value="failed">Record Failed Attempt</option>
                    </select>
                </div>
                
                <div id="failureFields" style="display:none;">
                    <div class="input-group">
                        <label>Reason for Failure</label>
                        <select name="reason" required>
                            <option value="Customer not available">Customer not available</option>
                            <option value="Wrong address">Wrong address</option>
                            <option value="Payment not ready">Payment not ready</option>
                            <option value="Other">Other (specify below)</option>
                        </select>
                    </div>
                    
                    <div class="input-group">
                        <label>Reschedule Date</label>
                        <input type="date" name="reschedule_date" min="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                
                <button type="submit" class="verify-btn">
                    <i class="fas fa-check-circle"></i> Confirm
                </button>
            </form>
        </div>
        
        <div class="delivery-card">
            <h3>Delivery History</h3>
            
            <?php if (empty($attempts)): ?>
                <p>No delivery attempts recorded yet</p>
            <?php else: ?>
                <?php foreach ($attempts as $attempt): ?>
                    <div class="attempt-item <?= $attempt['status'] ?>">
                        <p><strong><?= ucfirst($attempt['status']) ?></strong> - 
                            <?= date('M d, Y h:i A', strtotime($attempt['attempt_date'])) ?>
                        </p>
                        <?php if ($attempt['reason']): ?>
                            <p>Reason: <?= htmlspecialchars($attempt['reason']) ?></p>
                        <?php endif; ?>
                        <?php if ($attempt['rescheduled_date']): ?>
                            <p>Rescheduled: <?= date('M d, Y', strtotime($attempt['rescheduled_date'])) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Show/hide failure fields
        document.getElementById('actionSelect').addEventListener('change', function() {
            document.getElementById('failureFields').style.display = 
                this.value === 'failed' ? 'block' : 'none';
        });
    </script>
</body>
</html>
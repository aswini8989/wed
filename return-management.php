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

// Verify order ownership
$order = $conn->query("
    SELECT o.*, p.name as product_name, u.name as buyer_name,
           (p.price_per_kg * o.quantity_kg) as total_amount,
           rr.id as return_id, rr.reason, rr.description, rr.status
    FROM orders o
    JOIN products p ON o.product_id = p.id
    JOIN users u ON o.buyer_id = u.id
    LEFT JOIN return_requests rr ON rr.order_id = o.id
    WHERE o.id = $order_id AND p.seller_id = {$_SESSION['user_id']}
")->fetch_assoc();

if (!$order) {
    header("Location: seller/dashboard.php");
    exit;
}

// Handle return status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_status = $_POST['status'];
    $response_note = $_POST['response_note'] ?? '';
    
    $conn->begin_transaction();
    try {
        // Update return status
        $stmt = $conn->prepare("
            UPDATE return_requests 
            SET status = ?, 
                updated_at = NOW(),
                response_note = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ssi", $new_status, $response_note, $order['return_id']);
        $stmt->execute();
        
        // Update order if refunded
        if ($new_status === 'refunded') {
            $conn->query("
                UPDATE orders SET 
                    status = 'refunded'
                WHERE id = $order_id
            ");
        }
        
        // Notify buyer
        $notificationSystem = new NotificationSystem($conn);
        $notificationSystem->create(
            $order['buyer_id'],
            'Return ' . ucfirst($new_status) . ' #' . $order_id,
            "Your return request was $new_status. " . 
            ($response_note ? "Note: $response_note" : ""),
            "order-tracking.php?id=$order_id"
        );
        
        $conn->commit();
        $_SESSION['success'] = "Return request updated!";
        header("Location: return-management.php?order_id=$order_id");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Failed to update return: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Return | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .return-details {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .status-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <h1>Manage Return Request</h1>
        <h2>Order #<?= $order_id ?> - <?= htmlspecialchars($order['product_name']) ?></h2>
        <p>Customer: <?= htmlspecialchars($order['buyer_name']) ?></p>
        <p>Amount: <strong>₹<?= number_format($order['total_amount'], 2) ?></strong></p>
        
        <div class="return-details">
            <h3>Return Details</h3>
            <p><strong>Reason:</strong> <?= ucfirst(str_replace('_', ' ', $order['reason'])) ?></p>
            <?php if ($order['description']): ?>
                <p><strong>Description:</strong> <?= htmlspecialchars($order['description']) ?></p>
            <?php endif; ?>
            <p><strong>Status:</strong> <span class="status-<?= $order['status'] ?>">
                <?= ucfirst($order['status']) ?>
            </span></p>
            <p><small>Requested on: <?= date('M d, Y', strtotime($order['return_request_date'])) ?></small></p>
        </div>
        
        <?php if ($order['status'] === 'requested'): ?>
            <div class="status-form">
                <h3>Update Return Status</h3>
                <?php if (isset($error)): ?>
                    <div class="alert error"><?= $error ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="input-group">
                        <label>New Status</label>
                        <select name="status" required>
                            <option value="approved">Approve Return</option>
                            <option value="rejected">Reject Return</option>
                        </select>
                    </div>
                    
                    <div class="input-group">
                        <label>Response Note</label>
                        <textarea name="response_note" rows="3" 
                                  placeholder="Provide instructions for return or reason for rejection"></textarea>
                    </div>
                    
                    <div class="action-buttons">
                        <button type="submit" name="action" value="approve" class="btn-success">
                            Approve Return
                        </button>
                        <button type="submit" name="action" value="reject" class="btn-danger">
                            Reject Return
                        </button>
                    </div>
                </form>
            </div>
        <?php elseif ($order['status'] === 'approved'): ?>
            <div class="status-form">
                <h3>Process Refund</h3>
                <p>After receiving the returned items, mark as refunded:</p>
                <form method="POST">
                    <input type="hidden" name="status" value="refunded">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-money-bill-wave"></i> Mark as Refunded
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
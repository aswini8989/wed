<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';
redirectIfNotLoggedIn();

$order_id = $_GET['order_id'] ?? null;
if (!$order_id) {
    header("Location: dashboard.php");
    exit;
}

// Verify order ownership
$order = $conn->query("
    SELECT o.*, p.name as product_name, 
           (p.price_per_kg * o.quantity_kg) as total_amount
    FROM orders o
    JOIN products p ON o.product_id = p.id
    WHERE o.id = $order_id AND o.buyer_id = {$_SESSION['user_id']}
    AND o.status = 'delivered'
")->fetch_assoc();

if (!$order) {
    header("Location: dashboard.php");
    exit;
}

// Handle return request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = $_POST['reason'];
    $description = $_POST['description'] ?? '';
    
    $conn->begin_transaction();
    try {
        // Create return request
        $stmt = $conn->prepare("
            INSERT INTO return_requests 
            (order_id, reason, description, status)
            VALUES (?, ?, ?, 'requested')
        ");
        $stmt->bind_param("iss", $order_id, $reason, $description);
        $stmt->execute();
        
        // Update order
        $conn->query("
            UPDATE orders SET 
                return_requested = TRUE,
                return_reason = '$reason',
                return_request_date = NOW()
            WHERE id = $order_id
        ");
        
        // Notify seller
        $notificationSystem = new NotificationSystem($conn);
        $notificationSystem->create(
            $conn->query("SELECT seller_id FROM products WHERE id = {$order['product_id']}")->fetch_row()[0],
            'Return Request #' . $order_id,
            "Buyer requested return for order #$order_id. Reason: " . ucfirst(str_replace('_', ' ', $reason)),
            "return-management.php?order_id=$order_id"
        );
        
        $conn->commit();
        $_SESSION['success'] = "Return request submitted!";
        header("Location: order-tracking.php?id=$order_id");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Failed to submit request: " . $e->getMessage();
    }
}

// Check existing return
$existing_return = $conn->query("
    SELECT * FROM return_requests 
    WHERE order_id = $order_id
")->fetch_assoc();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Request Return | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .return-reasons {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin: 15px 0;
        }
        .reason-option {
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .reason-option:hover {
            border-color: #6c5ce7;
        }
        .reason-option.selected {
            background: #f0f0ff;
            border-color: #6c5ce7;
        }
        .return-status {
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .status-requested {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
        }
        .status-approved {
            background: #d4edda;
            border-left: 4px solid #28a745;
        }
        .status-rejected {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <h1>Request Return/Refund</h1>
        <p>Order #<?= $order_id ?> - <?= htmlspecialchars($order['product_name']) ?></p>
        <p>Amount Paid: <strong>₹<?= number_format($order['total_amount'], 2) ?></strong></p>
        
        <?php if ($existing_return): ?>
            <div class="return-status status-<?= $existing_return['status'] ?>">
                <h3>Return <?= ucfirst($existing_return['status']) ?></h3>
                <p><strong>Reason:</strong> <?= ucfirst(str_replace('_', ' ', $existing_return['reason'])) ?></p>
                <?php if ($existing_return['description']): ?>
                    <p><strong>Details:</strong> <?= htmlspecialchars($existing_return['description']) ?></p>
                <?php endif; ?>
                <p><small>Requested on: <?= date('M d, Y', strtotime($existing_return['created_at'])) ?></small></p>
                
                <?php if ($existing_return['status'] === 'approved'): ?>
                    <div class="alert success">
                        Your refund will be processed within 3-5 business days
                    </div>
                <?php elseif ($existing_return['status'] === 'rejected'): ?>
                    <div class="alert error">
                        Contact support if you disagree with this decision
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php if (isset($error)): ?>
                <div class="alert error"><?= $error ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <h3>Select Reason</h3>
                <div class="return-reasons">
                    <label class="reason-option">
                        <input type="radio" name="reason" value="damaged" required> 
                        Damaged Product
                    </label>
                    <label class="reason-option">
                        <input type="radio" name="reason" value="wrong_item"> 
                        Wrong Item
                    </label>
                    <label class="reason-option">
                        <input type="radio" name="reason" value="quality_issue"> 
                        Quality Issue
                    </label>
                    <label class="reason-option">
                        <input type="radio" name="reason" value="other"> 
                        Other
                    </label>
                </div>
                
                <div class="input-group">
                    <label>Additional Details</label>
                    <textarea name="description" rows="4" placeholder="Please describe the issue..."></textarea>
                </div>
                
                <button type="submit" class="btn-primary">Submit Request</button>
            </form>
            
            <script>
                // Style selected reason
                document.querySelectorAll('.reason-option').forEach(option => {
                    option.addEventListener('click', function() {
                        this.querySelector('input').checked = true;
                        document.querySelectorAll('.reason-option').forEach(o => {
                            o.classList.remove('selected');
                        });
                        this.classList.add('selected');
                    });
                });
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
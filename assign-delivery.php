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
    SELECT o.*, p.name as product_name, u.name as buyer_name
    FROM orders o
    JOIN products p ON o.product_id = p.id
    JOIN users u ON o.buyer_id = u.id
    WHERE o.id = $order_id AND p.seller_id = {$_SESSION['user_id']}
    AND o.status = 'shipped'
")->fetch_assoc();

if (!$order) {
    header("Location: seller/dashboard.php");
    exit;
}

// Get available delivery agents
$agents = $conn->query("
    SELECT * FROM delivery_agents 
    WHERE active = TRUE
    ORDER BY name
")->fetch_all(MYSQLI_ASSOC);

// Handle delivery assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $agent_id = $_POST['agent_id'];
    $contact_name = $_POST['contact_name'];
    $contact_phone = $_POST['contact_phone'];
    
    $conn->begin_transaction();
    try {
        // Update order with delivery info
        $stmt = $conn->prepare("
            UPDATE orders SET 
                delivery_contact_name = ?,
                delivery_contact_phone = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ssi", $contact_name, $contact_phone, $order_id);
        $stmt->execute();
        
        // Create assignment record
        $stmt = $conn->prepare("
            INSERT INTO order_delivery_assignments 
            (order_id, agent_id)
            VALUES (?, ?)
        ");
        $stmt->bind_param("ii", $order_id, $agent_id);
        $stmt->execute();
        
        // Notify buyer
        $notificationSystem = new NotificationSystem($conn);
        $notificationSystem->create(
            $order['buyer_id'],
            'Delivery Agent Assigned #' . $order_id,
            "Your order has been assigned to $contact_name ($contact_phone)",
            "order-tracking.php?id=$order_id"
        );
        
        $conn->commit();
        $_SESSION['success'] = "Delivery agent assigned!";
        header("Location: order-tracking.php?id=$order_id");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Failed to assign agent: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Assign Delivery | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .agent-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .agent-card:hover {
            border-color: #6c5ce7;
            background: #f8f9fa;
        }
        .agent-card.selected {
            border-color: #6c5ce7;
            background: #f0f0ff;
        }
        .agent-phone {
            color: #6c5ce7;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <h1>Assign Delivery Agent</h1>
        <h2>Order #<?= $order_id ?> - <?= htmlspecialchars($order['product_name']) ?></h2>
        <p>Customer: <?= htmlspecialchars($order['buyer_name']) ?></p>
        
        <?php if (isset($error)): ?>
            <div class="alert error"><?= $error ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <h3>Select Delivery Agent</h3>
            <div class="agent-list">
                <?php foreach ($agents as $agent): ?>
                    <label class="agent-card">
                        <input type="radio" name="agent_id" value="<?= $agent['id'] ?>" required>
                        <h4><?= htmlspecialchars($agent['name']) ?></h4>
                        <p class="agent-phone"><?= $agent['phone'] ?></p>
                        <?php if ($agent['vehicle_number']): ?>
                            <p>Vehicle: <?= $agent['vehicle_number'] ?></p>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
            
            <div class="input-group">
                <label>Contact Person Name</label>
                <input type="text" name="contact_name" required>
            </div>
            
            <div class="input-group">
                <label>Contact Phone Number</label>
                <input type="tel" name="contact_phone" required>
            </div>
            
            <button type="submit" class="btn-primary">Assign Agent</button>
        </form>
        
        <script>
            // Style selected agent
            document.querySelectorAll('.agent-card').forEach(card => {
                card.addEventListener('click', function() {
                    this.querySelector('input').checked = true;
                    document.querySelectorAll('.agent-card').forEach(c => {
                        c.classList.remove('selected');
                    });
                    this.classList.add('selected');
                });
            });
        </script>
    </div>
</body>
</html>
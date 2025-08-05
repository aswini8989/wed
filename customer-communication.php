// customer-communication.php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

$order_id = $_GET['order_id'] ?? null;
if (!$order_id) {
    header("Location: dashboard.php");
    exit;
}

// Verify order ownership
$order = $conn->query("
    SELECT o.*, p.name as product_name, 
           u1.name as buyer_name, u2.name as seller_name,
           p.seller_id
    FROM orders o
    JOIN products p ON o.product_id = p.id
    JOIN users u1 ON o.buyer_id = u1.id
    JOIN users u2 ON p.seller_id = u2.id
    WHERE o.id = $order_id AND 
          (o.buyer_id = {$_SESSION['user_id']} OR p.seller_id = {$_SESSION['user_id']})
")->fetch_assoc();

if (!$order) {
    header("Location: dashboard.php");
    exit;
}

// Get all messages
$messages = $conn->query("
    SELECT m.*, u.name as sender_name, u.role as sender_role
    FROM order_messages m
    JOIN users u ON m.sender_id = u.id
    WHERE m.order_id = $order_id
    ORDER BY m.created_at ASC
")->fetch_all(MYSQLI_ASSOC);

// Handle new message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $message = trim($_POST['message']);
    
    if (!empty($message)) {
        $stmt = $conn->prepare("
            INSERT INTO order_messages 
            (order_id, sender_id, message, is_read)
            VALUES (?, ?, ?, 0)
        ");
        $stmt->bind_param("iis", $order_id, $_SESSION['user_id'], $message);
        $stmt->execute();
        
        // Notify the other party
        $notificationSystem = new NotificationSystem($conn);
        $recipient_id = $_SESSION['user_id'] === $order['buyer_id'] 
            ? $order['seller_id'] 
            : $order['buyer_id'];
        
        $notificationSystem->create(
            $recipient_id,
            'New Message #' . $order_id,
            "You have a new message regarding your order",
            "customer-communication.php?order_id=$order_id"
        );
        
        header("Location: customer-communication.php?order_id=$order_id");
        exit;
    }
}

// Mark messages as read
if ($_SESSION['user_id'] === $order['buyer_id']) {
    $conn->query("
        UPDATE order_messages SET is_read = 1
        WHERE order_id = $order_id AND sender_id != {$_SESSION['user_id']}
    ");
} else {
    $conn->query("
        UPDATE order_messages SET is_read = 1
        WHERE order_id = $order_id AND sender_id != {$_SESSION['user_id']}
    ");
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Order Communication | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .message-container {
            max-height: 500px;
            overflow-y: auto;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
        }
        .message {
            margin-bottom: 15px;
            padding: 10px 15px;
            border-radius: 18px;
            max-width: 70%;
            word-wrap: break-word;
        }
        .sent {
            background: #e3f2fd;
            margin-left: auto;
            border-bottom-right-radius: 0;
        }
        .received {
            background: #f1f1f1;
            margin-right: auto;
            border-bottom-left-radius: 0;
        }
        .message-info {
            font-size: 0.8em;
            color: #666;
            margin-top: 5px;
        }
        .unread {
            border-left: 3px solid #6c5ce7;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <h1>Order Communication</h1>
        <h2>Order #<?= $order_id ?> - <?= htmlspecialchars($order['product_name']) ?></h2>
        
        <div class="message-container">
            <?php if (empty($messages)): ?>
                <p class="text-muted">No messages yet. Start the conversation.</p>
            <?php else: ?>
                <?php foreach ($messages as $message): ?>
                    <div class="message <?= $message['sender_id'] === $_SESSION['user_id'] ? 'sent' : 'received' ?> 
                                <?= !$message['is_read'] && $message['sender_id'] != $_SESSION['user_id'] ? 'unread' : '' ?>">
                        <p><?= nl2br(htmlspecialchars($message['message'])) ?></p>
                        <div class="message-info">
                            <?= $message['sender_name'] ?> (<?= ucfirst($message['sender_role']) ?>) • 
                            <?= date('M j, Y h:i A', strtotime($message['created_at'])) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <form method="POST">
            <div class="input-group">
                <label>Your Message</label>
                <textarea name="message" rows="3" required></textarea>
            </div>
            <button type="submit" class="btn-primary">Send Message</button>
        </form>
    </div>
</body>
</html>
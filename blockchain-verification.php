// blockchain-verification.php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

$order_id = $_GET['id'] ?? null;
if (!$order_id) {
    header("Location: dashboard.php");
    exit;
}

// Verify order ownership
$order = $conn->query("
    SELECT o.*, p.name as product_name, 
           u1.name as buyer_name, u2.name as seller_name
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

// Connect to blockchain (mock implementation)
class Blockchain {
    public static function verifyDelivery($order_id) {
        // In production, use Ethereum/Smart Contract
        return [
            'transaction_hash' => '0x' . bin2hex(random_bytes(16)),
            'block_number' => rand(100000, 999999),
            'timestamp' => time(),
            'status' => 'verified',
            'signature' => bin2hex(random_bytes(32))
        ];
    }
    
    public static function recordDelivery($order_data) {
        // Mock recording to blockchain
        return [
            'success' => true,
            'transaction_hash' => '0x' . bin2hex(random_bytes(16))
        ];
    }
}

// Handle blockchain verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_to_blockchain'])) {
    $order_data = [
        'order_id' => $order['id'],
        'buyer' => $order['buyer_name'],
        'seller' => $order['seller_name'],
        'product' => $order['product_name'],
        'delivery_date' => $order['delivery_confirmed_at'],
        'amount' => $order['price_per_kg'] * $order['quantity_kg']
    ];
    
    $result = Blockchain::recordDelivery($order_data);
    
    if ($result['success']) {
        $conn->query("
            UPDATE orders SET 
                blockchain_hash = '{$result['transaction_hash']}',
                blockchain_verified = TRUE
            WHERE id = {$order['id']}
        ");
        $_SESSION['success'] = "Delivery recorded on blockchain!";
        header("Location: blockchain-verification.php?id=$order_id");
        exit;
    } else {
        $error = "Failed to record on blockchain";
    }
}

// Check verification status
$verification = $order['blockchain_verified'] 
    ? Blockchain::verifyDelivery($order['id']) 
    : null;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Blockchain Verification | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .blockchain-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            background: #f8f9fa;
        }
        .verification-badge {
            padding: 5px 10px;
            border-radius: 12px;
            font-weight: bold;
            display: inline-block;
        }
        .verified {
            background: #d4edda;
            color: #155724;
        }
        .unverified {
            background: #fff3cd;
            color: #856404;
        }
        .blockchain-data {
            font-family: monospace;
            word-break: break-all;
            background: #f0f0f0;
            padding: 10px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <h1>Blockchain Delivery Verification</h1>
        <h2>Order #<?= $order_id ?> - <?= htmlspecialchars($order['product_name']) ?></h2>
        
        <div class="blockchain-card">
            <?php if ($order['blockchain_verified']): ?>
                <span class="verification-badge verified">
                    <i class="fas fa-check-circle"></i> Verified on Blockchain
                </span>
                
                <h3>Verification Details</h3>
                <p><strong>Transaction Hash:</strong></p>
                <div class="blockchain-data"><?= $order['blockchain_hash'] ?></div>
                
                <p><strong>Block Number:</strong> <?= $verification['block_number'] ?></p>
                <p><strong>Verification Timestamp:</strong> <?= date('M j, Y H:i:s', $verification['timestamp']) ?></p>
                <p><strong>Digital Signature:</strong></p>
                <div class="blockchain-data"><?= $verification['signature'] ?></div>
            <?php else: ?>
                <span class="verification-badge unverified">
                    <i class="fas fa-exclamation-triangle"></i> Not Recorded on Blockchain
                </span>
                
                <p>This delivery has not been recorded on the blockchain yet.</p>
                
                <?php if ($order['status'] === 'delivered' && ($_SESSION['user_id'] === $order['buyer_id'] || $_SESSION['role'] === 'admin')): ?>
                    <form method="POST" style="margin-top: 20px;">
                        <button type="submit" name="record_to_blockchain" class="btn-primary">
                            <i class="fas fa-link"></i> Record to Blockchain
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <div class="blockchain-info">
            <h3>About Blockchain Verification</h3>
            <p>Recording your delivery on the blockchain provides:</p>
            <ul>
                <li>Immutable proof of delivery</li>
                <li>Tamper-evident transaction records</li>
                <li>Transparent supply chain tracking</li>
                <li>Automated smart contract execution</li>
            </ul>
        </div>
    </div>
</body>
</html>
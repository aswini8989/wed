<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
redirectIfNotLoggedIn();
if ($_SESSION['role'] !== 'buyer') {
    header("Location: ../login.php");
    exit;
}

// Get buyer's orders
$stmt = $conn->prepare("SELECT o.id, p.name, p.price_per_kg, o.quantity_kg, o.status, o.order_date 
                        FROM orders o
                        JOIN products p ON o.product_id = p.id
                        WHERE o.buyer_id = ?
                        ORDER BY o.order_date DESC");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get recommended products
$recommended = $conn->query("SELECT * FROM products ORDER BY RAND() LIMIT 4")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Buyer Dashboard | Elixir Hub</title>
    <link href="../../assets/css/style.css" rel="stylesheet">
    <style>
        .dashboard {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 30px;
        }
        .profile-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            text-align: center;
        }
        .profile-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6c5ce7, #a29bfe);
            margin: 0 auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 40px;
            font-weight: bold;
            animation: pulse 2s infinite;
        }
        .order-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        .order-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
        }
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        .status-shipped {
            background: #cce5ff;
            color: #004085;
        }
        .status-delivered {
            background: #d4edda;
            color: #155724;
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        .recommended-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <?php include '../../includes/navbar.php'; ?>
    
    <div class="container dashboard">
        <!-- Profile Section -->
        <div class="profile-card">
            <div class="profile-circle">
                <?= strtoupper(substr($_SESSION['name'], 0, 1)) ?>
            </div>
            <h3><?= htmlspecialchars($_SESSION['name']) ?></h3>
            <p>Buyer Account</p>
            <a href="?action=logout" class="logout-btn">Logout</a>
            
            <div class="stats">
                <h4>Purchase History</h4>
                <p>Total Orders: <?= count($orders) ?></p>
                <p>Last Order: 
                    <?= !empty($orders) ? date('M d, Y', strtotime($orders[0]['order_date'])) : 'None' ?>
                </p>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <h2>Your Orders</h2>
            
            <?php if (empty($orders)): ?>
                <div class="alert">You haven't placed any orders yet.</div>
            <?php else: ?>
                <?php foreach ($orders as $order): ?>
                    <div class="order-card">
                        <div class="flex justify-between">
                            <h3><?= htmlspecialchars($order['name']) ?></h3>
                            <span class="status status-<?= $order['status'] ?>">
                                <?= ucfirst($order['status']) ?>
                            </span>
                        </div>
                        <p>Quantity: <?= $order['quantity_kg'] ?> kg</p>
                        <p>Price: ₹<?= number_format($order['price_per_kg'] * $order['quantity_kg'], 2) ?></p>
                        <p class="text-sm text-gray-500">
                            Ordered on: <?= date('M d, Y', strtotime($order['order_date'])) ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <h2>Recommended For You</h2>
            <div class="recommended-grid">
                <?php foreach ($recommended as $product): ?>
                    <div class="product-card">
                        <h3><?= htmlspecialchars($product['name']) ?></h3>
                        <p>₹<?= number_format($product['price_per_kg'], 2) ?>/kg</p>
                        <button class="btn-sm">Add to Cart</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</body>
</html>
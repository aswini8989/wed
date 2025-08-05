<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
redirectIfNotLoggedIn();
if ($_SESSION['role'] !== 'seller') {
    header("Location: ../login.php");
    exit;
}

// Get seller's products and sales data
$seller_id = $_SESSION['user_id'];

// Monthly sales
$monthly_sales = $conn->query("
    SELECT 
        DATE_FORMAT(o.order_date, '%Y-%m') AS month,
        SUM(p.price_per_kg * o.quantity_kg) AS total_sales,
        COUNT(o.id) AS order_count
    FROM orders o
    JOIN products p ON o.product_id = p.id
    WHERE p.seller_id = $seller_id
    GROUP BY DATE_FORMAT(o.order_date, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

// Recent orders
$recent_orders = $conn->query("
    SELECT o.id, p.name, o.quantity_kg, o.status, o.order_date, u.name AS buyer_name
    FROM orders o
    JOIN products p ON o.product_id = p.id
    JOIN users u ON o.buyer_id = u.id
    WHERE p.seller_id = $seller_id
    ORDER BY o.order_date DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Top buyers
$top_buyers = $conn->query("
    SELECT 
        u.name,
        SUM(o.quantity_kg) AS total_kg,
        COUNT(o.id) AS order_count
    FROM orders o
    JOIN users u ON o.buyer_id = u.id
    JOIN products p ON o.product_id = p.id
    WHERE p.seller_id = $seller_id
    GROUP BY u.id
    ORDER BY total_kg DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Seller Dashboard | Elixir Hub</title>
    <link href="../../assets/css/style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .dashboard-grid {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 30px;
        }
        .profile-section {
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
            background: linear-gradient(135deg, #00b894, #55efc4);
            margin: 0 auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 40px;
            font-weight: bold;
            animation: pulse 2s infinite;
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #00b894;
        }
        .buyer-card {
            display: flex;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .buyer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #a29bfe;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-weight: bold;
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php include '../../includes/navbar.php'; ?>
    
    <div class="container dashboard-grid">
        <!-- Profile Section -->
        <div class="profile-section">
            <div class="profile-circle">
                <?= strtoupper(substr($_SESSION['name'], 0, 1)) ?>
            </div>
            <h3><?= htmlspecialchars($_SESSION['name']) ?></h3>
            <p>Seller Account</p>
            <a href="?action=logout" class="logout-btn">Logout</a>
            
            <div class="stats">
                <h4>This Month</h4>
                <?php 
                $current_month = date('Y-m');
                $monthly_data = array_filter($monthly_sales, fn($m) => $m['month'] === $current_month);
                $current_sales = $monthly_data ? $monthly_data[0]['total_sales'] : 0;
                ?>
                <p class="stat-value">₹<?= number_format($current_sales, 2) ?></p>
                <p>Total Sales</p>
                
                <?php 
                $total_products = $conn->query("SELECT COUNT(*) FROM products WHERE seller_id = $seller_id")->fetch_row()[0];
                ?>
                <p class="stat-value"><?= $total_products ?></p>
                <p>Products Listed</p>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <h2>Sales Analytics</h2>
            
            <div class="chart-container">
                <canvas id="salesChart"></canvas>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div class="stat-card">
                    <h3>Recent Orders</h3>
                    <?php foreach ($recent_orders as $order): ?>
                        <div class="order-item">
                            <p><strong><?= htmlspecialchars($order['name']) ?></strong></p>
                            <p><?= $order['quantity_kg'] ?> kg - <?= ucfirst($order['status']) ?></p>
                            <p class="text-sm"><?= date('M d, Y', strtotime($order['order_date'])) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="stat-card">
                    <h3>Top Buyers</h3>
                    <?php foreach ($top_buyers as $buyer): ?>
                        <div class="buyer-card">
                            <div class="buyer-avatar">
                                <?= strtoupper(substr($buyer['name'], 0, 1)) ?>
                            </div>
                            <div>
                                <p><strong><?= htmlspecialchars($buyer['name']) ?></strong></p>
                                <p><?= $buyer['total_kg'] ?> kg purchased</p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Sales Chart
        const ctx = document.getElementById('salesChart').getContext('2d');
        const salesChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($monthly_sales, 'month')) ?>,
                datasets: [{
                    label: 'Monthly Sales (₹)',
                    data: <?= json_encode(array_column($monthly_sales, 'total_sales')) ?>,
                    backgroundColor: 'rgba(0, 184, 148, 0.7)',
                    borderColor: 'rgba(0, 184, 148, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    </script>
</body>
</html>
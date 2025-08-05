<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

// Only sellers/admins can access
if ($_SESSION['role'] !== 'seller' && $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

// Date range filtering
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Overall metrics
$metrics = $conn->query("
    SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as completed_orders,
        SUM(CASE WHEN status = 'delivered' AND delivery_confirmed_at <= estimated_delivery THEN 1 ELSE 0 END) as on_time_deliveries,
        AVG(customer_rating) as avg_rating
    FROM orders
    WHERE order_date BETWEEN '$start_date' AND '$end_date'
")->fetch_assoc();

// Daily trends
$daily_trends = $conn->query("
    SELECT 
        DATE(order_date) as day,
        COUNT(*) as total_orders,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as completed_orders,
        SUM(CASE WHEN status = 'delivered' AND delivery_confirmed_at <= estimated_delivery THEN 1 ELSE 0 END) as on_time_deliveries
    FROM orders
    WHERE order_date BETWEEN '$start_date' AND '$end_date'
    GROUP BY DATE(order_date)
    ORDER BY day
")->fetch_all(MYSQLI_ASSOC);

// Time window compliance
$time_windows = $conn->query("
    SELECT 
        delivery_time_window,
        COUNT(*) as total,
        SUM(CASE WHEN delivery_confirmed_at <= estimated_delivery THEN 1 ELSE 0 END) as on_time,
        AVG(customer_rating) as avg_rating
    FROM orders
    WHERE status = 'delivered'
    AND delivery_time_window IS NOT NULL
    AND order_date BETWEEN '$start_date' AND '$end_date'
    GROUP BY delivery_time_window
")->fetch_all(MYSQLI_ASSOC);

// Customer satisfaction trends
$rating_trends = $conn->query("
    SELECT 
        DATE(delivery_confirmed_at) as day,
        AVG(customer_rating) as avg_rating,
        COUNT(*) as rating_count
    FROM orders
    WHERE status = 'delivered'
    AND customer_rating IS NOT NULL
    AND delivery_confirmed_at BETWEEN '$start_date' AND '$end_date'
    GROUP BY DATE(delivery_confirmed_at)
    ORDER BY day
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Delivery Analytics | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .metric-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin: 20px 0;
        }
        .metric-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .metric-value {
            font-size: 2em;
            font-weight: bold;
            margin: 10px 0;
        }
        .chart-container {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .time-window-compliance {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }
        .time-window-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <h1>Delivery Performance Analytics</h1>
        
        <form method="GET" class="date-filter">
            <div class="input-group">
                <label>Start Date</label>
                <input type="date" name="start_date" value="<?= $start_date ?>">
            </div>
            <div class="input-group">
                <label>End Date</label>
                <input type="date" name="end_date" value="<?= $end_date ?>">
            </div>
            <button type="submit" class="btn-primary">Apply Filter</button>
        </form>
        
        <div class="metric-cards">
            <div class="metric-card">
                <h3>Total Orders</h3>
                <div class="metric-value"><?= $metrics['total_orders'] ?></div>
                <p>Between <?= date('M j', strtotime($start_date)) ?> - <?= date('M j', strtotime($end_date)) ?></p>
            </div>
            <div class="metric-card">
                <h3>Completed Deliveries</h3>
                <div class="metric-value"><?= $metrics['completed_orders'] ?></div>
                <p><?= round(($metrics['completed_orders'] / max(1, $metrics['total_orders'])) * 100) ?>% completion rate</p>
            </div>
            <div class="metric-card">
                <h3>On-Time Delivery</h3>
                <div class="metric-value"><?= $metrics['on_time_deliveries'] ?></div>
                <p><?= round(($metrics['on_time_deliveries'] / max(1, $metrics['completed_orders'])) * 100) ?>% on-time rate</p>
            </div>
            <div class="metric-card">
                <h3>Avg. Rating</h3>
                <div class="metric-value"><?= number_format($metrics['avg_rating'] ?? 0, 1) ?></div>
                <p>Out of 5 stars</p>
            </div>
        </div>
        
        <div class="chart-container">
            <h2>Daily Delivery Trends</h2>
            <canvas id="dailyTrendsChart"></canvas>
        </div>
        
        <div class="chart-container">
            <h2>Customer Satisfaction Trend</h2>
            <canvas id="ratingTrendsChart"></canvas>
        </div>
        
        <div class="chart-container">
            <h2>Time Window Compliance</h2>
            <div class="time-window-compliance">
                <?php foreach ($time_windows as $window): ?>
                    <div class="time-window-card">
                        <h3><?= ucfirst($window['delivery_time_window']) ?></h3>
                        <p><?= $window['on_time'] ?> / <?= $window['total'] ?> on-time</p>
                        <p><?= round(($window['on_time'] / max(1, $window['total'])) * 100) ?>% compliance</p>
                        <p>Avg. rating: <?= number_format($window['avg_rating'] ?? 0, 1) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Daily Trends Chart
        const dailyCtx = document.getElementById('dailyTrendsChart').getContext('2d');
        new Chart(dailyCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($daily_trends, 'day')) ?>,
                datasets: [
                    {
                        label: 'Total Orders',
                        data: <?= json_encode(array_column($daily_trends, 'total_orders')) ?>,
                        backgroundColor: 'rgba(54, 162, 235, 0.5)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Completed Deliveries',
                        data: <?= json_encode(array_column($daily_trends, 'completed_orders')) ?>,
                        backgroundColor: 'rgba(75, 192, 192, 0.5)',
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'On-Time Deliveries',
                        data: <?= json_encode(array_column($daily_trends, 'on_time_deliveries')) ?>,
                        backgroundColor: 'rgba(153, 102, 255, 0.5)',
                        borderColor: 'rgba(153, 102, 255, 1)',
                        borderWidth: 1
                    }
                ]
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
        
        // Rating Trends Chart
        const ratingCtx = document.getElementById('ratingTrendsChart').getContext('2d');
        new Chart(ratingCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($rating_trends, 'day')) ?>,
                datasets: [{
                    label: 'Average Rating',
                    data: <?= json_encode(array_column($rating_trends, 'avg_rating')) ?>,
                    fill: false,
                    borderColor: 'rgb(255, 159, 64)',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        min: 0,
                        max: 5
                    }
                }
            }
        });
    </script>
</body>
</html>
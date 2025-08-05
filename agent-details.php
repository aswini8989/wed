<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

// Only sellers/admins can access
if ($_SESSION['role'] !== 'seller' && $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$agent_id = $_GET['agent_id'] ?? null;
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

if (!$agent_id) {
    header("Location: agent-scorecards.php");
    exit;
}

// Get agent details
$agent = $conn->query("
    SELECT * FROM delivery_agents 
    WHERE id = $agent_id
")->fetch_assoc();

// Get performance metrics
$metrics = $conn->query("
    SELECT 
        COUNT(o.id) as total_deliveries,
        SUM(CASE WHEN o.delivery_confirmed_at <= o.estimated_delivery THEN 1 ELSE 0 END) as on_time_deliveries,
        AVG(o.customer_rating) as avg_rating,
        MIN(o.delivery_confirmed_at) as first_delivery,
        MAX(o.delivery_confirmed_at) as last_delivery
    FROM order_delivery_assignments da
    JOIN orders o ON da.order_id = o.id
    WHERE da.agent_id = $agent_id
    AND o.status = 'delivered'
    AND o.delivery_confirmed_at BETWEEN '$start_date' AND '$end_date'
")->fetch_assoc();

// Get daily performance
$daily_performance = $conn->query("
    SELECT 
        DATE(o.delivery_confirmed_at) as day,
        COUNT(*) as deliveries,
        AVG(o.customer_rating) as avg_rating,
        SUM(CASE WHEN o.delivery_confirmed_at <= o.estimated_delivery THEN 1 ELSE 0 END) as on_time
    FROM order_delivery_assignments da
    JOIN orders o ON da.order_id = o.id
    WHERE da.agent_id = $agent_id
    AND o.status = 'delivered'
    AND o.delivery_confirmed_at BETWEEN '$start_date' AND '$end_date'
    GROUP BY DATE(o.delivery_confirmed_at)
    ORDER BY day
")->fetch_all(MYSQLI_ASSOC);

// Get recent deliveries
$recent_deliveries = $conn->query("
    SELECT 
        o.id as order_id,
        p.name as product_name,
        o.delivery_confirmed_at,
        o.customer_rating,
        o.customer_feedback
    FROM order_delivery_assignments da
    JOIN orders o ON da.order_id = o.id
    JOIN products p ON o.product_id = p.id
    WHERE da.agent_id = $agent_id
    AND o.status = 'delivered'
    ORDER BY o.delivery_confirmed_at DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Agent Performance | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .agent-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }
        .agent-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #6c5ce7;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2em;
            font-weight: bold;
        }
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
        .delivery-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        .rating-stars {
            color: #ffc107;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <div class="agent-header">
            <div class="agent-avatar">
                <?= substr($agent['name'], 0, 1) ?>
            </div>
            <div>
                <h1><?= $agent['name'] ?></h1>
                <p><?= $agent['phone'] ?> | <?= $agent['vehicle_number'] ?></p>
                <p>Active since <?= date('M Y', strtotime($agent['created_at'])) ?></p>
            </div>
        </div>
        
        <div class="metric-cards">
            <div class="metric-card">
                <h3>Total Deliveries</h3>
                <div class="metric-value"><?= $metrics['total_deliveries'] ?></div>
                <p>Between <?= date('M j', strtotime($start_date)) ?> - <?= date('M j', strtotime($end_date)) ?></p>
            </div>
            <div class="metric-card">
                <h3>On-Time Rate</h3>
                <div class="metric-value"><?= round(($metrics['on_time_deliveries'] / max(1, $metrics['total_deliveries'])) * 100) ?>%</div>
                <p><?= $metrics['on_time_deliveries'] ?> on-time deliveries</p>
            </div>
            <div class="metric-card">
                <h3>Average Rating</h3>
                <div class="metric-value">
                    <span class="rating-stars">
                        <?= str_repeat('★', round($metrics['avg_rating'] ?? 0)) ?>
                        <?= str_repeat('☆', 5 - round($metrics['avg_rating'] ?? 0)) ?>
                    </span>
                </div>
                <p><?= number_format($metrics['avg_rating'] ?? 0, 1) ?> out of 5</p>
            </div>
            <div class="metric-card">
                <h3>Activity</h3>
                <div class="metric-value"><?= count($daily_performance) ?></div>
                <p>Active days</p>
            </div>
        </div>
        
        <div class="chart-container">
            <h2>Daily Performance</h2>
            <canvas id="dailyPerformanceChart"></canvas>
        </div>
        
        <div class="chart-container">
            <h2>Recent Deliveries</h2>
            <?php foreach ($recent_deliveries as $delivery): ?>
                <div class="delivery-item">
                    <h4>Order #<?= $delivery['order_id'] ?> - <?= $delivery['product_name'] ?></h4>
                    <p>Delivered on <?= date('M j, Y h:i A', strtotime($delivery['delivery_confirmed_at'])) ?></p>
                    <?php if ($delivery['customer_rating']): ?>
                        <p class="rating-stars">
                            <?= str_repeat('★', $delivery['customer_rating']) ?>
                            <?= str_repeat('☆', 5 - $delivery['customer_rating']) ?>
                            <?php if ($delivery['customer_feedback']): ?>
                                - "<?= htmlspecialchars($delivery['customer_feedback']) ?>"
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <script>
        // Daily Performance Chart
        const dailyPerfCtx = document.getElementById('dailyPerformanceChart').getContext('2d');
        new Chart(dailyPerfCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($daily_performance, 'day')) ?>,
                datasets: [
                    {
                        label: 'Deliveries',
                        data: <?= json_encode(array_column($daily_performance, 'deliveries')) ?>,
                        backgroundColor: 'rgba(54, 162, 235, 0.5)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'On-Time %',
                        data: <?= json_encode(array_map(function($day) {
                            return round(($day['on_time'] / max(1, $day['deliveries'])) * 100);
                        }, $daily_performance)) ?>,
                        backgroundColor: 'rgba(75, 192, 192, 0.5)',
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 1,
                        type: 'line',
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Deliveries'
                        }
                    },
                    y1: {
                        position: 'right',
                        beginAtZero: true,
                        max: 100,
                        title: {
                            display: true,
                            text: 'On-Time %'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
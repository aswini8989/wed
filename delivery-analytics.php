// delivery-analytics.php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

if ($_SESSION['role'] !== 'seller' && $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$timeframe = $_GET['timeframe'] ?? 'week';
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-1 ' . $timeframe));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Get performance metrics
$metrics = $conn->query("
    SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as completed_orders,
        SUM(CASE WHEN status = 'delivered' AND delivery_confirmed_at <= estimated_delivery THEN 1 ELSE 0 END) as on_time_deliveries,
        AVG(customer_rating) as avg_rating,
        SUM(CASE WHEN payment_method = 'cod' THEN (price_per_kg * quantity_kg) ELSE 0 END) as cod_amount
    FROM orders
    WHERE order_date BETWEEN '$start_date' AND '$end_date'
")->fetch_assoc();

// Get agent performance
$agents = $conn->query("
    SELECT 
        a.id, a.name,
        COUNT(o.id) as delivery_count,
        AVG(o.customer_rating) as avg_rating,
        SUM(CASE WHEN o.delivery_confirmed_at <= o.estimated_delivery THEN 1 ELSE 0 END) / COUNT(*) as on_time_rate,
        SUM(p.price_per_kg * o.quantity_kg) as cod_collected
    FROM delivery_agents a
    LEFT JOIN order_delivery_assignments da ON da.agent_id = a.id
    LEFT JOIN orders o ON da.order_id = o.id AND o.status = 'delivered'
    LEFT JOIN products p ON o.product_id = p.id
    WHERE o.delivery_confirmed_at BETWEEN '$start_date' AND '$end_date'
    GROUP BY a.id
    ORDER BY avg_rating DESC
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
        .agent-table {
            width: 100%;
            border-collapse: collapse;
        }
        .agent-table th, .agent-table td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }
        .agent-table th {
            background-color: #f2f2f2;
        }
        .rating-stars {
            color: #ffc107;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <h1>Delivery Performance Analytics</h1>
        
        <form method="GET" class="date-filter">
            <div class="input-group">
                <label>Timeframe</label>
                <select name="timeframe" onchange="this.form.submit()">
                    <option value="day" <?= $timeframe === 'day' ? 'selected' : '' ?>>Today</option>
                    <option value="week" <?= $timeframe === 'week' ? 'selected' : '' ?>>This Week</option>
                    <option value="month" <?= $timeframe === 'month' ? 'selected' : '' ?>>This Month</option>
                    <option value="custom" <?= $timeframe === 'custom' ? 'selected' : '' ?>>Custom</option>
                </select>
            </div>
            
            <?php if ($timeframe === 'custom'): ?>
                <div class="input-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?= $start_date ?>">
                </div>
                <div class="input-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" value="<?= $end_date ?>">
                </div>
            <?php endif; ?>
            
            <button type="submit" class="btn-primary">Apply Filter</button>
        </form>
        
        <div class="metric-cards">
            <div class="metric-card">
                <h3>Total Orders</h3>
                <div class="metric-value"><?= $metrics['total_orders'] ?></div>
                <p><?= date('M j', strtotime($start_date)) ?> - <?= date('M j', strtotime($end_date)) ?></p>
            </div>
            <div class="metric-card">
                <h3>Completed</h3>
                <div class="metric-value"><?= $metrics['completed_orders'] ?></div>
                <p><?= round(($metrics['completed_orders'] / max(1, $metrics['total_orders'])) * 100) ?>% rate</p>
            </div>
            <div class="metric-card">
                <h3>On-Time</h3>
                <div class="metric-value"><?= $metrics['on_time_deliveries'] ?></div>
                <p><?= round(($metrics['on_time_deliveries'] / max(1, $metrics['completed_orders'])) * 100) ?>% rate</p>
            </div>
            <div class="metric-card">
                <h3>Avg. Rating</h3>
                <div class="metric-value"><?= number_format($metrics['avg_rating'] ?? 0, 1) ?></div>
                <p>Out of 5 stars</p>
            </div>
        </div>
        
        <div class="chart-container">
            <h2>COD Collection: ₹<?= number_format($metrics['cod_amount'], 2) ?></h2>
            <canvas id="codChart"></canvas>
        </div>
        
        <div class="chart-container">
            <h2>Agent Performance</h2>
            <table class="agent-table">
                <thead>
                    <tr>
                        <th>Agent</th>
                        <th>Deliveries</th>
                        <th>On-Time Rate</th>
                        <th>Avg. Rating</th>
                        <th>COD Collected</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agents as $agent): ?>
                        <tr>
                            <td><?= htmlspecialchars($agent['name']) ?></td>
                            <td><?= $agent['delivery_count'] ?></td>
                            <td><?= round(($agent['on_time_rate'] ?? 0) * 100) ?>%</td>
                            <td class="rating-stars">
                                <?= str_repeat('★', round($agent['avg_rating'] ?? 0)) ?>
                                <?= str_repeat('☆', 5 - round($agent['avg_rating'] ?? 0)) ?>
                                (<?= number_format($agent['avg_rating'] ?? 0, 1) ?>)
                            </td>
                            <td>₹<?= number_format($agent['cod_collected'] ?? 0, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <script>
        // COD Collection Chart
        const codCtx = document.getElementById('codChart').getContext('2d');
        new Chart(codCtx, {
            type: 'bar',
            data: {
                labels: ['COD Collected'],
                datasets: [{
                    label: 'Amount',
                    data: [<?= $metrics['cod_amount'] ?>],
                    backgroundColor: 'rgba(75, 192, 192, 0.5)',
                    borderColor: 'rgba(75, 192, 192, 1)',
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
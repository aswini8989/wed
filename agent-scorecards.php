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

// Get all agents with performance metrics
$agents = $conn->query("
    SELECT 
        a.id, a.name, a.phone, a.vehicle_number,
        COUNT(o.id) as total_deliveries,
        SUM(CASE WHEN o.delivery_confirmed_at <= o.estimated_delivery THEN 1 ELSE 0 END) as on_time_deliveries,
        AVG(o.customer_rating) as avg_rating,
        COUNT(DISTINCT DATE(o.delivery_confirmed_at)) as active_days
    FROM delivery_agents a
    LEFT JOIN order_delivery_assignments da ON da.agent_id = a.id
    LEFT JOIN orders o ON da.order_id = o.id AND o.status = 'delivered'
    WHERE o.delivery_confirmed_at BETWEEN '$start_date' AND '$end_date'
    GROUP BY a.id
    ORDER BY avg_rating DESC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Agent Scorecards | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .agent-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .agent-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .agent-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        .agent-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #6c5ce7;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5em;
            font-weight: bold;
        }
        .performance-metrics {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 15px;
        }
        .metric {
            text-align: center;
        }
        .metric-value {
            font-size: 1.5em;
            font-weight: bold;
        }
        .rating-stars {
            color: #ffc107;
            font-size: 1.2em;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <h1>Delivery Agent Scorecards</h1>
        
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
        
        <div class="agent-cards">
            <?php foreach ($agents as $agent): ?>
                <div class="agent-card">
                    <div class="agent-header">
                        <div class="agent-avatar">
                            <?= substr($agent['name'], 0, 1) ?>
                        </div>
                        <div>
                            <h3><?= $agent['name'] ?></h3>
                            <p><?= $agent['vehicle_number'] ?></p>
                        </div>
                    </div>
                    
                    <div class="performance-metrics">
                        <div class="metric">
                            <div class="metric-value"><?= $agent['total_deliveries'] ?></div>
                            <p>Deliveries</p>
                        </div>
                        <div class="metric">
                            <div class="metric-value">
                                <?= round(($agent['on_time_deliveries'] / max(1, $agent['total_deliveries'])) * 100) ?>%
                            </div>
                            <p>On-Time</p>
                        </div>
                        <div class="metric">
                            <div class="metric-value">
                                <span class="rating-stars">
                                    <?= str_repeat('★', round($agent['avg_rating'] ?? 0)) ?>
                                    <?= str_repeat('☆', 5 - round($agent['avg_rating'] ?? 0)) ?>
                                </span>
                            </div>
                            <p>Rating</p>
                        </div>
                        <div class="metric">
                            <div class="metric-value"><?= $agent['active_days'] ?></div>
                            <p>Active Days</p>
                        </div>
                    </div>
                    
                    <a href="agent-details.php?agent_id=<?= $agent['id'] ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" 
                       class="btn-sm btn-primary" style="margin-top: 15px; display: block;">
                        View Detailed Performance
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
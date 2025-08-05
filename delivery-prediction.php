// delivery-prediction.php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

// Only admins/sellers can access
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'seller') {
    header("Location: dashboard.php");
    exit;
}

// Fetch historical delivery data for training
$delivery_data = $conn->query("
    SELECT 
        o.id,
        DATEDIFF(o.delivery_confirmed_at, o.order_date) as delivery_days,
        DAYOFWEEK(o.order_date) as order_day,
        HOUR(o.order_date) as order_hour,
        o.quantity_kg,
        p.price_per_kg,
        u.distance_from_warehouse,
        COUNT(da.id) as delivery_attempts,
        o.delivery_time_window
    FROM orders o
    JOIN products p ON o.product_id = p.id
    JOIN users u ON o.buyer_id = u.id
    LEFT JOIN delivery_attempts da ON da.order_id = o.id
    WHERE o.status = 'delivered'
    AND o.delivery_confirmed_at IS NOT NULL
    GROUP BY o.id
")->fetch_all(MYSQLI_ASSOC);

// Train simple prediction model (in practice, use Python/R)
function trainDeliveryModel($data) {
    // This is simplified - a real implementation would use ML
    $avg_delivery_time = array_sum(array_column($data, 'delivery_days')) / count($data);
    return [
        'base_days' => $avg_delivery_time,
        'time_window_adjustments' => [
            'morning' => -0.5,
            'afternoon' => 0,
            'evening' => 0.5
        ],
        'distance_factors' => [
            'near' => -1,
            'medium' => 0,
            'far' => 1
        ]
    ];
}

$model = trainDeliveryModel($delivery_data);

// Apply model to pending orders
$pending_orders = $conn->query("
    SELECT o.*, u.distance_from_warehouse
    FROM orders o
    JOIN users u ON o.buyer_id = u.id
    WHERE o.status = 'processing'
")->fetch_all(MYSQLI_ASSOC);

foreach ($pending_orders as &$order) {
    $base = $model['base_days'];
    $window_adjust = $model['time_window_adjustments'][$order['delivery_time_window'] ?? 0];
    
    // Simple distance categorization
    if ($order['distance_from_warehouse'] < 10) {
        $distance_factor = $model['distance_factors']['near'];
    } elseif ($order['distance_from_warehouse'] < 30) {
        $distance_factor = $model['distance_factors']['medium'];
    } else {
        $distance_factor = $model['distance_factors']['far'];
    }
    
    $predicted_days = $base + $window_adjust + $distance_factor;
    $order['predicted_delivery_date'] = date('Y-m-d', strtotime($order['order_date'] . " + $predicted_days days"));
}

// Save predictions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        foreach ($pending_orders as $order) {
            $stmt = $conn->prepare("
                UPDATE orders SET 
                    estimated_delivery = ?,
                    predicted_eta = ?
                WHERE id = ?
            ");
            $stmt->bind_param("ssi", 
                $order['predicted_delivery_date'],
                $order['predicted_days'],
                $order['id']
            );
            $stmt->execute();
        }
        $conn->commit();
        $_SESSION['success'] = "Delivery predictions updated for " . count($pending_orders) . " orders!";
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Failed to update predictions: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Delivery Prediction | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .prediction-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .prediction-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .eta-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-weight: bold;
        }
        .eta-good {
            background: #d4edda;
            color: #155724;
        }
        .eta-medium {
            background: #fff3cd;
            color: #856404;
        }
        .eta-bad {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <h1>AI Delivery Prediction</h1>
        
        <?php if (isset($error)): ?>
            <div class="alert error"><?= $error ?></div>
        <?php endif; ?>
        
        <div class="model-info">
            <h2>Prediction Model</h2>
            <p>Base Delivery Time: <?= number_format($model['base_days'], 1) ?> days</p>
            <p>Time Window Adjustments:</p>
            <ul>
                <li>Morning: <?= $model['time_window_adjustments']['morning'] ?> days</li>
                <li>Afternoon: <?= $model['time_window_adjustments']['afternoon'] ?> days</li>
                <li>Evening: <?= $model['time_window_adjustments']['evening'] ?> days</li>
            </ul>
        </div>
        
        <form method="POST">
            <button type="submit" class="btn-primary">
                <i class="fas fa-brain"></i> Apply Predictions to Orders
            </button>
        </form>
        
        <h2>Pending Order Predictions</h2>
        <?php foreach ($pending_orders as $order): ?>
            <div class="prediction-card">
                <div class="prediction-header">
                    <h3>Order #<?= $order['id'] ?></h3>
                    <span class="eta-badge <?= $order['predicted_days'] <= 3 ? 'eta-good' : 
                                          ($order['predicted_days'] <= 5 ? 'eta-medium' : 'eta-bad') ?>">
                        <?= $order['predicted_days'] ?> days
                    </span>
                </div>
                <p><strong>Predicted Delivery:</strong> <?= $order['predicted_delivery_date'] ?></p>
                <p><strong>Distance:</strong> <?= $order['distance_from_warehouse'] ?> km</p>
                <p><strong>Time Window:</strong> <?= $order['delivery_time_window'] ?? 'Not specified' ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
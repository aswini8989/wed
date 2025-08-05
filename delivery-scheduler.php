// delivery-scheduler.php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

if ($_SESSION['role'] !== 'seller' && $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

// Get pending deliveries
$pending_deliveries = $conn->query("
    SELECT o.id as order_id, u.address, u.lat, u.lng, 
           o.delivery_time_window, o.estimated_delivery,
           (p.price_per_kg * o.quantity_kg) as cod_amount
    FROM orders o
    JOIN products p ON o.product_id = p.id
    JOIN users u ON o.buyer_id = u.id
    WHERE o.status = 'shipped'
    AND o.id NOT IN (SELECT order_id FROM order_delivery_assignments)
    ORDER BY o.estimated_delivery
")->fetch_all(MYSQLI_ASSOC);

// Get available agents
$agents = $conn->query("
    SELECT a.*, 
           COUNT(da.id) as current_assignments,
           AVG(o.customer_rating) as avg_rating
    FROM delivery_agents a
    LEFT JOIN order_delivery_assignments da ON da.agent_id = a.id AND da.completed_at IS NULL
    LEFT JOIN orders o ON da.order_id = o.id
    WHERE a.active = TRUE
    GROUP BY a.id
    ORDER BY current_assignments, avg_rating DESC
")->fetch_all(MYSQLI_ASSOC);

// Handle auto-assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_assign'])) {
    $conn->begin_transaction();
    try {
        // Create optimized routes
        $optimized_routes = optimizeRoutes($pending_deliveries, $agents);
        
        foreach ($optimized_routes as $agent_id => $assignments) {
            if (!empty($assignments)) {
                // Create route
                $stmt = $conn->prepare("
                    INSERT INTO delivery_routes 
                    (agent_id, route_date, status)
                    VALUES (?, CURDATE(), 'pending')
                ");
                $stmt->bind_param("i", $agent_id);
                $stmt->execute();
                $route_id = $conn->insert_id;
                
                // Assign deliveries
                $stop_number = 1;
                foreach ($assignments as $order_id) {
                    $stmt = $conn->prepare("
                        INSERT INTO order_delivery_assignments 
                        (route_id, order_id, agent_id, stop_number)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->bind_param("iiii", $route_id, $order_id, $agent_id, $stop_number);
                    $stmt->execute();
                    $stop_number++;
                }
                
                // Notify agent
                $notificationSystem = new NotificationSystem($conn);
                $notificationSystem->create(
                    $agent_id,
                    'New Delivery Assignments',
                    "You have been assigned " . count($assignments) . " new deliveries",
                    "delivery-agent-app.php"
                );
            }
        }
        
        $conn->commit();
        $_SESSION['success'] = "Successfully assigned " . count($pending_deliveries) . " deliveries!";
        header("Location: delivery-scheduler.php");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Assignment failed: " . $e->getMessage();
    }
}

function optimizeRoutes($deliveries, $agents) {
    // Simplified optimization - in practice use a proper routing algorithm
    $routes = [];
    $agent_index = 0;
    
    // Group by time window first
    $time_windows = ['morning' => [], 'afternoon' => [], 'evening' => []];
    foreach ($deliveries as $delivery) {
        $window = $delivery['delivery_time_window'] ?? 'morning';
        $time_windows[$window][] = $delivery;
    }
    
    // Assign to agents
    foreach ($time_windows as $window => $window_deliveries) {
        foreach ($window_deliveries as $delivery) {
            if (!isset($routes[$agents[$agent_index]['id']])) {
                $routes[$agents[$agent_index]['id']] = [];
            }
            $routes[$agents[$agent_index]['id']][] = $delivery['order_id'];
            $agent_index = ($agent_index + 1) % count($agents);
        }
    }
    
    return $routes;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Delivery Scheduler | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
    <style>
        #map {
            height: 500px;
            width: 100%;
            margin: 20px 0;
            border-radius: 8px;
        }
        .delivery-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .time-window {
            background: #e3f2fd;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.9em;
        }
        .agent-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <h1>Delivery Scheduler</h1>
        
        <?php if (isset($error)): ?>
            <div class="alert error"><?= $error ?></div>
        <?php endif; ?>
        
        <div class="grid-2">
            <div>
                <h2>Pending Deliveries (<?= count($pending_deliveries) ?>)</h2>
                <?php if (empty($pending_deliveries)): ?>
                    <div class="alert success">All deliveries are assigned!</div>
                <?php else: ?>
                    <?php foreach ($pending_deliveries as $delivery): ?>
                        <div class="delivery-card">
                            <h3>Order #<?= $delivery['order_id'] ?></h3>
                            <?php if ($delivery['delivery_time_window']): ?>
                                <span class="time-window">
                                    <?= strtoupper($delivery['delivery_time_window']) ?>
                                </span>
                            <?php endif; ?>
                            <p>COD Amount: ₹<?= number_format($delivery['cod_amount'], 2) ?></p>
                            <p><?= $delivery['address'] ?></p>
                        </div>
                    <?php endforeach; ?>
                    
                    <form method="POST">
                        <button type="submit" name="auto_assign" class="btn-primary">
                            <i class="fas fa-robot"></i> Auto-Assign Deliveries
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            
            <div>
                <h2>Available Agents (<?= count($agents) ?>)</h2>
                <?php foreach ($agents as $agent): ?>
                    <div class="agent-card">
                        <h3><?= $agent['name'] ?></h3>
                        <p>Current Assignments: <?= $agent['current_assignments'] ?></p>
                        <p>Rating: <?= number_format($agent['avg_rating'] ?? 0, 1) ?>/5</p>
                        <p>Vehicle: <?= $agent['vehicle_number'] ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div id="map"></div>
    </div>

    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    <script>
        // Initialize map
        const map = L.map('map').setView([20.5937, 78.9629], 12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);

        // Add delivery locations
        <?php foreach ($pending_deliveries as $delivery): ?>
            <?php if ($delivery['lat'] && $delivery['lng']): ?>
                L.marker([<?= $delivery['lat'] ?>, <?= $delivery['lng'] ?>])
                    .addTo(map)
                    .bindPopup(`Order #<?= $delivery['order_id'] ?>`);
            <?php endif; ?>
        <?php endforeach; ?>
    </script>
</body>
</html>
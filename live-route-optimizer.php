// live-route-optimizer.php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

if ($_SESSION['role'] !== 'seller' && $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

// Get active deliveries
$active_deliveries = $conn->query("
    SELECT o.id, u.address, u.lat, u.lng, 
           o.delivery_time_window, o.estimated_delivery,
           da.current_lat, da.current_lng,
           ag.name as agent_name
    FROM orders o
    JOIN users u ON o.buyer_id = u.id
    JOIN order_delivery_assignments da ON da.order_id = o.id
    JOIN delivery_agents ag ON da.agent_id = ag.id
    WHERE o.status = 'shipped'
    AND da.completed_at IS NULL
")->fetch_all(MYSQLI_ASSOC);

// Group by agent
$agent_routes = [];
foreach ($active_deliveries as $delivery) {
    if (!isset($agent_routes[$delivery['agent_name']])) {
        $agent_routes[$delivery['agent_name']] = [];
    }
    $agent_routes[$delivery['agent_name']][] = $delivery;
}

// Get traffic data (mock - integrate with Google Maps API in production)
function getTrafficData($lat, $lng) {
    // In reality, call Google Maps API or similar
    return [
        'congestion' => rand(0, 100), // 0-100% congestion
        'delay_minutes' => rand(0, 30) // Estimated delay
    ];
}

// Optimize routes considering traffic
foreach ($agent_routes as &$route) {
    usort($route, function($a, $b) {
        $traffic_a = getTrafficData($a['lat'], $a['lng']);
        $traffic_b = getTrafficData($b['lat'], $b['lng']);
        
        // Prioritize time windows first, then traffic
        if ($a['delivery_time_window'] !== $b['delivery_time_window']) {
            return strcmp($a['delivery_time_window'], $b['delivery_time_window']);
        }
        return $traffic_a['delay_minutes'] <=> $traffic_b['delay_minutes'];
    });
}

// Handle route updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        foreach ($_POST['new_sequence'] as $agent => $order_sequence) {
            $stop_number = 1;
            foreach ($order_sequence as $order_id) {
                $stmt = $conn->prepare("
                    UPDATE order_delivery_assignments SET 
                        stop_number = ?
                    WHERE order_id = ?
                    AND completed_at IS NULL
                ");
                $stmt->bind_param("ii", $stop_number, $order_id);
                $stmt->execute();
                $stop_number++;
            }
            
            // Notify agent
            $notificationSystem = new NotificationSystem($conn);
            $agent_id = $conn->query("
                SELECT id FROM delivery_agents WHERE name = '$agent'
            ")->fetch_row()[0];
            
            $notificationSystem->create(
                $agent_id,
                'Route Updated',
                "Your delivery route has been optimized based on traffic conditions",
                "delivery-agent-app.php"
            );
        }
        
        $conn->commit();
        $_SESSION['success'] = "Routes optimized successfully!";
        header("Location: live-route-optimizer.php");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Route optimization failed: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Live Route Optimizer | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
    <style>
        #map {
            height: 600px;
            width: 100%;
            margin: 20px 0;
            border-radius: 8px;
        }
        .route-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .delivery-item {
            padding: 10px;
            margin: 5px 0;
            background: #f8f9fa;
            border-radius: 4px;
            cursor: move;
        }
        .traffic-info {
            font-size: 0.8em;
            color: #666;
        }
        .traffic-high {
            color: #dc3545;
            font-weight: bold;
        }
        .traffic-medium {
            color: #ffc107;
            font-weight: bold;
        }
        .traffic-low {
            color: #28a745;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <h1>Live Route Optimization</h1>
        
        <?php if (isset($error)): ?>
            <div class="alert error"><?= $error ?></div>
        <?php endif; ?>
        
        <div id="map"></div>
        
        <form method="POST" id="routeForm">
            <?php foreach ($agent_routes as $agent => $deliveries): ?>
                <div class="route-card">
                    <h2><?= $agent ?>'s Route</h2>
                    <div class="delivery-list" data-agent="<?= $agent ?>">
                        <?php foreach ($deliveries as $delivery): 
                            $traffic = getTrafficData($delivery['lat'], $delivery['lng']);
                            $traffic_class = $traffic['congestion'] > 70 ? 'high' : 
                                            ($traffic['congestion'] > 30 ? 'medium' : 'low');
                        ?>
                            <div class="delivery-item" data-order="<?= $delivery['id'] ?>">
                                <div class="delivery-header">
                                    <strong>Order #<?= $delivery['id'] ?></strong>
                                    <span class="time-window"><?= strtoupper($delivery['delivery_time_window']) ?></span>
                                </div>
                                <p><?= $delivery['address'] ?></p>
                                <p class="traffic-info">
                                    Traffic: 
                                    <span class="traffic-<?= $traffic_class ?>">
                                        <?= $traffic_class ?> (<?= $traffic['delay_minutes'] ?> min delay)
                                    </span>
                                </p>
                                <input type="hidden" name="new_sequence[<?= $agent ?>][]" value="<?= $delivery['id'] ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <button type="submit" class="btn-primary">
                <i class="fas fa-route"></i> Update Routes
            </button>
        </form>
    </div>

    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.14.0/Sortable.min.js"></script>
    <script>
        // Initialize map
        const map = L.map('map').setView([20.5937, 78.9629], 12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

        // Add delivery locations and routes
        <?php foreach ($agent_routes as $agent => $deliveries): ?>
            const agentColor = '#' + Math.floor(Math.random()*16777215).toString(16);
            const coordinates = [];
            
            <?php foreach ($deliveries as $delivery): ?>
                coordinates.push([<?= $delivery['lat'] ?>, <?= $delivery['lng'] ?>]);
                L.marker([<?= $delivery['lat'] ?>, <?= $delivery['lng'] ?>], {
                    icon: L.divIcon({
                        html: `<div style="background: ${agentColor}; color: white; border-radius: 50%; 
                               width: 24px; height: 24px; display: flex; align-items: center; 
                               justify-content: center;"><?= substr($delivery['id'], -2) ?></div>`
                    })
                }).addTo(map).bindPopup(`
                    <strong>Order #<?= $delivery['id'] ?></strong><br>
                    <?= $delivery['address'] ?><br>
                    Window: <?= $delivery['delivery_time_window'] ?>
                `);
            <?php endforeach; ?>
            
            if (coordinates.length > 1) {
                L.polyline(coordinates, {color: agentColor}).addTo(map);
            }
        <?php endforeach; ?>

        // Make delivery lists sortable
        document.querySelectorAll('.delivery-list').forEach(list => {
            new Sortable(list, {
                animation: 150,
                ghostClass: 'sortable-ghost',
                onEnd: function() {
                    // Update hidden inputs with new order
                    const inputs = list.querySelectorAll('input[type="hidden"]');
                    inputs.forEach((input, index) => {
                        input.value = list.children[index].dataset.order;
                    });
                }
            });
        });
    </script>
</body>
</html>
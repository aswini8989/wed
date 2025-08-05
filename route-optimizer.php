<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

// Only sellers/admins can access
if ($_SESSION['role'] !== 'seller' && $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

// Get pending deliveries
$deliveries = $conn->query("
    SELECT o.id, o.delivery_time_window, u.address, u.lat, u.lng
    FROM orders o
    JOIN users u ON o.buyer_id = u.id
    WHERE o.status = 'shipped'
    AND o.id NOT IN (SELECT order_id FROM route_stops)
")->fetch_all(MYSQLI_ASSOC);

// Handle route creation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $agent_id = $_POST['agent_id'];
    $selected_orders = $_POST['orders'] ?? [];
    
    if (empty($selected_orders)) {
        $error = "Please select at least one delivery";
    } else {
        $conn->begin_transaction();
        try {
            // Create route
            $stmt = $conn->prepare("
                INSERT INTO delivery_routes (agent_id, route_date, status)
                VALUES (?, CURDATE(), 'pending')
            ");
            $stmt->bind_param("i", $agent_id);
            $stmt->execute();
            $route_id = $conn->insert_id;
            
            // Optimize order based on location (simplified)
            usort($selected_orders, function($a, $b) {
                return $a['distance'] <=> $b['distance'];
            });
            
            // Add stops to route
            $stop_number = 1;
            foreach ($selected_orders as $order_id) {
                $stmt = $conn->prepare("
                    INSERT INTO route_stops (route_id, order_id, stop_number, status)
                    VALUES (?, ?, ?, 'pending')
                ");
                $stmt->bind_param("iii", $route_id, $order_id, $stop_number);
                $stmt->execute();
                $stop_number++;
                
                // Assign to agent
                $conn->query("
                    INSERT INTO order_delivery_assignments (order_id, agent_id)
                    VALUES ($order_id, $agent_id)
                ");
            }
            
            $conn->commit();
            $_SESSION['success'] = "Optimized route created with $stop_number stops!";
            header("Location: route-management.php");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to create route: " . $e->getMessage();
        }
    }
}

// Get available agents
$agents = $conn->query("
    SELECT * FROM delivery_agents 
    WHERE active = TRUE
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Route Optimizer | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
    <style>
        #map {
            height: 500px;
            width: 100%;
            margin: 20px 0;
            border-radius: 8px;
        }
        .delivery-item {
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .time-window {
            background: #e3f2fd;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <h1>Delivery Route Optimizer</h1>
        
        <?php if (isset($error)): ?>
            <div class="alert error"><?= $error ?></div>
        <?php endif; ?>
        
        <?php if (empty($deliveries)): ?>
            <div class="alert info">No pending deliveries to optimize</div>
        <?php else: ?>
            <div id="map"></div>
            
            <form method="POST">
                <div class="input-group">
                    <label>Select Delivery Agent</label>
                    <select name="agent_id" required>
                        <?php foreach ($agents as $agent): ?>
                            <option value="<?= $agent['id'] ?>">
                                <?= $agent['name'] ?> (<?= $agent['vehicle_number'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <h3>Select Deliveries for Route</h3>
                <div class="delivery-list">
                    <?php foreach ($deliveries as $delivery): ?>
                        <label class="delivery-item">
                            <div>
                                <input type="checkbox" name="orders[]" value="<?= $delivery['id'] ?>">
                                <strong>Order #<?= $delivery['id'] ?></strong>
                                <?php if ($delivery['delivery_time_window']): ?>
                                    <span class="time-window">
                                        <?= strtoupper($delivery['delivery_time_window']) ?>
                                    </span>
                                <?php endif; ?>
                                <p><?= $delivery['address'] ?></p>
                            </div>
                            <div>
                                <span class="distance-badge" data-lat="<?= $delivery['lat'] ?>" 
                                      data-lng="<?= $delivery['lng'] ?>">
                                    Calculating...
                                </span>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
                
                <button type="submit" class="btn-primary">
                    <i class="fas fa-route"></i> Create Optimized Route
                </button>
            </form>
        <?php endif; ?>
    </div>

    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    <script>
        // Initialize map
        const map = L.map('map').setView([20.5937, 78.9629], 5);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);

        // Add markers for each delivery
        const markers = [];
        <?php foreach ($deliveries as $delivery): ?>
            <?php if ($delivery['lat'] && $delivery['lng']): ?>
                const marker = L.marker([<?= $delivery['lat'] ?>, <?= $delivery['lng'] ?>])
                    .addTo(map)
                    .bindPopup(`Order #<?= $delivery['id'] ?>`);
                markers.push({
                    id: <?= $delivery['id'] ?>,
                    marker: marker,
                    lat: <?= $delivery['lat'] ?>,
                    lng: <?= $delivery['lng'] ?>
                });
            <?php endif; ?>
        <?php endforeach; ?>

        // Simple distance calculation (for demo)
        function calculateDistances() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(position => {
                    const userLat = position.coords.latitude;
                    const userLng = position.coords.longitude;
                    
                    markers.forEach(m => {
                        // Simplified distance calculation (Haversine would be better)
                        const dist = Math.sqrt(
                            Math.pow(m.lat - userLat, 2) + 
                            Math.pow(m.lng - userLng, 2)
                        ) * 111; // Approx km per degree
                        
                        document.querySelector(`.distance-badge[data-lat="${m.lat}"][data-lng="${m.lng}"]`)
                            .textContent = `${dist.toFixed(1)} km`;
                        
                        // Store distance for sorting
                        document.querySelector(`input[value="${m.id}"]`)
                            .parentElement.parentElement.dataset.distance = dist;
                    });
                });
            }
        }
        
        calculateDistances();
    </script>
</body>
</html>
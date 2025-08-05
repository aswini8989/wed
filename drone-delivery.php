// drone-delivery.php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

if ($_SESSION['role'] !== 'seller' && $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

// Check drone availability
$drones_available = $conn->query("
    SELECT COUNT(*) as available 
    FROM delivery_drones 
    WHERE status = 'available'
")->fetch_assoc()['available'];

// Get drone-eligible orders
$eligible_orders = $conn->query("
    SELECT o.id, u.name as customer, u.address, 
           u.lat, u.lng, (p.price_per_kg * o.quantity_kg) as amount,
           ST_Distance_Sphere(
               point(u.lng, u.lat),
               point(12.9716, 77.5946) -- Warehouse coordinates
           ) as distance_meters
    FROM orders o
    JOIN products p ON o.product_id = p.id
    JOIN users u ON o.buyer_id = u.id
    WHERE o.status = 'processing'
    AND o.quantity_kg <= 5 -- Drone weight limit
    AND ST_Distance_Sphere(
            point(u.lng, u.lat),
            point(12.9716, 77.5946)
        ) <= 15000 -- 15km range
")->fetch_all(MYSQLI_ASSOC);

// Handle drone assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_drone'])) {
    $order_id = $_POST['order_id'];
    $drone_id = $_POST['drone_id'];
    
    $conn->begin_transaction();
    try {
        // Mark order as drone delivery
        $conn->query("
            UPDATE orders SET 
                delivery_method = 'drone',
                drone_id = $drone_id,
                estimated_delivery = DATE_ADD(NOW(), INTERVAL 30 MINUTE)
            WHERE id = $order_id
        ");
        
        // Update drone status
        $conn->query("
            UPDATE delivery_drones SET 
                status = 'assigned',
                current_order = $order_id,
                last_assigned = NOW()
            WHERE id = $drone_id
        ");
        
        // Add status update
        $conn->query("
            INSERT INTO order_updates (order_id, status, update_text)
            VALUES ($order_id, 'processing', 'Assigned to drone delivery')
        ");
        
        // Notify customer
        $notificationSystem = new NotificationSystem($conn);
        $buyer_id = $conn->query("
            SELECT buyer_id FROM orders WHERE id = $order_id
        ")->fetch_row()[0];
        
        $notificationSystem->create(
            $buyer_id,
            'Drone Delivery Scheduled #' . $order_id,
            "Your order will be delivered by drone within 30 minutes!",
            "order-tracking.php?id=$order_id"
        );
        
        $conn->commit();
        $_SESSION['success'] = "Drone assigned successfully!";
        header("Location: drone-delivery.php");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Drone assignment failed: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Drone Delivery Management | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
    <style>
        .drone-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .drone-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: bold;
        }
        .status-available {
            background: #d4edda;
            color: #155724;
        }
        .status-assigned {
            background: #fff3cd;
            color: #856404;
        }
        .status-maintenance {
            background: #f8d7da;
            color: #721c24;
        }
        #drone-map {
            height: 400px;
            width: 100%;
            margin: 20px 0;
            border-radius: 8px;
        }
        .eligible-order {
            border-left: 3px solid #6c5ce7;
            padding: 10px;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <h1>Drone Delivery Management</h1>
        
        <?php if (isset($error)): ?>
            <div class="alert error"><?= $error ?></div>
        <?php endif; ?>
        
        <div class="grid-2">
            <div>
                <h2>Available Drones (<?= $drones_available ?>)</h2>
                <?php 
                $drones = $conn->query("
                    SELECT * FROM delivery_drones 
                    ORDER BY status, last_assigned DESC
                ");
                while ($drone = $drones->fetch_assoc()): ?>
                    <div class="drone-card">
                        <h3>Drone #<?= $drone['id'] ?> - <?= $drone['model'] ?></h3>
                        <p>
                            Status: 
                            <span class="drone-status status-<?= $drone['status'] ?>">
                                <?= ucfirst($drone['status']) ?>
                            </span>
                        </p>
                        <p>Battery: <?= $drone['battery_level'] ?>%</p>
                        <p>Capacity: <?= $drone['max_weight'] ?>kg</p>
                        <?php if ($drone['status'] === 'available' && !empty($eligible_orders)): ?>
                            <form method="POST" style="margin-top: 10px;">
                                <input type="hidden" name="drone_id" value="<?= $drone['id'] ?>">
                                <div class="input-group">
                                    <label>Assign Order</label>
                                    <select name="order_id" required>
                                        <?php foreach ($eligible_orders as $order): ?>
                                            <option value="<?= $order['id'] ?>">
                                                #<?= $order['id'] ?> - <?= round($order['distance_meters']/1000, 1) ?>km - ₹<?= $order['amount'] ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" name="assign_drone" class="btn-sm btn-primary">
                                    Assign Delivery
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
            </div>
            
            <div>
                <h2>Drone-Eligible Orders (<?= count($eligible_orders) ?>)</h2>
                <?php if (empty($eligible_orders)): ?>
                    <div class="alert info">No orders eligible for drone delivery</div>
                <?php else: ?>
                    <?php foreach ($eligible_orders as $order): ?>
                        <div class="eligible-order">
                            <h3>Order #<?= $order['id'] ?></h3>
                            <p>Customer: <?= $order['customer'] ?></p>
                            <p>Distance: <?= round($order['distance_meters']/1000, 1) ?> km</p>
                            <p>Amount: ₹<?= number_format($order['amount'], 2) ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <h2>Delivery Zone Map</h2>
        <div id="drone-map"></div>
    </div>

    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    <script>
        // Initialize map centered on warehouse
        const map = L.map('drone-map').setView([12.9716, 77.5946], 12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

        // Add warehouse marker
        L.marker([12.9716, 77.5946]).addTo(map)
            .bindPopup('Warehouse Location')
            .openPopup();

        // Add delivery locations
        <?php foreach ($eligible_orders as $order): ?>
            L.marker([<?= $order['lat'] ?>, <?= $order['lng'] ?>]).addTo(map)
                .bindPopup(`Order #<?= $order['id'] ?><br><?= $order['address'] ?>`);
                
            // Draw delivery radius (15km)
            L.circle([12.9716, 77.5946], {
                color: 'blue',
                fillColor: '#30a5ff',
                fillOpacity: 0.2,
                radius: 15000
            }).addTo(map);
        <?php endforeach; ?>
    </script>
</body>
</html>
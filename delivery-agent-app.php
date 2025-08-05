<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

// Only delivery agents can access
$agent = $conn->query("
    SELECT * FROM delivery_agents 
    WHERE phone = '{$_SESSION['phone']}'
")->fetch_assoc();

if (!$agent) {
    header("Location: dashboard.php");
    exit;
}

// Get assigned deliveries
$deliveries = $conn->query("
    SELECT o.id as order_id, o.delivery_time_window,
           p.name as product_name, u.name as buyer_name,
           u.address, u.phone as buyer_phone
    FROM order_delivery_assignments da
    JOIN orders o ON da.order_id = o.id
    JOIN products p ON o.product_id = p.id
    JOIN users u ON o.buyer_id = u.id
    WHERE da.agent_id = {$agent['id']}
    AND o.status = 'shipped'
    AND da.completed_at IS NULL
")->fetch_all(MYSQLI_ASSOC);

// Handle location update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'])) {
    $order_id = $_POST['order_id'];
    $lat = $_POST['lat'];
    $lng = $_POST['lng'];
    
    $conn->query("
        UPDATE order_delivery_assignments SET 
            current_lat = $lat,
            current_lng = $lng,
            location_updated_at = NOW()
        WHERE order_id = $order_id
    ");
    
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Delivery Agent App | Elixir Hub</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .delivery-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .time-window {
            background: #e3f2fd;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.9em;
            display: inline-block;
        }
        #map {
            height: 300px;
            width: 100%;
            margin: 15px 0;
        }
        .btn-block {
            display: block;
            width: 100%;
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Your Deliveries</h1>
        <p>Logged in as: <strong><?= $agent['name'] ?></strong></p>
        
        <?php if (empty($deliveries)): ?>
            <div class="alert info">No deliveries assigned</div>
        <?php else: ?>
            <?php foreach ($deliveries as $delivery): ?>
                <div class="delivery-card">
                    <h3>Order #<?= $delivery['order_id'] ?></h3>
                    <p><?= $delivery['product_name'] ?></p>
                    <?php if ($delivery['delivery_time_window']): ?>
                        <span class="time-window">
                            <?= strtoupper($delivery['delivery_time_window']) ?>
                        </span>
                    <?php endif; ?>
                    
                    <div class="delivery-info">
                        <p><strong>Customer:</strong> <?= $delivery['buyer_name'] ?></p>
                        <p><strong>Address:</strong> <?= $delivery['address'] ?></p>
                        <p><strong>Phone:</strong> 
                            <a href="tel:<?= $delivery['buyer_phone'] ?>">
                                <?= $delivery['buyer_phone'] ?>
                            </a>
                        </p>
                    </div>
                    
                    <div id="map-<?= $delivery['order_id'] ?>" class="map"></div>
                    
                    <div class="action-buttons">
                        <button class="btn-primary btn-block start-navigation" 
                                data-order="<?= $delivery['order_id'] ?>">
                            <i class="fas fa-road"></i> Start Navigation
                        </button>
                        <a href="delivery-signature.php?order_id=<?= $delivery['order_id'] ?>" 
                           class="btn-success btn-block">
                            <i class="fas fa-signature"></i> Collect Signature
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        // Initialize maps for each delivery
        document.querySelectorAll('.delivery-card').forEach(card => {
            const orderId = card.querySelector('h3').textContent.split('#')[1];
            const map = L.map(`map-${orderId}`).setView([20.5937, 78.9629], 5);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap'
            }).addTo(map);
        });
        
        // Handle location updates
        document.querySelectorAll('.start-navigation').forEach(btn => {
            btn.addEventListener('click', function() {
                const orderId = this.dataset.order;
                
                if (navigator.geolocation) {
                    // Update location every 30 seconds
                    const watchId = navigator.geolocation.watchPosition(
                        position => {
                            const { latitude, longitude } = position.coords;
                            
                            fetch('delivery-agent-app.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: `order_id=${orderId}&lat=${latitude}&lng=${longitude}`
                            });
                        },
                        error => {
                            console.error('Geolocation error:', error);
                        },
                        { enableHighAccuracy: true }
                    );
                    
                    // Stop tracking after 2 hours
                    setTimeout(() => {
                        navigator.geolocation.clearWatch(watchId);
                    }, 7200000);
                    
                    alert('Navigation started - your location is now being shared');
                } else {
                    alert('Geolocation is not supported by your device');
                }
            });
        });
    </script>
</body>
</html>
<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

$order_id = $_GET['order_id'] ?? null;
if (!$order_id) {
    header("Location: dashboard.php");
    exit;
}

// Verify order ownership
$order = $conn->query("
    SELECT o.*, p.name as product_name, 
           u.name as buyer_name, da.current_lat, da.current_lng,
           da.location_updated_at, ag.name as agent_name, ag.phone as agent_phone
    FROM orders o
    JOIN products p ON o.product_id = p.id
    JOIN users u ON o.buyer_id = u.id
    LEFT JOIN order_delivery_assignments da ON da.order_id = o.id
    LEFT JOIN delivery_agents ag ON da.agent_id = ag.id
    WHERE o.id = $order_id AND 
          (o.buyer_id = {$_SESSION['user_id']} OR p.seller_id = {$_SESSION['user_id']})
")->fetch_assoc();

if (!$order) {
    header("Location: dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Delivery Tracking | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
    <style>
        #map-container {
            height: 400px;
            border-radius: 8px;
            margin: 20px 0;
            border: 1px solid #ddd;
        }
        .delivery-time-window {
            background: #e3f2fd;
            padding: 10px;
            border-radius: 8px;
            margin: 10px 0;
            display: inline-block;
        }
        .tracking-status {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 15px 0;
        }
        .location-update {
            color: #666;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <h1>Delivery Tracking</h1>
        <h2>Order #<?= $order_id ?> - <?= htmlspecialchars($order['product_name']) ?></h2>
        
        <?php if ($order['delivery_time_window']): ?>
            <div class="delivery-time-window">
                <i class="fas fa-clock"></i>
                <strong>Delivery Window:</strong> 
                <?= strtoupper($order['delivery_time_window']) ?>
            </div>
        <?php endif; ?>
        
        <div class="tracking-status">
            <?php if ($order['current_lat']): ?>
                <i class="fas fa-truck-moving" style="color: #6c5ce7; font-size: 1.5em;"></i>
                <div>
                    <strong>Delivery Agent:</strong> <?= $order['agent_name'] ?> 
                    (<?= $order['agent_phone'] ?>)
                    <div class="location-update">
                        Last updated: <?= date('M d, Y h:i A', strtotime($order['location_updated_at'])) ?>
                    </div>
                </div>
            <?php else: ?>
                <i class="fas fa-map-marker-alt" style="color: #ccc;"></i>
                <div>Waiting for delivery agent to start tracking</div>
            <?php endif; ?>
        </div>
        
        <div id="map-container"></div>
        
        <?php if ($order['signature_data']): ?>
            <div class="signature-section">
                <h3>Delivery Confirmation</h3>
                <img src="<?= $order['signature_data'] ?>" alt="Delivery Signature" 
                     style="max-width: 100%; background: white; padding: 10px; border: 1px solid #ddd;">
                <p>Signed on <?= date('M d, Y h:i A', strtotime($order['signature_timestamp'])) ?></p>
            </div>
        <?php elseif ($_SESSION['user_id'] === $order['buyer_id']): ?>
            <div class="signature-pad-container">
                <h3>Signature Required on Delivery</h3>
                <canvas id="signature-pad" width="400" height="200" 
                        style="border: 1px solid #ddd; background: white;"></canvas>
                <div>
                    <button id="clear-signature" class="btn-warning">Clear</button>
                    <button id="save-signature" class="btn-success">Confirm Delivery</button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@2.3.2/dist/signature_pad.min.js"></script>
    <script>
        // Initialize map
        const map = L.map('map-container').setView([<?= $order['current_lat'] ?? 20.5937 ?>, 
                                                   <?= $order['current_lng'] ?? 78.9629 ?>], 13);
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(map);

        <?php if ($order['current_lat']): ?>
            // Add delivery marker
            const marker = L.marker([<?= $order['current_lat'] ?>, <?= $order['current_lng'] ?>])
                .addTo(map)
                .bindPopup("Delivery Agent: <?= $order['agent_name'] ?>");
            
            // Add estimated route (simplified)
            const route = L.polyline(
                [
                    [<?= $order['current_lat'] ?>, <?= $order['current_lng'] ?>],
                    [<?= rand($order['current_lat']-0.1, $order['current_lat']+0.1) ?>, 
                     <?= rand($order['current_lng']-0.1, $order['current_lng']+0.1) ?>]
                ],
                {color: '#6c5ce7', dashArray: '5, 5'}
            ).addTo(map);
        <?php endif; ?>

        // Initialize signature pad
        const canvas = document.getElementById('signature-pad');
        if (canvas) {
            const signaturePad = new SignaturePad(canvas);
            
            document.getElementById('clear-signature').addEventListener('click', () => {
                signaturePad.clear();
            });
            
            document.getElementById('save-signature').addEventListener('click', () => {
                if (signaturePad.isEmpty()) {
                    alert('Please provide your signature first');
                    return;
                }
                
                const signatureData = signaturePad.toDataURL();
                
                fetch('save-signature.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `order_id=<?= $order_id ?>&signature=${encodeURIComponent(signatureData)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert('Error saving signature: ' + data.error);
                    }
                });
            });
        }
        
        // Real-time updates for seller/admin
        <?php if ($_SESSION['role'] === 'seller' || $_SESSION['role'] === 'admin'): ?>
            function updateLocation() {
                fetch(`get-delivery-location.php?order_id=<?= $order_id ?>`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.lat && data.lng) {
                            if (marker) {
                                marker.setLatLng([data.lat, data.lng]);
                            } else {
                                marker = L.marker([data.lat, data.lng]).addTo(map)
                                    .bindPopup("Delivery Agent: <?= $order['agent_name'] ?>");
                            }
                            map.setView([data.lat, data.lng], 13);
                        }
                    });
            }
            
            // Update every 30 seconds
            setInterval(updateLocation, 30000);
        <?php endif; ?>
    </script>
</body>
</html>
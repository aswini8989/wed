<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';
redirectIfNotLoggedIn();

$order_id = $_GET['id'] ?? null;
if (!$order_id) {
    header("Location: dashboard.php");
    exit;
}

// Get order details with delivery attempts count
$stmt = $conn->prepare("
    SELECT o.*, p.name as product_name, p.price_per_kg, 
           u1.name as buyer_name, u2.name as seller_name,
           p.seller_id, COUNT(da.id) as delivery_attempts,
           ag.name as delivery_agent_name, ag.phone as delivery_agent_phone
    FROM orders o
    JOIN products p ON o.product_id = p.id
    JOIN users u1 ON o.buyer_id = u1.id
    JOIN users u2 ON p.seller_id = u2.id
    LEFT JOIN delivery_attempts da ON da.order_id = o.id
    LEFT JOIN order_delivery_assignments oda ON oda.order_id = o.id
    LEFT JOIN delivery_agents ag ON oda.agent_id = ag.id
    WHERE o.id = ? AND (o.buyer_id = ? OR p.seller_id = ?)
    GROUP BY o.id
");
$stmt->bind_param("iii", $order_id, $_SESSION['user_id'], $_SESSION['user_id']);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    header("Location: dashboard.php");
    exit;
}

// Get status updates
$updates = $conn->query("
    SELECT * FROM order_updates 
    WHERE order_id = $order_id 
    ORDER BY created_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Get delivery attempts
$attempts = $conn->query("
    SELECT * FROM delivery_attempts
    WHERE order_id = $order_id
    ORDER BY attempt_date DESC
")->fetch_all(MYSQLI_ASSOC);

// Handle status update (for sellers)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SESSION['role'] === 'seller') {
    $new_status = $_POST['status'];
    $update_text = $_POST['update_text'];
    $tracking_number = $_POST['tracking_number'] ?? null;
    $carrier = $_POST['carrier'] ?? null;
    $estimated_delivery = $_POST['estimated_delivery'] ?? null;
    $failure_reason = $_POST['failure_reason'] ?? null;
    $delivery_time_window = $_POST['delivery_time_window'] ?? null;
    
    $conn->begin_transaction();
    try {
        // Update order status
        $stmt = $conn->prepare("
            UPDATE orders 
            SET status = ?, 
                tracking_number = ?,
                carrier = ?,
                estimated_delivery = ?,
                delivery_attempts = delivery_attempts + ?,
                delivery_time_window = ?
            WHERE id = ?
        ");
        $increment_attempt = ($new_status === 'delivered' || $new_status === 'failed') ? 1 : 0;
        $stmt->bind_param(
            "ssssisi", 
            $new_status, 
            $tracking_number,
            $carrier,
            $estimated_delivery,
            $increment_attempt,
            $delivery_time_window,
            $order_id
        );
        $stmt->execute();
        
        // Add status update
        $update_text = $failure_reason 
            ? "Delivery failed: " . $failure_reason 
            : $update_text;
            
        $stmt = $conn->prepare("
            INSERT INTO order_updates (order_id, status, update_text)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param("iss", $order_id, $new_status, $update_text);
        $stmt->execute();
        
        // For failed attempts, log separately
        if ($new_status === 'failed') {
            $stmt = $conn->prepare("
                INSERT INTO delivery_attempts 
                (order_id, status, reason, rescheduled_date)
                VALUES (?, 'failed', ?, ?)
            ");
            $reschedule_date = $_POST['reschedule_date'] ?? null;
            $stmt->bind_param("iss", $order_id, $failure_reason, $reschedule_date);
            $stmt->execute();
        }
        
        // Calculate total amount
        $total_amount = $order['price_per_kg'] * $order['quantity_kg'];
        
        // Send appropriate notification
        $notificationSystem = new NotificationSystem($conn);
        switch ($new_status) {
            case 'processing':
                $notificationSystem->create(
                    $order['buyer_id'],
                    'Preparing Order #' . $order_id,
                    "Your COD order is being prepared. Amount due: ₹$total_amount",
                    "order-tracking.php?id=$order_id"
                );
                break;
                
            case 'shipped':
                $delivery_date = $estimated_delivery 
                    ? date('M d, Y', strtotime($estimated_delivery)) 
                    : 'soon';
                $notificationSystem->create(
                    $order['buyer_id'],
                    'Order #' . $order_id . ' Shipped',
                    "Prepare ₹$total_amount for delivery ($delivery_date). " .
                    ($tracking_number ? "Tracking: $tracking_number" : ""),
                    "order-tracking.php?id=$order_id"
                );
                break;
                
            case 'delivered':
                $notificationSystem->create(
                    $order['buyer_id'],
                    'Order #' . $order_id . ' Delivered',
                    "Thank you for your payment of ₹$total_amount",
                    "order-tracking.php?id=$order_id"
                );
                $notificationSystem->create(
                    $order['seller_id'],
                    'COD Payment Collected #' . $order_id,
                    "Successfully collected ₹$total_amount from buyer",
                    "order-tracking.php?id=$order_id"
                );
                break;
                
            case 'cancelled':
                $notificationSystem->create(
                    $order['buyer_id'],
                    'Order #' . $order_id . ' Cancelled',
                    "Your COD order was cancelled. Reason: " . ($failure_reason ?: "Not specified"),
                    "order-tracking.php?id=$order_id"
                );
                break;
        }
        
        $conn->commit();
        $_SESSION['success'] = "Order status updated!";
        header("Location: order-tracking.php?id=$order_id");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Failed to update order: " . $e->getMessage();
    }
}

$total_amount = $order['price_per_kg'] * $order['quantity_kg'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Order #<?= $order_id ?> | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .cod-badge {
            background: #ffc107;
            color: #856404;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
        }
        .amount-due {
            font-size: 18px;
            color: #28a745;
            font-weight: bold;
        }
        .attempt-item {
            padding: 15px;
            border-left: 3px solid #ddd;
            margin-bottom: 10px;
        }
        .attempt-item.failed {
            border-color: #dc3545;
            background: #fff5f5;
        }
        .attempt-item.delivered {
            border-color: #28a745;
            background: #f5fff7;
        }
        .delivery-attempts {
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .delivery-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            border-left: 3px solid #6c5ce7;
        }
        .delivery-confirm {
            margin-top: 15px;
        }
        .delivery-confirm button {
            display: flex;
            align-items: center;
            gap: 8px;
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
        .star-rating {
            direction: rtl;
            unicode-bidi: bidi-override;
            display: inline-block;
            font-size: 2em;
            margin: 10px 0;
        }
        .star-rating input {
            display: none;
        }
        .star-rating label {
            color: #ddd;
            cursor: pointer;
        }
        .star-rating input:checked ~ label,
        .star-rating label:hover,
        .star-rating label:hover ~ label {
            color: #ffc107;
        }
        .rating-display .stars {
            font-size: 1.5em;
            color: #ffc107;
            margin: 10px 0;
        }
        .signature-pad-container {
            margin: 20px 0;
        }
        #signature-pad {
            border: 1px solid #ddd;
            background: white;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container tracking-container">
        <div class="order-card highlight-card">
            <h2>
                Order #<?= $order_id ?>
                <span class="cod-badge">CASH ON DELIVERY</span>
            </h2>
            <p><strong>Product:</strong> <?= htmlspecialchars($order['product_name']) ?></p>
            <p><strong>Quantity:</strong> <?= $order['quantity_kg'] ?> kg</p>
            <p><strong>Unit Price:</strong> ₹<?= number_format($order['price_per_kg'], 2) ?>/kg</p>
            <p class="amount-due">Amount Due: ₹<?= number_format($total_amount, 2) ?></p>
            
            <?php if ($order['tracking_number']): ?>
                <p><strong>Tracking Number:</strong> 
                    <span class="tracking-number"><?= $order['tracking_number'] ?></span>
                </p>
                <p><strong>Carrier:</strong> <?= $order['carrier'] ?></p>
            <?php endif; ?>
            
            <?php if ($order['estimated_delivery']): ?>
                <p><strong>Estimated Delivery:</strong> 
                    <?= date('M d, Y', strtotime($order['estimated_delivery'])) ?>
                </p>
            <?php endif; ?>
            
            <?php if ($order['delivery_time_window']): ?>
                <div class="delivery-time-window">
                    <i class="fas fa-clock"></i>
                    <strong>Delivery Window:</strong> 
                    <?= strtoupper($order['delivery_time_window']) ?>
                </div>
            <?php endif; ?>
            
            <div class="current-status">
                <h3>Current Status: 
                    <span class="status-<?= $order['status'] ?>">
                        <?= ucfirst($order['status']) ?>
                    </span>
                </h3>
            </div>
        </div>
        
        <?php if ($order['status'] === 'shipped' || $order['status'] === 'delivered'): ?>
            <div class="delivery-info">
                <h4>Delivery Information</h4>
                <?php if ($order['delivery_agent_name']): ?>
                    <p><strong>Delivery Agent:</strong> <?= htmlspecialchars($order['delivery_agent_name']) ?></p>
                    <p><strong>Contact Phone:</strong> 
                        <a href="tel:<?= $order['delivery_agent_phone'] ?>">
                            <?= $order['delivery_agent_phone'] ?>
                        </a>
                    </p>
                    <?php if ($order['status'] === 'shipped' && !$order['delivery_confirmed'] && $_SESSION['user_id'] === $order['buyer_id']): ?>
                        <form method="POST" action="confirm-delivery.php" class="delivery-confirm">
                            <input type="hidden" name="order_id" value="<?= $order_id ?>">
                            <button type="submit" class="btn-success">
                                <i class="fas fa-check-circle"></i> Confirm Delivery
                            </button>
                        </form>
                    <?php elseif ($order['delivery_confirmed']): ?>
                        <p class="text-success">
                            <i class="fas fa-check-circle"></i> 
                            Confirmed on <?= date('M d, Y h:i A', strtotime($order['delivery_confirmed_at'])) ?>
                        </p>
                    <?php endif; ?>
                <?php else: ?>
                    <p>Delivery agent information will be available once assigned</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($attempts)): ?>
            <div class="delivery-attempts">
                <h3>Delivery Attempts</h3>
                <?php foreach ($attempts as $attempt): ?>
                    <div class="attempt-item <?= $attempt['status'] ?>">
                        <p><strong><?= ucfirst($attempt['status']) ?></strong> - 
                            <?= date('M d, Y h:i A', strtotime($attempt['attempt_date'])) ?>
                        </p>
                        <?php if ($attempt['reason']): ?>
                            <p>Reason: <?= htmlspecialchars($attempt['reason']) ?></p>
                        <?php endif; ?>
                        <?php if ($attempt['rescheduled_date']): ?>
                            <p>Rescheduled: <?= date('M d, Y', strtotime($attempt['rescheduled_date'])) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($order['status'] === 'delivered' && $order['delivery_photo']): ?>
            <div class="order-card">
                <h3>Delivery Proof</h3>
                <img src="<?= $order['delivery_photo'] ?>" alt="Delivery Proof" style="max-width: 100%; border-radius: 8px;">
                <p><small>Captured on <?= date('M d, Y h:i A', strtotime($order['delivery_confirmed_at'])) ?></small></p>
            </div>
        <?php endif; ?>
        
        <div class="order-card">
            <h3>Order Timeline</h3>
            <div class="timeline">
                <?php foreach ($updates as $update): ?>
                    <div class="timeline-item status-<?= $update['status'] ?>">
                        <div class="timeline-dot">
                            <i class="fas fa-<?= getStatusIcon($update['status']) ?>"></i>
                        </div>
                        <div class="timeline-content">
                            <p><strong><?= ucfirst($update['status']) ?></strong></p>
                            <p><?= htmlspecialchars($update['update_text']) ?></p>
                            <small><?= date('M d, Y h:i A', strtotime($update['created_at'])) ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="timeline-item status-pending">
                    <div class="timeline-dot">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div class="timeline-content">
                        <p><strong>Order Placed (COD)</strong></p>
                        <p>Order was successfully placed. Amount due: ₹<?= number_format($total_amount, 2) ?></p>
                        <small><?= date('M d, Y h:i A', strtotime($order['order_date'])) ?></small>
                    </div>
                </div>
            </div>
            
            <?php if ($_SESSION['role'] === 'seller'): ?>
                <div class="status-form">
                    <h4>Update Order Status</h4>
                    <?php if (isset($error)): ?>
                        <div class="alert error"><?= $error ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="input-group">
                            <label>New Status</label>
                            <select name="status" id="statusSelect" required>
                                <option value="processing">Processing</option>
                                <option value="shipped">Shipped</option>
                                <option value="delivered">Delivered</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        
                        <?php if ($order['status'] === 'processing'): ?>
                            <div class="input-group">
                                <label>Delivery Time Window</label>
                                <select name="delivery_time_window">
                                    <option value="">Select window...</option>
                                    <option value="morning">Morning (9AM-12PM)</option>
                                    <option value="afternoon">Afternoon (12PM-4PM)</option>
                                    <option value="evening">Evening (4PM-8PM)</option>
                                </select>
                            </div>
                        <?php endif; ?>
                        
                        <div class="input-group">
                            <label>Update Message</label>
                            <textarea name="update_text" rows="3" required></textarea>
                        </div>
                        
                        <div id="shippingFields" style="display:none;">
                            <div class="input-group">
                                <label>Tracking Number</label>
                                <input type="text" name="tracking_number">
                            </div>
                            
                            <div class="input-group">
                                <label>Carrier</label>
                                <input type="text" name="carrier">
                            </div>
                            
                            <div class="input-group">
                                <label>Estimated Delivery</label>
                                <input type="date" name="estimated_delivery">
                            </div>
                        </div>
                        
                        <div id="failureReason" style="display:none;">
                            <div class="input-group">
                                <label>Reason for Cancellation/Failure</label>
                                <textarea name="failure_reason" rows="2"></textarea>
                            </div>
                            
                            <div class="input-group">
                                <label>Reschedule Date</label>
                                <input type="date" name="reschedule_date">
                            </div>
                        </div>
                        
                        <button type="submit" class="btn-primary">Update Status</button>
                    </form>
                </div>
                
                <script>
                    document.getElementById('statusSelect').addEventListener('change', function() {
                        const status = this.value;
                        document.getElementById('shippingFields').style.display = 
                            (status === 'shipped') ? 'block' : 'none';
                        document.getElementById('failureReason').style.display = 
                            (status === 'cancelled' || status === 'failed') ? 'block' : 'none';
                    });
                </script>
            <?php endif; ?>
            
            <?php if ($_SESSION['role'] === 'buyer' && $order['status'] === 'delivered' && !$order['customer_rating']): ?>
                <div class="rating-section">
                    <h3>Rate Your Delivery Experience</h3>
                    <form method="POST" action="save-rating.php">
                        <input type="hidden" name="order_id" value="<?= $order_id ?>">
                        
                        <div class="star-rating">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <input type="radio" id="star<?= $i ?>" name="rating" value="<?= $i ?>">
                                <label for="star<?= $i ?>"><i class="fas fa-star"></i></label>
                            <?php endfor; ?>
                        </div>
                        
                        <div class="input-group">
                            <label>Feedback (Optional)</label>
                            <textarea name="feedback" rows="3"></textarea>
                        </div>
                        
                        <button type="submit" class="btn-primary">Submit Rating</button>
                    </form>
                </div>
            <?php elseif ($order['customer_rating']): ?>
                <div class="rating-display">
                    <h3>Your Rating</h3>
                    <div class="stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star <?= $i <= $order['customer_rating'] ? 'text-warning' : 'text-muted' ?>"></i>
                        <?php endfor; ?>
                    </div>
                    <?php if ($order['customer_feedback']): ?>
                        <p>"<?= htmlspecialchars($order['customer_feedback']) ?>"</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($_SESSION['user_id'] === $order['buyer_id'] && $order['status'] === 'delivered' && !$order['signature_data']): ?>
                <div class="signature-pad-container">
                    <h3>Signature Required</h3>
                    <canvas id="signature-pad" width="400" height="200"></canvas>
                    <div style="margin-top: 10px;">
                        <button id="clear-signature" class="btn-warning">Clear</button>
                        <button id="save-signature" class="btn-success">Confirm Delivery</button>
                    </div>
                </div>
                
                <script src="https://cdn.jsdelivr.net/npm/signature_pad@2.3.2/dist/signature_pad.min.js"></script>
                <script>
                    const canvas = document.getElementById('signature-pad');
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
                </script>
            <?php elseif ($order['signature_data']): ?>
                <div class="signature-display">
                    <h3>Delivery Confirmation</h3>
                    <img src="<?= $order['signature_data'] ?>" alt="Delivery Signature" 
                         style="max-width: 100%; background: white; padding: 10px; border: 1px solid #ddd;">
                    <p>Signed on <?= date('M d, Y h:i A', strtotime($order['signature_timestamp'])) ?></p>
                </div>
            <?php endif; ?>
            
            <?php if ($_SESSION['user_id'] === $order['buyer_id'] && $order['status'] === 'delivered' && !$order['return_requested']): ?>
                <div class="return-section" style="margin-top: 20px;">
                    <a href="return-request.php?order_id=<?= $order_id ?>" class="btn-warning">
                        <i class="fas fa-undo"></i> Request Return/Refund
                    </a>
                </div>
            <?php elseif ($order['return_requested']): ?>
                <div class="alert info">
                    Return requested: <?= ucfirst(str_replace('_', ' ', $order['return_reason'])) ?>
                    <a href="return-request.php?order_id=<?= $order_id ?>">View Status</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    // Add to order-tracking.php before </body>
<?php if ($order['status'] === 'shipped' && $order['current_lat']): ?>
<script>
// Establish WebSocket connection
const socket = new WebSocket('wss://yourdomain.com/tracking');

socket.onopen = function() {
    socket.send(JSON.stringify({
        action: 'subscribe',
        order_id: <?= $order_id ?>
    }));
};

socket.onmessage = function(event) {
    const data = JSON.parse(event.data);
    if (data.type === 'location_update') {
        updateMap(data.lat, data.lng);
    }
};

function updateMap(lat, lng) {
    if (typeof marker === 'undefined') {
        // Initialize map if not already done
        initMap(lat, lng);
    } else {
        marker.setLatLng([lat, lng]);
        map.panTo([lat, lng]);
    }
}

function initMap(lat, lng) {
    const map = L.map('map-container').setView([lat, lng], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
    marker = L.marker([lat, lng]).addTo(map);
}
</script>

<div id="map-container" style="height: 300px; margin: 20px 0;"></div>
<?php endif; ?>
</body>
</html>

<?php
function getStatusIcon($status) {
    switch ($status) {
        case 'processing': return 'cog';
        case 'shipped': return 'truck';
        case 'delivered': return 'check-circle';
        case 'cancelled': return 'times-circle';
        default: return 'clock';
    }
}
?>
<?php
session_start();

// Database configuration
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'elixir_hub';

// Create connection
$conn = new mysqli($host, $user, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset
$conn->set_charset("utf8mb4");

// Redirect helper
function redirect($url) {
    header("Location: $url");
    exit();
}
// Add to includes/config.php
function updateDeliveryStatusAutomatically($conn) {
    // Check for delayed shipments
    $delayed = $conn->query("
        SELECT o.id, o.buyer_id, o.tracking_number, o.estimated_delivery 
        FROM orders o
        WHERE o.status = 'shipped'
        AND o.estimated_delivery < NOW()
        AND o.delivery_delay_notified = FALSE
    ")->fetch_all(MYSQLI_ASSOC);

    $notificationSystem = new NotificationSystem($conn);
    foreach ($delayed as $order) {
        $conn->begin_transaction();
        try {
            // Mark as delayed
            $conn->query("
                UPDATE orders SET 
                    status = 'delayed',
                    delivery_delay_notified = TRUE
                WHERE id = {$order['id']}
            ");

            // Add status update
            $conn->query("
                INSERT INTO order_updates (order_id, status, update_text)
                VALUES ({$order['id']}, 'delayed', 
                'Delivery delayed due to shipping carrier. New ETA will be provided soon.')
            ");

            // Notify buyer
            $notificationSystem->create(
                $order['buyer_id'],
                'Delivery Delay #' . $order['id'],
                "Your order #{$order['id']} is delayed. We're working with the carrier to update the delivery date.",
                "order-tracking.php?id={$order['id']}"
            );

            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Failed to update delayed order: " . $e->getMessage());
        }
    }

    // Check for pending deliveries that should be marked as shipped
    $pending_shipments = $conn->query("
        SELECT o.id, o.buyer_id 
        FROM orders o
        WHERE o.status = 'processing'
        AND o.order_date < DATE_SUB(NOW(), INTERVAL 2 DAY)
    ")->fetch_all(MYSQLI_ASSOC);

    foreach ($pending_shipments as $order) {
        $conn->begin_transaction();
        try {
            // Mark as shipped
            $conn->query("
                UPDATE orders SET 
                    status = 'shipped',
                    estimated_delivery = DATE_ADD(NOW(), INTERVAL 3 DAY)
                WHERE id = {$order['id']}
            ");

            // Add status update
            $conn->query("
                INSERT INTO order_updates (order_id, status, update_text)
                VALUES ({$order['id']}, 'shipped', 
                'Your order has been shipped and should arrive in 3 business days.')
            ");

            // Notify buyer
            $notificationSystem->create(
                $order['buyer_id'],
                'Order Shipped #' . $order['id'],
                "Your order has been shipped and is on its way!",
                "order-tracking.php?id={$order['id']}"
            );

            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Failed to update pending order: " . $e->getMessage());
        }
    }
}

// Call this function in your order-tracking.php after auth checks
updateDeliveryStatusAutomatically($conn);

?>
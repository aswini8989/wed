<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json');

$order_id = $_GET['order_id'] ?? null;
if (!$order_id) {
    echo json_encode(['error' => 'Invalid order']);
    exit;
}

// Verify order ownership
$order = $conn->query("
    SELECT o.* FROM orders o
    JOIN products p ON o.product_id = p.id
    WHERE o.id = $order_id AND 
          (o.buyer_id = {$_SESSION['user_id']} OR p.seller_id = {$_SESSION['user_id']})
")->fetch_assoc();

if (!$order) {
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Get latest location
$location = $conn->query("
    SELECT current_lat as lat, current_lng as lng
    FROM order_delivery_assignments
    WHERE order_id = $order_id
")->fetch_assoc();

echo json_encode($location ?: ['error' => 'No location data']);
?>
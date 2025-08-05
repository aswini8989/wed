// After successfully creating a COD order
$notificationSystem->create(
    $product['seller_id'], // Notify seller
    'New COD Order #' . $order_id,
    'New cash-on-delivery order for ' . $_POST['quantity'] . 'kg of ' . $product['name'] . 
    '. Amount to collect: ₹' . ($product['price_per_kg'] * $_POST['quantity']),
    'order-tracking.php?id=' . $order_id
);

// Notify buyer
$notificationSystem->create(
    $_SESSION['user_id'],
    'Order #' . $order_id . ' Placed',
    'Your COD order is confirmed. Pay ₹' . ($product['price_per_kg'] * $_POST['quantity']) . 
    ' on delivery. Expected delivery: ' . $delivery_date,
    'order-tracking.php?id=' . $order_id
);
<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

header('Content-Type: application/json');

$order_id = $_GET['order_id'] ?? null;
if (!$order_id) {
    echo json_encode(['updated' => false]);
    exit;
}

// Get latest update timestamp
$stmt = $conn->prepare("
    SELECT MAX(created_at) as last_update 
    FROM order_updates 
    WHERE order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

echo json_encode([
    'updated' => $result['last_update'] > ($_GET['last_check'] ?? 0),
    'last_update' => $result['last_update']
]);
?>
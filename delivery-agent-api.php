// delivery-agent-api.php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

// API Authentication
$api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
$agent = $conn->query("SELECT * FROM delivery_agents WHERE api_key = '$api_key'")->fetch_assoc();

if (!$agent) {
    http_response_code(401);
    die(json_encode(['error' => 'Invalid API key']));
}

$endpoint = $_GET['action'] ?? '';

switch ($endpoint) {
    case 'get_assignments':
        $assignments = $conn->query("
            SELECT o.id as order_id, o.delivery_time_window, 
                   p.name as product_name, u.name as buyer_name,
                   u.address, u.lat, u.lng, u.phone as buyer_phone,
                   o.quantity_kg, (p.price_per_kg * o.quantity_kg) as cod_amount
            FROM order_delivery_assignments da
            JOIN orders o ON da.order_id = o.id
            JOIN products p ON o.product_id = p.id
            JOIN users u ON o.buyer_id = u.id
            WHERE da.agent_id = {$agent['id']}
            AND o.status = 'shipped'
            ORDER BY o.delivery_time_window, o.estimated_delivery
        ")->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode(['assignments' => $assignments]);
        break;
        
    case 'update_location':
        $lat = $_POST['lat'] ?? null;
        $lng = $_POST['lng'] ?? null;
        $order_id = $_POST['order_id'] ?? null;
        
        if (!$lat || !$lng) {
            http_response_code(400);
            die(json_encode(['error' => 'Missing coordinates']));
        }
        
        $conn->query("
            UPDATE order_delivery_assignments SET 
                current_lat = $lat,
                current_lng = $lng,
                location_updated_at = NOW()
            WHERE order_id = $order_id AND agent_id = {$agent['id']}
        ");
        
        echo json_encode(['success' => true]);
        break;
        
    case 'complete_delivery':
        $order_id = $_POST['order_id'] ?? null;
        $signature = $_POST['signature'] ?? null;
        $photo = $_POST['photo'] ?? null;
        
        if (!$order_id) {
            http_response_code(400);
            die(json_encode(['error' => 'Missing order ID']));
        }
        
        $conn->begin_transaction();
        try {
            // Mark order as delivered
            $conn->query("
                UPDATE orders SET 
                    status = 'delivered',
                    delivery_confirmed = TRUE,
                    delivery_confirmed_at = NOW(),
                    signature_data = " . ($signature ? "'$signature'" : "NULL") . ",
                    delivery_photo = " . ($photo ? "'$photo'" : "NULL") . "
                WHERE id = $order_id
            ");
            
            // Update assignment
            $conn->query("
                UPDATE order_delivery_assignments SET 
                    completed_at = NOW()
                WHERE order_id = $order_id
            ");
            
            // Add status update
            $conn->query("
                INSERT INTO order_updates (order_id, status, update_text)
                VALUES ($order_id, 'delivered', 'Delivery completed by agent')
            ");
            
            $conn->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
        
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Invalid endpoint']);
}
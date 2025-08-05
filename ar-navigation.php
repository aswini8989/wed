// ar-navigation.php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

// API endpoint for AR navigation data
$order_id = $_GET['order_id'] ?? null;
$agent_id = $_GET['agent_id'] ?? null;

if (!$order_id || !$agent_id) {
    http_response_code(400);
    die(json_encode(['error' => 'Missing parameters']));
}

// Verify assignment
$assignment = $conn->query("
    SELECT o.*, u.address, u.lat, u.lng, u.ar_landmarks
    FROM order_delivery_assignments da
    JOIN orders o ON da.order_id = o.id
    JOIN users u ON o.buyer_id = u.id
    WHERE da.order_id = $order_id
    AND da.agent_id = $agent_id
    AND da.completed_at IS NULL
")->fetch_assoc();

if (!$assignment) {
    http_response_code(404);
    die(json_encode(['error' => 'Assignment not found']));
}

// Generate AR navigation data
$ar_data = [
    'destination' => [
        'latitude' => (float)$assignment['lat'],
        'longitude' => (float)$assignment['lng'],
        'address' => $assignment['address']
    ],
    'landmarks' => json_decode($assignment['ar_landmarks'], true) ?? [],
    'navigation_instructions' => generateARNavigationInstructions($assignment),
    'delivery_notes' => $assignment['delivery_notes'] ?? ''
];

function generateARNavigationInstructions($assignment) {
    // In production, integrate with Mapbox/Google AR Navigation API
    return [
        [
            'type' => 'text',
            'content' => 'Head northwest toward the main road',
            'distance' => 150,
            'estimated_time' => 60
        ],
        [
            'type' => 'arrow',
            'direction' => 'right',
            'distance' => 50
        ],
        [
            'type' => 'landmark',
            'identifier' => 'blue_building',
            'action' => 'turn_left'
        ]
    ];
}

echo json_encode($ar_data);
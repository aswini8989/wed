<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

// Only admin/sellers can access
if ($_SESSION['role'] !== 'seller' && $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

// Handle agent addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_agent'])) {
    $name = $_POST['name'];
    $phone = $_POST['phone'];
    $vehicle = $_POST['vehicle_number'] ?? null;
    
    $stmt = $conn->prepare("
        INSERT INTO delivery_agents (name, phone, vehicle_number)
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param("sss", $name, $phone, $vehicle);
    $stmt->execute();
    $_SESSION['success'] = "Delivery agent added!";
    header("Location: delivery-agents.php");
    exit;
}

// Handle status toggle
if (isset($_GET['toggle_status'])) {
    $agent_id = $_GET['id'];
    $conn->query("
        UPDATE delivery_agents 
        SET active = NOT active 
        WHERE id = $agent_id
    ");
    header("Location: delivery-agents.php");
    exit;
}

// Get all agents
$agents = $conn->query("
    SELECT * FROM delivery_agents 
    ORDER BY active DESC, name
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Delivery Agents | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <h1>Delivery Agents Management</h1>
        
        <div class="agent-form">
            <h2>Add New Agent</h2>
            <form method="POST">
                <div class="input-group">
                    <label>Full Name</label>
                    <input type="text" name="name" required>
                </div>
                
                <div class="input-group">
                    <label>Phone Number</label>
                    <input type="tel" name="phone" required>
                </div>
                
                <div class="input-group">
                    <label>Vehicle Number (Optional)</label>
                    <input type="text" name="vehicle_number">
                </div>
                
                <button type="submit" name="add_agent" class="btn-primary">
                    Add Delivery Agent
                </button>
            </form>
        </div>
        
        <div class="agent-management">
            <h2>Current Agents</h2>
            <table class="agent-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Vehicle</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agents as $agent): ?>
                        <tr>
                            <td><?= htmlspecialchars($agent['name']) ?></td>
                            <td><?= $agent['phone'] ?></td>
                            <td><?= $agent['vehicle_number'] ?: 'N/A' ?></td>
                            <td>
                                <span class="agent-status <?= $agent['active'] ? 'active' : 'inactive' ?>">
                                    <?= $agent['active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td>
                                <a href="delivery-agents.php?toggle_status=1&id=<?= $agent['id'] ?>" 
                                   class="btn-sm <?= $agent['active'] ? 'btn-warning' : 'btn-success' ?>">
                                    <?= $agent['active'] ? 'Deactivate' : 'Activate' ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
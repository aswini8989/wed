// cod-reconciliation.php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

if ($_SESSION['role'] !== 'seller' && $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

// Handle daily reconciliation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['date'] ?? date('Y-m-d');
    $agent_id = $_POST['agent_id'] ?? null;
    
    $conn->begin_transaction();
    try {
        // Get all COD deliveries for the day
        $deliveries = $conn->query("
            SELECT o.id, o.delivery_confirmed_at, 
                   (p.price_per_kg * o.quantity_kg) as amount,
                   da.agent_id, ag.name as agent_name
            FROM orders o
            JOIN products p ON o.product_id = p.id
            JOIN order_delivery_assignments da ON da.order_id = o.id
            JOIN delivery_agents ag ON da.agent_id = ag.id
            WHERE o.status = 'delivered'
            AND DATE(o.delivery_confirmed_at) = '$date'
            " . ($agent_id ? "AND da.agent_id = $agent_id" : "") . "
            ORDER BY da.agent_id, o.delivery_confirmed_at
        ")->fetch_all(MYSQLI_ASSOC);

        // Generate reconciliation report
        $report = [];
        $total = 0;
        foreach ($deliveries as $delivery) {
            if (!isset($report[$delivery['agent_id']])) {
                $report[$delivery['agent_id']] = [
                    'agent_name' => $delivery['agent_name'],
                    'deliveries' => [],
                    'subtotal' => 0
                ];
            }
            
            $report[$delivery['agent_id']]['deliveries'][] = $delivery;
            $report[$delivery['agent_id']]['subtotal'] += $delivery['amount'];
            $total += $delivery['amount'];
        }

        // Mark as reconciled
        foreach (array_keys($report) as $agentId) {
            $conn->query("
                INSERT INTO cod_reconciliations 
                (agent_id, reconciliation_date, amount, status)
                VALUES ($agentId, '$date', {$report[$agentId]['subtotal']}, 'pending')
                ON DUPLICATE KEY UPDATE amount = VALUES(amount)
            ");
        }

        $conn->commit();
        $_SESSION['reconciliation_report'] = [
            'date' => $date,
            'report' => $report,
            'total' => $total
        ];
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Reconciliation failed: " . $e->getMessage();
    }
}

// Display form and results
?>
<!DOCTYPE html>
<html>
<head>
    <title>COD Reconciliation | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .reconciliation-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        .reconciliation-table th, 
        .reconciliation-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .reconciliation-table th {
            background-color: #f2f2f2;
        }
        .agent-total {
            font-weight: bold;
            background-color: #f9f9f9;
        }
        .grand-total {
            font-weight: bold;
            font-size: 1.2em;
            background-color: #e6f7ff;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <h1>COD Payment Reconciliation</h1>
        
        <form method="POST">
            <div class="input-group">
                <label>Date</label>
                <input type="date" name="date" value="<?= $_POST['date'] ?? date('Y-m-d') ?>">
            </div>
            
            <div class="input-group">
                <label>Agent (Optional)</label>
                <select name="agent_id">
                    <option value="">All Agents</option>
                    <?php 
                    $agents = $conn->query("SELECT id, name FROM delivery_agents ORDER BY name");
                    while ($agent = $agents->fetch_assoc()): ?>
                        <option value="<?= $agent['id'] ?>" 
                            <?= ($_POST['agent_id'] ?? '') == $agent['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($agent['name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <button type="submit" class="btn-primary">Generate Report</button>
        </form>
        
        <?php if (isset($_SESSION['reconciliation_report'])): 
            $report = $_SESSION['reconciliation_report'];
            unset($_SESSION['reconciliation_report']);
        ?>
            <h2>Reconciliation Report for <?= date('M j, Y', strtotime($report['date'])) ?></h2>
            
            <?php foreach ($report['report'] as $agentId => $agentData): ?>
                <h3><?= htmlspecialchars($agentData['agent_name']) ?></h3>
                <table class="reconciliation-table">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Time</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($agentData['deliveries'] as $delivery): ?>
                            <tr>
                                <td>#<?= $delivery['id'] ?></td>
                                <td><?= date('h:i A', strtotime($delivery['delivery_confirmed_at'])) ?></td>
                                <td>₹<?= number_format($delivery['amount'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="agent-total">
                            <td colspan="2">Subtotal</td>
                            <td>₹<?= number_format($agentData['subtotal'], 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php endforeach; ?>
            
            <div class="grand-total">
                Grand Total: ₹<?= number_format($report['total'], 2) ?>
            </div>
            
            <form method="POST" action="confirm-reconciliation.php" style="margin-top: 20px;">
                <input type="hidden" name="date" value="<?= $report['date'] ?>">
                <button type="submit" class="btn-success">Confirm Reconciliation</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
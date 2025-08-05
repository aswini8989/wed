<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';
redirectIfNotLoggedIn();

// Only delivery agents can access
$agent = $conn->query("
    SELECT * FROM delivery_agents 
    WHERE phone = '{$_SESSION['phone']}'
")->fetch_assoc();

if (!$agent) {
    header("Location: dashboard.php");
    exit;
}

$order_id = $_GET['order_id'] ?? null;
if (!$order_id) {
    header("Location: delivery-agent-app.php");
    exit;
}

// Verify order assignment
$order = $conn->query("
    SELECT o.* FROM orders o
    JOIN order_delivery_assignments da ON da.order_id = o.id
    WHERE o.id = $order_id AND da.agent_id = {$agent['id']}
")->fetch_assoc();

if (!$order) {
    header("Location: delivery-agent-app.php");
    exit;
}

// Handle photo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['delivery_photo'])) {
    $target_dir = __DIR__ . "/uploads/deliveries/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file_ext = pathinfo($_FILES['delivery_photo']['name'], PATHINFO_EXTENSION);
    $filename = "delivery_$order_id" . "_" . time() . ".$file_ext";
    $target_file = $target_dir . $filename;
    
    // Validate image
    $check = getimagesize($_FILES['delivery_photo']['tmp_name']);
    if ($check === false) {
        $error = "File is not an image.";
    } elseif ($_FILES['delivery_photo']['size'] > 5000000) {
        $error = "File is too large (max 5MB).";
    } elseif (!in_array(strtolower($file_ext), ['jpg', 'jpeg', 'png', 'gif'])) {
        $error = "Only JPG, JPEG, PNG & GIF files are allowed.";
    } elseif (move_uploaded_file($_FILES['delivery_photo']['tmp_name'], $target_file)) {
        $conn->begin_transaction();
        try {
            // Save to database
            $web_path = "/uploads/deliveries/$filename";
            $stmt = $conn->prepare("
                UPDATE orders SET 
                    delivery_photo = ?,
                    status = 'delivered',
                    delivery_confirmed = TRUE,
                    delivery_confirmed_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param("si", $web_path, $order_id);
            $stmt->execute();
            
            // Add status update
            $conn->query("
                INSERT INTO order_updates (order_id, status, update_text)
                VALUES ($order_id, 'delivered', 'Delivery completed with photo proof')
            ");
            
            // Update route stop
            $conn->query("
                UPDATE route_stops SET 
                    status = 'delivered',
                    visited_at = NOW()
                WHERE order_id = $order_id
            ");
            
            // Notify buyer and seller
            $notificationSystem = new NotificationSystem($conn);
            $notificationSystem->create(
                $order['buyer_id'],
                'Delivery Completed #' . $order_id,
                "Your order has been delivered with photo proof",
                "order-tracking.php?id=$order_id"
            );
            
            $seller_id = $conn->query("
                SELECT seller_id FROM products WHERE id = {$order['product_id']}
            ")->fetch_row()[0];
            
            $notificationSystem->create(
                $seller_id,
                'Delivery Proof #' . $order_id,
                "Delivery completed with photo proof",
                "order-tracking.php?id=$order_id"
            );
            
            $conn->commit();
            $_SESSION['success'] = "Delivery photo uploaded successfully!";
            header("Location: delivery-agent-app.php");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            unlink($target_file); // Remove uploaded file
            $error = "Failed to save delivery: " . $e->getMessage();
        }
    } else {
        $error = "Sorry, there was an error uploading your file.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Upload Delivery Proof | Elixir Hub</title>
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .preview-container {
            width: 100%;
            margin: 15px 0;
            text-align: center;
        }
        #imagePreview {
            max-width: 100%;
            max-height: 300px;
            border: 1px dashed #ddd;
        }
        .upload-btn {
            display: block;
            width: 100%;
            padding: 15px;
            text-align: center;
            border: 2px dashed #6c5ce7;
            border-radius: 8px;
            cursor: pointer;
            margin: 15px 0;
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <h1>Upload Delivery Proof</h1>
        <p>Order #<?= $order_id ?></p>
        
        <?php if (isset($error)): ?>
            <div class="alert error"><?= $error ?></div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data">
            <label for="delivery_photo" class="upload-btn">
                <i class="fas fa-camera" style="font-size: 2em;"></i>
                <p>Click to upload delivery photo</p>
                <input type="file" id="delivery_photo" name="delivery_photo" 
                       accept="image/*" capture="environment" style="display: none;" required>
            </label>
            
            <div class="preview-container">
                <img id="imagePreview" src="#" alt="Preview" style="display: none;">
            </div>
            
            <div class="input-group">
                <label>Additional Notes</label>
                <textarea name="notes" rows="2"></textarea>
            </div>
            
            <button type="submit" class="btn-primary">
                <i class="fas fa-check-circle"></i> Confirm Delivery
            </button>
        </form>
    </div>
    
    <script>
        // Show image preview
        document.getElementById('delivery_photo').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    document.getElementById('imagePreview').src = event.target.result;
                    document.getElementById('imagePreview').style.display = 'block';
                }
                reader.readAsDataURL(file);
            }
        });
    </script>
</body>
</html>
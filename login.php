<?php

if (isset($_GET['logout']) && $_GET['logout'] === 'success') {
    echo '<div class="alert success">You have been logged out successfully!</div>';
}
require_once __DIR__ . '/includes/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = "Email and password are required!";
    } else {
        $stmt = $conn->prepare("SELECT id, name, password, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['role'] = $user['role'];
                
                // Redirect based on role
                if ($user['role'] === 'buyer') {
                    header("Location: buyer/dashboard.php");
                } else {
                    header("Location: seller/dashboard.php");
                }
                exit;
            } else {
                $error = "Invalid password!";
            }
        } else {
            $error = "No account found with that email!";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login | Elixir Hub</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .role-tabs {
            display: flex;
            margin-bottom: 30px;
            animation: fadeIn 0.5s ease;
        }
        .role-tab {
            flex: 1;
            text-align: center;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
        }
        .role-tab.active {
            border-bottom: 3px solid var(--primary);
            font-weight: 600;
        }
        .role-tab:hover {
            background: rgba(108, 92, 231, 0.1);
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Welcome Back!</h2>
        
        <?php if (isset($error)): ?>
            <div class="alert error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><?= $_SESSION['success'] ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <div class="role-tabs">
            <div class="role-tab active" onclick="window.location='login.php'">Login</div>
            <div class="role-tab" onclick="window.location='register_buyer.php'">Buyer</div>
            <div class="role-tab" onclick="window.location='register_seller.php'">Seller</div>
        </div>
        
        <form method="POST" id="loginForm">
            <div class="input-group">
                <input type="email" name="email" placeholder="Email" required 
                       onfocus="this.style.borderColor='#6c5ce7'"
                       onblur="this.style.borderColor='#ddd'">
            </div>
            
            <div class="input-group" style="animation-delay: 0.1s;">
                <input type="password" name="password" placeholder="Password" required
                       onfocus="this.style.borderColor='#6c5ce7'"
                       onblur="this.style.borderColor='#ddd'">
            </div>
            
            <div class="input-group" style="animation-delay: 0.2s; text-align: right;">
                <a href="forgot_password.php" style="font-size: 14px;">Forgot password?</a>
            </div>
            
            <button type="submit" style="animation-delay: 0.3s;">
                <span id="btnText">Login</span>
                <div id="loader" style="display:none;"></div>
            </button>
        </form>
        
        <p class="link-text">Don't have an account? 
            <a href="register_buyer.php">Register as Buyer</a> or 
            <a href="register_seller.php">Seller</a>
        </p>
    </div>

    <script>
        // Form submission animation
        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = document.getElementById('btnText');
            const loader = document.getElementById('loader');
            btn.style.display = 'none';
            loader.style.display = 'block';
        });
    </script>
</body>
</html>
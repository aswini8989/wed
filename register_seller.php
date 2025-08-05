<?php
require_once __DIR__ . '/includes/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $phone = preg_replace('/[^0-9]/', '', $_POST['phone']);
    $company = trim($_POST['company']);

    if (empty($name) || empty($email) || empty($password)) {
        $error = "All fields are required!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format!";
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            $error = "Email already registered!";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $role = 'seller';
            
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, phone, company, role) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $name, $email, $hashed_password, $phone, $company, $role);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Registration successful! Please login.";
                header("Location: login.php");
                exit;
            } else {
                $error = "Database error: " . $conn->error;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Seller Registration</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <div class="container">
        <h2>Register as Seller</h2>
        
        <?php if (isset($error)): ?>
            <div class="alert error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="input-group">
                <input type="text" name="name" placeholder="Full Name" required>
            </div>
            
            <div class="input-group" style="animation-delay: 0.1s;">
                <input type="email" name="email" placeholder="Email" required>
            </div>
            
            <div class="input-group" style="animation-delay: 0.2s;">
                <input type="password" name="password" placeholder="Password" required>
            </div>
            
            <div class="input-group" style="animation-delay: 0.3s;">
                <input type="tel" name="phone" placeholder="Phone">
            </div>
            
            <div class="input-group" style="animation-delay: 0.4s;">
                <input type="text" name="company" placeholder="Company Name">
            </div>
            
            <button type="submit" style="animation-delay: 0.5s;">Register Now</button>
        </form>
        
        <p class="link-text">Already have an account? <a href="login.php">Login here</a></p>
    </div>
</body>
</html>
<?php
function redirectIfNotLoggedIn() {
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['error'] = "Please login first!";
        header("Location: ../login.php");
        exit;
    }
}

function redirectBasedOnRole() {
    if (!isset($_SESSION['role'])) {
        header("Location: login.php");
        exit;
    }
    
    if ($_SESSION['role'] === 'buyer') {
        header("Location: buyer/dashboard.php");
    } elseif ($_SESSION['role'] === 'seller') {
        header("Location: seller/dashboard.php");
    }
    exit;
}

function handleLogout() {
    session_unset();
    session_destroy();
    header("Location: login.php?logout=success");
    exit;
}

// Auto-handle logout if requested
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    handleLogout();
}
?>
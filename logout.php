<?php
/**
 * logout.php - Enhanced Logout Handler with Visual Feedback
 * Bless Mall - Quality Chinese Products | Ghana
 */

require_once 'includes/config.php';

// Handle logout
$logoutSuccess = false;

if (file_exists('includes/auth.php')) {
    require_once 'includes/auth.php';
    
    if (Auth::isLoggedIn()) {
        $userName = $_SESSION['user_name'] ?? 'Valued Customer';
        Auth::logout();
        $logoutSuccess = true;
    }
} else {
    // Manual logout
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        $userName = $_SESSION['user_name'] ?? 'Valued Customer';
        
        // Clear remember-me token
        if (isset($_COOKIE['remember_token']) && isset($conn)) {
            $parts = explode(':', $_COOKIE['remember_token'], 2);
            if (count($parts) === 2) {
                $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
                $stmt->bind_param("i", (int)$parts[0]);
                $stmt->execute();
                $stmt->close();
            }
            setcookie('remember_token', '', time() - 3600, '/');
        }
        
        $_SESSION = [];
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
        session_start();
        $logoutSuccess = true;
    }
}

// Set flash message
if ($logoutSuccess) {
    $_SESSION['flash_message'] = "Goodbye, {$userName}! You have been successfully logged out.";
    $_SESSION['flash_message_type'] = 'success';
}

// Redirect URL
$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'index.php';
$redirect_url = htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Logging Out... - <?php echo defined('SITE_NAME') ? SITE_NAME : 'Bless Mall'; ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0B6E3D 0%, #085530 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        
        .logout-container {
            background: white;
            border-radius: 1.5rem;
            padding: 3rem 2rem;
            text-align: center;
            max-width: 480px;
            width: 100%;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .logout-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #DCFCE7, #BBF7D0);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(11, 110, 61, 0.4);
            }
            50% {
                box-shadow: 0 0 0 20px rgba(11, 110, 61, 0);
            }
        }
        
        .logout-icon i {
            font-size: 2.5rem;
            color: #0B6E3D;
        }
        
        .logout-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.75rem;
            font-weight: 600;
            color: #1F2937;
            margin-bottom: 0.75rem;
        }
        
        .logout-message {
            color: #6B7280;
            margin-bottom: 1.5rem;
            font-size: 1.1rem;
            line-height: 1.6;
        }
        
        .loading-bar {
            width: 100%;
            height: 4px;
            background: #E5E7EB;
            border-radius: 2px;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .loading-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #0B6E3D, #D4AF37);
            border-radius: 2px;
            animation: loading 2s ease-in-out;
            width: 100%;
        }
        
        @keyframes loading {
            from {
                width: 0;
            }
            to {
                width: 100%;
            }
        }
        
        .redirect-info {
            color: #9CA3AF;
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
        }
        
        .btn-home {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #0B6E3D;
            color: white;
            text-decoration: none;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-home:hover {
            background: #085530;
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        
        .quick-links {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }
        
        .quick-link {
            color: #6B7280;
            text-decoration: none;
            font-size: 0.875rem;
            transition: color 0.2s;
        }
        
        .quick-link:hover {
            color: #0B6E3D;
        }
    </style>
</head>
<body>
    <div class="logout-container">
        <div class="logout-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        
        <h1 class="logout-title">Logged Out Successfully</h1>
        
        <?php if ($logoutSuccess && isset($userName)): ?>
            <p class="logout-message">
                Thank you for visiting, <strong><?php echo htmlspecialchars($userName); ?></strong>!<br>
                You have been securely logged out of your account.
            </p>
        <?php else: ?>
            <p class="logout-message">
                You have been logged out. Redirecting you to the homepage...
            </p>
        <?php endif; ?>
        
        <div class="loading-bar">
            <div class="loading-bar-fill"></div>
        </div>
        
        <p class="redirect-info">
            <i class="fas fa-clock"></i> Redirecting in <span id="countdown">3</span> seconds...
        </p>
        
        <a href="<?php echo $redirect_url; ?>" class="btn-home">
            <i class="fas fa-home"></i> Go to Homepage Now
        </a>
        
        <div class="quick-links">
            <a href="login.php" class="quick-link">
                <i class="fas fa-sign-in-alt"></i> Login Again
            </a>
            <a href="shop.php" class="quick-link">
                <i class="fas fa-shopping-bag"></i> Continue Shopping
            </a>
            <a href="contact.php" class="quick-link">
                <i class="fas fa-envelope"></i> Contact Us
            </a>
        </div>
    </div>
    
    <script>
        // Countdown redirect
        let seconds = 3;
        const countdownElement = document.getElementById('countdown');
        const redirectUrl = '<?php echo $redirect_url; ?>';
        
        const countdownInterval = setInterval(() => {
            seconds--;
            if (countdownElement) {
                countdownElement.textContent = seconds;
            }
            
            if (seconds <= 0) {
                clearInterval(countdownInterval);
                window.location.href = redirectUrl;
            }
        }, 1000);
        
        // Add exit animation
        document.querySelector('.btn-home')?.addEventListener('click', function(e) {
            e.preventDefault();
            const container = document.querySelector('.logout-container');
            container.style.transition = 'all 0.3s ease';
            container.style.opacity = '0';
            container.style.transform = 'scale(0.95)';
            
            setTimeout(() => {
                window.location.href = this.href;
            }, 300);
        });
    </script>
</body>
</html>
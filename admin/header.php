<?php
/**
 * Bless Mall - Admin Portal Header
 * Premium Admin Dashboard Navigation
 * 
 * Developed by NerdSpring Software Engineering
 * https://nerd-spring.com
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// Load config if not already loaded
if (!defined('BLESSMALL_CONFIG_INCLUDED')) {
    require_once dirname(__DIR__) . '/includes/config.php';
}

// Check admin authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

// Only allow admin (role_id = 1) and seller (role_id = 2) access
$role_id = (int)$_SESSION['role_id'];
if (!in_array($role_id, [1, 2])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$is_admin = ($role_id === 1);
$user_name = htmlspecialchars($_SESSION['user_name'] ?? 'Admin User');
$user_email = htmlspecialchars($_SESSION['user_email'] ?? '');
$user_avatar = $_SESSION['user_avatar'] ?? null;

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);

// Quick stats for header badges
$pending_orders = 0;
$low_stock_products = 0;
$new_reviews = 0;

if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    // Pending orders count
    $result = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE order_status = 'pending'");
    if ($result && $row = $result->fetch_assoc()) {
        $pending_orders = (int)$row['cnt'];
    }
    
    // Low stock products
    $result = $conn->query("SELECT COUNT(*) as cnt FROM product_inventory WHERE stock_quantity <= low_stock_threshold AND stock_quantity > 0");
    if ($result && $row = $result->fetch_assoc()) {
        $low_stock_products = (int)$row['cnt'];
    }
    
    // Pending vendor approvals (admin only)
    $pending_vendors = 0;
    if ($is_admin) {
        $result = $conn->query("SELECT COUNT(*) as cnt FROM vendors WHERE is_approved = 0 AND status = 'active'");
        if ($result && $row = $result->fetch_assoc()) {
            $pending_vendors = (int)$row['cnt'];
        }
    }
}

// Notification total
$total_notifications = $pending_orders + $low_stock_products + ($pending_vendors ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0B1E3D">
    
    <title><?php echo $page_title ?? 'Bless Mall Admin'; ?> | Bless Mall Admin Panel</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Chart.js for Admin Dashboard -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
    
    <style>
        :root {
            /* Admin Color Palette - Dark Professional Theme */
            --admin-bg: #0F172A;
            --admin-bg-light: #1E293B;
            --admin-bg-lighter: #334155;
            --admin-surface: #1E293B;
            --admin-surface-hover: #273548;
            --admin-border: #334155;
            --admin-border-light: #475569;
            
            --admin-primary: #3B82F6;
            --admin-primary-dark: #2563EB;
            --admin-primary-light: #60A5FA;
            --admin-primary-glow: rgba(59, 130, 246, 0.15);
            
            --admin-success: #10B981;
            --admin-success-dark: #059669;
            --admin-success-bg: rgba(16, 185, 129, 0.1);
            
            --admin-warning: #F59E0B;
            --admin-warning-bg: rgba(245, 158, 11, 0.1);
            
            --admin-danger: #EF4444;
            --admin-danger-bg: rgba(239, 68, 68, 0.1);
            
            --admin-info: #06B6D4;
            
            --text-primary: #F1F5F9;
            --text-secondary: #94A3B8;
            --text-muted: #64748B;
            --text-inverse: #0F172A;
            
            --white: #FFFFFF;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.4);
            --shadow-lg: 0 8px 32px rgba(0,0,0,0.5);
            --shadow-glow: 0 0 20px rgba(59, 130, 246, 0.1);
            
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --radius-full: 9999px;
            
            --transition-fast: 0.15s cubic-bezier(0.4, 0, 0.2, 1);
            --transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            
            --sidebar-width: 260px;
            --sidebar-collapsed: 72px;
            --header-height: 64px;
            --font-heading: 'Outfit', sans-serif;
            --font-body: 'Inter', sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }

        body {
            font-family: var(--font-body);
            background: var(--admin-bg);
            color: var(--text-primary);
            line-height: 1.6;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--admin-bg); }
        ::-webkit-scrollbar-thumb { background: var(--admin-border-light); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-muted); }

        /* ========== TOP BAR ========== */
        .admin-topbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: var(--header-height);
            background: var(--admin-bg-light);
            border-bottom: 1px solid var(--admin-border);
            z-index: 1000;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .topbar-inner {
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            gap: 1rem;
        }

        /* Sidebar Toggle */
        .sidebar-toggle {
            width: 40px;
            height: 40px;
            border: 1px solid var(--admin-border);
            background: var(--admin-surface);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            color: var(--text-secondary);
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .sidebar-toggle:hover {
            background: var(--admin-surface-hover);
            border-color: var(--admin-primary);
            color: var(--admin-primary);
            box-shadow: var(--shadow-glow);
        }

        /* Brand */
        .brand-area {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            flex-shrink: 0;
        }
        .brand-logo {
            width: 38px;
            height: 38px;
            border-radius: var(--radius-sm);
            background: linear-gradient(135deg, var(--admin-primary), #8B5CF6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: white;
            font-weight: 700;
        }
        .brand-text {
            font-family: var(--font-heading);
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -0.02em;
        }
        .brand-text .accent {
            color: var(--admin-primary);
        }
        .admin-badge {
            font-size: 0.65rem;
            background: var(--admin-primary);
            color: white;
            padding: 2px 8px;
            border-radius: var(--radius-full);
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-left: 4px;
        }

        /* Topbar Actions */
        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        /* Quick Action Buttons */
        .topbar-icon-btn {
            position: relative;
            width: 40px;
            height: 40px;
            border: 1px solid var(--admin-border);
            background: var(--admin-surface);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            color: var(--text-secondary);
            text-decoration: none;
        }
        .topbar-icon-btn:hover {
            background: var(--admin-surface-hover);
            border-color: var(--admin-primary);
            color: var(--text-primary);
        }
        .topbar-icon-btn .notification-dot {
            position: absolute;
            top: -4px;
            right: -4px;
            min-width: 18px;
            height: 18px;
            background: var(--admin-danger);
            border-radius: var(--radius-full);
            font-size: 0.65rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 5px;
            color: white;
            border: 2px solid var(--admin-bg-light);
            animation: pulse-dot 2s infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.4); }
            50% { box-shadow: 0 0 0 8px rgba(239,68,68,0); }
        }

        /* Visit Store Button */
        .btn-visit-store {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0.5rem 1rem;
            background: transparent;
            border: 1px solid var(--admin-border-light);
            color: var(--text-secondary);
            border-radius: var(--radius-sm);
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            white-space: nowrap;
        }
        .btn-visit-store:hover {
            border-color: var(--admin-success);
            color: var(--admin-success);
            background: var(--admin-success-bg);
        }
        .btn-visit-store i {
            font-size: 0.7rem;
        }

        /* User Menu */
        .user-menu-wrapper {
            position: relative;
        }
        .user-menu-trigger {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.35rem 0.75rem 0.35rem 0.5rem;
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-full);
            cursor: pointer;
            transition: var(--transition);
        }
        .user-menu-trigger:hover {
            border-color: var(--admin-primary);
            background: var(--admin-surface-hover);
        }
        .user-avatar-circle {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--admin-primary), #8B5CF6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: white;
            font-size: 0.85rem;
            text-transform: uppercase;
            flex-shrink: 0;
        }
        .user-info {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }
        .user-info .name {
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--text-primary);
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .user-info .role {
            font-size: 0.65rem;
            color: var(--text-muted);
        }
        .user-menu-trigger .chevron {
            font-size: 0.6rem;
            color: var(--text-muted);
            transition: var(--transition);
        }
        .user-menu-wrapper:hover .chevron {
            transform: rotate(180deg);
        }

        /* User Dropdown */
        .user-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            width: 240px;
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-8px);
            transition: var(--transition);
            z-index: 100;
            overflow: hidden;
        }
        .user-menu-wrapper:hover .user-dropdown,
        .user-menu-wrapper:focus-within .user-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        .user-dropdown-header {
            padding: 1rem;
            border-bottom: 1px solid var(--admin-border);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .user-dropdown-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--admin-primary), #8B5CF6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: white;
        }
        .user-dropdown-info .name {
            font-weight: 600;
            font-size: 0.85rem;
        }
        .user-dropdown-info .email {
            font-size: 0.7rem;
            color: var(--text-muted);
        }
        .user-dropdown-links {
            padding: 0.5rem 0;
        }
        .user-dropdown-links a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.6rem 1rem;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.8rem;
            transition: var(--transition-fast);
        }
        .user-dropdown-links a i {
            width: 20px;
            text-align: center;
            color: var(--text-muted);
        }
        .user-dropdown-links a:hover {
            background: var(--admin-surface-hover);
            color: var(--text-primary);
        }
        .user-dropdown-links a:hover i {
            color: var(--admin-primary);
        }
        .user-dropdown-links .divider {
            height: 1px;
            background: var(--admin-border);
            margin: 0.25rem 0;
        }
        .user-dropdown-links .logout-link {
            color: var(--admin-danger);
        }
        .user-dropdown-links .logout-link i {
            color: var(--admin-danger);
        }

        /* ========== SIDEBAR ========== */
        .admin-sidebar {
            position: fixed;
            top: var(--header-height);
            left: 0;
            bottom: 0;
            width: var(--sidebar-width);
            background: var(--admin-bg-light);
            border-right: 1px solid var(--admin-border);
            z-index: 999;
            overflow-y: auto;
            transition: var(--transition-slow);
            display: flex;
            flex-direction: column;
        }
        .admin-sidebar.collapsed {
            width: var(--sidebar-collapsed);
        }
        .admin-sidebar.collapsed .nav-label,
        .admin-sidebar.collapsed .nav-badge,
        .admin-sidebar.collapsed .sidebar-section-title,
        .admin-sidebar.collapsed .sidebar-footer-text {
            display: none;
        }
        .admin-sidebar.collapsed .nav-link {
            justify-content: center;
            padding: 0.75rem;
        }
        .admin-sidebar.collapsed .nav-icon {
            margin-right: 0;
        }

        /* Sidebar Navigation */
        .sidebar-nav {
            flex: 1;
            padding: 1rem 0.75rem;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .sidebar-section {
            margin-bottom: 1rem;
        }
        .sidebar-section-title {
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--text-muted);
            padding: 0.5rem 0.75rem;
            font-weight: 600;
        }
        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0.65rem 0.85rem;
            border-radius: var(--radius-sm);
            text-decoration: none;
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-weight: 500;
            transition: var(--transition-fast);
            position: relative;
        }
        .nav-link:hover {
            background: var(--admin-surface-hover);
            color: var(--text-primary);
        }
        .nav-link.active {
            background: var(--admin-primary-glow);
            color: var(--admin-primary);
            font-weight: 600;
        }
        .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 20px;
            background: var(--admin-primary);
            border-radius: 0 3px 3px 0;
        }
        .nav-icon {
            width: 22px;
            text-align: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .nav-link.active .nav-icon {
            color: var(--admin-primary);
        }
        .nav-badge {
            margin-left: auto;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: var(--radius-full);
            min-width: 22px;
            text-align: center;
        }
        .nav-badge.warning {
            background: var(--admin-warning-bg);
            color: var(--admin-warning);
        }
        .nav-badge.danger {
            background: var(--admin-danger-bg);
            color: var(--admin-danger);
        }
        .nav-badge.success {
            background: var(--admin-success-bg);
            color: var(--admin-success);
        }

        /* Sidebar Footer */
        .sidebar-footer {
            padding: 1rem 0.75rem;
            border-top: 1px solid var(--admin-border);
        }
        .sidebar-footer-text {
            text-align: center;
            font-size: 0.7rem;
            color: var(--text-muted);
            padding: 0.5rem;
        }
        .sidebar-footer-text a {
            color: var(--admin-primary);
            text-decoration: none;
            font-weight: 600;
        }
        .sidebar-footer-text a:hover {
            text-decoration: underline;
        }
        .sidebar-footer-text .dev-by {
            display: block;
            margin-top: 0.25rem;
        }

        /* ========== MAIN CONTENT WRAPPER ========== */
        .admin-main {
            margin-left: var(--sidebar-width);
            margin-top: var(--header-height);
            min-height: calc(100vh - var(--header-height));
            padding: 1.5rem;
            transition: var(--transition-slow);
        }
        .admin-sidebar.collapsed ~ .admin-main {
            margin-left: var(--sidebar-collapsed);
        }

        /* ========== RESPONSIVE ========== */
        @media (max-width: 1024px) {
            .admin-sidebar {
                width: var(--sidebar-collapsed);
            }
            .admin-sidebar .nav-label,
            .admin-sidebar .nav-badge,
            .admin-sidebar .sidebar-section-title,
            .admin-sidebar .sidebar-footer-text {
                display: none;
            }
            .admin-sidebar .nav-link {
                justify-content: center;
                padding: 0.75rem;
            }
            .admin-sidebar .nav-icon {
                margin-right: 0;
            }
            .admin-main {
                margin-left: var(--sidebar-collapsed);
            }
            .admin-sidebar.mobile-open {
                width: var(--sidebar-width);
                left: 0;
                box-shadow: var(--shadow-lg);
            }
            .admin-sidebar.mobile-open .nav-label,
            .admin-sidebar.mobile-open .nav-badge,
            .admin-sidebar.mobile-open .sidebar-section-title,
            .admin-sidebar.mobile-open .sidebar-footer-text {
                display: block;
            }
            .admin-sidebar.mobile-open .nav-link {
                justify-content: flex-start;
                padding: 0.65rem 0.85rem;
            }
            .admin-sidebar.mobile-open .nav-icon {
                margin-right: 0;
            }
        }

        @media (max-width: 768px) {
            .admin-sidebar {
                left: -100%;
                width: var(--sidebar-width);
                z-index: 998;
            }
            .admin-sidebar.mobile-open {
                left: 0;
            }
            .admin-main {
                margin-left: 0;
            }
            .brand-text {
                display: none;
            }
            .btn-visit-store span {
                display: none;
            }
            .user-info {
                display: none;
            }
        }

        /* Mobile Overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 997;
        }
        .sidebar-overlay.active {
            display: block;
        }

        /* Breadcrumb */
        .admin-breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .admin-breadcrumb a {
            color: var(--admin-primary);
            text-decoration: none;
            transition: var(--transition-fast);
        }
        .admin-breadcrumb a:hover {
            color: var(--admin-primary-light);
        }
        .admin-breadcrumb .separator {
            color: var(--text-muted);
            font-size: 0.6rem;
        }
        .admin-breadcrumb .current {
            color: var(--text-primary);
            font-weight: 500;
        }

        /* Page Header */
        .admin-page-header {
            margin-bottom: 1.5rem;
        }
        .admin-page-header h1 {
            font-family: var(--font-heading);
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .admin-page-header h1 i {
            color: var(--admin-primary);
        }
        .admin-page-header p {
            color: var(--text-muted);
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }
    </style>
</head>
<body>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ==================== TOP BAR ==================== -->
<header class="admin-topbar">
    <div class="topbar-inner">
        <!-- Left Section -->
        <div style="display: flex; align-items: center; gap: 1rem;">
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            
            <a href="index.php" class="brand-area">
                <div class="brand-logo">BM</div>
                <span class="brand-text">Bless<span class="accent">Mall</span></span>
                <span class="admin-badge">ADMIN</span>
            </a>
        </div>
        
        <!-- Right Section -->
        <div class="topbar-actions">
            <!-- Notifications -->
            <button class="topbar-icon-btn" title="Notifications" aria-label="Notifications">
                <i class="fas fa-bell"></i>
                <?php if ($total_notifications > 0): ?>
                    <span class="notification-dot"><?php echo $total_notifications; ?></span>
                <?php endif; ?>
            </button>
            
            <!-- Messages -->
            <button class="topbar-icon-btn" title="Messages" aria-label="Messages">
                <i class="fas fa-envelope"></i>
            </button>
            
            <!-- Visit Store -->
            <a href="<?php echo BASE_URL; ?>" target="_blank" class="btn-visit-store" title="Visit Store">
                <i class="fas fa-external-link-alt"></i>
                <span>Visit Store</span>
            </a>
            
            <!-- User Menu -->
            <div class="user-menu-wrapper">
                <button class="user-menu-trigger" aria-label="User menu">
                    <div class="user-avatar-circle">
                        <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                    </div>
                    <div class="user-info">
                        <span class="name"><?php echo $user_name; ?></span>
                        <span class="role"><?php echo $is_admin ? 'Administrator' : 'Seller'; ?></span>
                    </div>
                    <i class="fas fa-chevron-down chevron"></i>
                </button>
                
                <div class="user-dropdown">
                    <div class="user-dropdown-header">
                        <div class="user-dropdown-avatar">
                            <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                        </div>
                        <div class="user-dropdown-info">
                            <div class="name"><?php echo $user_name; ?></div>
                            <div class="email"><?php echo $user_email ?: 'admin@blessmall.com'; ?></div>
                        </div>
                    </div>
                    <div class="user-dropdown-links">
                        <a href="settings.php"><i class="fas fa-cog"></i> Account Settings</a>
                        <a href="profile.php"><i class="fas fa-user-edit"></i> Edit Profile</a>
                        <div class="divider"></div>
                        <a href="<?php echo BASE_URL; ?>" target="_blank"><i class="fas fa-store"></i> View Store</a>
                        <div class="divider"></div>
                        <a href="<?php echo BASE_URL; ?>logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- ==================== SIDEBAR ==================== -->
<aside class="admin-sidebar" id="adminSidebar">
    <nav class="sidebar-nav">
        <!-- Main Navigation -->
        <div class="sidebar-section">
            <div class="sidebar-section-title">Main</div>
            
            <a href="index.php" class="nav-link <?php echo $current_page === 'index.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                <span class="nav-label">Dashboard</span>
            </a>
            
            <a href="products.php" class="nav-link <?php echo $current_page === 'products.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-box-open"></i></span>
                <span class="nav-label">Products</span>
            </a>
            
            <a href="categories.php" class="nav-link <?php echo $current_page === 'categories.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-th-large"></i></span>
                <span class="nav-label">Categories</span>
            </a>
            
            <a href="inventory.php" class="nav-link <?php echo $current_page === 'inventory.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-warehouse"></i></span>
                <span class="nav-label">Inventory</span>
                <?php if ($low_stock_products > 0): ?>
                    <span class="nav-badge warning"><?php echo $low_stock_products; ?></span>
                <?php endif; ?>
            </a>
            
            <a href="orders.php" class="nav-link <?php echo $current_page === 'orders.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-shopping-cart"></i></span>
                <span class="nav-label">Orders</span>
                <?php if ($pending_orders > 0): ?>
                    <span class="nav-badge danger"><?php echo $pending_orders; ?></span>
                <?php endif; ?>
            </a>
        </div>
        
        <!-- Management -->
        <div class="sidebar-section">
            <div class="sidebar-section-title">Management</div>
            
            <?php if ($is_admin): ?>
            <a href="users.php" class="nav-link <?php echo $current_page === 'users.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-users"></i></span>
                <span class="nav-label">Users</span>
            </a>
            
            <a href="vendors.php" class="nav-link <?php echo $current_page === 'vendors.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-store-alt"></i></span>
                <span class="nav-label">Vendors</span>
                <?php if (($pending_vendors ?? 0) > 0): ?>
                    <span class="nav-badge warning"><?php echo $pending_vendors; ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
            
            <a href="reviews.php" class="nav-link <?php echo $current_page === 'reviews.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-star"></i></span>
                <span class="nav-label">Reviews</span>
            </a>
            
            <a href="promotions.php" class="nav-link <?php echo $current_page === 'promotions.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-tags"></i></span>
                <span class="nav-label">Promotions</span>
            </a>
        </div>
        
        <!-- Settings -->
        <div class="sidebar-section">
            <div class="sidebar-section-title">Settings</div>
            
            <a href="settings.php" class="nav-link <?php echo $current_page === 'settings.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-cog"></i></span>
                <span class="nav-label">Settings</span>
            </a>
            
            <a href="delivery.php" class="nav-link <?php echo $current_page === 'delivery.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-truck"></i></span>
                <span class="nav-label">Delivery</span>
            </a>
            
            <a href="reports.php" class="nav-link <?php echo $current_page === 'reports.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                <span class="nav-label">Reports</span>
            </a>
        </div>
    </nav>
    
    <!-- Sidebar Footer -->
    <div class="sidebar-footer">
        <div class="sidebar-footer-text">
            <span>Bless Mall Admin v1.0</span>
            <span class="dev-by">Developed by <a href="https://nerd-spring.com" target="_blank" rel="noopener">NerdSpring Software Engineering</a></span>
        </div>
    </div>
</aside>

<!-- Main Content Wrapper Start -->
<div class="admin-main" id="adminMain">

<script>
    // Sidebar Toggle
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const mainContent = document.getElementById('adminMain');
    let sidebarOpen = true;
    
    function toggleSidebar() {
        if (window.innerWidth <= 768) {
            sidebar.classList.toggle('mobile-open');
            overlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('mobile-open') ? 'hidden' : '';
        } else {
            sidebar.classList.toggle('collapsed');
        }
    }
    
    function closeSidebar() {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
    
    // Close sidebar on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeSidebar();
    });
    
    // Handle window resize
    window.addEventListener('resize', () => {
        if (window.innerWidth > 768) {
            closeSidebar();
        }
    });
    
    // Active link click on mobile - close sidebar
    document.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                closeSidebar();
            }
        });
    });
</script>
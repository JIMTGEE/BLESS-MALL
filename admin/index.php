<?php
/**
 * Bless Mall - Admin Dashboard
 * Central Admin Portal for Bless Mall E-commerce Platform
 * 
 * Features:
 * - Real-time statistics (orders, products, users, revenue)
 * - Admin-Vendor linking verification with profile completion
 * - Charts & Analytics (revenue trends, order status)
 * - Recent orders & low stock alerts
 * - Top selling products
 * - Recent user registrations
 * 
 * Developed by NerdSpring Software Engineering
 * https://nerd-spring.com
 */

// ------------------------------------------------------------
// 1. BOOTSTRAP & AUTHENTICATION
// ------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

// Check if user is logged in
if (!Auth::isLoggedIn()) {
    $_SESSION['redirect_after_login'] = 'admin/index.php';
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

// Check if user has admin role (role_id = 1)
if (!isset($_SESSION['role_id']) || (int)$_SESSION['role_id'] !== 1) {
    header('Location: ' . BASE_URL . 'account.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$userName = htmlspecialchars($_SESSION['user_name'] ?? 'Admin User');
$userEmail = htmlspecialchars($_SESSION['user_email'] ?? '');

$conn = getConnection();

// ------------------------------------------------------------
// 2. CHECK IF ADMIN IS LINKED TO VENDORS TABLE
// ------------------------------------------------------------
$vendorLinked = false;
$vendorId = null;
$vendorStatus = null;
$vendorProfileComplete = false;
$vendorData = null;
$vendorMissingFields = [];

$vendorCheckQuery = "SELECT v.*, 
                    CASE 
                        WHEN v.business_name IS NOT NULL 
                         AND v.business_name != '' 
                         AND v.business_name != 'Bless Mall'
                         AND v.description IS NOT NULL 
                         AND v.description != ''
                         AND v.contact_phone IS NOT NULL 
                         AND v.contact_phone != ''
                        THEN 1 
                        ELSE 0 
                    END as is_profile_complete
                    FROM vendors v 
                    WHERE v.user_id = ? AND v.is_approved = 1 
                    LIMIT 1";
$stmt = $conn->prepare($vendorCheckQuery);
$stmt->bind_param("i", $userId);
$stmt->execute();
$vendorResult = $stmt->get_result();

if ($vendorResult && $vendorResult->num_rows > 0) {
    $vendorData = $vendorResult->fetch_assoc();
    $vendorLinked = true;
    $vendorId = $vendorData['id'];
    $vendorStatus = $vendorData['status'];
    $vendorProfileComplete = (bool)$vendorData['is_profile_complete'];
    
    // Check which fields are missing
    if (!$vendorProfileComplete) {
        if (empty($vendorData['business_name']) || $vendorData['business_name'] === 'Bless Mall') {
            $vendorMissingFields[] = 'Business Name';
        }
        if (empty($vendorData['description'])) {
            $vendorMissingFields[] = 'Store Description';
        }
        if (empty($vendorData['contact_phone'])) {
            $vendorMissingFields[] = 'Contact Phone';
        }
        if (empty($vendorData['logo'])) {
            $vendorMissingFields[] = 'Store Logo';
        }
        if (empty($vendorData['banner'])) {
            $vendorMissingFields[] = 'Store Banner';
        }
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    // Create admin vendor account
    if ($action === 'create_admin_vendor') {
        $checkQuery = "SELECT id FROM vendors WHERE user_id = ? LIMIT 1";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        
        if ($existing) {
            $response = ['success' => true, 'message' => 'Vendor account already exists!', 'redirect' => 'index.php'];
        } else {
            $userQuery = "SELECT name, email, phone FROM users WHERE id = ?";
            $stmt = $conn->prepare($userQuery);
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $userData = $stmt->get_result()->fetch_assoc();
            
            $businessName = $userData['name'] . "'s Store";
            $contactPhone = $userData['phone'] ?? SITE_PHONE;
            
            $insertQuery = "INSERT INTO vendors (user_id, business_name, contact_phone, status, is_approved, is_express, created_at) 
                            VALUES (?, ?, ?, 'active', 1, 0, NOW())";
            $stmt = $conn->prepare($insertQuery);
            $stmt->bind_param("iss", $userId, $businessName, $contactPhone);
            
            if ($stmt->execute()) {
                $newVendorId = $stmt->insert_id;
                $response = ['success' => true, 'message' => 'Vendor account created successfully! Please complete your profile.', 'vendor_id' => $newVendorId];
            } else {
                $response = ['success' => false, 'message' => 'Failed to create vendor account. Please try again.'];
            }
        }
    }
    
    // Update vendor profile - Complete all fields
    if ($action === 'update_vendor_profile') {
        $vendorIdToUpdate = (int)($_POST['vendor_id'] ?? 0);
        $businessName = $conn->real_escape_string(trim($_POST['business_name'] ?? ''));
        $description = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $contactPhone = $conn->real_escape_string(trim($_POST['contact_phone'] ?? ''));
        $whatsapp = $conn->real_escape_string(trim($_POST['whatsapp'] ?? ''));
        $isExpress = isset($_POST['is_express']) ? 1 : 0;
        $storeBadge = $conn->real_escape_string(trim($_POST['store_badge'] ?? ''));
        
        // Validate
        if (empty($businessName)) {
            $response = ['success' => false, 'message' => 'Business name is required.'];
        } elseif (empty($contactPhone)) {
            $response = ['success' => false, 'message' => 'Contact phone is required.'];
        } else {
            // Check if vendor belongs to admin
            $verifyQuery = "SELECT id FROM vendors WHERE id = ? AND user_id = ? LIMIT 1";
            $stmt = $conn->prepare($verifyQuery);
            $stmt->bind_param("ii", $vendorIdToUpdate, $userId);
            $stmt->execute();
            
            if ($stmt->get_result()->num_rows > 0) {
                // Handle logo upload
                $logoPath = $vendorData['logo'] ?? '';
                if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/../uploads/vendors/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $logoExt = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                    $logoName = 'vendor_' . $vendorIdToUpdate . '_logo_' . time() . '.' . $logoExt;
                    $logoDestination = $uploadDir . $logoName;
                    
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $logoDestination)) {
                        $logoPath = 'uploads/vendors/' . $logoName;
                    }
                }
                
                // Handle banner upload
                $bannerPath = $vendorData['banner'] ?? '';
                if (isset($_FILES['banner']) && $_FILES['banner']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/../uploads/vendors/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $bannerExt = pathinfo($_FILES['banner']['name'], PATHINFO_EXTENSION);
                    $bannerName = 'vendor_' . $vendorIdToUpdate . '_banner_' . time() . '.' . $bannerExt;
                    $bannerDestination = $uploadDir . $bannerName;
                    
                    if (move_uploaded_file($_FILES['banner']['tmp_name'], $bannerDestination)) {
                        $bannerPath = 'uploads/vendors/' . $bannerName;
                    }
                }
                
                $updateQuery = "UPDATE vendors SET 
                                business_name = '$businessName', 
                                description = '$description', 
                                contact_phone = '$contactPhone', 
                                whatsapp = '$whatsapp', 
                                is_express = $isExpress,
                                store_badge = '$storeBadge',
                                logo = '$logoPath',
                                banner = '$bannerPath',
                                updated_at = NOW()
                                WHERE id = $vendorIdToUpdate AND user_id = $userId";
                
                if ($conn->query($updateQuery)) {
                    $response = ['success' => true, 'message' => 'Vendor profile updated successfully!', 'redirect' => 'index.php'];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to update profile: ' . $conn->error];
                }
            } else {
                $response = ['success' => false, 'message' => 'Unauthorized access.'];
            }
        }
    }
    
    echo json_encode($response);
    exit;
}

// ------------------------------------------------------------
// 3. FETCH DASHBOARD STATISTICS (Real-time from DB)
// ------------------------------------------------------------

// Total Orders
$totalOrdersQuery = "SELECT COUNT(*) as total FROM orders";
$totalOrdersResult = $conn->query($totalOrdersQuery);
$totalOrders = $totalOrdersResult->fetch_assoc()['total'] ?? 0;

// Pending Orders
$pendingOrdersQuery = "SELECT COUNT(*) as total FROM orders WHERE order_status = 'pending'";
$pendingOrdersResult = $conn->query($pendingOrdersQuery);
$pendingOrders = $pendingOrdersResult->fetch_assoc()['total'] ?? 0;

// Processing Orders
$processingOrdersQuery = "SELECT COUNT(*) as total FROM orders WHERE order_status IN ('confirmed','processing','packed')";
$processingOrdersResult = $conn->query($processingOrdersQuery);
$processingOrders = $processingOrdersResult->fetch_assoc()['total'] ?? 0;

// Shipped Orders
$shippedOrdersQuery = "SELECT COUNT(*) as total FROM orders WHERE order_status = 'shipped'";
$shippedOrdersResult = $conn->query($shippedOrdersQuery);
$shippedOrders = $shippedOrdersResult->fetch_assoc()['total'] ?? 0;

// Delivered Orders
$deliveredOrdersQuery = "SELECT COUNT(*) as total FROM orders WHERE order_status = 'delivered'";
$deliveredOrdersResult = $conn->query($deliveredOrdersQuery);
$deliveredOrders = $deliveredOrdersResult->fetch_assoc()['total'] ?? 0;

// Cancelled Orders
$cancelledOrdersQuery = "SELECT COUNT(*) as total FROM orders WHERE order_status = 'cancelled'";
$cancelledOrdersResult = $conn->query($cancelledOrdersQuery);
$cancelledOrders = $cancelledOrdersResult->fetch_assoc()['total'] ?? 0;

// Total Products
$totalProductsQuery = "SELECT COUNT(*) as total FROM products WHERE is_active = 1";
$totalProductsResult = $conn->query($totalProductsQuery);
$totalProducts = $totalProductsResult->fetch_assoc()['total'] ?? 0;

// Out of Stock Products
$outOfStockQuery = "SELECT COUNT(DISTINCT p.id) as total 
                    FROM products p
                    INNER JOIN product_pricing_tiers pt ON p.id = pt.product_id
                    INNER JOIN product_inventory pi ON pt.id = pi.pricing_tier_id
                    WHERE p.is_active = 1 AND pi.stock_quantity <= 0";
$outOfStockResult = $conn->query($outOfStockQuery);
$outOfStockProducts = $outOfStockResult->fetch_assoc()['total'] ?? 0;

// Low Stock Products
$lowStockQuery = "SELECT COUNT(DISTINCT p.id) as total 
                   FROM products p
                   INNER JOIN product_pricing_tiers pt ON p.id = pt.product_id
                   INNER JOIN product_inventory pi ON pt.id = pi.pricing_tier_id
                   WHERE p.is_active = 1 AND pi.stock_quantity > 0 AND pi.stock_quantity <= pi.low_stock_threshold";
$lowStockResult = $conn->query($lowStockQuery);
$lowStockProducts = $lowStockResult->fetch_assoc()['total'] ?? 0;

// Total Users
$totalUsersQuery = "SELECT COUNT(*) as total FROM users WHERE is_active = 1 AND deleted_at IS NULL";
$totalUsersResult = $conn->query($totalUsersQuery);
$totalUsers = $totalUsersResult->fetch_assoc()['total'] ?? 0;

// New Users (Last 30 days)
$newUsersQuery = "SELECT COUNT(*) as total FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
$newUsersResult = $conn->query($newUsersQuery);
$newUsers = $newUsersResult->fetch_assoc()['total'] ?? 0;

// Total Revenue (from paid orders)
$totalRevenueQuery = "SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE payment_status = 'paid'";
$totalRevenueResult = $conn->query($totalRevenueQuery);
$totalRevenue = $totalRevenueResult->fetch_assoc()['total'] ?? 0;

// Monthly Revenue (Current Month)
$monthlyRevenueQuery = "SELECT COALESCE(SUM(total_amount), 0) as total 
                         FROM orders 
                         WHERE payment_status = 'paid' 
                         AND MONTH(created_at) = MONTH(CURDATE()) 
                         AND YEAR(created_at) = YEAR(CURDATE())";
$monthlyRevenueResult = $conn->query($monthlyRevenueQuery);
$monthlyRevenue = $monthlyRevenueResult->fetch_assoc()['total'] ?? 0;

// Daily Revenue (Today)
$todayRevenueQuery = "SELECT COALESCE(SUM(total_amount), 0) as total 
                       FROM orders 
                       WHERE DATE(created_at) = CURDATE() 
                       AND payment_status = 'paid'";
$todayRevenueResult = $conn->query($todayRevenueQuery);
$todayRevenue = $todayRevenueResult->fetch_assoc()['total'] ?? 0;

// Today's Orders Count
$todayOrdersQuery = "SELECT COUNT(*) as total FROM orders WHERE DATE(created_at) = CURDATE()";
$todayOrdersResult = $conn->query($todayOrdersQuery);
$todayOrders = $todayOrdersResult->fetch_assoc()['total'] ?? 0;

// Total Vendors
$totalVendorsQuery = "SELECT COUNT(*) as total FROM vendors WHERE status = 'active' AND is_approved = 1";
$totalVendorsResult = $conn->query($totalVendorsQuery);
$totalVendors = $totalVendorsResult->fetch_assoc()['total'] ?? 0;

// Pending Vendor Approvals
$pendingVendorsCountQuery = "SELECT COUNT(*) as total FROM vendors WHERE is_approved = 0 AND status = 'active'";
$pendingVendorsCountResult = $conn->query($pendingVendorsCountQuery);
$pendingVendorsCount = $pendingVendorsCountResult->fetch_assoc()['total'] ?? 0;

// Express Vendors
$expressVendorsQuery = "SELECT COUNT(*) as total FROM vendors WHERE is_express = 1 AND status = 'active'";
$expressVendorsResult = $conn->query($expressVendorsQuery);
$expressVendors = $expressVendorsResult->fetch_assoc()['total'] ?? 0;

// Average Order Value
$avgOrderValueQuery = "SELECT COALESCE(AVG(total_amount), 0) as avg_value FROM orders WHERE order_status != 'cancelled'";
$avgOrderValueResult = $conn->query($avgOrderValueQuery);
$avgOrderValue = $avgOrderValueResult->fetch_assoc()['avg_value'] ?? 0;

// Total Reviews
$totalReviewsQuery = "SELECT COUNT(*) as total FROM reviews";
$totalReviewsResult = $conn->query($totalReviewsQuery);
$totalReviews = $totalReviewsResult->fetch_assoc()['total'] ?? 0;

// Average Rating
$avgRatingQuery = "SELECT COALESCE(AVG(rating), 0) as avg_rating FROM reviews";
$avgRatingResult = $conn->query($avgRatingQuery);
$avgRating = round($avgRatingResult->fetch_assoc()['avg_rating'] ?? 0, 1);

// Pending Deliveries
$pendingDeliveriesQuery = "SELECT COUNT(*) as total FROM deliveries WHERE status IN ('pending','assigned','in_transit')";
$pendingDeliveriesResult = $conn->query($pendingDeliveriesQuery);
$pendingDeliveries = $pendingDeliveriesResult->fetch_assoc()['total'] ?? 0;

// Total Categories
$totalCategoriesQuery = "SELECT COUNT(*) as total FROM categories WHERE is_active = 1";
$totalCategoriesResult = $conn->query($totalCategoriesQuery);
$totalCategories = $totalCategoriesResult->fetch_assoc()['total'] ?? 0;

// Active Promotions
$activePromotionsQuery = "SELECT COUNT(*) as total FROM promotions WHERE is_active = 1 AND start_date <= CURDATE() AND end_date >= CURDATE()";
$activePromotionsResult = $conn->query($activePromotionsQuery);
$activePromotions = $activePromotionsResult->fetch_assoc()['total'] ?? 0;

// ------------------------------------------------------------
// 4. FETCH RECENT ORDERS
// ------------------------------------------------------------
$recentOrders = [];
$recentOrdersQuery = "SELECT o.id, o.order_number, o.total_amount, o.order_status, o.payment_status, o.created_at,
                      u.name as customer_name,
                      v.business_name as vendor_name,
                      (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
                      FROM orders o
                      LEFT JOIN users u ON o.user_id = u.id
                      LEFT JOIN vendors v ON o.vendor_id = v.id
                      ORDER BY o.created_at DESC
                      LIMIT 10";
$recentOrdersResult = $conn->query($recentOrdersQuery);
if ($recentOrdersResult && $recentOrdersResult->num_rows > 0) {
    while ($row = $recentOrdersResult->fetch_assoc()) {
        $recentOrders[] = $row;
    }
}

// ------------------------------------------------------------
// 5. FETCH LOW STOCK PRODUCTS
// ------------------------------------------------------------
$lowStockProductsList = [];
$lowStockProdQuery = "SELECT p.id, p.name, p.slug, p.sku,
                      COALESCE(SUM(pi.stock_quantity), 0) as stock_quantity,
                      MAX(pi.low_stock_threshold) as low_stock_threshold,
                      v.business_name as vendor_name,
                      v.id as vendor_id
                      FROM products p
                      INNER JOIN product_pricing_tiers pt ON p.id = pt.product_id
                      INNER JOIN product_inventory pi ON pt.id = pi.pricing_tier_id
                      LEFT JOIN vendors v ON p.vendor_id = v.id
                      WHERE p.is_active = 1 
                      GROUP BY p.id, p.name, p.slug, p.sku, v.business_name, v.id
                      HAVING stock_quantity > 0 AND stock_quantity <= MAX(pi.low_stock_threshold)
                      ORDER BY stock_quantity ASC
                      LIMIT 10";
$lowStockProdResult = $conn->query($lowStockProdQuery);
if ($lowStockProdResult && $lowStockProdResult->num_rows > 0) {
    while ($row = $lowStockProdResult->fetch_assoc()) {
        $lowStockProductsList[] = $row;
    }
}

// ------------------------------------------------------------
// 6. FETCH TOP SELLING PRODUCTS
// ------------------------------------------------------------
$topProducts = [];
$topProductsQuery = "SELECT p.id, p.name, p.slug,
                     SUM(oi.quantity) as total_sold,
                     COALESCE(SUM(oi.subtotal), 0) as total_revenue,
                     (SELECT image_path FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_path
                     FROM order_items oi
                     INNER JOIN products p ON oi.product_id = p.id
                     INNER JOIN orders o ON oi.order_id = o.id
                     WHERE o.order_status != 'cancelled'
                     GROUP BY p.id, p.name, p.slug
                     ORDER BY total_sold DESC
                     LIMIT 8";
$topProductsResult = $conn->query($topProductsQuery);
if ($topProductsResult && $topProductsResult->num_rows > 0) {
    while ($row = $topProductsResult->fetch_assoc()) {
        $topProducts[] = $row;
    }
}

// ------------------------------------------------------------
// 7. FETCH RECENT USERS
// ------------------------------------------------------------
$recentUsers = [];
$recentUsersQuery = "SELECT id, name, email, phone, account_type, created_at, is_active 
                     FROM users 
                     WHERE deleted_at IS NULL 
                     ORDER BY created_at DESC 
                     LIMIT 8";
$recentUsersResult = $conn->query($recentUsersQuery);
if ($recentUsersResult && $recentUsersResult->num_rows > 0) {
    while ($row = $recentUsersResult->fetch_assoc()) {
        $recentUsers[] = $row;
    }
}

// ------------------------------------------------------------
// 8. FETCH ORDER STATUS COUNTS FOR CHART
// ------------------------------------------------------------
$orderStatusCounts = [
    'pending' => 0,
    'confirmed' => 0,
    'processing' => 0,
    'packed' => 0,
    'shipped' => 0,
    'delivered' => 0,
    'cancelled' => 0
];

$statusCountQuery = "SELECT order_status, COUNT(*) as count FROM orders GROUP BY order_status";
$statusCountResult = $conn->query($statusCountQuery);
if ($statusCountResult && $statusCountResult->num_rows > 0) {
    while ($row = $statusCountResult->fetch_assoc()) {
        if (isset($orderStatusCounts[$row['order_status']])) {
            $orderStatusCounts[$row['order_status']] = (int)$row['count'];
        }
    }
}

// ------------------------------------------------------------
// 9. FETCH LAST 14 DAYS REVENUE FOR CHART
// ------------------------------------------------------------
$revenueChartData = [];
$hasChartData = false;
for ($i = 13; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dayName = date('D', strtotime($date));
    
    $revenueQuery = "SELECT COALESCE(SUM(total_amount), 0) as daily_revenue 
                     FROM orders 
                     WHERE DATE(created_at) = '$date' AND payment_status = 'paid'";
    $revenueResult = $conn->query($revenueQuery);
    $dailyRevenue = $revenueResult->fetch_assoc()['daily_revenue'] ?? 0;
    
    $ordersQuery = "SELECT COUNT(*) as daily_orders FROM orders WHERE DATE(created_at) = '$date'";
    $ordersResult = $conn->query($ordersQuery);
    $dailyOrders = $ordersResult->fetch_assoc()['daily_orders'] ?? 0;
    
    if ($dailyRevenue > 0 || $dailyOrders > 0) {
        $hasChartData = true;
    }
    
    $revenueChartData[] = [
        'date' => $dayName . ' ' . date('d', strtotime($date)),
        'date_full' => $date,
        'revenue' => (float)$dailyRevenue,
        'orders' => (int)$dailyOrders
    ];
}

// ------------------------------------------------------------
// 10. RECENT REVIEWS
// ------------------------------------------------------------
$recentReviews = [];
$recentReviewsQuery = "SELECT r.id, r.rating, r.comment, r.created_at,
                       u.name as user_name,
                       p.name as product_name, p.slug as product_slug
                       FROM reviews r
                       INNER JOIN users u ON r.user_id = u.id
                       INNER JOIN products p ON r.product_id = p.id
                       ORDER BY r.created_at DESC
                       LIMIT 5";
$recentReviewsResult = $conn->query($recentReviewsQuery);
if ($recentReviewsResult && $recentReviewsResult->num_rows > 0) {
    while ($row = $recentReviewsResult->fetch_assoc()) {
        $recentReviews[] = $row;
    }
}

// Page title
$page_title = 'Admin Dashboard - Bless Mall';

// Helper function for price formatting
function formatPrice($amount) {
    return 'GHS ' . number_format((float)$amount, 2);
}

// Include admin header
require_once __DIR__ . '/header.php';
?>

<!-- Admin Dashboard Styles -->
<style>
    /* Dashboard Container */
    .dashboard-container {
        padding: 0.5rem;
    }
    
    /* Welcome Section */
    .welcome-section {
        margin-bottom: 1.5rem;
    }
    
    .welcome-card {
        background: linear-gradient(135deg, var(--admin-primary), #8B5CF6);
        border-radius: var(--radius-lg);
        padding: 1.5rem 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .welcome-text h2 {
        color: white;
        font-size: 1.3rem;
        font-weight: 700;
        margin-bottom: 0.25rem;
    }
    
    .welcome-text p {
        color: rgba(255,255,255,0.85);
        font-size: 0.85rem;
    }
    
    .welcome-date {
        color: rgba(255,255,255,0.9);
        font-size: 0.9rem;
        text-align: right;
    }
    
    .welcome-date .time {
        font-size: 1.5rem;
        font-weight: 700;
    }
    
    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    
    .stat-card {
        background: var(--admin-surface);
        border-radius: var(--radius-lg);
        padding: 1.25rem;
        border: 1px solid var(--admin-border);
        transition: var(--transition);
        position: relative;
        cursor: pointer;
        text-decoration: none;
        display: block;
    }
    
    .stat-card:hover {
        transform: translateY(-3px);
        border-color: var(--admin-primary);
        box-shadow: 0 8px 25px rgba(59, 130, 246, 0.15);
    }
    
    .stat-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.75rem;
    }
    
    .stat-card-title {
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--text-muted);
        font-weight: 600;
    }
    
    .stat-card-icon {
        width: 42px;
        height: 42px;
        border-radius: var(--radius-md);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        flex-shrink: 0;
    }
    
    .stat-icon-green { background: rgba(16, 185, 129, 0.15); color: #10B981; }
    .stat-icon-blue { background: rgba(59, 130, 246, 0.15); color: #3B82F6; }
    .stat-icon-purple { background: rgba(139, 92, 246, 0.15); color: #8B5CF6; }
    .stat-icon-orange { background: rgba(245, 158, 11, 0.15); color: #F59E0B; }
    .stat-icon-red { background: rgba(239, 68, 68, 0.15); color: #EF4444; }
    .stat-icon-cyan { background: rgba(6, 182, 212, 0.15); color: #06B6D4; }
    .stat-icon-pink { background: rgba(236, 72, 153, 0.15); color: #EC4899; }
    .stat-icon-emerald { background: rgba(16, 185, 129, 0.15); color: #059669; }
    .stat-icon-indigo { background: rgba(99, 102, 241, 0.15); color: #6366F1; }
    .stat-icon-amber { background: rgba(245, 158, 11, 0.15); color: #D97706; }
    
    .stat-card-value {
        font-size: 1.8rem;
        font-weight: 800;
        color: var(--text-primary);
        line-height: 1.2;
        margin-bottom: 0.25rem;
    }
    
    .stat-card-sub {
        font-size: 0.7rem;
        color: var(--text-muted);
    }
    
    .stat-card-details {
        display: flex;
        gap: 1rem;
        margin-top: 0.5rem;
        font-size: 0.72rem;
        color: var(--text-muted);
    }
    
    /* Dashboard Layout */
    .dashboard-row {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    
    .dashboard-row.equal {
        grid-template-columns: 1fr 1fr;
    }
    
    .dashboard-row.single {
        grid-template-columns: 1fr;
    }
    
    .dashboard-card {
        background: var(--admin-surface);
        border-radius: var(--radius-lg);
        border: 1px solid var(--admin-border);
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }
    
    .card-header {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--admin-border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
        min-height: 56px;
    }
    
    .card-header h3 {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .card-header h3 i {
        color: var(--admin-primary);
        font-size: 0.9rem;
    }
    
    .card-body {
        padding: 1.25rem;
        flex: 1;
        overflow: auto;
    }
    
    .card-body.no-padding {
        padding: 0;
    }
    
    /* Tables */
    .data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8rem;
    }
    
    .data-table th {
        text-align: left;
        padding: 0.6rem 0;
        color: var(--text-muted);
        font-weight: 600;
        border-bottom: 2px solid var(--admin-border);
        white-space: nowrap;
        font-size: 0.7rem;
        text-transform: uppercase;
    }
    
    .data-table td {
        padding: 0.6rem 0;
        border-bottom: 1px solid var(--admin-border-light);
        color: var(--text-secondary);
    }
    
    .data-table tr:last-child td {
        border-bottom: none;
    }
    
    .data-table tr:hover td {
        background: rgba(59, 130, 246, 0.02);
    }
    
    /* Status Badges */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 0.2rem 0.6rem;
        border-radius: var(--radius-full);
        font-size: 0.65rem;
        font-weight: 600;
        white-space: nowrap;
    }
    
    .status-pending { background: rgba(245, 158, 11, 0.15); color: #F59E0B; }
    .status-confirmed { background: rgba(59, 130, 246, 0.15); color: #3B82F6; }
    .status-processing { background: rgba(139, 92, 246, 0.15); color: #8B5CF6; }
    .status-packed { background: rgba(236, 72, 153, 0.15); color: #EC4899; }
    .status-shipped { background: rgba(6, 182, 212, 0.15); color: #06B6D4; }
    .status-delivered { background: rgba(16, 185, 129, 0.15); color: #10B981; }
    .status-cancelled { background: rgba(239, 68, 68, 0.15); color: #EF4444; }
    
    /* Alert Banner */
    .alert-banner {
        background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(239, 68, 68, 0.05));
        border: 1px solid rgba(245, 158, 11, 0.3);
        border-radius: var(--radius-lg);
        padding: 1rem 1.5rem;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .alert-banner.info {
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(6, 182, 212, 0.05));
        border-color: rgba(59, 130, 246, 0.3);
    }
    
    .alert-content {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    
    .alert-content i {
        font-size: 1.5rem;
    }
    
    .alert-banner:not(.info) .alert-content i { color: #F59E0B; }
    .alert-banner.info .alert-content i { color: #3B82F6; }
    
    .alert-text h4 {
        font-size: 0.9rem;
        font-weight: 700;
        margin-bottom: 0.2rem;
    }
    
    .alert-banner:not(.info) .alert-text h4 { color: #F59E0B; }
    .alert-banner.info .alert-text h4 { color: #3B82F6; }
    
    .alert-text p {
        font-size: 0.8rem;
        color: var(--text-secondary);
    }
    
    .alert-actions {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    
    .btn-alert-action {
        padding: 0.6rem 1.2rem;
        border-radius: var(--radius-md);
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        border: none;
        font-family: inherit;
        font-size: 0.85rem;
        text-decoration: none;
    }
    
    .btn-primary-action {
        background: var(--admin-primary);
        color: white;
    }
    
    .btn-primary-action:hover {
        background: var(--admin-primary-dark);
        transform: translateY(-1px);
    }
    
    .btn-warning-action {
        background: #F59E0B;
        color: #1A1A1A;
    }
    
    .btn-warning-action:hover {
        background: #D97706;
        transform: translateY(-1px);
    }
    
    .btn-secondary-action {
        background: rgba(255,255,255,0.1);
        color: var(--text-secondary);
        border: 1px solid var(--admin-border);
    }
    
    .btn-secondary-action:hover {
        background: rgba(255,255,255,0.2);
        color: var(--text-primary);
    }
    
    /* Product/User List */
    .item-list {
        display: flex;
        flex-direction: column;
        gap: 0;
    }
    
    .list-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 0;
        border-bottom: 1px solid var(--admin-border-light);
        transition: background 0.2s;
    }
    
    .list-item:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }
    
    .list-item:first-child {
        padding-top: 0;
    }
    
    .list-item:hover {
        background: rgba(59, 130, 246, 0.02);
    }
    
    .item-avatar {
        width: 42px;
        height: 42px;
        background: var(--admin-bg);
        border-radius: var(--radius-sm);
        overflow: hidden;
        flex-shrink: 0;
    }
    
    .item-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .item-avatar .avatar-placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        text-transform: uppercase;
        color: white;
        font-size: 0.9rem;
    }
    
    .item-avatar.circle {
        border-radius: 50%;
    }
    
    .item-info {
        flex: 1;
        min-width: 0;
    }
    
    .item-title {
        font-weight: 600;
        font-size: 0.85rem;
        color: var(--text-primary);
        margin-bottom: 0.15rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .item-meta {
        font-size: 0.7rem;
        color: var(--text-muted);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .item-right {
        text-align: right;
        flex-shrink: 0;
    }
    
    .item-value {
        font-weight: 700;
        font-size: 0.85rem;
        color: var(--text-primary);
    }
    
    .item-sub {
        font-size: 0.7rem;
        color: var(--text-muted);
    }
    
    /* Chart Containers */
    .chart-container {
        position: relative;
        height: 280px;
        width: 100%;
    }
    
    /* Empty States */
    .empty-state {
        text-align: center;
        padding: 3rem 1.5rem;
    }
    
    .empty-state i {
        font-size: 3rem;
        color: var(--text-muted);
        opacity: 0.3;
        margin-bottom: 1rem;
    }
    
    .empty-state h4 {
        font-size: 1rem;
        color: var(--text-secondary);
        margin-bottom: 0.25rem;
    }
    
    .empty-state p {
        color: var(--text-muted);
        font-size: 0.8rem;
    }
    
    /* View All Link */
    .view-all-link {
        font-size: 0.75rem;
        color: var(--admin-primary);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: var(--transition);
        white-space: nowrap;
    }
    
    .view-all-link:hover {
        gap: 8px;
        color: var(--admin-primary-light);
    }
    
    /* Quick Actions */
    .quick-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
    }
    
    .quick-action-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.6rem 1.2rem;
        background: var(--admin-bg);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-md);
        color: var(--text-secondary);
        text-decoration: none;
        font-size: 0.8rem;
        font-weight: 500;
        transition: var(--transition);
    }
    
    .quick-action-btn:hover {
        border-color: var(--admin-primary);
        color: var(--admin-primary);
        background: var(--admin-primary-glow);
    }
    
    .quick-action-btn i {
        font-size: 0.9rem;
    }
    
    /* Modal Styles */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.7);
        z-index: 2000;
        align-items: center;
        justify-content: center;
    }
    
    .modal-overlay.active {
        display: flex;
    }
    
    .modal {
        background: var(--admin-surface);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-lg);
        width: 90%;
        max-width: 650px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: var(--shadow-xl);
        animation: modalSlideIn 0.3s ease;
    }
    
    @keyframes modalSlideIn {
        from { transform: translateY(-30px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }
    
    .modal-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid var(--admin-border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        top: 0;
        background: var(--admin-surface);
        z-index: 1;
    }
    
    .modal-header h3 {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .modal-header h3 i {
        color: var(--admin-primary);
    }
    
    .modal-close {
        background: none;
        border: none;
        color: var(--text-muted);
        font-size: 1.5rem;
        cursor: pointer;
        transition: var(--transition);
        padding: 0.25rem;
        line-height: 1;
    }
    
    .modal-close:hover {
        color: var(--admin-danger);
    }
    
    .modal-body {
        padding: 1.5rem;
    }
    
    .modal-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid var(--admin-border);
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        position: sticky;
        bottom: 0;
        background: var(--admin-surface);
    }
    
    .form-group {
        margin-bottom: 1.25rem;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text-primary);
    }
    
    .form-group label .required {
        color: #EF4444;
    }
    
    .form-group input[type="text"],
    .form-group input[type="tel"],
    .form-group input[type="url"],
    .form-group textarea,
    .form-group select {
        width: 100%;
        padding: 0.7rem 1rem;
        background: var(--admin-bg);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-md);
        color: var(--text-primary);
        font-family: var(--font-body);
        font-size: 0.85rem;
        transition: var(--transition);
    }
    
    .form-group input:focus,
    .form-group textarea:focus,
    .form-group select:focus {
        outline: none;
        border-color: var(--admin-primary);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .form-group textarea {
        resize: vertical;
        min-height: 80px;
    }
    
    .form-group .help-text {
        font-size: 0.7rem;
        color: var(--text-muted);
        margin-top: 0.35rem;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }
    
    .checkbox-group {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .checkbox-group input[type="checkbox"] {
        width: auto;
        accent-color: var(--admin-primary);
    }
    
    .checkbox-group label {
        margin-bottom: 0;
        cursor: pointer;
    }
    
    .file-upload-wrapper {
        position: relative;
    }
    
    .file-upload-preview {
        width: 100%;
        height: 120px;
        background: var(--admin-bg);
        border: 2px dashed var(--admin-border);
        border-radius: var(--radius-md);
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 0.5rem;
        overflow: hidden;
        cursor: pointer;
        transition: var(--transition);
    }
    
    .file-upload-preview:hover {
        border-color: var(--admin-primary);
        background: rgba(59, 130, 246, 0.05);
    }
    
    .file-upload-preview img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
    }
    
    .file-upload-preview .placeholder {
        text-align: center;
        color: var(--text-muted);
    }
    
    .file-upload-preview .placeholder i {
        font-size: 2rem;
        margin-bottom: 0.5rem;
    }
    
    .btn {
        padding: 0.7rem 1.5rem;
        border-radius: var(--radius-md);
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        border: none;
        font-family: inherit;
        font-size: 0.85rem;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .btn-primary {
        background: var(--admin-primary);
        color: white;
    }
    
    .btn-primary:hover {
        background: var(--admin-primary-dark);
        transform: translateY(-1px);
    }
    
    .btn-secondary {
        background: var(--admin-bg);
        color: var(--text-secondary);
        border: 1px solid var(--admin-border);
    }
    
    .btn-secondary:hover {
        background: var(--admin-surface-hover);
        color: var(--text-primary);
    }
    
    /* Loading Spinner */
    .loading-spinner {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 2px solid rgba(255,255,255,0.3);
        border-top-color: white;
        border-radius: 50%;
        animation: spin 0.7s linear infinite;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    /* Toast Notifications */
    .toast-container {
        position: fixed;
        bottom: 24px;
        right: 24px;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    
    .toast {
        background: var(--admin-surface);
        border: 1px solid var(--admin-border);
        color: var(--text-primary);
        padding: 12px 20px;
        border-radius: var(--radius-md);
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.85rem;
        font-weight: 500;
        box-shadow: 0 8px 32px rgba(0,0,0,0.5);
        animation: slideInRight 0.3s ease;
        max-width: 420px;
    }
    
    .toast.success { border-left: 3px solid #10B981; }
    .toast.success i { color: #10B981; }
    .toast.error { border-left: 3px solid #EF4444; }
    .toast.error i { color: #EF4444; }
    .toast.warning { border-left: 3px solid #F59E0B; }
    .toast.warning i { color: #F59E0B; }
    
    @keyframes slideInRight {
        from { transform: translateX(120%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    
    @keyframes slideOutRight {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(120%); opacity: 0; }
    }
    
    /* Responsive */
    @media (max-width: 1200px) {
        .dashboard-row,
        .dashboard-row.equal {
            grid-template-columns: 1fr;
        }
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .welcome-card {
            flex-direction: column;
            text-align: center;
        }
        
        .welcome-date {
            text-align: center;
        }
        
        .form-row {
            grid-template-columns: 1fr;
        }
        
        .alert-banner {
            flex-direction: column;
            text-align: center;
        }
        
        .alert-content {
            flex-direction: column;
            text-align: center;
        }
        
        .alert-actions {
            justify-content: center;
        }
        
        .stat-card-value {
            font-size: 1.4rem;
        }
    }
    
    @media (max-width: 480px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .data-table {
            font-size: 0.7rem;
        }
        
        .quick-actions {
            flex-direction: column;
        }
        
        .quick-action-btn {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<!-- Dashboard Content -->
<div class="dashboard-container">
    
    <!-- Welcome Section -->
    <div class="welcome-section">
        <div class="welcome-card">
            <div class="welcome-text">
                <h2>👋 Welcome back, <?php echo $userName; ?>!</h2>
                <p>Here's what's happening with your store today.</p>
            </div>
            <div class="welcome-date">
                <div class="time" id="liveTime"><?php echo date('h:i A'); ?></div>
                <div style="font-size: 0.9rem;"><?php echo date('l, F j, Y'); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Vendor Alert Banner (if admin not linked to vendors) -->
    <?php if (!$vendorLinked): ?>
    <div class="alert-banner" id="vendorAlert">
        <div class="alert-content">
            <i class="fas fa-store-alt"></i>
            <div class="alert-text">
                <h4>Vendor Account Required</h4>
                <p>Your admin account is not linked to a vendor profile. To sell products on Bless Mall, you need to create a vendor account first.</p>
            </div>
        </div>
        <div class="alert-actions">
            <button class="btn-alert-action btn-primary-action" id="createVendorBtn" onclick="createVendorAccount()">
                <i class="fas fa-plus"></i> Create Vendor Account
            </button>
        </div>
    </div>
    <?php elseif (!$vendorProfileComplete): ?>
    <div class="alert-banner info" id="vendorProfileAlert">
        <div class="alert-content">
            <i class="fas fa-user-edit"></i>
            <div class="alert-text">
                <h4>Complete Your Vendor Profile</h4>
                <p>Your vendor account needs additional details: <?php echo implode(', ', $vendorMissingFields); ?>. Complete your profile to start selling products on Bless Mall.</p>
            </div>
        </div>
        <div class="alert-actions">
            <button class="btn-alert-action btn-warning-action" onclick="openVendorProfileModal()">
                <i class="fas fa-edit"></i> Complete Profile
            </button>
            <button class="btn-alert-action btn-secondary-action" onclick="dismissProfileAlert()">
                <i class="fas fa-clock"></i> Do It Later
            </button>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Quick Actions -->
    <div class="quick-actions" style="margin-bottom: 1.5rem;">
        <?php if ($vendorLinked): ?>
        <a href="products.php?action=add" class="quick-action-btn">
            <i class="fas fa-plus-circle"></i> Add Product
        </a>
        <?php endif; ?>
        <a href="orders.php" class="quick-action-btn">
            <i class="fas fa-shopping-bag"></i> View Orders
        </a>
        <a href="products.php" class="quick-action-btn">
            <i class="fas fa-box-open"></i> Manage Products
        </a>
        <a href="inventory.php" class="quick-action-btn">
            <i class="fas fa-warehouse"></i> Inventory
        </a>
        <a href="categories.php" class="quick-action-btn">
            <i class="fas fa-th-large"></i> Categories
        </a>
        <a href="promotions.php" class="quick-action-btn">
            <i class="fas fa-tags"></i> Promotions
        </a>
        <a href="settings.php" class="quick-action-btn">
            <i class="fas fa-cog"></i> Settings
        </a>
    </div>
    
    <!-- Statistics Grid -->
    <div class="stats-grid">
        <!-- Revenue Card -->
        <a href="orders.php" class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-title">Total Revenue</span>
                <div class="stat-card-icon stat-icon-green"><i class="fas fa-cedi-sign"></i></div>
            </div>
            <div class="stat-card-value"><?php echo formatPrice($totalRevenue); ?></div>
            <div class="stat-card-details">
                <span>Today: <?php echo formatPrice($todayRevenue); ?></span>
                <span>Month: <?php echo formatPrice($monthlyRevenue); ?></span>
            </div>
        </a>
        
        <!-- Orders Card -->
        <a href="orders.php" class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-title">Orders</span>
                <div class="stat-card-icon stat-icon-blue"><i class="fas fa-shopping-bag"></i></div>
            </div>
            <div class="stat-card-value"><?php echo number_format($totalOrders); ?></div>
            <div class="stat-card-details">
                <span>Pending: <?php echo number_format($pendingOrders); ?></span>
                <span>Today: <?php echo number_format($todayOrders); ?></span>
                <span>Avg: <?php echo formatPrice($avgOrderValue); ?></span>
            </div>
        </a>
        
        <!-- Products Card -->
        <a href="products.php" class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-title">Products</span>
                <div class="stat-card-icon stat-icon-purple"><i class="fas fa-box-open"></i></div>
            </div>
            <div class="stat-card-value"><?php echo number_format($totalProducts); ?></div>
            <div class="stat-card-details">
                <span>Low Stock: <?php echo number_format($lowStockProducts); ?></span>
                <span>Out: <?php echo number_format($outOfStockProducts); ?></span>
            </div>
        </a>
        
        <!-- Users Card -->
        <a href="users.php" class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-title">Users</span>
                <div class="stat-card-icon stat-icon-orange"><i class="fas fa-users"></i></div>
            </div>
            <div class="stat-card-value"><?php echo number_format($totalUsers); ?></div>
            <div class="stat-card-details">
                <span>New (30d): <?php echo number_format($newUsers); ?></span>
            </div>
        </a>
        
        <!-- Vendors Card -->
        <a href="vendors.php" class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-title">Vendors</span>
                <div class="stat-card-icon stat-icon-cyan"><i class="fas fa-store"></i></div>
            </div>
            <div class="stat-card-value"><?php echo number_format($totalVendors); ?></div>
            <div class="stat-card-details">
                <span>Express: <?php echo number_format($expressVendors); ?></span>
                <?php if ($pendingVendorsCount > 0): ?>
                <span style="color: #F59E0B;">Pending: <?php echo number_format($pendingVendorsCount); ?></span>
                <?php endif; ?>
            </div>
        </a>
        
        <!-- Reviews Card -->
        <a href="reviews.php" class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-title">Reviews</span>
                <div class="stat-card-icon stat-icon-pink"><i class="fas fa-star"></i></div>
            </div>
            <div class="stat-card-value"><?php echo number_format($totalReviews); ?></div>
            <div class="stat-card-details">
                <span>Avg Rating: <?php echo $avgRating; ?> / 5</span>
            </div>
        </a>
        
        <!-- Categories Card -->
        <a href="categories.php" class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-title">Categories</span>
                <div class="stat-card-icon stat-icon-indigo"><i class="fas fa-th-large"></i></div>
            </div>
            <div class="stat-card-value"><?php echo number_format($totalCategories); ?></div>
            <div class="stat-card-details">
                <span>Active categories</span>
            </div>
        </a>
        
        <!-- Promotions Card -->
        <a href="promotions.php" class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-title">Active Promos</span>
                <div class="stat-card-icon stat-icon-amber"><i class="fas fa-tags"></i></div>
            </div>
            <div class="stat-card-value"><?php echo number_format($activePromotions); ?></div>
            <div class="stat-card-details">
                <span>Currently running</span>
            </div>
        </a>
    </div>
    
    <!-- Charts Row -->
    <?php if ($totalOrders > 0): ?>
    <div class="dashboard-row equal">
        <!-- Revenue Chart -->
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-chart-line"></i> Revenue & Orders (14 Days)</h3>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Order Status Chart -->
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-chart-pie"></i> Order Status Distribution</h3>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="orderStatusChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Recent Orders & Low Stock Row -->
    <?php if (!empty($recentOrders) || !empty($lowStockProductsList)): ?>
    <div class="dashboard-row">
        <!-- Recent Orders -->
        <?php if (!empty($recentOrders)): ?>
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-clock"></i> Recent Orders</h3>
                <a href="orders.php" class="view-all-link">View All <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="card-body no-padding" style="overflow-x: auto;">
                <table class="data-table" style="padding: 0 1.25rem;">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentOrders as $order): ?>
                        <tr>
                            <td><a href="order-detail.php?id=<?php echo $order['id']; ?>" style="color: var(--admin-primary); text-decoration: none; font-weight: 600;">#<?php echo htmlspecialchars($order['order_number']); ?></a></td>
                            <td><?php echo htmlspecialchars($order['customer_name'] ?? 'Guest'); ?></td>
                            <td><?php echo $order['item_count']; ?></td>
                            <td><?php echo formatPrice($order['total_amount']); ?></td>
                            <td><span class="status-badge status-<?php echo $order['order_status']; ?>"><?php echo ucfirst($order['order_status']); ?></span></td>
                            <td style="white-space: nowrap;"><?php echo date('M d', strtotime($order['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Low Stock Products -->
        <?php if (!empty($lowStockProductsList)): ?>
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Low Stock Products</h3>
                <a href="inventory.php" class="view-all-link">Manage <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="card-body">
                <div class="item-list">
                    <?php foreach ($lowStockProductsList as $product): 
                        $stockPercentage = ($product['stock_quantity'] / max($product['low_stock_threshold'], 1)) * 100;
                        $stockColor = $stockPercentage <= 25 ? '#EF4444' : ($stockPercentage <= 50 ? '#F59E0B' : '#3B82F6');
                    ?>
                    <div class="list-item">
                        <div class="item-info">
                            <div class="item-title"><?php echo htmlspecialchars($product['name']); ?></div>
                            <div class="item-meta">
                                SKU: <?php echo htmlspecialchars($product['sku'] ?? 'N/A'); ?>
                                <?php if (!empty($product['vendor_name'])): ?>
                                | <?php echo htmlspecialchars($product['vendor_name']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="item-right">
                            <div class="item-value" style="color: <?php echo $stockColor; ?>;">
                                <?php echo $product['stock_quantity']; ?> left
                            </div>
                            <div class="item-sub">Threshold: <?php echo $product['low_stock_threshold']; ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Top Products & Recent Reviews Row -->
    <?php if (!empty($topProducts) || !empty($recentReviews)): ?>
    <div class="dashboard-row equal">
        <!-- Top Selling Products -->
        <?php if (!empty($topProducts)): ?>
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-trophy"></i> Top Selling Products</h3>
                <a href="products.php" class="view-all-link">All Products <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="card-body">
                <div class="item-list">
                    <?php foreach ($topProducts as $index => $product): 
                        $imagePath = $product['image_path'] ?? '';
                        if (!empty($imagePath)) {
                            $fullImagePath = __DIR__ . '/../' . ltrim($imagePath, '/');
                            $imgSrc = file_exists($fullImagePath) ? BASE_URL . ltrim($imagePath, '/') : null;
                        } else {
                            $imgSrc = null;
                        }
                        $colors = ['#3B82F6', '#8B5CF6', '#10B981', '#F59E0B', '#EC4899', '#06B6D4', '#6366F1', '#14B8A6'];
                        $badgeColor = $colors[$index % count($colors)];
                    ?>
                    <div class="list-item">
                        <div class="item-avatar">
                            <?php if ($imgSrc): ?>
                            <img src="<?php echo $imgSrc; ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                            <?php else: ?>
                            <div class="avatar-placeholder" style="background: <?php echo $badgeColor; ?>;">
                                <?php echo strtoupper(substr($product['name'], 0, 2)); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="item-info">
                            <div class="item-title"><?php echo htmlspecialchars($product['name']); ?></div>
                            <div class="item-meta"><?php echo number_format($product['total_sold']); ?> units sold</div>
                        </div>
                        <div class="item-right">
                            <div class="item-value"><?php echo formatPrice($product['total_revenue']); ?></div>
                            <div class="item-sub">revenue</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Recent Reviews -->
        <?php if (!empty($recentReviews)): ?>
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-star"></i> Recent Reviews</h3>
                <a href="reviews.php" class="view-all-link">All Reviews <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="card-body">
                <div class="item-list">
                    <?php foreach ($recentReviews as $review): ?>
                    <div class="list-item">
                        <div class="item-avatar circle">
                            <div class="avatar-placeholder" style="background: linear-gradient(135deg, #F59E0B, #EF4444);">
                                <?php echo strtoupper(substr($review['user_name'], 0, 1)); ?>
                            </div>
                        </div>
                        <div class="item-info">
                            <div class="item-title"><?php echo htmlspecialchars($review['user_name']); ?></div>
                            <div class="item-meta">
                                on <?php echo htmlspecialchars(mb_strlen($review['product_name']) > 30 ? mb_substr($review['product_name'], 0, 30) . '...' : $review['product_name']); ?>
                            </div>
                        </div>
                        <div class="item-right">
                            <div class="item-value">
                                <?php 
                                $stars = '';
                                for ($i = 1; $i <= 5; $i++) {
                                    $stars .= $i <= $review['rating'] ? '★' : '☆';
                                }
                                echo '<span style="color: #F59E0B; font-size: 0.8rem;">' . $stars . '</span>';
                                ?>
                            </div>
                            <div class="item-sub"><?php echo date('M d', strtotime($review['created_at'])); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Recent Users Row -->
    <?php if (!empty($recentUsers)): ?>
    <div class="dashboard-row single">
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-user-plus"></i> Recent Users</h3>
                <a href="users.php" class="view-all-link">View All <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="card-body">
                <div class="item-list" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 0;">
                    <?php foreach ($recentUsers as $user): 
                        $avatarColors = [
                            '#3B82F6', '#8B5CF6', '#10B981', '#F59E0B', '#EC4899', '#06B6D4', '#6366F1', '#14B8A6'
                        ];
                        $avatarColor = $avatarColors[array_rand($avatarColors)];
                    ?>
                    <div class="list-item">
                        <div class="item-avatar circle">
                            <div class="avatar-placeholder" style="background: <?php echo $avatarColor; ?>;">
                                <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                            </div>
                        </div>
                        <div class="item-info">
                            <div class="item-title"><?php echo htmlspecialchars($user['name']); ?></div>
                            <div class="item-meta"><?php echo htmlspecialchars($user['email'] ?? $user['phone']); ?></div>
                        </div>
                        <div class="item-right">
                            <div class="item-value">
                                <span class="status-badge <?php echo $user['is_active'] ? 'status-delivered' : 'status-cancelled'; ?>">
                                    <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                            <div class="item-sub"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
</div>

<!-- Vendor Profile Completion Modal -->
<div class="modal-overlay" id="vendorProfileModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-store-alt"></i> <?php echo $vendorLinked ? 'Complete Your Vendor Profile' : 'Create Vendor Profile'; ?></h3>
            <button class="modal-close" onclick="closeVendorProfileModal()">&times;</button>
        </div>
        <form id="vendorProfileForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_vendor_profile">
            <input type="hidden" name="vendor_id" value="<?php echo $vendorId; ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label>Business Name <span class="required">*</span></label>
                    <input type="text" name="business_name" id="vpBusinessName" 
                           value="<?php echo htmlspecialchars($vendorData['business_name'] ?? ''); ?>" 
                           placeholder="Enter your store name" required maxlength="200">
                    <div class="help-text">This will be displayed to customers as your store name</div>
                </div>
                
                <div class="form-group">
                    <label>Store Description <span class="required">*</span></label>
                    <textarea name="description" id="vpDescription" rows="3"
                              placeholder="Tell customers about your store and what you sell..." required><?php echo htmlspecialchars($vendorData['description'] ?? ''); ?></textarea>
                    <div class="help-text">A good description helps customers trust your store</div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Contact Phone <span class="required">*</span></label>
                        <input type="tel" name="contact_phone" id="vpPhone" 
                               value="<?php echo htmlspecialchars($vendorData['contact_phone'] ?? ''); ?>" 
                               placeholder="e.g., 024XXXXXXX" required maxlength="20">
                    </div>
                    
                    <div class="form-group">
                        <label>WhatsApp (Optional)</label>
                        <input type="tel" name="whatsapp" id="vpWhatsapp" 
                               value="<?php echo htmlspecialchars($vendorData['whatsapp'] ?? ''); ?>" 
                               placeholder="e.g., 024XXXXXXX" maxlength="20">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Store Logo</label>
                        <div class="file-upload-wrapper">
                            <div class="file-upload-preview" id="logoPreview" onclick="document.getElementById('logoInput').click()">
                                <?php if (!empty($vendorData['logo'])): ?>
                                    <img src="<?php echo BASE_URL . ltrim($vendorData['logo'], '/'); ?>" alt="Logo Preview">
                                <?php else: ?>
                                    <div class="placeholder">
                                        <i class="fas fa-image"></i>
                                        <p>Click to upload logo</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="file" name="logo" id="logoInput" accept="image/*" style="display: none;" onchange="previewFile(this, 'logoPreview')">
                        </div>
                        <div class="help-text">Recommended: 200x200px, PNG or JPG</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Store Banner</label>
                        <div class="file-upload-wrapper">
                            <div class="file-upload-preview" id="bannerPreview" onclick="document.getElementById('bannerInput').click()">
                                <?php if (!empty($vendorData['banner'])): ?>
                                    <img src="<?php echo BASE_URL . ltrim($vendorData['banner'], '/'); ?>" alt="Banner Preview">
                                <?php else: ?>
                                    <div class="placeholder">
                                        <i class="fas fa-image"></i>
                                        <p>Click to upload banner</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="file" name="banner" id="bannerInput" accept="image/*" style="display: none;" onchange="previewFile(this, 'bannerPreview')">
                        </div>
                        <div class="help-text">Recommended: 1200x400px, PNG or JPG</div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Store Badge</label>
                        <input type="text" name="store_badge" id="vpBadge" 
                               value="<?php echo htmlspecialchars($vendorData['store_badge'] ?? ''); ?>" 
                               placeholder="e.g., Top Rated, Verified Seller" maxlength="50">
                        <div class="help-text">A badge displayed on your store (optional)</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Express Delivery</label>
                        <div class="checkbox-group" style="margin-top: 0.5rem;">
                            <input type="checkbox" name="is_express" id="vpExpress" value="1" 
                                   <?php echo !empty($vendorData['is_express']) ? 'checked' : ''; ?>>
                            <label for="vpExpress">Enable Express Delivery for your products</label>
                        </div>
                        <div class="help-text">Express products are eligible for 24-48hr delivery</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeVendorProfileModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary" id="saveProfileBtn">
                    <i class="fas fa-save"></i> Save Profile
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    // ========== LIVE CLOCK ==========
    function updateLiveTime() {
        const timeElement = document.getElementById('liveTime');
        if (timeElement) {
            const now = new Date();
            timeElement.textContent = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit',
                hour12: true 
            });
        }
    }
    setInterval(updateLiveTime, 60000);
    
    // ========== VENDOR ACCOUNT CREATION ==========
    async function createVendorAccount() {
        const btn = document.getElementById('createVendorBtn');
        if (!btn) return;
        
        const originalText = btn.innerHTML;
        btn.innerHTML = '<span class="loading-spinner"></span> Creating...';
        btn.disabled = true;
        
        try {
            const formData = new FormData();
            formData.append('action', 'create_admin_vendor');
            
            const response = await fetch('index.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                showToast(data.message, 'success');
                // Open the profile modal after creating vendor
                if (data.vendor_id) {
                    document.querySelector('input[name="vendor_id"]').value = data.vendor_id;
                }
                setTimeout(() => {
                    document.getElementById('vendorAlert')?.remove();
                    openVendorProfileModal();
                }, 1500);
            } else {
                showToast(data.message, 'error');
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        } catch (error) {
            console.error('Error:', error);
            showToast('An error occurred. Please try again.', 'error');
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    }
    
    // ========== VENDOR PROFILE MODAL ==========
    function openVendorProfileModal() {
        const modal = document.getElementById('vendorProfileModal');
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }
    
    function closeVendorProfileModal() {
        const modal = document.getElementById('vendorProfileModal');
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    }
    
    function dismissProfileAlert() {
        const alert = document.getElementById('vendorProfileAlert');
        if (alert) {
            alert.style.transition = 'all 0.3s ease';
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(() => alert.remove(), 300);
        }
        showToast('You can complete your vendor profile later from Settings.', 'info');
    }
    
    // File preview
    function previewFile(input, previewId) {
        const preview = document.getElementById(previewId);
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }
    
    // Close modal on overlay click
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-overlay')) {
            e.target.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
    
    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modals = document.querySelectorAll('.modal-overlay.active');
            modals.forEach(modal => {
                modal.classList.remove('active');
            });
            document.body.style.overflow = '';
        }
    });
    
    // Vendor Profile Form Submission
    const vendorProfileForm = document.getElementById('vendorProfileForm');
    if (vendorProfileForm) {
        vendorProfileForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const saveBtn = document.getElementById('saveProfileBtn');
            const originalText = saveBtn.innerHTML;
            saveBtn.innerHTML = '<span class="loading-spinner"></span> Saving...';
            saveBtn.disabled = true;
            
            try {
                const formData = new FormData(this);
                formData.append('action', 'update_vendor_profile');
                
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showToast(data.message, 'success');
                    closeVendorProfileModal();
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showToast(data.message, 'error');
                    saveBtn.innerHTML = originalText;
                    saveBtn.disabled = false;
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('An error occurred. Please try again.', 'error');
                saveBtn.innerHTML = originalText;
                saveBtn.disabled = false;
            }
        });
    }
    
    // Auto-open vendor profile modal if profile is incomplete
    <?php if ($vendorLinked && !$vendorProfileComplete): ?>
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            openVendorProfileModal();
        }, 800);
    });
    <?php endif; ?>
    
    // ========== CHARTS (Only if there's data) ==========
    <?php if ($totalOrders > 0): ?>
    // Revenue & Orders Chart
    const chartData = <?php echo json_encode($revenueChartData); ?>;
    const revenueCtx = document.getElementById('revenueChart')?.getContext('2d');
    
    if (revenueCtx) {
        new Chart(revenueCtx, {
            type: 'bar',
            data: {
                labels: chartData.map(item => item.date),
                datasets: [
                    {
                        label: 'Revenue (GHS)',
                        data: chartData.map(item => item.revenue),
                        backgroundColor: 'rgba(59, 130, 246, 0.6)',
                        borderColor: '#3B82F6',
                        borderWidth: 1,
                        borderRadius: 6,
                        yAxisID: 'y',
                        order: 2
                    },
                    {
                        label: 'Orders',
                        data: chartData.map(item => item.orders),
                        type: 'line',
                        borderColor: '#10B981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 2,
                        tension: 0.4,
                        pointBackgroundColor: '#10B981',
                        pointBorderColor: '#1E293B',
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        yAxisID: 'y1',
                        order: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        labels: { 
                            color: '#94A3B8',
                            usePointStyle: true,
                            padding: 20
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                if (context.dataset.label === 'Revenue (GHS)') {
                                    return 'Revenue: GHS ' + parseFloat(context.raw).toFixed(2);
                                }
                                return 'Orders: ' + context.raw;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        beginAtZero: true,
                        grid: { color: '#334155' },
                        ticks: { 
                            color: '#94A3B8', 
                            callback: function(value) { return 'GHS ' + value; }
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        beginAtZero: true,
                        grid: { drawOnChartArea: false },
                        ticks: { 
                            color: '#94A3B8',
                            stepSize: 1
                        }
                    },
                    x: {
                        grid: { color: '#334155' },
                        ticks: { color: '#94A3B8' }
                    }
                }
            }
        });
    }
    
    // Order Status Chart
    const orderStatuses = <?php echo json_encode($orderStatusCounts); ?>;
    const statusCtx = document.getElementById('orderStatusChart')?.getContext('2d');
    
    if (statusCtx) {
        const statusData = [
            orderStatuses.pending,
            orderStatuses.confirmed,
            orderStatuses.processing,
            orderStatuses.packed,
            orderStatuses.shipped,
            orderStatuses.delivered,
            orderStatuses.cancelled
        ];
        
        const total = statusData.reduce((a, b) => a + b, 0);
        
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'Confirmed', 'Processing', 'Packed', 'Shipped', 'Delivered', 'Cancelled'],
                datasets: [{
                    data: statusData,
                    backgroundColor: [
                        '#F59E0B', '#3B82F6', '#8B5CF6', '#EC4899', '#06B6D4', '#10B981', '#EF4444'
                    ],
                    borderWidth: 0,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { 
                            color: '#94A3B8', 
                            font: { size: 11 },
                            padding: 16,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }
    <?php endif; ?>
    
    // ========== TOAST NOTIFICATION SYSTEM ==========
    function showToast(message, type = 'success') {
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };
        
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `<i class="fas ${icons[type] || icons.info}"></i><span>${message}</span>`;
        container.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'slideOutRight 0.3s ease forwards';
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }
</script>

<?php
// Include admin footer
require_once __DIR__ . '/footer.php';
?>
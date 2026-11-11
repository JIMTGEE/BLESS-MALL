<?php
/**
 * reports.php – Bless Mall Admin Reports & Analytics
 * Premium Admin Dashboard Analytics & Reporting
 * 
 * Features:
 * - Sales Overview & Revenue Analytics
 * - Order Statistics & Trends
 * - Product Performance Reports
 * - Customer Analytics
 * - Vendor Performance
 * - Inventory Reports
 * - Export Capabilities
 * - Date Range Filtering
 * 
 * Developed by NerdSpring Software Engineering
 * https://nerd-spring.com
 */

// ------------------------------------------------------------
// 1. BOOTSTRAP & AUTHENTICATION
// ------------------------------------------------------------
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

// Check admin authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$role_id = (int)$_SESSION['role_id'];
if (!in_array($role_id, [1, 2])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$is_admin = ($role_id === 1);
$user_name = htmlspecialchars($_SESSION['user_name'] ?? 'Admin User');

// ------------------------------------------------------------
// 2. DATABASE CONNECTION
// ------------------------------------------------------------
$conn = getConnection();

// ------------------------------------------------------------
// 3. DATE RANGE FILTERING
// ------------------------------------------------------------
$dateRange = isset($_GET['range']) ? $_GET['range'] : '30days';
$customStart = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$customEnd = isset($_GET['end_date']) ? $_GET['end_date'] : '';

switch ($dateRange) {
    case 'today':
        $dateFilter = "DATE(created_at) = CURDATE()";
        $dateLabel = "Today";
        break;
    case 'yesterday':
        $dateFilter = "DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        $dateLabel = "Yesterday";
        break;
    case '7days':
        $dateFilter = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $dateLabel = "Last 7 Days";
        break;
    case '30days':
        $dateFilter = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $dateLabel = "Last 30 Days";
        break;
    case '90days':
        $dateFilter = "created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
        $dateLabel = "Last 90 Days";
        break;
    case 'this_month':
        $dateFilter = "DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
        $dateLabel = "This Month";
        break;
    case 'last_month':
        $dateFilter = "DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m')";
        $dateLabel = "Last Month";
        break;
    case 'this_year':
        $dateFilter = "YEAR(created_at) = YEAR(CURDATE())";
        $dateLabel = "This Year";
        break;
    case 'custom':
        if (!empty($customStart) && !empty($customEnd)) {
            $startDate = $conn->real_escape_string($customStart);
            $endDate = $conn->real_escape_string($customEnd);
            $dateFilter = "DATE(created_at) BETWEEN '$startDate' AND '$endDate'";
            $dateLabel = date('M d, Y', strtotime($customStart)) . ' - ' . date('M d, Y', strtotime($customEnd));
        } else {
            $dateFilter = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            $dateLabel = "Last 30 Days";
        }
        break;
    default:
        $dateFilter = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $dateLabel = "Last 30 Days";
}

// ------------------------------------------------------------
// 4. SALES & REVENUE STATISTICS
// ------------------------------------------------------------

// Total Revenue
$revenueQuery = "SELECT 
                    COALESCE(SUM(total_amount), 0) AS total_revenue,
                    COUNT(*) AS total_orders,
                    COALESCE(AVG(total_amount), 0) AS avg_order_value,
                    COALESCE(SUM(delivery_fee), 0) AS total_delivery_fees,
                    COALESCE(SUM(discount_amount), 0) AS total_discounts
                 FROM orders 
                 WHERE $dateFilter 
                 AND order_status != 'cancelled'";
$revenueResult = $conn->query($revenueQuery);
$revenueData = $revenueResult->fetch_assoc();

// Revenue by Payment Method
$paymentMethodQuery = "SELECT 
                        payment_method,
                        COUNT(*) AS order_count,
                        COALESCE(SUM(total_amount), 0) AS total_amount,
                        COALESCE(AVG(total_amount), 0) AS avg_amount
                       FROM orders 
                       WHERE $dateFilter 
                       AND order_status != 'cancelled'
                       GROUP BY payment_method
                       ORDER BY total_amount DESC";
$paymentMethodResult = $conn->query($paymentMethodQuery);
$paymentMethods = [];
while ($row = $paymentMethodResult->fetch_assoc()) {
    $paymentMethods[] = $row;
}

// ------------------------------------------------------------
// 5. ORDER STATUS BREAKDOWN
// ------------------------------------------------------------
$orderStatusQuery = "SELECT 
                        order_status,
                        COUNT(*) AS count,
                        COALESCE(SUM(total_amount), 0) AS total_value
                     FROM orders 
                     WHERE $dateFilter
                     GROUP BY order_status
                     ORDER BY FIELD(order_status, 'pending', 'confirmed', 'processing', 'packed', 'shipped', 'delivered', 'cancelled', 'refunded')";
$orderStatusResult = $conn->query($orderStatusQuery);
$orderStatuses = [];
while ($row = $orderStatusResult->fetch_assoc()) {
    $orderStatuses[] = $row;
}

// ------------------------------------------------------------
// 6. DAILY SALES TREND (Last 30 Days)
// ------------------------------------------------------------
$dailyTrendQuery = "SELECT 
                        DATE(created_at) AS sale_date,
                        COUNT(*) AS order_count,
                        COALESCE(SUM(total_amount), 0) AS daily_revenue
                    FROM orders 
                    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    AND order_status != 'cancelled'
                    GROUP BY DATE(created_at)
                    ORDER BY sale_date ASC";
$dailyTrendResult = $conn->query($dailyTrendQuery);
$dailyTrend = [];
while ($row = $dailyTrendResult->fetch_assoc()) {
    $dailyTrend[] = $row;
}

// Monthly Sales Trend (Last 12 Months)
$monthlyTrendQuery = "SELECT 
                        DATE_FORMAT(created_at, '%Y-%m') AS month,
                        DATE_FORMAT(created_at, '%b %Y') AS month_label,
                        COUNT(*) AS order_count,
                        COALESCE(SUM(total_amount), 0) AS monthly_revenue
                      FROM orders 
                      WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                      AND order_status != 'cancelled'
                      GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%b %Y')
                      ORDER BY month ASC";
$monthlyTrendResult = $conn->query($monthlyTrendQuery);
$monthlyTrend = [];
while ($row = $monthlyTrendResult->fetch_assoc()) {
    $monthlyTrend[] = $row;
}

// ------------------------------------------------------------
// 7. TOP SELLING PRODUCTS
// ------------------------------------------------------------
$topProductsQuery = "SELECT 
                        p.id,
                        p.name,
                        p.sku,
                        COUNT(oi.id) AS times_ordered,
                        SUM(oi.quantity) AS total_quantity_sold,
                        COALESCE(SUM(oi.subtotal), 0) AS total_revenue,
                        (SELECT pi.image_path FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS image_path,
                        c.name AS category_name
                     FROM order_items oi
                     INNER JOIN orders o ON oi.order_id = o.id
                     INNER JOIN products p ON oi.product_id = p.id
                     LEFT JOIN categories c ON p.category_id = c.id
                     WHERE o.$dateFilter
                     AND o.order_status != 'cancelled'
                     GROUP BY p.id, p.name, p.sku, c.name
                     ORDER BY total_revenue DESC
                     LIMIT 10";
$topProductsResult = $conn->query($topProductsQuery);
$topProducts = [];
while ($row = $topProductsResult->fetch_assoc()) {
    $topProducts[] = $row;
}

// ------------------------------------------------------------
// 8. TOP CATEGORIES
// ------------------------------------------------------------
$topCategoriesQuery = "SELECT 
                        c.id,
                        c.name,
                        c.slug,
                        COUNT(DISTINCT o.id) AS order_count,
                        SUM(oi.quantity) AS total_items_sold,
                        COALESCE(SUM(oi.subtotal), 0) AS total_revenue
                       FROM order_items oi
                       INNER JOIN orders o ON oi.order_id = o.id
                       INNER JOIN products p ON oi.product_id = p.id
                       INNER JOIN categories c ON p.category_id = c.id
                       WHERE o.$dateFilter
                       AND o.order_status != 'cancelled'
                       GROUP BY c.id, c.name, c.slug
                       ORDER BY total_revenue DESC
                       LIMIT 10";
$topCategoriesResult = $conn->query($topCategoriesQuery);
$topCategories = [];
while ($row = $topCategoriesResult->fetch_assoc()) {
    $topCategories[] = $row;
}

// ------------------------------------------------------------
// 9. CUSTOMER ANALYTICS
// ------------------------------------------------------------
$customerStatsQuery = "SELECT 
                        COUNT(DISTINCT user_id) AS total_customers,
                        COUNT(DISTINCT CASE WHEN o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN o.user_id END) AS active_customers_30d,
                        COUNT(DISTINCT CASE WHEN o.user_id NOT IN (SELECT DISTINCT user_id FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) THEN o.user_id END) AS inactive_customers
                       FROM orders o
                       WHERE o.order_status != 'cancelled'";
$customerStatsResult = $conn->query($customerStatsQuery);
$customerStats = $customerStatsResult->fetch_assoc();

// Top Customers
$topCustomersQuery = "SELECT 
                        u.id,
                        u.name,
                        u.email,
                        COUNT(o.id) AS order_count,
                        COALESCE(SUM(o.total_amount), 0) AS total_spent,
                        COALESCE(AVG(o.total_amount), 0) AS avg_order_value,
                        MAX(o.created_at) AS last_order_date
                      FROM orders o
                      INNER JOIN users u ON o.user_id = u.id
                      WHERE o.$dateFilter
                      AND o.order_status != 'cancelled'
                      GROUP BY u.id, u.name, u.email
                      ORDER BY total_spent DESC
                      LIMIT 10";
$topCustomersResult = $conn->query($topCustomersQuery);
$topCustomers = [];
while ($row = $topCustomersResult->fetch_assoc()) {
    $topCustomers[] = $row;
}

// ------------------------------------------------------------
// 10. VENDOR PERFORMANCE (Admin Only)
// ------------------------------------------------------------
$vendorPerformance = [];
if ($is_admin) {
    $vendorQuery = "SELECT 
                        v.id,
                        v.business_name,
                        v.is_express,
                        COUNT(DISTINCT p.id) AS total_products,
                        COUNT(DISTINCT o.id) AS total_orders,
                        COALESCE(SUM(o.total_amount), 0) AS total_revenue,
                        COALESCE(AVG(o.total_amount), 0) AS avg_order_value,
                        v.rating AS vendor_rating
                    FROM vendors v
                    LEFT JOIN products p ON v.id = p.vendor_id AND p.is_active = 1
                    LEFT JOIN orders o ON v.id = o.vendor_id AND o.$dateFilter AND o.order_status != 'cancelled'
                    WHERE v.status = 'active'
                    GROUP BY v.id, v.business_name, v.is_express, v.rating
                    ORDER BY total_revenue DESC
                    LIMIT 10";
    $vendorResult = $conn->query($vendorQuery);
    while ($row = $vendorResult->fetch_assoc()) {
        $vendorPerformance[] = $row;
    }
}

// ------------------------------------------------------------
// 11. INVENTORY REPORT
// ------------------------------------------------------------
$inventorySummaryQuery = "SELECT 
                            COUNT(DISTINCT p.id) AS total_products,
                            COALESCE(SUM(pi.stock_quantity), 0) AS total_stock,
                            COALESCE(SUM(pi.reserved_quantity), 0) AS total_reserved,
                            COUNT(DISTINCT CASE WHEN pi.stock_quantity = 0 THEN p.id END) AS out_of_stock,
                            COUNT(DISTINCT CASE WHEN pi.stock_quantity > 0 AND pi.stock_quantity <= pi.low_stock_threshold THEN p.id END) AS low_stock
                          FROM products p
                          LEFT JOIN product_pricing_tiers ppt ON p.id = ppt.product_id AND ppt.is_active = 1
                          LEFT JOIN product_inventory pi ON ppt.id = pi.pricing_tier_id
                          WHERE p.is_active = 1";
$inventorySummaryResult = $conn->query($inventorySummaryQuery);
$inventorySummary = $inventorySummaryResult->fetch_assoc();

// Low Stock Products
$lowStockQuery = "SELECT 
                    p.id,
                    p.name,
                    p.sku,
                    ppt.tier_name,
                    pi.stock_quantity,
                    pi.reserved_quantity,
                    (pi.stock_quantity - pi.reserved_quantity) AS available_stock,
                    pi.low_stock_threshold,
                    v.business_name AS vendor_name
                  FROM product_inventory pi
                  INNER JOIN product_pricing_tiers ppt ON pi.pricing_tier_id = ppt.id
                  INNER JOIN products p ON ppt.product_id = p.id
                  LEFT JOIN vendors v ON p.vendor_id = v.id
                  WHERE pi.stock_quantity > 0 
                  AND pi.stock_quantity <= pi.low_stock_threshold
                  AND p.is_active = 1
                  ORDER BY available_stock ASC
                  LIMIT 10";
$lowStockResult = $conn->query($lowStockQuery);
$lowStockProducts = [];
while ($row = $lowStockResult->fetch_assoc()) {
    $lowStockProducts[] = $row;
}

// ------------------------------------------------------------
// 12. RECENT ORDERS (Quick View)
// ------------------------------------------------------------
$recentOrdersQuery = "SELECT 
                        o.id,
                        o.order_number,
                        o.total_amount,
                        o.order_status,
                        o.payment_status,
                        o.payment_method,
                        o.created_at,
                        u.name AS customer_name,
                        v.business_name AS vendor_name,
                        (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS item_count
                      FROM orders o
                      LEFT JOIN users u ON o.user_id = u.id
                      LEFT JOIN vendors v ON o.vendor_id = v.id
                      ORDER BY o.created_at DESC
                      LIMIT 10";
$recentOrdersResult = $conn->query($recentOrdersQuery);
$recentOrders = [];
while ($row = $recentOrdersResult->fetch_assoc()) {
    $recentOrders[] = $row;
}

// ------------------------------------------------------------
// 13. HELPER FUNCTIONS
// ------------------------------------------------------------
function formatCurrency($amount) {
    return 'GH₵ ' . number_format((float)$amount, 2);
}

function formatNumber($number) {
    if ($number >= 1000000) {
        return round($number / 1000000, 1) . 'M';
    } elseif ($number >= 1000) {
        return round($number / 1000, 1) . 'K';
    }
    return number_format($number);
}

function getStatusBadge($status) {
    $badges = [
        'pending' => ['class' => 'badge-warning', 'icon' => 'fa-clock'],
        'confirmed' => ['class' => 'badge-info', 'icon' => 'fa-check-circle'],
        'processing' => ['class' => 'badge-primary', 'icon' => 'fa-cogs'],
        'packed' => ['class' => 'badge-purple', 'icon' => 'fa-box'],
        'shipped' => ['class' => 'badge-cyan', 'icon' => 'fa-truck'],
        'delivered' => ['class' => 'badge-success', 'icon' => 'fa-check-double'],
        'cancelled' => ['class' => 'badge-danger', 'icon' => 'fa-ban'],
        'refunded' => ['class' => 'badge-secondary', 'icon' => 'fa-undo-alt']
    ];
    $badge = $badges[strtolower($status)] ?? ['class' => 'badge-secondary', 'icon' => 'fa-question'];
    return '<span class="badge ' . $badge['class'] . '"><i class="fas ' . $badge['icon'] . '"></i> ' . ucfirst($status) . '</span>';
}

function getPaymentBadge($status) {
    $badges = [
        'paid' => 'badge-success',
        'unpaid' => 'badge-warning',
        'partially_paid' => 'badge-info',
        'refunded' => 'badge-secondary'
    ];
    $class = $badges[strtolower($status)] ?? 'badge-secondary';
    return '<span class="badge ' . $class . '">' . ucfirst(str_replace('_', ' ', $status)) . '</span>';
}

function formatDate($date) {
    return date('M d, Y h:i A', strtotime($date));
}

function formatDateShort($date) {
    return date('M d', strtotime($date));
}

// ------------------------------------------------------------
// 14. PAGE METADATA
// ------------------------------------------------------------
$page_title = 'Reports & Analytics - Bless Mall Admin';
$current_page = 'reports.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0B1E3D">
    
    <title><?php echo $page_title; ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <style>
        :root {
            --admin-bg: #0F172A;
            --admin-bg-light: #1E293B;
            --admin-surface: #1E293B;
            --admin-surface-hover: #273548;
            --admin-border: #334155;
            --admin-border-light: #475569;
            --admin-primary: #3B82F6;
            --admin-primary-dark: #2563EB;
            --admin-primary-glow: rgba(59, 130, 246, 0.15);
            --admin-success: #10B981;
            --admin-success-bg: rgba(16, 185, 129, 0.1);
            --admin-warning: #F59E0B;
            --admin-warning-bg: rgba(245, 158, 11, 0.1);
            --admin-danger: #EF4444;
            --admin-danger-bg: rgba(239, 68, 68, 0.1);
            --admin-info: #06B6D4;
            --admin-purple: #8B5CF6;
            --admin-cyan: #06B6D4;
            --text-primary: #F1F5F9;
            --text-secondary: #94A3B8;
            --text-muted: #64748B;
            --white: #FFFFFF;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.4);
            --shadow-lg: 0 8px 32px rgba(0,0,0,0.5);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --radius-full: 9999px;
            --transition-fast: 0.15s cubic-bezier(0.4, 0, 0.2, 1);
            --transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
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
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--admin-bg); }
        ::-webkit-scrollbar-thumb { background: var(--admin-border-light); border-radius: 3px; }

        /* Page Header */
        .page-header {
            margin-bottom: 1.5rem;
        }
        .page-header h1 {
            font-family: var(--font-heading);
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 0.25rem;
        }
        .page-header h1 i { color: var(--admin-primary); }
        .page-header p {
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        /* Date Range Filter */
        .filter-bar {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-lg);
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .filter-tabs {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }
        .filter-tab {
            padding: 0.45rem 1rem;
            border: 1px solid transparent;
            border-radius: var(--radius-full);
            background: transparent;
            color: var(--text-secondary);
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition-fast);
            text-decoration: none;
            white-space: nowrap;
        }
        .filter-tab:hover {
            background: var(--admin-surface-hover);
            color: var(--text-primary);
        }
        .filter-tab.active {
            background: var(--admin-primary);
            color: white;
            border-color: var(--admin-primary);
        }
        .custom-date-form {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .custom-date-form input {
            background: var(--admin-bg);
            border: 1px solid var(--admin-border);
            color: var(--text-primary);
            padding: 0.45rem 0.75rem;
            border-radius: var(--radius-sm);
            font-family: var(--font-body);
            font-size: 0.8rem;
        }
        .custom-date-form input:focus {
            outline: none;
            border-color: var(--admin-primary);
        }
        .btn-apply {
            padding: 0.45rem 1rem;
            background: var(--admin-primary);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 600;
            font-size: 0.8rem;
            transition: var(--transition-fast);
        }
        .btn-apply:hover { background: var(--admin-primary-dark); }
        .btn-export {
            padding: 0.45rem 1rem;
            background: var(--admin-success);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 600;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            transition: var(--transition-fast);
        }
        .btn-export:hover { opacity: 0.9; }

        /* Stats Cards Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-card {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        .stat-card:hover {
            border-color: var(--admin-primary);
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.08);
        }
        .stat-card .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            margin-bottom: 0.75rem;
        }
        .stat-icon.blue { background: rgba(59, 130, 246, 0.15); color: var(--admin-primary); }
        .stat-icon.green { background: var(--admin-success-bg); color: var(--admin-success); }
        .stat-icon.warning { background: var(--admin-warning-bg); color: var(--admin-warning); }
        .stat-icon.purple { background: rgba(139, 92, 246, 0.15); color: var(--admin-purple); }
        .stat-icon.cyan { background: rgba(6, 182, 212, 0.15); color: var(--admin-cyan); }
        .stat-icon.danger { background: var(--admin-danger-bg); color: var(--admin-danger); }
        .stat-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 0.25rem;
        }
        .stat-value {
            font-family: var(--font-heading);
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
        }
        .stat-subtext {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
        .stat-trend {
            font-size: 0.7rem;
            font-weight: 600;
            margin-top: 0.25rem;
        }
        .stat-trend.up { color: var(--admin-success); }
        .stat-trend.down { color: var(--admin-danger); }

        /* Chart Cards */
        .chart-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .chart-card {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
        }
        .chart-card h3 {
            font-family: var(--font-heading);
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .chart-card h3 i { color: var(--admin-primary); font-size: 0.9rem; }
        .chart-container {
            position: relative;
            width: 100%;
            height: 300px;
        }
        .chart-container canvas {
            width: 100% !important;
            height: 100% !important;
        }

        /* Data Tables */
        .table-card {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .table-card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--admin-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .table-card-header h3 {
            font-family: var(--font-heading);
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .table-card-header h3 i { color: var(--admin-primary); }
        .table-card-header .badge-count {
            background: var(--admin-primary-glow);
            color: var(--admin-primary);
            padding: 0.2rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .admin-table {
            width: 100%;
            border-collapse: collapse;
        }
        .admin-table th {
            text-align: left;
            padding: 0.75rem 1rem;
            background: rgba(15, 23, 42, 0.5);
            color: var(--text-muted);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
            white-space: nowrap;
            border-bottom: 1px solid var(--admin-border);
        }
        .admin-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--admin-border);
            font-size: 0.85rem;
            color: var(--text-secondary);
            white-space: nowrap;
        }
        .admin-table tr:last-child td { border-bottom: none; }
        .admin-table tr:hover td { background: rgba(255,255,255,0.02); }
        .admin-table .text-success { color: var(--admin-success); }
        .admin-table .text-warning { color: var(--admin-warning); }
        .admin-table .text-danger { color: var(--admin-danger); }
        .admin-table .text-primary { color: var(--admin-primary); }
        .admin-table .fw-bold { font-weight: 600; color: var(--text-primary); }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.2rem 0.65rem;
            border-radius: var(--radius-full);
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .badge-success { background: var(--admin-success-bg); color: var(--admin-success); }
        .badge-warning { background: var(--admin-warning-bg); color: var(--admin-warning); }
        .badge-danger { background: var(--admin-danger-bg); color: var(--admin-danger); }
        .badge-info { background: rgba(6, 182, 212, 0.1); color: var(--admin-info); }
        .badge-primary { background: var(--admin-primary-glow); color: var(--admin-primary); }
        .badge-purple { background: rgba(139, 92, 246, 0.15); color: var(--admin-purple); }
        .badge-cyan { background: rgba(6, 182, 212, 0.15); color: var(--admin-cyan); }
        .badge-secondary { background: rgba(100, 116, 139, 0.15); color: #94A3B8; }

        /* Product image thumbnail */
        .product-thumb {
            width: 32px;
            height: 32px;
            border-radius: var(--radius-sm);
            object-fit: cover;
            background: var(--admin-bg);
        }
        .product-info-cell {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .product-info-cell .details {
            display: flex;
            flex-direction: column;
        }
        .product-info-cell .details .name {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.85rem;
        }
        .product-info-cell .details .sku {
            font-size: 0.7rem;
            color: var(--text-muted);
            font-family: var(--font-mono);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
        }
        .empty-state i {
            font-size: 3rem;
            color: var(--text-muted);
            opacity: 0.5;
            margin-bottom: 1rem;
        }
        .empty-state p { color: var(--text-muted); font-size: 0.9rem; }

        /* Grid layouts */
        .grid-2-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .chart-grid { grid-template-columns: 1fr; }
            .grid-2-col { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-tabs { justify-content: center; }
            .custom-date-form { justify-content: center; }
            .admin-table { font-size: 0.75rem; }
            .admin-table th, .admin-table td { padding: 0.5rem 0.75rem; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }

        /* Print styles */
        @media print {
            body { background: white; color: black; }
            .admin-topbar, .admin-sidebar, .filter-bar, .btn-export { display: none; }
            .chart-card, .table-card, .stat-card { 
                background: white; 
                border: 1px solid #ddd; 
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/header.php'; ?>

<!-- Breadcrumb -->
<div class="admin-breadcrumb">
    <a href="index.php"><i class="fas fa-home"></i> Dashboard</a>
    <span class="separator"><i class="fas fa-chevron-right"></i></span>
    <span class="current">Reports & Analytics</span>
</div>

<!-- Page Header -->
<div class="page-header">
    <h1><i class="fas fa-chart-pie"></i> Reports & Analytics</h1>
    <p><?php echo $dateLabel; ?> overview · Updated <?php echo date('M d, Y h:i A'); ?></p>
</div>

<!-- Date Range Filter -->
<div class="filter-bar">
    <div class="filter-tabs">
        <a href="?range=today" class="filter-tab <?php echo $dateRange === 'today' ? 'active' : ''; ?>">Today</a>
        <a href="?range=yesterday" class="filter-tab <?php echo $dateRange === 'yesterday' ? 'active' : ''; ?>">Yesterday</a>
        <a href="?range=7days" class="filter-tab <?php echo $dateRange === '7days' ? 'active' : ''; ?>">7 Days</a>
        <a href="?range=30days" class="filter-tab <?php echo $dateRange === '30days' ? 'active' : ''; ?>">30 Days</a>
        <a href="?range=90days" class="filter-tab <?php echo $dateRange === '90days' ? 'active' : ''; ?>">90 Days</a>
        <a href="?range=this_month" class="filter-tab <?php echo $dateRange === 'this_month' ? 'active' : ''; ?>">This Month</a>
        <a href="?range=last_month" class="filter-tab <?php echo $dateRange === 'last_month' ? 'active' : ''; ?>">Last Month</a>
        <a href="?range=this_year" class="filter-tab <?php echo $dateRange === 'this_year' ? 'active' : ''; ?>">This Year</a>
    </div>
    <form method="GET" class="custom-date-form">
        <input type="hidden" name="range" value="custom">
        <input type="date" name="start_date" value="<?php echo $customStart; ?>" placeholder="Start Date" style="color-scheme: dark;">
        <span style="color: var(--text-muted);">to</span>
        <input type="date" name="end_date" value="<?php echo $customEnd; ?>" placeholder="End Date" style="color-scheme: dark;">
        <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Apply</button>
    </form>
    <a href="#" class="btn-export" onclick="window.print(); return false;">
        <i class="fas fa-download"></i> Export Report
    </a>
</div>

<!-- Key Metrics Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-cedi-sign"></i></div>
        <div class="stat-label">Total Revenue</div>
        <div class="stat-value"><?php echo formatCurrency($revenueData['total_revenue']); ?></div>
        <div class="stat-trend up"><i class="fas fa-arrow-up"></i> <?php echo $dateLabel; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-shopping-cart"></i></div>
        <div class="stat-label">Total Orders</div>
        <div class="stat-value"><?php echo formatNumber($revenueData['total_orders']); ?></div>
        <div class="stat-subtext"><?php echo $dateLabel; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-receipt"></i></div>
        <div class="stat-label">Avg Order Value</div>
        <div class="stat-value"><?php echo formatCurrency($revenueData['avg_order_value']); ?></div>
        <div class="stat-subtext">Per order</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon cyan"><i class="fas fa-truck"></i></div>
        <div class="stat-label">Delivery Fees</div>
        <div class="stat-value"><?php echo formatCurrency($revenueData['total_delivery_fees']); ?></div>
        <div class="stat-subtext">Total collected</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><i class="fas fa-tags"></i></div>
        <div class="stat-label">Discounts Given</div>
        <div class="stat-value"><?php echo formatCurrency($revenueData['total_discounts']); ?></div>
        <div class="stat-subtext">Total discounts</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon danger"><i class="fas fa-clock"></i></div>
        <div class="stat-label">Pending Orders</div>
        <div class="stat-value">
            <?php 
            $pendingCount = 0;
            foreach ($orderStatuses as $os) {
                if (in_array($os['order_status'], ['pending', 'confirmed'])) {
                    $pendingCount += $os['count'];
                }
            }
            echo $pendingCount;
            ?>
        </div>
        <div class="stat-trend down">Requires action</div>
    </div>
</div>

<!-- Charts Row -->
<div class="chart-grid">
    <!-- Revenue Trend Chart -->
    <div class="chart-card">
        <h3><i class="fas fa-chart-line"></i> Daily Revenue Trend (Last 30 Days)</h3>
        <div class="chart-container">
            <canvas id="revenueTrendChart"></canvas>
        </div>
    </div>
    
    <!-- Order Status Distribution -->
    <div class="chart-card">
        <h3><i class="fas fa-chart-pie"></i> Order Status Distribution</h3>
        <div class="chart-container">
            <canvas id="orderStatusChart"></canvas>
        </div>
    </div>
</div>

<div class="grid-2-col">
    <!-- Payment Methods Chart -->
    <div class="chart-card">
        <h3><i class="fas fa-credit-card"></i> Revenue by Payment Method</h3>
        <div class="chart-container">
            <canvas id="paymentMethodChart"></canvas>
        </div>
    </div>
    
    <!-- Monthly Revenue Trend -->
    <div class="chart-card">
        <h3><i class="fas fa-chart-bar"></i> Monthly Revenue (Last 12 Months)</h3>
        <div class="chart-container">
            <canvas id="monthlyRevenueChart"></canvas>
        </div>
    </div>
</div>

<!-- Order Status Breakdown Table -->
<div class="table-card">
    <div class="table-card-header">
        <h3><i class="fas fa-clipboard-list"></i> Order Status Breakdown</h3>
        <span class="badge-count"><?php echo count($orderStatuses); ?> Statuses</span>
    </div>
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Orders</th>
                    <th>Total Value</th>
                    <th>% of Total</th>
                    <th>Progress</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $totalOrderCount = $revenueData['total_orders'] > 0 ? $revenueData['total_orders'] : 1;
                foreach ($orderStatuses as $os): 
                    $percentage = round(($os['count'] / $totalOrderCount) * 100, 1);
                    $barColor = match($os['order_status']) {
                        'delivered' => 'var(--admin-success)',
                        'cancelled', 'refunded' => 'var(--admin-danger)',
                        'shipped' => 'var(--admin-cyan)',
                        'processing', 'packed' => 'var(--admin-purple)',
                        'confirmed' => 'var(--admin-primary)',
                        default => 'var(--admin-warning)'
                    };
                ?>
                <tr>
                    <td><?php echo getStatusBadge($os['order_status']); ?></td>
                    <td class="fw-bold"><?php echo number_format($os['count']); ?></td>
                    <td class="text-primary fw-bold"><?php echo formatCurrency($os['total_value']); ?></td>
                    <td><?php echo $percentage; ?>%</td>
                    <td>
                        <div style="background: var(--admin-bg); border-radius: 4px; height: 6px; width: 100px; overflow: hidden;">
                            <div style="width: <?php echo $percentage; ?>%; height: 100%; background: <?php echo $barColor; ?>; border-radius: 4px;"></div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($orderStatuses)): ?>
                <tr><td colspan="5" class="empty-state"><i class="fas fa-inbox"></i><p>No order data for this period</p></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Top Selling Products -->
<div class="table-card">
    <div class="table-card-header">
        <h3><i class="fas fa-trophy"></i> Top Selling Products</h3>
        <span class="badge-count">Top 10</span>
    </div>
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Times Ordered</th>
                    <th>Qty Sold</th>
                    <th>Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $rank = 1;
                foreach ($topProducts as $tp): 
                    $imageSrc = !empty($tp['image_path']) ? BASE_URL . 'uploads/products/' . $tp['image_path'] : BASE_URL . 'assets/images/placeholders/product-placeholder.jpg';
                ?>
                <tr>
                    <td>
                        <?php if ($rank === 1): ?><i class="fas fa-medal" style="color: #FFD700;"></i>
                        <?php elseif ($rank === 2): ?><i class="fas fa-medal" style="color: #C0C0C0;"></i>
                        <?php elseif ($rank === 3): ?><i class="fas fa-medal" style="color: #CD7F32;"></i>
                        <?php else: echo $rank; endif; ?>
                    </td>
                    <td>
                        <div class="product-info-cell">
                            <img src="<?php echo $imageSrc; ?>" alt="" class="product-thumb" onerror="this.style.display='none'">
                            <div class="details">
                                <span class="name"><?php echo htmlspecialchars($tp['name']); ?></span>
                                <span class="sku">SKU: <?php echo htmlspecialchars($tp['sku'] ?? 'N/A'); ?></span>
                            </div>
                        </div>
                    </td>
                    <td><?php echo htmlspecialchars($tp['category_name'] ?? 'N/A'); ?></td>
                    <td class="fw-bold"><?php echo number_format($tp['times_ordered']); ?></td>
                    <td><?php echo number_format($tp['total_quantity_sold']); ?></td>
                    <td class="text-success fw-bold"><?php echo formatCurrency($tp['total_revenue']); ?></td>
                </tr>
                <?php 
                    $rank++;
                endforeach; 
                if (empty($topProducts)): 
                ?>
                <tr><td colspan="6" class="empty-state"><i class="fas fa-box-open"></i><p>No product sales data for this period</p></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Top Categories -->
<div class="table-card">
    <div class="table-card-header">
        <h3><i class="fas fa-th-large"></i> Top Performing Categories</h3>
        <span class="badge-count">Top 10</span>
    </div>
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Category</th>
                    <th>Orders</th>
                    <th>Items Sold</th>
                    <th>Total Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php $catRank = 1; foreach ($topCategories as $tc): ?>
                <tr>
                    <td><?php echo $catRank; ?></td>
                    <td class="fw-bold"><?php echo htmlspecialchars($tc['name']); ?></td>
                    <td><?php echo number_format($tc['order_count']); ?></td>
                    <td><?php echo number_format($tc['total_items_sold']); ?></td>
                    <td class="text-primary fw-bold"><?php echo formatCurrency($tc['total_revenue']); ?></td>
                </tr>
                <?php $catRank++; endforeach; ?>
                <?php if (empty($topCategories)): ?>
                <tr><td colspan="5" class="empty-state"><i class="fas fa-folder-open"></i><p>No category data for this period</p></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Recent Orders -->
<div class="table-card">
    <div class="table-card-header">
        <h3><i class="fas fa-receipt"></i> Recent Orders</h3>
        <span class="badge-count">Latest 10</span>
    </div>
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Customer</th>
                    <th>Vendor</th>
                    <th>Items</th>
                    <th>Total</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentOrders as $ro): ?>
                <tr>
                    <td class="fw-bold text-primary">#<?php echo htmlspecialchars($ro['order_number']); ?></td>
                    <td><?php echo htmlspecialchars($ro['customer_name'] ?? 'Guest'); ?></td>
                    <td><?php echo htmlspecialchars($ro['vendor_name'] ?? 'N/A'); ?></td>
                    <td><?php echo $ro['item_count']; ?></td>
                    <td class="fw-bold"><?php echo formatCurrency($ro['total_amount']); ?></td>
                    <td><?php echo getPaymentBadge($ro['payment_status']); ?></td>
                    <td><?php echo getStatusBadge($ro['order_status']); ?></td>
                    <td><?php echo formatDateShort($ro['created_at']); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recentOrders)): ?>
                <tr><td colspan="8" class="empty-state"><i class="fas fa-inbox"></i><p>No orders found</p></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($is_admin && !empty($vendorPerformance)): ?>
<!-- Vendor Performance (Admin Only) -->
<div class="table-card">
    <div class="table-card-header">
        <h3><i class="fas fa-store-alt"></i> Vendor Performance</h3>
        <span class="badge-count">Top 10</span>
    </div>
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Vendor</th>
                    <th>Products</th>
                    <th>Orders</th>
                    <th>Revenue</th>
                    <th>Avg Order</th>
                    <th>Rating</th>
                    <th>Express</th>
                </tr>
            </thead>
            <tbody>
                <?php $vRank = 1; foreach ($vendorPerformance as $vp): ?>
                <tr>
                    <td><?php echo $vRank; ?></td>
                    <td class="fw-bold"><?php echo htmlspecialchars($vp['business_name']); ?></td>
                    <td><?php echo number_format($vp['total_products']); ?></td>
                    <td><?php echo number_format($vp['total_orders']); ?></td>
                    <td class="text-success fw-bold"><?php echo formatCurrency($vp['total_revenue']); ?></td>
                    <td><?php echo formatCurrency($vp['avg_order_value']); ?></td>
                    <td>
                        <span class="badge badge-warning">
                            <i class="fas fa-star"></i> <?php echo number_format($vp['vendor_rating'], 1); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($vp['is_express']): ?>
                            <span class="badge badge-cyan"><i class="fas fa-bolt"></i> Express</span>
                        <?php else: ?>
                            <span style="color: var(--text-muted);">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php $vRank++; endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Inventory Summary -->
<div class="stats-grid" style="margin-top: 1.5rem;">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-boxes"></i></div>
        <div class="stat-label">Total Products</div>
        <div class="stat-value"><?php echo number_format($inventorySummary['total_products']); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-warehouse"></i></div>
        <div class="stat-label">Total Stock</div>
        <div class="stat-value"><?php echo number_format($inventorySummary['total_stock']); ?></div>
        <div class="stat-subtext"><?php echo number_format($inventorySummary['total_reserved']); ?> reserved</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon danger"><i class="fas fa-times-circle"></i></div>
        <div class="stat-label">Out of Stock</div>
        <div class="stat-value"><?php echo number_format($inventorySummary['out_of_stock']); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="stat-label">Low Stock</div>
        <div class="stat-value"><?php echo number_format($inventorySummary['low_stock']); ?></div>
    </div>
</div>

<!-- Low Stock Alerts -->
<?php if (!empty($lowStockProducts)): ?>
<div class="table-card">
    <div class="table-card-header">
        <h3><i class="fas fa-exclamation-triangle" style="color: var(--admin-warning);"></i> Low Stock Alerts</h3>
        <span class="badge-count" style="background: var(--admin-warning-bg); color: var(--admin-warning);"><?php echo count($lowStockProducts); ?> Products</span>
    </div>
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Tier</th>
                    <th>Stock</th>
                    <th>Reserved</th>
                    <th>Available</th>
                    <th>Threshold</th>
                    <th>Vendor</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lowStockProducts as $ls): ?>
                <tr>
                    <td class="fw-bold"><?php echo htmlspecialchars($ls['name']); ?></td>
                    <td style="font-family: var(--font-mono); font-size: 0.75rem;"><?php echo htmlspecialchars($ls['sku'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($ls['tier_name']); ?></td>
                    <td><?php echo $ls['stock_quantity']; ?></td>
                    <td><?php echo $ls['reserved_quantity']; ?></td>
                    <td class="text-warning fw-bold"><?php echo $ls['available_stock']; ?></td>
                    <td><?php echo $ls['low_stock_threshold']; ?></td>
                    <td><?php echo htmlspecialchars($ls['vendor_name'] ?? 'N/A'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Top Customers -->
<?php if (!empty($topCustomers)): ?>
<div class="table-card">
    <div class="table-card-header">
        <h3><i class="fas fa-users"></i> Top Customers</h3>
        <span class="badge-count">Top 10</span>
    </div>
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Customer</th>
                    <th>Email</th>
                    <th>Orders</th>
                    <th>Total Spent</th>
                    <th>Avg Order</th>
                    <th>Last Order</th>
                </tr>
            </thead>
            <tbody>
                <?php $cRank = 1; foreach ($topCustomers as $tc): ?>
                <tr>
                    <td><?php echo $cRank; ?></td>
                    <td class="fw-bold"><?php echo htmlspecialchars($tc['name']); ?></td>
                    <td><?php echo htmlspecialchars($tc['email'] ?? 'N/A'); ?></td>
                    <td><?php echo number_format($tc['order_count']); ?></td>
                    <td class="text-success fw-bold"><?php echo formatCurrency($tc['total_spent']); ?></td>
                    <td><?php echo formatCurrency($tc['avg_order_value']); ?></td>
                    <td><?php echo formatDateShort($tc['last_order_date']); ?></td>
                </tr>
                <?php $cRank++; endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>

<script>
    // ==================== REVENUE TREND CHART ====================
    const dailyTrendData = <?php echo json_encode($dailyTrend); ?>;
    const dailyLabels = dailyTrendData.map(d => {
        const date = new Date(d.sale_date);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    });
    const dailyRevenue = dailyTrendData.map(d => parseFloat(d.daily_revenue));
    const dailyOrders = dailyTrendData.map(d => parseInt(d.order_count));
    
    const revenueCtx = document.getElementById('revenueTrendChart').getContext('2d');
    new Chart(revenueCtx, {
        type: 'line',
        data: {
            labels: dailyLabels,
            datasets: [{
                label: 'Daily Revenue (GH₵)',
                data: dailyRevenue,
                borderColor: '#3B82F6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointBackgroundColor: '#3B82F6',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1E293B',
                    titleColor: '#F1F5F9',
                    bodyColor: '#94A3B8',
                    borderColor: '#334155',
                    borderWidth: 1,
                    callbacks: {
                        label: function(ctx) {
                            return 'GH₵ ' + ctx.parsed.y.toLocaleString('en-US', { minimumFractionDigits: 2 });
                        }
                    }
                }
            },
            scales: {
                x: { 
                    grid: { color: 'rgba(51, 65, 85, 0.3)' },
                    ticks: { color: '#64748B', maxTicksLimit: 10, font: { size: 10 } }
                },
                y: { 
                    grid: { color: 'rgba(51, 65, 85, 0.3)' },
                    ticks: { 
                        color: '#64748B',
                        callback: function(value) { return 'GH₵ ' + value.toLocaleString(); },
                        font: { size: 10 }
                    },
                    beginAtZero: true
                }
            }
        }
    });
    
    // ==================== ORDER STATUS PIE CHART ====================
    const orderStatusData = <?php echo json_encode($orderStatuses); ?>;
    const statusLabels = orderStatusData.map(d => d.order_status.charAt(0).toUpperCase() + d.order_status.slice(1));
    const statusCounts = orderStatusData.map(d => parseInt(d.count));
    const statusColors = [
        '#F59E0B', '#3B82F6', '#8B5CF6', '#EC4899', '#06B6D4', '#10B981', '#EF4444', '#64748B'
    ];
    
    const statusCtx = document.getElementById('orderStatusChart').getContext('2d');
    new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: statusLabels,
            datasets: [{
                data: statusCounts,
                backgroundColor: statusColors.slice(0, statusLabels.length),
                borderColor: '#1E293B',
                borderWidth: 2
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
                        padding: 15, 
                        font: { size: 11 },
                        usePointStyle: true,
                        pointStyleWidth: 8
                    }
                },
                tooltip: {
                    backgroundColor: '#1E293B',
                    titleColor: '#F1F5F9',
                    bodyColor: '#94A3B8',
                    borderColor: '#334155',
                    borderWidth: 1
                }
            }
        }
    });
    
    // ==================== PAYMENT METHOD CHART ====================
    const paymentData = <?php echo json_encode($paymentMethods); ?>;
    const paymentLabels = paymentData.map(d => d.payment_method.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' '));
    const paymentAmounts = paymentData.map(d => parseFloat(d.total_amount));
    const paymentColors = ['#3B82F6', '#10B981', '#F59E0B', '#8B5CF6', '#06B6D4'];
    
    const paymentCtx = document.getElementById('paymentMethodChart').getContext('2d');
    new Chart(paymentCtx, {
        type: 'bar',
        data: {
            labels: paymentLabels,
            datasets: [{
                label: 'Revenue by Payment Method',
                data: paymentAmounts,
                backgroundColor: paymentColors.slice(0, paymentLabels.length),
                borderColor: paymentColors.slice(0, paymentLabels.length),
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1E293B',
                    callbacks: {
                        label: function(ctx) {
                            return 'GH₵ ' + ctx.parsed.x.toLocaleString('en-US', { minimumFractionDigits: 2 });
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(51, 65, 85, 0.3)' },
                    ticks: { 
                        color: '#64748B',
                        callback: function(value) { return 'GH₵ ' + value.toLocaleString(); },
                        font: { size: 10 }
                    },
                    beginAtZero: true
                },
                y: {
                    grid: { display: false },
                    ticks: { color: '#94A3B8', font: { size: 11 } }
                }
            }
        }
    });
    
    // ==================== MONTHLY REVENUE CHART ====================
    const monthlyTrendData = <?php echo json_encode($monthlyTrend); ?>;
    const monthlyLabels = monthlyTrendData.map(d => d.month_label);
    const monthlyRevenue = monthlyTrendData.map(d => parseFloat(d.monthly_revenue));
    
    const monthlyCtx = document.getElementById('monthlyRevenueChart').getContext('2d');
    new Chart(monthlyCtx, {
        type: 'bar',
        data: {
            labels: monthlyLabels,
            datasets: [{
                label: 'Monthly Revenue (GH₵)',
                data: monthlyRevenue,
                backgroundColor: function(context) {
                    const chart = context.chart;
                    const {ctx, chartArea} = chart;
                    if (!chartArea) return 'rgba(59, 130, 246, 0.6)';
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    gradient.addColorStop(0, 'rgba(59, 130, 246, 0.8)');
                    gradient.addColorStop(1, 'rgba(59, 130, 246, 0.2)');
                    return gradient;
                },
                borderColor: '#3B82F6',
                borderWidth: 1,
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1E293B',
                    callbacks: {
                        label: function(ctx) {
                            return 'GH₵ ' + ctx.parsed.y.toLocaleString('en-US', { minimumFractionDigits: 2 });
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(51, 65, 85, 0.3)' },
                    ticks: { color: '#64748B', font: { size: 10 }, maxTicksLimit: 12 }
                },
                y: {
                    grid: { color: 'rgba(51, 65, 85, 0.3)' },
                    ticks: { 
                        color: '#64748B',
                        callback: function(value) { return 'GH₵ ' + value.toLocaleString(); },
                        font: { size: 10 }
                    },
                    beginAtZero: true
                }
            }
        }
    });
</script>

</body>
</html>
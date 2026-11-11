<?php
/**
 * track-order.php – Bless Mall Order Tracking
 * Track your orders in real-time
 * 
 * Track by Order Number or Tracking Reference
 */

// ------------------------------------------------------------
// 1. BOOTSTRAP: Load core system files
// ------------------------------------------------------------
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// ------------------------------------------------------------
// 2. DATABASE CONNECTION
// ------------------------------------------------------------
$conn = getConnection();

// ------------------------------------------------------------
// 3. GET TRACKING PARAMETERS
// ------------------------------------------------------------
$trackingRef = isset($_GET['ref']) ? trim($_GET['ref']) : '';
$orderNumber = isset($_GET['order']) ? trim($_GET['order']) : '';
$email = isset($_GET['email']) ? trim($_GET['email']) : '';

$trackingResult = null;
$error = null;
$orderDetails = null;

// ------------------------------------------------------------
// 4. PROCESS TRACKING REQUEST
// ------------------------------------------------------------
if (!Auth::isLoggedIn() && !empty($trackingRef)) {
    $error = 'Log in to view an order by tracking reference, or use your order number and account email.';
} elseif (!Auth::isLoggedIn() && (empty($orderNumber) || empty($email))) {
    $error = 'Enter your order number and the email address used for the order.';
} elseif (!empty($trackingRef) || !empty($orderNumber)) {
    $ownerCondition = Auth::isLoggedIn()
        ? ' AND o.user_id = ' . (int)$_SESSION['user_id']
        : '';
    
    // Build query based on input type
    if (!empty($trackingRef)) {
        // Track by delivery tracking reference
        $refEscaped = $conn->real_escape_string($trackingRef);
        $query = "SELECT 
                    o.id,
                    o.order_number,
                    o.subtotal,
                    o.delivery_fee,
                    o.discount_amount,
                    o.total_amount,
                    o.order_status,
                    o.payment_status,
                    o.payment_method,
                    o.delivery_type,
                    o.notes,
                    o.created_at,
                    o.updated_at,
                    v.id AS vendor_id,
                    v.business_name AS vendor_name,
                    v.logo AS vendor_logo,
                    v.contact_phone AS vendor_phone,
                    v.is_express,
                    d.id AS delivery_id,
                    d.tracking_ref,
                    d.delivery_partner,
                    d.rider_phone,
                    d.estimated_delivery_date,
                    d.actual_delivery_date,
                    d.status AS delivery_status,
                    d.created_at AS delivery_created_at,
                    a.address_line1,
                    a.address_line2,
                    a.city,
                    a.region,
                    a.phone AS delivery_phone,
                    (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS item_count,
                    (SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = o.id) AS total_items
                FROM deliveries d
                INNER JOIN orders o ON d.order_id = o.id
                LEFT JOIN vendors v ON o.vendor_id = v.id
                LEFT JOIN addresses a ON d.address_id = a.id
                WHERE d.tracking_ref = '$refEscaped' $ownerCondition
                LIMIT 1";
    } else {
        // Track by order number for the owner, or order number plus email for guests.
        $orderEscaped = $conn->real_escape_string($orderNumber);
        $identityCondition = Auth::isLoggedIn()
            ? ' AND o.user_id = ' . (int)$_SESSION['user_id']
            : " AND u.email = '" . $conn->real_escape_string($email) . "'";
        
        $query = "SELECT 
                    o.id,
                    o.order_number,
                    o.subtotal,
                    o.delivery_fee,
                    o.discount_amount,
                    o.total_amount,
                    o.order_status,
                    o.payment_status,
                    o.payment_method,
                    o.delivery_type,
                    o.notes,
                    o.created_at,
                    o.updated_at,
                    v.id AS vendor_id,
                    v.business_name AS vendor_name,
                    v.logo AS vendor_logo,
                    v.contact_phone AS vendor_phone,
                    v.is_express,
                    d.id AS delivery_id,
                    d.tracking_ref,
                    d.delivery_partner,
                    d.rider_phone,
                    d.estimated_delivery_date,
                    d.actual_delivery_date,
                    d.status AS delivery_status,
                    d.created_at AS delivery_created_at,
                    a.address_line1,
                    a.address_line2,
                    a.city,
                    a.region,
                    a.phone AS delivery_phone,
                    u.name AS customer_name,
                    u.email AS customer_email,
                    (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS item_count,
                    (SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = o.id) AS total_items
                FROM orders o
                LEFT JOIN vendors v ON o.vendor_id = v.id
                LEFT JOIN deliveries d ON o.id = d.order_id
                LEFT JOIN addresses a ON d.address_id = a.id
                LEFT JOIN users u ON o.user_id = u.id
                WHERE o.order_number = '$orderEscaped' $identityCondition
                LIMIT 1";
    }
    
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $orderDetails = $result->fetch_assoc();
        $trackingResult = $orderDetails;
        
        // Get order items
        $itemsQuery = "SELECT 
                        oi.id,
                        oi.quantity,
                        oi.unit_price,
                        oi.subtotal,
                        p.id AS product_id,
                        p.name AS product_name,
                        p.slug,
                        (SELECT pi.image_path FROM product_images pi 
                         WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS product_image
                      FROM order_items oi
                      INNER JOIN products p ON oi.product_id = p.id
                      WHERE oi.order_id = " . (int)$orderDetails['id'] . "
                      ORDER BY oi.id ASC";
        
        $itemsResult = $conn->query($itemsQuery);
        $orderItems = [];
        if ($itemsResult && $itemsResult->num_rows > 0) {
            while ($item = $itemsResult->fetch_assoc()) {
                $orderItems[] = $item;
            }
        }
        $orderDetails['items'] = $orderItems;
        
        // Get status history
        $historyQuery = "SELECT 
                            old_status,
                            new_status,
                            notes,
                            created_at,
                            (SELECT name FROM users WHERE id = changed_by) AS changed_by_name
                         FROM order_status_history
                         WHERE order_id = " . (int)$orderDetails['id'] . "
                         ORDER BY created_at ASC";
        $historyResult = $conn->query($historyQuery);
        $statusHistory = [];
        if ($historyResult && $historyResult->num_rows > 0) {
            while ($history = $historyResult->fetch_assoc()) {
                $statusHistory[] = $history;
            }
        }
        $orderDetails['status_history'] = $statusHistory;
        
    } else {
        $error = "No order found with the provided information. Please check and try again.";
    }
}

// ------------------------------------------------------------
// 5. HELPER FUNCTIONS
// ------------------------------------------------------------
function getOrderStatusBadge($status) {
    $badges = [
        'pending' => ['class' => 'badge-pending', 'icon' => 'fa-clock', 'text' => 'Pending'],
        'confirmed' => ['class' => 'badge-confirmed', 'icon' => 'fa-check-circle', 'text' => 'Confirmed'],
        'processing' => ['class' => 'badge-processing', 'icon' => 'fa-cogs', 'text' => 'Processing'],
        'packed' => ['class' => 'badge-packed', 'icon' => 'fa-box', 'text' => 'Packed'],
        'shipped' => ['class' => 'badge-shipped', 'icon' => 'fa-shipping-fast', 'text' => 'Shipped'],
        'delivered' => ['class' => 'badge-delivered', 'icon' => 'fa-check-double', 'text' => 'Delivered'],
        'cancelled' => ['class' => 'badge-cancelled', 'icon' => 'fa-ban', 'text' => 'Cancelled'],
        'refunded' => ['class' => 'badge-refunded', 'icon' => 'fa-undo-alt', 'text' => 'Refunded']
    ];
    
    $status = strtolower($status);
    $badge = $badges[$status] ?? ['class' => 'badge-default', 'icon' => 'fa-question', 'text' => ucfirst($status)];
    
    return '<span class="status-badge ' . $badge['class'] . '"><i class="fas ' . $badge['icon'] . '"></i> ' . $badge['text'] . '</span>';
}

function getDeliveryStatusBadge($status) {
    $badges = [
        'pending' => ['class' => 'badge-pending', 'icon' => 'fa-clock', 'text' => 'Pending Assignment'],
        'assigned' => ['class' => 'badge-confirmed', 'icon' => 'fa-user-check', 'text' => 'Rider Assigned'],
        'in_transit' => ['class' => 'badge-shipped', 'icon' => 'fa-truck', 'text' => 'In Transit'],
        'delivered' => ['class' => 'badge-delivered', 'icon' => 'fa-check-double', 'text' => 'Delivered']
    ];
    
    $status = strtolower($status);
    $badge = $badges[$status] ?? ['class' => 'badge-default', 'icon' => 'fa-question', 'text' => ucfirst($status)];
    
    return '<span class="status-badge ' . $badge['class'] . '"><i class="fas ' . $badge['icon'] . '"></i> ' . $badge['text'] . '</span>';
}

function formatTrackingDate($date) {
    if (!$date) return 'N/A';
    return date('M d, Y h:i A', strtotime($date));
}

function getDeliveryProgressPercent($status) {
    $steps = ['pending' => 0, 'assigned' => 25, 'in_transit' => 50, 'delivered' => 100];
    return $steps[strtolower($status)] ?? 0;
}

function getOrderProgressPercent($status) {
    $steps = [
        'pending' => 0,
        'confirmed' => 20,
        'processing' => 40,
        'packed' => 60,
        'shipped' => 80,
        'delivered' => 100
    ];
    return $steps[strtolower($status)] ?? 0;
}

function formatPriceShort($price) {
    return 'GHS ' . number_format($price, 2);
}

// ------------------------------------------------------------
// 6. CART COUNT
// ------------------------------------------------------------
$cart_count = 0;
if (!empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_count += (int)($item['quantity'] ?? 0);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Track your Bless Mall order in real-time. Enter your order number or tracking reference to see live delivery status.">
    <meta name="robots" content="noindex, nofollow">
    
    <title>Track Order - Bless Mall</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- AOS Animation -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.css">
    
    <!-- Custom Styles -->
    <link rel="stylesheet" href="<?php echo CSS_URL; ?>header.css">
    <link rel="stylesheet" href="<?php echo CSS_URL; ?>footer.css">
    
    <style>
        :root {
            --primary-green: #0B6E3D;
            --primary-green-dark: #085229;
            --primary-green-light: #0D8B4E;
            --accent-green: #1FAF5B;
            --light-green: #E8F5EE;
            --lighter-green: #F4FAF7;
            --white: #FFFFFF;
            --off-white: #FAFBFA;
            --dark: #1A2E24;
            --dark-secondary: #2D3B35;
            --text-primary: #1A2E24;
            --text-secondary: #4A665C;
            --text-muted: #7C9A8E;
            --border-light: #E2EDE8;
            --border-medium: #C8DDD4;
            
            --status-pending: #F59E0B;
            --status-pending-bg: #FEF3C7;
            --status-confirmed: #3B82F6;
            --status-confirmed-bg: #DBEAFE;
            --status-processing: #8B5CF6;
            --status-processing-bg: #EDE9FE;
            --status-packed: #EC4899;
            --status-packed-bg: #FCE7F3;
            --status-shipped: #06B6D4;
            --status-shipped-bg: #CFFAFE;
            --status-delivered: #10B981;
            --status-delivered-bg: #D1FAE5;
            --status-cancelled: #EF4444;
            --status-cancelled-bg: #FEE2E2;
            
            --shadow-xs: 0 1px 3px rgba(0,0,0,0.04);
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.06);
            --shadow-md: 0 8px 24px rgba(0,0,0,0.08);
            --shadow-lg: 0 16px 48px rgba(0,0,0,0.10);
            
            --radius-xs: 6px;
            --radius-sm: 10px;
            --radius-md: 16px;
            --radius-lg: 24px;
            --radius-full: 9999px;
            
            --transition-fast: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            --transition: 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--text-primary);
            line-height: 1.6;
            overflow-x: hidden;
            background: var(--off-white);
            -webkit-font-smoothing: antialiased;
        }

        ::-webkit-scrollbar { width: 10px; }
        ::-webkit-scrollbar-track { background: var(--light-green); }
        ::-webkit-scrollbar-thumb { background: var(--primary-green); border-radius: 10px; }
        ::selection { background: var(--primary-green); color: white; }

        /* Top Bar */
        .top-bar {
            background: var(--primary-green-dark);
            color: rgba(255,255,255,0.9);
            font-size: 0.8rem;
            padding: 8px 0;
            z-index: 1001;
            position: relative;
        }
        .top-bar-inner {
            max-width: 1300px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .top-bar-left { display: flex; align-items: center; gap: 20px; }
        .top-bar-left span { display: flex; align-items: center; gap: 6px; }
        .top-bar-left i { color: var(--accent-green); }
        .top-bar-right { display: flex; align-items: center; gap: 16px; }
        .top-bar-right a {
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            transition: color 0.2s;
            font-weight: 500;
        }
        .top-bar-right a:hover { color: var(--accent-green); }

        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--primary-green-light) 100%);
            padding: 2.5rem 2rem;
            text-align: center;
            color: white;
        }
        .page-header h1 {
            font-family: 'Playfair Display', serif;
            font-size: clamp(1.8rem, 4vw, 2.5rem);
            font-weight: 800;
            margin-bottom: 0.5rem;
        }
        .page-header p {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        /* Breadcrumb */
        .breadcrumb {
            max-width: 1300px;
            margin: 1rem auto;
            padding: 0 2rem;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .breadcrumb a {
            color: var(--primary-green);
            text-decoration: none;
            font-weight: 500;
        }
        .breadcrumb a:hover { color: var(--primary-green-dark); }
        .breadcrumb .current { color: var(--primary-green); font-weight: 600; }

        /* Main Container */
        .tracking-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Search Form */
        .search-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
        }
        .search-title {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .search-title h2 {
            font-size: 1.3rem;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }
        .search-title p {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        .tracking-tabs {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid var(--border-light);
            padding-bottom: 0.5rem;
        }
        .tab-btn {
            padding: 0.6rem 1.5rem;
            background: none;
            border: none;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            transition: var(--transition-fast);
            border-radius: var(--radius-full);
        }
        .tab-btn.active {
            background: var(--light-green);
            color: var(--primary-green);
        }
        .tab-btn:hover:not(.active) {
            color: var(--primary-green);
        }
        .tracking-form {
            max-width: 500px;
            margin: 0 auto;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 0.4rem;
            color: var(--dark);
        }
        .form-group input {
            width: 100%;
            padding: 0.8rem 1rem;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            transition: var(--transition-fast);
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(11,110,61,0.1);
        }
        .btn-track {
            width: 100%;
            padding: 0.8rem;
            background: var(--primary-green);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition);
        }
        .btn-track:hover {
            background: var(--primary-green-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .info-text {
            text-align: center;
            margin-top: 1rem;
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        .info-text i {
            margin-right: 4px;
        }

        /* Error Message */
        .error-message {
            background: #FEE2E2;
            border: 1px solid #FCA5A5;
            color: #DC2626;
            padding: 1rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .error-message i {
            font-size: 1.2rem;
        }

        /* Tracking Result */
        .tracking-result {
            animation: fadeIn 0.5s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Order Summary Card */
        .order-summary-card {
            background: white;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .card-header {
            background: var(--off-white);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .card-header h3 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--dark);
        }
        .order-ref {
            font-family: monospace;
            font-size: 0.85rem;
            color: var(--primary-green);
            font-weight: 600;
        }

        /* Progress Section */
        .progress-section {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-light);
        }
        .progress-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .progress-title h4 {
            font-size: 0.9rem;
            color: var(--dark);
        }
        .progress-bar-container {
            background: var(--border-light);
            border-radius: var(--radius-full);
            height: 8px;
            overflow: hidden;
            margin: 1rem 0;
        }
        .progress-bar {
            background: var(--primary-green);
            height: 100%;
            border-radius: var(--radius-full);
            transition: width 0.5s ease;
            width: 0%;
        }
        .progress-steps {
            display: flex;
            justify-content: space-between;
            margin-top: 1rem;
            flex-wrap: wrap;
        }
        .step {
            text-align: center;
            flex: 1;
            min-width: 60px;
        }
        .step-icon {
            width: 36px;
            height: 36px;
            background: var(--off-white);
            border: 2px solid var(--border-light);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .step-icon.completed {
            background: var(--primary-green);
            border-color: var(--primary-green);
            color: white;
        }
        .step-icon.active {
            background: var(--primary-green);
            border-color: var(--primary-green);
            color: white;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(11,110,61,0.4); }
            50% { box-shadow: 0 0 0 8px rgba(11,110,61,0); }
        }
        .step-label {
            font-size: 0.7rem;
            color: var(--text-muted);
        }
        .step-label.active {
            color: var(--primary-green);
            font-weight: 600;
        }
        .step-date {
            font-size: 0.6rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }

        /* Order Details Grid */
        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-light);
        }
        .detail-item {
            display: flex;
            align-items: flex-start;
            gap: 0.8rem;
        }
        .detail-icon {
            width: 40px;
            height: 40px;
            background: var(--light-green);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-green);
            font-size: 1rem;
            flex-shrink: 0;
        }
        .detail-content {
            flex: 1;
        }
        .detail-label {
            font-size: 0.7rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.2rem;
        }
        .detail-value {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }
        .detail-value a {
            color: var(--primary-green);
            text-decoration: none;
        }
        .detail-value a:hover {
            text-decoration: underline;
        }

        /* Products List */
        .products-section {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-light);
        }
        .products-section h4 {
            font-size: 0.9rem;
            margin-bottom: 1rem;
            color: var(--dark);
        }
        .product-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.8rem 0;
            border-bottom: 1px solid var(--border-light);
        }
        .product-item:last-child {
            border-bottom: none;
        }
        .product-image {
            width: 60px;
            height: 60px;
            background: var(--off-white);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }
        .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .product-image i {
            font-size: 1.5rem;
            color: var(--text-muted);
            opacity: 0.5;
        }
        .product-info {
            flex: 1;
        }
        .product-name {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }
        .product-meta {
            font-size: 0.7rem;
            color: var(--text-muted);
        }
        .product-price {
            font-weight: 700;
            color: var(--primary-green);
            font-size: 0.85rem;
            white-space: nowrap;
        }

        /* Status History */
        .history-section {
            padding: 1.5rem;
        }
        .timeline {
            position: relative;
            padding-left: 2rem;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 7px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border-light);
        }
        .timeline-item {
            position: relative;
            padding-bottom: 1.5rem;
        }
        .timeline-item:last-child {
            padding-bottom: 0;
        }
        .timeline-dot {
            position: absolute;
            left: -2rem;
            top: 0;
            width: 16px;
            height: 16px;
            background: white;
            border: 2px solid var(--primary-green);
            border-radius: 50%;
        }
        .timeline-dot.completed {
            background: var(--primary-green);
        }
        .timeline-content {
            background: var(--off-white);
            padding: 0.8rem 1rem;
            border-radius: var(--radius-md);
        }
        .timeline-status {
            font-weight: 700;
            font-size: 0.85rem;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }
        .timeline-date {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }
        .timeline-notes {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: var(--radius-full);
            font-size: 0.7rem;
            font-weight: 600;
        }
        .badge-pending { background: var(--status-pending-bg); color: var(--status-pending); }
        .badge-confirmed { background: var(--status-confirmed-bg); color: var(--status-confirmed); }
        .badge-processing { background: var(--status-processing-bg); color: var(--status-processing); }
        .badge-packed { background: #FCE7F3; color: #EC4899; }
        .badge-shipped { background: var(--status-shipped-bg); color: var(--status-shipped); }
        .badge-delivered { background: var(--status-delivered-bg); color: var(--status-delivered); }
        .badge-cancelled { background: var(--status-cancelled-bg); color: var(--status-cancelled); }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            padding: 1.5rem;
            background: var(--off-white);
            border-top: 1px solid var(--border-light);
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0.7rem 1.5rem;
            border-radius: var(--radius-full);
            font-weight: 600;
            font-size: 0.85rem;
            text-decoration: none;
            transition: var(--transition);
        }
        .btn-primary {
            background: var(--primary-green);
            color: white;
        }
        .btn-primary:hover {
            background: var(--primary-green-dark);
            transform: translateY(-2px);
        }
        .btn-outline {
            background: white;
            color: var(--primary-green);
            border: 1px solid var(--primary-green);
        }
        .btn-outline:hover {
            background: var(--light-green);
            transform: translateY(-2px);
        }

        /* Help Section */
        .help-section {
            background: var(--lighter-green);
            border-radius: var(--radius-lg);
            padding: 2rem;
            text-align: center;
            margin-top: 2rem;
        }
        .help-section h3 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }
        .help-section p {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }
        .contact-links {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            flex-wrap: wrap;
        }
        .contact-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary-green);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .contact-link:hover {
            text-decoration: underline;
        }

        /* Toast */
        .toast-container {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .toast {
            background: var(--primary-green);
            color: white;
            padding: 12px 20px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            box-shadow: var(--shadow-lg);
            animation: slideInRight 0.4s ease;
            font-size: 0.85rem;
        }
        .toast.error { background: #E53935; }
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .tracking-container { padding: 1rem; }
            .search-card { padding: 1.5rem; }
            .details-grid { grid-template-columns: 1fr; }
            .progress-steps { gap: 0.5rem; }
            .step { min-width: 50px; }
            .step-icon { width: 30px; height: 30px; font-size: 0.7rem; }
            .step-label { font-size: 0.6rem; }
            .action-buttons { flex-direction: column; }
            .btn { justify-content: center; }
        }
        
        @media (max-width: 480px) {
            .card-header { flex-direction: column; text-align: center; }
            .product-item { flex-wrap: wrap; }
            .product-price { margin-left: auto; }
        }
    </style>
</head>
<body>

    <!-- Top Bar -->
    <div class="top-bar">
        <div class="top-bar-inner">
            <div class="top-bar-left">
                <span><i class="fas fa-phone-alt"></i> <?php echo SITE_PHONE; ?></span>
                <span><i class="fab fa-whatsapp"></i> <?php echo SITE_WHATSAPP; ?></span>
            </div>
            <div class="top-bar-right">
                <a href="track-order.php"><i class="fas fa-map-marker-alt"></i> Track Order</a>
                <a href="express.php"><i class="fas fa-bolt"></i> Express</a>
                <span><i class="fas fa-map-marker-alt"></i> 🇬🇭 Ghana</span>
            </div>
        </div>
    </div>

    <!-- Header -->
    <?php include __DIR__ . '/header.php'; ?>

    <!-- Main Content -->
    <main>
        <!-- Page Header -->
        <section class="page-header" data-aos="fade-in">
            <h1><i class="fas fa-map-marker-alt"></i> Track Your Order</h1>
            <p>Real-time order tracking and delivery updates</p>
        </section>

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="index.php"><i class="fas fa-home"></i> Home</a>
            <i class="fas fa-chevron-right" style="font-size:0.6rem;"></i>
            <span class="current">Track Order</span>
        </div>

        <!-- Tracking Container -->
        <div class="tracking-container">
            
            <!-- Search Card -->
            <div class="search-card" data-aos="fade-up">
                <div class="search-title">
                    <h2>Track Your Package</h2>
                    <p>Enter your order number or tracking reference to see real-time status</p>
                </div>
                
                <div class="tracking-tabs">
                    <button class="tab-btn active" onclick="switchTab('order')">Order Number</button>
                    <button class="tab-btn" onclick="switchTab('tracking')">Tracking Reference</button>
                </div>
                
                <!-- Order Number Form -->
                <form method="get" action="track-order.php" id="orderForm" class="tracking-form">
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Order Number</label>
                        <input type="text" name="order" placeholder="e.g., BM-2024-12345" value="<?php echo htmlspecialchars($orderNumber); ?>">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email Address (Optional - for verification)</label>
                        <input type="email" name="email" placeholder="Enter your email address" value="<?php echo htmlspecialchars($email); ?>">
                    </div>
                    <button type="submit" class="btn-track"><i class="fas fa-search"></i> Track Order</button>
                    <div class="info-text">
                        <i class="fas fa-info-circle"></i> Enter the order number you received in your confirmation email
                    </div>
                </form>
                
                <!-- Tracking Reference Form (hidden by default) -->
                <form method="get" action="track-order.php" id="trackingForm" class="tracking-form" style="display: none;">
                    <div class="form-group">
                        <label><i class="fas fa-barcode"></i> Tracking Reference</label>
                        <input type="text" name="ref" placeholder="e.g., BLESS-TRK-123456" value="<?php echo htmlspecialchars($trackingRef); ?>">
                    </div>
                    <button type="submit" class="btn-track"><i class="fas fa-search"></i> Track Package</button>
                    <div class="info-text">
                        <i class="fas fa-info-circle"></i> Enter the tracking number sent via SMS/Email when your order shipped
                    </div>
                </form>
            </div>

            <!-- Error Message -->
            <?php if ($error): ?>
            <div class="error-message" data-aos="fade-up">
                <i class="fas fa-exclamation-triangle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
            <?php endif; ?>

            <!-- Tracking Result -->
            <?php if ($orderDetails): ?>
            <div class="tracking-result">
                <!-- Order Summary Card -->
                <div class="order-summary-card" data-aos="fade-up">
                    <div class="card-header">
                        <div>
                            <h3><i class="fas fa-shopping-bag"></i> Order Summary</h3>
                            <div class="order-ref">#<?php echo htmlspecialchars($orderDetails['order_number']); ?></div>
                        </div>
                        <div>
                            <?php echo getOrderStatusBadge($orderDetails['order_status']); ?>
                            <?php if ($orderDetails['is_express']): ?>
                                <span class="status-badge" style="background: var(--primary-green); color: white; margin-left: 0.5rem;">
                                    <i class="fas fa-bolt"></i> Express
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Progress Section -->
                    <div class="progress-section">
                        <div class="progress-title">
                            <h4><i class="fas fa-tasks"></i> Order Progress</h4>
                            <span style="font-size: 0.8rem; font-weight: 600;"><?php echo getOrderProgressPercent($orderDetails['order_status']); ?>% Complete</span>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-bar" style="width: <?php echo getOrderProgressPercent($orderDetails['order_status']); ?>%;"></div>
                        </div>
                        <div class="progress-steps">
                            <?php
                            $steps = [
                                'pending' => ['icon' => 'fa-clock', 'label' => 'Order Placed'],
                                'confirmed' => ['icon' => 'fa-check', 'label' => 'Confirmed'],
                                'processing' => ['icon' => 'fa-cogs', 'label' => 'Processing'],
                                'packed' => ['icon' => 'fa-box', 'label' => 'Packed'],
                                'shipped' => ['icon' => 'fa-truck', 'label' => 'Shipped'],
                                'delivered' => ['icon' => 'fa-home', 'label' => 'Delivered']
                            ];
                            
                            $currentStatus = strtolower($orderDetails['order_status']);
                            $statusIndex = array_keys($steps);
                            $currentIndex = array_search($currentStatus, $statusIndex);
                            
                            foreach ($steps as $key => $step):
                                $stepIndex = array_search($key, $statusIndex);
                                $isCompleted = $stepIndex < $currentIndex;
                                $isActive = $key === $currentStatus;
                            ?>
                            <div class="step">
                                <div class="step-icon <?php echo ($isCompleted || $isActive) ? 'completed' : ''; ?> <?php echo $isActive ? 'active' : ''; ?>">
                                    <i class="fas <?php echo $step['icon']; ?>"></i>
                                </div>
                                <div class="step-label <?php echo $isActive ? 'active' : ''; ?>">
                                    <?php echo $step['label']; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Order Details Grid -->
                    <div class="details-grid">
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-calendar-alt"></i></div>
                            <div class="detail-content">
                                <div class="detail-label">Order Date</div>
                                <div class="detail-value"><?php echo formatTrackingDate($orderDetails['created_at']); ?></div>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-credit-card"></i></div>
                            <div class="detail-content">
                                <div class="detail-label">Payment Method</div>
                                <div class="detail-value"><?php echo ucfirst(str_replace('_', ' ', $orderDetails['payment_method'])); ?></div>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-money-bill-wave"></i></div>
                            <div class="detail-content">
                                <div class="detail-label">Payment Status</div>
                                <div class="detail-value"><?php echo ucfirst($orderDetails['payment_status']); ?></div>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-store"></i></div>
                            <div class="detail-content">
                                <div class="detail-label">Vendor</div>
                                <div class="detail-value"><?php echo htmlspecialchars($orderDetails['vendor_name']); ?></div>
                            </div>
                        </div>
                        <?php if ($orderDetails['delivery_partner']): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-truck"></i></div>
                            <div class="detail-content">
                                <div class="detail-label">Delivery Partner</div>
                                <div class="detail-value"><?php echo htmlspecialchars($orderDetails['delivery_partner']); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($orderDetails['tracking_ref']): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-barcode"></i></div>
                            <div class="detail-content">
                                <div class="detail-label">Tracking Reference</div>
                                <div class="detail-value"><?php echo htmlspecialchars($orderDetails['tracking_ref']); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($orderDetails['rider_phone']): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-motorcycle"></i></div>
                            <div class="detail-content">
                                <div class="detail-label">Rider Contact</div>
                                <div class="detail-value"><a href="tel:<?php echo $orderDetails['rider_phone']; ?>"><?php echo htmlspecialchars($orderDetails['rider_phone']); ?></a></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($orderDetails['estimated_delivery_date']): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-calendar-check"></i></div>
                            <div class="detail-content">
                                <div class="detail-label">Est. Delivery</div>
                                <div class="detail-value"><?php echo date('M d, Y', strtotime($orderDetails['estimated_delivery_date'])); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Delivery Address -->
                    <?php if ($orderDetails['address_line1']): ?>
                    <div class="details-grid" style="border-top: 1px solid var(--border-light); padding-top: 1.5rem;">
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fas fa-map-marker-alt"></i></div>
                            <div class="detail-content">
                                <div class="detail-label">Delivery Address</div>
                                <div class="detail-value">
                                    <?php echo htmlspecialchars($orderDetails['address_line1']); ?>
                                    <?php if ($orderDetails['address_line2']): ?>, <?php echo htmlspecialchars($orderDetails['address_line2']); ?><?php endif; ?>
                                    <br><?php echo htmlspecialchars($orderDetails['city']); ?>, <?php echo htmlspecialchars($orderDetails['region']); ?>
                                    <?php if ($orderDetails['delivery_phone']): ?><br>Tel: <?php echo htmlspecialchars($orderDetails['delivery_phone']); ?><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Products Section -->
                    <?php if (!empty($orderDetails['items'])): ?>
                    <div class="products-section">
                        <h4><i class="fas fa-boxes"></i> Order Items (<?php echo $orderDetails['total_items']; ?> items)</h4>
                        <?php foreach ($orderDetails['items'] as $item): ?>
                        <div class="product-item">
                            <div class="product-image">
                                <?php if (!empty($item['product_image']) && file_exists(BASE_PATH . ltrim($item['product_image'], '/'))): ?>
                                    <img src="<?php echo BASE_URL . ltrim($item['product_image'], '/'); ?>" alt="<?php echo htmlspecialchars($item['product_name']); ?>">
                                <?php else: ?>
                                    <i class="fas fa-box-open"></i>
                                <?php endif; ?>
                            </div>
                            <div class="product-info">
                                <div class="product-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                <div class="product-meta">Quantity: <?php echo $item['quantity']; ?></div>
                            </div>
                            <div class="product-price"><?php echo formatPriceShort($item['subtotal']); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Status History -->
                    <?php if (!empty($orderDetails['status_history'])): ?>
                    <div class="history-section">
                        <h4><i class="fas fa-history"></i> Order Timeline</h4>
                        <div class="timeline">
                            <?php foreach ($orderDetails['status_history'] as $history): ?>
                            <div class="timeline-item">
                                <div class="timeline-dot completed"></div>
                                <div class="timeline-content">
                                    <div class="timeline-status">
                                        <?php echo getOrderStatusBadge($history['new_status']); ?>
                                    </div>
                                    <div class="timeline-date">
                                        <i class="far fa-calendar-alt"></i> <?php echo formatTrackingDate($history['created_at']); ?>
                                    </div>
                                    <?php if ($history['notes']): ?>
                                    <div class="timeline-notes">
                                        <i class="fas fa-comment"></i> <?php echo htmlspecialchars($history['notes']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Action Buttons -->
                <div class="action-buttons">
                    <?php if ($orderDetails['tracking_ref']): ?>
                    <a href="#" onclick="window.print();" class="btn btn-outline">
                        <i class="fas fa-print"></i> Print Details
                    </a>
                    <?php endif; ?>
                    <a href="contact.php" class="btn btn-outline">
                        <i class="fas fa-headset"></i> Need Help?
                    </a>
                    <a href="shop.php" class="btn btn-primary">
                        <i class="fas fa-shopping-cart"></i> Continue Shopping
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Help Section -->
            <div class="help-section" data-aos="fade-up">
                <h3><i class="fas fa-question-circle"></i> Need Assistance?</h3>
                <p>If you have any questions about your order, our support team is here to help.</p>
                <div class="contact-links">
                    <a href="tel:<?php echo SITE_PHONE; ?>" class="contact-link">
                        <i class="fas fa-phone-alt"></i> Call Us: <?php echo SITE_PHONE; ?>
                    </a>
                    <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', SITE_WHATSAPP); ?>" class="contact-link">
                        <i class="fab fa-whatsapp"></i> WhatsApp Support
                    </a>
                    <a href="mailto:<?php echo SITE_EMAIL; ?>" class="contact-link">
                        <i class="fas fa-envelope"></i> Email Support
                    </a>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <?php include __DIR__ . '/footer.php'; ?>

    <!-- Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>
    <script>
        // Initialize AOS
        AOS.init({
            duration: 600,
            easing: 'ease-out-cubic',
            once: true,
            offset: 60
        });

        // Header scroll effect
        const headerEl = document.querySelector('.main-header');
        if (headerEl) {
            window.addEventListener('scroll', () => {
                if (window.scrollY > 50) {
                    headerEl.classList.add('scrolled');
                } else {
                    headerEl.classList.remove('scrolled');
                }
            });
        }

        // Tab switching
        function switchTab(tab) {
            const orderForm = document.getElementById('orderForm');
            const trackingForm = document.getElementById('trackingForm');
            const tabs = document.querySelectorAll('.tab-btn');
            
            tabs.forEach(btn => btn.classList.remove('active'));
            
            if (tab === 'order') {
                orderForm.style.display = 'block';
                trackingForm.style.display = 'none';
                tabs[0].classList.add('active');
            } else {
                orderForm.style.display = 'none';
                trackingForm.style.display = 'block';
                tabs[1].classList.add('active');
            }
        }
        
        // Check URL parameters to set active tab
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('ref')) {
            switchTab('tracking');
        } else {
            switchTab('order');
        }
        
        // Print functionality
        function showToast(message, type = 'success') {
            let container = document.querySelector('.toast-container');
            if (!container) {
                container = document.createElement('div');
                container.className = 'toast-container';
                document.body.appendChild(container);
            }

            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `<span>${message}</span>`;
            container.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(100%)';
                toast.style.transition = 'all 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
    </script>
</body>
</html>
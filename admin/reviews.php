<?php
/**
 * Bless Mall - Admin Reviews Management
 * Manage product reviews, moderate content, and analyze customer feedback
 * 
 * Developed by NerdSpring Software Engineering
 * https://nerd-spring.com
 */

// ------------------------------------------------------------
// 1. BOOTSTRAP
// ------------------------------------------------------------
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';

// Check admin authentication
if (!Auth::isLoggedIn()) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$role_id = (int)$_SESSION['role_id'];
if (!in_array($role_id, [ROLE_ADMIN, ROLE_SELLER])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$is_admin = ($role_id === ROLE_ADMIN);
$current_user_id = (int)$_SESSION['user_id'];

// ------------------------------------------------------------
// 2. DATABASE CONNECTION
// ------------------------------------------------------------
$conn = getConnection();

// ------------------------------------------------------------
// 3. HANDLE ACTIONS
// ------------------------------------------------------------
$message = '';
$message_type = '';

// Approve/Unapprove Review (for moderation)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $review_id = (int)$_GET['id'];
    $action = $_GET['action'];
    
    if ($action === 'approve' && $is_admin) {
        $stmt = $conn->prepare("UPDATE reviews SET is_approved = 1, moderated_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $review_id);
        if ($stmt->execute()) {
            $message = "Review approved successfully.";
            $message_type = 'success';
        } else {
            $message = "Error approving review.";
            $message_type = 'error';
        }
        $stmt->close();
    }
    
    if ($action === 'unapprove' && $is_admin) {
        $stmt = $conn->prepare("UPDATE reviews SET is_approved = 0, moderated_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $review_id);
        if ($stmt->execute()) {
            $message = "Review hidden from public view.";
            $message_type = 'success';
        } else {
            $message = "Error updating review.";
            $message_type = 'error';
        }
        $stmt->close();
    }
    
    if ($action === 'delete' && $is_admin) {
        $stmt = $conn->prepare("DELETE FROM reviews WHERE id = ?");
        $stmt->bind_param("i", $review_id);
        if ($stmt->execute()) {
            $message = "Review deleted successfully.";
            $message_type = 'success';
        } else {
            $message = "Error deleting review.";
            $message_type = 'error';
        }
        $stmt->close();
    }
}

// Bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete']) && $is_admin) {
    if (!empty($_POST['review_ids'])) {
        $ids = array_map('intval', $_POST['review_ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("DELETE FROM reviews WHERE id IN ($placeholders)");
        $types = str_repeat('i', count($ids));
        $stmt->bind_param($types, ...$ids);
        if ($stmt->execute()) {
            $message = count($ids) . " review(s) deleted successfully.";
            $message_type = 'success';
        }
        $stmt->close();
    }
}

// Bulk approve
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_approve']) && $is_admin) {
    if (!empty($_POST['review_ids'])) {
        $ids = array_map('intval', $_POST['review_ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("UPDATE reviews SET is_approved = 1, moderated_at = NOW() WHERE id IN ($placeholders)");
        $types = str_repeat('i', count($ids));
        $stmt->bind_param($types, ...$ids);
        if ($stmt->execute()) {
            $message = count($ids) . " review(s) approved successfully.";
            $message_type = 'success';
        }
        $stmt->close();
    }
}

// ------------------------------------------------------------
// 4. FILTERS & PAGINATION
// ------------------------------------------------------------
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

$rating_filter = isset($_GET['rating']) ? (int)$_GET['rating'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$product_filter = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Seller restriction
$vendor_restriction = '';
$vendor_params = [];
$vendor_types = '';

if (!$is_admin) {
    $vendor_restriction = " AND p.vendor_id = ?";
    $vendor_params[] = $current_user_id;
    $vendor_types .= 'i';
}

// Build WHERE conditions
$where = ["1=1"];
$params = [];
$types = '';

if ($rating_filter > 0) {
    $where[] = "r.rating = ?";
    $params[] = $rating_filter;
    $types .= 'i';
}

if (!empty($search)) {
    $where[] = "(r.comment LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR p.name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ssss';
}

if ($product_filter > 0) {
    $where[] = "r.product_id = ?";
    $params[] = $product_filter;
    $types .= 'i';
}

if (!empty($date_from)) {
    $where[] = "DATE(r.created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if (!empty($date_to)) {
    $where[] = "DATE(r.created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

if (!empty($status_filter)) {
    if ($status_filter === 'approved') {
        $where[] = "r.is_approved = 1";
    } elseif ($status_filter === 'pending') {
        $where[] = "(r.is_approved = 0 OR r.is_approved IS NULL)";
    }
}

$where_clause = implode(' AND ', $where);

// Sorting
$order = "r.created_at DESC";
switch ($sort) {
    case 'oldest': $order = "r.created_at ASC"; break;
    case 'highest': $order = "r.rating DESC, r.created_at DESC"; break;
    case 'lowest': $order = "r.rating ASC, r.created_at DESC"; break;
    case 'helpful': $order = "r.helpful_count DESC, r.created_at DESC"; break;
}

// Merge vendor params with main params
$all_params = array_merge($params, $vendor_params);
$all_types = $types . $vendor_types;

// ------------------------------------------------------------
// 5. GET REVIEWS
// ------------------------------------------------------------
$count_query = "SELECT COUNT(*) as total 
                FROM reviews r
                JOIN products p ON r.product_id = p.id
                JOIN users u ON r.user_id = u.id
                WHERE $where_clause $vendor_restriction";

$stmt = $conn->prepare($count_query);
if (!empty($all_params)) {
    $stmt->bind_param($all_types, ...$all_params);
}
$stmt->execute();
$total_reviews = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
$total_pages = $total_reviews > 0 ? ceil($total_reviews / $per_page) : 1;
$stmt->close();

// Add pagination params
$all_params[] = $per_page;
$all_params[] = $offset;
$all_types .= 'ii';

$query = "SELECT r.*, 
          u.name as user_name, u.email as user_email,
          p.name as product_name, p.slug as product_slug, p.id as product_id,
          v.business_name as vendor_name,
          (SELECT image_path FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as product_image
          FROM reviews r
          JOIN products p ON r.product_id = p.id
          JOIN users u ON r.user_id = u.id
          LEFT JOIN vendors v ON p.vendor_id = v.id
          WHERE $where_clause $vendor_restriction
          ORDER BY $order
          LIMIT ? OFFSET ?";

$stmt = $conn->prepare($query);
$stmt->bind_param($all_types, ...$all_params);
$stmt->execute();
$result = $stmt->get_result();

$reviews = [];
while ($row = $result->fetch_assoc()) {
    $reviews[] = $row;
}
$stmt->close();

// ------------------------------------------------------------
// 6. GET STATISTICS (FIXED: handle null values)
// ------------------------------------------------------------
$stats_query = "SELECT 
    COUNT(*) as total_reviews,
    ROUND(COALESCE(AVG(rating), 0), 1) as avg_rating,
    SUM(CASE WHEN rating >= 4 THEN 1 ELSE 0 END) as positive_reviews,
    SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as neutral_reviews,
    SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) as negative_reviews,
    COUNT(DISTINCT r.user_id) as unique_reviewers
    FROM reviews r
    JOIN products p ON r.product_id = p.id";

if (!$is_admin) {
    $stats_query .= " WHERE p.vendor_id = ?";
    $stmt = $conn->prepare($stats_query);
    $stmt->bind_param('i', $current_user_id);
} else {
    $stmt = $conn->prepare($stats_query);
}

$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Ensure all stats have numeric values (fix for number_format warnings)
$stats['total_reviews'] = (int)($stats['total_reviews'] ?? 0);
$stats['avg_rating'] = (float)($stats['avg_rating'] ?? 0);
$stats['positive_reviews'] = (int)($stats['positive_reviews'] ?? 0);
$stats['neutral_reviews'] = (int)($stats['neutral_reviews'] ?? 0);
$stats['negative_reviews'] = (int)($stats['negative_reviews'] ?? 0);
$stats['unique_reviewers'] = (int)($stats['unique_reviewers'] ?? 0);

// Rating distribution
$dist_query = "SELECT rating, COUNT(*) as count FROM reviews r JOIN products p ON r.product_id = p.id";
if (!$is_admin) {
    $dist_query .= " WHERE p.vendor_id = ? GROUP BY rating ORDER BY rating DESC";
    $stmt = $conn->prepare($dist_query);
    $stmt->bind_param('i', $current_user_id);
} else {
    $dist_query .= " GROUP BY rating ORDER BY rating DESC";
    $stmt = $conn->prepare($dist_query);
}
$stmt->execute();
$dist_result = $stmt->get_result();
$rating_distribution = [];
while ($row = $dist_result->fetch_assoc()) {
    $rating_distribution[$row['rating']] = (int)$row['count'];
}
$stmt->close();

// Fill missing ratings
for ($i = 1; $i <= 5; $i++) {
    if (!isset($rating_distribution[$i])) {
        $rating_distribution[$i] = 0;
    }
}
krsort($rating_distribution);

// Top reviewed products
$top_products_query = "SELECT p.id, p.name, p.slug, COUNT(r.id) as review_count, 
                       ROUND(COALESCE(AVG(r.rating), 0), 1) as avg_rating
                       FROM reviews r
                       JOIN products p ON r.product_id = p.id";
if (!$is_admin) {
    $top_products_query .= " WHERE p.vendor_id = ? GROUP BY p.id ORDER BY review_count DESC LIMIT 5";
    $stmt = $conn->prepare($top_products_query);
    $stmt->bind_param('i', $current_user_id);
} else {
    $top_products_query .= " GROUP BY p.id ORDER BY review_count DESC LIMIT 5";
    $stmt = $conn->prepare($top_products_query);
}
$stmt->execute();
$top_products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get products for filter dropdown
$products_query = "SELECT id, name FROM products WHERE is_active = 1";
if (!$is_admin) {
    $products_query .= " AND vendor_id = ?";
    $stmt = $conn->prepare($products_query);
    $stmt->bind_param('i', $current_user_id);
} else {
    $stmt = $conn->prepare($products_query);
}
$stmt->execute();
$products_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ------------------------------------------------------------
// 7. HELPER FUNCTIONS
// ------------------------------------------------------------
function getRatingStars($rating) {
    $rating = (float)$rating;
    $html = '<div class="rating-stars">';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $html .= '<i class="fas fa-star"></i>';
        } elseif ($i - 0.5 <= $rating) {
            $html .= '<i class="fas fa-star-half-alt"></i>';
        } else {
            $html .= '<i class="far fa-star"></i>';
        }
    }
    $html .= '</div>';
    return $html;
}

function getRatingBadge($rating) {
    $rating = (float)$rating;
    if ($rating >= 4) {
        return '<span class="badge-rating badge-positive"><i class="fas fa-smile"></i> Positive</span>';
    } elseif ($rating == 3) {
        return '<span class="badge-rating badge-neutral"><i class="fas fa-meh"></i> Neutral</span>';
    } else {
        return '<span class="badge-rating badge-negative"><i class="fas fa-frown"></i> Negative</span>';
    }
}

function getStatusBadge($is_approved) {
    if ($is_approved) {
        return '<span class="badge-status badge-approved"><i class="fas fa-check-circle"></i> Approved</span>';
    } else {
        return '<span class="badge-status badge-pending"><i class="fas fa-clock"></i> Pending</span>';
    }
}

function timeAgo($datetime) {
    if (empty($datetime)) return 'Unknown';
    $time = strtotime($datetime);
    $diff = time() - $time;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M d, Y', $time);
}

function buildFilterUrl($overrides = []) {
    $params = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    unset($params['page']);
    return 'reviews.php?' . http_build_query($params);
}

// Current page for active menu
$page_title = 'Reviews Management';
$current_page = 'reviews.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    
    <title><?php echo $page_title; ?> | Bless Mall Admin Panel</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Chart.js for analytics -->
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
            --radius-full: 9999px;
            --transition-fast: 0.15s cubic-bezier(0.4, 0, 0.2, 1);
            --transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            --font-heading: 'Outfit', sans-serif;
            --font-body: 'Inter', sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: var(--font-body);
            background: var(--admin-bg);
            color: var(--text-primary);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }
        
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--admin-bg); }
        ::-webkit-scrollbar-thumb { background: var(--admin-border-light); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--admin-primary); }
        
        /* Admin Layout */
        .admin-wrapper {
            display: flex;
            min-height: 100vh;
        }
        
        .admin-main {
            flex: 1;
            padding: 1.5rem;
            transition: var(--transition);
        }
        
        /* Breadcrumb */
        .admin-breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        .admin-breadcrumb a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: var(--transition-fast);
        }
        
        .admin-breadcrumb a:hover {
            color: var(--admin-primary);
        }
        
        .admin-breadcrumb .separator {
            color: var(--text-muted);
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
            font-size: 1.75rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--text-primary) 0%, var(--text-secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.5rem;
        }
        
        .admin-page-header p {
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-card {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-md);
            padding: 1rem;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--admin-primary), var(--admin-purple));
            opacity: 0;
            transition: var(--transition);
        }
        
        .stat-card:hover::before {
            opacity: 1;
        }
        
        .stat-card:hover {
            border-color: var(--admin-primary);
            transform: translateY(-2px);
        }
        
        .stat-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }
        
        .stat-card-icon {
            width: 42px;
            height: 42px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .stat-card-icon.primary { background: var(--admin-primary-glow); color: var(--admin-primary); }
        .stat-card-icon.success { background: var(--admin-success-bg); color: var(--admin-success); }
        .stat-card-icon.warning { background: var(--admin-warning-bg); color: var(--admin-warning); }
        .stat-card-icon.danger { background: var(--admin-danger-bg); color: var(--admin-danger); }
        .stat-card-icon.info { background: rgba(6, 182, 212, 0.1); color: var(--admin-info); }
        .stat-card-icon.purple { background: rgba(139, 92, 246, 0.1); color: var(--admin-purple); }
        
        .stat-card-value {
            font-family: var(--font-heading);
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
        }
        
        .stat-card-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
        
        /* Analytics Grid */
        .analytics-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        /* Rating Distribution */
        .rating-distribution {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-md);
            padding: 1.25rem;
        }
        
        .distribution-title {
            font-family: var(--font-heading);
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .distribution-bar {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }
        
        .distribution-label {
            width: 70px;
            font-size: 0.8rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .distribution-fill {
            flex: 1;
            height: 8px;
            background: var(--admin-bg);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .distribution-fill-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        .distribution-fill-bar.excellent { background: linear-gradient(90deg, var(--admin-success), #34D399); }
        .distribution-fill-bar.good { background: linear-gradient(90deg, #34D399, #6EE7B7); }
        .distribution-fill-bar.average { background: linear-gradient(90deg, var(--admin-warning), #FCD34D); }
        .distribution-fill-bar.poor { background: linear-gradient(90deg, #F87171, #FCA5A5); }
        .distribution-fill-bar.bad { background: linear-gradient(90deg, var(--admin-danger), #F87171); }
        
        .distribution-count {
            width: 45px;
            text-align: right;
            font-size: 0.8rem;
            color: var(--text-muted);
            font-family: var(--font-mono);
        }
        
        /* Top Products Panel */
        .top-products-panel {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-md);
            padding: 1.25rem;
        }
        
        .top-product-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--admin-border);
            transition: var(--transition-fast);
        }
        
        .top-product-item:hover {
            background: var(--admin-surface-hover);
            margin: 0 -0.5rem;
            padding: 0.75rem 0.5rem;
            border-radius: var(--radius-sm);
        }
        
        .top-product-item:last-child {
            border-bottom: none;
        }
        
        .top-product-info {
            flex: 1;
        }
        
        .top-product-name {
            font-weight: 500;
            color: var(--text-primary);
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }
        
        .top-product-name a {
            color: inherit;
            text-decoration: none;
        }
        
        .top-product-name a:hover {
            color: var(--admin-primary);
        }
        
        .top-product-rating {
            color: #F59E0B;
            font-size: 0.75rem;
        }
        
        .top-product-stats {
            text-align: right;
        }
        
        .top-product-count {
            font-size: 0.7rem;
            color: var(--text-muted);
        }
        
        /* Filters Section */
        .filters-section {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        
        .filters-row {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 150px;
        }
        
        .filter-group label {
            display: block;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            margin-bottom: 0.4rem;
            font-weight: 600;
        }
        
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 0.55rem 0.75rem;
            background: var(--admin-bg);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-family: var(--font-body);
            font-size: 0.85rem;
            transition: var(--transition-fast);
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--admin-primary);
            box-shadow: 0 0 0 3px var(--admin-primary-glow);
        }
        
        .filter-group input::placeholder {
            color: var(--text-muted);
        }
        
        .btn-filter {
            padding: 0.55rem 1.25rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            font-family: var(--font-body);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-filter-primary {
            background: var(--admin-primary);
            color: white;
        }
        
        .btn-filter-primary:hover {
            background: var(--admin-primary-dark);
            transform: translateY(-1px);
        }
        
        .btn-filter-outline {
            background: transparent;
            border: 1px solid var(--admin-border);
            color: var(--text-secondary);
        }
        
        .btn-filter-outline:hover {
            border-color: var(--admin-primary);
            color: var(--admin-primary);
        }
        
        .btn-filter-success {
            background: var(--admin-success-bg);
            color: var(--admin-success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .btn-filter-success:hover {
            background: var(--admin-success);
            color: white;
        }
        
        .btn-filter-danger {
            background: var(--admin-danger-bg);
            color: var(--admin-danger);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .btn-filter-danger:hover {
            background: var(--admin-danger);
            color: white;
        }
        
        /* Reviews Table */
        .reviews-table-container {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-md);
            overflow: hidden;
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--admin-border);
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .table-header h3 {
            font-family: var(--font-heading);
            font-size: 1rem;
            font-weight: 600;
        }
        
        .table-header-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .reviews-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .reviews-table th {
            text-align: left;
            padding: 0.75rem 1rem;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            font-weight: 600;
            background: var(--admin-bg);
            border-bottom: 1px solid var(--admin-border);
        }
        
        .reviews-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--admin-border);
            font-size: 0.85rem;
            vertical-align: middle;
        }
        
        .reviews-table tr:hover td {
            background: var(--admin-surface-hover);
        }
        
        .reviews-table tr:last-child td {
            border-bottom: none;
        }
        
        .review-product {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .review-product-image {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-sm);
            background: var(--admin-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .review-product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .review-product-image i {
            color: var(--text-muted);
            font-size: 1.2rem;
        }
        
        .review-product-info {
            flex: 1;
            min-width: 0;
        }
        
        .review-product-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.15rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .review-product-name a {
            color: inherit;
            text-decoration: none;
        }
        
        .review-product-name a:hover {
            color: var(--admin-primary);
        }
        
        .review-vendor {
            font-size: 0.7rem;
            color: var(--text-muted);
        }
        
        .review-user {
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 0.15rem;
        }
        
        .review-user-email {
            font-size: 0.7rem;
            color: var(--text-muted);
        }
        
        .rating-stars {
            color: #F59E0B;
            font-size: 0.8rem;
            white-space: nowrap;
        }
        
        .rating-stars i {
            margin-right: 1px;
        }
        
        .badge-rating, .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.2rem 0.6rem;
            border-radius: var(--radius-full);
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
            margin-top: 0.25rem;
        }
        
        .badge-positive { background: var(--admin-success-bg); color: var(--admin-success); }
        .badge-neutral { background: var(--admin-warning-bg); color: var(--admin-warning); }
        .badge-negative { background: var(--admin-danger-bg); color: var(--admin-danger); }
        .badge-approved { background: var(--admin-success-bg); color: var(--admin-success); }
        .badge-pending { background: var(--admin-warning-bg); color: var(--admin-warning); }
        
        .review-comment {
            max-width: 280px;
            color: var(--text-secondary);
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            font-size: 0.8rem;
        }
        
        .review-date {
            font-size: 0.7rem;
            color: var(--text-muted);
            white-space: nowrap;
        }
        
        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: var(--radius-sm);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition-fast);
            border: 1px solid transparent;
            background: transparent;
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        
        .action-btn:hover {
            background: var(--admin-surface-hover);
            color: var(--text-primary);
        }
        
        .action-btn.approve:hover {
            background: var(--admin-success-bg);
            color: var(--admin-success);
        }
        
        .action-btn.delete:hover {
            background: var(--admin-danger-bg);
            color: var(--admin-danger);
        }
        
        .action-btn.view:hover {
            background: var(--admin-primary-glow);
            color: var(--admin-primary);
        }
        
        /* Checkbox */
        .checkbox-wrapper {
            display: flex;
            align-items: center;
        }
        
        .checkbox-wrapper input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--admin-primary);
            cursor: pointer;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }
        
        .empty-state-icon {
            font-size: 3rem;
            color: var(--text-muted);
            opacity: 0.5;
            margin-bottom: 1rem;
        }
        
        .empty-state h4 {
            font-size: 1.1rem;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            padding: 1rem;
            gap: 0.35rem;
            border-top: 1px solid var(--admin-border);
            flex-wrap: wrap;
        }
        
        .pagination a,
        .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 0.75rem;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            transition: var(--transition-fast);
            border: 1px solid var(--admin-border);
            color: var(--text-secondary);
            background: transparent;
        }
        
        .pagination a:hover {
            background: var(--admin-surface-hover);
            border-color: var(--admin-primary);
            color: var(--admin-primary);
        }
        
        .pagination .active {
            background: var(--admin-primary);
            color: white;
            border-color: var(--admin-primary);
        }
        
        .pagination .disabled {
            opacity: 0.4;
            pointer-events: none;
        }
        
        /* Alert Messages */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.85rem;
            font-weight: 500;
            animation: slideInDown 0.3s ease;
        }
        
        .alert-success {
            background: var(--admin-success-bg);
            color: var(--admin-success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .alert-error {
            background: var(--admin-danger-bg);
            color: var(--admin-danger);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        /* Toast Container */
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
            border-left: 3px solid;
            padding: 12px 20px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            font-weight: 500;
            box-shadow: var(--shadow-lg);
            animation: slideInRight 0.3s ease;
            max-width: 420px;
        }
        
        .toast.success { border-left-color: var(--admin-success); }
        .toast.error { border-left-color: var(--admin-danger); }
        .toast.info { border-left-color: var(--admin-info); }
        
        /* Animations */
        @keyframes slideInDown {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        @keyframes slideInRight {
            from { transform: translateX(120%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .analytics-grid {
                grid-template-columns: 1fr;
            }
            
            .reviews-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }
        
        @media (max-width: 768px) {
            .admin-main {
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }
            
            .filters-row {
                flex-direction: column;
            }
            
            .filter-group {
                min-width: 100%;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .reviews-table th,
            .reviews-table td {
                padding: 0.75rem;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Loading State */
        .loading {
            opacity: 0.6;
            pointer-events: none;
            animation: pulse 1.5s ease-in-out infinite;
        }
        
        /* Tooltip */
        [data-tooltip] {
            position: relative;
            cursor: pointer;
        }
        
        [data-tooltip]:before {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            padding: 4px 8px;
            background: var(--admin-bg);
            color: var(--text-primary);
            font-size: 0.7rem;
            border-radius: var(--radius-sm);
            white-space: nowrap;
            display: none;
            z-index: 100;
            border: 1px solid var(--admin-border);
        }
        
        [data-tooltip]:hover:before {
            display: block;
        }
    </style>
</head>
<body>

<div class="admin-wrapper">
    <?php include __DIR__ . '/header.php'; ?>
    
    <main class="admin-main">
        <!-- Breadcrumb -->
        <div class="admin-breadcrumb">
            <a href="index.php"><i class="fas fa-home"></i> Dashboard</a>
            <span class="separator">/</span>
            <span class="current">Reviews Management</span>
        </div>

        <!-- Page Header -->
        <div class="admin-page-header">
            <h1><i class="fas fa-star" style="background: linear-gradient(135deg, #F59E0B, #FCD34D); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"></i> Reviews Management</h1>
            <p>Monitor, moderate, and analyze customer feedback for your products</p>
        </div>

        <!-- Alert Messages -->
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards (FIXED: number_format with null safety) -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-icon primary">
                        <i class="fas fa-star"></i>
                    </div>
                    <i class="fas fa-chart-line" style="color: var(--text-muted); font-size: 0.8rem;"></i>
                </div>
                <div class="stat-card-value"><?php echo number_format((float)$stats['avg_rating'], 1); ?></div>
                <div class="stat-card-label">Average Rating</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-icon success">
                        <i class="fas fa-comments"></i>
                    </div>
                </div>
                <div class="stat-card-value"><?php echo number_format((int)$stats['total_reviews']); ?></div>
                <div class="stat-card-label">Total Reviews</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-icon success">
                        <i class="fas fa-smile"></i>
                    </div>
                </div>
                <div class="stat-card-value"><?php echo number_format((int)$stats['positive_reviews']); ?></div>
                <div class="stat-card-label">Positive (4-5 ★)</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-icon warning">
                        <i class="fas fa-meh"></i>
                    </div>
                </div>
                <div class="stat-card-value"><?php echo number_format((int)$stats['neutral_reviews']); ?></div>
                <div class="stat-card-label">Neutral (3 ★)</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-icon danger">
                        <i class="fas fa-frown"></i>
                    </div>
                </div>
                <div class="stat-card-value"><?php echo number_format((int)$stats['negative_reviews']); ?></div>
                <div class="stat-card-label">Negative (1-2 ★)</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-icon purple">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-card-value"><?php echo number_format((int)$stats['unique_reviewers']); ?></div>
                <div class="stat-card-label">Unique Reviewers</div>
            </div>
        </div>

        <!-- Analytics Section -->
        <div class="analytics-grid">
            <!-- Rating Distribution -->
            <div class="rating-distribution">
                <h3 class="distribution-title">
                    <i class="fas fa-chart-bar" style="color: var(--admin-primary);"></i>
                    Rating Distribution
                    <span style="font-size: 0.7rem; font-weight: 400; color: var(--text-muted); margin-left: auto;">
                        <?php echo number_format((int)$stats['total_reviews']); ?> total votes
                    </span>
                </h3>
                <?php 
                $max_count = max($rating_distribution) ?: 1;
                $bar_colors = [5 => 'excellent', 4 => 'good', 3 => 'average', 2 => 'poor', 1 => 'bad'];
                $star_labels = [5 => '★★★★★', 4 => '★★★★☆', 3 => '★★★☆☆', 2 => '★★☆☆☆', 1 => '★☆☆☆☆'];
                
                foreach ($rating_distribution as $rating => $count): 
                    $percent = $stats['total_reviews'] > 0 ? round(($count / $stats['total_reviews']) * 100) : 0;
                ?>
                <div class="distribution-bar">
                    <span class="distribution-label">
                        <span style="color: #F59E0B;"><?php echo $star_labels[$rating]; ?></span>
                    </span>
                    <div class="distribution-fill">
                        <div class="distribution-fill-bar <?php echo $bar_colors[$rating]; ?>" style="width: <?php echo $percent; ?>%;"></div>
                    </div>
                    <span class="distribution-count"><?php echo number_format($count); ?></span>
                    <span style="font-size: 0.7rem; color: var(--text-muted); width: 40px;"><?php echo $percent; ?>%</span>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Top Reviewed Products -->
            <div class="top-products-panel">
                <h3 class="distribution-title">
                    <i class="fas fa-trophy" style="color: var(--admin-warning);"></i>
                    Top Reviewed Products
                </h3>
                <?php if (!empty($top_products)): ?>
                    <?php foreach ($top_products as $index => $tp): ?>
                    <div class="top-product-item">
                        <div class="top-product-info">
                            <div class="top-product-name">
                                <span style="color: var(--admin-warning); font-weight: 700; margin-right: 0.5rem;">#<?php echo $index + 1; ?></span>
                                <a href="../product-detail.php?id=<?php echo $tp['id']; ?>" target="_blank">
                                    <?php echo htmlspecialchars(mb_strlen($tp['name']) > 35 ? mb_substr($tp['name'], 0, 35) . '...' : $tp['name']); ?>
                                </a>
                            </div>
                            <div class="top-product-rating">
                                <?php echo getRatingStars($tp['avg_rating']); ?>
                            </div>
                        </div>
                        <div class="top-product-stats">
                            <div class="top-product-count"><?php echo number_format($tp['review_count']); ?> reviews</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
                        <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 0.5rem; display: block;"></i>
                        No reviews yet
                        <p style="font-size: 0.75rem; margin-top: 0.5rem;">Products will appear here once they receive reviews</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filters-section">
            <form method="get" action="reviews.php" id="filterForm">
                <div class="filters-row">
                    <div class="filter-group">
                        <label><i class="fas fa-search"></i> Search</label>
                        <input type="text" name="search" placeholder="Search reviews, products, users..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-star"></i> Rating</label>
                        <select name="rating">
                            <option value="">All Ratings</option>
                            <option value="5" <?php echo $rating_filter === 5 ? 'selected' : ''; ?>>★★★★★ (5 Stars)</option>
                            <option value="4" <?php echo $rating_filter === 4 ? 'selected' : ''; ?>>★★★★☆ (4 Stars)</option>
                            <option value="3" <?php echo $rating_filter === 3 ? 'selected' : ''; ?>>★★★☆☆ (3 Stars)</option>
                            <option value="2" <?php echo $rating_filter === 2 ? 'selected' : ''; ?>>★★☆☆☆ (2 Stars)</option>
                            <option value="1" <?php echo $rating_filter === 1 ? 'selected' : ''; ?>>★☆☆☆☆ (1 Star)</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-sort-amount-down"></i> Sort By</label>
                        <select name="sort">
                            <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                            <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                            <option value="highest" <?php echo $sort === 'highest' ? 'selected' : ''; ?>>Highest Rated</option>
                            <option value="lowest" <?php echo $sort === 'lowest' ? 'selected' : ''; ?>>Lowest Rated</option>
                            <option value="helpful" <?php echo $sort === 'helpful' ? 'selected' : ''; ?>>Most Helpful</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-tag"></i> Product</label>
                        <select name="product_id">
                            <option value="0">All Products</option>
                            <?php foreach ($products_list as $product): ?>
                            <option value="<?php echo $product['id']; ?>" <?php echo $product_filter === (int)$product['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(mb_strlen($product['name']) > 40 ? mb_substr($product['name'], 0, 40) . '...' : $product['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-calendar-alt"></i> Date Range</label>
                        <div style="display: flex; gap: 0.5rem;">
                            <input type="date" name="date_from" placeholder="From" value="<?php echo htmlspecialchars($date_from); ?>" style="flex: 1;">
                            <input type="date" name="date_to" placeholder="To" value="<?php echo htmlspecialchars($date_to); ?>" style="flex: 1;">
                        </div>
                    </div>
                    
                    <?php if ($is_admin): ?>
                    <div class="filter-group">
                        <label><i class="fas fa-check-circle"></i> Status</label>
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending Moderation</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div style="display: flex; gap: 0.5rem; align-items: flex-end;">
                        <button type="submit" class="btn-filter btn-filter-primary">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <a href="reviews.php" class="btn-filter btn-filter-outline">
                            <i class="fas fa-redo-alt"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Reviews Table -->
        <div class="reviews-table-container">
            <div class="table-header">
                <h3>
                    <i class="fas fa-list"></i> 
                    All Reviews 
                    <span style="color: var(--text-muted); font-weight: 400; font-size: 0.8rem;">
                        (<?php echo number_format($total_reviews); ?> total)
                    </span>
                </h3>
                <div class="table-header-actions">
                    <?php if ($is_admin && $total_reviews > 0): ?>
                    <button type="button" class="btn-filter btn-filter-success" id="bulkApproveBtn" style="display: none;" onclick="bulkApprove()">
                        <i class="fas fa-check-circle"></i> Approve Selected
                    </button>
                    <button type="button" class="btn-filter btn-filter-danger" id="bulkDeleteBtn" style="display: none;" onclick="bulkDelete()">
                        <i class="fas fa-trash-alt"></i> Delete Selected
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (!empty($reviews)): ?>
            <form id="bulkActionForm" method="post" action="reviews.php">
                <div style="overflow-x: auto;">
                    <table class="reviews-table">
                        <thead>
                            <tr>
                                <?php if ($is_admin): ?>
                                <th style="width: 40px;">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" id="selectAll" onchange="toggleAllCheckboxes(this)">
                                    </div>
                                </th>
                                <?php endif; ?>
                                <th>Product & Vendor</th>
                                <th>Customer</th>
                                <th>Rating & Status</th>
                                <th>Review</th>
                                <th>Date</th>
                                <th style="width: 100px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reviews as $review): ?>
                            <tr class="<?php echo !($review['is_approved'] ?? false) ? 'pending-review' : ''; ?>">
                                <?php if ($is_admin): ?>
                                <td>
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" name="review_ids[]" value="<?php echo $review['id']; ?>" class="review-checkbox" onchange="toggleBulkButtons()">
                                    </div>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <div class="review-product">
                                        <div class="review-product-image">
                                            <?php if (!empty($review['product_image'])): ?>
                                                <img src="<?php echo UPLOADS_URL; ?>products/<?php echo htmlspecialchars($review['product_image']); ?>" alt="<?php echo htmlspecialchars($review['product_name']); ?>">
                                            <?php else: ?>
                                                <i class="fas fa-box-open"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="review-product-info">
                                            <div class="review-product-name">
                                                <a href="../product-detail.php?id=<?php echo $review['product_id']; ?>" target="_blank">
                                                    <?php echo htmlspecialchars($review['product_name']); ?>
                                                </a>
                                            </div>
                                            <div class="review-vendor">
                                                <i class="fas fa-store"></i> 
                                                <?php echo htmlspecialchars($review['vendor_name'] ?? 'Bless Mall Express'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="review-user"><?php echo htmlspecialchars($review['user_name']); ?></div>
                                    <div class="review-user-email"><?php echo htmlspecialchars($review['user_email']); ?></div>
                                </td>
                                <td>
                                    <?php echo getRatingStars($review['rating']); ?>
                                    <?php echo getRatingBadge($review['rating']); ?>
                                    <?php if ($is_admin): ?>
                                        <?php echo getStatusBadge($review['is_approved'] ?? false); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="review-comment">
                                        <?php echo !empty($review['comment']) ? nl2br(htmlspecialchars($review['comment'])) : '<em style="color: var(--text-muted);">No written review</em>'; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="review-date">
                                        <?php echo date('M d, Y', strtotime($review['created_at'])); ?>
                                        <br>
                                        <span style="font-size: 0.65rem;"><?php echo timeAgo($review['created_at']); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 0.35rem; flex-wrap: wrap;">
                                        <a href="../product-detail.php?id=<?php echo $review['product_id']; ?>" target="_blank" class="action-btn view" data-tooltip="View Product">
                                            <i class="fas fa-external-link-alt"></i>
                                        </a>
                                        <?php if ($is_admin): ?>
                                            <?php if (!($review['is_approved'] ?? false)): ?>
                                            <button type="button" class="action-btn approve" onclick="approveReview(<?php echo $review['id']; ?>)" data-tooltip="Approve Review">
                                                <i class="fas fa-check-circle"></i>
                                            </button>
                                            <?php else: ?>
                                            <button type="button" class="action-btn approve" onclick="unapproveReview(<?php echo $review['id']; ?>)" data-tooltip="Hide Review">
                                                <i class="fas fa-eye-slash"></i>
                                            </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <button type="button" class="action-btn delete" onclick="confirmDelete(<?php echo $review['id']; ?>)" data-tooltip="Delete Review">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo buildFilterUrl(['page' => $page - 1]); ?>">
                            <i class="fas fa-chevron-left"></i> Prev
                        </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-chevron-left"></i> Prev</span>
                    <?php endif; ?>
                    
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                    
                    if ($start > 1) {
                        echo '<a href="' . buildFilterUrl(['page' => 1]) . '">1</a>';
                        if ($start > 2) echo '<span class="disabled">...</span>';
                    }
                    
                    for ($i = $start; $i <= $end; $i++): ?>
                        <a href="<?php echo buildFilterUrl(['page' => $i]); ?>" class="<?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor;
                    
                    if ($end < $total_pages) {
                        if ($end < $total_pages - 1) echo '<span class="disabled">...</span>';
                        echo '<a href="' . buildFilterUrl(['page' => $total_pages]) . '">' . $total_pages . '</a>';
                    }
                    ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="<?php echo buildFilterUrl(['page' => $page + 1]); ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled">Next <i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </form>
            
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-star-of-life"></i>
                </div>
                <h4>No Reviews Found</h4>
                <p>
                    <?php if (!empty($search) || $rating_filter > 0 || $product_filter > 0 || !empty($date_from) || !empty($status_filter)): ?>
                        No reviews match your filters. Try adjusting your search criteria.
                    <?php else: ?>
                        No customer reviews have been submitted yet. Reviews will appear here once customers leave feedback.
                    <?php endif; ?>
                </p>
                <?php if (!empty($search) || $rating_filter > 0 || $product_filter > 0): ?>
                <a href="reviews.php" class="btn-filter btn-filter-primary" style="margin-top: 1rem; display: inline-flex;">
                    <i class="fas fa-redo-alt"></i> Clear Filters
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Include Admin Footer -->
<?php include __DIR__ . '/footer.php'; ?>

<script>
    // Select all checkboxes
    function toggleAllCheckboxes(source) {
        const checkboxes = document.querySelectorAll('.review-checkbox');
        checkboxes.forEach(cb => cb.checked = source.checked);
        toggleBulkButtons();
    }
    
    // Toggle bulk action buttons visibility
    function toggleBulkButtons() {
        const checkedCount = document.querySelectorAll('.review-checkbox:checked').length;
        const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
        const bulkApproveBtn = document.getElementById('bulkApproveBtn');
        
        if (bulkDeleteBtn) {
            bulkDeleteBtn.style.display = checkedCount > 0 ? 'inline-flex' : 'none';
        }
        if (bulkApproveBtn) {
            bulkApproveBtn.style.display = checkedCount > 0 ? 'inline-flex' : 'none';
        }
    }
    
    // Bulk delete
    function bulkDelete() {
        const checkedCount = document.querySelectorAll('.review-checkbox:checked').length;
        if (checkedCount === 0) return;
        
        if (confirm(`⚠️ Are you sure you want to DELETE ${checkedCount} selected review(s)?\n\nThis action cannot be undone and will permanently remove these reviews from the system.`)) {
            const form = document.getElementById('bulkActionForm');
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'bulk_delete';
            input.value = '1';
            form.appendChild(input);
            form.submit();
        }
    }
    
    // Bulk approve
    function bulkApprove() {
        const checkedCount = document.querySelectorAll('.review-checkbox:checked').length;
        if (checkedCount === 0) return;
        
        if (confirm(`Approve ${checkedCount} selected review(s)?\n\nApproved reviews will be visible to all customers on the product pages.`)) {
            const form = document.getElementById('bulkActionForm');
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'bulk_approve';
            input.value = '1';
            form.appendChild(input);
            form.submit();
        }
    }
    
    // Approve single review
    function approveReview(reviewId) {
        if (confirm('Approve this review? It will become visible to all customers.')) {
            window.location.href = 'reviews.php?action=approve&id=' + reviewId + '&<?php echo http_build_query(array_filter($_GET, function($k) { return !in_array($k, ['action', 'id']); }, ARRAY_FILTER_USE_KEY)); ?>';
        }
    }
    
    // Unapprove (hide) single review
    function unapproveReview(reviewId) {
        if (confirm('Hide this review? It will no longer be visible to customers.')) {
            window.location.href = 'reviews.php?action=unapprove&id=' + reviewId + '&<?php echo http_build_query(array_filter($_GET, function($k) { return !in_array($k, ['action', 'id']); }, ARRAY_FILTER_USE_KEY)); ?>';
        }
    }
    
    // Confirm single delete
    function confirmDelete(reviewId) {
        if (confirm('⚠️ Are you sure you want to delete this review?\n\nThis action cannot be undone.')) {
            window.location.href = 'reviews.php?action=delete&id=' + reviewId + '&<?php echo http_build_query(array_filter($_GET, function($k) { return !in_array($k, ['action', 'id']); }, ARRAY_FILTER_USE_KEY)); ?>';
        }
    }
    
    // Toast notification system
    function showToast(message, type = 'success') {
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            <span>${message}</span>
        `;
        
        container.appendChild(toast);
        
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(120%)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }
    
    // Handle flash messages
    <?php if ($message): ?>
        showToast('<?php echo addslashes($message); ?>', '<?php echo $message_type === 'success' ? 'success' : 'error'; ?>');
    <?php endif; ?>
    
    // Auto-dismiss alerts after 5 seconds
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-20px)';
            alert.style.transition = 'all 0.3s ease';
            setTimeout(() => alert.remove(), 300);
        });
    }, 5000);
    
    // Highlight pending reviews
    const pendingRows = document.querySelectorAll('.pending-review');
    pendingRows.forEach(row => {
        row.style.backgroundColor = 'rgba(245, 158, 11, 0.05)';
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
        // Ctrl/Cmd + F to focus search
        if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
            e.preventDefault();
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) searchInput.focus();
        }
        
        // Escape to clear filters
        if (e.key === 'Escape') {
            window.location.href = 'reviews.php';
        }
    });
</script>

</body>
</html>
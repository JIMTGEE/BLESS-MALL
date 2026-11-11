<?php
/**
 * vendors.php - Bless Mall Admin Vendors Management
 * Premium Admin Dashboard - Vendor Management
 * 
 * Features:
 * - View all vendors with detailed DB-driven metrics
 * - Approve/Reject vendor applications
 * - Toggle vendor status (active/inactive/suspended)
 * - Toggle express delivery status
 * - Search and filter vendors
 * - Real-time vendor performance metrics from DB
 * - Revenue tracking from orders table
 * - Product count from products table
 * - Order statistics from orders table
 * - Rating from reviews table
 * - Quick actions (view products, orders)
 * - Responsive data table with pagination
 * 
 * Developed by NerdSpring Software Engineering
 * https://nerd-spring.com
 */

// ------------------------------------------------------------
// 1. BOOTSTRAP: Load core system files
// ------------------------------------------------------------
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

// ------------------------------------------------------------
// 2. AUTHENTICATION CHECK
// ------------------------------------------------------------
if (!Auth::isLoggedIn()) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

// Only admin (role_id = 1) can access vendor management
if (!Auth::isAdmin()) {
    header('Location: ' . BASE_URL . 'admin/index.php');
    exit;
}

// ------------------------------------------------------------
// 3. DATABASE CONNECTION
// ------------------------------------------------------------
$conn = getConnection();

// ------------------------------------------------------------
// 4. HANDLE POST ACTIONS (AJAX or Form)
// ------------------------------------------------------------
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $vendorId = isset($_POST['vendor_id']) ? (int)$_POST['vendor_id'] : 0;
    
    // CSRF Protection
    if (!CSRFToken::validate($_POST['csrf_token'] ?? '')) {
        $message = 'Security validation failed. Please try again.';
        $messageType = 'error';
    } else {
        switch ($action) {
            case 'toggle_approval':
                $isApproved = isset($_POST['is_approved']) ? (int)$_POST['is_approved'] : 0;
                $stmt = $conn->prepare("UPDATE vendors SET is_approved = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("ii", $isApproved, $vendorId);
                if ($stmt->execute()) {
                    $message = $isApproved ? 'Vendor approved successfully!' : 'Vendor approval revoked.';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to update vendor approval status.';
                    $messageType = 'error';
                }
                $stmt->close();
                break;
                
            case 'toggle_status':
                $status = $_POST['status'] ?? 'active';
                $allowedStatuses = ['active', 'inactive', 'suspended'];
                if (!in_array($status, $allowedStatuses)) {
                    $message = 'Invalid status.';
                    $messageType = 'error';
                    break;
                }
                $stmt = $conn->prepare("UPDATE vendors SET status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("si", $status, $vendorId);
                if ($stmt->execute()) {
                    $message = 'Vendor status updated to ' . ucfirst($status) . '.';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to update vendor status.';
                    $messageType = 'error';
                }
                $stmt->close();
                break;
                
            case 'toggle_express':
                $isExpress = isset($_POST['is_express']) ? (int)$_POST['is_express'] : 0;
                $stmt = $conn->prepare("UPDATE vendors SET is_express = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("ii", $isExpress, $vendorId);
                if ($stmt->execute()) {
                    $message = $isExpress ? 'Vendor marked as Express!' : 'Vendor removed from Express.';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to update express status.';
                    $messageType = 'error';
                }
                $stmt->close();
                break;
                
            case 'update_vendor':
                $businessName = trim($_POST['business_name'] ?? '');
                $contactPhone = trim($_POST['contact_phone'] ?? '');
                $whatsapp = trim($_POST['whatsapp'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $storeBadge = trim($_POST['store_badge'] ?? '');
                
                if (empty($businessName)) {
                    $message = 'Business name is required.';
                    $messageType = 'error';
                    break;
                }
                
                $stmt = $conn->prepare("UPDATE vendors SET 
                    business_name = ?, 
                    contact_phone = ?, 
                    whatsapp = ?, 
                    description = ?, 
                    store_badge = ?,
                    updated_at = NOW() 
                    WHERE id = ?");
                $stmt->bind_param("sssssi", $businessName, $contactPhone, $whatsapp, $description, $storeBadge, $vendorId);
                    
                if ($stmt->execute()) {
                    $message = 'Vendor updated successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to update vendor: ' . $conn->error;
                    $messageType = 'error';
                }
                $stmt->close();
                break;
        }
    }
    
    // If AJAX request, return JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
        exit;
    }
}

// ------------------------------------------------------------
// 5. GET FILTERS & PAGINATION
// ------------------------------------------------------------
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 15;
$offset = ($page - 1) * $perPage;

$searchFilter = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$approvalFilter = isset($_GET['approved']) ? trim($_GET['approved']) : '';
$expressFilter = isset($_GET['express']) ? trim($_GET['express']) : '';

// Build WHERE conditions with proper parameterized queries
$whereConditions = [];
$countParams = [];
$countTypes = '';

if (!empty($searchFilter)) {
    $whereConditions[] = "(v.business_name LIKE ? OR v.contact_phone LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $searchParam = '%' . $searchFilter . '%';
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $countTypes .= 'sssss';
}

if (!empty($statusFilter)) {
    $whereConditions[] = "v.status = ?";
    $countParams[] = $statusFilter;
    $countTypes .= 's';
}

if ($approvalFilter !== '') {
    $whereConditions[] = "v.is_approved = ?";
    $countParams[] = ($approvalFilter === 'approved' ? 1 : 0);
    $countTypes .= 'i';
}

if ($expressFilter !== '') {
    $whereConditions[] = "v.is_express = ?";
    $countParams[] = ($expressFilter === 'yes' ? 1 : 0);
    $countTypes .= 'i';
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// ------------------------------------------------------------
// 6. GET TOTAL VENDORS COUNT (Parameterized)
// ------------------------------------------------------------
$countQuery = "SELECT COUNT(*) AS total FROM vendors v 
               LEFT JOIN users u ON v.user_id = u.id 
               $whereClause";

$stmt = $conn->prepare($countQuery);
if (!empty($countParams)) {
    $stmt->bind_param($countTypes, ...$countParams);
}
$stmt->execute();
$countResult = $stmt->get_result();
$totalVendors = (int)$countResult->fetch_assoc()['total'];
$stmt->close();

$totalPages = ceil($totalVendors / $perPage);

// ------------------------------------------------------------
// 7. FETCH VENDORS WITH ALL DB-DRIVEN METRICS
// ------------------------------------------------------------
// Main query parameters (add LIMIT/OFFSET params)
$mainParams = $countParams;
$mainTypes = $countTypes;
$mainParams[] = $perPage;
$mainParams[] = $offset;
$mainTypes .= 'ii';

$vendorsQuery = "SELECT 
                    v.id,
                    v.user_id,
                    v.business_name,
                    v.logo,
                    v.banner,
                    v.description,
                    v.contact_phone,
                    v.whatsapp,
                    v.rating,
                    v.is_approved,
                    v.status,
                    v.is_express,
                    v.store_badge,
                    v.created_at,
                    v.updated_at,
                    u.name AS owner_name,
                    u.email AS owner_email,
                    u.phone AS owner_phone,
                    u.is_active AS owner_active,
                    u.created_at AS owner_joined,
                    -- Product metrics from products table
                    COALESCE(prod_stats.total_products, 0) AS total_products,
                    COALESCE(prod_stats.active_products, 0) AS active_products,
                    COALESCE(prod_stats.inactive_products, 0) AS inactive_products,
                    -- Order metrics from orders table
                    COALESCE(order_stats.total_orders, 0) AS total_orders,
                    COALESCE(order_stats.pending_orders, 0) AS pending_orders,
                    COALESCE(order_stats.processing_orders, 0) AS processing_orders,
                    COALESCE(order_stats.shipped_orders, 0) AS shipped_orders,
                    COALESCE(order_stats.delivered_orders, 0) AS delivered_orders,
                    COALESCE(order_stats.cancelled_orders, 0) AS cancelled_orders,
                    -- Revenue metrics from orders + payments
                    COALESCE(order_stats.total_revenue, 0) AS total_revenue,
                    COALESCE(order_stats.paid_revenue, 0) AS paid_revenue,
                    COALESCE(order_stats.pending_revenue, 0) AS pending_revenue,
                    -- Review/Rating metrics from reviews table
                    COALESCE(review_stats.total_reviews, 0) AS total_reviews,
                    COALESCE(review_stats.avg_product_rating, 0) AS avg_product_rating,
                    COALESCE(review_stats.five_star_count, 0) AS five_star_count,
                    COALESCE(review_stats.four_star_count, 0) AS four_star_count
                FROM vendors v
                LEFT JOIN users u ON v.user_id = u.id
                -- Product statistics subquery
                LEFT JOIN (
                    SELECT 
                        vendor_id,
                        COUNT(*) AS total_products,
                        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_products,
                        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactive_products
                    FROM products
                    GROUP BY vendor_id
                ) prod_stats ON v.id = prod_stats.vendor_id
                -- Order statistics subquery
                LEFT JOIN (
                    SELECT 
                        vendor_id,
                        COUNT(*) AS total_orders,
                        SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) AS pending_orders,
                        SUM(CASE WHEN order_status IN ('confirmed','processing','packed') THEN 1 ELSE 0 END) AS processing_orders,
                        SUM(CASE WHEN order_status = 'shipped' THEN 1 ELSE 0 END) AS shipped_orders,
                        SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) AS delivered_orders,
                        SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
                        COALESCE(SUM(total_amount), 0) AS total_revenue,
                        COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END), 0) AS paid_revenue,
                        COALESCE(SUM(CASE WHEN payment_status IN ('unpaid','partially_paid') THEN total_amount ELSE 0 END), 0) AS pending_revenue
                    FROM orders
                    GROUP BY vendor_id
                ) order_stats ON v.id = order_stats.vendor_id
                -- Review statistics subquery
                LEFT JOIN (
                    SELECT 
                        p.vendor_id,
                        COUNT(r.id) AS total_reviews,
                        COALESCE(AVG(r.rating), 0) AS avg_product_rating,
                        SUM(CASE WHEN r.rating = 5 THEN 1 ELSE 0 END) AS five_star_count,
                        SUM(CASE WHEN r.rating = 4 THEN 1 ELSE 0 END) AS four_star_count
                    FROM reviews r
                    INNER JOIN products p ON r.product_id = p.id
                    GROUP BY p.vendor_id
                ) review_stats ON v.id = review_stats.vendor_id
                $whereClause
                ORDER BY v.created_at DESC
                LIMIT ? OFFSET ?";

$stmt = $conn->prepare($vendorsQuery);
$stmt->bind_param($mainTypes, ...$mainParams);
$stmt->execute();
$vendorsResult = $stmt->get_result();

$vendors = [];
if ($vendorsResult && $vendorsResult->num_rows > 0) {
    while ($row = $vendorsResult->fetch_assoc()) {
        $vendors[] = $row;
    }
}
$stmt->close();

// ------------------------------------------------------------
// 8. GET PLATFORM-WIDE STATISTICS (Direct from DB)
// ------------------------------------------------------------
$statsQuery = "SELECT 
                COUNT(*) AS total_vendors,
                SUM(CASE WHEN is_approved = 1 THEN 1 ELSE 0 END) AS approved_vendors,
                SUM(CASE WHEN is_approved = 0 THEN 1 ELSE 0 END) AS pending_vendors,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_vendors,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_vendors,
                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) AS suspended_vendors,
                SUM(CASE WHEN is_express = 1 THEN 1 ELSE 0 END) AS express_vendors
              FROM vendors";
$statsResult = $conn->query($statsQuery);
$stats = $statsResult->fetch_assoc();

// Get total platform products count
$totalProductsQuery = "SELECT COUNT(*) AS total FROM products WHERE is_active = 1";
$totalProductsResult = $conn->query($totalProductsQuery);
$totalPlatformProducts = $totalProductsResult->fetch_assoc()['total'];

// Get total platform orders count
$totalOrdersQuery = "SELECT 
                        COUNT(*) AS total_orders,
                        COALESCE(SUM(total_amount), 0) AS total_revenue,
                        COUNT(DISTINCT user_id) AS total_customers
                     FROM orders";
$totalOrdersResult = $conn->query($totalOrdersQuery);
$platformOrderStats = $totalOrdersResult->fetch_assoc();

// Get total reviews count
$totalReviewsQuery = "SELECT COUNT(*) AS total FROM reviews";
$totalReviewsResult = $conn->query($totalReviewsQuery);
$totalPlatformReviews = $totalReviewsResult->fetch_assoc()['total'];

// ------------------------------------------------------------
// 9. PAGE METADATA
// ------------------------------------------------------------
$page_title = 'Vendor Management - Bless Mall Admin';
$current_page = 'vendors.php';

// Include admin header
require_once __DIR__ . '/header.php';
?>

<!-- ==================== PAGE HEADER ==================== -->
<div class="admin-page-header">
    <h1>
        <i class="fas fa-store-alt"></i> Vendor Management
    </h1>
    <p>Manage all vendors, approve applications, and monitor performance metrics</p>
</div>

<!-- ==================== BREADCRUMB ==================== -->
<div class="admin-breadcrumb">
    <a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <span class="separator"><i class="fas fa-chevron-right"></i></span>
    <span class="current">Vendors</span>
</div>

<!-- ==================== MESSAGE ALERTS ==================== -->
<?php if (!empty($message)): ?>
<div class="alert alert-<?php echo $messageType; ?>" id="alertMessage">
    <div class="alert-icon">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    </div>
    <div class="alert-content">
        <span><?php echo htmlspecialchars($message); ?></span>
    </div>
    <button class="alert-close" onclick="this.parentElement.remove()">
        <i class="fas fa-times"></i>
    </button>
</div>
<?php endif; ?>

<!-- ==================== STATS CARDS ==================== -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1); color: #3B82F6;">
            <i class="fas fa-store"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?php echo number_format($stats['total_vendors']); ?></div>
            <div class="stat-label">Total Vendors</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: #10B981;">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?php echo number_format($stats['approved_vendors']); ?></div>
            <div class="stat-label">Approved</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: #F59E0B;">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?php echo number_format($stats['pending_vendors']); ?></div>
            <div class="stat-label">Pending Approval</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(6, 182, 212, 0.1); color: #06B6D4;">
            <i class="fas fa-bolt"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?php echo number_format($stats['express_vendors']); ?></div>
            <div class="stat-label">Express Vendors</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(139, 92, 246, 0.1); color: #8B5CF6;">
            <i class="fas fa-boxes"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?php echo number_format($totalPlatformProducts); ?></div>
            <div class="stat-label">Total Products</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(236, 72, 153, 0.1); color: #EC4899;">
            <i class="fas fa-shopping-cart"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?php echo number_format($platformOrderStats['total_orders']); ?></div>
            <div class="stat-label">Total Orders</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(34, 197, 94, 0.1); color: #22C55E;">
            <i class="fas fa-cedi-sign"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?php echo formatPrice($platformOrderStats['total_revenue']); ?></div>
            <div class="stat-label">Platform Revenue</div>
        </div>
    </div>
</div>

<!-- ==================== FILTER BAR ==================== -->
<div class="filter-bar">
    <form method="get" action="vendors.php" class="filter-form" id="filterForm">
        <!-- Search -->
        <div class="search-group">
            <div class="search-input-wrapper">
                <i class="fas fa-search"></i>
                <input type="text" name="search" placeholder="Search by name, phone, email..." 
                       value="<?php echo htmlspecialchars($searchFilter); ?>"
                       class="search-input"
                       id="searchInput">
                <?php if (!empty($searchFilter)): ?>
                <button type="button" class="search-clear" onclick="clearSearch()">
                    <i class="fas fa-times"></i>
                </button>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Status Filter -->
        <div class="filter-select-group">
            <select name="status" class="filter-select" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active (<?php echo $stats['active_vendors']; ?>)</option>
                <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive (<?php echo $stats['inactive_vendors']; ?>)</option>
                <option value="suspended" <?php echo $statusFilter === 'suspended' ? 'selected' : ''; ?>>Suspended (<?php echo $stats['suspended_vendors']; ?>)</option>
            </select>
        </div>
        
        <!-- Approval Filter -->
        <div class="filter-select-group">
            <select name="approved" class="filter-select" onchange="this.form.submit()">
                <option value="">All Approvals</option>
                <option value="approved" <?php echo $approvalFilter === 'approved' ? 'selected' : ''; ?>>Approved (<?php echo $stats['approved_vendors']; ?>)</option>
                <option value="pending" <?php echo $approvalFilter === 'pending' ? 'selected' : ''; ?>>Pending (<?php echo $stats['pending_vendors']; ?>)</option>
            </select>
        </div>
        
        <!-- Express Filter -->
        <div class="filter-select-group">
            <select name="express" class="filter-select" onchange="this.form.submit()">
                <option value="">All Delivery Types</option>
                <option value="yes" <?php echo $expressFilter === 'yes' ? 'selected' : ''; ?>>Express Only</option>
                <option value="no" <?php echo $expressFilter === 'no' ? 'selected' : ''; ?>>Standard Only</option>
            </select>
        </div>
        
        <!-- Reset -->
        <?php if (!empty($searchFilter) || !empty($statusFilter) || $approvalFilter !== '' || $expressFilter !== ''): ?>
        <a href="vendors.php" class="btn-reset-filter">
            <i class="fas fa-times"></i> Clear Filters
        </a>
        <?php endif; ?>
        
        <!-- Showing results info -->
        <span class="results-info">
            Showing <strong><?php echo min($totalVendors, $offset + 1); ?>-<?php echo min($totalVendors, $offset + $perPage); ?></strong> of <strong><?php echo number_format($totalVendors); ?></strong> vendors
        </span>
    </form>
</div>

<!-- ==================== VENDORS TABLE ==================== -->
<div class="table-container">
    <?php if (!empty($vendors)): ?>
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Vendor</th>
                    <th>Owner</th>
                    <th>Products</th>
                    <th>Orders</th>
                    <th>Revenue (GHS)</th>
                    <th>Rating</th>
                    <th>Status</th>
                    <th>Express</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vendors as $vendor): 
                    $vendorId = (int)$vendor['id'];
                    $businessName = htmlspecialchars($vendor['business_name']);
                    $ownerName = htmlspecialchars($vendor['owner_name'] ?? 'N/A');
                    $ownerEmail = htmlspecialchars($vendor['owner_email'] ?? '');
                    $totalProducts = (int)$vendor['total_products'];
                    $activeProducts = (int)$vendor['active_products'];
                    $totalOrders = (int)$vendor['total_orders'];
                    $deliveredOrders = (int)$vendor['delivered_orders'];
                    $pendingOrders = (int)$vendor['pending_orders'];
                    $totalRevenue = (float)$vendor['total_revenue'];
                    $paidRevenue = (float)$vendor['paid_revenue'];
                    $rating = round((float)$vendor['rating'], 1);
                    $avgProductRating = round((float)$vendor['avg_product_rating'], 1);
                    $totalReviews = (int)$vendor['total_reviews'];
                    $isApproved = (bool)$vendor['is_approved'];
                    $isExpress = (bool)$vendor['is_express'];
                    $status = $vendor['status'];
                    $storeBadge = htmlspecialchars($vendor['store_badge'] ?? '');
                    $logo = $vendor['logo'];
                    $createdAt = date('M d, Y', strtotime($vendor['created_at']));
                    
                    // Status badge classes
                    $statusClass = match($status) {
                        'active' => 'badge-success',
                        'inactive' => 'badge-warning',
                        'suspended' => 'badge-danger',
                        default => 'badge-default'
                    };
                    
                    // Revenue trend indicator
                    $revenueClass = $paidRevenue > 0 ? 'revenue-positive' : 'revenue-neutral';
                ?>
                <tr data-vendor-id="<?php echo $vendorId; ?>">
                    <!-- Vendor Info -->
                    <td>
                        <div class="vendor-info-cell">
                            <div class="vendor-logo">
                                <?php if (!empty($logo) && file_exists(BASE_PATH . ltrim($logo, '/'))): ?>
                                    <img src="<?php echo BASE_URL . ltrim($logo, '/'); ?>" alt="<?php echo $businessName; ?>" loading="lazy">
                                <?php else: ?>
                                    <div class="vendor-logo-placeholder">
                                        <?php echo strtoupper(substr($businessName, 0, 2)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="vendor-details">
                                <span class="vendor-name"><?php echo $businessName; ?></span>
                                <?php if (!empty($storeBadge)): ?>
                                    <span class="vendor-badge"><?php echo $storeBadge; ?></span>
                                <?php endif; ?>
                                <span class="vendor-meta">ID: #<?php echo $vendorId; ?> • Since <?php echo $createdAt; ?></span>
                            </div>
                        </div>
                    </td>
                    
                    <!-- Owner Info -->
                    <td>
                        <div class="owner-info">
                            <span class="owner-name"><?php echo $ownerName; ?></span>
                            <?php if (!empty($ownerEmail)): ?>
                                <span class="owner-email" title="<?php echo $ownerEmail; ?>">
                                    <i class="fas fa-envelope"></i> <?php echo $ownerEmail; ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($vendor['contact_phone'])): ?>
                                <span class="owner-phone">
                                    <i class="fas fa-phone"></i> <?php echo htmlspecialchars($vendor['contact_phone']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!$vendor['owner_active']): ?>
                                <span class="badge badge-danger badge-sm">Owner Inactive</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    
                    <!-- Products -->
                    <td>
                        <div class="metric-cell">
                            <span class="metric-value"><?php echo $activeProducts; ?></span>
                            <span class="metric-label">Active / <?php echo $totalProducts; ?> Total</span>
                            <?php if ($totalProducts > 0): ?>
                            <div class="mini-progress">
                                <div class="mini-progress-fill" style="width: <?php echo $totalProducts > 0 ? round(($activeProducts / $totalProducts) * 100) : 0; ?>%"></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </td>
                    
                    <!-- Orders -->
                    <td>
                        <div class="metric-cell">
                            <span class="metric-value"><?php echo $totalOrders; ?></span>
                            <span class="metric-label">
                                <?php echo $deliveredOrders; ?> delivered • <?php echo $pendingOrders; ?> pending
                            </span>
                        </div>
                    </td>
                    
                    <!-- Revenue -->
                    <td>
                        <div class="revenue-cell">
                            <span class="revenue-value <?php echo $revenueClass; ?>">
                                <?php echo formatPrice($paidRevenue); ?>
                            </span>
                            <?php if ($totalRevenue > $paidRevenue): ?>
                            <span class="revenue-pending" title="Pending Revenue">
                                <?php echo formatPrice($totalRevenue - $paidRevenue); ?> pending
                            </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    
                    <!-- Rating -->
                    <td>
                        <div class="rating-cell">
                            <div class="stars-display">
                                <?php 
                                $displayRating = $avgProductRating > 0 ? $avgProductRating : $rating;
                                for ($i = 1; $i <= 5; $i++): 
                                    $starClass = 'far fa-star text-muted';
                                    if ($i <= floor($displayRating)) {
                                        $starClass = 'fas fa-star';
                                    } elseif ($i - 0.5 <= $displayRating) {
                                        $starClass = 'fas fa-star-half-alt';
                                    }
                                ?>
                                    <i class="<?php echo $starClass; ?>" style="color: <?php echo $i <= $displayRating ? '#F59E0B' : '#475569'; ?>; font-size: 0.7rem;"></i>
                                <?php endfor; ?>
                            </div>
                            <span class="rating-text">
                                <?php echo number_format($displayRating, 1); ?> 
                                (<?php echo $totalReviews; ?> reviews)
                            </span>
                        </div>
                    </td>
                    
                    <!-- Status -->
                    <td>
                        <div class="status-toggle-group">
                            <span class="badge <?php echo $statusClass; ?>">
                                <i class="fas fa-<?php echo $status === 'active' ? 'check' : ($status === 'suspended' ? 'ban' : 'clock'); ?>"></i>
                                <?php echo ucfirst($status); ?>
                            </span>
                            <?php if (!$isApproved): ?>
                                <span class="badge badge-warning badge-sm">
                                    <i class="fas fa-clock"></i> Pending Approval
                                </span>
                            <?php endif; ?>
                            <?php if ($isExpress): ?>
                                <span class="badge badge-info badge-sm">
                                    <i class="fas fa-bolt"></i> Express
                                </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    
                    <!-- Express Toggle -->
                    <td>
                        <label class="toggle-switch" title="<?php echo $isExpress ? 'Disable Express' : 'Enable Express'; ?>">
                            <input type="checkbox" 
                                   <?php echo $isExpress ? 'checked' : ''; ?>
                                   onchange="toggleExpress(<?php echo $vendorId; ?>, this.checked)"
                                   class="toggle-input">
                            <span class="toggle-slider"></span>
                        </label>
                    </td>
                    
                    <!-- Actions -->
                    <td>
                        <div class="action-dropdown">
                            <button class="btn-action-toggle" onclick="toggleActionMenu(event, <?php echo $vendorId; ?>)" title="Actions">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <div class="action-menu" id="actionMenu<?php echo $vendorId; ?>">
                                <a href="#" onclick="viewVendorDetails(<?php echo $vendorId; ?>); return false;" class="action-item">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                                <a href="../shop.php?vendor=<?php echo $vendorId; ?>" target="_blank" class="action-item">
                                    <i class="fas fa-external-link-alt"></i> View Store
                                </a>
                                <a href="products.php?vendor=<?php echo $vendorId; ?>" class="action-item">
                                    <i class="fas fa-box"></i> View Products (<?php echo $totalProducts; ?>)
                                </a>
                                <a href="orders.php?vendor=<?php echo $vendorId; ?>" class="action-item">
                                    <i class="fas fa-shopping-cart"></i> View Orders (<?php echo $totalOrders; ?>)
                                </a>
                                <div class="action-divider"></div>
                                
                                <?php if (!$isApproved): ?>
                                <button onclick="toggleApproval(<?php echo $vendorId; ?>, 1)" class="action-item action-approve">
                                    <i class="fas fa-check"></i> Approve Vendor
                                </button>
                                <?php else: ?>
                                <button onclick="toggleApproval(<?php echo $vendorId; ?>, 0)" class="action-item action-revoke">
                                    <i class="fas fa-undo"></i> Revoke Approval
                                </button>
                                <?php endif; ?>
                                
                                <?php if ($status !== 'active'): ?>
                                <button onclick="changeStatus(<?php echo $vendorId; ?>, 'active')" class="action-item action-activate">
                                    <i class="fas fa-play"></i> Activate Vendor
                                </button>
                                <?php endif; ?>
                                
                                <?php if ($status !== 'suspended'): ?>
                                <button onclick="changeStatus(<?php echo $vendorId; ?>, 'suspended')" class="action-item action-suspend">
                                    <i class="fas fa-pause"></i> Suspend Vendor
                                </button>
                                <?php endif; ?>
                                
                                <?php if ($status !== 'inactive'): ?>
                                <button onclick="changeStatus(<?php echo $vendorId; ?>, 'inactive')" class="action-item action-deactivate">
                                    <i class="fas fa-stop"></i> Deactivate Vendor
                                </button>
                                <?php endif; ?>
                                
                                <div class="action-divider"></div>
                                <button onclick="editVendor(<?php echo $vendorId; ?>)" class="action-item">
                                    <i class="fas fa-edit"></i> Edit Details
                                </button>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Table Footer with Summary -->
    <div class="table-footer">
        <div class="table-footer-info">
            <span>Total vendors on this page: <strong><?php echo count($vendors); ?></strong></span>
            <span>Total products: <strong><?php echo array_sum(array_column($vendors, 'total_products')); ?></strong></span>
            <span>Total orders: <strong><?php echo array_sum(array_column($vendors, 'total_orders')); ?></strong></span>
            <span>Total revenue: <strong><?php echo formatPrice(array_sum(array_column($vendors, 'paid_revenue'))); ?></strong></span>
        </div>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php 
        $queryParams = $_GET;
        unset($queryParams['page']);
        $baseQuery = http_build_query($queryParams);
        $baseUrl = 'vendors.php' . ($baseQuery ? '?' . $baseQuery . '&' : '?');
        ?>
        
        <?php if ($page > 1): ?>
            <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>" class="page-link" title="Previous page">
                <i class="fas fa-chevron-left"></i>
            </a>
        <?php else: ?>
            <span class="page-link disabled"><i class="fas fa-chevron-left"></i></span>
        <?php endif; ?>
        
        <?php
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        
        if ($startPage > 1) {
            echo '<a href="' . $baseUrl . 'page=1" class="page-link">1</a>';
            if ($startPage > 2) echo '<span class="page-link disabled">...</span>';
        }
        
        for ($i = $startPage; $i <= $endPage; $i++):
        ?>
            <a href="<?php echo $baseUrl; ?>page=<?php echo $i; ?>" 
               class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </a>
        <?php endfor;
        
        if ($endPage < $totalPages) {
            if ($endPage < $totalPages - 1) echo '<span class="page-link disabled">...</span>';
            echo '<a href="' . $baseUrl . 'page=' . $totalPages . '" class="page-link">' . $totalPages . '</a>';
        }
        ?>
        
        <?php if ($page < $totalPages): ?>
            <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>" class="page-link" title="Next page">
                <i class="fas fa-chevron-right"></i>
            </a>
        <?php else: ?>
            <span class="page-link disabled"><i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
        
        <span class="page-info">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
    </div>
    <?php endif; ?>
    
    <?php else: ?>
    <!-- Empty State -->
    <div class="empty-state">
        <div class="empty-icon">
            <i class="fas fa-store-alt"></i>
        </div>
        <h3>No Vendors Found</h3>
        <p><?php echo !empty($searchFilter) || !empty($statusFilter) || $approvalFilter !== '' ? 'No vendors match your current filters. Try adjusting your search criteria.' : 'No vendors have been registered yet. Vendors will appear here once they sign up.'; ?></p>
        <?php if (!empty($searchFilter) || !empty($statusFilter) || $approvalFilter !== '' || $expressFilter !== ''): ?>
            <a href="vendors.php" class="btn btn-primary">
                <i class="fas fa-times"></i> Clear All Filters
            </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ==================== VENDOR DETAILS MODAL ==================== -->
<div id="vendorModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 id="modalTitle">Vendor Details</h3>
            <button class="modal-close" onclick="closeModal('vendorModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" id="vendorModalBody">
            <div class="modal-loading">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Loading vendor details...</p>
            </div>
        </div>
    </div>
</div>

<!-- ==================== EDIT VENDOR MODAL ==================== -->
<div id="editVendorModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit Vendor Details</h3>
            <button class="modal-close" onclick="closeModal('editVendorModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <form id="editVendorForm" method="POST" action="vendors.php">
                <input type="hidden" name="action" value="update_vendor">
                <input type="hidden" name="vendor_id" id="editVendorId">
                <?php echo CSRFToken::field(); ?>
                
                <div class="form-group">
                    <label>Business Name <span class="required">*</span></label>
                    <input type="text" name="business_name" id="editBusinessName" class="form-input" required 
                           placeholder="Enter business name">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Contact Phone</label>
                        <input type="text" name="contact_phone" id="editContactPhone" class="form-input" 
                               placeholder="e.g., 024XXXXXXX">
                    </div>
                    <div class="form-group">
                        <label>WhatsApp Number</label>
                        <input type="text" name="whatsapp" id="editWhatsapp" class="form-input" 
                               placeholder="e.g., 024XXXXXXX">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Store Badge</label>
                    <input type="text" name="store_badge" id="editStoreBadge" class="form-input" 
                           placeholder="e.g., Top Rated, Premium Seller, New Store">
                    <small class="form-hint">Displayed as a badge next to the vendor name</small>
                </div>
                
                <div class="form-group">
                    <label>Store Description</label>
                    <textarea name="description" id="editDescription" class="form-input" rows="4" 
                              placeholder="Brief description of the vendor's store"></textarea>
                </div>
                
                <div class="modal-footer-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editVendorModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==================== CONFIRMATION MODAL ==================== -->
<div id="confirmModal" class="modal">
    <div class="modal-content modal-sm">
        <div class="modal-body text-center">
            <div class="confirm-icon" id="confirmIcon">
                <i class="fas fa-question-circle"></i>
            </div>
            <h4 id="confirmTitle">Confirm Action</h4>
            <p id="confirmMessage">Are you sure you want to proceed with this action?</p>
        </div>
        <div class="modal-footer-actions">
            <button class="btn btn-secondary" onclick="closeModal('confirmModal')">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button class="btn btn-primary" id="confirmActionBtn">
                <i class="fas fa-check"></i> Confirm
            </button>
        </div>
    </div>
</div>

<!-- ==================== STYLES ==================== -->
<style>
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
        padding: 1.25rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        transition: var(--transition-fast);
    }
    
    .stat-card:hover {
        border-color: var(--admin-primary);
        box-shadow: var(--shadow-glow);
        transform: translateY(-2px);
    }
    
    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: var(--radius-sm);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        flex-shrink: 0;
    }
    
    .stat-info {
        display: flex;
        flex-direction: column;
    }
    
    .stat-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text-primary);
        line-height: 1.2;
    }
    
    .stat-label {
        font-size: 0.7rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: 0.2rem;
    }
    
    /* Filter Bar */
    .filter-bar {
        background: var(--admin-surface);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-md);
        padding: 1rem 1.25rem;
        margin-bottom: 1.5rem;
    }
    
    .filter-form {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    
    .search-group {
        flex: 1;
        min-width: 220px;
    }
    
    .search-input-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }
    
    .search-input-wrapper > i {
        position: absolute;
        left: 12px;
        color: var(--text-muted);
        font-size: 0.85rem;
        z-index: 1;
    }
    
    .search-input {
        width: 100%;
        padding: 0.6rem 2.5rem 0.6rem 2.2rem;
        background: var(--admin-bg);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-sm);
        color: var(--text-primary);
        font-family: var(--font-body);
        font-size: 0.85rem;
        transition: var(--transition-fast);
    }
    
    .search-input:focus {
        outline: none;
        border-color: var(--admin-primary);
        box-shadow: 0 0 0 3px var(--admin-primary-glow);
    }
    
    .search-input::placeholder {
        color: var(--text-muted);
    }
    
    .search-clear {
        position: absolute;
        right: 8px;
        background: none;
        border: none;
        color: var(--text-muted);
        cursor: pointer;
        padding: 4px;
        font-size: 0.8rem;
        transition: var(--transition-fast);
    }
    
    .search-clear:hover {
        color: var(--admin-danger);
    }
    
    .filter-select-group {
        min-width: 150px;
    }
    
    .filter-select {
        width: 100%;
        padding: 0.6rem 2rem 0.6rem 0.75rem;
        background: var(--admin-bg);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-sm);
        color: var(--text-primary);
        font-family: var(--font-body);
        font-size: 0.85rem;
        cursor: pointer;
        transition: var(--transition-fast);
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2364748B' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 0.5rem center;
    }
    
    .filter-select:focus {
        outline: none;
        border-color: var(--admin-primary);
    }
    
    .btn-reset-filter {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.6rem 1rem;
        background: var(--admin-danger-bg);
        color: var(--admin-danger);
        border-radius: var(--radius-sm);
        text-decoration: none;
        font-size: 0.85rem;
        font-weight: 500;
        transition: var(--transition-fast);
        white-space: nowrap;
        border: 1px solid transparent;
    }
    
    .btn-reset-filter:hover {
        background: rgba(239, 68, 68, 0.2);
        color: #FCA5A5;
        border-color: rgba(239, 68, 68, 0.3);
    }
    
    .results-info {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-left: auto;
        white-space: nowrap;
    }
    
    .results-info strong {
        color: var(--text-primary);
    }
    
    /* Table Container */
    .table-container {
        background: var(--admin-surface);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-md);
        overflow: hidden;
    }
    
    .table-responsive {
        overflow-x: auto;
    }
    
    .admin-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .admin-table thead {
        background: var(--admin-bg);
        position: sticky;
        top: 0;
        z-index: 1;
    }
    
    .admin-table th {
        padding: 0.85rem 1rem;
        text-align: left;
        font-size: 0.7rem;
        font-weight: 700;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 1px;
        white-space: nowrap;
        border-bottom: 2px solid var(--admin-border);
    }
    
    .admin-table td {
        padding: 0.85rem 1rem;
        border-bottom: 1px solid var(--admin-border);
        color: var(--text-secondary);
        font-size: 0.85rem;
        vertical-align: middle;
    }
    
    .admin-table tbody tr {
        transition: var(--transition-fast);
    }
    
    .admin-table tbody tr:hover {
        background: var(--admin-surface-hover);
    }
    
    .admin-table tbody tr:last-child td {
        border-bottom: none;
    }
    
    /* Vendor Info Cell */
    .vendor-info-cell {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        min-width: 200px;
    }
    
    .vendor-logo {
        width: 42px;
        height: 42px;
        border-radius: var(--radius-sm);
        overflow: hidden;
        flex-shrink: 0;
        border: 1px solid var(--admin-border);
    }
    
    .vendor-logo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .vendor-logo-placeholder {
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, var(--admin-primary), #8B5CF6);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 700;
        font-size: 0.9rem;
    }
    
    .vendor-details {
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
    }
    
    .vendor-name {
        font-weight: 600;
        color: var(--text-primary);
        font-size: 0.9rem;
    }
    
    .vendor-badge {
        display: inline-block;
        background: var(--admin-primary-glow);
        color: var(--admin-primary);
        padding: 0.1rem 0.5rem;
        border-radius: var(--radius-full);
        font-size: 0.65rem;
        font-weight: 600;
        width: fit-content;
    }
    
    .vendor-meta {
        font-size: 0.7rem;
        color: var(--text-muted);
        font-family: var(--font-mono);
    }
    
    /* Owner Info */
    .owner-info {
        display: flex;
        flex-direction: column;
        gap: 0.2rem;
        min-width: 150px;
    }
    
    .owner-name {
        font-weight: 500;
        color: var(--text-primary);
    }
    
    .owner-email,
    .owner-phone {
        font-size: 0.7rem;
        color: var(--text-muted);
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }
    
    /* Metric Cell */
    .metric-cell {
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
    }
    
    .metric-value {
        font-weight: 700;
        color: var(--text-primary);
        font-size: 1rem;
    }
    
    .metric-label {
        font-size: 0.7rem;
        color: var(--text-muted);
    }
    
    .mini-progress {
        height: 3px;
        background: var(--admin-border);
        border-radius: 2px;
        margin-top: 0.3rem;
        overflow: hidden;
    }
    
    .mini-progress-fill {
        height: 100%;
        background: var(--admin-success);
        border-radius: 2px;
        transition: width 0.5s ease;
    }
    
    /* Revenue Cell */
    .revenue-cell {
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
    }
    
    .revenue-value {
        font-weight: 600;
        font-family: var(--font-mono);
        font-size: 0.9rem;
    }
    
    .revenue-positive {
        color: var(--admin-success);
    }
    
    .revenue-neutral {
        color: var(--text-muted);
    }
    
    .revenue-pending {
        font-size: 0.65rem;
        color: var(--admin-warning);
        cursor: help;
    }
    
    /* Rating */
    .rating-cell {
        display: flex;
        flex-direction: column;
        gap: 0.2rem;
    }
    
    .stars-display {
        display: flex;
        gap: 2px;
    }
    
    .stars-display .text-muted {
        color: var(--admin-border-light) !important;
    }
    
    .rating-text {
        font-size: 0.7rem;
        color: var(--text-muted);
    }
    
    /* Status Toggle */
    .status-toggle-group {
        display: flex;
        flex-direction: column;
        gap: 0.3rem;
        align-items: flex-start;
    }
    
    /* Badges */
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.25rem 0.75rem;
        border-radius: var(--radius-full);
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: capitalize;
    }
    
    .badge-sm {
        padding: 0.15rem 0.5rem;
        font-size: 0.65rem;
    }
    
    .badge-success {
        background: var(--admin-success-bg);
        color: var(--admin-success);
    }
    
    .badge-warning {
        background: var(--admin-warning-bg);
        color: var(--admin-warning);
    }
    
    .badge-danger {
        background: var(--admin-danger-bg);
        color: var(--admin-danger);
    }
    
    .badge-info {
        background: rgba(6, 182, 212, 0.1);
        color: #06B6D4;
    }
    
    .badge-default {
        background: rgba(100, 116, 139, 0.1);
        color: #94A3B8;
    }
    
    /* Toggle Switch */
    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 44px;
        height: 24px;
        cursor: pointer;
    }
    
    .toggle-input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    
    .toggle-slider {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: var(--admin-border-light);
        border-radius: var(--radius-full);
        transition: var(--transition-fast);
    }
    
    .toggle-slider::before {
        content: '';
        position: absolute;
        height: 18px;
        width: 18px;
        left: 3px;
        bottom: 3px;
        background: white;
        border-radius: 50%;
        transition: var(--transition-fast);
        box-shadow: var(--shadow-sm);
    }
    
    .toggle-input:checked + .toggle-slider {
        background: var(--admin-success);
    }
    
    .toggle-input:checked + .toggle-slider::before {
        transform: translateX(20px);
    }
    
    /* Action Dropdown */
    .action-dropdown {
        position: relative;
    }
    
    .btn-action-toggle {
        width: 32px;
        height: 32px;
        border: 1px solid var(--admin-border);
        background: var(--admin-bg);
        border-radius: var(--radius-sm);
        color: var(--text-muted);
        cursor: pointer;
        transition: var(--transition-fast);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
    }
    
    .btn-action-toggle:hover {
        background: var(--admin-surface-hover);
        color: var(--text-primary);
        border-color: var(--admin-primary);
    }
    
    .action-menu {
        display: none;
        position: absolute;
        right: 0;
        top: 100%;
        min-width: 220px;
        background: var(--admin-surface);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-md);
        box-shadow: var(--shadow-lg);
        z-index: 100;
        padding: 0.5rem 0;
        margin-top: 4px;
    }
    
    .action-menu.show {
        display: block;
        animation: fadeInDown 0.2s ease;
    }
    
    @keyframes fadeInDown {
        from { opacity: 0; transform: translateY(-8px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .action-item {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        padding: 0.6rem 1rem;
        color: var(--text-secondary);
        text-decoration: none;
        font-size: 0.8rem;
        transition: var(--transition-fast);
        border: none;
        background: none;
        width: 100%;
        cursor: pointer;
        font-family: var(--font-body);
        text-align: left;
        white-space: nowrap;
    }
    
    .action-item i {
        width: 18px;
        text-align: center;
        color: var(--text-muted);
        font-size: 0.85rem;
    }
    
    .action-item:hover {
        background: var(--admin-surface-hover);
        color: var(--text-primary);
    }
    
    .action-item:hover i {
        color: var(--admin-primary);
    }
    
    .action-divider {
        height: 1px;
        background: var(--admin-border);
        margin: 0.25rem 0;
    }
    
    .action-approve,
    .action-approve:hover { color: var(--admin-success) !important; }
    .action-approve i,
    .action-approve:hover i { color: var(--admin-success) !important; }
    
    .action-revoke,
    .action-revoke:hover { color: var(--admin-warning) !important; }
    .action-revoke i,
    .action-revoke:hover i { color: var(--admin-warning) !important; }
    
    .action-activate,
    .action-activate:hover { color: var(--admin-primary) !important; }
    .action-activate i,
    .action-activate:hover i { color: var(--admin-primary) !important; }
    
    .action-suspend,
    .action-suspend:hover { color: #F97316 !important; }
    .action-suspend i,
    .action-suspend:hover i { color: #F97316 !important; }
    
    .action-deactivate,
    .action-deactivate:hover { color: var(--admin-danger) !important; }
    .action-deactivate i,
    .action-deactivate:hover i { color: var(--admin-danger) !important; }
    
    /* Table Footer */
    .table-footer {
        padding: 0.75rem 1rem;
        background: var(--admin-bg);
        border-top: 1px solid var(--admin-border);
    }
    
    .table-footer-info {
        display: flex;
        gap: 1.5rem;
        flex-wrap: wrap;
        font-size: 0.75rem;
        color: var(--text-muted);
    }
    
    .table-footer-info strong {
        color: var(--text-primary);
    }
    
    /* Modal Loading */
    .modal-loading {
        text-align: center;
        padding: 3rem;
        color: var(--text-muted);
    }
    
    .modal-loading i {
        font-size: 2rem;
        color: var(--admin-primary);
        margin-bottom: 1rem;
        display: block;
    }
    
    /* Form Hint */
    .form-hint {
        display: block;
        font-size: 0.7rem;
        color: var(--text-muted);
        margin-top: 0.3rem;
    }
    
    /* Modal Styles - Enhanced */
    .modal {
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
        backdrop-filter: blur(3px);
    }
    
    .modal.active {
        display: flex;
    }
    
    .modal-content {
        background: var(--admin-surface);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-lg);
        width: 90%;
        max-height: 85vh;
        overflow-y: auto;
        animation: modalSlideIn 0.3s ease;
    }
    
    .modal-lg {
        max-width: 750px;
    }
    
    .modal-sm {
        max-width: 420px;
    }
    
    .modal-content {
        max-width: 550px;
    }
    
    @keyframes modalSlideIn {
        from { opacity: 0; transform: translateY(-30px) scale(0.95); }
        to { opacity: 1; transform: translateY(0) scale(1); }
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
        z-index: 2;
    }
    
    .modal-header h3 {
        font-family: var(--font-heading);
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .modal-close {
        width: 32px;
        height: 32px;
        border: 1px solid var(--admin-border);
        background: var(--admin-bg);
        border-radius: var(--radius-sm);
        color: var(--text-muted);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: var(--transition-fast);
        font-size: 0.9rem;
    }
    
    .modal-close:hover {
        background: var(--admin-danger-bg);
        color: var(--admin-danger);
        border-color: var(--admin-danger);
    }
    
    .modal-body {
        padding: 1.5rem;
    }
    
    .modal-footer-actions {
        display: flex;
        gap: 0.75rem;
        justify-content: flex-end;
        margin-top: 1.5rem;
        padding-top: 1rem;
        border-top: 1px solid var(--admin-border);
    }
    
    /* Form Styles */
    .form-group {
        margin-bottom: 1.25rem;
    }
    
    .form-group label {
        display: block;
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.4rem;
    }
    
    .form-group .required {
        color: var(--admin-danger);
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }
    
    .form-input {
        width: 100%;
        padding: 0.65rem 0.85rem;
        background: var(--admin-bg);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-sm);
        color: var(--text-primary);
        font-family: var(--font-body);
        font-size: 0.85rem;
        transition: var(--transition-fast);
    }
    
    .form-input:focus {
        outline: none;
        border-color: var(--admin-primary);
        box-shadow: 0 0 0 3px var(--admin-primary-glow);
    }
    
    .form-input::placeholder {
        color: var(--text-muted);
    }
    
    textarea.form-input {
        resize: vertical;
        min-height: 80px;
    }
    
    /* Buttons */
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.65rem 1.25rem;
        border-radius: var(--radius-sm);
        font-weight: 600;
        font-size: 0.85rem;
        cursor: pointer;
        transition: var(--transition-fast);
        border: none;
        font-family: var(--font-body);
        text-decoration: none;
    }
    
    .btn-primary {
        background: var(--admin-primary);
        color: white;
    }
    
    .btn-primary:hover {
        background: var(--admin-primary-dark);
        box-shadow: var(--shadow-glow);
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
    
    /* Confirm Icon */
    .confirm-icon {
        font-size: 3rem;
        color: var(--admin-warning);
        margin-bottom: 1rem;
    }
    
    .text-center {
        text-align: center;
    }
    
    /* Alert Styles */
    .alert {
        padding: 1rem 1.25rem;
        border-radius: var(--radius-md);
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        animation: slideDown 0.3s ease;
    }
    
    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .alert-success {
        background: var(--admin-success-bg);
        border: 1px solid rgba(16, 185, 129, 0.3);
        color: var(--admin-success);
    }
    
    .alert-error {
        background: var(--admin-danger-bg);
        border: 1px solid rgba(239, 68, 68, 0.3);
        color: var(--admin-danger);
    }
    
    .alert-icon {
        font-size: 1.2rem;
        flex-shrink: 0;
    }
    
    .alert-content {
        flex: 1;
        font-size: 0.85rem;
    }
    
    .alert-close {
        background: none;
        border: none;
        color: inherit;
        opacity: 0.6;
        cursor: pointer;
        padding: 0.25rem;
        font-size: 0.85rem;
        transition: var(--transition-fast);
    }
    
    .alert-close:hover {
        opacity: 1;
    }
    
    /* Pagination */
    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 0.5rem;
        padding: 1.25rem;
        border-top: 1px solid var(--admin-border);
        flex-wrap: wrap;
    }
    
    .page-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 36px;
        height: 36px;
        padding: 0 10px;
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-sm);
        color: var(--text-secondary);
        text-decoration: none;
        font-size: 0.85rem;
        font-weight: 500;
        transition: var(--transition-fast);
        background: var(--admin-bg);
    }
    
    .page-link:hover {
        background: var(--admin-surface-hover);
        color: var(--text-primary);
        border-color: var(--admin-primary);
    }
    
    .page-link.active {
        background: var(--admin-primary);
        color: white;
        border-color: var(--admin-primary);
        font-weight: 600;
    }
    
    .page-link.disabled {
        opacity: 0.3;
        pointer-events: none;
        cursor: not-allowed;
    }
    
    .page-info {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-left: 0.75rem;
    }
    
    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
    }
    
    .empty-icon {
        font-size: 3.5rem;
        color: var(--admin-border-light);
        margin-bottom: 1rem;
        opacity: 0.5;
    }
    
    .empty-state h3 {
        font-size: 1.2rem;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }
    
    .empty-state p {
        color: var(--text-muted);
        margin-bottom: 1.5rem;
        max-width: 400px;
        margin-left: auto;
        margin-right: auto;
    }
    
    /* Vendor Detail Grid (Modal) */
    .vendor-detail-grid {
        display: grid;
        gap: 1.5rem;
    }
    
    .detail-section {
        background: var(--admin-bg);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-sm);
        padding: 1.25rem;
    }
    
    .detail-section h4 {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid var(--admin-border);
    }
    
    .detail-section h4 i {
        color: var(--admin-primary);
    }
    
    .detail-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem 0;
        border-bottom: 1px solid rgba(51, 65, 85, 0.3);
        font-size: 0.85rem;
    }
    
    .detail-row:last-child {
        border-bottom: none;
    }
    
    .detail-label {
        color: var(--text-muted);
        font-weight: 500;
    }
    
    .detail-value {
        color: var(--text-primary);
        font-weight: 500;
        text-align: right;
    }
    
    /* Responsive */
    @media (max-width: 1200px) {
        .admin-table th:nth-child(5),
        .admin-table td:nth-child(5) { display: none; }
    }
    
    @media (max-width: 1024px) {
        .admin-table th:nth-child(4),
        .admin-table td:nth-child(4) { display: none; }
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .filter-form {
            flex-direction: column;
            align-items: stretch;
        }
        
        .search-group,
        .filter-select-group {
            width: 100%;
        }
        
        .results-info {
            margin-left: 0;
            text-align: center;
            width: 100%;
        }
        
        .admin-table th:nth-child(3),
        .admin-table td:nth-child(3),
        .admin-table th:nth-child(6),
        .admin-table td:nth-child(6) { display: none; }
        
        .form-row {
            grid-template-columns: 1fr;
        }
        
        .table-footer-info {
            flex-direction: column;
            gap: 0.5rem;
        }
    }
    
    @media (max-width: 480px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .admin-table th:nth-child(8),
        .admin-table td:nth-child(8) { display: none; }
        
        .vendor-info-cell {
            min-width: auto;
        }
    }
</style>

<!-- ==================== SCRIPTS ==================== -->
<script>
    // CSRF Token for AJAX requests
    const csrfToken = '<?php echo CSRFToken::generate(); ?>';
    
    // Active action menu tracking
    let activeMenu = null;
    
    // Toggle action menu
    function toggleActionMenu(event, vendorId) {
        event.stopPropagation();
        const menu = document.getElementById('actionMenu' + vendorId);
        
        if (activeMenu && activeMenu !== menu) {
            activeMenu.classList.remove('show');
        }
        
        menu.classList.toggle('show');
        activeMenu = menu.classList.contains('show') ? menu : null;
    }
    
    // Close all action menus when clicking outside
    document.addEventListener('click', function(e) {
        if (activeMenu && !e.target.closest('.action-dropdown')) {
            activeMenu.classList.remove('show');
            activeMenu = null;
        }
    });
    
    // Prevent menu close when clicking inside
    document.querySelectorAll('.action-menu').forEach(menu => {
        menu.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
    
    // Clear search
    function clearSearch() {
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.value = '';
            document.getElementById('filterForm').submit();
        }
    }
    
    // Modal functions
    function openModal(modalId) {
        document.getElementById(modalId).classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('active');
        document.body.style.overflow = '';
    }
    
    // Close modal on outside click
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal(this.id);
            }
        });
    });
    
    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.active').forEach(modal => {
                closeModal(modal.id);
            });
        }
    });
    
    // AJAX POST helper
    function ajaxPost(action, data, callback) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('csrf_token', csrfToken);
        
        for (const [key, value] of Object.entries(data)) {
            formData.append(key, value);
        }
        
        fetch('vendors.php', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (callback) callback(data);
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('An error occurred. Please try again.', 'error');
        });
    }
    
    // Toggle vendor approval
    function toggleApproval(vendorId, isApproved) {
        const action = isApproved ? 'approve' : 'revoke approval for';
        document.getElementById('confirmIcon').innerHTML = '<i class="fas fa-question-circle" style="color: #3B82F6;"></i>';
        document.getElementById('confirmTitle').textContent = isApproved ? 'Approve Vendor' : 'Revoke Approval';
        document.getElementById('confirmMessage').textContent = `Are you sure you want to ${action} this vendor?`;
        
        openModal('confirmModal');
        
        document.getElementById('confirmActionBtn').onclick = function() {
            closeModal('confirmModal');
            showLoadingState(vendorId);
            ajaxPost('toggle_approval', { vendor_id: vendorId, is_approved: isApproved ? 1 : 0 }, function(data) {
                hideLoadingState(vendorId);
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    setTimeout(() => location.reload(), 1000);
                }
            });
        };
    }
    
    // Change vendor status
    function changeStatus(vendorId, status) {
        const statusLabels = {
            'active': 'Activate',
            'inactive': 'Deactivate',
            'suspended': 'Suspend'
        };
        
        document.getElementById('confirmIcon').innerHTML = '<i class="fas fa-exclamation-triangle" style="color: #F59E0B;"></i>';
        document.getElementById('confirmTitle').textContent = `${statusLabels[status]} Vendor`;
        document.getElementById('confirmMessage').textContent = `Are you sure you want to ${statusLabels[status].toLowerCase()} this vendor? This may affect their ability to sell products.`;
        
        openModal('confirmModal');
        
        document.getElementById('confirmActionBtn').onclick = function() {
            closeModal('confirmModal');
            showLoadingState(vendorId);
            ajaxPost('toggle_status', { vendor_id: vendorId, status: status }, function(data) {
                hideLoadingState(vendorId);
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    setTimeout(() => location.reload(), 1000);
                }
            });
        };
    }
    
    // Toggle express delivery
    function toggleExpress(vendorId, isExpress) {
        showLoadingState(vendorId);
        ajaxPost('toggle_express', { vendor_id: vendorId, is_express: isExpress ? 1 : 0 }, function(data) {
            hideLoadingState(vendorId);
            showToast(data.message, data.success ? 'success' : 'error');
            if (!data.success) {
                // Revert toggle if failed
                const checkbox = document.querySelector(`tr[data-vendor-id="${vendorId}"] .toggle-input`);
                if (checkbox) checkbox.checked = !isExpress;
            }
        });
    }
    
    // Show loading state on row
    function showLoadingState(vendorId) {
        const row = document.querySelector(`tr[data-vendor-id="${vendorId}"]`);
        if (row) {
            row.style.opacity = '0.6';
            row.style.pointerEvents = 'none';
        }
    }
    
    function hideLoadingState(vendorId) {
        const row = document.querySelector(`tr[data-vendor-id="${vendorId}"]`);
        if (row) {
            row.style.opacity = '1';
            row.style.pointerEvents = 'auto';
        }
    }
    
    // View vendor details
    function viewVendorDetails(vendorId) {
        openModal('vendorModal');
        document.getElementById('modalTitle').textContent = 'Loading...';
        document.getElementById('vendorModalBody').innerHTML = `
            <div class="modal-loading">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Loading vendor details...</p>
            </div>
        `;
        
        fetch(`api/get-vendor-details.php?id=${vendorId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const vendor = data.vendor;
                    document.getElementById('modalTitle').textContent = vendor.business_name;
                    document.getElementById('vendorModalBody').innerHTML = buildVendorDetailHTML(vendor);
                } else {
                    document.getElementById('vendorModalBody').innerHTML = `
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-exclamation-circle"></i></div>
                            <h3>Error Loading Details</h3>
                            <p>${data.message || 'Unable to load vendor details.'}</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                document.getElementById('vendorModalBody').innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-plug"></i></div>
                        <h3>Connection Error</h3>
                        <p>Unable to connect to the server. Please check your connection and try again.</p>
                    </div>
                `;
            });
    }
    
    // Build vendor detail HTML
    function buildVendorDetailHTML(vendor) {
        return `
            <div class="vendor-detail-grid">
                <div class="detail-section">
                    <h4><i class="fas fa-info-circle"></i> Business Information</h4>
                    <div class="detail-row">
                        <span class="detail-label">Business Name:</span>
                        <span class="detail-value">${escapeHtml(vendor.business_name)}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Store Badge:</span>
                        <span class="detail-value">${vendor.store_badge ? escapeHtml(vendor.store_badge) : '<span style="color: var(--text-muted);">N/A</span>'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Description:</span>
                        <span class="detail-value">${vendor.description ? escapeHtml(vendor.description) : '<span style="color: var(--text-muted);">No description</span>'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Contact Phone:</span>
                        <span class="detail-value">${vendor.contact_phone ? escapeHtml(vendor.contact_phone) : '<span style="color: var(--text-muted);">N/A</span>'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">WhatsApp:</span>
                        <span class="detail-value">${vendor.whatsapp ? escapeHtml(vendor.whatsapp) : '<span style="color: var(--text-muted);">N/A</span>'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Vendor Since:</span>
                        <span class="detail-value">${vendor.created_at_formatted || 'N/A'}</span>
                    </div>
                </div>
                
                <div class="detail-section">
                    <h4><i class="fas fa-user"></i> Owner Information</h4>
                    <div class="detail-row">
                        <span class="detail-label">Name:</span>
                        <span class="detail-value">${vendor.owner_name || 'N/A'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Email:</span>
                        <span class="detail-value">${vendor.owner_email || 'N/A'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Phone:</span>
                        <span class="detail-value">${vendor.owner_phone || 'N/A'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Owner Status:</span>
                        <span class="detail-value">${vendor.owner_active ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-danger">Inactive</span>'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Joined:</span>
                        <span class="detail-value">${vendor.owner_joined || 'N/A'}</span>
                    </div>
                </div>
                
                <div class="detail-section">
                    <h4><i class="fas fa-chart-bar"></i> Performance Metrics</h4>
                    <div class="detail-row">
                        <span class="detail-label">Total Products:</span>
                        <span class="detail-value">${vendor.total_products || 0}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Active Products:</span>
                        <span class="detail-value">${vendor.active_products || 0}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Total Orders:</span>
                        <span class="detail-value">${vendor.total_orders || 0}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Delivered Orders:</span>
                        <span class="detail-value">${vendor.delivered_orders || 0}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Total Revenue:</span>
                        <span class="detail-value" style="color: var(--admin-success); font-weight: 600;">${vendor.total_revenue_formatted || 'GHS 0.00'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Paid Revenue:</span>
                        <span class="detail-value" style="color: var(--admin-success);">${vendor.paid_revenue_formatted || 'GHS 0.00'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Product Rating:</span>
                        <span class="detail-value">${parseFloat(vendor.avg_product_rating || 0).toFixed(1)} / 5.0</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Total Reviews:</span>
                        <span class="detail-value">${vendor.total_reviews || 0}</span>
                    </div>
                </div>
                
                <div class="detail-section">
                    <h4><i class="fas fa-cog"></i> Status Information</h4>
                    <div class="detail-row">
                        <span class="detail-label">Approval:</span>
                        <span class="detail-value">${vendor.is_approved == 1 ? '<span class="badge badge-success">Approved</span>' : '<span class="badge badge-warning">Pending Approval</span>'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Status:</span>
                        <span class="detail-value"><span class="badge badge-${vendor.status === 'active' ? 'success' : vendor.status === 'suspended' ? 'danger' : 'warning'}">${vendor.status}</span></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Express Delivery:</span>
                        <span class="detail-value">${vendor.is_express == 1 ? '<span class="badge badge-info">Yes</span>' : '<span class="badge badge-default">No</span>'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Created:</span>
                        <span class="detail-value">${vendor.created_at_formatted || 'N/A'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Last Updated:</span>
                        <span class="detail-value">${vendor.updated_at || 'N/A'}</span>
                    </div>
                </div>
            </div>
        `;
    }
    
    // Escape HTML helper
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Edit vendor
    function editVendor(vendorId) {
        fetch(`api/get-vendor-details.php?id=${vendorId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const vendor = data.vendor;
                    document.getElementById('editVendorId').value = vendorId;
                    document.getElementById('editBusinessName').value = vendor.business_name || '';
                    document.getElementById('editContactPhone').value = vendor.contact_phone || '';
                    document.getElementById('editWhatsapp').value = vendor.whatsapp || '';
                    document.getElementById('editStoreBadge').value = vendor.store_badge || '';
                    document.getElementById('editDescription').value = vendor.description || '';
                    openModal('editVendorModal');
                } else {
                    showToast('Failed to load vendor data', 'error');
                }
            })
            .catch(error => {
                showToast('Connection error. Please try again.', 'error');
            });
    }
    
    // Toast notification
    function showToast(message, type = 'info') {
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
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <i class="fas ${icons[type] || icons.info}"></i>
            <span>${message}</span>
            <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
        `;
        
        container.appendChild(toast);
        
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(120%)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }
    
    // Auto-hide alerts
    setTimeout(() => {
        const alert = document.getElementById('alertMessage');
        if (alert) {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.5s ease';
            setTimeout(() => alert.remove(), 500);
        }
    }, 5000);
    
    // Handle edit form submission
    document.getElementById('editVendorForm')?.addEventListener('submit', function(e) {
        const businessName = document.getElementById('editBusinessName').value.trim();
        if (!businessName) {
            e.preventDefault();
            showToast('Business name is required.', 'error');
            return false;
        }
    });
</script>

<!-- Toast Styles -->
<style>
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
        box-shadow: var(--shadow-lg);
        animation: slideInRight 0.3s ease;
        max-width: 420px;
    }
    
    .toast-success { border-left: 3px solid var(--admin-success); }
    .toast-success i { color: var(--admin-success); }
    .toast-error { border-left: 3px solid var(--admin-danger); }
    .toast-error i { color: var(--admin-danger); }
    .toast-warning { border-left: 3px solid var(--admin-warning); }
    .toast-warning i { color: var(--admin-warning); }
    .toast-info { border-left: 3px solid var(--admin-primary); }
    .toast-info i { color: var(--admin-primary); }
    
    .toast-close {
        background: none;
        border: none;
        color: var(--text-muted);
        cursor: pointer;
        font-size: 1.1rem;
        padding: 0;
        margin-left: auto;
        transition: var(--transition-fast);
    }
    
    .toast-close:hover { color: var(--text-primary); }
    
    @keyframes slideInRight {
        from { transform: translateX(120%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
</style>

<?php
// Include admin footer
require_once __DIR__ . '/footer.php';
?>
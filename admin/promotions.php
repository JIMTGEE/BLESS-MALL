<?php
/**
 * promotions.php - Bless Mall Admin Promotions Management
 * 
 * Manage promotional campaigns with:
 * - Create/Edit/Delete promotions
 * - Assign products to promotions
 * - Track promotion performance
 * - Active/Inactive toggling
 * - Date range management
 * - Discount types (percentage/fixed)
 * 
 * Developed by NerdSpring Software Engineering
 * https://nerd-spring.com
 */

// ------------------------------------------------------------
// 1. BOOTSTRAP & AUTHENTICATION
// ------------------------------------------------------------
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';

// Check admin/seller authentication
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
// 3. HANDLE FORM SUBMISSIONS
// ------------------------------------------------------------
$message = '';
$message_type = '';

// Create/Update Promotion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // CSRF Token validation
    if (!isset($_POST['csrf_token']) || !CSRFToken::validate($_POST['csrf_token'])) {
        $message = 'Security validation failed. Please try again.';
        $message_type = 'error';
    } else {
        switch ($action) {
            case 'save_promotion':
                $promo_id = isset($_POST['promo_id']) ? (int)$_POST['promo_id'] : 0;
                $name = $conn->real_escape_string(trim($_POST['name']));
                $description = $conn->real_escape_string(trim($_POST['description'] ?? ''));
                $discount_type = $conn->real_escape_string($_POST['discount_type']);
                $discount_value = (float)$_POST['discount_value'];
                $start_date = $conn->real_escape_string($_POST['start_date']);
                $end_date = $conn->real_escape_string($_POST['end_date']);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                $selected_products = isset($_POST['products']) ? $_POST['products'] : [];
                
                // Validation
                $errors = [];
                if (empty($name)) $errors[] = 'Promotion name is required';
                if (empty($discount_type)) $errors[] = 'Discount type is required';
                if ($discount_value <= 0) $errors[] = 'Discount value must be greater than 0';
                if (empty($start_date)) $errors[] = 'Start date is required';
                if (empty($end_date)) $errors[] = 'End date is required';
                if ($start_date > $end_date) $errors[] = 'End date must be after start date';
                if ($discount_type === 'percentage' && $discount_value > 100) $errors[] = 'Percentage cannot exceed 100%';
                
                if (empty($errors)) {
                    if ($promo_id > 0) {
                        // Update existing promotion
                        $update_query = "UPDATE promotions SET 
                            name = ?, description = ?, discount_type = ?, 
                            discount_value = ?, start_date = ?, end_date = ?, 
                            is_active = ? WHERE id = ?";
                        $stmt = $conn->prepare($update_query);
                        $stmt->bind_param("sssddiii", $name, $description, $discount_type, 
                            $discount_value, $start_date, $end_date, $is_active, $promo_id);
                        
                        if ($stmt->execute()) {
                            // Remove existing product associations
                            $conn->query("DELETE FROM product_promotions WHERE promotion_id = $promo_id");
                            
                            // Add new product associations
                            if (!empty($selected_products)) {
                                $insert_pp = $conn->prepare("INSERT INTO product_promotions (product_id, promotion_id) VALUES (?, ?)");
                                foreach ($selected_products as $product_id) {
                                    $product_id = (int)$product_id;
                                    $insert_pp->bind_param("ii", $product_id, $promo_id);
                                    $insert_pp->execute();
                                }
                            }
                            
                            $message = 'Promotion updated successfully!';
                            $message_type = 'success';
                        } else {
                            $message = 'Error updating promotion: ' . $conn->error;
                            $message_type = 'error';
                        }
                    } else {
                        // Create new promotion
                        $insert_query = "INSERT INTO promotions (name, description, discount_type, discount_value, start_date, end_date, is_active) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($insert_query);
                        $stmt->bind_param("sssddii", $name, $description, $discount_type, 
                            $discount_value, $start_date, $end_date, $is_active);
                        
                        if ($stmt->execute()) {
                            $new_promo_id = $stmt->insert_id;
                            
                            // Add product associations
                            if (!empty($selected_products)) {
                                $insert_pp = $conn->prepare("INSERT INTO product_promotions (product_id, promotion_id) VALUES (?, ?)");
                                foreach ($selected_products as $product_id) {
                                    $product_id = (int)$product_id;
                                    $insert_pp->bind_param("ii", $product_id, $new_promo_id);
                                    $insert_pp->execute();
                                }
                            }
                            
                            $message = 'Promotion created successfully!';
                            $message_type = 'success';
                        } else {
                            $message = 'Error creating promotion: ' . $conn->error;
                            $message_type = 'error';
                        }
                    }
                } else {
                    $message = implode('<br>', $errors);
                    $message_type = 'error';
                }
                break;
                
            case 'delete_promotion':
                $promo_id = (int)$_POST['promo_id'];
                
                // Delete product associations first
                $conn->query("DELETE FROM product_promotions WHERE promotion_id = $promo_id");
                
                // Delete the promotion
                $delete_query = "DELETE FROM promotions WHERE id = ?";
                $stmt = $conn->prepare($delete_query);
                $stmt->bind_param("i", $promo_id);
                
                if ($stmt->execute()) {
                    $message = 'Promotion deleted successfully!';
                    $message_type = 'success';
                } else {
                    $message = 'Error deleting promotion';
                    $message_type = 'error';
                }
                break;
                
            case 'toggle_status':
                $promo_id = (int)$_POST['promo_id'];
                $new_status = (int)$_POST['is_active'];
                
                $toggle_query = "UPDATE promotions SET is_active = ? WHERE id = ?";
                $stmt = $conn->prepare($toggle_query);
                $stmt->bind_param("ii", $new_status, $promo_id);
                
                if ($stmt->execute()) {
                    $message = $new_status ? 'Promotion activated!' : 'Promotion deactivated!';
                    $message_type = 'success';
                } else {
                    $message = 'Error updating promotion status';
                    $message_type = 'error';
                }
                break;
        }
    }
}

// ------------------------------------------------------------
// 4. FETCH PROMOTIONS DATA
// ------------------------------------------------------------
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build WHERE conditions
$where_conditions = [];
$params = [];
$types = "";

if (!empty($search)) {
    $where_conditions[] = "(p.name LIKE ? OR p.description LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if ($status_filter === 'active') {
    $where_conditions[] = "p.is_active = 1 AND p.start_date <= CURDATE() AND p.end_date >= CURDATE()";
} elseif ($status_filter === 'inactive') {
    $where_conditions[] = "p.is_active = 0";
} elseif ($status_filter === 'ended') {
    $where_conditions[] = "p.end_date < CURDATE()";
} elseif ($status_filter === 'upcoming') {
    $where_conditions[] = "p.start_date > CURDATE()";
}

$where_clause = !empty($where_conditions) ? " WHERE " . implode(" AND ", $where_conditions) : "";

// Get total count
$count_query = "SELECT COUNT(*) as total FROM promotions p" . $where_clause;
$stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_promotions = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_promotions / $per_page);

// Fetch promotions with product counts
$promotions_query = "SELECT p.*, 
                     COUNT(pp.product_id) as product_count
                     FROM promotions p
                     LEFT JOIN product_promotions pp ON p.id = pp.promotion_id
                     $where_clause
                     GROUP BY p.id
                     ORDER BY p.created_at DESC
                     LIMIT $per_page OFFSET $offset";
$stmt = $conn->prepare($promotions_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$promotions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch all active products for the assignment modal
$products_query = "SELECT id, name, brand, 
                   (SELECT image_path FROM product_images WHERE product_id = products.id AND is_primary = 1 LIMIT 1) as image,
                   (SELECT unit_price FROM product_pricing_tiers WHERE product_id = products.id AND min_quantity = 1 LIMIT 1) as price
                   FROM products 
                   WHERE is_active = 1 
                   ORDER BY name ASC";
$all_products = $conn->query($products_query)->fetch_all(MYSQLI_ASSOC);

// If editing, get promotion details
$edit_promotion = null;
$edit_products = [];
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $edit_query = "SELECT * FROM promotions WHERE id = ?";
    $stmt = $conn->prepare($edit_query);
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $edit_promotion = $stmt->get_result()->fetch_assoc();
    
    if ($edit_promotion) {
        $ep_query = "SELECT product_id FROM product_promotions WHERE promotion_id = ?";
        $stmt = $conn->prepare($ep_query);
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $edit_products[] = $row['product_id'];
        }
    }
}

// Stats
$active_promos = $conn->query("SELECT COUNT(*) as cnt FROM promotions WHERE is_active = 1 AND start_date <= CURDATE() AND end_date >= CURDATE()")->fetch_assoc()['cnt'];
$upcoming_promos = $conn->query("SELECT COUNT(*) as cnt FROM promotions WHERE is_active = 1 AND start_date > CURDATE()")->fetch_assoc()['cnt'];
$total_discount_products = $conn->query("SELECT COUNT(DISTINCT product_id) as cnt FROM product_promotions pp JOIN promotions p ON pp.promotion_id = p.id WHERE p.is_active = 1 AND p.start_date <= CURDATE() AND p.end_date >= CURDATE()")->fetch_assoc()['cnt'];

// Page title
$page_title = 'Promotions Management - Bless Mall Admin';

// Include admin header
require_once __DIR__ . '/header.php';
?>

<!-- ==================== PAGE CONTENT ==================== -->
<div class="admin-page-header">
    <h1>
        <i class="fas fa-tags"></i>
        Promotions Management
    </h1>
    <p>Create and manage promotional campaigns, discounts, and special offers</p>
</div>

<!-- Breadcrumb -->
<div class="admin-breadcrumb">
    <a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <span class="separator"><i class="fas fa-chevron-right"></i></span>
    <span class="current">Promotions</span>
</div>

<!-- Alert Messages -->
<?php if ($message): ?>
<div class="alert alert-<?php echo $message_type; ?>" id="alertMessage">
    <div class="alert-icon">
        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    </div>
    <div class="alert-content">
        <p><?php echo $message; ?></p>
    </div>
    <button class="alert-close" onclick="this.parentElement.remove()">
        <i class="fas fa-times"></i>
    </button>
</div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1);">
            <i class="fas fa-percentage" style="color: #3B82F6;"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?php echo $total_promotions; ?></div>
            <div class="stat-label">Total Promotions</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1);">
            <i class="fas fa-play-circle" style="color: #10B981;"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?php echo $active_promos; ?></div>
            <div class="stat-label">Active Promotions</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1);">
            <i class="fas fa-clock" style="color: #F59E0B;"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?php echo $upcoming_promos; ?></div>
            <div class="stat-label">Upcoming</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(139, 92, 246, 0.1);">
            <i class="fas fa-boxes" style="color: #8B5CF6;"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?php echo $total_discount_products; ?></div>
            <div class="stat-label">Discounted Products</div>
        </div>
    </div>
</div>

<!-- Filters & Actions Bar -->
<div class="action-bar">
    <div class="action-bar-left">
        <!-- Search -->
        <form method="GET" action="promotions.php" class="search-form">
            <div class="search-input-wrapper">
                <i class="fas fa-search"></i>
                <input type="text" name="search" placeholder="Search promotions..." 
                       value="<?php echo htmlspecialchars($search); ?>">
                <?php if ($status_filter !== 'all'): ?>
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-sm btn-primary">Search</button>
            <?php if (!empty($search) || $status_filter !== 'all'): ?>
                <a href="promotions.php" class="btn btn-sm btn-outline">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    
    <div class="action-bar-center">
        <!-- Status Filters -->
        <div class="status-filters">
            <a href="promotions.php<?php echo !empty($search) ? '?search=' . urlencode($search) : ''; ?>" 
               class="filter-pill <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                All
            </a>
            <a href="promotions.php?status=active<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
               class="filter-pill <?php echo $status_filter === 'active' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i> Active
            </a>
            <a href="promotions.php?status=upcoming<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
               class="filter-pill <?php echo $status_filter === 'upcoming' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i> Upcoming
            </a>
            <a href="promotions.php?status=ended<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
               class="filter-pill <?php echo $status_filter === 'ended' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> Ended
            </a>
            <a href="promotions.php?status=inactive<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
               class="filter-pill <?php echo $status_filter === 'inactive' ? 'active' : ''; ?>">
                <i class="fas fa-pause-circle"></i> Inactive
            </a>
        </div>
    </div>
    
    <div class="action-bar-right">
        <button class="btn btn-primary" onclick="openPromoModal()">
            <i class="fas fa-plus-circle"></i> Create Promotion
        </button>
    </div>
</div>

<!-- Promotions Table -->
<div class="table-container">
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th width="5%">#</th>
                    <th width="18%">Promotion Name</th>
                    <th width="10%">Type</th>
                    <th width="10%">Discount</th>
                    <th width="12%">Duration</th>
                    <th width="8%">Products</th>
                    <th width="10%">Status</th>
                    <th width="15%">Created</th>
                    <th width="12%">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($promotions)): ?>
                <tr>
                    <td colspan="9">
                        <div class="empty-table">
                            <div class="empty-icon">
                                <i class="fas fa-tags"></i>
                            </div>
                            <h4>No Promotions Found</h4>
                            <p><?php echo !empty($search) ? 'No promotions match your search criteria.' : 'Start by creating your first promotion!'; ?></p>
                            <?php if (empty($search)): ?>
                                <button class="btn btn-primary" onclick="openPromoModal()">
                                    <i class="fas fa-plus-circle"></i> Create Promotion
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                    <?php 
                    $index = $offset + 1;
                    foreach ($promotions as $promo): 
                        $now = date('Y-m-d');
                        $is_active = $promo['is_active'] == 1;
                        $is_current = $is_active && $promo['start_date'] <= $now && $promo['end_date'] >= $now;
                        $is_upcoming = $is_active && $promo['start_date'] > $now;
                        $is_ended = $promo['end_date'] < $now;
                        
                        // Determine status
                        if ($is_current) {
                            $status_class = 'active';
                            $status_text = 'Active';
                        } elseif ($is_upcoming) {
                            $status_class = 'upcoming';
                            $status_text = 'Upcoming';
                        } elseif ($is_ended) {
                            $status_class = 'ended';
                            $status_text = 'Ended';
                        } else {
                            $status_class = 'inactive';
                            $status_text = 'Inactive';
                        }
                    ?>
                    <tr>
                        <td><?php echo $index++; ?></td>
                        <td>
                            <div class="product-name-cell">
                                <span class="product-title"><?php echo htmlspecialchars($promo['name']); ?></span>
                                <?php if (!empty($promo['description'])): ?>
                                    <span class="product-subtitle"><?php echo htmlspecialchars(substr($promo['description'], 0, 50)); ?><?php echo strlen($promo['description']) > 50 ? '...' : ''; ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-type">
                                <?php echo $promo['discount_type'] === 'percentage' ? 
                                    '<i class="fas fa-percent"></i> Percentage' : 
                                    '<i class="fas fa-cedi-sign"></i> Fixed Amount'; ?>
                            </span>
                        </td>
                        <td>
                            <span class="discount-value">
                                <?php echo $promo['discount_type'] === 'percentage' ? 
                                    $promo['discount_value'] . '% OFF' : 
                                    'GHS ' . number_format($promo['discount_value'], 2) . ' OFF'; ?>
                            </span>
                        </td>
                        <td>
                            <div class="date-range">
                                <span class="date-item">
                                    <i class="fas fa-calendar-check"></i> 
                                    <?php echo date('M d, Y', strtotime($promo['start_date'])); ?>
                                </span>
                                <span class="date-separator">→</span>
                                <span class="date-item">
                                    <i class="fas fa-calendar-times"></i> 
                                    <?php echo date('M d, Y', strtotime($promo['end_date'])); ?>
                                </span>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-count">
                                <i class="fas fa-box"></i> <?php echo $promo['product_count']; ?> products
                            </span>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo $status_class; ?>">
                                <span class="status-dot"></span>
                                <?php echo $status_text; ?>
                            </span>
                        </td>
                        <td>
                            <span class="date-text">
                                <?php echo date('M d, Y h:i A', strtotime($promo['created_at'])); ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon" title="Edit" onclick="editPromotion(<?php echo $promo['id']; ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn-icon" title="<?php echo $is_active ? 'Deactivate' : 'Activate'; ?>" 
                                        onclick="togglePromotion(<?php echo $promo['id']; ?>, <?php echo $is_active ? 0 : 1; ?>)">
                                    <i class="fas fa-<?php echo $is_active ? 'pause-circle' : 'play-circle'; ?>"></i>
                                </button>
                                <button class="btn-icon btn-danger" title="Delete" 
                                        onclick="deletePromotion(<?php echo $promo['id']; ?>, '<?php echo htmlspecialchars(addslashes($promo['name'])); ?>')">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php 
        $query_params = $_GET;
        unset($query_params['page']);
        $base_query = http_build_query($query_params);
        $base_url = 'promotions.php' . ($base_query ? '?' . $base_query . '&' : '?');
        ?>
        
        <?php if ($page > 1): ?>
            <a href="<?php echo $base_url; ?>page=<?php echo $page - 1; ?>" class="page-link">
                <i class="fas fa-chevron-left"></i>
            </a>
        <?php endif; ?>
        
        <?php
        $start_page = max(1, $page - 2);
        $end_page = min($total_pages, $page + 2);
        for ($i = $start_page; $i <= $end_page; $i++):
        ?>
            <a href="<?php echo $base_url; ?>page=<?php echo $i; ?>" 
               class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </a>
        <?php endfor; ?>
        
        <?php if ($page < $total_pages): ?>
            <a href="<?php echo $base_url; ?>page=<?php echo $page + 1; ?>" class="page-link">
                <i class="fas fa-chevron-right"></i>
            </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ==================== PROMOTION MODAL ==================== -->
<div id="promoModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h2 id="modalTitle">
                <i class="fas fa-plus-circle"></i> Create New Promotion
            </h2>
            <button class="modal-close" onclick="closePromoModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form method="POST" action="promotions.php" id="promoForm">
            <input type="hidden" name="action" value="save_promotion">
            <input type="hidden" name="promo_id" id="promo_id" value="">
            <?php echo CSRFToken::field(); ?>
            
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label for="name">Promotion Name <span class="required">*</span></label>
                        <input type="text" id="name" name="name" class="form-control" 
                               placeholder="e.g., Black Friday Sale" required maxlength="150"
                               value="<?php echo htmlspecialchars($edit_promotion['name'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-control" 
                              rows="3" placeholder="Describe your promotion..."><?php echo htmlspecialchars($edit_promotion['description'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="discount_type">Discount Type <span class="required">*</span></label>
                        <select id="discount_type" name="discount_type" class="form-control" required>
                            <option value="">Select type</option>
                            <option value="percentage" <?php echo ($edit_promotion['discount_type'] ?? '') === 'percentage' ? 'selected' : ''; ?>>
                                Percentage (%)
                            </option>
                            <option value="fixed" <?php echo ($edit_promotion['discount_type'] ?? '') === 'fixed' ? 'selected' : ''; ?>>
                                Fixed Amount (GHS)
                            </option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="discount_value">Discount Value <span class="required">*</span></label>
                        <div class="input-group">
                            <input type="number" id="discount_value" name="discount_value" 
                                   class="form-control" placeholder="0.00" step="0.01" min="0" required
                                   value="<?php echo htmlspecialchars($edit_promotion['discount_value'] ?? ''); ?>">
                            <span class="input-group-text" id="discount_suffix">%</span>
                        </div>
                        <small class="form-text" id="discount_hint">Enter percentage (1-100) or fixed amount in GHS</small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date">Start Date <span class="required">*</span></label>
                        <input type="date" id="start_date" name="start_date" class="form-control" required
                               value="<?php echo htmlspecialchars($edit_promotion['start_date'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="end_date">End Date <span class="required">*</span></label>
                        <input type="date" id="end_date" name="end_date" class="form-control" required
                               value="<?php echo htmlspecialchars($edit_promotion['end_date'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="is_active" name="is_active" value="1"
                               <?php echo (($edit_promotion['is_active'] ?? 1) == 1) ? 'checked' : ''; ?>>
                        <label for="is_active">Activate promotion immediately</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Select Products <span class="required">*</span></label>
                    <div class="product-selection">
                        <div class="selected-count">
                            <span id="selectedCount">0</span> products selected
                        </div>
                        <div class="product-search">
                            <input type="text" id="productSearch" class="form-control" 
                                   placeholder="Search products...">
                        </div>
                        <div class="products-grid" id="productsGrid">
                            <?php foreach ($all_products as $product): 
                                $is_selected = in_array($product['id'], $edit_products);
                            ?>
                            <label class="product-checkbox <?php echo $is_selected ? 'selected' : ''; ?>">
                                <input type="checkbox" name="products[]" value="<?php echo $product['id']; ?>"
                                       <?php echo $is_selected ? 'checked' : ''; ?>>
                                <div class="product-checkbox-content">
                                    <?php if ($product['image']): ?>
                                        <img src="<?php echo BASE_URL . 'uploads/products/' . $product['image']; ?>" 
                                             alt="<?php echo htmlspecialchars($product['name']); ?>" class="product-avatar">
                                    <?php else: ?>
                                        <div class="product-avatar-placeholder">
                                            <i class="fas fa-box"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="product-checkbox-info">
                                        <span class="product-checkbox-name"><?php echo htmlspecialchars($product['name']); ?></span>
                                        <?php if ($product['brand']): ?>
                                            <span class="product-checkbox-brand"><?php echo htmlspecialchars($product['brand']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($product['price']): ?>
                                        <span class="product-checkbox-price">GHS <?php echo number_format($product['price'], 2); ?></span>
                                    <?php endif; ?>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closePromoModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Promotion
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal-container modal-sm">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color: #EF4444;"></i> Delete Promotion</h3>
            <button class="modal-close" onclick="closeDeleteModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <div class="delete-confirmation">
                <div class="delete-icon">
                    <i class="fas fa-trash-alt"></i>
                </div>
                <h4>Are you sure?</h4>
                <p>You are about to delete <strong id="deletePromoName"></strong>. This action cannot be undone.</p>
                <p class="text-muted">All associated product discounts will be removed.</p>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
            <form method="POST" action="promotions.php" id="deleteForm">
                <input type="hidden" name="action" value="delete_promotion">
                <input type="hidden" name="promo_id" id="deletePromoId" value="">
                <?php echo CSRFToken::field(); ?>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash-alt"></i> Delete Promotion
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Toggle Status Form -->
<form method="POST" action="promotions.php" id="toggleForm" style="display: none;">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="promo_id" id="togglePromoId" value="">
    <input type="hidden" name="is_active" id="toggleStatus" value="">
    <?php echo CSRFToken::field(); ?>
</form>

<!-- ==================== STYLES ==================== -->
<style>
    /* Promotions Page Specific Styles */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1.25rem;
        margin-bottom: 1.5rem;
    }
    
    .stat-card {
        background: var(--admin-surface);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-lg);
        padding: 1.25rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        transition: var(--transition);
    }
    
    .stat-card:hover {
        border-color: var(--admin-primary);
        box-shadow: var(--shadow-md);
    }
    
    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: var(--radius-md);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }
    
    .stat-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text-primary);
        line-height: 1.2;
    }
    
    .stat-label {
        font-size: 0.75rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    /* Action Bar */
    .action-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
    }
    
    .action-bar-left {
        flex: 1;
        min-width: 250px;
    }
    
    .action-bar-center {
        flex-shrink: 0;
    }
    
    .action-bar-right {
        flex-shrink: 0;
    }
    
    .search-form {
        display: flex;
        gap: 0.5rem;
    }
    
    .search-input-wrapper {
        position: relative;
        flex: 1;
        max-width: 300px;
    }
    
    .search-input-wrapper i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
    }
    
    .search-input-wrapper input {
        width: 100%;
        padding: 0.5rem 0.75rem 0.5rem 2.25rem;
        background: var(--admin-surface);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-sm);
        color: var(--text-primary);
        font-family: var(--font-body);
        font-size: 0.8rem;
    }
    
    .search-input-wrapper input:focus {
        outline: none;
        border-color: var(--admin-primary);
    }
    
    /* Status Filters */
    .status-filters {
        display: flex;
        gap: 0.35rem;
    }
    
    .filter-pill {
        padding: 0.4rem 0.85rem;
        border-radius: var(--radius-full);
        font-size: 0.75rem;
        font-weight: 500;
        color: var(--text-secondary);
        text-decoration: none;
        transition: var(--transition-fast);
        border: 1px solid var(--admin-border);
        white-space: nowrap;
    }
    
    .filter-pill:hover {
        border-color: var(--admin-primary);
        color: var(--admin-primary);
    }
    
    .filter-pill.active {
        background: var(--admin-primary);
        color: white;
        border-color: var(--admin-primary);
    }
    
    /* Buttons */
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.55rem 1.1rem;
        border-radius: var(--radius-sm);
        font-weight: 600;
        font-size: 0.8rem;
        cursor: pointer;
        transition: var(--transition-fast);
        border: 1px solid transparent;
        font-family: var(--font-body);
        text-decoration: none;
        white-space: nowrap;
    }
    
    .btn-sm {
        padding: 0.4rem 0.85rem;
        font-size: 0.75rem;
    }
    
    .btn-primary {
        background: var(--admin-primary);
        color: white;
    }
    
    .btn-primary:hover {
        background: var(--admin-primary-dark);
    }
    
    .btn-outline {
        background: transparent;
        border-color: var(--admin-border);
        color: var(--text-secondary);
    }
    
    .btn-outline:hover {
        border-color: var(--admin-primary);
        color: var(--admin-primary);
    }
    
    .btn-danger {
        background: var(--admin-danger);
        color: white;
    }
    
    .btn-danger:hover {
        background: #DC2626;
    }
    
    /* Table */
    .table-container {
        background: var(--admin-surface);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-lg);
        overflow: hidden;
    }
    
    .table-wrapper {
        overflow-x: auto;
    }
    
    .data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8rem;
    }
    
    .data-table thead {
        background: var(--admin-surface-hover);
    }
    
    .data-table th {
        padding: 0.85rem 1rem;
        text-align: left;
        font-weight: 600;
        color: var(--text-secondary);
        text-transform: uppercase;
        font-size: 0.7rem;
        letter-spacing: 0.5px;
        border-bottom: 1px solid var(--admin-border);
    }
    
    .data-table td {
        padding: 0.85rem 1rem;
        border-bottom: 1px solid var(--admin-border-light);
        color: var(--text-primary);
    }
    
    .data-table tbody tr:hover {
        background: var(--admin-surface-hover);
    }
    
    .data-table tbody tr:last-child td {
        border-bottom: none;
    }
    
    /* Product Name Cell */
    .product-name-cell {
        display: flex;
        flex-direction: column;
        gap: 0.2rem;
    }
    
    .product-title {
        font-weight: 600;
        color: var(--text-primary);
    }
    
    .product-subtitle {
        font-size: 0.7rem;
        color: var(--text-muted);
    }
    
    /* Badges */
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.65rem;
        border-radius: var(--radius-full);
        font-size: 0.7rem;
        font-weight: 500;
    }
    
    .badge-type {
        background: rgba(59, 130, 246, 0.1);
        color: #3B82F6;
    }
    
    .badge-count {
        background: rgba(139, 92, 246, 0.1);
        color: #8B5CF6;
    }
    
    .discount-value {
        font-weight: 700;
        color: var(--admin-danger);
        font-size: 0.85rem;
    }
    
    /* Date Range */
    .date-range {
        display: flex;
        flex-direction: column;
        gap: 0.2rem;
        font-size: 0.75rem;
    }
    
    .date-item {
        color: var(--text-secondary);
    }
    
    .date-item i {
        color: var(--text-muted);
        width: 18px;
    }
    
    .date-separator {
        color: var(--text-muted);
        font-size: 0.7rem;
        padding-left: 18px;
    }
    
    /* Status Badge */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.65rem;
        border-radius: var(--radius-full);
        font-size: 0.7rem;
        font-weight: 600;
    }
    
    .status-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
    }
    
    .status-active {
        background: rgba(16, 185, 129, 0.1);
        color: #10B981;
    }
    
    .status-active .status-dot {
        background: #10B981;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
    }
    
    .status-upcoming {
        background: rgba(245, 158, 11, 0.1);
        color: #F59E0B;
    }
    
    .status-upcoming .status-dot {
        background: #F59E0B;
    }
    
    .status-ended {
        background: rgba(107, 114, 128, 0.1);
        color: #6B7280;
    }
    
    .status-ended .status-dot {
        background: #6B7280;
    }
    
    .status-inactive {
        background: rgba(239, 68, 68, 0.1);
        color: #EF4444;
    }
    
    .status-inactive .status-dot {
        background: #EF4444;
    }
    
    /* Action Buttons */
    .action-buttons {
        display: flex;
        gap: 0.35rem;
    }
    
    .btn-icon {
        width: 32px;
        height: 32px;
        border-radius: var(--radius-sm);
        border: 1px solid var(--admin-border);
        background: transparent;
        color: var(--text-secondary);
        cursor: pointer;
        transition: var(--transition-fast);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
    }
    
    .btn-icon:hover {
        background: var(--admin-primary-glow);
        border-color: var(--admin-primary);
        color: var(--admin-primary);
    }
    
    .btn-icon.btn-danger:hover {
        background: var(--admin-danger-bg);
        border-color: var(--admin-danger);
        color: var(--admin-danger);
    }
    
    /* Empty State */
    .empty-table {
        text-align: center;
        padding: 3rem 1rem;
    }
    
    .empty-icon {
        font-size: 3rem;
        color: var(--text-muted);
        opacity: 0.5;
        margin-bottom: 1rem;
    }
    
    .empty-table h4 {
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }
    
    .empty-table p {
        color: var(--text-muted);
        font-size: 0.85rem;
        margin-bottom: 1.25rem;
    }
    
    /* Modal */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.6);
        z-index: 2000;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(4px);
    }
    
    .modal-overlay.active {
        display: flex;
    }
    
    .modal-container {
        background: var(--admin-surface);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-xl);
        width: 90%;
        max-width: 750px;
        max-height: 90vh;
        overflow-y: auto;
        animation: modalSlideIn 0.3s ease;
    }
    
    .modal-sm {
        max-width: 450px;
    }
    
    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: translateY(-30px) scale(0.95);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid var(--admin-border);
    }
    
    .modal-header h2, .modal-header h3 {
        font-family: var(--font-heading);
        font-size: 1.1rem;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .modal-close {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        border: 1px solid var(--admin-border);
        background: transparent;
        color: var(--text-muted);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: var(--transition-fast);
    }
    
    .modal-close:hover {
        background: var(--admin-danger-bg);
        color: var(--admin-danger);
        border-color: var(--admin-danger);
    }
    
    .modal-body {
        padding: 1.5rem;
    }
    
    .modal-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid var(--admin-border);
        display: flex;
        gap: 0.75rem;
        justify-content: flex-end;
    }
    
    /* Form Styles */
    .form-group {
        margin-bottom: 1.25rem;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }
    
    .form-group label {
        display: block;
        font-weight: 600;
        font-size: 0.8rem;
        color: var(--text-secondary);
        margin-bottom: 0.4rem;
    }
    
    .required {
        color: var(--admin-danger);
    }
    
    .form-control {
        width: 100%;
        padding: 0.65rem 0.85rem;
        background: var(--admin-bg);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-sm);
        color: var(--text-primary);
        font-family: var(--font-body);
        font-size: 0.82rem;
        transition: var(--transition-fast);
    }
    
    .form-control:focus {
        outline: none;
        border-color: var(--admin-primary);
        box-shadow: 0 0 0 3px var(--admin-primary-glow);
    }
    
    select.form-control {
        cursor: pointer;
    }
    
    textarea.form-control {
        resize: vertical;
    }
    
    .input-group {
        display: flex;
    }
    
    .input-group .form-control {
        border-radius: var(--radius-sm) 0 0 var(--radius-sm);
    }
    
    .input-group-text {
        padding: 0.65rem 0.85rem;
        background: var(--admin-bg-lighter);
        border: 1px solid var(--admin-border);
        border-left: none;
        border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
        color: var(--text-muted);
        font-size: 0.8rem;
        display: flex;
        align-items: center;
    }
    
    .form-text {
        display: block;
        font-size: 0.7rem;
        color: var(--text-muted);
        margin-top: 0.35rem;
    }
    
    .checkbox-group {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .checkbox-group input[type="checkbox"] {
        width: 18px;
        height: 18px;
        accent-color: var(--admin-primary);
        cursor: pointer;
    }
    
    .checkbox-group label {
        cursor: pointer;
        margin-bottom: 0;
    }
    
    /* Product Selection */
    .product-selection {
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-md);
        overflow: hidden;
    }
    
    .selected-count {
        padding: 0.5rem 0.75rem;
        background: var(--admin-bg);
        border-bottom: 1px solid var(--admin-border);
        font-size: 0.75rem;
        color: var(--text-muted);
        font-weight: 500;
    }
    
    .selected-count span {
        color: var(--admin-primary);
        font-weight: 700;
    }
    
    .product-search {
        padding: 0.5rem 0.75rem;
        border-bottom: 1px solid var(--admin-border);
    }
    
    .product-search input {
        width: 100%;
        padding: 0.5rem 0.75rem;
        background: var(--admin-bg);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-sm);
        color: var(--text-primary);
        font-size: 0.8rem;
    }
    
    .product-search input:focus {
        outline: none;
        border-color: var(--admin-primary);
    }
    
    .products-grid {
        max-height: 300px;
        overflow-y: auto;
    }
    
    .product-checkbox {
        display: flex;
        align-items: center;
        padding: 0.65rem 0.75rem;
        cursor: pointer;
        transition: var(--transition-fast);
        border-bottom: 1px solid var(--admin-border-light);
    }
    
    .product-checkbox:last-child {
        border-bottom: none;
    }
    
    .product-checkbox:hover {
        background: var(--admin-surface-hover);
    }
    
    .product-checkbox.selected {
        background: var(--admin-primary-glow);
    }
    
    .product-checkbox input[type="checkbox"] {
        display: none;
    }
    
    .product-checkbox-content {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        width: 100%;
    }
    
    .product-avatar {
        width: 40px;
        height: 40px;
        border-radius: var(--radius-sm);
        object-fit: cover;
        flex-shrink: 0;
    }
    
    .product-avatar-placeholder {
        width: 40px;
        height: 40px;
        border-radius: var(--radius-sm);
        background: var(--admin-bg);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-muted);
        flex-shrink: 0;
    }
    
    .product-checkbox-info {
        flex: 1;
    }
    
    .product-checkbox-name {
        display: block;
        font-size: 0.8rem;
        font-weight: 500;
        color: var(--text-primary);
    }
    
    .product-checkbox-brand {
        display: block;
        font-size: 0.7rem;
        color: var(--text-muted);
    }
    
    .product-checkbox-price {
        font-weight: 600;
        font-size: 0.8rem;
        color: var(--admin-primary);
        flex-shrink: 0;
    }
    
    /* Alert Messages */
    .alert {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        padding: 1rem 1.25rem;
        border-radius: var(--radius-md);
        margin-bottom: 1.25rem;
        animation: slideDown 0.3s ease;
    }
    
    .alert-success {
        background: var(--admin-success-bg);
        border: 1px solid rgba(16, 185, 129, 0.2);
        color: #10B981;
    }
    
    .alert-error {
        background: var(--admin-danger-bg);
        border: 1px solid rgba(239, 68, 68, 0.2);
        color: #EF4444;
    }
    
    .alert-icon {
        font-size: 1.1rem;
        flex-shrink: 0;
        margin-top: 1px;
    }
    
    .alert-content p {
        margin: 0;
        font-size: 0.85rem;
        line-height: 1.5;
    }
    
    .alert-close {
        margin-left: auto;
        background: none;
        border: none;
        color: inherit;
        cursor: pointer;
        opacity: 0.7;
        transition: var(--transition-fast);
        flex-shrink: 0;
    }
    
    .alert-close:hover {
        opacity: 1;
    }
    
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    /* Delete Confirmation */
    .delete-confirmation {
        text-align: center;
    }
    
    .delete-icon {
        font-size: 3rem;
        color: var(--admin-danger);
        margin-bottom: 1rem;
        opacity: 0.8;
    }
    
    .delete-confirmation h4 {
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }
    
    .delete-confirmation p {
        color: var(--text-secondary);
        font-size: 0.85rem;
        margin-bottom: 0.25rem;
    }
    
    .text-muted {
        color: var(--text-muted) !important;
    }
    
    /* Pagination */
    .pagination {
        display: flex;
        justify-content: center;
        gap: 0.35rem;
        padding: 1.25rem;
        border-top: 1px solid var(--admin-border);
    }
    
    .page-link {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        border-radius: var(--radius-sm);
        border: 1px solid var(--admin-border);
        color: var(--text-secondary);
        text-decoration: none;
        font-size: 0.8rem;
        font-weight: 500;
        transition: var(--transition-fast);
    }
    
    .page-link:hover {
        border-color: var(--admin-primary);
        color: var(--admin-primary);
    }
    
    .page-link.active {
        background: var(--admin-primary);
        border-color: var(--admin-primary);
        color: white;
    }
    
    /* Responsive */
    @media (max-width: 992px) {
        .action-bar {
            flex-direction: column;
            align-items: stretch;
        }
        
        .action-bar-left,
        .action-bar-center,
        .action-bar-right {
            width: 100%;
        }
        
        .search-input-wrapper {
            max-width: none;
        }
        
        .status-filters {
            flex-wrap: wrap;
        }
        
        .form-row {
            grid-template-columns: 1fr;
        }
        
        .modal-container {
            max-width: 95%;
        }
    }
    
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .data-table th:nth-child(2),
        .data-table td:nth-child(2),
        .data-table th:nth-child(7),
        .data-table td:nth-child(7) {
            display: none;
        }
    }
    
    @media (max-width: 576px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<!-- ==================== SCRIPTS ==================== -->
<script>
    // Modal Management
    const promoModal = document.getElementById('promoModal');
    const deleteModal = document.getElementById('deleteModal');
    const modalTitle = document.getElementById('modalTitle');
    const promoForm = document.getElementById('promoForm');
    const promoIdInput = document.getElementById('promo_id');
    
    function openPromoModal() {
        modalTitle.innerHTML = '<i class="fas fa-plus-circle"></i> Create New Promotion';
        promoForm.reset();
        promoIdInput.value = '';
        document.getElementById('is_active').checked = true;
        document.querySelectorAll('.product-checkbox').forEach(cb => cb.classList.remove('selected'));
        document.querySelectorAll('.product-checkbox input').forEach(input => input.checked = false);
        updateSelectedCount();
        promoModal.classList.add('active');
    }
    
    function closePromoModal() {
        promoModal.classList.remove('active');
    }
    
    function closeDeleteModal() {
        deleteModal.classList.remove('active');
    }
    
    // Edit promotion
    function editPromotion(promoId) {
        window.location.href = `promotions.php?edit=${promoId}`;
    }
    
    // Delete promotion
    function deletePromotion(promoId, promoName) {
        document.getElementById('deletePromoId').value = promoId;
        document.getElementById('deletePromoName').textContent = promoName;
        deleteModal.classList.add('active');
    }
    
    // Toggle promotion status
    function togglePromotion(promoId, newStatus) {
        document.getElementById('togglePromoId').value = promoId;
        document.getElementById('toggleStatus').value = newStatus;
        document.getElementById('toggleForm').submit();
    }
    
    // Auto-open modal if editing
    <?php if ($edit_promotion): ?>
    document.addEventListener('DOMContentLoaded', function() {
        modalTitle.innerHTML = '<i class="fas fa-edit"></i> Edit Promotion';
        promoIdInput.value = '<?php echo $edit_promotion['id']; ?>';
        updateSelectedCount();
        promoModal.classList.add('active');
    });
    <?php endif; ?>
    
    // Close modals on overlay click
    [promoModal, deleteModal].forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
            }
        });
    });
    
    // Close modals on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            promoModal.classList.remove('active');
            deleteModal.classList.remove('active');
        }
    });
    
    // Discount type change handler
    const discountType = document.getElementById('discount_type');
    const discountSuffix = document.getElementById('discount_suffix');
    const discountHint = document.getElementById('discount_hint');
    
    if (discountType && discountSuffix && discountHint) {
        discountType.addEventListener('change', function() {
            if (this.value === 'percentage') {
                discountSuffix.textContent = '%';
                discountHint.textContent = 'Enter percentage (1-100)';
                document.getElementById('discount_value').max = 100;
            } else {
                discountSuffix.textContent = 'GHS';
                discountHint.textContent = 'Enter fixed amount in GHS';
                document.getElementById('discount_value').max = 999999;
            }
        });
    }
    
    // Product selection
    function updateSelectedCount() {
        const checked = document.querySelectorAll('.product-checkbox input:checked');
        const count = checked.length;
        document.getElementById('selectedCount').textContent = count;
        
        checked.forEach(input => {
            input.closest('.product-checkbox').classList.add('selected');
        });
    }
    
    // Initialize selected count
    updateSelectedCount();
    
    // Product checkbox click handler
    document.querySelectorAll('.product-checkbox').forEach(label => {
        label.addEventListener('click', function(e) {
            // Don't trigger if clicking the checkbox input directly
            if (e.target.tagName === 'INPUT') return;
            
            const checkbox = this.querySelector('input[type="checkbox"]');
            checkbox.checked = !checkbox.checked;
            
            if (checkbox.checked) {
                this.classList.add('selected');
            } else {
                this.classList.remove('selected');
            }
            
            updateSelectedCount();
        });
    });
    
    // Product search filter
    const productSearch = document.getElementById('productSearch');
    if (productSearch) {
        productSearch.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const productCheckboxes = document.querySelectorAll('.product-checkbox');
            
            productCheckboxes.forEach(checkbox => {
                const name = checkbox.querySelector('.product-checkbox-name').textContent.toLowerCase();
                const brand = checkbox.querySelector('.product-checkbox-brand')?.textContent.toLowerCase() || '';
                
                if (name.includes(searchTerm) || brand.includes(searchTerm)) {
                    checkbox.style.display = '';
                } else {
                    checkbox.style.display = 'none';
                }
            });
        });
    }
    
    // Auto-hide alert messages
    const alertMessage = document.getElementById('alertMessage');
    if (alertMessage) {
        setTimeout(() => {
            alertMessage.style.opacity = '0';
            alertMessage.style.transition = 'opacity 0.5s ease';
            setTimeout(() => alertMessage.remove(), 500);
        }, 5000);
    }
    
    // Date validation
    const startDate = document.getElementById('start_date');
    const endDate = document.getElementById('end_date');
    
    if (startDate && endDate) {
        startDate.addEventListener('change', function() {
            endDate.min = this.value;
            if (endDate.value && endDate.value < this.value) {
                endDate.value = this.value;
            }
        });
        
        endDate.addEventListener('change', function() {
            if (startDate.value && this.value < startDate.value) {
                alert('End date must be after start date');
                this.value = startDate.value;
            }
        });
    }
</script>

<?php
// Include admin footer
require_once __DIR__ . '/footer.php';
?>
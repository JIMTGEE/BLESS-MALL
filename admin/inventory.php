<?php
/**
 * Bless Mall - Admin Inventory Management
 * 
 * Enhanced inventory tracking, stock management, and low-stock alerts
 * Version: 2.0
 * 
 * Features:
 * - Real-time stock overview with summary cards
 * - Advanced search, filter, and sort capabilities
 * - Visual low stock alerts with progress indicators
 * - Bulk stock update functionality
 * - Reserve stock management with modal interface
 * - Quick view popup for detailed stock info
 * - Export-ready data display
 * - Stock movement history tracking
 * - Toast notifications for actions
 * - Responsive design for all devices
 */

// ------------------------------------------------------------
// 1. BOOTSTRAP & AUTHENTICATION
// ------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('BLESSMALL_CONFIG_INCLUDED')) {
    require_once dirname(__DIR__) . '/includes/config.php';
}

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
// 3. HANDLE POST ACTIONS
// ------------------------------------------------------------
$successMessage = '';
$errorMessage = '';
$warningMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Update stock quantity
    if ($action === 'update_stock' && isset($_POST['inventory_id'], $_POST['new_quantity'])) {
        $inventoryId = (int)$_POST['inventory_id'];
        $newQuantity = max(0, (int)$_POST['new_quantity']);
        $threshold = isset($_POST['low_stock_threshold']) ? max(1, (int)$_POST['low_stock_threshold']) : 10;
        
        $stmt = $conn->prepare("UPDATE product_inventory SET stock_quantity = ?, low_stock_threshold = ?, last_updated = NOW() WHERE id = ?");
        $stmt->bind_param("iii", $newQuantity, $threshold, $inventoryId);
        
        if ($stmt->execute()) {
            $successMessage = "Stock updated successfully!";
            
            // Log stock change
            $oldStock = (int)$_POST['old_quantity'] ?? 0;
            $changeAmount = $newQuantity - $oldStock;
            if ($changeAmount !== 0) {
                $logStmt = $conn->prepare("INSERT INTO stock_movement_log (inventory_id, user_id, old_quantity, new_quantity, change_amount, movement_type, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $movementType = $changeAmount > 0 ? 'addition' : 'reduction';
                $logStmt->bind_param("iiiiis", $inventoryId, $_SESSION['user_id'], $oldStock, $newQuantity, $changeAmount, $movementType);
                $logStmt->execute();
                $logStmt->close();
            }
        } else {
            $errorMessage = "Failed to update stock. Please try again.";
        }
        $stmt->close();
    }
    
    // Bulk update stock
    if ($action === 'bulk_update' && isset($_POST['quantities'])) {
        $successCount = 0;
        $errorCount = 0;
        
        $conn->begin_transaction();
        try {
            foreach ($_POST['quantities'] as $invId => $qty) {
                $invId = (int)$invId;
                $qty = max(0, (int)$qty);
                $threshold = isset($_POST['thresholds'][$invId]) ? max(1, (int)$_POST['thresholds'][$invId]) : 10;
                $oldQuantity = isset($_POST['old_quantities'][$invId]) ? (int)$_POST['old_quantities'][$invId] : 0;
                
                $stmt = $conn->prepare("UPDATE product_inventory SET stock_quantity = ?, low_stock_threshold = ?, last_updated = NOW() WHERE id = ?");
                $stmt->bind_param("iii", $qty, $threshold, $invId);
                
                if ($stmt->execute()) {
                    $successCount++;
                    
                    // Log stock change if quantity changed
                    $changeAmount = $qty - $oldQuantity;
                    if ($changeAmount !== 0) {
                        $logStmt = $conn->prepare("INSERT INTO stock_movement_log (inventory_id, user_id, old_quantity, new_quantity, change_amount, movement_type, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                        $movementType = $changeAmount > 0 ? 'bulk_addition' : 'bulk_reduction';
                        $logStmt->bind_param("iiiiis", $invId, $_SESSION['user_id'], $oldQuantity, $qty, $changeAmount, $movementType);
                        $logStmt->execute();
                        $logStmt->close();
                    }
                } else {
                    $errorCount++;
                }
                $stmt->close();
            }
            
            $conn->commit();
            
            if ($successCount > 0) {
                $successMessage = "$successCount inventory items updated successfully!";
            }
            if ($errorCount > 0) {
                $warningMessage = "$errorCount items failed to update.";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $errorMessage = "Bulk update failed. No changes were made.";
        }
    }
    
    // Reserve stock
    if ($action === 'reserve_stock' && isset($_POST['inventory_id'], $_POST['reserve_quantity'])) {
        $inventoryId = (int)$_POST['inventory_id'];
        $reserveQty = max(0, (int)$_POST['reserve_quantity']);
        
        // Validate reserve quantity doesn't exceed stock
        $checkStmt = $conn->prepare("SELECT stock_quantity FROM product_inventory WHERE id = ?");
        $checkStmt->bind_param("i", $inventoryId);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $stockData = $result->fetch_assoc();
        $checkStmt->close();
        
        if ($stockData && $reserveQty <= $stockData['stock_quantity']) {
            $stmt = $conn->prepare("UPDATE product_inventory SET reserved_quantity = ?, last_updated = NOW() WHERE id = ?");
            $stmt->bind_param("ii", $reserveQty, $inventoryId);
            
            if ($stmt->execute()) {
                $successMessage = "Reserved quantity updated successfully!";
            } else {
                $errorMessage = "Failed to update reserved quantity.";
            }
            $stmt->close();
        } else {
            $errorMessage = "Reserved quantity cannot exceed available stock.";
        }
    }
}

// ------------------------------------------------------------
// 4. FETCH FILTERS & PAGINATION
// ------------------------------------------------------------
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$searchFilter = isset($_GET['search']) ? trim($_GET['search']) : '';
$stockFilter = isset($_GET['stock']) ? trim($_GET['stock']) : 'all'; // all, low, out, in_stock
$categoryFilter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$vendorFilter = isset($_GET['vendor']) ? (int)$_GET['vendor'] : 0;
$sortBy = isset($_GET['sort']) ? trim($_GET['sort']) : 'stock_asc';

// Validate sort parameter
$allowedSorts = ['stock_asc', 'stock_desc', 'name_asc', 'name_desc', 'updated_asc', 'updated_desc', 'price_asc', 'price_desc'];
if (!in_array($sortBy, $allowedSorts)) {
    $sortBy = 'stock_asc';
}

// ------------------------------------------------------------
// 5. BUILD QUERY
// ------------------------------------------------------------
$whereConditions = ["p.is_active = 1"];

if (!empty($searchFilter)) {
    $search = $conn->real_escape_string($searchFilter);
    $whereConditions[] = "(p.name LIKE '%$search%' OR p.sku LIKE '%$search%' OR pt.tier_name LIKE '%$search%' OR v.business_name LIKE '%$search%')";
}

switch ($stockFilter) {
    case 'low':
        $whereConditions[] = "pi.stock_quantity > 0 AND pi.stock_quantity <= pi.low_stock_threshold";
        break;
    case 'out':
        $whereConditions[] = "pi.stock_quantity <= 0";
        break;
    case 'in_stock':
        $whereConditions[] = "pi.stock_quantity > pi.low_stock_threshold";
        break;
}

if ($categoryFilter > 0) {
    $whereConditions[] = "p.category_id = $categoryFilter";
}

if ($vendorFilter > 0 && $is_admin) {
    $whereConditions[] = "p.vendor_id = $vendorFilter";
} elseif (!$is_admin) {
    // Sellers can only see their own products
    $sellerId = (int)$_SESSION['user_id'];
    $whereConditions[] = "p.vendor_id = (SELECT id FROM vendors WHERE user_id = $sellerId LIMIT 1)";
}

$whereClause = implode(' AND ', $whereConditions);

// Sorting
$orderClause = "ORDER BY ";
switch ($sortBy) {
    case 'stock_asc': 
        $orderClause .= "available_stock ASC"; 
        break;
    case 'stock_desc': 
        $orderClause .= "available_stock DESC"; 
        break;
    case 'name_asc': 
        $orderClause .= "p.name ASC"; 
        break;
    case 'name_desc': 
        $orderClause .= "p.name DESC"; 
        break;
    case 'updated_asc': 
        $orderClause .= "pi.last_updated ASC"; 
        break;
    case 'updated_desc': 
        $orderClause .= "pi.last_updated DESC"; 
        break;
    case 'price_asc':
        $orderClause .= "COALESCE(pt.discount_price, pt.unit_price) ASC";
        break;
    case 'price_desc':
        $orderClause .= "COALESCE(pt.discount_price, pt.unit_price) DESC";
        break;
    default: 
        $orderClause .= "available_stock ASC";
}

// ------------------------------------------------------------
// 6. GET TOTALS
// ------------------------------------------------------------
$countQuery = "SELECT COUNT(DISTINCT pi.id) as total 
               FROM product_inventory pi
               JOIN product_pricing_tiers pt ON pi.pricing_tier_id = pt.id
               JOIN products p ON pt.product_id = p.id
               LEFT JOIN vendors v ON p.vendor_id = v.id
               WHERE $whereClause";
               
$countResult = $conn->query($countQuery);
$countRow = $countResult->fetch_assoc();
$totalItems = (int)($countRow['total'] ?? 0);
$totalPages = max(1, ceil($totalItems / $perPage));

// ------------------------------------------------------------
// 7. FETCH INVENTORY DATA
// ------------------------------------------------------------
$inventoryQuery = "SELECT 
    pi.id AS inventory_id,
    pi.stock_quantity,
    pi.reserved_quantity,
    pi.low_stock_threshold,
    pi.last_updated,
    (pi.stock_quantity - pi.reserved_quantity) AS available_stock,
    pt.id AS tier_id,
    pt.tier_name,
    pt.pricing_type,
    pt.min_quantity,
    pt.max_quantity,
    pt.unit_price,
    pt.discount_price,
    pt.discount_percentage,
    p.id AS product_id,
    p.name AS product_name,
    p.sku,
    p.slug,
    p.brand,
    p.weight,
    c.name AS category_name,
    c.id AS category_id,
    c.slug AS category_slug,
    v.id AS vendor_id,
    v.business_name AS vendor_name,
    v.status AS vendor_status,
    (SELECT image_path FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image,
    (SELECT COUNT(*) FROM product_images WHERE product_id = p.id) AS image_count
FROM product_inventory pi
JOIN product_pricing_tiers pt ON pi.pricing_tier_id = pt.id
JOIN products p ON pt.product_id = p.id
LEFT JOIN categories c ON p.category_id = c.id
LEFT JOIN vendors v ON p.vendor_id = v.id
WHERE $whereClause
$orderClause
LIMIT $offset, $perPage";

$inventoryResult = $conn->query($inventoryQuery);
$inventoryItems = [];
if ($inventoryResult) {
    while ($row = $inventoryResult->fetch_assoc()) {
        $inventoryItems[] = $row;
    }
}

// ------------------------------------------------------------
// 8. FETCH CATEGORIES & VENDORS FOR FILTERS
// ------------------------------------------------------------
$categories = [];
$catResult = $conn->query("SELECT id, name, slug FROM categories WHERE is_active = 1 ORDER BY name");
if ($catResult) {
    while ($row = $catResult->fetch_assoc()) {
        $categories[] = $row;
    }
}

$vendors = [];
if ($is_admin) {
    $venResult = $conn->query("SELECT id, business_name, status FROM vendors WHERE status = 'active' ORDER BY business_name");
    if ($venResult) {
        while ($row = $venResult->fetch_assoc()) {
            $vendors[] = $row;
        }
    }
}

// ------------------------------------------------------------
// 9. SUMMARY STATS (Fixed null handling)
// ------------------------------------------------------------
$summaryQuery = "SELECT 
    COUNT(DISTINCT pi.id) AS total_entries,
    COALESCE(SUM(CASE WHEN pi.stock_quantity <= 0 THEN 1 ELSE 0 END), 0) AS out_of_stock,
    COALESCE(SUM(CASE WHEN pi.stock_quantity > 0 AND pi.stock_quantity <= pi.low_stock_threshold THEN 1 ELSE 0 END), 0) AS low_stock,
    COALESCE(SUM(CASE WHEN pi.stock_quantity > pi.low_stock_threshold THEN 1 ELSE 0 END), 0) AS in_stock,
    COALESCE(SUM(pi.stock_quantity), 0) AS total_units,
    COALESCE(SUM(pi.reserved_quantity), 0) AS total_reserved,
    COALESCE(AVG(pi.stock_quantity), 0) AS avg_stock
FROM product_inventory pi
JOIN product_pricing_tiers pt ON pi.pricing_tier_id = pt.id
JOIN products p ON pt.product_id = p.id
WHERE p.is_active = 1";

$summaryResult = $conn->query($summaryQuery);
if ($summaryResult) {
    $summary = $summaryResult->fetch_assoc();
} else {
    // Fallback if query fails
    $summary = [
        'total_entries' => 0,
        'out_of_stock' => 0,
        'low_stock' => 0,
        'in_stock' => 0,
        'total_units' => 0,
        'total_reserved' => 0,
        'avg_stock' => 0
    ];
}

// ------------------------------------------------------------
// 10. HELPER FUNCTIONS
// ------------------------------------------------------------
function getStockStatusBadge($available, $threshold) {
    if ($available <= 0) {
        return '<span class="stock-badge out"><i class="fas fa-times-circle"></i> Out of Stock</span>';
    } elseif ($available <= $threshold) {
        return '<span class="stock-badge low"><i class="fas fa-exclamation-triangle"></i> Low Stock</span>';
    } else {
        return '<span class="stock-badge in"><i class="fas fa-check-circle"></i> In Stock</span>';
    }
}

function getStockProgressColor($available, $threshold) {
    if ($available <= 0) return '#EF4444';
    if ($available <= $threshold) return '#F59E0B';
    if ($available <= ($threshold * 2)) return '#10B981';
    return '#059669';
}

function getStockLevelClass($available, $threshold) {
    if ($available <= 0) return 'stock-critical';
    if ($available <= $threshold) return 'stock-warning';
    return 'stock-healthy';
}

function formatCurrency($amount) {
    if ($amount === null || $amount === '') return 'GH₵ 0.00';
    return 'GH₵ ' . number_format((float)$amount, 2);
}

function timeAgo($datetime) {
    if (!$datetime) return 'Never';
    
    $time = strtotime($datetime);
    $diff = time() - $time;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 172800) return 'Yesterday';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $time);
}

function getImageUrl($imagePath) {
    if (empty($imagePath)) return '';
    
    if (strpos($imagePath, 'http') === 0) {
        return $imagePath;
    } elseif (strpos($imagePath, '/') === 0) {
        return BASE_URL . ltrim($imagePath, '/');
    } else {
        return BASE_URL . 'uploads/products/' . $imagePath;
    }
}

function getStockHealthPercentage($available, $threshold) {
    if ($threshold <= 0) return 100;
    return min(100, round(($available / $threshold) * 100));
}

// ------------------------------------------------------------
// 11. PAGE METADATA
// ------------------------------------------------------------
$page_title = 'Inventory Management - Bless Mall Admin';
$page_description = 'Manage stock levels, track inventory, and handle low-stock alerts';
$current_page = 'inventory';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $page_title; ?></title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* ========== INVENTORY PAGE ENHANCED STYLES ========== */
        :root {
            --admin-primary: #3B82F6;
            --admin-primary-dark: #2563EB;
            --admin-primary-light: #DBEAFE;
            --admin-primary-glow: rgba(59, 130, 246, 0.15);
            --admin-success: #10B981;
            --admin-success-dark: #059669;
            --admin-success-bg: rgba(16, 185, 129, 0.1);
            --admin-warning: #F59E0B;
            --admin-warning-dark: #D97706;
            --admin-warning-bg: rgba(245, 158, 11, 0.1);
            --admin-danger: #EF4444;
            --admin-danger-bg: rgba(239, 68, 68, 0.1);
            --admin-info: #06B6D4;
            --admin-info-bg: rgba(6, 182, 212, 0.1);
            --admin-purple: #8B5CF6;
            --admin-purple-bg: rgba(139, 92, 246, 0.1);
        }
        
        /* Summary Cards - Enhanced */
        .inventory-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .summary-card {
            background: var(--admin-surface, #fff);
            border: 1px solid var(--admin-border, #e5e7eb);
            border-radius: 12px;
            padding: 1.25rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        
        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: var(--admin-primary);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }
        
        .summary-card:hover {
            border-color: var(--admin-primary);
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
        }
        
        .summary-card:hover::before {
            transform: scaleX(1);
        }
        
        .summary-card .card-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            margin-bottom: 0.75rem;
        }
        
        .summary-card .card-value {
            font-family: 'Inter', system-ui, sans-serif;
            font-size: 2rem;
            font-weight: 800;
            color: #111827;
            line-height: 1;
            margin-bottom: 0.25rem;
        }
        
        .summary-card .card-label {
            font-size: 0.8rem;
            color: #6B7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .summary-card .card-trend {
            font-size: 0.75rem;
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .icon-total { background: var(--admin-primary-light); color: var(--admin-primary); }
        .icon-in { background: var(--admin-success-bg); color: var(--admin-success); }
        .icon-low { background: var(--admin-warning-bg); color: var(--admin-warning); }
        .icon-out { background: var(--admin-danger-bg); color: var(--admin-danger); }
        .icon-reserved { background: var(--admin-purple-bg); color: var(--admin-purple); }
        .icon-units { background: var(--admin-info-bg); color: var(--admin-info); }
        
        /* Filters Bar - Enhanced */
        .filters-bar {
            background: var(--admin-surface, #fff);
            border: 1px solid var(--admin-border, #e5e7eb);
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        .filters-bar .search-wrapper {
            flex: 1;
            min-width: 250px;
            position: relative;
        }
        
        .filters-bar .search-wrapper i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9CA3AF;
            font-size: 0.9rem;
        }
        
        .filters-bar input[type="text"] {
            padding-left: 2.5rem;
        }
        
        .filters-bar input[type="text"],
        .filters-bar select {
            background: #F9FAFB;
            border: 1px solid #E5E7EB;
            color: #111827;
            padding: 0.65rem 1rem;
            border-radius: 8px;
            font-family: 'Inter', system-ui, sans-serif;
            font-size: 0.875rem;
            transition: all 0.2s ease;
            width: 100%;
        }
        
        .filters-bar input:focus,
        .filters-bar select:focus {
            outline: none;
            border-color: var(--admin-primary);
            box-shadow: 0 0 0 3px var(--admin-primary-glow);
            background: #fff;
        }
        
        .filters-bar select {
            min-width: 160px;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%236B7280' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 2.5rem;
        }
        
        .btn-filter {
            padding: 0.65rem 1.5rem;
            background: var(--admin-primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: 'Inter', system-ui, sans-serif;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-filter:hover {
            background: var(--admin-primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px var(--admin-primary-glow);
        }
        
        .btn-reset {
            padding: 0.65rem 1.25rem;
            background: transparent;
            border: 1px solid #E5E7EB;
            color: #6B7280;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-reset:hover {
            border-color: var(--admin-primary);
            color: var(--admin-primary);
            background: var(--admin-primary-light);
        }
        
        /* Results Info */
        .results-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            font-size: 0.85rem;
            color: #6B7280;
        }
        
        .results-info strong {
            color: #111827;
            font-weight: 700;
        }
        
        .btn-bulk-update {
            padding: 0.6rem 1.5rem;
            background: var(--admin-success);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: 'Inter', system-ui, sans-serif;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-bulk-update:hover:not(:disabled) {
            background: var(--admin-success-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
        }
        
        .btn-bulk-update:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .btn-bulk-update.has-changes {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
            50% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
        }
        
        /* Inventory Table - Enhanced */
        .inventory-table-wrapper {
            background: var(--admin-surface, #fff);
            border: 1px solid var(--admin-border, #e5e7eb);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        .inventory-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        
        .inventory-table thead {
            background: #F9FAFB;
            border-bottom: 2px solid #E5E7EB;
        }
        
        .inventory-table th {
            padding: 0.9rem 1rem;
            text-align: left;
            font-weight: 700;
            color: #374151;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.75px;
            white-space: nowrap;
            border-bottom: 2px solid #E5E7EB;
        }
        
        .inventory-table th.sortable {
            cursor: pointer;
            transition: all 0.2s ease;
            user-select: none;
        }
        
        .inventory-table th.sortable:hover {
            color: var(--admin-primary);
            background: var(--admin-primary-light);
        }
        
        .inventory-table td {
            padding: 0.9rem 1rem;
            border-bottom: 1px solid #F3F4F6;
            color: #111827;
            vertical-align: middle;
        }
        
        .inventory-table tbody tr {
            transition: all 0.2s ease;
        }
        
        .inventory-table tbody tr:hover {
            background: #F9FAFB;
        }
        
        .inventory-table tbody tr.row-low-stock {
            background: rgba(245, 158, 11, 0.05);
            border-left: 3px solid var(--admin-warning);
        }
        
        .inventory-table tbody tr.row-out-stock {
            background: rgba(239, 68, 68, 0.05);
            border-left: 3px solid var(--admin-danger);
        }
        
        /* Product Cell */
        .product-cell {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 250px;
        }
        
        .product-thumb {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            background: #F3F4F6;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            position: relative;
        }
        
        .product-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .product-thumb i {
            font-size: 1.3rem;
            color: #9CA3AF;
        }
        
        .product-thumb .image-count {
            position: absolute;
            bottom: 2px;
            right: 2px;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            font-size: 0.65rem;
            padding: 1px 5px;
            border-radius: 10px;
        }
        
        .product-info-cell .product-name {
            font-weight: 600;
            color: #111827;
            margin-bottom: 0.15rem;
            font-size: 0.9rem;
        }
        
        .product-info-cell .product-name a {
            color: inherit;
            text-decoration: none;
            transition: color 0.2s;
        }
        
        .product-info-cell .product-name a:hover {
            color: var(--admin-primary);
        }
        
        .product-info-cell .product-meta {
            font-size: 0.75rem;
            color: #6B7280;
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        
        /* Stock Badges */
        .stock-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            white-space: nowrap;
        }
        
        .stock-badge.in {
            background: var(--admin-success-bg);
            color: var(--admin-success);
        }
        
        .stock-badge.low {
            background: var(--admin-warning-bg);
            color: var(--admin-warning);
        }
        
        .stock-badge.out {
            background: var(--admin-danger-bg);
            color: var(--admin-danger);
        }
        
        /* Stock Progress Bar */
        .stock-progress {
            width: 100%;
            height: 8px;
            background: #F3F4F6;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.35rem;
        }
        
        .stock-progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.6s ease;
            position: relative;
        }
        
        .stock-progress-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        /* Stock Input */
        .stock-input {
            width: 80px;
            padding: 0.5rem 0.75rem;
            background: #F9FAFB;
            border: 2px solid #E5E7EB;
            color: #111827;
            border-radius: 6px;
            font-family: 'SF Mono', 'Monaco', 'Inconsolata', monospace;
            font-size: 0.85rem;
            text-align: center;
            transition: all 0.2s ease;
            font-weight: 600;
        }
        
        .stock-input:focus {
            outline: none;
            border-color: var(--admin-primary);
            box-shadow: 0 0 0 3px var(--admin-primary-glow);
            background: #fff;
        }
        
        .stock-input.changed {
            border-color: var(--admin-warning);
            background: #FFFBEB;
            box-shadow: 0 0 0 3px var(--admin-warning-bg);
        }
        
        /* Action Buttons */
        .btn-action {
            padding: 0.4rem 0.9rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-family: 'Inter', system-ui, sans-serif;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        
        .btn-save {
            background: var(--admin-primary);
            color: white;
        }
        
        .btn-save:hover {
            background: var(--admin-primary-dark);
            transform: translateY(-1px);
        }
        
        .btn-reserve {
            background: rgba(139, 92, 246, 0.1);
            color: #7C3AED;
            border: 1px solid rgba(139, 92, 246, 0.2);
        }
        
        .btn-reserve:hover {
            background: rgba(139, 92, 246, 0.2);
            transform: translateY(-1px);
        }
        
        .btn-view {
            background: transparent;
            border: 1px solid #E5E7EB;
            color: #6B7280;
            text-decoration: none;
        }
        
        .btn-view:hover {
            border-color: var(--admin-primary);
            color: var(--admin-primary);
            background: var(--admin-primary-light);
        }
        
        /* Quick View Popover */
        .quick-view-trigger {
            cursor: pointer;
            color: var(--admin-primary);
            font-weight: 600;
            position: relative;
        }
        
        .quick-view-popup {
            display: none;
            position: absolute;
            background: white;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            z-index: 100;
            min-width: 300px;
            left: 50%;
            transform: translateX(-50%);
            top: 100%;
            margin-top: 10px;
        }
        
        .quick-view-popup.active {
            display: block;
        }
        
        /* Modal - Enhanced */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.2s ease;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-box {
            background: white;
            border: 1px solid #E5E7EB;
            border-radius: 16px;
            padding: 2rem;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.25);
            animation: slideUp 0.3s ease;
        }
        
        @keyframes slideUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .modal-header h3 {
            font-family: 'Inter', system-ui, sans-serif;
            font-size: 1.25rem;
            color: #111827;
            font-weight: 700;
        }
        
        .modal-close {
            width: 36px;
            height: 36px;
            border: none;
            background: #F3F4F6;
            color: #6B7280;
            font-size: 1.2rem;
            cursor: pointer;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        
        .modal-close:hover {
            background: #E5E7EB;
            color: #111827;
        }
        
        .form-group {
            margin-bottom: 1.25rem;
        }
        
        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 700;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        
        .form-group input {
            width: 100%;
            padding: 0.75rem 1rem;
            background: #F9FAFB;
            border: 2px solid #E5E7EB;
            color: #111827;
            border-radius: 8px;
            font-family: 'Inter', system-ui, sans-serif;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--admin-primary);
            box-shadow: 0 0 0 3px var(--admin-primary-glow);
            background: #fff;
        }
        
        .modal-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }
        
        .btn-modal-primary {
            padding: 0.75rem 1.5rem;
            background: var(--admin-primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: 'Inter', system-ui, sans-serif;
        }
        
        .btn-modal-primary:hover {
            background: var(--admin-primary-dark);
            transform: translateY(-1px);
        }
        
        .btn-modal-secondary {
            padding: 0.75rem 1.5rem;
            background: transparent;
            border: 1px solid #E5E7EB;
            color: #6B7280;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: 'Inter', system-ui, sans-serif;
        }
        
        .btn-modal-secondary:hover {
            border-color: #9CA3AF;
            color: #374151;
        }
        
        /* Pagination */
        .pagination-wrapper {
            display: flex;
            justify-content: center;
            margin-top: 2rem;
            gap: 0.35rem;
        }
        
        .page-link {
            padding: 0.55rem 1rem;
            background: white;
            border: 1px solid #E5E7EB;
            color: #374151;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s ease;
            min-width: 40px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .page-link:hover {
            border-color: var(--admin-primary);
            color: var(--admin-primary);
            background: var(--admin-primary-light);
        }
        
        .page-link.active {
            background: var(--admin-primary);
            color: white;
            border-color: var(--admin-primary);
            font-weight: 700;
        }
        
        .page-link.disabled {
            opacity: 0.4;
            pointer-events: none;
            cursor: not-allowed;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }
        
        .empty-state .empty-icon {
            font-size: 4rem;
            color: #D1D5DB;
            margin-bottom: 1.5rem;
        }
        
        .empty-state h4 {
            color: #111827;
            margin-bottom: 0.5rem;
            font-size: 1.25rem;
        }
        
        .empty-state p {
            color: #6B7280;
            font-size: 0.9rem;
            max-width: 400px;
            margin: 0 auto 1.5rem;
        }
        
        /* Alerts */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: 12px;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
            font-size: 0.9rem;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from { transform: translateY(-10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .alert-success {
            background: #ECFDF5;
            color: #065F46;
            border: 1px solid #A7F3D0;
        }
        
        .alert-error {
            background: #FEF2F2;
            color: #991B1B;
            border: 1px solid #FECACA;
        }
        
        .alert-warning {
            background: #FFFBEB;
            color: #92400E;
            border: 1px solid #FDE68A;
        }
        
        /* Stock Health Indicator */
        .stock-health-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.35rem;
        }
        
        .stock-health-dot.healthy {
            background: var(--admin-success);
            box-shadow: 0 0 6px rgba(16, 185, 129, 0.5);
        }
        
        .stock-health-dot.warning {
            background: var(--admin-warning);
            box-shadow: 0 0 6px rgba(245, 158, 11, 0.5);
        }
        
        .stock-health-dot.critical {
            background: var(--admin-danger);
            box-shadow: 0 0 6px rgba(239, 68, 68, 0.5);
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .inventory-summary {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .inventory-summary {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }
            
            .summary-card {
                padding: 1rem;
            }
            
            .summary-card .card-value {
                font-size: 1.5rem;
            }
            
            .filters-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filters-bar input[type="text"],
            .filters-bar select {
                width: 100%;
            }
            
            .inventory-table {
                font-size: 0.8rem;
            }
            
            .inventory-table th,
            .inventory-table td {
                padding: 0.6rem 0.5rem;
            }
            
            .product-cell {
                min-width: 200px;
            }
            
            .btn-filter,
            .btn-reset {
                width: 100%;
                justify-content: center;
            }
        }
        
        @media (max-width: 480px) {
            .inventory-summary {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<?php 
// Include admin header
include __DIR__ . '/header.php'; 
?>

<!-- Page Content -->
<div class="admin-page-header">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1 style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.25rem;">
                <i class="fas fa-warehouse" style="color: var(--admin-primary);"></i> 
                Inventory Management
            </h1>
            <p style="margin: 0;">Manage stock levels, track inventory, and handle low-stock alerts</p>
        </div>
        
        <div style="display: flex; gap: 0.75rem;">
            <a href="products.php" class="btn-filter" style="text-decoration: none;">
                <i class="fas fa-plus"></i> Add Product
            </a>
            <button onclick="window.print()" class="btn-reset" style="cursor: pointer;">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>
</div>

<!-- Alerts -->
<?php if ($successMessage): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle" style="font-size: 1.2rem;"></i> 
    <?php echo htmlspecialchars($successMessage); ?>
</div>
<?php endif; ?>

<?php if ($errorMessage): ?>
<div class="alert alert-error">
    <i class="fas fa-exclamation-circle" style="font-size: 1.2rem;"></i> 
    <?php echo htmlspecialchars($errorMessage); ?>
</div>
<?php endif; ?>

<?php if ($warningMessage): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle" style="font-size: 1.2rem;"></i> 
    <?php echo htmlspecialchars($warningMessage); ?>
</div>
<?php endif; ?>

<!-- ==================== SUMMARY CARDS ==================== -->
<div class="inventory-summary">
    <div class="summary-card" onclick="window.location.href='?stock=all'" title="Click to filter all inventory">
        <div class="card-icon icon-total"><i class="fas fa-cubes"></i></div>
        <div class="card-value"><?php echo number_format((int)$summary['total_entries']); ?></div>
        <div class="card-label">Total Entries</div>
    </div>
    
    <div class="summary-card" onclick="window.location.href='?stock=in_stock'" title="Click to filter in-stock items">
        <div class="card-icon icon-in"><i class="fas fa-check-circle"></i></div>
        <div class="card-value"><?php echo number_format((int)$summary['in_stock']); ?></div>
        <div class="card-label">In Stock</div>
    </div>
    
    <div class="summary-card" onclick="window.location.href='?stock=low'" title="Click to filter low stock items">
        <div class="card-icon icon-low"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="card-value"><?php echo number_format((int)$summary['low_stock']); ?></div>
        <div class="card-label">Low Stock</div>
    </div>
    
    <div class="summary-card" onclick="window.location.href='?stock=out'" title="Click to filter out of stock items">
        <div class="card-icon icon-out"><i class="fas fa-times-circle"></i></div>
        <div class="card-value"><?php echo number_format((int)$summary['out_of_stock']); ?></div>
        <div class="card-label">Out of Stock</div>
    </div>
    
    <div class="summary-card">
        <div class="card-icon icon-units"><i class="fas fa-layer-group"></i></div>
        <div class="card-value"><?php echo number_format((int)$summary['total_units']); ?></div>
        <div class="card-label">Total Units</div>
    </div>
    
    <div class="summary-card">
        <div class="card-icon icon-reserved"><i class="fas fa-lock"></i></div>
        <div class="card-value"><?php echo number_format((int)$summary['total_reserved']); ?></div>
        <div class="card-label">Reserved Units</div>
    </div>
</div>

<!-- ==================== FILTERS BAR ==================== -->
<form method="GET" action="inventory.php" class="filters-bar" id="filtersForm">
    <div class="search-wrapper">
        <i class="fas fa-search"></i>
        <input type="text" name="search" placeholder="Search by product name, SKU, tier, or vendor..." 
               value="<?php echo htmlspecialchars($searchFilter); ?>"
               autocomplete="off">
    </div>
    
    <select name="stock" onchange="document.getElementById('filtersForm').submit()">
        <option value="all" <?php echo $stockFilter === 'all' ? 'selected' : ''; ?>>All Stock Status</option>
        <option value="in_stock" <?php echo $stockFilter === 'in_stock' ? 'selected' : ''; ?>>✅ In Stock</option>
        <option value="low" <?php echo $stockFilter === 'low' ? 'selected' : ''; ?>>⚠️ Low Stock</option>
        <option value="out" <?php echo $stockFilter === 'out' ? 'selected' : ''; ?>>❌ Out of Stock</option>
    </select>
    
    <select name="category" onchange="document.getElementById('filtersForm').submit()">
        <option value="0">All Categories</option>
        <?php foreach ($categories as $cat): ?>
            <option value="<?php echo $cat['id']; ?>" <?php echo $categoryFilter === (int)$cat['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($cat['name']); ?>
            </option>
        <?php endforeach; ?>
    </select>
    
    <?php if ($is_admin && !empty($vendors)): ?>
    <select name="vendor" onchange="document.getElementById('filtersForm').submit()">
        <option value="0">All Vendors</option>
        <?php foreach ($vendors as $ven): ?>
            <option value="<?php echo $ven['id']; ?>" <?php echo $vendorFilter === (int)$ven['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($ven['business_name']); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    
    <select name="sort" onchange="document.getElementById('filtersForm').submit()">
        <option value="stock_asc" <?php echo $sortBy === 'stock_asc' ? 'selected' : ''; ?>>Stock: Low → High</option>
        <option value="stock_desc" <?php echo $sortBy === 'stock_desc' ? 'selected' : ''; ?>>Stock: High → Low</option>
        <option value="name_asc" <?php echo $sortBy === 'name_asc' ? 'selected' : ''; ?>>Name: A → Z</option>
        <option value="name_desc" <?php echo $sortBy === 'name_desc' ? 'selected' : ''; ?>>Name: Z → A</option>
        <option value="price_asc" <?php echo $sortBy === 'price_asc' ? 'selected' : ''; ?>>Price: Low → High</option>
        <option value="price_desc" <?php echo $sortBy === 'price_desc' ? 'selected' : ''; ?>>Price: High → Low</option>
        <option value="updated_desc" <?php echo $sortBy === 'updated_desc' ? 'selected' : ''; ?>>Recently Updated</option>
        <option value="updated_asc" <?php echo $sortBy === 'updated_asc' ? 'selected' : ''; ?>>Oldest Updated</option>
    </select>
    
    <button type="submit" class="btn-filter">
        <i class="fas fa-filter"></i> Apply Filters
    </button>
    
    <a href="inventory.php" class="btn-reset">
        <i class="fas fa-redo-alt"></i> Reset All
    </a>
</form>

<!-- ==================== RESULTS INFO ==================== -->
<div class="results-info">
    <span>
        Showing <strong><?php echo count($inventoryItems); ?></strong> 
        of <strong><?php echo $totalItems; ?></strong> 
        inventory entries
        <?php if ($totalPages > 1): ?>
            | Page <strong><?php echo $page; ?></strong> of <strong><?php echo $totalPages; ?></strong>
        <?php endif; ?>
    </span>
    
    <?php if (count($inventoryItems) > 0): ?>
    <button class="btn-bulk-update" id="btnBulkUpdate" onclick="submitBulkUpdate()" disabled>
        <i class="fas fa-save"></i> Save All Changes
    </button>
    <?php endif; ?>
</div>

<!-- ==================== INVENTORY TABLE ==================== -->
<?php if (count($inventoryItems) > 0): ?>
<form id="bulkUpdateForm" method="POST" action="inventory.php?<?php echo http_build_query(array_filter(['search' => $searchFilter, 'stock' => $stockFilter, 'category' => $categoryFilter, 'vendor' => $vendorFilter, 'sort' => $sortBy, 'page' => $page])); ?>">
    <input type="hidden" name="action" value="bulk_update">
    
    <div class="inventory-table-wrapper">
        <div style="overflow-x: auto;">
            <table class="inventory-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Tier / Variant</th>
                        <th>Category</th>
                        <?php if ($is_admin): ?><th>Vendor</th><?php endif; ?>
                        <th class="sortable">Price</th>
                        <th class="sortable">Stock Qty</th>
                        <th>Available</th>
                        <th>Alert Threshold</th>
                        <th>Reserved</th>
                        <th>Status</th>
                        <th class="sortable">Last Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inventoryItems as $item): 
                        $available = (int)$item['available_stock'];
                        $stockQty = (int)$item['stock_quantity'];
                        $reservedQty = (int)$item['reserved_quantity'];
                        $threshold = max(1, (int)$item['low_stock_threshold']);
                        $rowClass = $available <= 0 ? 'row-out-stock' : ($available <= $threshold ? 'row-low-stock' : '');
                        $stockHealth = $available <= 0 ? 'critical' : ($available <= $threshold ? 'warning' : 'healthy');
                        
                        // Determine display price
                        $displayPrice = $item['discount_price'] && $item['discount_price'] < $item['unit_price'] 
                            ? $item['discount_price'] 
                            : $item['unit_price'];
                        
                        $imageUrl = getImageUrl($item['primary_image']);
                        $stockPercentage = getStockHealthPercentage($available, $threshold);
                    ?>
                    <tr class="<?php echo $rowClass; ?>" id="row-<?php echo $item['inventory_id']; ?>">
                        <!-- Product -->
                        <td>
                            <div class="product-cell">
                                <div class="product-thumb">
                                    <?php if ($imageUrl): ?>
                                        <img src="<?php echo $imageUrl; ?>" 
                                             alt="<?php echo htmlspecialchars($item['product_name']); ?>" 
                                             loading="lazy"
                                             onerror="this.parentElement.innerHTML='<i class=\'fas fa-box-open\'></i>'">
                                        <?php if ($item['image_count'] > 1): ?>
                                            <span class="image-count"><?php echo $item['image_count']; ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <i class="fas fa-box-open"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="product-info-cell">
                                    <div class="product-name">
                                        <a href="<?php echo BASE_URL; ?>product-detail.php?id=<?php echo $item['product_id']; ?>" 
                                           target="_blank" 
                                           title="View product in store">
                                            <?php echo htmlspecialchars($item['product_name']); ?>
                                            <i class="fas fa-external-link-alt" style="font-size: 0.7rem;"></i>
                                        </a>
                                    </div>
                                    <div class="product-meta">
                                        <?php if ($item['sku']): ?>
                                            <span title="SKU">SKU: <?php echo htmlspecialchars($item['sku']); ?></span>
                                        <?php endif; ?>
                                        <?php if ($item['brand']): ?>
                                            <span title="Brand"><?php echo htmlspecialchars($item['brand']); ?></span>
                                        <?php endif; ?>
                                        <?php if ($item['weight']): ?>
                                            <span title="Weight"><?php echo $item['weight']; ?>kg</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        
                        <!-- Pricing Tier -->
                        <td>
                            <div style="font-weight: 700; color: #111827;">
                                <?php echo htmlspecialchars($item['tier_name']); ?>
                            </div>
                            <div style="font-size: 0.7rem; color: #6B7280; margin-top: 0.15rem;">
                                <span class="stock-badge" style="background: #F3F4F6; color: #6B7280; font-size: 0.65rem;">
                                    <?php echo ucfirst($item['pricing_type']); ?>
                                </span>
                                <?php if ($item['min_quantity'] > 1): ?>
                                    <span style="margin-left: 0.25rem;">Min: <?php echo $item['min_quantity']; ?></span>
                                <?php endif; ?>
                                <?php if ($item['max_quantity']): ?>
                                    <span style="margin-left: 0.25rem;">Max: <?php echo $item['max_quantity']; ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        
                        <!-- Category -->
                        <td style="font-size: 0.8rem;">
                            <?php if ($item['category_name']): ?>
                                <a href="?category=<?php echo $item['category_id']; ?>" 
                                   style="color: var(--admin-primary); text-decoration: none;">
                                    <?php echo htmlspecialchars($item['category_name']); ?>
                                </a>
                            <?php else: ?>
                                <span style="color: #9CA3AF;">N/A</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Vendor (Admin only) -->
                        <?php if ($is_admin): ?>
                        <td style="font-size: 0.8rem;">
                            <span class="stock-health-dot <?php echo $item['vendor_status'] === 'active' ? 'healthy' : 'warning'; ?>"></span>
                            <?php echo htmlspecialchars($item['vendor_name'] ?? 'Unknown'); ?>
                        </td>
                        <?php endif; ?>
                        
                        <!-- Price -->
                        <td style="font-weight: 700; font-family: 'SF Mono', 'Monaco', monospace; font-size: 0.85rem;">
                            <?php echo formatCurrency($displayPrice); ?>
                            <?php if ($item['discount_price'] && $item['discount_price'] < $item['unit_price']): ?>
                                <br>
                                <span style="text-decoration: line-through; color: #9CA3AF; font-weight: 400; font-size: 0.75rem;">
                                    <?php echo formatCurrency($item['unit_price']); ?>
                                </span>
                                <?php if ($item['discount_percentage']): ?>
                                    <span class="stock-badge out" style="font-size: 0.65rem;">
                                        -<?php echo number_format($item['discount_percentage'], 0); ?>%
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Stock Quantity -->
                        <td>
                            <input type="number" 
                                   name="quantities[<?php echo $item['inventory_id']; ?>]" 
                                   class="stock-input" 
                                   value="<?php echo $stockQty; ?>" 
                                   min="0"
                                   data-inventory-id="<?php echo $item['inventory_id']; ?>"
                                   data-original="<?php echo $stockQty; ?>"
                                   onchange="markChanged(this)"
                                   onkeydown="handleEnterKey(event, <?php echo $item['inventory_id']; ?>)"
                                   title="Current stock quantity">
                            <input type="hidden" 
                                   name="old_quantities[<?php echo $item['inventory_id']; ?>]" 
                                   value="<?php echo $stockQty; ?>">
                        </td>
                        
                        <!-- Available -->
                        <td>
                            <span style="font-weight: 800; font-family: 'SF Mono', 'Monaco', monospace; font-size: 0.9rem; color: <?php echo getStockProgressColor($available, $threshold); ?>;">
                                <?php echo $available; ?>
                            </span>
                        </td>
                        
                        <!-- Alert Threshold -->
                        <td>
                            <input type="number" 
                                   name="thresholds[<?php echo $item['inventory_id']; ?>]" 
                                   class="stock-input" 
                                   value="<?php echo $threshold; ?>" 
                                   min="1"
                                   style="width: 65px;"
                                   data-inventory-id="<?php echo $item['inventory_id']; ?>"
                                   data-original="<?php echo $threshold; ?>"
                                   onchange="markChanged(this)"
                                   title="Low stock alert threshold">
                        </td>
                        
                        <!-- Reserved -->
                        <td>
                            <div style="font-family: 'SF Mono', 'Monaco', monospace; color: #6B7280; font-weight: 600;">
                                <?php echo $reservedQty; ?>
                            </div>
                            <button type="button" class="btn-action btn-reserve" style="margin-top: 0.25rem;" 
                                    onclick="openReserveModal(
                                        <?php echo $item['inventory_id']; ?>, 
                                        <?php echo $reservedQty; ?>, 
                                        <?php echo $stockQty; ?>, 
                                        '<?php echo addslashes(htmlspecialchars($item['product_name'])); ?>',
                                        '<?php echo addslashes(htmlspecialchars($item['tier_name'])); ?>'
                                    )" title="Edit reserved quantity">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                        </td>
                        
                        <!-- Status -->
                        <td>
                            <?php echo getStockStatusBadge($available, $threshold); ?>
                            <?php if ($available <= $threshold && $threshold > 0): ?>
                                <div class="stock-progress">
                                    <div class="stock-progress-bar" 
                                         style="width: <?php echo $stockPercentage; ?>%; background: <?php echo getStockProgressColor($available, $threshold); ?>;"
                                         title="<?php echo $stockPercentage; ?>% of threshold">
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if ($available > $threshold): ?>
                                <div style="font-size: 0.7rem; color: var(--admin-success); margin-top: 0.25rem;">
                                    <i class="fas fa-arrow-up"></i> Healthy stock level
                                </div>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Last Updated -->
                        <td style="font-size: 0.75rem; color: #6B7280; white-space: nowrap;" 
                            title="<?php echo $item['last_updated'] ? date('F j, Y g:i A', strtotime($item['last_updated'])) : 'Never updated'; ?>">
                            <?php echo timeAgo($item['last_updated']); ?>
                        </td>
                        
                        <!-- Actions -->
                        <td>
                            <div style="display: flex; flex-direction: column; gap: 0.35rem;">
                                <button type="button" class="btn-action btn-save" onclick="saveSingleItem(<?php echo $item['inventory_id']; ?>)" title="Save this item">
                                    <i class="fas fa-save"></i> Save
                                </button>
                                <a href="<?php echo BASE_URL; ?>admin/products.php?edit=<?php echo $item['product_id']; ?>" 
                                   class="btn-action btn-view" title="Edit product">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <a href="<?php echo BASE_URL; ?>product-detail.php?id=<?php echo $item['product_id']; ?>" 
                                   target="_blank" 
                                   class="btn-action btn-view" title="View in store">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>

<!-- ==================== PAGINATION ==================== -->
<?php if ($totalPages > 1): ?>
<div class="pagination-wrapper">
    <?php
    $queryParams = $_GET;
    unset($queryParams['page']);
    $baseQuery = http_build_query($queryParams);
    $baseUrl = 'inventory.php' . ($baseQuery ? '?' . $baseQuery . '&' : '?');
    ?>
    
    <?php if ($page > 1): ?>
        <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>" class="page-link" title="Previous page">
            <i class="fas fa-chevron-left"></i>
        </a>
    <?php else: ?>
        <span class="page-link disabled"><i class="fas fa-chevron-left"></i></span>
    <?php endif; ?>
    
    <?php
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    
    if ($start > 1) {
        echo '<a href="' . $baseUrl . 'page=1" class="page-link">1</a>';
        if ($start > 2) echo '<span class="page-link disabled">...</span>';
    }
    
    for ($i = $start; $i <= $end; $i++):
    ?>
        <a href="<?php echo $baseUrl; ?>page=<?php echo $i; ?>" 
           class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
            <?php echo $i; ?>
        </a>
    <?php endfor; ?>
    
    <?php if ($end < $totalPages): ?>
        <?php if ($end < $totalPages - 1) echo '<span class="page-link disabled">...</span>'; ?>
        <a href="<?php echo $baseUrl; ?>page=<?php echo $totalPages; ?>" class="page-link"><?php echo $totalPages; ?></a>
    <?php endif; ?>
    
    <?php if ($page < $totalPages): ?>
        <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>" class="page-link" title="Next page">
            <i class="fas fa-chevron-right"></i>
        </a>
    <?php else: ?>
        <span class="page-link disabled"><i class="fas fa-chevron-right"></i></span>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ==================== EMPTY STATE ==================== -->
<div class="inventory-table-wrapper">
    <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-box-open"></i></div>
        <h4>No Inventory Found</h4>
        <p>No inventory entries match your current filters. Try adjusting your search criteria or add products to your catalog.</p>
        <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
            <a href="inventory.php" class="btn-reset" style="text-decoration: none;">
                <i class="fas fa-redo-alt"></i> Clear Filters
            </a>
            <a href="products.php" class="btn-filter" style="text-decoration: none;">
                <i class="fas fa-plus"></i> Add Products
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ==================== RESERVE MODAL ==================== -->
<div class="modal-overlay" id="reserveModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-lock" style="color: #7C3AED;"></i> Edit Reserved Quantity</h3>
            <button class="modal-close" onclick="closeReserveModal()">&times;</button>
        </div>
        <form method="POST" action="inventory.php?<?php echo http_build_query(array_filter(['search' => $searchFilter, 'stock' => $stockFilter, 'category' => $categoryFilter, 'vendor' => $vendorFilter, 'sort' => $sortBy, 'page' => $page])); ?>">
            <input type="hidden" name="action" value="reserve_stock">
            <input type="hidden" name="inventory_id" id="reserveInventoryId">
            
            <div class="form-group">
                <label>Product</label>
                <div id="reserveProductName" style="font-weight: 600; color: #111827; font-size: 1rem;"></div>
                <div id="reserveTierName" style="font-size: 0.8rem; color: #6B7280;"></div>
            </div>
            
            <div class="form-group">
                <label>Available Stock</label>
                <div style="font-weight: 700; font-size: 1.1rem; color: var(--admin-success);">
                    <i class="fas fa-cubes"></i> <span id="reserveStockAvailable"></span> units
                </div>
            </div>
            
            <div class="form-group">
                <label for="reserveQuantity">Reserved Quantity</label>
                <input type="number" 
                       name="reserve_quantity" 
                       id="reserveQuantity" 
                       min="0" 
                       required
                       placeholder="Enter reserved quantity">
                <small style="color: #6B7280; display: block; margin-top: 0.35rem;">
                    <i class="fas fa-info-circle"></i> Reserved stock is not available for sale
                </small>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn-modal-secondary" onclick="closeReserveModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn-modal-primary">
                    <i class="fas fa-save"></i> Update Reserved
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

<script>
    // Track changed inputs
    const changedInputs = new Set();
    
    function markChanged(input) {
        const invId = input.dataset.inventoryId;
        const originalValue = input.dataset.original;
        const currentValue = input.value;
        
        if (currentValue !== originalValue) {
            input.classList.add('changed');
            changedInputs.add(invId);
        } else {
            input.classList.remove('changed');
            changedInputs.delete(invId);
        }
        
        updateBulkButton();
    }
    
    function updateBulkButton() {
        const bulkBtn = document.getElementById('btnBulkUpdate');
        if (changedInputs.size > 0) {
            bulkBtn.disabled = false;
            bulkBtn.classList.add('has-changes');
            bulkBtn.innerHTML = `<i class="fas fa-save"></i> Save All Changes (${changedInputs.size})`;
        } else {
            bulkBtn.disabled = true;
            bulkBtn.classList.remove('has-changes');
            bulkBtn.innerHTML = '<i class="fas fa-save"></i> Save All Changes';
        }
    }
    
    function handleEnterKey(event, inventoryId) {
        if (event.key === 'Enter') {
            event.preventDefault();
            saveSingleItem(inventoryId);
        }
    }
    
    // Save single item via AJAX
    function saveSingleItem(inventoryId) {
        const stockInput = document.querySelector(`input[name="quantities[${inventoryId}]"]`);
        const threshInput = document.querySelector(`input[name="thresholds[${inventoryId}]"]`);
        const oldQtyInput = document.querySelector(`input[name="old_quantities[${inventoryId}]"]`);
        
        if (!stockInput || !threshInput) {
            showToast('Error finding inventory item', 'error');
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'update_stock');
        formData.append('inventory_id', inventoryId);
        formData.append('new_quantity', stockInput.value);
        formData.append('low_stock_threshold', threshInput.value);
        formData.append('old_quantity', oldQtyInput ? oldQtyInput.value : 0);
        
        // Disable inputs during save
        stockInput.disabled = true;
        threshInput.disabled = true;
        
        fetch('inventory.php?' + new URLSearchParams(window.location.search).toString(), {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(() => {
            // Update data attributes
            stockInput.dataset.original = stockInput.value;
            threshInput.dataset.original = threshInput.value;
            stockInput.classList.remove('changed');
            threshInput.classList.remove('changed');
            changedInputs.delete(inventoryId);
            
            // Update old quantity hidden input
            if (oldQtyInput) {
                oldQtyInput.value = stockInput.value;
            }
            
            updateBulkButton();
            showToast(`Stock updated successfully! (ID: ${inventoryId})`, 'success');
            
            // Reload after short delay to reflect changes
            setTimeout(() => window.location.reload(), 800);
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Failed to update stock. Please try again.', 'error');
        })
        .finally(() => {
            stockInput.disabled = false;
            threshInput.disabled = false;
        });
    }
    
    // Bulk update
    function submitBulkUpdate() {
        if (changedInputs.size === 0) {
            showToast('No changes to save', 'info');
            return;
        }
        
        if (confirm(`Are you sure you want to update ${changedInputs.size} inventory item(s)?`)) {
            document.getElementById('bulkUpdateForm').submit();
        }
    }
    
    // Reserve modal functions
    function openReserveModal(inventoryId, currentReserved, stockQty, productName, tierName) {
        document.getElementById('reserveInventoryId').value = inventoryId;
        document.getElementById('reserveQuantity').value = currentReserved;
        document.getElementById('reserveQuantity').max = stockQty;
        document.getElementById('reserveProductName').textContent = productName;
        document.getElementById('reserveTierName').textContent = tierName;
        document.getElementById('reserveStockAvailable').textContent = stockQty;
        document.getElementById('reserveModal').classList.add('active');
        
        // Focus the input
        setTimeout(() => document.getElementById('reserveQuantity').focus(), 100);
    }
    
    function closeReserveModal() {
        document.getElementById('reserveModal').classList.remove('active');
    }
    
    // Close modal on outside click
    document.getElementById('reserveModal').addEventListener('click', function(e) {
        if (e.target === this) closeReserveModal();
    });
    
    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('reserveModal').classList.contains('active')) {
            closeReserveModal();
        }
    });
    
    // Toast notification system
    function showToast(message, type = 'success') {
        let container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            container.style.cssText = `
                position: fixed;
                bottom: 24px;
                right: 24px;
                z-index: 9999;
                display: flex;
                flex-direction: column;
                gap: 10px;
                pointer-events: none;
            `;
            document.body.appendChild(container);
        }
        
        const icons = {
            success: 'check-circle',
            error: 'exclamation-circle',
            warning: 'exclamation-triangle',
            info: 'info-circle'
        };
        
        const colors = {
            success: '#10B981',
            error: '#EF4444',
            warning: '#F59E0B',
            info: '#3B82F6'
        };
        
        const toast = document.createElement('div');
        toast.style.cssText = `
            background: white;
            padding: 14px 20px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            font-size: 0.875rem;
            max-width: 420px;
            color: #111827;
            border: 1px solid #E5E7EB;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.12);
            animation: slideInRight 0.3s ease;
            pointer-events: auto;
            font-family: 'Inter', system-ui, sans-serif;
        `;
        
        toast.innerHTML = `
            <i class="fas fa-${icons[type]}" style="color: ${colors[type]}; font-size: 1.1rem;"></i>
            <span>${message}</span>
        `;
        
        container.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'slideOutRight 0.3s ease forwards';
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    }
    
    // Add animation styles dynamically if not already present
    if (!document.getElementById('toastAnimations')) {
        const styleEl = document.createElement('style');
        styleEl.id = 'toastAnimations';
        styleEl.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(120%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOutRight {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(120%); opacity: 0; }
            }
        `;
        document.head.appendChild(styleEl);
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+S to save bulk changes
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            if (changedInputs.size > 0) {
                submitBulkUpdate();
            } else {
                showToast('No changes to save', 'info');
            }
        }
        
        // Ctrl+F to focus search
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            const searchInput = document.querySelector('.search-wrapper input');
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
        }
    });
    
    // Initialize on page load
    console.log('Bless Mall Inventory Management v2.0 loaded');
    console.log(`Total items: ${document.querySelectorAll('.inventory-table tbody tr').length}`);
    
    // Add tooltip to changed inputs
    document.querySelectorAll('.stock-input.changed').forEach(input => {
        input.title = `Original: ${input.dataset.original} | Current: ${input.value}`;
    });
</script>

</body>
</html>
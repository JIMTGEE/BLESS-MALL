<?php
/**
 * Bless Mall - Admin Delivery Management
 * Premium Admin Dashboard - Delivery & Tracking Management
 * 
 * Features:
 * - View all deliveries with tracking references
 * - Assign riders to deliveries
 * - Update delivery status
 * - Filter by status, delivery type, partners
 * - Real-time search by tracking ref, order number
 * - View delivery details modal with order info
 * - Export delivery data
 * - Bulk status updates
 * 
 * Developed by NerdSpring Software Engineering
 * https://nerd-spring.com
 */

// ------------------------------------------------------------
// 1. BOOTSTRAP
// ------------------------------------------------------------
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';

if (!Auth::isAdmin()) {
    header('Location: ' . BASE_URL . 'account.php?error=unauthorized');
    exit;
}

$csrfToken = CSRFToken::generate();

// ------------------------------------------------------------
// 2. DATABASE CONNECTION
// ------------------------------------------------------------
$conn = getConnection();

// ------------------------------------------------------------
// 3. PARAMETER HANDLING
// ------------------------------------------------------------
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Filters
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$delivery_type_filter = isset($_GET['delivery_type']) ? trim($_GET['delivery_type']) : '';
$partner_filter = isset($_GET['partner']) ? trim($_GET['partner']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// ------------------------------------------------------------
// 4. HANDLE POST ACTIONS (AJAX-ready)
// ------------------------------------------------------------
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRFToken::validate($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Security validation failed. Refresh the page and try again.');
    }

    $action = $_POST['action'] ?? '';
    
    // Update delivery status
    if ($action === 'update_status' && isset($_POST['delivery_id'], $_POST['new_status'])) {
        $delivery_id = (int)$_POST['delivery_id'];
        $new_status = $conn->real_escape_string($_POST['new_status']);
        $rider_phone = isset($_POST['rider_phone']) ? $conn->real_escape_string(trim($_POST['rider_phone'])) : '';
        $tracking_ref = isset($_POST['tracking_ref']) ? $conn->real_escape_string(trim($_POST['tracking_ref'])) : '';
        $notes = isset($_POST['notes']) ? $conn->real_escape_string(trim($_POST['notes'])) : '';
        
        $allowed_statuses = ['pending', 'assigned', 'in_transit', 'delivered'];
        
        if (in_array($new_status, $allowed_statuses)) {
            $update_fields = ["status = '$new_status'"];
            
            if (!empty($rider_phone)) {
                $update_fields[] = "rider_phone = '$rider_phone'";
            }
            if (!empty($tracking_ref)) {
                $update_fields[] = "tracking_ref = '$tracking_ref'";
            }
            if ($new_status === 'delivered') {
                $update_fields[] = "actual_delivery_date = CURDATE()";
            }
            
            $update_sql = "UPDATE deliveries SET " . implode(', ', $update_fields) . " WHERE id = $delivery_id";
            
            if ($conn->query($update_sql)) {
                // Also update order status if delivered
                if ($new_status === 'delivered') {
                    $order_update = "UPDATE orders o 
                                    INNER JOIN deliveries d ON o.id = d.order_id 
                                    SET o.order_status = 'delivered' 
                                    WHERE d.id = $delivery_id AND o.order_status != 'delivered'";
                    $conn->query($order_update);
                }
                
                $message = "Delivery status updated successfully!";
                $message_type = 'success';
            } else {
                $message = "Failed to update delivery: " . $conn->error;
                $message_type = 'error';
            }
        }
    }
    
    // Bulk status update
    if ($action === 'bulk_update' && isset($_POST['delivery_ids'], $_POST['bulk_status'])) {
        $delivery_ids = array_map('intval', explode(',', $_POST['delivery_ids']));
        $bulk_status = $conn->real_escape_string($_POST['bulk_status']);
        
        if (!empty($delivery_ids) && in_array($bulk_status, $allowed_statuses)) {
            $ids_string = implode(',', $delivery_ids);
            $bulk_sql = "UPDATE deliveries SET status = '$bulk_status' WHERE id IN ($ids_string)";
            
            if ($conn->query($bulk_sql)) {
                $message = count($delivery_ids) . " deliveries updated successfully!";
                $message_type = 'success';
            } else {
                $message = "Bulk update failed.";
                $message_type = 'error';
            }
        }
    }
}

// ------------------------------------------------------------
// 5. BUILD QUERY
// ------------------------------------------------------------
$where_conditions = [];

if (!empty($status_filter)) {
    $where_conditions[] = "d.status = '" . $conn->real_escape_string($status_filter) . "'";
}

if (!empty($delivery_type_filter)) {
    $where_conditions[] = "d.delivery_type = '" . $conn->real_escape_string($delivery_type_filter) . "'";
}

if (!empty($partner_filter)) {
    $where_conditions[] = "d.delivery_partner = '" . $conn->real_escape_string($partner_filter) . "'";
}

if (!empty($search)) {
    $search_escaped = $conn->real_escape_string($search);
    $where_conditions[] = "(d.tracking_ref LIKE '%$search_escaped%' 
                          OR o.order_number LIKE '%$search_escaped%' 
                          OR d.rider_phone LIKE '%$search_escaped%'
                          OR v.business_name LIKE '%$search_escaped%'
                          OR u.name LIKE '%$search_escaped%')";
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(d.created_at) >= '" . $conn->real_escape_string($date_from) . "'";
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(d.created_at) <= '" . $conn->real_escape_string($date_to) . "'";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// ------------------------------------------------------------
// 6. COUNT & FETCH DELIVERIES
// ------------------------------------------------------------
$count_sql = "SELECT COUNT(*) as total 
              FROM deliveries d
              INNER JOIN orders o ON d.order_id = o.id
              LEFT JOIN vendors v ON o.vendor_id = v.id
              LEFT JOIN users u ON o.user_id = u.id
              LEFT JOIN addresses a ON d.address_id = a.id
              $where_clause";
$count_result = $conn->query($count_sql);
$total_deliveries = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_deliveries / $per_page);

$deliveries_sql = "SELECT 
                    d.id,
                    d.order_id,
                    d.address_id,
                    d.delivery_type,
                    d.delivery_partner,
                    d.tracking_ref,
                    d.rider_phone,
                    d.estimated_delivery_date,
                    d.actual_delivery_date,
                    d.status AS delivery_status,
                    d.created_at AS delivery_created,
                    o.order_number,
                    o.total_amount,
                    o.order_status,
                    o.payment_status,
                    o.created_at AS order_date,
                    o.user_id,
                    u.name AS customer_name,
                    u.phone AS customer_phone,
                    v.business_name AS vendor_name,
                    a.city,
                    a.region,
                    a.address_line1,
                    a.phone AS delivery_phone
                  FROM deliveries d
                  INNER JOIN orders o ON d.order_id = o.id
                  LEFT JOIN vendors v ON o.vendor_id = v.id
                  LEFT JOIN users u ON o.user_id = u.id
                  LEFT JOIN addresses a ON d.address_id = a.id
                  $where_clause
                  ORDER BY d.created_at DESC
                  LIMIT $offset, $per_page";
$deliveries_result = $conn->query($deliveries_sql);

$deliveries = [];
if ($deliveries_result && $deliveries_result->num_rows > 0) {
    while ($row = $deliveries_result->fetch_assoc()) {
        $deliveries[] = $row;
    }
}

// ------------------------------------------------------------
// 7. STATISTICS
// ------------------------------------------------------------
$stats = [];
$stats_sql = "SELECT 
                COUNT(*) as total_deliveries,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned_count,
                SUM(CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END) as in_transit_count,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
                SUM(CASE WHEN status = 'delivered' AND actual_delivery_date <= estimated_delivery_date THEN 1 ELSE 0 END) as on_time_count
              FROM deliveries";
$stats_result = $conn->query($stats_sql);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
}

// Delivery partners list for filter
$partners_sql = "SELECT DISTINCT delivery_partner FROM deliveries WHERE delivery_partner IS NOT NULL ORDER BY delivery_partner";
$partners_result = $conn->query($partners_sql);
$delivery_partners = [];
if ($partners_result && $partners_result->num_rows > 0) {
    while ($row = $partners_result->fetch_assoc()) {
        $delivery_partners[] = $row['delivery_partner'];
    }
}

// ------------------------------------------------------------
// 8. HELPER FUNCTIONS
// ------------------------------------------------------------
function getDeliveryStatusClass($status) {
    $classes = [
        'pending' => 'status-pending',
        'assigned' => 'status-info',
        'in_transit' => 'status-warning',
        'delivered' => 'status-success',
        'failed' => 'status-danger'
    ];
    return $classes[strtolower($status)] ?? 'status-default';
}

function getDeliveryStatusIcon($status) {
    $icons = [
        'pending' => 'fa-clock',
        'assigned' => 'fa-user-check',
        'in_transit' => 'fa-truck-fast',
        'delivered' => 'fa-circle-check',
        'failed' => 'fa-circle-xmark'
    ];
    return $icons[strtolower($status)] ?? 'fa-circle-question';
}

function formatDeliveryDate($date) {
    if (!$date) return '<span class="text-muted">N/A</span>';
    $timestamp = strtotime($date);
    $today = strtotime('today');
    $tomorrow = strtotime('tomorrow');
    
    if ($timestamp === $today) {
        return '<span class="text-warning">Today</span>';
    } elseif ($timestamp === $tomorrow) {
        return '<span class="text-info">Tomorrow</span>';
    } elseif ($timestamp < time()) {
        return '<span class="text-danger">' . date('M d, Y', $timestamp) . ' <small>(Overdue)</small></span>';
    }
    return date('M d, Y', $timestamp);
}

// Page title
$page_title = 'Delivery Management - Bless Mall Admin';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    
    <title><?php echo $page_title; ?></title>
    
    <?php 
    // Include admin header styles would be here via header.php
    // Additional page-specific styles
    ?>
    
    <style>
        /* ========== DELIVERY PAGE SPECIFIC STYLES ========== */
        
        /* Stats Cards Row */
        .delivery-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-card {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            border-radius: var(--radius-md) 0 0 var(--radius-md);
        }
        
        .stat-card.pending::before { background: var(--admin-warning); }
        .stat-card.assigned::before { background: var(--admin-info); }
        .stat-card.transit::before { background: #8B5CF6; }
        .stat-card.delivered::before { background: var(--admin-success); }
        .stat-card.total::before { background: var(--admin-primary); }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .stat-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        
        .stat-card-icon {
            width: 42px;
            height: 42px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }
        
        .stat-card.pending .stat-card-icon { background: rgba(245,158,11,0.15); color: var(--admin-warning); }
        .stat-card.assigned .stat-card-icon { background: rgba(6,182,212,0.15); color: var(--admin-info); }
        .stat-card.transit .stat-card-icon { background: rgba(139,92,246,0.15); color: #8B5CF6; }
        .stat-card.delivered .stat-card-icon { background: rgba(16,185,129,0.15); color: var(--admin-success); }
        .stat-card.total .stat-card-icon { background: rgba(59,130,246,0.15); color: var(--admin-primary); }
        
        .stat-card-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
        }
        
        .stat-card-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-card-sub {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
        
        /* Filter Bar */
        .filter-bar {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-lg);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .filter-row {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .filter-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .filter-select,
        .filter-input {
            padding: 0.5rem 0.75rem;
            background: var(--admin-bg);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-size: 0.8rem;
            font-family: var(--font-body);
            transition: var(--transition-fast);
            min-width: 140px;
        }
        
        .filter-select:focus,
        .filter-input:focus {
            outline: none;
            border-color: var(--admin-primary);
            box-shadow: 0 0 0 3px var(--admin-primary-glow);
        }
        
        .filter-input {
            min-width: 200px;
        }
        
        .filter-date {
            min-width: 130px;
        }
        
        .btn-filter {
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            transition: var(--transition-fast);
            border: 1px solid var(--admin-border);
            font-family: var(--font-body);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-filter-apply {
            background: var(--admin-primary);
            color: white;
            border-color: var(--admin-primary);
        }
        .btn-filter-apply:hover {
            background: var(--admin-primary-dark);
        }
        
        .btn-filter-reset {
            background: transparent;
            color: var(--text-secondary);
        }
        .btn-filter-reset:hover {
            background: var(--admin-surface-hover);
            color: var(--text-primary);
        }
        
        .btn-bulk {
            padding: 0.45rem 0.85rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.75rem;
            cursor: pointer;
            transition: var(--transition-fast);
            border: none;
            font-family: var(--font-body);
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-bulk-success {
            background: var(--admin-success);
            color: white;
        }
        .btn-bulk-success:hover {
            background: var(--admin-success-dark);
        }
        
        .btn-bulk-warning {
            background: var(--admin-warning);
            color: #1a1a1a;
        }
        .btn-bulk-warning:hover {
            background: #D97706;
            color: white;
        }
        
        .btn-bulk-danger {
            background: var(--admin-danger);
            color: white;
        }
        .btn-bulk-danger:hover {
            background: #DC2626;
        }
        
        /* Table Container */
        .table-container {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }
        
        .table-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--admin-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        
        .table-header h3 {
            font-family: var(--font-heading);
            font-size: 1.05rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .table-header h3 i {
            color: var(--admin-primary);
        }
        
        .results-info {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        /* Delivery Table */
        .delivery-table {
            width: 100%;
            overflow-x: auto;
        }
        
        .delivery-table table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .delivery-table thead th {
            padding: 0.75rem 1rem;
            text-align: left;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            font-weight: 600;
            background: var(--admin-bg);
            border-bottom: 1px solid var(--admin-border);
            white-space: nowrap;
        }
        
        .delivery-table tbody td {
            padding: 0.85rem 1rem;
            font-size: 0.8rem;
            color: var(--text-primary);
            border-bottom: 1px solid var(--admin-border);
            vertical-align: middle;
        }
        
        .delivery-table tbody tr {
            transition: var(--transition-fast);
        }
        
        .delivery-table tbody tr:hover {
            background: var(--admin-surface-hover);
        }
        
        .delivery-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: var(--radius-full);
            font-size: 0.68rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .status-pending {
            background: var(--admin-warning-bg);
            color: var(--admin-warning);
        }
        
        .status-info {
            background: rgba(6,182,212,0.1);
            color: var(--admin-info);
        }
        
        .status-warning {
            background: rgba(139,92,246,0.1);
            color: #8B5CF6;
        }
        
        .status-success {
            background: var(--admin-success-bg);
            color: var(--admin-success);
        }
        
        .status-danger {
            background: var(--admin-danger-bg);
            color: var(--admin-danger);
        }
        
        /* Tracking Ref */
        .tracking-ref {
            font-family: var(--font-mono);
            font-size: 0.75rem;
            background: var(--admin-bg);
            padding: 2px 8px;
            border-radius: var(--radius-xs);
            color: var(--admin-primary);
            cursor: pointer;
        }
        
        .tracking-ref:hover {
            background: var(--admin-primary-glow);
        }
        
        /* Action Buttons */
        .action-btns {
            display: flex;
            gap: 0.4rem;
        }
        
        .btn-action {
            width: 30px;
            height: 30px;
            border-radius: var(--radius-xs);
            border: 1px solid var(--admin-border);
            background: transparent;
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            transition: var(--transition-fast);
        }
        
        .btn-action:hover {
            background: var(--admin-surface-hover);
            color: var(--admin-primary);
            border-color: var(--admin-primary);
        }
        
        .btn-action.edit:hover {
            color: var(--admin-success);
            border-color: var(--admin-success);
        }
        
        .btn-action.view:hover {
            color: var(--admin-warning);
            border-color: var(--admin-warning);
        }
        
        /* Quick Status Update Dropdown */
        .status-select-cell {
            min-width: 150px;
        }
        
        .status-quick-select {
            padding: 4px 8px;
            border-radius: var(--radius-xs);
            border: 1px solid var(--admin-border);
            background: var(--admin-bg);
            color: var(--text-primary);
            font-size: 0.7rem;
            font-family: var(--font-body);
            cursor: pointer;
            width: 100%;
        }
        
        .status-quick-select:focus {
            outline: none;
            border-color: var(--admin-primary);
        }
        
        /* Customer Info */
        .customer-info {
            display: flex;
            flex-direction: column;
        }
        
        .customer-name {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.8rem;
        }
        
        .customer-phone {
            font-size: 0.7rem;
            color: var(--text-muted);
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 6px;
            padding: 1rem;
            border-top: 1px solid var(--admin-border);
        }
        
        .pagination a,
        .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            border-radius: var(--radius-xs);
            font-size: 0.8rem;
            font-weight: 500;
            text-decoration: none;
            transition: var(--transition-fast);
            border: 1px solid var(--admin-border);
            color: var(--text-secondary);
        }
        
        .pagination a:hover {
            background: var(--admin-surface-hover);
            color: var(--text-primary);
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
        
        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal-dialog {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 650px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--admin-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            font-family: var(--font-heading);
            font-size: 1.1rem;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .modal-close {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: none;
            background: none;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 1.1rem;
            transition: var(--transition-fast);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-close:hover {
            background: var(--admin-bg);
            color: var(--text-primary);
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
        
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }
        
        .detail-item {
            background: var(--admin-bg);
            border-radius: var(--radius-sm);
            padding: 1rem;
        }
        
        .detail-label {
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            margin-bottom: 0.35rem;
        }
        
        .detail-value {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.85rem;
        }
        
        /* Form Styles in Modal */
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 0.35rem;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.6rem 0.75rem;
            background: var(--admin-bg);
            border: 1px solid var(--admin-border);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-size: 0.85rem;
            font-family: var(--font-body);
            transition: var(--transition-fast);
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--admin-primary);
            box-shadow: 0 0 0 3px var(--admin-primary-glow);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .btn {
            padding: 0.6rem 1.25rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: var(--transition-fast);
            font-family: var(--font-body);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-primary {
            background: var(--admin-primary);
            color: white;
        }
        .btn-primary:hover {
            background: var(--admin-primary-dark);
        }
        
        .btn-secondary {
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid var(--admin-border);
        }
        .btn-secondary:hover {
            background: var(--admin-surface-hover);
            color: var(--text-primary);
        }
        
        .btn-success {
            background: var(--admin-success);
            color: white;
        }
        .btn-success:hover {
            background: var(--admin-success-dark);
        }
        
        /* Alert Messages */
        .alert {
            padding: 1rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
        }
        
        .alert-success {
            background: var(--admin-success-bg);
            color: var(--admin-success);
            border: 1px solid rgba(16,185,129,0.3);
        }
        
        .alert-error {
            background: var(--admin-danger-bg);
            color: var(--admin-danger);
            border: 1px solid rgba(239,68,68,0.3);
        }
        
        /* Checkbox for bulk */
        .checkbox-cell {
            width: 40px;
        }
        
        .checkbox-cell input {
            accent-color: var(--admin-primary);
            width: 16px;
            height: 16px;
            cursor: pointer;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
        }
        
        .empty-state-icon {
            font-size: 3rem;
            color: var(--text-muted);
            opacity: 0.4;
            margin-bottom: 1rem;
        }
        
        .empty-state h4 {
            font-size: 1rem;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .filter-row {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .filter-select,
            .filter-input {
                flex: 1;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .delivery-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .delivery-table {
                font-size: 0.7rem;
            }
            
            .action-btns {
                flex-direction: column;
            }
        }
        
        @media (max-width: 480px) {
            .delivery-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

    <?php 
    // Include admin header
    require_once __DIR__ . '/header.php'; 
    ?>

    <!-- ==================== BREADCRUMB ==================== -->
    <div class="admin-breadcrumb">
        <a href="index.php"><i class="fas fa-home"></i> Dashboard</a>
        <i class="fas fa-chevron-right separator"></i>
        <span class="current">Delivery Management</span>
    </div>

    <!-- ==================== PAGE HEADER ==================== -->
    <div class="admin-page-header">
        <h1>
            <i class="fas fa-truck-fast"></i> Delivery Management
        </h1>
        <p>Manage deliveries, assign riders, and track shipments</p>
    </div>

    <!-- ==================== ALERT MESSAGES ==================== -->
    <?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $message_type; ?>" id="alertMessage">
        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo $message; ?>
        <button onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;color:inherit;cursor:pointer;font-size:1.1rem;">&times;</button>
    </div>
    <?php endif; ?>

    <!-- ==================== STATS CARDS ==================== -->
    <div class="delivery-stats">
        <div class="stat-card total">
            <div class="stat-card-header">
                <div class="stat-card-icon"><i class="fas fa-boxes"></i></div>
            </div>
            <div class="stat-card-value"><?php echo number_format($stats['total_deliveries'] ?? 0); ?></div>
            <div class="stat-card-label">Total Deliveries</div>
        </div>
        
        <div class="stat-card pending">
            <div class="stat-card-header">
                <div class="stat-card-icon"><i class="fas fa-clock"></i></div>
            </div>
            <div class="stat-card-value"><?php echo number_format($stats['pending_count'] ?? 0); ?></div>
            <div class="stat-card-label">Pending</div>
        </div>
        
        <div class="stat-card assigned">
            <div class="stat-card-header">
                <div class="stat-card-icon"><i class="fas fa-user-check"></i></div>
            </div>
            <div class="stat-card-value"><?php echo number_format($stats['assigned_count'] ?? 0); ?></div>
            <div class="stat-card-label">Assigned</div>
        </div>
        
        <div class="stat-card transit">
            <div class="stat-card-header">
                <div class="stat-card-icon"><i class="fas fa-truck"></i></div>
            </div>
            <div class="stat-card-value"><?php echo number_format($stats['in_transit_count'] ?? 0); ?></div>
            <div class="stat-card-label">In Transit</div>
        </div>
        
        <div class="stat-card delivered">
            <div class="stat-card-header">
                <div class="stat-card-icon"><i class="fas fa-circle-check"></i></div>
            </div>
            <div class="stat-card-value"><?php echo number_format($stats['delivered_count'] ?? 0); ?></div>
            <div class="stat-card-label">Delivered</div>
            <?php if (($stats['delivered_count'] ?? 0) > 0): ?>
            <div class="stat-card-sub">
                <?php 
                $on_time_pct = round((($stats['on_time_count'] ?? 0) / ($stats['delivered_count'] ?? 1)) * 100);
                echo "{$on_time_pct}% On-Time";
                ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==================== FILTER BAR ==================== -->
    <form method="GET" action="delivery.php" class="filter-bar" id="filterForm">
        <div class="filter-row">
            <div class="filter-group">
                <span class="filter-label"><i class="fas fa-filter"></i> Status</span>
                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="assigned" <?php echo $status_filter === 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                    <option value="in_transit" <?php echo $status_filter === 'in_transit' ? 'selected' : ''; ?>>In Transit</option>
                    <option value="delivered" <?php echo $status_filter === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                </select>
            </div>
            
            <div class="filter-group">
                <span class="filter-label"><i class="fas fa-truck"></i> Type</span>
                <select name="delivery_type" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Types</option>
                    <option value="delivery" <?php echo $delivery_type_filter === 'delivery' ? 'selected' : ''; ?>>Delivery</option>
                    <option value="pickup" <?php echo $delivery_type_filter === 'pickup' ? 'selected' : ''; ?>>Pickup</option>
                </select>
            </div>
            
            <?php if (!empty($delivery_partners)): ?>
            <div class="filter-group">
                <span class="filter-label"><i class="fas fa-building"></i> Partner</span>
                <select name="partner" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Partners</option>
                    <?php foreach ($delivery_partners as $partner): ?>
                    <option value="<?php echo htmlspecialchars($partner); ?>" <?php echo $partner_filter === $partner ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($partner); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <div class="filter-group">
                <span class="filter-label"><i class="fas fa-calendar"></i> From</span>
                <input type="date" name="date_from" class="filter-input filter-date" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            
            <div class="filter-group">
                <span class="filter-label">To</span>
                <input type="date" name="date_to" class="filter-input filter-date" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            
            <div class="filter-group" style="flex:1; min-width: 200px;">
                <input type="text" name="search" class="filter-input" placeholder="Search tracking ref, order #, customer..." value="<?php echo htmlspecialchars($search); ?>" style="width:100%;">
            </div>
            
            <div class="filter-group">
                <button type="submit" class="btn-filter btn-filter-apply">
                    <i class="fas fa-search"></i> Search
                </button>
                <a href="delivery.php" class="btn-filter btn-filter-reset">
                    <i class="fas fa-redo-alt"></i> Reset
                </a>
            </div>
        </div>
    </form>

    <!-- ==================== DELIVERIES TABLE ==================== -->
    <div class="table-container">
        <div class="table-header">
            <h3><i class="fas fa-list-ul"></i> Delivery Records</h3>
            <span class="results-info">
                Showing <?php echo count($deliveries); ?> of <?php echo $total_deliveries; ?> deliveries
                <?php if (!empty($status_filter)): ?>
                    | Filtered by: <strong><?php echo ucfirst($status_filter); ?></strong>
                <?php endif; ?>
            </span>
        </div>
        
        <?php if (!empty($deliveries)): ?>
        <div class="delivery-table">
            <table>
                <thead>
                    <tr>
                        <th class="checkbox-cell">
                            <input type="checkbox" id="selectAll" title="Select All">
                        </th>
                        <th>Tracking Ref</th>
                        <th>Order #</th>
                        <th>Customer</th>
                        <th>Vendor</th>
                        <th>Destination</th>
                        <th>Type</th>
                        <th>Partner</th>
                        <th>Rider Phone</th>
                        <th>Est. Delivery</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deliveries as $delivery): 
                        $delivery_id = (int)$delivery['id'];
                        $status = strtolower($delivery['delivery_status']);
                    ?>
                    <tr>
                        <td class="checkbox-cell">
                            <input type="checkbox" class="delivery-check" value="<?php echo $delivery_id; ?>">
                        </td>
                        <td>
                            <?php if (!empty($delivery['tracking_ref'])): ?>
                                <span class="tracking-ref" onclick="copyToClipboard('<?php echo htmlspecialchars($delivery['tracking_ref']); ?>')" title="Click to copy">
                                    <?php echo htmlspecialchars($delivery['tracking_ref']); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">Not assigned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="order-detail.php?id=<?php echo $delivery['order_id']; ?>" style="color: var(--admin-primary); text-decoration: none; font-weight: 600;">
                                #<?php echo htmlspecialchars($delivery['order_number']); ?>
                            </a>
                        </td>
                        <td>
                            <div class="customer-info">
                                <span class="customer-name"><?php echo htmlspecialchars($delivery['customer_name'] ?? 'N/A'); ?></span>
                                <span class="customer-phone"><?php echo htmlspecialchars($delivery['customer_phone'] ?? ''); ?></span>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($delivery['vendor_name'] ?? 'N/A'); ?></td>
                        <td>
                            <?php if (!empty($delivery['city'])): ?>
                                <?php echo htmlspecialchars($delivery['city']); ?>, <?php echo htmlspecialchars($delivery['region']); ?>
                            <?php else: ?>
                                <span class="text-muted">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge" style="background: rgba(107,114,128,0.1); color: var(--text-secondary);">
                                <i class="fas fa-<?php echo $delivery['delivery_type'] === 'pickup' ? 'store' : 'truck'; ?>"></i>
                                <?php echo ucfirst($delivery['delivery_type']); ?>
                            </span>
                        </td>
                        <td><?php echo !empty($delivery['delivery_partner']) ? htmlspecialchars($delivery['delivery_partner']) : '<span class="text-muted">N/A</span>'; ?></td>
                        <td>
                            <?php if (!empty($delivery['rider_phone'])): ?>
                                <a href="tel:<?php echo htmlspecialchars($delivery['rider_phone']); ?>" style="color: var(--admin-info);">
                                    <?php echo htmlspecialchars($delivery['rider_phone']); ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo formatDeliveryDate($delivery['estimated_delivery_date']); ?></td>
                        <td class="status-select-cell">
                            <select class="status-quick-select <?php echo getDeliveryStatusClass($status); ?>" 
                                    onchange="quickUpdateStatus(<?php echo $delivery_id; ?>, this.value)"
                                    style="background: <?php 
                                        $bg_colors = [
                                            'pending' => 'rgba(245,158,11,0.1)',
                                            'assigned' => 'rgba(6,182,212,0.1)',
                                            'in_transit' => 'rgba(139,92,246,0.1)',
                                            'delivered' => 'rgba(16,185,129,0.1)'
                                        ];
                                        echo $bg_colors[$status] ?? 'transparent';
                                    ?>; color: <?php 
                                        $text_colors = [
                                            'pending' => '#F59E0B',
                                            'assigned' => '#06B6D4',
                                            'in_transit' => '#8B5CF6',
                                            'delivered' => '#10B981'
                                        ];
                                        echo $text_colors[$status] ?? '#94A3B8';
                                    ?>;">
                                <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>⏳ Pending</option>
                                <option value="assigned" <?php echo $status === 'assigned' ? 'selected' : ''; ?>>👤 Assigned</option>
                                <option value="in_transit" <?php echo $status === 'in_transit' ? 'selected' : ''; ?>>🚚 In Transit</option>
                                <option value="delivered" <?php echo $status === 'delivered' ? 'selected' : ''; ?>>✅ Delivered</option>
                            </select>
                        </td>
                        <td>
                            <div class="action-btns">
                                <button class="btn-action view" onclick="viewDelivery(<?php echo $delivery_id; ?>)" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn-action edit" onclick="editDelivery(<?php echo $delivery_id; ?>)" title="Edit Delivery">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Bulk Actions -->
        <div style="padding: 0.75rem 1.5rem; border-top: 1px solid var(--admin-border); display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
            <span style="font-size: 0.75rem; color: var(--text-muted);">Bulk Update Selected:</span>
            <button class="btn-bulk btn-bulk-warning" onclick="bulkUpdateStatus('assigned')">
                <i class="fas fa-user-check"></i> Mark Assigned
            </button>
            <button class="btn-bulk" style="background: #8B5CF6; color: white;" onclick="bulkUpdateStatus('in_transit')">
                <i class="fas fa-truck"></i> Mark In Transit
            </button>
            <button class="btn-bulk btn-bulk-success" onclick="bulkUpdateStatus('delivered')">
                <i class="fas fa-check-circle"></i> Mark Delivered
            </button>
            <span class="results-info" style="margin-left: auto;">
                <span id="selectedCount">0</span> selected
            </span>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php
            $query_params = $_GET;
            unset($query_params['page']);
            $base_query = http_build_query($query_params);
            $base_url = 'delivery.php' . ($base_query ? '?' . $base_query . '&' : '?');
            ?>
            
            <?php if ($page > 1): ?>
                <a href="<?php echo $base_url; ?>page=<?php echo $page - 1; ?>"><i class="fas fa-chevron-left"></i></a>
            <?php else: ?>
                <span class="disabled"><i class="fas fa-chevron-left"></i></span>
            <?php endif; ?>
            
            <?php
            $start = max(1, $page - 2);
            $end = min($total_pages, $page + 2);
            
            if ($start > 1) {
                echo '<a href="' . $base_url . 'page=1">1</a>';
                if ($start > 2) echo '<span class="disabled">...</span>';
            }
            
            for ($i = $start; $i <= $end; $i++): ?>
                <a href="<?php echo $base_url; ?>page=<?php echo $i; ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor;
            
            if ($end < $total_pages) {
                if ($end < $total_pages - 1) echo '<span class="disabled">...</span>';
                echo '<a href="' . $base_url . 'page=' . $total_pages . '">' . $total_pages . '</a>';
            }
            ?>
            
            <?php if ($page < $total_pages): ?>
                <a href="<?php echo $base_url; ?>page=<?php echo $page + 1; ?>"><i class="fas fa-chevron-right"></i></a>
            <?php else: ?>
                <span class="disabled"><i class="fas fa-chevron-right"></i></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <!-- Empty State -->
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fas fa-truck-fast"></i>
            </div>
            <h4>No Deliveries Found</h4>
            <p><?php echo !empty($search) ? 'No deliveries match your search criteria.' : 'No delivery records available yet.'; ?></p>
            <?php if (!empty($search) || !empty($status_filter)): ?>
                <a href="delivery.php" class="btn btn-secondary" style="margin-top: 1rem;">Clear Filters</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ==================== VIEW/EDIT MODAL ==================== -->
    <div class="modal-overlay" id="deliveryModal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3><i class="fas fa-truck"></i> <span id="modalTitle">Delivery Details</span></h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Populated by JavaScript -->
            </div>
            <div class="modal-footer" id="modalFooter">
                <!-- Populated by JavaScript -->
            </div>
        </div>
    </div>

    <?php 
    // Include admin footer
    require_once __DIR__ . '/footer.php'; 
    ?>

    <script>
        // ============ DELIVERY MANAGEMENT SCRIPTS ============
        const csrfToken = <?php echo json_encode($csrfToken); ?>;
        
        // Delivery data cache
        let deliveriesData = <?php echo json_encode($deliveries); ?>;
        
        // Modal elements
        const modal = document.getElementById('deliveryModal');
        const modalTitle = document.getElementById('modalTitle');
        const modalBody = document.getElementById('modalBody');
        const modalFooter = document.getElementById('modalFooter');
        
        // Checkbox handling
        const selectAllCheckbox = document.getElementById('selectAll');
        const deliveryChecks = document.querySelectorAll('.delivery-check');
        const selectedCountEl = document.getElementById('selectedCount');
        
        function updateSelectedCount() {
            const checked = document.querySelectorAll('.delivery-check:checked');
            if (selectedCountEl) {
                selectedCountEl.textContent = checked.length;
            }
        }
        
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                deliveryChecks.forEach(cb => cb.checked = this.checked);
                updateSelectedCount();
            });
        }
        
        deliveryChecks.forEach(cb => {
            cb.addEventListener('change', function() {
                updateSelectedCount();
                if (!this.checked && selectAllCheckbox) {
                    selectAllCheckbox.checked = false;
                }
                if (document.querySelectorAll('.delivery-check:checked').length === deliveryChecks.length && selectAllCheckbox) {
                    selectAllCheckbox.checked = true;
                }
            });
        });
        
        // Get selected delivery IDs
        function getSelectedIds() {
            const checked = document.querySelectorAll('.delivery-check:checked');
            return Array.from(checked).map(cb => cb.value);
        }
        
        // Bulk status update
        function bulkUpdateStatus(status) {
            const ids = getSelectedIds();
            if (ids.length === 0) {
                showToast('Please select at least one delivery', 'warning');
                return;
            }
            
            if (confirm(`Update ${ids.length} deliveries to "${status}"?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                    <input type="hidden" name="action" value="bulk_update">
                    <input type="hidden" name="delivery_ids" value="${ids.join(',')}">
                    <input type="hidden" name="bulk_status" value="${status}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Quick status update from dropdown
        function quickUpdateStatus(deliveryId, newStatus) {
            if (confirm(`Update delivery #${deliveryId} status to "${newStatus}"?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="delivery_id" value="${deliveryId}">
                    <input type="hidden" name="new_status" value="${newStatus}">
                `;
                document.body.appendChild(form);
                form.submit();
            } else {
                // Revert the select
                location.reload();
            }
        }
        
        // View delivery details
        function viewDelivery(deliveryId) {
            const delivery = deliveriesData.find(d => d.id == deliveryId);
            if (!delivery) {
                showToast('Delivery not found', 'error');
                return;
            }
            
            modalTitle.innerHTML = '<i class="fas fa-eye"></i> Delivery Details';
            
            modalBody.innerHTML = `
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-label">Tracking Reference</div>
                        <div class="detail-value">${delivery.tracking_ref || 'Not assigned'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Order Number</div>
                        <div class="detail-value">
                            <a href="order-detail.php?id=${delivery.order_id}" style="color: var(--admin-primary);">#${delivery.order_number}</a>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Customer</div>
                        <div class="detail-value">${delivery.customer_name || 'N/A'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Customer Phone</div>
                        <div class="detail-value">${delivery.customer_phone || 'N/A'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Vendor</div>
                        <div class="detail-value">${delivery.vendor_name || 'N/A'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Delivery Type</div>
                        <div class="detail-value">${delivery.delivery_type ? delivery.delivery_type.charAt(0).toUpperCase() + delivery.delivery_type.slice(1) : 'N/A'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Delivery Partner</div>
                        <div class="detail-value">${delivery.delivery_partner || 'N/A'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Rider Phone</div>
                        <div class="detail-value">${delivery.rider_phone || 'N/A'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Status</div>
                        <div class="detail-value">
                            <span class="status-badge ${getStatusClass(delivery.delivery_status)}">
                                ${delivery.delivery_status.charAt(0).toUpperCase() + delivery.delivery_status.slice(1)}
                            </span>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Estimated Delivery</div>
                        <div class="detail-value">${delivery.estimated_delivery_date || 'N/A'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Actual Delivery</div>
                        <div class="detail-value">${delivery.actual_delivery_date || 'N/A'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Created</div>
                        <div class="detail-value">${delivery.delivery_created || 'N/A'}</div>
                    </div>
                </div>
                
                <h4 style="margin: 1rem 0 0.75rem; font-size: 0.9rem;">Delivery Address</h4>
                <div class="detail-item">
                    <p style="margin:0; font-size: 0.85rem;">
                        ${delivery.address_line1 || ''} ${delivery.city ? ', ' + delivery.city : ''} ${delivery.region ? ', ' + delivery.region : ''}
                        ${delivery.delivery_phone ? '<br>Phone: ' + delivery.delivery_phone : ''}
                    </p>
                </div>
            `;
            
            modalFooter.innerHTML = `
                <button class="btn btn-secondary" onclick="closeModal()">Close</button>
                <button class="btn btn-primary" onclick="closeModal(); editDelivery(${deliveryId});">
                    <i class="fas fa-edit"></i> Edit Delivery
                </button>
            `;
            
            modal.classList.add('active');
        }
        
        // Edit delivery
        function editDelivery(deliveryId) {
            const delivery = deliveriesData.find(d => d.id == deliveryId);
            if (!delivery) {
                showToast('Delivery not found', 'error');
                return;
            }
            
            modalTitle.innerHTML = '<i class="fas fa-edit"></i> Edit Delivery';
            
            modalBody.innerHTML = `
                <form id="editDeliveryForm" method="POST" action="delivery.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="delivery_id" value="${deliveryId}">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="new_status" class="filter-select" style="width:100%;">
                                <option value="pending" ${delivery.delivery_status === 'pending' ? 'selected' : ''}>Pending</option>
                                <option value="assigned" ${delivery.delivery_status === 'assigned' ? 'selected' : ''}>Assigned</option>
                                <option value="in_transit" ${delivery.delivery_status === 'in_transit' ? 'selected' : ''}>In Transit</option>
                                <option value="delivered" ${delivery.delivery_status === 'delivered' ? 'selected' : ''}>Delivered</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Tracking Reference</label>
                            <input type="text" name="tracking_ref" value="${delivery.tracking_ref || ''}" placeholder="e.g., BLESS-TRK-123456">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Rider Phone</label>
                            <input type="tel" name="rider_phone" value="${delivery.rider_phone || ''}" placeholder="e.g., 0244123456">
                        </div>
                        <div class="form-group">
                            <label>Delivery Partner</label>
                            <input type="text" value="${delivery.delivery_partner || ''}" disabled style="opacity:0.7;">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" rows="3" placeholder="Add any notes about this delivery..."></textarea>
                    </div>
                </form>
            `;
            
            modalFooter.innerHTML = `
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-success" onclick="document.getElementById('editDeliveryForm').submit()">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            `;
            
            modal.classList.add('active');
        }
        
        // Close modal
        function closeModal() {
            modal.classList.remove('active');
        }
        
        // Close on overlay click
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeModal();
            }
        });
        
        // Close on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                closeModal();
            }
        });
        
        // Helper: Get status class
        function getStatusClass(status) {
            const classes = {
                'pending': 'status-pending',
                'assigned': 'status-info',
                'in_transit': 'status-warning',
                'delivered': 'status-success'
            };
            return classes[status] || 'status-default';
        }
        
        // Copy to clipboard
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                showToast('Tracking reference copied!', 'success');
            }).catch(() => {
                showToast('Failed to copy', 'error');
            });
        }
        
        // Auto-hide alerts
        const alertMessage = document.getElementById('alertMessage');
        if (alertMessage) {
            setTimeout(() => {
                alertMessage.style.opacity = '0';
                alertMessage.style.transition = 'opacity 0.5s ease';
                setTimeout(() => alertMessage.remove(), 500);
            }, 5000);
        }
    </script>
</body>
</html>
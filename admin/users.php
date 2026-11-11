<?php
/**
 * Bless Mall - Admin Users Management
 * Enhanced User Management Dashboard with Add User Feature
 *
 * Features:
 * - Complete user listing with advanced filters
 * - ADD NEW USER (admin can add any user type: admin, seller, buyer, deliverer)
 * - User role management (admin, seller, buyer, deliverer)
 * - Account status management (activate, deactivate, soft delete)
 * - Search and filter by role, status, date range, account type
 * - User detail modal with order history and stats
 * - Bulk actions (activate, deactivate, delete)
 * - Export to CSV
 * - User statistics dashboard
 * - AJAX-powered actions with CSRF protection
 * - Password reset functionality
 * - Automatic vendor/deliverer record creation on role change
 */

// ------------------------------------------------------------
// 1. BOOTSTRAP & AUTHENTICATION
// ------------------------------------------------------------
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

// Only admins can access this page
if (!Auth::isLoggedIn() || !Auth::isAdmin()) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

require_once __DIR__ . '/header.php';

$userId = (int)$_SESSION['user_id'];
$conn = getConnection();

// ------------------------------------------------------------
// 2. CSRF TOKEN
// ------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ------------------------------------------------------------
// 3. HANDLE AJAX REQUESTS
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action'];

    // Verify CSRF token for all POST actions
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        echo json_encode(['success' => false, 'message' => 'Security token mismatch']);
        exit;
    }

    switch ($action) {
        case 'add_user':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $roleId = (int)($_POST['role_id'] ?? 3);
            $accountType = $_POST['account_type'] ?? 'regular';
            $password = $_POST['password'] ?? '';
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            // Validation
            if (empty($name) || empty($phone) || empty($password)) {
                $response = ['success' => false, 'message' => 'Name, phone, and password are required'];
                echo json_encode($response);
                exit;
            }

            if (strlen($password) < 8) {
                $response = ['success' => false, 'message' => 'Password must be at least 8 characters'];
                echo json_encode($response);
                exit;
            }

            // Check if email exists (if provided)
            if (!empty($email)) {
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1");
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $response = ['success' => false, 'message' => 'Email already exists'];
                    echo json_encode($response);
                    exit;
                }
            }

            // Check if phone exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE phone = ? AND deleted_at IS NULL LIMIT 1");
            $stmt->bind_param('s', $phone);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $response = ['success' => false, 'message' => 'Phone number already exists'];
                echo json_encode($response);
                exit;
            }

            $hashedPassword = Auth::hashPassword($password);

            $stmt = $conn->prepare("INSERT INTO users (role_id, name, email, phone, account_type, password, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $stmt->bind_param('isssssi', $roleId, $name, $email, $phone, $accountType, $hashedPassword, $isActive);
            
            if ($stmt->execute()) {
                $newUserId = $conn->insert_id;

                // If role is seller, create vendor entry
                if ($roleId == ROLE_SELLER) {
                    $stmtVendor = $conn->prepare("INSERT INTO vendors (user_id, business_name, status, created_at, updated_at) VALUES (?, ?, 'active', NOW(), NOW())");
                    $stmtVendor->bind_param('is', $newUserId, $name);
                    $stmtVendor->execute();
                }
                // If role is deliverer (role_id = 4)
                if ($roleId == 4) {
                    $stmtDeliverer = $conn->prepare("INSERT INTO deliverers (user_id, availability_status, created_at) VALUES (?, 'offline', NOW())");
                    $stmtDeliverer->bind_param('i', $newUserId);
                    $stmtDeliverer->execute();
                }

                $response = ['success' => true, 'message' => 'User added successfully', 'user_id' => $newUserId];
            } else {
                $response = ['success' => false, 'message' => 'Failed to add user: ' . $conn->error];
            }
            break;

        case 'update_user_status':
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            $newStatus = $_POST['status'] ?? '';

            if ($targetUserId > 0 && in_array($newStatus, ['activate', 'deactivate'])) {
                $isActive = $newStatus === 'activate' ? 1 : 0;
                if ($targetUserId === $userId && $isActive === 0) {
                    $response = ['success' => false, 'message' => 'You cannot deactivate your own account'];
                } else {
                    $stmt = $conn->prepare("UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->bind_param('ii', $isActive, $targetUserId);
                    if ($stmt->execute()) {
                        $response = ['success' => true, 'message' => 'User status updated successfully'];
                    } else {
                        $response = ['success' => false, 'message' => 'Failed to update status'];
                    }
                }
            }
            break;

        case 'update_user_role':
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            $newRole = (int)($_POST['role_id'] ?? 0);

            if ($targetUserId > 0 && in_array($newRole, [ROLE_ADMIN, ROLE_SELLER, ROLE_BUYER, 4])) {
                if ($targetUserId === $userId) {
                    $response = ['success' => false, 'message' => 'You cannot change your own role'];
                } else {
                    // Get current role
                    $stmt = $conn->prepare("SELECT role_id FROM users WHERE id = ?");
                    $stmt->bind_param('i', $targetUserId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result->fetch_assoc();
                    $oldRole = $row['role_id'] ?? 0;

                    $stmt = $conn->prepare("UPDATE users SET role_id = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->bind_param('ii', $newRole, $targetUserId);
                    if ($stmt->execute()) {
                        // Handle vendor creation/deletion based on new role
                        if ($newRole == ROLE_SELLER && $oldRole != ROLE_SELLER) {
                            $userQuery = "SELECT name FROM users WHERE id = $targetUserId";
                            $userResult = $conn->query($userQuery);
                            $user = $userResult->fetch_assoc();
                            $stmtVendor = $conn->prepare("INSERT INTO vendors (user_id, business_name, status, created_at, updated_at) VALUES (?, ?, 'active', NOW(), NOW())");
                            $stmtVendor->bind_param('is', $targetUserId, $user['name']);
                            $stmtVendor->execute();
                        } elseif ($newRole == 4 && $oldRole != 4) {
                            // Create deliverer record if not exists
                            $stmtDel = $conn->prepare("INSERT INTO deliverers (user_id, availability_status, created_at) VALUES (?, 'offline', NOW())");
                            $stmtDel->bind_param('i', $targetUserId);
                            $stmtDel->execute();
                        }
                        $response = ['success' => true, 'message' => 'User role updated successfully'];
                    } else {
                        $response = ['success' => false, 'message' => 'Failed to update role'];
                    }
                }
            }
            break;

        case 'reset_user_password':
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            $newPassword = $_POST['new_password'] ?? '';

            if ($targetUserId > 0 && !empty($newPassword) && strlen($newPassword) >= 8) {
                $hashedPassword = Auth::hashPassword($newPassword);
                $stmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param('si', $hashedPassword, $targetUserId);
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Password reset successfully'];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to reset password'];
                }
            } else {
                $response = ['success' => false, 'message' => 'Password must be at least 8 characters'];
            }
            break;

        case 'delete_user':
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            if ($targetUserId > 0 && $targetUserId !== $userId) {
                $stmt = $conn->prepare("UPDATE users SET deleted_at = NOW(), is_active = 0, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param('i', $targetUserId);
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'User deleted successfully'];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to delete user'];
                }
            } else {
                $response = ['success' => false, 'message' => 'You cannot delete your own account'];
            }
            break;

        case 'bulk_action':
            $userIds = json_decode($_POST['user_ids'] ?? '[]', true);
            $bulkAction = $_POST['bulk_action'] ?? '';

            if (!empty($userIds) && in_array($bulkAction, ['activate', 'deactivate', 'delete'])) {
                $userIds = array_filter($userIds, function ($id) use ($userId) {
                    return (int)$id !== $userId;
                });
                if (empty($userIds)) {
                    $response = ['success' => false, 'message' => 'Cannot perform action on your own account'];
                    break;
                }
                $ids = implode(',', array_map('intval', $userIds));

                switch ($bulkAction) {
                    case 'activate':
                        $conn->query("UPDATE users SET is_active = 1, updated_at = NOW() WHERE id IN ($ids)");
                        $response = ['success' => true, 'message' => count($userIds) . ' users activated'];
                        break;
                    case 'deactivate':
                        $conn->query("UPDATE users SET is_active = 0, updated_at = NOW() WHERE id IN ($ids)");
                        $response = ['success' => true, 'message' => count($userIds) . ' users deactivated'];
                        break;
                    case 'delete':
                        $conn->query("UPDATE users SET deleted_at = NOW(), is_active = 0, updated_at = NOW() WHERE id IN ($ids)");
                        $response = ['success' => true, 'message' => count($userIds) . ' users deleted'];
                        break;
                }
            }
            break;

        case 'get_user_details':
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            if ($targetUserId > 0) {
                $query = "SELECT 
                            u.*,
                            r.role_name,
                            (SELECT COUNT(*) FROM orders WHERE user_id = u.id) AS total_orders,
                            (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = u.id AND order_status != 'cancelled') AS total_spent,
                            (SELECT COUNT(*) FROM wishlist WHERE user_id = u.id) AS wishlist_count,
                            (SELECT COUNT(*) FROM addresses WHERE user_id = u.id) AS addresses_count,
                            (SELECT COUNT(*) FROM reviews WHERE user_id = u.id) AS reviews_count
                          FROM users u
                          LEFT JOIN roles r ON u.role_id = r.id
                          WHERE u.id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param('i', $targetUserId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $user = $result->fetch_assoc();

                    // Recent orders
                    $ordersQuery = "SELECT 
                                        o.id, o.order_number, o.total_amount, o.order_status, o.created_at,
                                        (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS item_count
                                    FROM orders o
                                    WHERE o.user_id = ?
                                    ORDER BY o.created_at DESC
                                    LIMIT 5";
                    $stmtOrders = $conn->prepare($ordersQuery);
                    $stmtOrders->bind_param('i', $targetUserId);
                    $stmtOrders->execute();
                    $ordersResult = $stmtOrders->get_result();
                    $user['recent_orders'] = [];
                    while ($order = $ordersResult->fetch_assoc()) {
                        $user['recent_orders'][] = $order;
                    }

                    $response = ['success' => true, 'data' => $user];
                } else {
                    $response = ['success' => false, 'message' => 'User not found'];
                }
            }
            break;

        default:
            $response = ['success' => false, 'message' => 'Invalid action'];
    }

    echo json_encode($response);
    exit;
}

// ------------------------------------------------------------
// 4. HANDLE CSV EXPORT
// ------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_users') {
    if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        die('Invalid security token');
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="users_export_' . date('Y-m-d') . '.csv"');

    $query = "SELECT 
                u.id, u.name, u.email, u.phone, r.role_name, 
                u.account_type, u.is_active, u.last_login, u.created_at
              FROM users u
              LEFT JOIN roles r ON u.role_id = r.id
              WHERE u.deleted_at IS NULL
              ORDER BY u.created_at DESC";
    $result = $conn->query($query);

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Name', 'Email', 'Phone', 'Role', 'Account Type', 'Status', 'Last Login', 'Registered']);

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['id'],
            $row['name'],
            $row['email'],
            $row['phone'],
            $row['role_name'],
            $row['account_type'],
            $row['is_active'] ? 'Active' : 'Inactive',
            $row['last_login'],
            $row['created_at']
        ]);
    }
    fclose($output);
    exit;
}

// ------------------------------------------------------------
// 5. FILTER PARAMETERS
// ------------------------------------------------------------
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$roleFilter = isset($_GET['role']) ? (int)$_GET['role'] : 0;
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$searchFilter = isset($_GET['search']) ? trim($_GET['search']) : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$accountType = isset($_GET['account_type']) ? $_GET['account_type'] : '';

// ------------------------------------------------------------
// 6. BUILD QUERY
// ------------------------------------------------------------
$whereConditions = ["u.deleted_at IS NULL"];

if ($roleFilter > 0) {
    $whereConditions[] = "u.role_id = $roleFilter";
}

if ($statusFilter !== '') {
    $isActive = $statusFilter === 'active' ? 1 : 0;
    $whereConditions[] = "u.is_active = $isActive";
}

if (!empty($accountType)) {
    $typeEscaped = $conn->real_escape_string($accountType);
    $whereConditions[] = "u.account_type = '$typeEscaped'";
}

if (!empty($searchFilter)) {
    $searchEscaped = $conn->real_escape_string($searchFilter);
    $whereConditions[] = "(u.name LIKE '%$searchEscaped%' OR u.email LIKE '%$searchEscaped%' OR u.phone LIKE '%$searchEscaped%')";
}

if (!empty($dateFrom)) {
    $whereConditions[] = "DATE(u.created_at) >= '" . $conn->real_escape_string($dateFrom) . "'";
}

if (!empty($dateTo)) {
    $whereConditions[] = "DATE(u.created_at) <= '" . $conn->real_escape_string($dateTo) . "'";
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// ------------------------------------------------------------
// 7. GET STATISTICS
// ------------------------------------------------------------
$statsQuery = "SELECT 
                COUNT(*) AS total_users,
                SUM(CASE WHEN u.role_id = 1 THEN 1 ELSE 0 END) AS admin_count,
                SUM(CASE WHEN u.role_id = 2 THEN 1 ELSE 0 END) AS seller_count,
                SUM(CASE WHEN u.role_id = 3 THEN 1 ELSE 0 END) AS buyer_count,
                SUM(CASE WHEN u.role_id = 4 THEN 1 ELSE 0 END) AS deliverer_count,
                SUM(CASE WHEN u.is_active = 1 THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN u.is_active = 0 THEN 1 ELSE 0 END) AS inactive_count,
                SUM(CASE WHEN u.account_type = 'premium' THEN 1 ELSE 0 END) AS premium_count,
                SUM(CASE WHEN u.last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS active_30days,
                SUM(CASE WHEN u.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS new_this_week
              FROM users u
              $whereClause";

$statsResult = $conn->query($statsQuery);
$stats = $statsResult->fetch_assoc();

// ------------------------------------------------------------
// 8. GET USERS
// ------------------------------------------------------------
$countQuery = "SELECT COUNT(*) AS total FROM users u $whereClause";
$countResult = $conn->query($countQuery);
$totalUsers = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalUsers / $perPage);

$usersQuery = "SELECT 
                    u.id, u.name, u.email, u.phone, u.account_type, u.is_active, 
                    u.last_login, u.created_at, u.updated_at,
                    r.id AS role_id, r.role_name,
                    (SELECT COUNT(*) FROM orders WHERE user_id = u.id) AS total_orders,
                    (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = u.id AND order_status = 'delivered') AS total_spent,
                    (SELECT COUNT(*) FROM reviews WHERE user_id = u.id) AS total_reviews
                FROM users u
                LEFT JOIN roles r ON u.role_id = r.id
                $whereClause
                ORDER BY u.created_at DESC
                LIMIT $offset, $perPage";

$usersResult = $conn->query($usersQuery);
$users = [];
if ($usersResult && $usersResult->num_rows > 0) {
    while ($row = $usersResult->fetch_assoc()) {
        $users[] = $row;
    }
}

// ------------------------------------------------------------
// 9. GET ROLES
// ------------------------------------------------------------
$rolesQuery = "SELECT * FROM roles ORDER BY id ASC";
$rolesResult = $conn->query($rolesQuery);
$roles = [];
while ($role = $rolesResult->fetch_assoc()) {
    $roles[] = $role;
}

// ------------------------------------------------------------
// 10. HELPER FUNCTIONS
// ------------------------------------------------------------
function getRoleBadge($roleName, $roleId)
{
    $badges = [
        1 => ['class' => 'role-admin', 'icon' => 'fa-crown', 'label' => 'Admin'],
        2 => ['class' => 'role-seller', 'icon' => 'fa-store', 'label' => 'Seller'],
        3 => ['class' => 'role-buyer', 'icon' => 'fa-user', 'label' => 'Buyer'],
        4 => ['class' => 'role-deliverer', 'icon' => 'fa-truck', 'label' => 'Deliverer']
    ];

    $badge = $badges[$roleId] ?? ['class' => 'role-default', 'icon' => 'fa-question', 'label' => $roleName];
    return '<span class="role-badge ' . $badge['class'] . '"><i class="fas ' . $badge['icon'] . '"></i> ' . $badge['label'] . '</span>';
}

function getStatusBadge($isActive)
{
    if ($isActive) {
        return '<span class="status-badge-active"><i class="fas fa-check-circle"></i> Active</span>';
    }
    return '<span class="status-badge-inactive"><i class="fas fa-times-circle"></i> Inactive</span>';
}

function formatDate($date)
{
    if (!$date) return 'Never';
    return date('M d, Y', strtotime($date));
}

function formatDateTime($date)
{
    if (!$date) return 'Never';
    return date('M d, Y h:i A', strtotime($date));
}

function formatCurrency($amount)
{
    return 'GHS ' . number_format($amount, 2);
}

function getInitials($name)
{
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper($word[0]);
        }
    }
    return substr($initials, 0, 2);
}

// ------------------------------------------------------------
// 11. PAGE TITLE
// ------------------------------------------------------------
$page_title = 'Users Management - Bless Mall Admin';
?>

<style>
    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .stat-card {
        background: var(--admin-surface);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-md);
        padding: 1rem;
        transition: var(--transition-fast);
        cursor: pointer;
        text-decoration: none;
        color: inherit;
        display: block;
    }

    .stat-card:hover {
        background: var(--admin-surface-hover);
        border-color: var(--admin-primary);
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
        width: 36px;
        height: 36px;
        border-radius: var(--radius-sm);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
    }

    .stat-card-icon.users { background: rgba(59, 130, 246, 0.15); color: var(--admin-primary); }
    .stat-card-icon.admin { background: rgba(139, 92, 246, 0.15); color: #8B5CF6; }
    .stat-card-icon.seller { background: rgba(16, 185, 129, 0.15); color: var(--admin-success); }
    .stat-card-icon.buyer { background: rgba(6, 182, 212, 0.15); color: #06B6D4; }
    .stat-card-icon.deliverer { background: rgba(245, 158, 11, 0.15); color: #F59E0B; }
    .stat-card-icon.active { background: rgba(16, 185, 129, 0.15); color: var(--admin-success); }
    .stat-card-icon.inactive { background: rgba(239, 68, 68, 0.15); color: var(--admin-danger); }
    .stat-card-icon.premium { background: rgba(245, 158, 11, 0.15); color: var(--admin-warning); }
    .stat-card-icon.new { background: rgba(59, 130, 246, 0.15); color: var(--admin-primary); }

    .stat-card-value {
        font-size: 1.3rem;
        font-weight: 700;
        color: var(--text-primary);
        line-height: 1.2;
    }

    .stat-card-label {
        font-size: 0.7rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Filter Bar */
    .filter-bar {
        background: var(--admin-surface);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-md);
        padding: 1rem;
        margin-bottom: 1.5rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        align-items: center;
    }

    .filter-group {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .filter-select {
        background: var(--admin-bg);
        border: 1px solid var(--admin-border);
        color: var(--text-primary);
        padding: 0.5rem 2rem 0.5rem 0.75rem;
        border-radius: var(--radius-sm);
        font-size: 0.8rem;
        font-family: var(--font-body);
        cursor: pointer;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2394A3B8' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 0.5rem center;
        min-width: 120px;
    }

    .filter-select:focus {
        outline: none;
        border-color: var(--admin-primary);
    }

    .filter-input {
        background: var(--admin-bg);
        border: 1px solid var(--admin-border);
        color: var(--text-primary);
        padding: 0.5rem 0.75rem;
        border-radius: var(--radius-sm);
        font-size: 0.8rem;
        font-family: var(--font-body);
        min-width: 160px;
    }

    .filter-input:focus {
        outline: none;
        border-color: var(--admin-primary);
    }

    .btn-filter {
        padding: 0.5rem 1rem;
        background: var(--admin-primary);
        color: white;
        border: none;
        border-radius: var(--radius-sm);
        font-weight: 600;
        cursor: pointer;
        font-size: 0.8rem;
        transition: var(--transition-fast);
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .btn-filter:hover {
        background: var(--admin-primary-dark);
    }

    .btn-reset {
        padding: 0.5rem 1rem;
        background: transparent;
        border: 1px solid var(--admin-border);
        color: var(--text-secondary);
        border-radius: var(--radius-sm);
        font-weight: 500;
        cursor: pointer;
        font-size: 0.8rem;
        text-decoration: none;
        transition: var(--transition-fast);
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .btn-reset:hover {
        border-color: var(--admin-primary);
        color: var(--admin-primary);
    }

    .btn-export {
        padding: 0.5rem 1rem;
        background: var(--admin-success);
        color: white;
        border: none;
        border-radius: var(--radius-sm);
        font-weight: 600;
        cursor: pointer;
        font-size: 0.8rem;
        transition: var(--transition-fast);
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
    }

    .btn-export:hover {
        background: var(--admin-success-dark);
    }

    .btn-add-user {
        padding: 0.5rem 1rem;
        background: linear-gradient(135deg, var(--admin-primary), #8B5CF6);
        color: white;
        border: none;
        border-radius: var(--radius-sm);
        font-weight: 600;
        cursor: pointer;
        font-size: 0.8rem;
        transition: var(--transition-fast);
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .btn-add-user:hover {
        transform: translateY(-1px);
        box-shadow: var(--shadow-md);
    }

    /* Bulk Actions Bar */
    .bulk-actions-bar {
        background: var(--admin-primary-glow);
        border: 1px solid var(--admin-primary);
        border-radius: var(--radius-md);
        padding: 0.75rem 1.25rem;
        margin-bottom: 1.5rem;
        display: none;
        align-items: center;
        gap: 1rem;
        animation: slideDown 0.3s ease;
    }

    .bulk-actions-bar.active {
        display: flex;
    }

    .bulk-count {
        font-weight: 600;
        color: var(--admin-primary);
        font-size: 0.85rem;
    }

    .btn-bulk {
        padding: 0.4rem 0.85rem;
        border-radius: var(--radius-sm);
        font-size: 0.75rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: var(--transition-fast);
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }

    .btn-bulk-activate {
        background: var(--admin-success);
        color: white;
    }
    .btn-bulk-activate:hover { background: var(--admin-success-dark); }

    .btn-bulk-deactivate {
        background: var(--admin-warning);
        color: white;
    }
    .btn-bulk-deactivate:hover { background: #D97706; }

    .btn-bulk-delete {
        background: var(--admin-danger);
        color: white;
    }
    .btn-bulk-delete:hover { background: #DC2626; }

    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Table */
    .table-container {
        background: var(--admin-surface);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-md);
        overflow: hidden;
    }

    .table-header {
        padding: 1rem;
        border-bottom: 1px solid var(--admin-border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .table-header h3 {
        font-family: var(--font-heading);
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
    }

    .table-header .count {
        font-size: 0.8rem;
        color: var(--text-muted);
    }

    .users-table {
        width: 100%;
        border-collapse: collapse;
    }

    .users-table th {
        text-align: left;
        padding: 0.75rem;
        background: var(--admin-bg);
        color: var(--text-muted);
        font-weight: 600;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 1px solid var(--admin-border);
        white-space: nowrap;
    }

    .users-table td {
        padding: 0.75rem;
        border-bottom: 1px solid var(--admin-border);
        font-size: 0.8rem;
        color: var(--text-primary);
    }

    .users-table tr:hover td {
        background: rgba(59, 130, 246, 0.02);
    }

    .user-cell {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .user-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--admin-primary), #8B5CF6);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        color: white;
        font-size: 0.8rem;
        flex-shrink: 0;
    }

    .user-info .user-name {
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition-fast);
    }

    .user-info .user-name:hover {
        color: var(--admin-primary);
    }

    .user-info .user-contact {
        font-size: 0.7rem;
        color: var(--text-muted);
    }

    .role-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.65rem;
        border-radius: var(--radius-full);
        font-size: 0.7rem;
        font-weight: 600;
        white-space: nowrap;
    }

    .role-admin { background: rgba(139, 92, 246, 0.15); color: #8B5CF6; }
    .role-seller { background: rgba(16, 185, 129, 0.15); color: var(--admin-success); }
    .role-buyer { background: rgba(59, 130, 246, 0.15); color: var(--admin-primary); }
    .role-deliverer { background: rgba(245, 158, 11, 0.15); color: #F59E0B; }

    .status-badge-active, .status-badge-inactive {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.65rem;
        border-radius: var(--radius-full);
        font-size: 0.7rem;
        font-weight: 600;
    }

    .status-badge-active {
        background: rgba(16, 185, 129, 0.15);
        color: var(--admin-success);
    }

    .status-badge-inactive {
        background: rgba(239, 68, 68, 0.15);
        color: var(--admin-danger);
    }

    .action-btns {
        display: flex;
        gap: 0.35rem;
        flex-wrap: wrap;
    }

    .btn-action {
        padding: 0.3rem 0.55rem;
        border-radius: var(--radius-sm);
        font-size: 0.65rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition-fast);
        border: 1px solid transparent;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        white-space: nowrap;
    }

    .btn-view { background: rgba(59, 130, 246, 0.1); color: var(--admin-primary); border-color: rgba(59, 130, 246, 0.2); }
    .btn-view:hover { background: rgba(59, 130, 246, 0.2); }

    .btn-activate { background: rgba(16, 185, 129, 0.1); color: var(--admin-success); border-color: rgba(16, 185, 129, 0.2); }
    .btn-activate:hover { background: rgba(16, 185, 129, 0.2); }

    .btn-deactivate { background: rgba(245, 158, 11, 0.1); color: var(--admin-warning); border-color: rgba(245, 158, 11, 0.2); }
    .btn-deactivate:hover { background: rgba(245, 158, 11, 0.2); }

    .btn-delete { background: rgba(239, 68, 68, 0.1); color: var(--admin-danger); border-color: rgba(239, 68, 68, 0.2); }
    .btn-delete:hover { background: rgba(239, 68, 68, 0.2); }

    .btn-reset-password { background: rgba(59, 130, 246, 0.1); color: var(--admin-primary); border-color: rgba(59, 130, 246, 0.2); }
    .btn-reset-password:hover { background: rgba(59, 130, 246, 0.2); }

    .checkbox-cell {
        width: 40px;
        text-align: center;
    }
    .checkbox-cell input[type="checkbox"] {
        accent-color: var(--admin-primary);
        width: 16px;
        height: 16px;
        cursor: pointer;
    }

    /* Modals */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.7);
        z-index: 2000;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }
    .modal-overlay.active { display: flex; }

    .modal-dialog {
        background: var(--admin-bg-light);
        border: 1px solid var(--admin-border);
        border-radius: var(--radius-lg);
        max-width: 500px;
        width: 100%;
        max-height: 90vh;
        overflow-y: auto;
        animation: modalIn 0.3s ease;
    }
    .modal-dialog-lg { max-width: 700px; }

    @keyframes modalIn {
        from { opacity: 0; transform: scale(0.95) translateY(-20px); }
        to { opacity: 1; transform: scale(1) translateY(0); }
    }

    .modal-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid var(--admin-border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        top: 0;
        background: var(--admin-bg-light);
        z-index: 1;
        border-radius: var(--radius-lg) var(--radius-lg) 0 0;
    }

    .modal-header h3 {
        font-family: var(--font-heading);
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .modal-close {
        width: 36px;
        height: 36px;
        border: 1px solid var(--admin-border);
        background: var(--admin-surface);
        border-radius: var(--radius-sm);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: var(--text-muted);
        font-size: 1.1rem;
        transition: var(--transition-fast);
    }
    .modal-close:hover {
        background: var(--admin-danger-bg);
        color: var(--admin-danger);
        border-color: var(--admin-danger);
    }

    .modal-body { padding: 1.5rem; }

    /* Form */
    .form-group { margin-bottom: 1rem; }
    .form-group label {
        display: block;
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text-secondary);
        margin-bottom: 0.4rem;
    }
    .form-group label .required { color: var(--admin-danger); }

    .form-control {
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
    .form-control:focus {
        outline: none;
        border-color: var(--admin-primary);
    }

    .form-check {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-top: 0.5rem;
    }
    .form-check input { width: 16px; height: 16px; cursor: pointer; }
    .form-check label { margin: 0; cursor: pointer; }

    .form-actions {
        display: flex;
        gap: 0.75rem;
        justify-content: flex-end;
        margin-top: 1.5rem;
        padding-top: 1rem;
        border-top: 1px solid var(--admin-border);
    }

    .btn-submit {
        padding: 0.6rem 1.25rem;
        background: var(--admin-primary);
        color: white;
        border: none;
        border-radius: var(--radius-sm);
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition-fast);
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    .btn-submit:hover {
        background: var(--admin-primary-dark);
        transform: translateY(-1px);
    }

    .btn-cancel {
        padding: 0.6rem 1.25rem;
        background: transparent;
        border: 1px solid var(--admin-border);
        color: var(--text-secondary);
        border-radius: var(--radius-sm);
        font-weight: 500;
        cursor: pointer;
        transition: var(--transition-fast);
    }
    .btn-cancel:hover {
        border-color: var(--admin-danger);
        color: var(--admin-danger);
    }

    .password-strength { margin-top: 0.35rem; font-size: 0.7rem; }
    .strength-weak { color: var(--admin-danger); }
    .strength-medium { color: var(--admin-warning); }
    .strength-strong { color: var(--admin-success); }

    /* Pagination */
    .pagination {
        display: flex;
        justify-content: center;
        gap: 0.5rem;
        margin-top: 1.5rem;
    }
    .pagination a, .pagination span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 36px;
        height: 36px;
        padding: 0 0.75rem;
        border-radius: var(--radius-sm);
        font-weight: 500;
        font-size: 0.8rem;
        text-decoration: none;
        transition: var(--transition-fast);
        border: 1px solid var(--admin-border);
        color: var(--text-secondary);
        background: var(--admin-surface);
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

    /* Toast */
    .toast-notification {
        position: fixed;
        bottom: 20px;
        right: 20px;
        padding: 1rem 1.5rem;
        border-radius: var(--radius-md);
        box-shadow: var(--shadow-lg);
        z-index: 2100;
        animation: slideInRight 0.3s ease;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-weight: 500;
    }
    .toast-success { background: #10B981; color: white; }
    .toast-error { background: #EF4444; color: white; }
    .toast-warning { background: #F59E0B; color: white; }

    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }

    .empty-state {
        text-align: center;
        padding: 3rem 2rem;
    }
    .empty-state-icon {
        font-size: 3rem;
        color: var(--text-muted);
        opacity: 0.3;
        margin-bottom: 1rem;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .filter-bar { flex-direction: column; align-items: stretch; }
        .filter-group { flex-wrap: wrap; }
        .filter-select, .filter-input { width: 100%; min-width: auto; }
        .users-table { display: block; overflow-x: auto; }
        .action-btns { flex-direction: column; }
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .bulk-actions-bar { flex-wrap: wrap; }
    }
</style>

<!-- ==================== PAGE CONTENT ==================== -->

<div class="admin-breadcrumb">
    <a href="index.php"><i class="fas fa-home"></i> Dashboard</a>
    <span class="separator"><i class="fas fa-chevron-right"></i></span>
    <span class="current">Users Management</span>
</div>

<div class="admin-page-header">
    <h1><i class="fas fa-users"></i> Users Management</h1>
    <p>Manage all registered users, their roles, and account status</p>
</div>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-card-header">
            <div class="stat-card-icon users"><i class="fas fa-users"></i></div>
        </div>
        <div class="stat-card-value"><?php echo number_format($stats['total_users'] ?? 0); ?></div>
        <div class="stat-card-label">Total Users</div>
    </div>

    <a href="users.php?role=1" class="stat-card">
        <div class="stat-card-header">
            <div class="stat-card-icon admin"><i class="fas fa-crown"></i></div>
        </div>
        <div class="stat-card-value"><?php echo number_format($stats['admin_count'] ?? 0); ?></div>
        <div class="stat-card-label">Admins</div>
    </a>

    <a href="users.php?role=2" class="stat-card">
        <div class="stat-card-header">
            <div class="stat-card-icon seller"><i class="fas fa-store"></i></div>
        </div>
        <div class="stat-card-value"><?php echo number_format($stats['seller_count'] ?? 0); ?></div>
        <div class="stat-card-label">Sellers</div>
    </a>

    <a href="users.php?role=3" class="stat-card">
        <div class="stat-card-header">
            <div class="stat-card-icon buyer"><i class="fas fa-user"></i></div>
        </div>
        <div class="stat-card-value"><?php echo number_format($stats['buyer_count'] ?? 0); ?></div>
        <div class="stat-card-label">Buyers</div>
    </a>

    <a href="users.php?role=4" class="stat-card">
        <div class="stat-card-header">
            <div class="stat-card-icon deliverer"><i class="fas fa-truck"></i></div>
        </div>
        <div class="stat-card-value"><?php echo number_format($stats['deliverer_count'] ?? 0); ?></div>
        <div class="stat-card-label">Deliverers</div>
    </a>

    <a href="users.php?status=active" class="stat-card">
        <div class="stat-card-header">
            <div class="stat-card-icon active"><i class="fas fa-check-circle"></i></div>
        </div>
        <div class="stat-card-value"><?php echo number_format($stats['active_count'] ?? 0); ?></div>
        <div class="stat-card-label">Active</div>
    </a>

    <a href="users.php?status=inactive" class="stat-card">
        <div class="stat-card-header">
            <div class="stat-card-icon inactive"><i class="fas fa-times-circle"></i></div>
        </div>
        <div class="stat-card-value"><?php echo number_format($stats['inactive_count'] ?? 0); ?></div>
        <div class="stat-card-label">Inactive</div>
    </a>

    <a href="users.php?account_type=premium" class="stat-card">
        <div class="stat-card-header">
            <div class="stat-card-icon premium"><i class="fas fa-gem"></i></div>
        </div>
        <div class="stat-card-value"><?php echo number_format($stats['premium_count'] ?? 0); ?></div>
        <div class="stat-card-label">Premium</div>
    </a>

    <div class="stat-card">
        <div class="stat-card-header">
            <div class="stat-card-icon new"><i class="fas fa-user-plus"></i></div>
        </div>
        <div class="stat-card-value"><?php echo number_format($stats['new_this_week'] ?? 0); ?></div>
        <div class="stat-card-label">New This Week</div>
    </div>
</div>

<!-- Filter Bar -->
<form method="GET" action="users.php" class="filter-bar" id="filterForm">
    <div class="filter-group">
        <select name="role" class="filter-select" onchange="this.form.submit()">
            <option value="0">All Roles</option>
            <?php foreach ($roles as $role): ?>
                <option value="<?php echo $role['id']; ?>" <?php echo $roleFilter === (int)$role['id'] ? 'selected' : ''; ?>>
                    <?php echo ucfirst($role['role_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="filter-group">
        <select name="status" class="filter-select" onchange="this.form.submit()">
            <option value="">All Status</option>
            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>
    </div>

    <div class="filter-group">
        <select name="account_type" class="filter-select" onchange="this.form.submit()">
            <option value="">All Types</option>
            <option value="regular" <?php echo $accountType === 'regular' ? 'selected' : ''; ?>>Regular</option>
            <option value="premium" <?php echo $accountType === 'premium' ? 'selected' : ''; ?>>Premium</option>
        </select>
    </div>

    <div class="filter-group">
        <input type="date" name="date_from" class="filter-input" value="<?php echo $dateFrom; ?>" placeholder="From Date" onchange="this.form.submit()">
        <span style="color: var(--text-muted);">to</span>
        <input type="date" name="date_to" class="filter-input" value="<?php echo $dateTo; ?>" placeholder="To Date" onchange="this.form.submit()">
    </div>

    <div class="filter-group" style="flex: 1;">
        <input type="text" name="search" class="filter-input" value="<?php echo htmlspecialchars($searchFilter); ?>" placeholder="Search name, email, phone..." style="width: 100%;">
        <button type="submit" class="btn-filter"><i class="fas fa-search"></i></button>
    </div>

    <button type="button" class="btn-add-user" onclick="openAddUserModal()">
        <i class="fas fa-user-plus"></i> Add User
    </button>
    <a href="users.php" class="btn-reset"><i class="fas fa-redo"></i> Reset</a>
    <a href="users.php?action=export_users&csrf_token=<?php echo $csrfToken; ?>" class="btn-export"><i class="fas fa-download"></i> Export CSV</a>
</form>

<!-- Bulk Actions Bar -->
<div class="bulk-actions-bar" id="bulkActionsBar">
    <span class="bulk-count" id="bulkCount">0 users selected</span>
    <button class="btn-bulk btn-bulk-activate" onclick="performBulkAction('activate')">
        <i class="fas fa-check-circle"></i> Activate
    </button>
    <button class="btn-bulk btn-bulk-deactivate" onclick="performBulkAction('deactivate')">
        <i class="fas fa-pause-circle"></i> Deactivate
    </button>
    <button class="btn-bulk btn-bulk-delete" onclick="performBulkAction('delete')">
        <i class="fas fa-trash"></i> Delete
    </button>
    <button class="btn-reset" onclick="clearSelection()" style="margin-left: auto;">
        <i class="fas fa-times"></i> Clear Selection
    </button>
</div>

<!-- Users Table -->
<div class="table-container">
    <div class="table-header">
        <h3><i class="fas fa-list"></i> Users</h3>
        <span class="count"><?php echo number_format($totalUsers); ?> total users</span>
    </div>

    <?php if (!empty($users)): ?>
    <div style="overflow-x: auto;">
        <table class="users-table">
            <thead>
                <tr>
                    <th class="checkbox-cell">
                        <input type="checkbox" class="select-all-checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                    </th>
                    <th>User</th>
                    <th>Role</th>
                    <th>Account</th>
                    <th>Status</th>
                    <th>Orders</th>
                    <th>Spent</th>
                    <th>Reviews</th>
                    <th>Last Login</th>
                    <th>Registered</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr data-user-id="<?php echo $user['id']; ?>">
                    <td class="checkbox-cell">
                        <input type="checkbox" class="user-checkbox" value="<?php echo $user['id']; ?>" onchange="updateBulkBar()">
                    </td>
                    <td>
                        <div class="user-cell">
                            <div class="user-avatar"><?php echo getInitials($user['name']); ?></div>
                            <div class="user-info">
                                <div class="user-name" onclick="viewUserDetails(<?php echo $user['id']; ?>)">
                                    <?php echo htmlspecialchars($user['name']); ?>
                                </div>
                                <div class="user-contact">
                                    <?php echo htmlspecialchars($user['email'] ?: $user['phone']); ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php echo getRoleBadge($user['role_name'], $user['role_id']); ?>
                    </td>
                    <td>
                        <span style="text-transform: capitalize; font-size: 0.75rem;">
                            <?php if ($user['account_type'] === 'premium'): ?>
                                <i class="fas fa-gem" style="color: var(--admin-warning);"></i>
                            <?php endif; ?>
                            <?php echo $user['account_type']; ?>
                        </span>
                    </td>
                    <td>
                        <?php echo getStatusBadge($user['is_active']); ?>
                    </td>
                    <td>
                        <strong><?php echo $user['total_orders']; ?></strong>
                    </td>
                    <td>
                        <?php echo formatCurrency($user['total_spent']); ?>
                    </td>
                    <td>
                        <?php echo $user['total_reviews']; ?>
                    </td>
                    <td>
                        <span style="font-size: 0.7rem;"><?php echo formatDate($user['last_login']); ?></span>
                    </td>
                    <td>
                        <span style="font-size: 0.7rem;"><?php echo formatDate($user['created_at']); ?></span>
                    </td>
                    <td>
                        <div class="action-btns">
                            <button class="btn-action btn-view" onclick="viewUserDetails(<?php echo $user['id']; ?>)">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn-action btn-reset-password" onclick="openResetPasswordModal(<?php echo $user['id']; ?>)">
                                <i class="fas fa-key"></i>
                            </button>
                            <?php if ($user['id'] !== $userId): ?>
                                <?php if ($user['is_active']): ?>
                                    <button class="btn-action btn-deactivate" onclick="updateUserStatus(<?php echo $user['id']; ?>, 'deactivate')">
                                        <i class="fas fa-pause"></i>
                                    </button>
                                <?php else: ?>
                                    <button class="btn-action btn-activate" onclick="updateUserStatus(<?php echo $user['id']; ?>, 'activate')">
                                        <i class="fas fa-check"></i>
                                    </button>
                                <?php endif; ?>
                                <button class="btn-action btn-delete" onclick="deleteUser(<?php echo $user['id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            <?php else: ?>
                                <span style="font-size: 0.65rem; color: var(--admin-primary);">You</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-users"></i></div>
        <h4>No Users Found</h4>
        <p>No users match your current filters. Try adjusting your search criteria.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php
    $queryParams = $_GET;
    unset($queryParams['page']);
    $baseQuery = http_build_query($queryParams);
    $baseUrl = 'users.php' . ($baseQuery ? '?' . $baseQuery . '&' : '?');
    ?>

    <?php if ($page > 1): ?>
        <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>"><i class="fas fa-chevron-left"></i></a>
    <?php else: ?>
        <span class="disabled"><i class="fas fa-chevron-left"></i></span>
    <?php endif; ?>

    <?php
    $startPage = max(1, $page - 2);
    $endPage = min($totalPages, $page + 2);

    if ($startPage > 1) {
        echo '<a href="' . $baseUrl . 'page=1">1</a>';
        if ($startPage > 2) echo '<span class="disabled">...</span>';
    }

    for ($i = $startPage; $i <= $endPage; $i++):
    ?>
        <a href="<?php echo $baseUrl; ?>page=<?php echo $i; ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
    <?php endfor;

    if ($endPage < $totalPages) {
        if ($endPage < $totalPages - 1) echo '<span class="disabled">...</span>';
        echo '<a href="' . $baseUrl . 'page=' . $totalPages . '">' . $totalPages . '</a>';
    }
    ?>

    <?php if ($page < $totalPages): ?>
        <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>"><i class="fas fa-chevron-right"></i></a>
    <?php else: ?>
        <span class="disabled"><i class="fas fa-chevron-right"></i></span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ==================== MODALS ==================== -->

<!-- Add User Modal -->
<div class="modal-overlay" id="addUserModal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3><i class="fas fa-user-plus"></i> Add New User</h3>
            <button class="modal-close" onclick="closeAddUserModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form id="addUserForm" onsubmit="submitAddUser(event)">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <div class="form-group">
                    <label>Full Name <span class="required">*</span></label>
                    <input type="text" name="name" class="form-control" required placeholder="Enter full name">
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" class="form-control" placeholder="user@example.com">
                </div>
                <div class="form-group">
                    <label>Phone Number <span class="required">*</span></label>
                    <input type="tel" name="phone" class="form-control" required placeholder="+233 XX XXX XXXX">
                </div>
                <div class="form-group">
                    <label>Password <span class="required">*</span></label>
                    <input type="password" name="password" class="form-control" required placeholder="Min. 8 characters" id="newPassword" onkeyup="checkPasswordStrength(this.value)">
                    <div class="password-strength" id="passwordStrength"></div>
                </div>
                <div class="form-group">
                    <label>User Role</label>
                    <select name="role_id" class="form-control">
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo $role['id']; ?>" <?php echo $role['id'] == 3 ? 'selected' : ''; ?>>
                                <?php echo ucfirst($role['role_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Account Type</label>
                    <select name="account_type" class="form-control">
                        <option value="regular">Regular</option>
                        <option value="premium">Premium</option>
                    </select>
                </div>
                <div class="form-check">
                    <input type="checkbox" name="is_active" id="isActive" checked>
                    <label for="isActive">Activate account immediately</label>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeAddUserModal()">Cancel</button>
                    <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- User Detail Modal -->
<div class="modal-overlay" id="userDetailModal">
    <div class="modal-dialog modal-dialog-lg">
        <div class="modal-header">
            <h3><i class="fas fa-user"></i> User Details</h3>
            <button class="modal-close" onclick="closeUserModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="userDetailContent">
            <div style="text-align: center; padding: 3rem;">
                <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: var(--admin-primary);"></i>
                <p style="margin-top: 1rem; color: var(--text-muted);">Loading user details...</p>
            </div>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal-overlay" id="resetPasswordModal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3><i class="fas fa-key"></i> Reset User Password</h3>
            <button class="modal-close" onclick="closeResetPasswordModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form id="resetPasswordForm" onsubmit="submitResetPassword(event)">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="user_id" id="resetUserId">
                <div class="form-group">
                    <label>New Password <span class="required">*</span></label>
                    <input type="password" name="new_password" class="form-control" required placeholder="Min. 8 characters" id="resetPasswordField" onkeyup="checkResetPasswordStrength(this.value)">
                    <div class="password-strength" id="resetPasswordStrength"></div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeResetPasswordModal()">Cancel</button>
                    <button type="submit" class="btn-submit"><i class="fas fa-key"></i> Reset Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==================== SCRIPTS ==================== -->
<script>
    let currentUserId = null;

    // Password strength checker
    function checkPasswordStrength(password) {
        const strengthDiv = document.getElementById('passwordStrength');
        if (!password) { strengthDiv.innerHTML = ''; return; }
        let strength = 0;
        if (password.length >= 8) strength++;
        if (password.match(/[a-z]+/)) strength++;
        if (password.match(/[A-Z]+/)) strength++;
        if (password.match(/[0-9]+/)) strength++;
        if (password.match(/[$@#&!]+/)) strength++;
        let strengthText = '', strengthClass = '';
        if (strength <= 2) { strengthText = 'Weak'; strengthClass = 'strength-weak'; }
        else if (strength <= 4) { strengthText = 'Medium'; strengthClass = 'strength-medium'; }
        else { strengthText = 'Strong'; strengthClass = 'strength-strong'; }
        strengthDiv.innerHTML = `<span class="${strengthClass}">Password Strength: ${strengthText}</span>`;
    }

    function checkResetPasswordStrength(password) {
        const strengthDiv = document.getElementById('resetPasswordStrength');
        if (!password) { strengthDiv.innerHTML = ''; return; }
        let strength = 0;
        if (password.length >= 8) strength++;
        if (password.match(/[a-z]+/)) strength++;
        if (password.match(/[A-Z]+/)) strength++;
        if (password.match(/[0-9]+/)) strength++;
        if (password.match(/[$@#&!]+/)) strength++;
        let strengthText = '', strengthClass = '';
        if (strength <= 2) { strengthText = 'Weak'; strengthClass = 'strength-weak'; }
        else if (strength <= 4) { strengthText = 'Medium'; strengthClass = 'strength-medium'; }
        else { strengthText = 'Strong'; strengthClass = 'strength-strong'; }
        strengthDiv.innerHTML = `<span class="${strengthClass}">Password Strength: ${strengthText}</span>`;
    }

    // Add User Modal
    function openAddUserModal() {
        document.getElementById('addUserModal').classList.add('active');
        document.getElementById('addUserForm').reset();
        document.getElementById('passwordStrength').innerHTML = '';
    }

    function closeAddUserModal() {
        document.getElementById('addUserModal').classList.remove('active');
    }

    function submitAddUser(event) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        formData.append('action', 'add_user');

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            showToast(data.message, data.success ? 'success' : 'error');
            if (data.success) {
                closeAddUserModal();
                setTimeout(() => window.location.reload(), 1500);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('An error occurred while adding user', 'error');
        });
    }

    // View user details
    function viewUserDetails(userId) {
        const modal = document.getElementById('userDetailModal');
        const content = document.getElementById('userDetailContent');
        modal.classList.add('active');
        content.innerHTML = '<div style="text-align: center; padding: 3rem;"><i class="fas fa-spinner fa-spin" style="font-size: 2rem;"></i><p style="margin-top: 1rem;">Loading user details...</p></div>';

        const formData = new FormData();
        formData.append('action', 'get_user_details');
        formData.append('user_id', userId);
        formData.append('csrf_token', '<?php echo $csrfToken; ?>');

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderUserDetails(data.data);
            } else {
                content.innerHTML = '<div class="empty-state"><div class="empty-state-icon"><i class="fas fa-exclamation-circle"></i></div><h4>Error</h4><p>' + data.message + '</p></div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            content.innerHTML = '<div class="empty-state"><div class="empty-state-icon"><i class="fas fa-exclamation-circle"></i></div><h4>Error</h4><p>Failed to load user details</p></div>';
        });
    }

    function closeUserModal() {
        document.getElementById('userDetailModal').classList.remove('active');
    }

    // Reset Password Modal
    function openResetPasswordModal(userId) {
        currentUserId = userId;
        document.getElementById('resetUserId').value = userId;
        document.getElementById('resetPasswordModal').classList.add('active');
        document.getElementById('resetPasswordForm').reset();
        document.getElementById('resetPasswordStrength').innerHTML = '';
    }

    function closeResetPasswordModal() {
        document.getElementById('resetPasswordModal').classList.remove('active');
    }

    function submitResetPassword(event) {
        event.preventDefault();
        const formData = new FormData(document.getElementById('resetPasswordForm'));
        formData.append('action', 'reset_user_password');

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            showToast(data.message, data.success ? 'success' : 'error');
            if (data.success) {
                closeResetPasswordModal();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('An error occurred while resetting password', 'error');
        });
    }

    // Close modals on overlay click
    document.getElementById('addUserModal').addEventListener('click', function(e) {
        if (e.target === this) closeAddUserModal();
    });
    document.getElementById('userDetailModal').addEventListener('click', function(e) {
        if (e.target === this) closeUserModal();
    });
    document.getElementById('resetPasswordModal').addEventListener('click', function(e) {
        if (e.target === this) closeResetPasswordModal();
    });

    // Close on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAddUserModal();
            closeUserModal();
            closeResetPasswordModal();
        }
    });

    // Render user details in modal
    function renderUserDetails(user) {
        const content = document.getElementById('userDetailContent');

        let ordersHtml = '';
        if (user.recent_orders && user.recent_orders.length > 0) {
            user.recent_orders.forEach(order => {
                ordersHtml += `
                    <div class="mini-order-item" style="display: flex; justify-content: space-between; align-items: center; padding: 0.6rem; background: var(--admin-bg); border-radius: var(--radius-sm); margin-bottom: 0.4rem; flex-wrap: wrap; gap: 0.5rem;">
                        <span style="color: var(--admin-primary); font-weight: 600;">#${order.order_number}</span>
                        <span>GHS ${parseFloat(order.total_amount).toFixed(2)}</span>
                        <span>${order.item_count} items</span>
                        <span>${order.order_status}</span>
                        <span style="font-size: 0.7rem; color: var(--text-muted);">${new Date(order.created_at).toLocaleDateString()}</span>
                    </div>
                `;
            });
        } else {
            ordersHtml = '<p style="font-size: 0.8rem; color: var(--text-muted);">No orders yet</p>';
        }

        content.innerHTML = `
            <div class="user-detail-header" style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--admin-border); flex-wrap: wrap;">
                <div class="user-detail-avatar" style="width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg, var(--admin-primary), #8B5CF6); display: flex; align-items: center; justify-content: center; font-weight: 700; color: white; font-size: 1.5rem; flex-shrink: 0;">${user.name.charAt(0).toUpperCase()}</div>
                <div class="user-detail-info" style="flex: 1;">
                    <div style="font-size: 1.2rem; font-weight: 700;">${escapeHtml(user.name)}</div>
                    <div style="color: var(--text-muted); font-size: 0.85rem;">${user.email || 'No email'} | ${user.phone || 'No phone'}</div>
                    <div style="margin-top: 0.5rem; display: flex; flex-wrap: wrap; gap: 0.5rem;">
                        ${getRoleBadgeHtml(user.role_name, user.role_id)}
                        ${user.is_active ? '<span class="status-badge-active"><i class="fas fa-check-circle"></i> Active</span>' : '<span class="status-badge-inactive"><i class="fas fa-times-circle"></i> Inactive</span>'}
                        ${user.account_type === 'premium' ? '<span class="role-badge" style="background: rgba(245,158,11,0.15); color: #F59E0B;"><i class="fas fa-gem"></i> Premium</span>' : ''}
                    </div>
                </div>
            </div>

            <div class="detail-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                <div style="background: var(--admin-bg); border: 1px solid var(--admin-border); border-radius: var(--radius-sm); padding: 1rem;">
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase;">Total Orders</div>
                    <div style="font-weight: 600;">${user.total_orders}</div>
                </div>
                <div style="background: var(--admin-bg); border: 1px solid var(--admin-border); border-radius: var(--radius-sm); padding: 1rem;">
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase;">Total Spent</div>
                    <div style="font-weight: 600;">GHS ${parseFloat(user.total_spent).toFixed(2)}</div>
                </div>
                <div style="background: var(--admin-bg); border: 1px solid var(--admin-border); border-radius: var(--radius-sm); padding: 1rem;">
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase;">Wishlist Items</div>
                    <div style="font-weight: 600;">${user.wishlist_count}</div>
                </div>
                <div style="background: var(--admin-bg); border: 1px solid var(--admin-border); border-radius: var(--radius-sm); padding: 1rem;">
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase;">Saved Addresses</div>
                    <div style="font-weight: 600;">${user.addresses_count}</div>
                </div>
                <div style="background: var(--admin-bg); border: 1px solid var(--admin-border); border-radius: var(--radius-sm); padding: 1rem;">
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase;">Reviews</div>
                    <div style="font-weight: 600;">${user.reviews_count}</div>
                </div>
                <div style="background: var(--admin-bg); border: 1px solid var(--admin-border); border-radius: var(--radius-sm); padding: 1rem;">
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase;">Last Login</div>
                    <div style="font-weight: 600; font-size: 0.8rem;">${user.last_login ? new Date(user.last_login).toLocaleString() : 'Never'}</div>
                </div>
                <div style="background: var(--admin-bg); border: 1px solid var(--admin-border); border-radius: var(--radius-sm); padding: 1rem;">
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase;">Registered</div>
                    <div style="font-weight: 600; font-size: 0.8rem;">${new Date(user.created_at).toLocaleString()}</div>
                </div>
                <div style="background: var(--admin-bg); border: 1px solid var(--admin-border); border-radius: var(--radius-sm); padding: 1rem;">
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase;">Role</div>
                    <div style="font-weight: 600;">
                        <select onchange="updateUserRole(${user.id}, this.value)" style="background: var(--admin-bg); border: 1px solid var(--admin-border); color: var(--text-primary); padding: 0.4rem; border-radius: var(--radius-sm); font-family: var(--font-body); cursor: pointer; width: 100%;">
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>" ${user.role_id == <?php echo $role['id']; ?> ? 'selected' : ''}>
                                    <?php echo ucfirst($role['role_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div style="margin-bottom: 1.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <button class="btn-action btn-reset-password" onclick="openResetPasswordModal(${user.id})">
                    <i class="fas fa-key"></i> Reset Password
                </button>
            </div>

            <div class="recent-orders">
                <h4 style="font-size: 0.9rem; margin-bottom: 0.75rem;">Recent Orders</h4>
                ${ordersHtml}
            </div>
        `;
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function getRoleBadgeHtml(roleName, roleId) {
        const badges = {
            1: { class: 'role-admin', icon: 'fa-crown', label: 'Admin' },
            2: { class: 'role-seller', icon: 'fa-store', label: 'Seller' },
            3: { class: 'role-buyer', icon: 'fa-user', label: 'Buyer' },
            4: { class: 'role-deliverer', icon: 'fa-truck', label: 'Deliverer' }
        };
        const badge = badges[roleId] || { class: 'role-default', icon: 'fa-question', label: roleName };
        return `<span class="role-badge ${badge.class}"><i class="fas ${badge.icon}"></i> ${badge.label}</span>`;
    }

    // Update user status
    function updateUserStatus(userId, status) {
        const actionText = status === 'activate' ? 'activate' : 'deactivate';
        if (confirm(`Are you sure you want to ${actionText} this user?`)) {
            const formData = new FormData();
            formData.append('action', 'update_user_status');
            formData.append('user_id', userId);
            formData.append('status', status);
            formData.append('csrf_token', '<?php echo $csrfToken; ?>');

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) setTimeout(() => window.location.reload(), 1000);
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred', 'error');
            });
        }
    }

    // Update user role
    function updateUserRole(userId, roleId) {
        if (confirm('Are you sure you want to change this user\'s role?')) {
            const formData = new FormData();
            formData.append('action', 'update_user_role');
            formData.append('user_id', userId);
            formData.append('role_id', roleId);
            formData.append('csrf_token', '<?php echo $csrfToken; ?>');

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) setTimeout(() => window.location.reload(), 1000);
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred', 'error');
            });
        }
    }

    // Delete user
    function deleteUser(userId) {
        if (confirm('Are you sure you want to delete this user? This will soft-delete the account.')) {
            const formData = new FormData();
            formData.append('action', 'delete_user');
            formData.append('user_id', userId);
            formData.append('csrf_token', '<?php echo $csrfToken; ?>');

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) setTimeout(() => window.location.reload(), 1000);
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred', 'error');
            });
        }
    }

    // Bulk selection
    function toggleSelectAll(checkbox) {
        document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = checkbox.checked);
        updateBulkBar();
    }

    function updateBulkBar() {
        const checkboxes = document.querySelectorAll('.user-checkbox:checked');
        const count = checkboxes.length;
        const bar = document.getElementById('bulkActionsBar');
        const bulkCount = document.getElementById('bulkCount');

        if (count > 0) {
            bar.classList.add('active');
            bulkCount.textContent = `${count} user${count > 1 ? 's' : ''} selected`;
        } else {
            bar.classList.remove('active');
        }
    }

    function clearSelection() {
        document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = false);
        document.getElementById('selectAll').checked = false;
        updateBulkBar();
    }

    function getSelectedUserIds() {
        return Array.from(document.querySelectorAll('.user-checkbox:checked')).map(cb => cb.value);
    }

    function performBulkAction(action) {
        const userIds = getSelectedUserIds();
        if (userIds.length === 0) {
            showToast('No users selected', 'warning');
            return;
        }
        const actionText = action === 'activate' ? 'activate' : (action === 'deactivate' ? 'deactivate' : 'delete');
        if (confirm(`Are you sure you want to ${actionText} ${userIds.length} user(s)?`)) {
            const formData = new FormData();
            formData.append('action', 'bulk_action');
            formData.append('user_ids', JSON.stringify(userIds));
            formData.append('bulk_action', action);
            formData.append('csrf_token', '<?php echo $csrfToken; ?>');

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) setTimeout(() => window.location.reload(), 1000);
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred', 'error');
            });
        }
    }

    // Toast notification
    function showToast(message, type) {
        const toast = document.createElement('div');
        toast.className = `toast-notification toast-${type}`;
        toast.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : (type === 'error' ? 'exclamation-circle' : 'info-circle')}"></i>
            <span>${message}</span>
        `;
        document.body.appendChild(toast);
        setTimeout(() => {
            if (toast.parentNode) toast.remove();
        }, 4000);
    }
</script>

<?php
require_once __DIR__ . '/footer.php';
?>
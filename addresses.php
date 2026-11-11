<?php
/**
 * Bless Mall - Address Book Page
 * Manage user shipping addresses with full CRUD operations
 */

require_once 'includes/config.php';
require_once 'includes/functions.php';

// Redirect if not logged in
if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = 'addresses.php';
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Handle address mutations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Security validation failed. Please try again.';
        $message_type = 'error';
    } else {
        $action = $_POST['action'] ?? 'save';
        $address_id = (int)($_POST['address_id'] ?? 0);

        if ($action === 'delete') {
            $stmt = $conn->prepare("DELETE FROM addresses WHERE id = ? AND user_id = ? AND is_default = 0");
            $stmt->bind_param('ii', $address_id, $user_id);
            if ($stmt->execute() && $stmt->affected_rows === 1) {
                $message = 'Address deleted successfully!';
                $message_type = 'success';
            } else {
                $message = 'Address could not be deleted. Default addresses must be changed first.';
                $message_type = 'error';
            }
        } elseif ($action === 'set_default') {
            $conn->begin_transaction();
            try {
                $reset_stmt = $conn->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ?');
                $reset_stmt->bind_param('i', $user_id);
                $reset_stmt->execute();

                $set_stmt = $conn->prepare('UPDATE addresses SET is_default = 1 WHERE id = ? AND user_id = ?');
                $set_stmt->bind_param('ii', $address_id, $user_id);
                $set_stmt->execute();
                if ($set_stmt->affected_rows !== 1) {
                    throw new RuntimeException('Address does not belong to this user.');
                }

                $conn->commit();
                $message = 'Default address updated successfully!';
                $message_type = 'success';
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Default address update failed: ' . $exception->getMessage());
                $message = 'Address could not be set as default.';
                $message_type = 'error';
            }
        } else {
            $address_line1 = trim((string)($_POST['address_line1'] ?? ''));
            $address_line2 = trim((string)($_POST['address_line2'] ?? ''));
            $city = trim((string)($_POST['city'] ?? ''));
            $region = trim((string)($_POST['region'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $is_default = isset($_POST['is_default']) ? 1 : 0;

            $errors = [];
            if ($address_line1 === '') $errors[] = 'Address line is required';
            if ($city === '') $errors[] = 'City is required';
            if ($region === '') $errors[] = 'Region is required';

            if (empty($errors)) {
                $conn->begin_transaction();
                try {
                    if ($address_id > 0) {
                        $owner_stmt = $conn->prepare('SELECT id FROM addresses WHERE id = ? AND user_id = ?');
                        $owner_stmt->bind_param('ii', $address_id, $user_id);
                        $owner_stmt->execute();
                        if (!$owner_stmt->get_result()->fetch_assoc()) {
                            throw new RuntimeException('Address does not belong to this user.');
                        }

                        $stmt = $conn->prepare('UPDATE addresses SET address_line1 = ?, address_line2 = ?, city = ?, region = ?, phone = ?, is_default = ? WHERE id = ? AND user_id = ?');
                        $stmt->bind_param('sssssiii', $address_line1, $address_line2, $city, $region, $phone, $is_default, $address_id, $user_id);
                        $success_message = 'Address updated successfully!';
                    } else {
                        $stmt = $conn->prepare('INSERT INTO addresses (user_id, address_line1, address_line2, city, region, phone, is_default, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
                        $stmt->bind_param('isssssi', $user_id, $address_line1, $address_line2, $city, $region, $phone, $is_default);
                        $success_message = 'Address added successfully!';
                    }

                    if (!$stmt->execute()) {
                        throw new RuntimeException('Address save query failed.');
                    }

                    $saved_address_id = $address_id > 0 ? $address_id : $stmt->insert_id;
                    if ($is_default) {
                        $update_stmt = $conn->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ? AND id != ?');
                        $update_stmt->bind_param('ii', $user_id, $saved_address_id);
                        $update_stmt->execute();
                    }

                    $conn->commit();
                    $message = $success_message;
                    $message_type = 'success';
                } catch (Throwable $exception) {
                    $conn->rollback();
                    error_log('Address save failed: ' . $exception->getMessage());
                    $message = 'Address could not be saved.';
                    $message_type = 'error';
                }
            } else {
                $message = implode(', ', $errors);
                $message_type = 'error';
            }
        }
    }
}

$csrf_token = generateCsrfToken();

// Handle Edit - Get address data
$edit_address = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $edit_query = "SELECT * FROM addresses WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($edit_query);
    $stmt->bind_param("ii", $edit_id, $user_id);
    $stmt->execute();
    $edit_address = $stmt->get_result()->fetch_assoc();
}

// Fetch all user addresses
$query = "SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, created_at DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$addresses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get default address count
$default_exists = false;
foreach ($addresses as $addr) {
    if ($addr['is_default']) {
        $default_exists = true;
        break;
    }
}

// Ghana regions
$ghana_regions = [
    'Greater Accra', 'Ashanti', 'Western', 'Eastern', 'Central', 
    'Volta', 'Northern', 'Upper East', 'Upper West', 'Bono', 
    'Ahafo', 'Bono East', 'Oti', 'North East', 'Savannah', 'Western North'
];

$page_title = "My Addresses - Bless Mall Ghana";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo $page_title; ?></title>
    <meta name="description" content="Manage your shipping addresses on Bless Mall. Add, edit, or delete delivery addresses for faster checkout.">
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&family=Playfair+Display:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #F8F9FA;
            color: #1A1A1A;
        }
        
        :root {
            --primary: #0B6E3D;
            --primary-dark: #085530;
            --primary-light: #2E8B57;
            --accent: #D4AF37;
            --accent-dark: #B8942E;
            --gray-50: #F9FAFB;
            --gray-100: #F3F4F6;
            --gray-200: #E5E7EB;
            --gray-300: #D1D5DB;
            --gray-400: #9CA3AF;
            --gray-500: #6B7280;
            --gray-600: #4B5563;
            --gray-700: #374151;
            --gray-800: #1F2937;
            --gray-900: #111827;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
        }
        
        /* Breadcrumb */
        .breadcrumb {
            padding: 1.5rem 0;
            font-size: 0.875rem;
        }
        
        .breadcrumb a {
            color: var(--gray-600);
            text-decoration: none;
            transition: color 0.2s;
        }
        
        .breadcrumb a:hover {
            color: var(--primary);
        }
        
        .breadcrumb .separator {
            margin: 0 0.5rem;
            color: var(--gray-300);
        }
        
        .breadcrumb .current {
            color: var(--gray-800);
            font-weight: 500;
        }
        
        /* Page Layout */
        .page-layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 2rem;
            margin-bottom: 3rem;
        }
        
        /* Sidebar */
        .sidebar {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            height: fit-content;
            position: sticky;
            top: 2rem;
        }
        
        .sidebar-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--gray-200);
        }
        
        .sidebar-menu {
            list-style: none;
        }
        
        .sidebar-menu li {
            margin-bottom: 0.5rem;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            color: var(--gray-700);
            text-decoration: none;
            border-radius: 0.5rem;
            transition: all 0.2s;
        }
        
        .sidebar-menu a i {
            width: 20px;
            color: var(--gray-500);
        }
        
        .sidebar-menu a:hover {
            background: var(--gray-100);
            color: var(--primary);
        }
        
        .sidebar-menu a:hover i {
            color: var(--primary);
        }
        
        .sidebar-menu .active {
            background: var(--primary);
            color: white;
        }
        
        .sidebar-menu .active i {
            color: white;
        }
        
        /* Main Content */
        .main-content {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .page-header h1 {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--gray-800);
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.625rem 1.25rem;
            border-radius: 0.5rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-secondary {
            background: white;
            border: 2px solid var(--gray-200);
            color: var(--gray-700);
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-secondary:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .btn-danger {
            background: white;
            border: 2px solid #FEE2E2;
            color: #DC2626;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-danger:hover {
            background: #FEE2E2;
            border-color: #DC2626;
        }
        
        /* Alert Messages */
        .alert {
            padding: 1rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .alert-success {
            background: #DCFCE7;
            color: #166534;
            border-left: 4px solid #22C55E;
        }
        
        .alert-error {
            background: #FEE2E2;
            color: #991B1B;
            border-left: 4px solid #EF4444;
        }
        
        /* Address Grid */
        .addresses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
        }
        
        /* Address Card */
        .address-card {
            background: var(--gray-50);
            border-radius: 1rem;
            padding: 1.25rem;
            transition: all 0.3s;
            border: 2px solid transparent;
            position: relative;
        }
        
        .address-card.default {
            border-color: var(--accent);
            background: linear-gradient(135deg, #FFF9E6, white);
        }
        
        .address-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }
        
        .default-badge {
            position: absolute;
            top: -10px;
            right: 1rem;
            background: var(--accent);
            color: var(--gray-800);
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .address-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .address-type {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            color: var(--gray-800);
        }
        
        .address-type i {
            color: var(--primary);
        }
        
        .address-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .action-icon {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--gray-600);
            text-decoration: none;
        }
        
        .action-icon:hover {
            background: white;
        }
        
        .action-icon.edit:hover {
            color: var(--primary);
        }
        
        .action-icon.delete:hover {
            background: #FEE2E2;
            color: #DC2626;
        }
        
        .address-details {
            margin-bottom: 1rem;
        }
        
        .address-line {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            color: var(--gray-600);
            font-size: 0.875rem;
        }
        
        .address-line i {
            width: 20px;
            color: var(--gray-400);
        }
        
        .set-default-btn {
            width: 100%;
            padding: 0.5rem;
            background: transparent;
            border: 1px dashed var(--gray-300);
            border-radius: 0.5rem;
            color: var(--gray-600);
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .set-default-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(11, 110, 61, 0.05);
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 1rem;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem 1.5rem 0.5rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .modal-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
        }
        
        .modal-close {
            cursor: pointer;
            font-size: 1.5rem;
            color: var(--gray-500);
            transition: color 0.2s;
        }
        
        .modal-close:hover {
            color: var(--gray-800);
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-footer {
            padding: 1rem 1.5rem 1.5rem 1.5rem;
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            border-top: 1px solid var(--gray-200);
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 1.25rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            font-size: 0.875rem;
            color: var(--gray-700);
        }
        
        .form-group label .required {
            color: #EF4444;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--gray-200);
            border-radius: 0.5rem;
            font-size: 0.875rem;
            transition: all 0.2s;
            font-family: inherit;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(11, 110, 61, 0.1);
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .checkbox-group input {
            width: auto;
        }
        
        .checkbox-group label {
            margin-bottom: 0;
            cursor: pointer;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        /* Toast Notification */
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: var(--gray-800);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            z-index: 1000;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            box-shadow: var(--shadow-lg);
        }
        
        .toast.show {
            transform: translateX(0);
        }
        
        .toast.success {
            background: var(--primary);
        }
        
        .toast.error {
            background: #DC2626;
        }
        
        /* Delete Modal specific */
        .delete-icon {
            font-size: 3rem;
            color: #DC2626;
            text-align: center;
            margin-bottom: 1rem;
        }
        
        .delete-text {
            text-align: center;
            margin-bottom: 0.5rem;
        }
        
        .delete-warning {
            text-align: center;
            font-size: 0.875rem;
            color: var(--gray-500);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            color: var(--gray-300);
            margin-bottom: 1rem;
        }
        
        .empty-state h3 {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            color: var(--gray-500);
            margin-bottom: 1.5rem;
        }
        
        @media (max-width: 968px) {
            .page-layout {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                position: static;
            }
            
            .container {
                padding: 0 1rem;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .addresses-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <main>
        <div class="container">
            <!-- Breadcrumb -->
            <div class="breadcrumb">
                <a href="index.php">Home</a>
                <span class="separator">/</span>
                <a href="account.php">My Account</a>
                <span class="separator">/</span>
                <span class="current">Address Book</span>
            </div>
            
            <div class="page-layout">
                <!-- Sidebar -->
                <aside class="sidebar">
                    <h3 class="sidebar-title">My Account</h3>
                    <ul class="sidebar-menu">
                        <li><a href="account.php"><i class="fas fa-user"></i> Dashboard</a></li>
                        <li><a href="orders.php"><i class="fas fa-shopping-bag"></i> Orders</a></li>
                        <li><a href="wishlist.php"><i class="fas fa-heart"></i> Wishlist</a></li>
                        <li><a href="addresses.php" class="active"><i class="fas fa-map-marker-alt"></i> Address Book</a></li>
                        <li><a href="account-settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    </ul>
                </aside>
                
                <!-- Main Content -->
                <div class="main-content">
                    <div class="page-header">
                        <h1><i class="fas fa-map-marker-alt" style="color: var(--primary); margin-right: 0.5rem;"></i> Address Book</h1>
                        <button id="addAddressBtn" class="btn-primary">
                            <i class="fas fa-plus"></i> Add New Address
                        </button>
                    </div>
                    
                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?>">
                        <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                        <?php echo $message; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Addresses Grid -->
                    <?php if (count($addresses) > 0): ?>
                    <div class="addresses-grid">
                        <?php foreach ($addresses as $address): ?>
                        <div class="address-card <?php echo $address['is_default'] ? 'default' : ''; ?>">
                            <?php if ($address['is_default']): ?>
                            <div class="default-badge">
                                <i class="fas fa-check-circle"></i> Default
                            </div>
                            <?php endif; ?>
                            
                            <div class="address-header">
                                <div class="address-type">
                                    <i class="fas fa-home"></i>
                                    <span>Delivery Address</span>
                                </div>
                                <div class="address-actions">
                                    <a href="?edit=<?php echo $address['id']; ?>" class="action-icon edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="#" class="action-icon delete" data-id="<?php echo $address['id']; ?>" data-default="<?php echo $address['is_default']; ?>">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                </div>
                            </div>
                            
                            <div class="address-details">
                                <div class="address-line">
                                    <i class="fas fa-location-dot"></i>
                                    <span><?php echo htmlspecialchars($address['address_line1']); ?></span>
                                </div>
                                <?php if ($address['address_line2']): ?>
                                <div class="address-line">
                                    <i class="fas fa-location-dot" style="opacity: 0;"></i>
                                    <span><?php echo htmlspecialchars($address['address_line2']); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="address-line">
                                    <i class="fas fa-city"></i>
                                    <span><?php echo htmlspecialchars($address['city']) . ', ' . htmlspecialchars($address['region']); ?></span>
                                </div>
                                <?php if ($address['phone']): ?>
                                <div class="address-line">
                                    <i class="fas fa-phone"></i>
                                    <span><?php echo htmlspecialchars($address['phone']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!$address['is_default']): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="set_default">
                                <input type="hidden" name="address_id" value="<?php echo (int)$address['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="set-default-btn">
                                    <i class="fas fa-check-circle"></i> Set as Default
                                </button>
                            </form>
                            <?php else: ?>
                            <div style="margin-top: 0.75rem; font-size: 0.75rem; color: var(--gray-500); text-align: center;">
                                <i class="fas fa-star" style="color: var(--accent);"></i> This is your default shipping address
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <!-- Empty State -->
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <h3>No addresses saved yet</h3>
                        <p>Add your first shipping address for faster checkout</p>
                        <button id="addFirstAddressBtn" class="btn-primary">
                            <i class="fas fa-plus"></i> Add New Address
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Address Modal (Add/Edit) -->
    <div id="addressModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Add New Address</h3>
                <span class="modal-close">&times;</span>
            </div>
            <form id="addressForm" method="POST">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="address_id" id="address_id" value="<?php echo $edit_address['id'] ?? ''; ?>">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Address Line <span class="required">*</span></label>
                        <input type="text" name="address_line1" id="address_line1" required 
                               value="<?php echo htmlspecialchars($edit_address['address_line1'] ?? ''); ?>"
                               placeholder="Street address, building name, number">
                    </div>
                    
                    <div class="form-group">
                        <label>Address Line 2 <span style="color: var(--gray-500);">(Optional)</span></label>
                        <input type="text" name="address_line2" id="address_line2" 
                               value="<?php echo htmlspecialchars($edit_address['address_line2'] ?? ''); ?>"
                               placeholder="Apartment, suite, unit, floor">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>City <span class="required">*</span></label>
                            <input type="text" name="city" id="city" required 
                                   value="<?php echo htmlspecialchars($edit_address['city'] ?? ''); ?>"
                                   placeholder="e.g., Accra, Kumasi">
                        </div>
                        
                        <div class="form-group">
                            <label>Region <span class="required">*</span></label>
                            <select name="region" id="region" required>
                                <option value="">Select Region</option>
                                <?php foreach ($ghana_regions as $region): ?>
                                <option value="<?php echo $region; ?>" <?php echo (($edit_address['region'] ?? '') == $region) ? 'selected' : ''; ?>>
                                    <?php echo $region; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="phone" id="phone" 
                               value="<?php echo htmlspecialchars($edit_address['phone'] ?? ''); ?>"
                               placeholder="e.g., 0244123456">
                    </div>
                    
                    <div class="form-group checkbox-group">
                        <input type="checkbox" name="is_default" id="is_default" value="1" 
                               <?php echo (($edit_address['is_default'] ?? 0) || (!$default_exists && !$edit_address)) ? 'checked' : ''; ?>>
                        <label for="is_default">Set as default shipping address</label>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="cancelModalBtn">Cancel</button>
                    <button type="submit" class="btn-primary">Save Address</button>
                </div>
            </form>
        </div>
    </div>

    <form id="deleteAddressForm" method="POST" hidden>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="address_id" id="delete_address_id" value="">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
    </form>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Delete Address</h3>
                <span class="modal-close">&times;</span>
            </div>
            <div class="modal-body">
                <div class="delete-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="delete-text">
                    <p>Are you sure you want to delete this address?</p>
                </div>
                <div class="delete-warning" id="deleteWarning" style="display: none;">
                    <i class="fas fa-info-circle"></i> You cannot delete your default address. Please set another address as default first.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelDeleteBtn">Cancel</button>
                <button type="button" class="btn-danger" id="confirmDeleteBtn">Delete</button>
            </div>
        </div>
    </div>
    
    <!-- Toast Notification -->
    <div id="toast" class="toast">
        <i class="fas fa-check-circle"></i>
        <span id="toastMessage"></span>
    </div>
    
    <?php include 'footer.php'; ?>
    
    <script>
        // Get modal elements
        const addressModal = document.getElementById('addressModal');
        const deleteModal = document.getElementById('deleteModal');
        let deleteId = null;
        let isDefault = false;
        
        // Show toast function
        function showToast(message, isSuccess = true) {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            toastMessage.textContent = message;
            toast.classList.add('show');
            if (isSuccess) {
                toast.classList.add('success');
                toast.classList.remove('error');
            } else {
                toast.classList.add('error');
                toast.classList.remove('success');
            }
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }
        
        // Add address button
        document.getElementById('addAddressBtn')?.addEventListener('click', () => {
            document.getElementById('modalTitle').textContent = 'Add New Address';
            document.getElementById('address_id').value = '';
            document.getElementById('addressForm').reset();
            // If no default exists, auto-check the default checkbox
            const defaultExists = <?php echo $default_exists ? 'true' : 'false'; ?>;
            if (!defaultExists) {
                document.getElementById('is_default').checked = true;
            }
            addressModal.classList.add('active');
        });
        
        // Add first address button (for empty state)
        document.getElementById('addFirstAddressBtn')?.addEventListener('click', () => {
            document.getElementById('modalTitle').textContent = 'Add New Address';
            document.getElementById('address_id').value = '';
            document.getElementById('addressForm').reset();
            addressModal.classList.add('active');
        });
        
        // Edit address - handles if edit parameter exists in URL
        <?php if ($edit_address): ?>
        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('modalTitle').textContent = 'Edit Address';
            addressModal.classList.add('active');
        });
        <?php endif; ?>
        
        // Delete button clicks
        document.querySelectorAll('.action-icon.delete').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                deleteId = btn.dataset.id;
                isDefault = btn.dataset.default === '1';
                
                if (isDefault) {
                    document.getElementById('deleteWarning').style.display = 'block';
                    document.getElementById('confirmDeleteBtn').disabled = true;
                } else {
                    document.getElementById('deleteWarning').style.display = 'none';
                    document.getElementById('confirmDeleteBtn').disabled = false;
                }
                deleteModal.classList.add('active');
            });
        });
        
        // Confirm delete
        document.getElementById('confirmDeleteBtn')?.addEventListener('click', () => {
            if (deleteId && !isDefault) {
                document.getElementById('delete_address_id').value = deleteId;
                document.getElementById('deleteAddressForm').requestSubmit();
            }
        });
        
        // Cancel delete
        document.getElementById('cancelDeleteBtn')?.addEventListener('click', () => {
            deleteModal.classList.remove('active');
            deleteId = null;
        });
        
        // Modal close handlers
        document.querySelectorAll('.modal-close').forEach(closeBtn => {
            closeBtn.addEventListener('click', function() {
                this.closest('.modal').classList.remove('active');
            });
        });
        
        document.getElementById('cancelModalBtn')?.addEventListener('click', () => {
            addressModal.classList.remove('active');
        });
        
        // Close modal on outside click
        window.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('active');
            }
        });
        
        // Handle URL parameters for messages
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('success')) {
            showToast('Address updated successfully!', true);
            // Remove param from URL
            window.history.replaceState({}, document.title, window.location.pathname);
        }
        
        // Form validation
        document.getElementById('addressForm')?.addEventListener('submit', function(e) {
            const addressLine1 = document.getElementById('address_line1').value.trim();
            const city = document.getElementById('city').value.trim();
            const region = document.getElementById('region').value;
            
            if (!addressLine1) {
                e.preventDefault();
                showToast('Please enter your address', false);
                return false;
            }
            if (!city) {
                e.preventDefault();
                showToast('Please enter your city', false);
                return false;
            }
            if (!region) {
                e.preventDefault();
                showToast('Please select your region', false);
                return false;
            }
        });
        
        // Phone number formatting
        const phoneInput = document.getElementById('phone');
        if (phoneInput) {
            phoneInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                if (value.startsWith('0') && value.length > 10) {
                    value = value.slice(0, 10);
                }
                e.target.value = value;
            });
        }
    </script>
</body>
</html>
<?php
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

Auth::requireLogin();
if (!Auth::isSeller()) {
    header('Location: ' . (Auth::isAdmin() ? BASE_URL . 'admin/index.php' : BASE_URL . 'account.php'));
    exit;
}

$conn = getConnection();
$userId = (int)$_SESSION['user_id'];
$stmt = $conn->prepare('SELECT id, business_name, status, is_approved FROM vendors WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stats = ['products' => 0, 'orders' => 0, 'pending' => 0, 'low_stock' => 0];
$vendorReady = $vendor && $vendor['status'] === 'active' && (int)$vendor['is_approved'] === 1;
if ($vendorReady) {
    $vendorId = (int)$vendor['id'];
    $stmt = $conn->prepare('SELECT COUNT(*) FROM products WHERE vendor_id = ?');
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $stats['products'] = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*), SUM(order_status = 'pending') FROM orders WHERE vendor_id = ?");
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    [$stats['orders'], $stats['pending']] = array_map('intval', $stmt->get_result()->fetch_row());
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(DISTINCT p.id) FROM products p JOIN product_pricing_tiers t ON t.product_id = p.id JOIN product_inventory i ON i.pricing_tier_id = t.id WHERE p.vendor_id = ? AND i.stock_quantity <= i.low_stock_threshold");
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $stats['low_stock'] = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
}

$storeName = $vendor['business_name'] ?? 'Seller account';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Seller Portal - <?php echo e(SITE_NAME); ?></title>
    <style>
        :root { color-scheme: light; --green: #0b6e3d; --ink: #17221b; --muted: #58665d; --line: #dce6df; --surface: #fff; --wash: #f2f6f3; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--wash); color: var(--ink); font: 16px Arial, sans-serif; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 18px max(20px, calc((100% - 1100px) / 2)); background: var(--surface); border-bottom: 1px solid var(--line); }
        header a, .actions a { color: var(--green); font-weight: 700; text-decoration: none; }
        main { max-width: 1100px; margin: 36px auto; padding: 0 20px; }
        h1 { margin: 0 0 8px; font-size: 1.8rem; }
        .muted { margin: 0; color: var(--muted); }
        .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin: 28px 0; }
        .stat, .notice { padding: 20px; background: var(--surface); border: 1px solid var(--line); border-radius: 6px; }
        .stat strong { display: block; margin-top: 8px; font-size: 1.8rem; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; padding: 18px 0; border-top: 1px solid var(--line); }
        .actions a { padding: 11px 14px; background: var(--surface); border: 1px solid var(--line); border-radius: 4px; }
        @media (max-width: 700px) { .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } header { align-items: flex-start; } }
    </style>
</head>
<body>
<header>
    <strong><?php echo e(SITE_NAME); ?> / Seller</strong>
    <a href="<?php echo e(BASE_URL . 'logout.php'); ?>">Log out</a>
</header>
<main>
    <h1><?php echo e($storeName); ?></h1>
    <p class="muted">Seller portal</p>
    <?php if (!$vendorReady): ?>
        <section class="notice" role="status">
            Your seller profile is awaiting approval or is inactive. Contact the administrator to continue.
        </section>
    <?php else: ?>
        <section class="stats" aria-label="Store overview">
            <div class="stat">Products<strong><?php echo $stats['products']; ?></strong></div>
            <div class="stat">Orders<strong><?php echo $stats['orders']; ?></strong></div>
            <div class="stat">Pending orders<strong><?php echo $stats['pending']; ?></strong></div>
            <div class="stat">Low-stock products<strong><?php echo $stats['low_stock']; ?></strong></div>
        </section>
        <nav class="actions" aria-label="Seller actions">
            <a href="<?php echo e(BASE_URL . 'admin/products.php'); ?>">Manage products</a>
            <a href="<?php echo e(BASE_URL . 'admin/orders.php'); ?>">Manage orders</a>
            <a href="<?php echo e(BASE_URL . 'admin/inventory.php'); ?>">View inventory</a>
        </nav>
    <?php endif; ?>
</main>
</body>
</html>
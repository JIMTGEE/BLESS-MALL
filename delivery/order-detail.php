<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireDeliverer();
$assignmentId = (int)($_GET['id'] ?? $_POST['assignment_id'] ?? 0);
$delivererId = (int)getCurrentDelivererId();
$stmt = $conn->prepare("SELECT a.id AS assignment_id, a.status AS assignment_status, a.notes, o.id AS order_id, o.order_number, o.order_status, o.total_amount, o.delivery_type, u.name AS customer_name, u.phone AS customer_phone, oa.address_line1, oa.address_line2, oa.city, oa.region, oa.phone AS delivery_phone FROM order_courier_assignments a JOIN orders o ON o.id = a.order_id JOIN users u ON u.id = o.user_id LEFT JOIN order_addresses oa ON oa.order_id = o.id WHERE a.id = ? AND a.courier_id = ? LIMIT 1");
$stmt->bind_param('ii', $assignmentId, $delivererId);
$stmt->execute();
$assignment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$assignment) {
    http_response_code(404);
    exit('Delivery assignment not found.');
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRFToken::validate($_POST['csrf_token'] ?? '')) {
        $message = 'Security validation failed. Please try again.';
    } else {
        $nextStatus = (string)($_POST['status'] ?? '');
        $notes = trim((string)($_POST['notes'] ?? ''));
        $transitions = [
            'assigned' => ['accepted', 'failed'],
            'accepted' => ['picked_up', 'failed'],
            'picked_up' => ['in_transit', 'failed'],
            'in_transit' => ['delivered', 'failed'],
        ];

        if (!in_array($nextStatus, $transitions[$assignment['assignment_status']] ?? [], true)) {
            $message = 'That delivery status transition is not allowed.';
        } else {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('UPDATE order_courier_assignments SET status = ?, notes = ? WHERE id = ? AND courier_id = ? AND status = ?');
                $stmt->bind_param('ssiis', $nextStatus, $notes, $assignmentId, $delivererId, $assignment['assignment_status']);
                $stmt->execute();
                if ($stmt->affected_rows !== 1) {
                    throw new RuntimeException('Assignment changed before the update could be saved.');
                }

                if ($nextStatus === 'delivered') {
                    $stmt = $conn->prepare("UPDATE orders SET order_status = 'delivered', updated_at = NOW() WHERE id = ? AND order_status NOT IN ('cancelled', 'refunded')");
                    $stmt->bind_param('i', $assignment['order_id']);
                    $stmt->execute();
                    if ($stmt->affected_rows === 1) {
                        $stmt = $conn->prepare("INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, notes, created_at) VALUES (?, ?, 'delivered', ?, ?, NOW())");
                        $stmt->bind_param('isis', $assignment['order_id'], $assignment['order_status'], $_SESSION['user_id'], $notes);
                        $stmt->execute();
                    } else {
                        $stmt = $conn->prepare('SELECT order_status FROM orders WHERE id = ?');
                        $stmt->bind_param('i', $assignment['order_id']);
                        $stmt->execute();
                        $currentStatus = $stmt->get_result()->fetch_column();
                        if ($currentStatus !== 'delivered') {
                            throw new RuntimeException('Order cannot be marked delivered in its current state.');
                        }
                    }
                }

                $conn->commit();
                setFlashMessage('Delivery status updated.', 'success');
                redirect(BASE_URL . 'delivery/index.php');
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Delivery status update failed: ' . $exception->getMessage());
                $message = 'Delivery status could not be updated.';
            }
        }
    }
}

$nextStatuses = [
    'assigned' => ['accepted', 'failed'],
    'accepted' => ['picked_up', 'failed'],
    'picked_up' => ['in_transit', 'failed'],
    'in_transit' => ['delivered', 'failed'],
][$assignment['assignment_status']] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Delivery <?php echo e($assignment['order_number']); ?> - <?php echo e(SITE_NAME); ?></title>
    <style>
        body { margin: 0; padding: 24px; background: #f2f6f3; color: #17221b; font: 16px Arial, sans-serif; }
        main { width: min(100%, 620px); margin: 24px auto; padding: 24px; background: #fff; border: 1px solid #dce6df; border-radius: 6px; }
        h1 { margin-top: 0; font-size: 1.5rem; }
        dl { display: grid; grid-template-columns: 130px 1fr; gap: 10px; }
        dt { color: #58665d; } dd { margin: 0; overflow-wrap: anywhere; }
        label { display: block; margin: 20px 0 8px; font-weight: 700; }
        select, textarea, button { width: 100%; box-sizing: border-box; padding: 12px; font: inherit; }
        textarea, select { border: 1px solid #c7d2ca; border-radius: 4px; }
        button { margin-top: 14px; border: 0; border-radius: 4px; background: #0b6e3d; color: #fff; font-weight: 700; }
        .message { padding: 12px; background: #fff0ee; color: #8b1e13; }
        a { color: #0b6e3d; }
        @media (max-width: 480px) { body { padding: 12px; } main { padding: 18px; } dl { grid-template-columns: 1fr; gap: 4px; } dd { margin-bottom: 8px; } }
    </style>
</head>
<body>
<main>
    <p><a href="index.php">Back to delivery dashboard</a></p>
    <h1>Order #<?php echo e($assignment['order_number']); ?></h1>
    <dl>
        <dt>Assignment status</dt><dd><?php echo e($assignment['assignment_status']); ?></dd>
        <dt>Customer</dt><dd><?php echo e($assignment['customer_name']); ?>, <?php echo e($assignment['delivery_phone'] ?: $assignment['customer_phone']); ?></dd>
        <dt>Destination</dt><dd><?php echo e(implode(', ', array_filter([$assignment['address_line1'], $assignment['address_line2'], $assignment['city'], $assignment['region']] ))); ?></dd>
        <dt>Order type</dt><dd><?php echo e($assignment['delivery_type']); ?></dd>
    </dl>
    <?php if ($message !== ''): ?><p class="message" role="alert"><?php echo e($message); ?></p><?php endif; ?>
    <?php if ($nextStatuses): ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo e(CSRFToken::generate()); ?>">
            <input type="hidden" name="assignment_id" value="<?php echo $assignmentId; ?>">
            <label for="status">Update delivery status</label>
            <select id="status" name="status" required>
                <?php foreach ($nextStatuses as $status): ?><option value="<?php echo e($status); ?>"><?php echo e(ucwords(str_replace('_', ' ', $status))); ?></option><?php endforeach; ?>
            </select>
            <label for="notes">Delivery note</label>
            <textarea id="notes" name="notes" maxlength="1000" rows="3"></textarea>
            <button type="submit">Save status</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>

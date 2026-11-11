<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$token = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string)($_POST['token'] ?? '')
    : (string)($_GET['token'] ?? '');
$message = '';
$messageType = 'error';
$resetComplete = false;

if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
    $message = 'This reset link is invalid or expired. Request a new one.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRFToken::validate($_POST['csrf_token'] ?? '')) {
        $message = 'Security validation failed. Please try again.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $confirmation = (string)($_POST['password_confirmation'] ?? '');
        if ($password !== $confirmation) {
            $message = 'The passwords do not match.';
        } else {
            try {
                $result = Auth::resetPassword($token, $password);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                $resetComplete = $result['success'];
            } catch (Throwable $exception) {
                error_log('Password reset failed: ' . $exception->getMessage());
                $message = 'We could not reset the password right now. Please try again later.';
            }
        }
    }
}

$csrfToken = CSRFToken::generate();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose a new password - <?php echo e(SITE_NAME); ?></title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #f4f7f5; color: #17221b; font: 16px Arial, sans-serif; }
        main { width: min(100%, 420px); padding: 32px; background: #fff; border: 1px solid #dce6df; border-radius: 8px; }
        h1 { margin: 0 0 10px; font-size: 1.6rem; }
        p { line-height: 1.55; color: #56645a; }
        label { display: block; margin: 18px 0 7px; font-weight: 600; }
        input { width: 100%; box-sizing: border-box; padding: 12px; border: 1px solid #b8c6bc; border-radius: 4px; font: inherit; }
        button { margin-top: 18px; width: 100%; padding: 12px; border: 0; border-radius: 4px; background: #0b6e3d; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        .message { padding: 12px; border-radius: 4px; background: #fff0ee; color: #8b1e13; }
        .success { background: #edf6ef; color: #1b5132; }
        a { color: #0b6e3d; }
    </style>
</head>
<body>
<main>
    <h1>Choose a new password</h1>
    <?php if ($message !== ''): ?>
        <p class="message <?php echo $messageType === 'success' ? 'success' : ''; ?>" role="status"><?php echo e($message); ?></p>
    <?php endif; ?>
    <?php if (!$resetComplete && preg_match('/\A[a-f0-9]{64}\z/', $token)): ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="token" value="<?php echo e($token); ?>">
            <label for="password">New password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>
            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required>
            <button type="submit">Update password</button>
        </form>
    <?php endif; ?>
    <p><a href="<?php echo e(BASE_URL . 'login.php'); ?>">Back to login</a></p>
</main>
</body>
</html>
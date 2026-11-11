<?php
/**
 * delivery/index.php - Deliverer Dashboard
 * Bless Mall - Quality Chinese Products | Ghana
 * 
 * Features:
 * - Complete deliverer dashboard with real-time stats
 * - Active deliveries management
 * - Status update capability
 * - Availability toggling
 * - Mobile-responsive design
 * - Flash messages support
 * - CSRF protection
 */

// Load configuration and authentication
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// ==================== AUTHENTICATION GUARD ====================
// Check if user is logged in
if (!isLoggedIn()) {
    setFlashMessage('Please login to access the delivery dashboard.', 'error');
    redirect(BASE_URL . 'login.php?type=deliverer');
    exit;
}

// Check if user is a deliverer
if (!isDeliverer()) {
    // Logged in but not a deliverer – redirect to appropriate portal
    if (isAdmin()) {
        redirect(BASE_URL . 'admin/index.php');
    } elseif (isSeller()) {
        redirect(BASE_URL . 'admin/index.php');
    } else {
        setFlashMessage('You do not have delivery partner access.', 'error');
        redirect(BASE_URL . 'account.php');
    }
    exit;
}

$user_id = getCurrentUserId();
$deliverer_id = getCurrentDelivererId();

// ==================== FETCH DELIVERER PROFILE ====================
try {
    $stmt = $conn->prepare("
        SELECT d.*, u.name, u.phone, u.email, u.account_type, u.created_at
        FROM deliverers d
        JOIN users u ON d.user_id = u.id
        WHERE d.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $deliverer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Exception $e) {
    error_log("Deliverer fetch error: " . $e->getMessage());
    $deliverer = null;
}

if (!$deliverer) {
    // Session has deliverer_id but no DB row – clear and redirect
    unset($_SESSION['deliverer_id'], $_SESSION['deliverer_status'], $_SESSION['deliverer_vehicle_type']);
    setFlashMessage('Your delivery profile could not be found. Please contact support.', 'error');
    redirect(BASE_URL . 'login.php');
    exit;
}

// Update session with fresh data
$_SESSION['deliverer_status'] = $deliverer['availability_status'];
$_SESSION['deliverer_vehicle_type'] = $deliverer['vehicle_type'] ?? null;

// ==================== FETCH STATISTICS ====================
$stats = [
    'total' => 0,
    'assigned' => 0,
    'accepted' => 0,
    'picked_up' => 0,
    'in_transit' => 0,
    'delivered' => 0,
    'failed' => 0,
    'completed' => 0
];

try {
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(status = 'assigned') AS assigned,
            SUM(status = 'accepted') AS accepted,
            SUM(status = 'picked_up') AS picked_up,
            SUM(status = 'in_transit') AS in_transit,
            SUM(status = 'delivered') AS delivered,
            SUM(status = 'failed') AS failed,
            SUM(status IN ('delivered', 'completed')) AS completed
        FROM order_courier_assignments
        WHERE courier_id = ?
    ");
    $stmt->bind_param('i', $deliverer_id);
    $stmt->execute();
    $stats_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($stats_result) {
        $stats = array_merge($stats, $stats_result);
    }
} catch (Exception $e) {
    error_log("Stats fetch error: " . $e->getMessage());
}

// ==================== TODAY'S STATS ====================
$today_stats = ['delivered' => 0, 'total' => 0];
try {
    $stmt = $conn->prepare("
        SELECT
            SUM(status = 'delivered') AS delivered,
            COUNT(*) AS total
        FROM order_courier_assignments
        WHERE courier_id = ?
          AND DATE(assigned_at) = CURDATE()
    ");
    $stmt->bind_param('i', $deliverer_id);
    $stmt->execute();
    $today_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($today_result) {
        $today_stats = array_merge($today_stats, $today_result);
    }
} catch (Exception $e) {
    error_log("Today stats error: " . $e->getMessage());
}

// ==================== FETCH ACTIVE DELIVERIES ====================
$active_deliveries = [];
try {
    $stmt = $conn->prepare("
        SELECT
            oca.id AS assignment_id,
            oca.status AS assignment_status,
            oca.assigned_at,
            oca.notes AS assignment_notes,
            o.id AS order_id,
            o.order_number,
            o.total_amount,
            o.delivery_type,
            o.order_status,
            u.name AS customer_name,
            u.phone AS customer_phone,
            oa.address_line1,
            oa.address_line2,
            oa.city,
            oa.region,
            oa.phone AS delivery_phone,
            v.business_name AS vendor_name
        FROM order_courier_assignments oca
        JOIN orders o ON oca.order_id = o.id
        JOIN users u ON o.user_id = u.id
        LEFT JOIN order_addresses oa ON oa.order_id = o.id
        LEFT JOIN vendors v ON o.vendor_id = v.id
        WHERE oca.courier_id = ?
          AND oca.status NOT IN ('delivered', 'failed', 'completed')
        ORDER BY
            FIELD(oca.status, 'in_transit', 'picked_up', 'accepted', 'assigned') ASC,
            oca.assigned_at ASC
        LIMIT 20
    ");
    $stmt->bind_param('i', $deliverer_id);
    $stmt->execute();
    $active_deliveries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    error_log("Active deliveries error: " . $e->getMessage());
}

// ==================== FETCH RECENT COMPLETIONS ====================
$recent_completed = [];
try {
    $stmt = $conn->prepare("
        SELECT
            oca.id AS assignment_id,
            oca.status AS assignment_status,
            oca.assigned_at,
            o.order_number,
            o.total_amount,
            u.name AS customer_name,
            oa.city,
            oa.region
        FROM order_courier_assignments oca
        JOIN orders o ON oca.order_id = o.id
        JOIN users u ON o.user_id = u.id
        LEFT JOIN order_addresses oa ON oa.order_id = o.id
        WHERE oca.courier_id = ?
          AND oca.status IN ('delivered', 'completed')
        ORDER BY oca.assigned_at DESC
        LIMIT 5
    ");
    $stmt->bind_param('i', $deliverer_id);
    $stmt->execute();
    $recent_completed = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    error_log("Recent completed error: " . $e->getMessage());
}

// ==================== CALCULATE ACTIVE COUNT ====================
$active_count = (int)($stats['assigned'] ?? 0) + (int)($stats['accepted'] ?? 0) 
              + (int)($stats['picked_up'] ?? 0) + (int)($stats['in_transit'] ?? 0);

// ==================== FLASH MESSAGE ====================
$flash = getFlashMessage();

// ==================== STATUS MAPS ====================
$status_map = [
    'assigned' => [
        'label' => 'Assigned', 
        'color' => '#D4AF37', 
        'bg' => 'rgba(212,175,55,.12)', 
        'icon' => 'bx bx-package'
    ],
    'accepted' => [
        'label' => 'Accepted', 
        'color' => '#3b82f6', 
        'bg' => 'rgba(59,130,246,.12)', 
        'icon' => 'bx bx-check-circle'
    ],
    'picked_up' => [
        'label' => 'Picked Up', 
        'color' => '#8b5cf6', 
        'bg' => 'rgba(139,92,246,.12)', 
        'icon' => 'bx bx-store'
    ],
    'in_transit' => [
        'label' => 'In Transit', 
        'color' => '#f97316', 
        'bg' => 'rgba(249,115,22,.12)', 
        'icon' => 'bx bxs-truck'
    ],
    'delivered' => [
        'label' => 'Delivered', 
        'color' => '#0B6E3D', 
        'bg' => 'rgba(11,110,61,.12)', 
        'icon' => 'bx bx-check-double'
    ],
    'failed' => [
        'label' => 'Failed', 
        'color' => '#ef4444', 
        'bg' => 'rgba(239,68,68,.12)', 
        'icon' => 'bx bx-x-circle'
    ],
];

$avail_map = [
    'available' => ['label' => 'Available', 'dot' => '#22c55e', 'glow' => 'rgba(34,197,94,.35)'],
    'busy' => ['label' => 'Busy', 'dot' => '#f97316', 'glow' => 'rgba(249,115,22,.35)'],
    'offline' => ['label' => 'Offline', 'dot' => '#94a3b8', 'glow' => 'rgba(148,163,184,.2)'],
];
$current_avail = $avail_map[$deliverer['availability_status']] ?? $avail_map['offline'];

// ==================== PAGE TITLE ====================
$page_title = 'Delivery Dashboard - ' . SITE_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Bless Mall Delivery Partner Dashboard - Manage your deliveries">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $page_title; ?></title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,400&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    
    <style>
        /* ─── Design tokens ─────────────────────────────── */
        :root {
            --green:        #0B6E3D;
            --green-mid:    #1FAF5B;
            --green-pale:   #e8f5ef;
            --green-glow:   rgba(11,110,61,.25);
            --gold:         #D4AF37;
            --gold-pale:    #faf3d8;
            --gold-glow:    rgba(212,175,55,.2);
            --dark:         #0d1018;
            --dark2:        #141720;
            --dark3:        #1e2130;
            --dark4:        #252838;
            --muted:        #6b7280;
            --border:       rgba(255,255,255,0.07);
            --border-hover: rgba(255,255,255,0.14);
            --text:         #e2e8f0;
            --text-dim:     #94a3b8;
            --text-faint:   #4b5563;
            --radius:       14px;
            --radius-sm:    9px;
            --card-bg:      #191c27;
            --sidebar-w:    256px;
            --font-head:    'Syne', sans-serif;
            --font-body:    'DM Sans', sans-serif;
            --shadow:       0 4px 24px rgba(0,0,0,.4);
            --shadow-sm:    0 2px 8px rgba(0,0,0,.3);
        }

        /* ─── Reset ─────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 15px; scroll-behavior: smooth; }
        body {
            font-family: var(--font-body);
            background: var(--dark);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            line-height: 1.5;
        }
        a { color: inherit; text-decoration: none; }
        button { font-family: var(--font-body); cursor: pointer; border: none; }
        img { display: block; max-width: 100%; }
        ::-webkit-scrollbar { width: 4px; height: 4px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--dark4); border-radius: 99px; }

        /* ─── Sidebar ───────────────────────────────────── */
        .sidebar {
            width: var(--sidebar-w);
            min-height: 100vh;
            background: var(--dark2);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0; bottom: 0;
            z-index: 100;
            transition: transform .28s cubic-bezier(.4,0,.2,1);
        }

        .sidebar::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse at 0% 0%, rgba(11,110,61,.08) 0%, transparent 60%);
            pointer-events: none;
        }

        .sidebar-logo {
            padding: 26px 22px 18px;
            border-bottom: 1px solid var(--border);
            position: relative;
        }
        .brand {
            font-family: var(--font-head);
            font-size: 1.3rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -.03em;
            line-height: 1;
        }
        .brand span { color: var(--gold); }
        .brand-sub {
            font-size: .68rem;
            color: var(--text-faint);
            margin-top: 4px;
            letter-spacing: .1em;
            text-transform: uppercase;
        }

        .sidebar-profile {
            padding: 16px 22px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 11px;
        }
        .avatar {
            width: 38px; height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--green) 0%, #1a8a4a 50%, var(--gold) 100%);
            display: flex; align-items: center; justify-content: center;
            font-family: var(--font-head);
            font-weight: 700;
            font-size: .88rem;
            color: #fff;
            flex-shrink: 0;
            box-shadow: 0 0 0 2px rgba(212,175,55,.25);
        }
        .profile-name {
            font-weight: 600;
            font-size: .85rem;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .avail-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: .69rem;
            color: var(--text-dim);
            margin-top: 2px;
        }
        .avail-dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .avail-dot.pulse {
            animation: pulse-dot 2s infinite;
            box-shadow: 0 0 0 0 currentColor;
        }
        @keyframes pulse-dot {
            0%   { box-shadow: 0 0 0 0 var(--dot-color, rgba(34,197,94,.6)); }
            70%  { box-shadow: 0 0 0 5px transparent; }
            100% { box-shadow: 0 0 0 0 transparent; }
        }

        nav { flex: 1; padding: 14px 0; overflow-y: auto; }
        .nav-section { padding: 0 14px; margin-bottom: 6px; }
        .nav-label {
            font-size: .62rem;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: var(--text-faint);
            padding: 8px 8px 4px;
            font-weight: 600;
        }
        .nav-link {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 8px 10px;
            border-radius: var(--radius-sm);
            color: var(--text-dim);
            font-size: .845rem;
            font-weight: 400;
            transition: all .18s ease;
            margin-bottom: 1px;
            position: relative;
        }
        .nav-link i { font-size: 1.05rem; flex-shrink: 0; }
        .nav-link:hover {
            background: rgba(255,255,255,.05);
            color: var(--text);
        }
        .nav-link.active {
            background: linear-gradient(90deg, rgba(11,110,61,.35), rgba(11,110,61,.1));
            color: #fff;
            font-weight: 500;
            border: 1px solid rgba(11,110,61,.3);
        }
        .nav-link.active i { color: var(--green-mid); }
        .nav-link .badge-count {
            margin-left: auto;
            background: var(--gold);
            color: var(--dark);
            font-size: .62rem;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 99px;
            min-width: 20px;
            text-align: center;
        }

        .sidebar-footer {
            padding: 14px;
            border-top: 1px solid var(--border);
        }
        .sidebar-footer a {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 8px 10px;
            border-radius: var(--radius-sm);
            color: #ef4444;
            font-size: .845rem;
            transition: background .18s;
        }
        .sidebar-footer a:hover { background: rgba(239,68,68,.08); }
        .sidebar-footer a i { font-size: 1.05rem; }

        /* ─── Main ──────────────────────────────────────── */
        .main {
            margin-left: var(--sidebar-w);
            flex: 1;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: var(--dark);
        }

        /* ─── Topbar ────────────────────────────────────── */
        .topbar {
            height: 62px;
            background: var(--dark2);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 26px;
            position: sticky;
            top: 0;
            z-index: 50;
            backdrop-filter: blur(12px);
        }
        .topbar-left { display: flex; align-items: center; gap: 12px; }
        .page-title {
            font-family: var(--font-head);
            font-size: 1.05rem;
            font-weight: 700;
            color: #fff;
            letter-spacing: -.02em;
        }
        .topbar-date {
            font-size: .75rem;
            color: var(--text-dim);
            background: var(--dark3);
            padding: 4px 10px;
            border-radius: 6px;
            border: 1px solid var(--border);
        }
        .topbar-right { display: flex; align-items: center; gap: 8px; }

        .avail-toggle-btn {
            display: flex;
            align-items: center;
            gap: 7px;
            background: var(--dark3);
            border: 1px solid var(--border);
            border-radius: 99px;
            padding: 5px 13px 5px 9px;
            font-size: .78rem;
            cursor: pointer;
            transition: border-color .2s;
            color: var(--text);
        }
        .avail-toggle-btn:hover { border-color: var(--green-mid); }
        .avail-toggle-btn .toggle-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .avail-toggle-btn i { font-size: .8rem; color: var(--text-dim); }

        .icon-btn {
            width: 36px; height: 36px;
            border-radius: var(--radius-sm);
            background: var(--dark3);
            border: 1px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            color: var(--text-dim);
            font-size: 1.05rem;
            transition: all .18s;
            position: relative;
        }
        .icon-btn:hover { border-color: var(--border-hover); color: var(--text); }
        .notif-dot {
            position: absolute;
            top: 6px; right: 6px;
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--gold);
            border: 2px solid var(--dark2);
        }

        .hamburger {
            display: none;
            background: var(--dark3);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 5px 7px;
            color: var(--text);
            font-size: 1.15rem;
            cursor: pointer;
            align-items: center;
        }

        /* ─── Content ───────────────────────────────────── */
        .content { padding: 24px 26px; flex: 1; max-width: 1400px; width: 100%; }

        /* ─── Flash ─────────────────────────────────────── */
        .flash {
            padding: 13px 16px;
            border-radius: var(--radius);
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: .86rem;
        }
        .flash.success { background: rgba(11,110,61,.12); border: 1px solid rgba(11,110,61,.25); color: #6ee7a6; }
        .flash.error   { background: rgba(239,68,68,.1);  border: 1px solid rgba(239,68,68,.2);  color: #fca5a5; }
        .flash.warning { background: rgba(212,175,55,.1); border: 1px solid rgba(212,175,55,.2); color: #fde68a; }
        .flash.info    { background: rgba(59,130,246,.1); border: 1px solid rgba(59,130,246,.2); color: #93c5fd; }

        /* ─── Welcome banner ─────────────────────────────── */
        .welcome-banner {
            background: linear-gradient(110deg, var(--dark3) 0%, rgba(11,110,61,.12) 60%, rgba(212,175,55,.06) 100%);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 22px 26px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            overflow: hidden;
            position: relative;
        }
        .welcome-banner::after {
            content: '';
            position: absolute;
            right: -40px; top: -40px;
            width: 200px; height: 200px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(212,175,55,.06) 0%, transparent 70%);
            pointer-events: none;
        }
        .welcome-text h2 {
            font-family: var(--font-head);
            font-size: 1.15rem;
            font-weight: 700;
            color: #fff;
            letter-spacing: -.02em;
        }
        .welcome-text p {
            font-size: .8rem;
            color: var(--text-dim);
            margin-top: 3px;
        }
        .welcome-text p strong { color: var(--gold); }
        .banner-actions { display: flex; gap: 8px; flex-shrink: 0; }
        .btn-banner {
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            font-size: .8rem;
            font-weight: 600;
            transition: all .18s;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .btn-banner.primary { background: var(--green); color: #fff; }
        .btn-banner.primary:hover { background: var(--green-mid); }
        .btn-banner.outline { background: transparent; border: 1px solid var(--border); color: var(--text-dim); }
        .btn-banner.outline:hover { border-color: var(--border-hover); color: var(--text); }

        /* ─── Stats grid ─────────────────────────────────── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 22px;
        }
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px 20px;
            position: relative;
            overflow: hidden;
            transition: transform .2s, border-color .2s, box-shadow .2s;
            cursor: default;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            border-color: var(--border-hover);
            box-shadow: var(--shadow-sm);
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: var(--accent-color, var(--green));
            opacity: .8;
        }
        .stat-card::after {
            content: '';
            position: absolute;
            bottom: -20px; right: -20px;
            width: 80px; height: 80px;
            border-radius: 50%;
            background: var(--accent-color, var(--green));
            opacity: .04;
        }
        .stat-icon {
            width: 36px; height: 36px;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
            margin-bottom: 12px;
            background: var(--icon-bg, rgba(11,110,61,.12));
            color: var(--accent-color, var(--green-mid));
        }
        .stat-value {
            font-family: var(--font-head);
            font-size: 2rem;
            font-weight: 800;
            color: #fff;
            line-height: 1;
            margin-bottom: 4px;
            letter-spacing: -.03em;
        }
        .stat-label {
            font-size: .72rem;
            color: var(--text-dim);
            text-transform: uppercase;
            letter-spacing: .06em;
            font-weight: 500;
        }

        /* ─── Section layout ─────────────────────────────── */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 18px;
            align-items: start;
        }

        /* ─── Section header ─────────────────────────────── */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 13px;
        }
        .section-title {
            font-family: var(--font-head);
            font-size: .95rem;
            font-weight: 700;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 7px;
            letter-spacing: -.01em;
        }
        .section-title i { color: var(--gold); font-size: 1rem; }
        .view-all {
            font-size: .76rem;
            color: var(--green-mid);
            display: flex;
            align-items: center;
            gap: 3px;
            transition: gap .2s;
        }
        .view-all:hover { gap: 7px; }

        /* ─── Delivery cards ─────────────────────────────── */
        .delivery-list { display: flex; flex-direction: column; gap: 10px; }

        .delivery-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px 18px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            transition: border-color .18s, transform .18s, box-shadow .18s;
            position: relative;
            overflow: hidden;
        }
        .delivery-card::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 3px;
            background: var(--status-color, var(--green));
            border-radius: 0 2px 2px 0;
        }
        .delivery-card:hover {
            border-color: var(--border-hover);
            transform: translateX(2px);
            box-shadow: var(--shadow-sm);
        }
        .delivery-icon {
            width: 42px; height: 42px;
            border-radius: 10px;
            background: var(--status-bg, rgba(255,255,255,.05));
            display: flex; align-items: center; justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
            color: var(--status-color, var(--gold));
        }
        .delivery-body { flex: 1; min-width: 0; }
        .delivery-order {
            font-family: var(--font-head);
            font-weight: 700;
            color: #fff;
            font-size: .88rem;
            letter-spacing: -.01em;
        }
        .delivery-info-row {
            font-size: .77rem;
            color: var(--text-dim);
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }
        .delivery-info-row i { font-size: .85rem; flex-shrink: 0; }
        .delivery-info-row a { color: var(--green-mid); }
        .delivery-info-row a:hover { text-decoration: underline; }
        .delivery-address { margin-top: 5px; }
        .dot-sep { color: var(--text-faint); }

        .delivery-actions { display: flex; gap: 5px; margin-top: 10px; flex-wrap: wrap; }
        .btn-xs {
            padding: 5px 11px;
            border-radius: 7px;
            font-size: .73rem;
            font-weight: 600;
            transition: all .18s;
            display: flex;
            align-items: center;
            gap: 3px;
            white-space: nowrap;
        }
        .btn-primary   { background: var(--green); color: #fff; }
        .btn-primary:hover { background: var(--green-mid); }
        .btn-call      { background: rgba(59,130,246,.15); border: 1px solid rgba(59,130,246,.2); color: #93c5fd; }
        .btn-call:hover { background: rgba(59,130,246,.25); }

        .delivery-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 6px;
            flex-shrink: 0;
            min-width: 90px;
        }
        .delivery-amount {
            font-family: var(--font-head);
            font-weight: 700;
            font-size: .9rem;
            color: var(--gold);
            letter-spacing: -.01em;
        }
        .status-pill {
            padding: 3px 9px;
            border-radius: 99px;
            font-size: .67rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            background: rgba(255,255,255,.05);
            border: 1px solid rgba(255,255,255,.08);
            white-space: nowrap;
        }
        .time-label {
            font-size: .68rem;
            color: var(--text-faint);
        }

        /* ─── Empty state ────────────────────────────────── */
        .empty-state {
            text-align: center;
            padding: 44px 24px;
            color: var(--text-dim);
            background: var(--card-bg);
            border: 1px dashed rgba(255,255,255,.08);
            border-radius: var(--radius);
        }
        .empty-state i { font-size: 2.8rem; margin-bottom: 10px; opacity: .25; display: block; }
        .empty-state p { font-size: .85rem; line-height: 1.6; }

        /* ─── Right column ───────────────────────────────── */
        .right-col { display: flex; flex-direction: column; gap: 16px; }

        /* ─── Info card ──────────────────────────────────── */
        .info-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }
        .info-card-header {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            font-family: var(--font-head);
            font-weight: 700;
            font-size: .85rem;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 7px;
            letter-spacing: -.01em;
        }
        .info-card-header i { color: var(--gold); font-size: .95rem; }
        .info-card-body { padding: 16px 18px; }

        .profile-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
            font-size: .8rem;
        }
        .profile-row:last-child { border-bottom: none; padding-bottom: 0; }
        .profile-row i { font-size: .95rem; color: var(--green-mid); width: 16px; text-align: center; flex-shrink: 0; }
        .row-label { color: var(--text-dim); min-width: 68px; font-size: .75rem; }
        .row-value { color: var(--text); font-weight: 500; }

        /* Availability form */
        .avail-select-wrap { display: flex; gap: 8px; }
        .avail-select {
            flex: 1;
            background: var(--dark3);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 8px 12px;
            color: var(--text);
            font-family: var(--font-body);
            font-size: .82rem;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 11px center;
            cursor: pointer;
        }
        .avail-select:focus { outline: none; border-color: var(--green-mid); }
        .btn-save {
            padding: 8px 15px;
            background: var(--green);
            color: #fff;
            border-radius: var(--radius-sm);
            font-size: .8rem;
            font-weight: 600;
            transition: background .18s;
            white-space: nowrap;
        }
        .btn-save:hover { background: var(--green-mid); }
        .avail-hint { font-size: .75rem; color: var(--text-dim); margin-bottom: 11px; line-height: 1.4; }

        /* Recent table */
        .recent-table { width: 100%; border-collapse: collapse; }
        .recent-table th {
            font-size: .65rem;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: var(--text-faint);
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            font-weight: 600;
        }
        .recent-table td {
            padding: 9px 10px;
            font-size: .78rem;
            border-bottom: 1px solid rgba(255,255,255,.035);
            color: var(--text);
        }
        .recent-table tr:last-child td { border-bottom: none; }
        .recent-table tbody tr:hover td { background: rgba(255,255,255,.02); }
        .recent-table .order-cell { font-weight: 700; color: #fff; font-family: var(--font-head); font-size: .8rem; }
        .recent-table .amount-cell { color: var(--gold); font-weight: 700; }

        /* ─── Entrance animations ────────────────────────── */
        @keyframes fade-up {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .animate-in { opacity: 0; animation: fade-up .4s ease forwards; }
        .delay-1 { animation-delay: .06s; }
        .delay-2 { animation-delay: .12s; }
        .delay-3 { animation-delay: .18s; }
        .delay-4 { animation-delay: .24s; }
        .delay-5 { animation-delay: .30s; }

        /* ─── Responsive ─────────────────────────────────── */
        @media (max-width: 1100px) {
            .grid-2 { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; }
            .hamburger { display: flex; }
            .topbar { padding: 0 14px; }
            .content { padding: 14px 16px; }
            .welcome-banner { flex-direction: column; align-items: flex-start; }
            .banner-actions { width: 100%; }
            .btn-banner { flex: 1; justify-content: center; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .stat-value { font-size: 1.6rem; }
            .delivery-card { flex-direction: column; }
            .delivery-meta { align-items: flex-start; width: 100%; }
        }
    </style>
</head>
<body>

<!-- ── Sidebar ──────────────────────────────────── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <div class="brand">Bless<span>Mall</span></div>
        <div class="brand-sub">Deliverer Portal</div>
    </div>

    <div class="sidebar-profile">
        <div class="avatar"><?php echo strtoupper(mb_substr($deliverer['name'], 0, 1)); ?></div>
        <div>
            <div class="profile-name"><?php echo e($deliverer['name']); ?></div>
            <div class="avail-badge">
                <span class="avail-dot pulse" style="background:<?php echo $current_avail['dot']; ?>;--dot-color:<?php echo $current_avail['glow']; ?>"></span>
                <?php echo $current_avail['label']; ?>
            </div>
        </div>
    </div>

    <nav>
        <div class="nav-section">
            <div class="nav-label">Main</div>
            <a href="index.php" class="nav-link active">
                <i class='bx bxs-dashboard'></i> Dashboard
            </a>
            <a href="deliveries.php" class="nav-link">
                <i class='bx bxs-truck'></i> My Deliveries
                <?php if ($active_count > 0): ?>
                    <span class="badge-count"><?php echo $active_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="history.php" class="nav-link">
                <i class='bx bx-history'></i> Delivery History
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-label">Account</div>
            <a href="profile.php" class="nav-link">
                <i class='bx bx-user'></i> My Profile
            </a>
            <a href="earnings.php" class="nav-link">
                <i class='bx bx-wallet'></i> Earnings
            </a>
        </div>
    </nav>

    <div class="sidebar-footer">
        <a href="<?php echo BASE_URL; ?>logout.php">
            <i class='bx bx-log-out'></i> Sign Out
        </a>
    </div>
</aside>

<!-- ── Main ─────────────────────────────────────── -->
<div class="main">

    <header class="topbar">
        <div class="topbar-left">
            <button class="hamburger" onclick="toggleSidebar()" aria-label="Menu">
                <i class='bx bx-menu'></i>
            </button>
            <span class="page-title">Dashboard</span>
            <span class="topbar-date"><?php echo date('D, d M Y'); ?></span>
        </div>
        <div class="topbar-right">
            <!-- Quick availability cycle -->
            <form method="POST" action="update-availability.php" id="availForm" style="display:contents">
                <?php echo CSRFToken::field(); ?>
                <input type="hidden" name="status" id="availStatus" value="<?php echo e($deliverer['availability_status']); ?>">
                <button type="button" class="avail-toggle-btn" onclick="cycleAvailability()" title="Cycle availability status">
                    <span class="toggle-dot" id="toggleDot" style="background:<?php echo $current_avail['dot']; ?>"></span>
                    <span id="toggleLabel"><?php echo $current_avail['label']; ?></span>
                    <i class='bx bx-chevron-down'></i>
                </button>
            </form>

            <button class="icon-btn" title="Active deliveries" onclick="location.href='index.php#active-deliveries'">
                <i class='bx bx-bell'></i>
                <?php if ($active_count > 0): ?>
                    <span class="notif-dot"></span>
                <?php endif; ?>
            </button>
            <a href="index.php#rider-info" class="icon-btn" title="Rider profile">
                <i class='bx bx-cog'></i>
            </a>
        </div>
    </header>

    <div class="content">

        <!-- Flash message -->
        <?php if ($flash): ?>
            <div class="flash <?php echo e($flash['type']); ?> animate-in">
                <i class='bx bx-<?php echo $flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'error' ? 'x-circle' : 'info-circle'); ?>'></i>
                <?php echo e($flash['text']); ?>
            </div>
        <?php endif; ?>

        <!-- Welcome banner -->
        <div class="welcome-banner animate-in">
            <div class="welcome-text">
                <h2>Welcome back, <?php echo e(explode(' ', $deliverer['name'])[0]); ?> 👋</h2>
                <p>
                    You have <strong><?php echo $active_count; ?> active</strong>
                    <?php echo $active_count === 1 ? 'delivery' : 'deliveries'; ?> right now.
                    <?php if ((int)($today_stats['delivered'] ?? 0) > 0): ?>
                        Today you've completed <strong><?php echo (int)$today_stats['delivered']; ?></strong> drop-offs.
                    <?php else: ?>
                        Keep up the great work!
                    <?php endif; ?>
                </p>
            </div>
            <div class="banner-actions">
                <?php if ($active_count > 0): ?>
                    <a href="deliveries.php" class="btn-banner primary">
                        <i class='bx bxs-truck'></i> View Active
                    </a>
                <?php endif; ?>
                <a href="history.php" class="btn-banner outline">
                    <i class='bx bx-history'></i> History
                </a>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="stats-grid">
            <div class="stat-card animate-in delay-1" style="--accent-color:#D4AF37;--icon-bg:rgba(212,175,55,.1)">
                <div class="stat-icon"><i class='bx bx-package'></i></div>
                <div class="stat-value"><?php echo (int)($stats['total'] ?? 0); ?></div>
                <div class="stat-label">Total Assigned</div>
            </div>
            <div class="stat-card animate-in delay-2" style="--accent-color:#f97316;--icon-bg:rgba(249,115,22,.1)">
                <div class="stat-icon"><i class='bx bxs-truck'></i></div>
                <div class="stat-value"><?php echo (int)($stats['in_transit'] ?? 0); ?></div>
                <div class="stat-label">In Transit</div>
            </div>
            <div class="stat-card animate-in delay-3" style="--accent-color:#0B6E3D;--icon-bg:rgba(11,110,61,.1)">
                <div class="stat-icon"><i class='bx bx-check-double'></i></div>
                <div class="stat-value"><?php echo (int)($stats['delivered'] ?? 0); ?></div>
                <div class="stat-label">Delivered</div>
            </div>
            <div class="stat-card animate-in delay-4" style="--accent-color:#3b82f6;--icon-bg:rgba(59,130,246,.1)">
                <div class="stat-icon"><i class='bx bx-sun'></i></div>
                <div class="stat-value"><?php echo (int)($today_stats['delivered'] ?? 0); ?></div>
                <div class="stat-label">Today's Drop-offs</div>
            </div>
        </div>

        <!-- Two-column grid -->
        <div class="grid-2">

            <!-- Active deliveries -->
            <div class="animate-in delay-2" id="active-deliveries">
                <div class="section-header">
                    <div class="section-title">
                        <i class='bx bxs-truck'></i>
                        Active Deliveries
                        <?php if ($active_count > 0): ?>
                            <span style="font-size:.7rem;font-family:var(--font-body);background:var(--gold);color:var(--dark);padding:2px 8px;border-radius:99px;font-weight:700"><?php echo $active_count; ?></span>
                        <?php endif; ?>
                    </div>
                    <a href="deliveries.php" class="view-all">View all <i class='bx bx-right-arrow-alt'></i></a>
                </div>

                <?php if (empty($active_deliveries)): ?>
                    <div class="empty-state">
                        <i class='bx bx-package'></i>
                        <p>No active deliveries at the moment.<br>New assignments will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="delivery-list">
                    <?php foreach ($active_deliveries as $d):
                        $si = $status_map[$d['assignment_status']] ?? $status_map['assigned'];
                        $addr_parts = array_filter([$d['address_line1'], $d['city'], $d['region']]);
                        $addr = e(implode(', ', $addr_parts));
                        $phone = $d['delivery_phone'] ?: $d['customer_phone'];
                    ?>
                        <div class="delivery-card" style="--status-color:<?php echo $si['color']; ?>;--status-bg:<?php echo $si['bg']; ?>">
                            <div class="delivery-icon">
                                <i class="<?php echo $si['icon']; ?>"></i>
                            </div>
                            <div class="delivery-body">
                                <div class="delivery-order">#<?php echo e($d['order_number']); ?></div>
                                <div class="delivery-info-row">
                                    <i class='bx bx-user'></i>
                                    <?php echo e($d['customer_name']); ?>
                                    <?php if ($phone): ?>
                                        <span class="dot-sep">·</span>
                                        <a href="tel:<?php echo e($phone); ?>"><?php echo e($phone); ?></a>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($d['vendor_name'])): ?>
                                <div class="delivery-info-row">
                                    <i class='bx bx-store'></i>
                                    <?php echo e($d['vendor_name']); ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($addr): ?>
                                    <div class="delivery-info-row delivery-address">
                                        <i class='bx bx-map-pin' style="color:var(--gold)"></i>
                                        <?php echo $addr; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="delivery-actions">
                                    <button class="btn-xs btn-primary" onclick="location.href='order-detail.php?id=<?php echo (int)$d['assignment_id']; ?>'">
                                        <i class='bx bx-edit-alt'></i> Update
                                    </button>
                                    <?php if ($phone): ?>
                                        <a href="tel:<?php echo e($phone); ?>" class="btn-xs btn-call">
                                            <i class='bx bx-phone'></i> Call
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="delivery-meta">
                                <div class="delivery-amount"><?php echo formatPrice($d['total_amount']); ?></div>
                                <span class="status-pill" style="color:<?php echo $si['color']; ?>;border-color:<?php echo $si['color']; ?>33">
                                    <?php echo $si['label']; ?>
                                </span>
                                <div class="time-label"><?php echo timeAgo($d['assigned_at']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right column -->
            <div class="right-col animate-in delay-3">

                <!-- Rider info -->
                <div class="info-card" id="rider-info">
                    <div class="info-card-header">
                        <i class='bx bx-id-card'></i> Rider Info
                    </div>
                    <div class="info-card-body">
                        <div class="profile-row">
                            <i class='bx bx-user'></i>
                            <span class="row-label">Name</span>
                            <span class="row-value"><?php echo e($deliverer['name']); ?></span>
                        </div>
                        <div class="profile-row">
                            <i class='bx bx-phone'></i>
                            <span class="row-label">Phone</span>
                            <span class="row-value"><?php echo e($deliverer['phone']); ?></span>
                        </div>
                        <div class="profile-row">
                            <i class='bx bx-cycling'></i>
                            <span class="row-label">Vehicle</span>
                            <span class="row-value"><?php echo e($deliverer['vehicle_type'] ?: '—'); ?></span>
                        </div>
                        <?php if (!empty($deliverer['license_number'])): ?>
                        <div class="profile-row">
                            <i class='bx bx-credit-card'></i>
                            <span class="row-label">License</span>
                            <span class="row-value"><?php echo e($deliverer['license_number']); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="profile-row">
                            <i class='bx bx-envelope'></i>
                            <span class="row-label">Email</span>
                            <span class="row-value" style="font-size:.74rem;word-break:break-all"><?php echo e($deliverer['email'] ?: '—'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Availability updater -->
                <div class="info-card">
                    <div class="info-card-header">
                        <i class='bx bx-pulse'></i> Availability
                    </div>
                    <div class="info-card-body">
                        <p class="avail-hint">Update your status so dispatch knows your situation in real time.</p>
                        <form method="POST" action="update-availability.php">
                            <?php echo CSRFToken::field(); ?>
                            <div class="avail-select-wrap">
                                <select name="status" class="avail-select">
                                    <option value="available" <?php echo $deliverer['availability_status'] === 'available' ? 'selected' : ''; ?>>🟢 Available</option>
                                    <option value="busy" <?php echo $deliverer['availability_status'] === 'busy' ? 'selected' : ''; ?>>🟠 Busy</option>
                                    <option value="offline" <?php echo $deliverer['availability_status'] === 'offline' ? 'selected' : ''; ?>>⚫ Offline</option>
                                </select>
                                <button type="submit" class="btn-save">Save</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Recent completions -->
                <?php if (!empty($recent_completed)): ?>
                <div class="info-card">
                    <div class="info-card-header">
                        <i class='bx bx-check-double'></i> Recent Completions
                    </div>
                    <div style="overflow-x:auto">
                        <table class="recent-table">
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recent_completed as $r): ?>
                                <tr>
                                    <td class="order-cell">#<?php echo e($r['order_number']); ?></td>
                                    <td><?php echo e($r['customer_name']); ?></td>
                                    <td class="amount-cell"><?php echo formatPrice($r['total_amount']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<script>
// ─── Sidebar toggle ──────────────────────────────
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
}
document.addEventListener('click', function(e) {
    const sidebar = document.getElementById('sidebar');
    if (sidebar.classList.contains('open') &&
        !sidebar.contains(e.target) &&
        !e.target.closest('.hamburger')) {
        sidebar.classList.remove('open');
    }
});

// ─── Availability cycle ──────────────────────────
const AVAIL_CYCLE = ['available', 'busy', 'offline'];
const AVAIL_COLORS = { available: '#22c55e', busy: '#f97316', offline: '#94a3b8' };
const AVAIL_LABELS = { available: 'Available', busy: 'Busy', offline: 'Offline' };

function cycleAvailability() {
    const inp = document.getElementById('availStatus');
    const dot = document.getElementById('toggleDot');
    const label = document.getElementById('toggleLabel');
    const cur = inp.value;
    const next = AVAIL_CYCLE[(AVAIL_CYCLE.indexOf(cur) + 1) % AVAIL_CYCLE.length];
    inp.value = next;
    dot.style.background = AVAIL_COLORS[next];
    label.textContent = AVAIL_LABELS[next];
    document.getElementById('availForm').submit();
}
</script>
</body>
</html>
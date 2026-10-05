<?php
// BLACKHABIT: Cashier uses its own independent session.
session_name('BH_CASHIER_SESSION');
session_start();

if (
    !isset($_SESSION['role']) ||
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true
) {
    header('Location: index.php');
    exit();
}

$role = strtolower(trim((string)$_SESSION['role']));

if ($role !== 'cashier' && (int)$_SESSION['role'] !== 3) {
    header('Location: index.php');
    exit();
}

require_once 'db.php';

$status = trim($_GET['status'] ?? '');
$allowed = ['Pending','Processing','Ready','Completed','Cancelled'];
if (!in_array($status, $allowed, true)) $status = '';

$from = trim($_GET['from_date'] ?? '');
$to = trim($_GET['to_date'] ?? '');

if ($from !== '' && $to === '') $to = $from;
if ($to !== '' && $from === '') $from = $to;
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}

$where = [];

if ($status === 'Cancelled') {
    $where[] = "orders.status='Cancelled'";
} else {
    $where[] = "orders.status <> 'Cancelled'";
    if ($status !== '') {
        $where[] = "orders.status='" . mysqli_real_escape_string($conn, $status) . "'";
    }
}
if ($from !== '') {
    $where[] = "DATE(orders.created_at) >= '" . mysqli_real_escape_string($conn, $from) . "'";
}
if ($to !== '') {
    $where[] = "DATE(orders.created_at) <= '" . mysqli_real_escape_string($conn, $to) . "'";
}

$sql = "SELECT orders.*
        FROM orders
        WHERE " . implode(' AND ', $where) . "
        ORDER BY orders.created_at DESC, orders.id DESC";

$result = mysqli_query($conn, $sql);
if (!$result) die('Order query failed: ' . htmlspecialchars(mysqli_error($conn)));

function orderNumber($id, $created = '') {
    $year = $created ? date('Y', strtotime($created)) : date('Y');
    return 'BH-' . $year . '-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}

function statusClass($s) {
    return strtolower(str_replace(' ', '-', $s));
}

function filterUrl($status = '', $from = '', $to = '') {
    $params = [];
    if ($status !== '') $params['status'] = $status;
    if ($from !== '') $params['from_date'] = $from;
    if ($to !== '') $params['to_date'] = $to;
    return 'cashier_order_management.php' . ($params ? '?' . http_build_query($params) : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Manage Orders - BLACKHABIT</title>
<link rel="icon" href="favicon_io/favicon.ico">
<link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<style>
.table-container{overflow-x:auto}
.order-filters{display:flex;gap:10px;flex-wrap:wrap;margin:15px 0}
.filter-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 13px;border:1px solid #333;border-radius:9px;color:#ddd;text-decoration:none;background:#181818}
.filter-btn.active{background:#c59d5f;color:#111;border-color:#c59d5f}
.date-filter{display:flex;gap:10px;align-items:end;flex-wrap:wrap;padding:15px;background:#161616;border:1px solid #2b2b2b;border-radius:10px}
.date-filter label{display:block;color:#aaa;font-size:12px;margin-bottom:5px}
.date-filter input{background:#101010;color:#fff;border:1px solid #333;border-radius:7px;padding:9px}
.date-actions{display:flex;gap:8px;margin-left:auto}
.date-actions .btn{padding:9px 16px}
.status-badge{padding:5px 9px;border-radius:15px;font-size:11px;font-weight:700;display:inline-block}
.pending{background:#fff0c2;color:#755800}
.processing{background:#dce9ff;color:#24518c}
.ready{background:#d8f3df;color:#196b35}
.completed{background:#d8f3df;color:#196b35}
.order-number{font-weight:700;color:#c59d5f}
.total-link{color:#c59d5f;font-weight:700;text-decoration:none}
.total-link:hover{text-decoration:underline}
.instruction{max-width:330px;color:#ccc;font-size:12px;line-height:1.55}
.empty{padding:35px;text-align:center;color:#888}
.queue-note{font-size:12px;color:#888;margin:0 0 10px}
.action-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:7px;background:#252525;color:#fff;text-decoration:none;border:1px solid #3a3a3a;font-size:12px;margin:2px}
.action-btn:hover{border-color:#c59d5f;color:#c59d5f}
@media(max-width:700px){
    .date-actions{margin-left:0}
    .date-filter input{width:100%}
    .date-filter>div{width:100%}
}


/* =========================================================
   BLACKHABIT — TABLET / iPAD LANDSCAPE RESPONSIVE
   Added after the page's existing styles so it can override
   desktop/mobile breakpoints without changing PHP logic.
   ========================================================= */

html,
body {
    max-width: 100%;
    overflow-x: hidden;
}

.main {
    min-width: 0;
    width: auto;
}

/* Tablet / iPad landscape: 768px–1366px */
@media screen and (min-width: 768px) and (max-width: 1366px) and (orientation: landscape) {

    .main {
        min-width: 0;
        max-width: 100%;
        padding-left: 22px;
        padding-right: 22px;
    }

    .topbar {
        min-width: 0;
        gap: 14px;
    }

    .topbar h1 {
        min-width: 0;
        font-size: 22px;
        white-space: nowrap;
    }

    .profile {
        min-width: 0;
        flex-shrink: 0;
    }

    .profile h3 {
        font-size: 12px;
        white-space: nowrap;
    }

    .profile span {
        font-size: 10px;
        white-space: nowrap;
    }

    /* Touch-friendly controls */
    button,
    .btn,
    .filter-btn,
    .action-btn,
    select,
    input {
        -webkit-tap-highlight-color: transparent;
    }
}

/* Compact tablet landscape: small iPads / Android tablets */
@media screen and (min-width: 768px) and (max-width: 899px) and (orientation: landscape) {
    .main {
        padding-left: 16px;
        padding-right: 16px;
    }

    .topbar h1 {
        font-size: 20px;
    }
}

/* ---------- ORDER MANAGEMENT ---------- */
@media screen and (min-width: 768px) and (max-width: 1366px) and (orientation: landscape) {

    .panel {
        min-width: 0;
        width: 100%;
    }

    .date-filter {
        display: grid;
        grid-template-columns: minmax(150px, 1fr) minmax(150px, 1fr) auto;
        align-items: end;
        gap: 10px;
    }

    .date-filter > div {
        min-width: 0;
    }

    .date-filter input {
        width: 100%;
        min-height: 40px;
        box-sizing: border-box;
    }

    .date-actions {
        margin-left: 0;
        display: flex;
        gap: 8px;
        flex-shrink: 0;
    }

    .date-actions .btn {
        min-height: 40px;
        white-space: nowrap;
    }

    .order-filters {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 8px;
    }

    .filter-btn {
        min-height: 40px;
        justify-content: center;
        padding: 8px 7px;
        font-size: 11px;
        white-space: nowrap;
        text-align: center;
    }

    .queue-note {
        line-height: 1.5;
    }

    .table-container {
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        overflow-y: visible;
        -webkit-overflow-scrolling: touch;
    }

    .product-table {
        width: 100%;
        min-width: 980px;
        table-layout: auto;
    }

    .product-table th,
    .product-table td {
        white-space: normal;
        word-break: normal;
        vertical-align: middle;
    }

    .product-table th {
        font-size: 10px;
        padding: 11px 8px;
    }

    .product-table td {
        font-size: 11px;
        padding: 12px 8px;
    }

    .order-number {
        white-space: normal;
        word-break: break-word;
    }

    .instruction {
        max-width: 260px;
        min-width: 180px;
        line-height: 1.45;
        overflow-wrap: anywhere;
    }

    .status-badge {
        white-space: nowrap;
        font-size: 10px;
    }

    .action-btn {
        min-height: 38px;
        min-width: 64px;
        justify-content: center;
        white-space: nowrap;
    }
}

@media screen and (min-width: 768px) and (max-width: 899px) and (orientation: landscape) {
    .date-filter {
        grid-template-columns: 1fr 1fr;
    }

    .date-actions {
        grid-column: 1 / -1;
        justify-content: flex-end;
    }

    .order-filters {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .filter-btn {
        white-space: normal;
        min-height: 42px;
    }

    .product-table {
        min-width: 920px;
    }
}

</style>
</head>
<body>
<?php include 'cashier_sidebar.php'; ?>

<div class="main">
<div class="topbar">
    <button class="menu-toggle" id="menuToggle"><i class="fa-solid fa-bars"></i></button>
    <h1>Order Management</h1>
    <div class="profile">
        <i class="fa-solid fa-circle-user" style="font-size:24px;color:#c59d5f"></i>
        <div>
            <h3><?php echo htmlspecialchars($_SESSION['name'] ?? 'Cashier'); ?></h3>
            <span>Cashier</span>
        </div>
    </div>
</div>

<div class="panel">
<div class="panel-header">
    <h2><i class="fa-solid fa-list-check"></i> Orders</h2>
</div>

<form class="date-filter" method="GET" action="cashier_order_management.php">
    <div>
        <label>Ordering Date: From</label>
        <input type="date" name="from_date" value="<?php echo htmlspecialchars($from); ?>">
    </div>

    <div>
        <label>To</label>
        <input type="date" name="to_date" value="<?php echo htmlspecialchars($to); ?>">
    </div>

    <?php if ($status !== ''): ?>
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
    <?php endif; ?>

    <div class="date-actions">
        <button class="btn" type="submit">Go</button>
        <a class="btn btn-reset" href="cashier_order_management.php">Reset</a>
    </div>
</form>

<div class="order-filters">
    <a class="filter-btn <?php echo $status === '' ? 'active' : ''; ?>"
       href="<?php echo htmlspecialchars(filterUrl('', $from, $to)); ?>">
        <i class="fa-solid fa-list"></i> All Orders
    </a>

    <a class="filter-btn <?php echo $status === 'Pending' ? 'active' : ''; ?>"
       href="<?php echo htmlspecialchars(filterUrl('Pending', $from, $to)); ?>">
        <i class="fa-solid fa-clock"></i> Pending Queue
    </a>

    <a class="filter-btn <?php echo $status === 'Processing' ? 'active' : ''; ?>"
       href="<?php echo htmlspecialchars(filterUrl('Processing', $from, $to)); ?>">
        <i class="fa-solid fa-spinner"></i> Processing
    </a>

    <a class="filter-btn <?php echo $status === 'Ready' ? 'active' : ''; ?>"
       href="<?php echo htmlspecialchars(filterUrl('Ready', $from, $to)); ?>">
        <i class="fa-solid fa-bell"></i> Ready
    </a>

    <a class="filter-btn <?php echo $status === 'Cancelled' ? 'active' : ''; ?>"
       href="<?php echo htmlspecialchars(filterUrl('Cancelled', $from, $to)); ?>">
        <i class="fa-solid fa-ban"></i> Cancelled
    </a>

    <a class="filter-btn <?php echo $status === 'Completed' ? 'active' : ''; ?>"
       href="<?php echo htmlspecialchars(filterUrl('Completed', $from, $to)); ?>">
        <i class="fa-solid fa-circle-check"></i> Completed
    </a>
</div>

<p class="queue-note">
    Pending orders are the active queue waiting for cashier processing.
    Cancelled orders are excluded from this order list.
</p>

<div class="table-container">
<table class="product-table">
<thead>
<tr>
    <th>Order No.</th>
    <th>Total</th>
    <th>Paid</th>
    <th>Balance</th>
    <th>Status</th>
    <th>Date &amp; Time</th>
    <th>Kitchen Instructions</th>
    <th>Action</th>
</tr>
</thead>

<?php
/* Check optional columns once per page load for database compatibility. */
$columnExists = function($table, $column) use ($conn) {
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $check = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $check && mysqli_num_rows($check) > 0;
};
$hasSpecialInstructions = $columnExists('order_items', 'special_instructions');
$hasAddons = $columnExists('order_items', 'addons');
$specialSql = $hasSpecialInstructions ? "oi.special_instructions" : "'' AS special_instructions";
$addonsSql = $hasAddons ? "oi.addons" : "'' AS addons";
?>

<tbody>
<?php if (mysqli_num_rows($result) > 0): ?>

<?php while ($row = mysqli_fetch_assoc($result)): ?>
<?php
    $oid = (int)$row['id'];

    $itemsQ = mysqli_query(
        $conn,
        "SELECT
            oi.quantity,
            oi.flavor,
            oi.size,
            oi.sugar,
            oi.ice,
            $specialSql,
            $addonsSql,
            p.name
         FROM order_items oi
         LEFT JOIN products p
            ON p.id = oi.product_id
         WHERE oi.order_id = $oid
         ORDER BY oi.id ASC"
    );

    $parts = [];

    while ($it = ($itemsQ ? mysqli_fetch_assoc($itemsQ) : null)) {
        $x = number_format((int)$it['quantity']) . 'x ' . ($it['name'] ?? 'Product');
        $opts = [];

        foreach (
            ['size' => 'Size', 'flavor' => 'Flavor', 'sugar' => 'Sugar', 'ice' => 'Ice']
            as $k => $lab
        ) {
            if (
                !empty($it[$k]) &&
                $it[$k] !== 'Default' &&
                $it[$k] !== 'Original' &&
                $it[$k] !== 'Normal' &&
                $it[$k] !== '100%'
            ) {
                $opts[] = $lab . ': ' . $it[$k];
            }
        }

        $special = trim((string)($it['special_instructions'] ?? ''));
        $addons = trim((string)($it['addons'] ?? ''));
        if ($addons !== '' && strcasecmp($addons, 'None') !== 0) $opts[] = 'Add-ons: ' . $addons;
        $line = $x . ($opts ? ' (' . implode(', ', $opts) . ')' : '');
        if ($special !== '') $line .= ' — Note: ' . $special;
        $parts[] = $line;
    }

    $instruction = $parts
        ? implode(' • ', $parts)
        : 'No item instructions recorded.';
?>

<tr>
    <td>
        <span class="order-number">
            <?php echo htmlspecialchars(orderNumber($oid, $row['created_at'])); ?>
        </span>
    </td>
    <td>
        <a class="total-link"
           href="view_order.php?id=<?php echo $oid; ?>&from=cashier">
            ₱<?php echo number_format((float)$row['total'], 2); ?>
        </a>
    </td>

    <td>₱<?php echo number_format((float)($row['paid_amount'] ?? 0), 2); ?></td>

    <td>₱<?php echo number_format((float)($row['balance'] ?? 0), 2); ?></td>

    <td>
        <span class="status-badge <?php echo statusClass($row['status']); ?>">
            <?php echo htmlspecialchars($row['status']); ?>
        </span>
    </td>

    <td>
        <?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?>
    </td>

    <td class="instruction">
        <?php echo htmlspecialchars($instruction); ?>
    </td>

    <td>
        <a class="action-btn"
           href="view_order.php?id=<?php echo $oid; ?>&from=cashier">
            <i class="fa-solid fa-eye"></i> View
        </a>


    </td>
</tr>

<?php endwhile; ?>

<?php else: ?>

<tr>
    <td colspan="9" class="empty">
        No orders found for the selected filter/date range.
    </td>
</tr>

<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>



</body>
</html>

<?php
// BLACKHABIT: Admin uses its own independent session.
session_name('BH_ADMIN_SESSION');
session_start();

// Admin access only
if (!isset($_SESSION['role']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

$role = strtolower(trim((string)$_SESSION['role']));

if ($role !== 'admin' && (int)$_SESSION['role'] !== 1) {
    header("Location: index.php");
    exit();
}

include "db.php";

/* ==========================
   DASHBOARD STATISTICS
========================== */

// Total Products
$product = mysqli_fetch_assoc(mysqli_query($conn,"
SELECT COUNT(*) AS total
FROM products
"));
$totalProducts = $product['total'];

// Total Ingredients
$ingredient = mysqli_fetch_assoc(mysqli_query($conn,"
SELECT COUNT(*) AS total
FROM ingredients
"));
$totalIngredients = $ingredient['total'];

// Total Supplies
$supply = mysqli_fetch_assoc(mysqli_query($conn,"
SELECT COUNT(*) AS total
FROM supplies
"));
$totalSupplies = $supply['total'];

// Total Orders
$order = mysqli_fetch_assoc(mysqli_query($conn,"
SELECT COUNT(*) AS total
FROM orders
"));
$totalOrders = $order['total'];

// Completed Orders
$completed = mysqli_fetch_assoc(mysqli_query($conn,"
SELECT COUNT(*) AS total
FROM orders
WHERE status='Completed'
"));
$totalCompleted = $completed['total'];

// Pending Orders
$pending = mysqli_fetch_assoc(mysqli_query($conn,"
SELECT COUNT(*) AS total
FROM orders
WHERE status='Pending'
"));
$totalPending = $pending['total'];

// Cancelled Orders
$cancelled = mysqli_fetch_assoc(mysqli_query($conn,"
SELECT COUNT(*) AS total
FROM orders
WHERE status='Cancelled'
"));
$totalCancelled = $cancelled['total'];

// Total Sales
$sales = mysqli_fetch_assoc(mysqli_query($conn,"
SELECT IFNULL(SUM(total),0) AS total
FROM orders
WHERE status='Completed'
"));
$totalSales = $sales['total'];

// Today's Sales
$todaySales = mysqli_fetch_assoc(mysqli_query($conn,"
SELECT IFNULL(SUM(total),0) AS total
FROM orders
WHERE status='Completed'
AND DATE(created_at)=CURDATE()
"));
$totalTodaySales = $todaySales['total'];

// Monthly Sales
$monthSales = mysqli_fetch_assoc(mysqli_query($conn,"
SELECT IFNULL(SUM(total),0) AS total
FROM orders
WHERE status='Completed'
AND MONTH(created_at)=MONTH(CURDATE())
AND YEAR(created_at)=YEAR(CURDATE())
"));
$totalMonthSales = $monthSales['total'];

// Low Stock Ingredients
$lowIngredients = mysqli_fetch_assoc(mysqli_query($conn,"
SELECT COUNT(*) AS total
FROM ingredients
WHERE current_stock <= minimum_stock
"));
$totalLowIngredients = $lowIngredients['total'];

// Low Stock Supplies
$lowSupplies = mysqli_fetch_assoc(mysqli_query($conn,"
SELECT COUNT(*) AS total
FROM supplies
WHERE current_stock <= minimum_stock
"));
$totalLowSupplies = $lowSupplies['total'];

$totalLowStock = $totalLowIngredients + $totalLowSupplies;

?>

<?php
/* ==========================================================
   ADMIN SALES OVERVIEW
   Default: Last 7 Days
   The dropdown changes the chart range without changing the
   existing dashboard cards.
========================================================== */

$allowedRanges = [
    '7'       => 'Last 7 Days',
    '14'      => 'Last 14 Days',
    '30'      => 'Last 30 Days',
    'month'   => 'This Month',
    'lastmonth' => 'Last Month',
    'year'    => 'This Year',
    'lastyear'=> 'Last Year'
];

$salesRange = strtolower(trim((string)($_GET['sales_range'] ?? '7')));
if (!in_array($salesRange, array_keys($allowedRanges), true) && $salesRange !== 'custom') {
    $salesRange = '7';
}

$customStart = trim((string)($_GET['custom_start'] ?? ''));
$customEnd = trim((string)($_GET['custom_end'] ?? ''));

if ($salesRange === 'custom') {
    $validCustomDates =
        preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $customStart) &&
        preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $customEnd) &&
        strtotime($customStart) !== false &&
        strtotime($customEnd) !== false &&
        $customStart <= $customEnd;

    if (!$validCustomDates) {
        $salesRange = '7';
        $customStart = '';
        $customEnd = '';
    }
}

$rangeStart = date('Y-m-d');
$rangeEnd = date('Y-m-d');

switch ($salesRange) {
    case '14':
        $rangeStart = date('Y-m-d', strtotime('-13 days'));
        break;

    case '30':
        $rangeStart = date('Y-m-d', strtotime('-29 days'));
        break;

    case 'month':
        $rangeStart = date('Y-m-01');
        break;

    case 'lastmonth':
        $rangeStart = date('Y-m-01', strtotime('first day of last month'));
        $rangeEnd = date('Y-m-t', strtotime('last month'));
        break;

    case 'year':
        $rangeStart = date('Y-01-01');
        break;

    case 'lastyear':
        $rangeStart = date('Y-01-01', strtotime('-1 year'));
        $rangeEnd = date('Y-12-31', strtotime('-1 year'));
        break;

    case 'custom':
        $rangeStart = $customStart;
        $rangeEnd = $customEnd;
        break;

    default:
        $rangeStart = date('Y-m-d', strtotime('-6 days'));
        break;
}

$rangeEndExclusive = date('Y-m-d', strtotime($rangeEnd . ' +1 day'));

$adminDailyRevenue = [];

$startEsc = mysqli_real_escape_string($conn, $rangeStart);
$endEsc = mysqli_real_escape_string($conn, $rangeEndExclusive);

$daily_q = mysqli_query($conn, "
    SELECT DATE(created_at) AS sale_date,
           IFNULL(SUM(total),0) AS revenue
    FROM orders
    WHERE status='Completed'
      AND created_at >= '{$startEsc}'
      AND created_at < '{$endEsc}'
    GROUP BY DATE(created_at)
    ORDER BY sale_date ASC
");

if ($daily_q) {
    while ($r = mysqli_fetch_assoc($daily_q)) {
        $adminDailyRevenue[$r['sale_date']] = (float)$r['revenue'];
    }
}

$adminChartLabels = [];
$adminChartRevenue = [];

$cursor = new DateTime($rangeStart);
$endDate = new DateTime($rangeEnd);

while ($cursor <= $endDate) {
    $d = $cursor->format('Y-m-d');
    $adminChartLabels[] = $cursor->format('M j');
    $adminChartRevenue[] = $adminDailyRevenue[$d] ?? 0;
    $cursor->modify('+1 day');
}

$adminOrderStatus = [];
$status_q = mysqli_query($conn, "SELECT status, COUNT(*) AS total FROM orders GROUP BY status");
if ($status_q) {
    while ($r = mysqli_fetch_assoc($status_q)) {
        $adminOrderStatus[$r['status']] = (int)$r['total'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BlackHabit Admin Dashboard</title>
<link rel="icon" type="image/x-icon" href="favicon_io/favicon.ico">
<link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

<style>
:root{
 --bh-bg:#0b0b0b;--bh-panel:#151515;--bh-panel2:#1b1b1b;--bh-border:#2a2a2a;
 --bh-gold:#cda45e;--bh-gold2:#e5bd76;--bh-text:#f6f6f6;--bh-muted:#999;
 --bh-green:#24c46a;--bh-blue:#2492ff;--bh-red:#ef5350;--bh-orange:#ff9d22;
}
*{box-sizing:border-box}
body{margin:0;background:radial-gradient(circle at 80% 0%,rgba(205,164,94,.08),transparent 28%),var(--bh-bg);color:var(--bh-text);font-family:Poppins,sans-serif}
.main{min-height:100vh;padding:0 34px 40px}
.topbar{height:78px;display:flex;align-items:center;border-bottom:1px solid #222}
.topbar h1{font-size:25px;margin:0;font-weight:700}
.menu-toggle{background:none;border:0;color:#fff;font-size:21px;cursor:pointer;margin-right:20px}
.profile{margin-left:auto;display:flex;align-items:center;gap:11px}
.profile i{font-size:28px;color:var(--bh-gold)}
.profile h3{font-size:14px;margin:0}.profile span{font-size:11px;color:var(--bh-muted)}
.dashboard-head{display:flex;justify-content:space-between;align-items:flex-end;padding:28px 0 20px}
.dashboard-head h2{margin:0;font-size:30px}.dashboard-head h2 span{color:var(--bh-gold)}
.dashboard-head p{margin:5px 0 0;color:var(--bh-muted);font-size:13px}
.brand-quote{text-align:right;color:var(--bh-gold2);font-size:20px;font-weight:600;font-style:italic}
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:16px}
.card{background:linear-gradient(145deg,#181818,#111);border:1px solid var(--bh-border);border-radius:15px;min-height:108px;padding:18px;display:flex;align-items:center;gap:15px;box-shadow:0 8px 25px rgba(0,0,0,.18)}
.card .icon{width:52px;height:52px;border-radius:13px;display:grid;place-items:center;background:rgba(205,164,94,.12);color:var(--bh-gold2);font-size:21px;border:1px solid rgba(205,164,94,.16)}
.card h3{margin:0 0 5px;color:#aaa;font-size:11px;text-transform:uppercase;letter-spacing:.4px}.card h1{margin:0;font-size:24px}
.content-grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(300px,.85fr);gap:16px}
.chart-grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(280px,.85fr);gap:16px;margin-bottom:16px}
.panel{background:linear-gradient(145deg,#171717,#111);border:1px solid var(--bh-border);border-radius:15px;padding:19px;box-shadow:0 8px 25px rgba(0,0,0,.16)}
.panel-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:15px}
.panel-header h2{font-size:16px;margin:0}.panel-header h2 i{color:var(--bh-gold);margin-right:8px}
.chart-box{height:285px;position:relative}
.quick-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:11px}
.quick-btn{min-height:85px;padding:14px 8px;border:1px solid #303030;border-radius:12px;background:#202020;color:#fff;text-decoration:none;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;transition:.2s;font-size:12px;font-weight:600}
.quick-btn i{font-size:20px;color:var(--bh-gold2)}.quick-btn:hover{transform:translateY(-2px);border-color:var(--bh-gold)}
.quick-btn.primary{background:linear-gradient(145deg,#dcb36d,#bd8f4a);color:#111}.quick-btn.primary i{color:#111}
.queue-table{width:100%;border-collapse:collapse}.queue-table th{text-align:left;padding:11px 9px;background:#202020;color:#aaa;font-size:10px;text-transform:uppercase}
.queue-table td{padding:12px 9px;border-bottom:1px solid #262626;font-size:12px}.queue-table tr:last-child td{border-bottom:0}
.status{display:inline-flex;padding:5px 10px;border-radius:999px;font-size:10px;font-weight:700;white-space:nowrap}
.status.pending{background:rgba(255,157,34,.15);color:#ffad3d}.status.processing{background:rgba(205,164,94,.16);color:#e4bb70}
.status.success{background:rgba(36,196,106,.14);color:#35d978}.status.danger{background:rgba(239,83,80,.14);color:#ff6b68}
.side-stack{display:grid;gap:16px}.info-card{min-height:125px;display:flex;flex-direction:column;justify-content:center}
.info-card .big-icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:rgba(205,164,94,.12);color:var(--bh-gold);margin-bottom:10px}
.info-card h3{margin:0;font-size:14px}.info-card p{margin:4px 0 0;color:var(--bh-muted);font-size:11px;line-height:1.6}
.low-list{display:grid;gap:9px}.low-item{display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border:1px solid #282828;border-radius:9px;background:#191919;font-size:11px}
.low-item strong{color:#fff}.low-badge{padding:4px 8px;border-radius:99px;background:rgba(239,83,80,.15);color:#ff7471;font-size:9px;font-weight:700}.low-type{padding:3px 7px;border-radius:6px;background:rgba(205,164,94,.12);color:var(--bh-gold2);font-size:8px;font-weight:700;text-transform:uppercase;flex:none}.low-badge.out{background:rgba(239,83,80,.25);color:#ff5f5c}
/* Sales Overview range selector */
.sales-overview-header{
    gap:15px;
}
.sales-range-form{
    margin-left:auto;
}
.sales-range-form select{
    min-width:138px;
    height:36px;
    padding:0 30px 0 12px;
    border:1px solid #cda45e;
    border-radius:8px;
    background:#171717;
    color:#f6f6f6;
    font-family:Poppins,sans-serif;
    font-size:11px;
    font-weight:500;
    outline:none;
    cursor:pointer;
}
.custom-date-fields{
    align-items:flex-end;
    gap:7px;
    margin-top:8px;
}
.custom-date-fields label{
    display:flex;
    flex-direction:column;
    gap:3px;
    color:#999;
    font-size:9px;
    font-weight:500;
}
.custom-date-fields input{
    height:36px;
    width:135px;
    padding:0 9px;
    border:1px solid #333;
    border-radius:8px;
    background:#171717;
    color:#f6f6f6;
    font-family:Poppins,sans-serif;
    font-size:10px;
    outline:none;
}
.custom-date-fields input:focus{
    border-color:#cda45e;
}
.custom-apply-btn{
    height:36px;
    padding:0 14px;
    border:1px solid #cda45e;
    border-radius:8px;
    background:#cda45e;
    color:#111;
    font-family:Poppins,sans-serif;
    font-size:10px;
    font-weight:700;
    cursor:pointer;
}
.custom-apply-btn:hover{
    background:#e5bd76;
}
.sales-range-form select:hover,
.sales-range-form select:focus{
    border-color:#e5bd76;
    box-shadow:0 0 0 2px rgba(205,164,94,.10);
}
.sales-range-form option{
    background:#171717;
    color:#f6f6f6;
}

@media(max-width:1150px){.cards{grid-template-columns:repeat(2,1fr)}.chart-grid,.content-grid{grid-template-columns:1fr}}
@media(max-width:800px){.sales-overview-header{align-items:flex-start}.sales-range-form{margin-left:0}.sales-range-form select{width:138px}.custom-date-fields{flex-wrap:wrap}.custom-date-fields input{width:130px}.main{padding:0 16px 30px}.cards{grid-template-columns:1fr}.quick-grid{grid-template-columns:1fr 1fr}.brand-quote{display:none}.queue-scroll{overflow-x:auto}.queue-table{min-width:650px}}
</style>
<style>

/* ==========================================================
   BLACKHABIT ADMIN - FINAL RESPONSIVE OVERRIDE
   Desktop / iPad landscape / iPad portrait / phone
   This section only changes layout; PHP/database logic is untouched.
   ========================================================== */

html, body {
    max-width: 100%;
    overflow-x: hidden;
}

.main {
    box-sizing: border-box;
    min-width: 0 !important;
    width: auto;
    max-width: 100%;
}

.main * {
    box-sizing: border-box;
}

.topbar {
    min-width: 0;
    max-width: 100%;
}

.topbar h1 {
    min-width: 0;
    overflow-wrap: anywhere;
}

.panel,
.sales-analytics,
.page-intro,
.form-container,
.section,
.dashboard-head {
    max-width: 100%;
    min-width: 0;
}

.table-container,
.formula-table-wrap,
.queue-scroll {
    width: 100%;
    max-width: 100%;
    overflow-x: auto !important;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
}

.product-table,
.order-management-table,
.history-table,
.queue-table {
    max-width: none;
}

img, canvas, video, iframe {
    max-width: 100%;
}

button, input, select, textarea {
    max-width: 100%;
}

@media (min-width: 769px) and (max-width: 1366px) {
    .main {
        padding-left: 18px !important;
        padding-right: 18px !important;
    }

    .topbar {
        gap: 12px;
    }

    .topbar h1 {
        font-size: clamp(20px, 2.2vw, 27px) !important;
    }

    .profile {
        min-width: 0;
    }

    .cards {
        gap: 12px !important;
    }

    .panel {
        padding: 16px !important;
    }

    .table-container {
        border-radius: 8px;
    }

    .product-table th,
    .product-table td,
    .order-management-table th,
    .order-management-table td,
    .history-table th,
    .history-table td {
        padding: 9px 8px;
        font-size: 12px;
    }
}

@media (min-width: 769px) and (max-width: 1100px) {
    .cards {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    .content-grid,
    .chart-grid {
        grid-template-columns: 1fr !important;
    }

    .inventory-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    .form-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    .sales-overview-header,
    .analytics-header-right,
    .panel-header {
        min-width: 0;
    }
}

@media (min-width: 769px) and (max-width: 900px) {
    .main {
        padding: 14px !important;
    }

    .cards {
        gap: 10px !important;
    }

    .card {
        min-width: 0 !important;
    }

    .quick-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    .inventory-summary {
        grid-template-columns: 1fr !important;
    }

    .history-tools,
    .inventory-toolbar,
    .product-list-toolbar,
    .formula-heading {
        gap: 10px;
    }
}

@media (max-width: 768px) {
    .main {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        margin-left: 0 !important;
        padding: 12px !important;
    }

    .topbar {
        width: 100%;
        flex-wrap: wrap;
        align-items: center;
        margin-bottom: 14px !important;
        padding-bottom: 12px !important;
    }

    .topbar h1 {
        flex: 1 1 160px;
        font-size: 20px !important;
        line-height: 1.25;
    }

    .profile {
        flex: 0 1 auto;
        margin-left: auto;
    }

    .profile > div {
        display: none;
    }

    .dashboard-head {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 12px;
    }

    .brand-quote {
        display: none !important;
    }

    .cards {
        grid-template-columns: 1fr !important;
        gap: 10px !important;
    }

    .card {
        width: 100%;
        min-width: 0 !important;
    }

    .quick-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 8px !important;
    }

    .content-grid,
    .chart-grid {
        grid-template-columns: 1fr !important;
    }

    .inventory-summary {
        grid-template-columns: 1fr !important;
    }

    .inventory-toolbar,
    .history-tools,
    .product-list-toolbar,
    .formula-heading {
        flex-direction: column !important;
        align-items: stretch !important;
    }

    .history-search,
    .inventory-search-box,
    .supply-search-box {
        width: 100% !important;
    }

    .inventory-search-panel,
    .supply-search,
    .date-filter,
    .filter-form {
        width: 100%;
        gap: 8px !important;
    }

    .inventory-search-panel,
    .supply-search,
    .date-filter {
        flex-wrap: wrap;
    }

    .inventory-search-box,
    .supply-search-box {
        flex: 1 1 100%;
    }

    .inventory-search-btn,
    .inventory-reset-btn,
    .supply-search-btn,
    .supply-reset-btn,
    .add-ingredient-btn,
    .add-supply-trigger,
    .btn-new-product {
        min-height: 40px;
    }

    .table-container {
        overflow-x: auto !important;
        overflow-y: hidden;
    }

    .product-table,
    .order-management-table,
    .history-table,
    .queue-table {
        width: max-content !important;
        min-width: 700px;
    }

    .product-table th,
    .product-table td,
    .history-table th,
    .history-table td {
        white-space: nowrap;
        font-size: 11px;
        padding: 9px 7px;
    }

    .order-management-table {
        min-width: 950px !important;
    }

    .order-management-table th,
    .order-management-table td {
        font-size: 11px;
        padding: 9px 7px;
        white-space: nowrap;
    }

    .date-filter {
        flex-direction: column !important;
        align-items: stretch !important;
    }

    .date-filter > div,
    .date-actions {
        width: 100% !important;
        margin-left: 0 !important;
    }

    .date-filter input,
    .date-actions .btn {
        width: 100% !important;
    }

    .order-filters {
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px !important;
    }

    .filter-btn {
        width: 100%;
        min-width: 0 !important;
        white-space: normal !important;
        text-align: center;
        padding: 9px 6px !important;
    }

    .form-grid {
        grid-template-columns: 1fr !important;
    }

    .formula-row {
        grid-template-columns: 1fr 1fr 1fr 40px !important;
    }

    .add-product-dialog,
    .supply-modal-dialog {
        width: min(96vw, 700px) !important;
        max-width: 96vw !important;
        max-height: calc(100vh - 20px);
        overflow-y: auto;
    }

    .supply-modal-form-grid {
        grid-template-columns: 1fr !important;
    }

    .sales-analytics {
        padding: 14px !important;
    }

    .sales-overview-header,
    .analytics-header-right {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 10px !important;
    }

    .sales-range-form,
    .sales-range-form select,
    .graph-toggle-btn,
    .custom-apply-btn {
        width: 100% !important;
        margin-left: 0 !important;
    }

    .custom-date-fields {
        flex-wrap: wrap !important;
    }

    .custom-date-fields input {
        width: 100% !important;
        flex: 1 1 130px;
    }

    .chart-container {
        height: 280px !important;
        padding: 12px !important;
    }

    .graph-canvas-wrap {
        height: 270px !important;
    }

    .sales-graph-container {
        padding: 12px !important;
    }

    .page-intro {
        padding: 14px !important;
    }

    .page-intro h2 {
        font-size: 17px !important;
    }
}

@media (max-width: 480px) {
    .main {
        padding: 9px !important;
    }

    .topbar h1 {
        font-size: 18px !important;
    }

    .quick-grid {
        grid-template-columns: 1fr !important;
    }

    .order-filters {
        grid-template-columns: 1fr !important;
    }

    .panel {
        padding: 10px !important;
    }

    .panel-header h2 {
        font-size: 16px !important;
    }

    .product-table,
    .history-table {
        min-width: 650px !important;
    }

    .order-management-table {
        min-width: 900px !important;
    }

    .formula-row {
        grid-template-columns: 1fr 1fr 40px !important;
    }

    .formula-row select {
        grid-column: 1 / -1;
    }
}

</style>

</head>
<body>
<?php include "admin_sidebar.php"; ?>

<div class="main">
 <div class="topbar">
  <button class="menu-toggle" id="menuToggle"><i class="fa-solid fa-bars"></i></button>
  <h1>Admin Dashboard</h1>
  <div class="profile">
   <i class="fa-solid fa-circle-user"></i>
   <div><h3><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin Staff'); ?></h3><span>Administrator</span></div>
  </div>
 </div>

 <div class="dashboard-head">
  <div><h2>Welcome back, <span><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></span> 👋</h2>
  <p>Here's an overview of BlackHabit products, inventory, orders and sales.</p></div>
  <div class="brand-quote">Good Coffee<br>Better Days</div>
 </div>

 <div class="cards">
  <div class="card"><div class="icon"><i class="fa-solid fa-box"></i></div><div><h3>Total Products</h3><h1><?php echo $totalProducts; ?></h1></div></div>
  <div class="card"><div class="icon"><i class="fa-solid fa-seedling"></i></div><div><h3>Ingredients</h3><h1><?php echo $totalIngredients; ?></h1></div></div>
  <div class="card"><div class="icon"><i class="fa-solid fa-cart-shopping"></i></div><div><h3>Total Orders</h3><h1><?php echo $totalOrders; ?></h1></div></div>
  <div class="card"><div class="icon"><i class="fa-solid fa-peso-sign"></i></div><div><h3>Total Sales</h3><h1>₱<?php echo number_format($totalSales,2); ?></h1></div></div>
 </div>

 <div class="cards">
  <div class="card" style="border-left:3px solid #cda45e"><div class="icon"><i class="fa-solid fa-calendar-day"></i></div><div><h3>Today's Sales</h3><h1>₱<?php echo number_format($totalTodaySales,2); ?></h1></div></div>
  <div class="card" style="border-left:3px solid #24c46a"><div class="icon"><i class="fa-solid fa-calendar"></i></div><div><h3>Monthly Sales</h3><h1>₱<?php echo number_format($totalMonthSales,2); ?></h1></div></div>
  <div class="card" style="border-left:3px solid #ff9d22"><div class="icon"><i class="fa-solid fa-hourglass-half"></i></div><div><h3>Pending Orders</h3><h1><?php echo $totalPending; ?></h1></div></div>
  <div class="card" style="border-left:3px solid #ef5350"><div class="icon"><i class="fa-solid fa-triangle-exclamation"></i></div><div><h3>Low Stock Items</h3><h1><?php echo $totalLowStock; ?></h1></div></div>
 </div>

 <div class="chart-grid">
  <div class="panel">
   <div class="panel-header sales-overview-header">
        <h2><i class="fa-solid fa-chart-line"></i> Sales Overview</h2>

        <form method="GET" class="sales-range-form" id="salesRangeForm">
            <select name="sales_range" id="salesRange" aria-label="Sales date range">
                <?php foreach ($allowedRanges as $value => $label): ?>
                    <option value="<?php echo htmlspecialchars($value); ?>"
                        <?php echo $salesRange === $value ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($label); ?>
                    </option>
                <?php endforeach; ?>
                <option value="custom" <?php echo $salesRange === 'custom' ? 'selected' : ''; ?>>
                    Custom Date Range
                </option>
            </select>

            <div class="custom-date-fields" id="customDateFields"
                 style="<?php echo $salesRange === 'custom' ? 'display:flex;' : 'display:none;'; ?>">
                <label>
                    <span>From</span>
                    <input type="date" name="custom_start" id="customStart"
                           value="<?php echo htmlspecialchars($customStart); ?>">
                </label>

                <label>
                    <span>To</span>
                    <input type="date" name="custom_end" id="customEnd"
                           value="<?php echo htmlspecialchars($customEnd); ?>">
                </label>

                <button type="submit" class="custom-apply-btn">Apply</button>
            </div>
        </form>
    </div>
   <div class="chart-box"><canvas id="adminSalesChart"></canvas></div>
  </div>
  <div class="panel">
   <div class="panel-header"><h2><i class="fa-solid fa-chart-pie"></i> Order Status</h2></div>
   <div class="chart-box"><canvas id="adminStatusChart"></canvas></div>
  </div>
 </div>

 <div class="content-grid">
  <div>
   <div class="panel" style="margin-bottom:16px">
    <div class="panel-header"><h2><i class="fa-solid fa-bolt"></i> Quick Actions</h2></div>
    <div class="quick-grid">
     <a href="product_management.php" class="quick-btn primary"><i class="fa-solid fa-box"></i><span>Products</span></a>
     <a href="inventory_management.php?role=admin" class="quick-btn"><i class="fa-solid fa-seedling"></i><span>Ingredients</span></a>
     <a href="admin_order_management.php" class="quick-btn"><i class="fa-solid fa-cart-shopping"></i><span>Orders</span></a>
     <a href="supplies_management.php" class="quick-btn"><i class="fa-solid fa-arrow-down"></i><span>Stock In</span></a>
     <a href="supplies_management.php" class="quick-btn"><i class="fa-solid fa-arrow-up"></i><span>Stock Out</span></a>
    </div>
   </div>

   <div class="panel">
    <div class="panel-header"><h2><i class="fa-solid fa-triangle-exclamation"></i> Low Stock Inventory</h2></div>
    <div class="low-list">
    <?php
    // Show both ingredients and supplies.
    $low = mysqli_query($conn, "
        SELECT
            'Ingredient' AS item_type,
            ingredient_name AS item_name,
            current_stock,
            minimum_stock,
            unit
        FROM ingredients
        WHERE current_stock <= minimum_stock

        UNION ALL

        SELECT
            'Supply' AS item_type,
            supply_name AS item_name,
            current_stock,
            minimum_stock,
            unit
        FROM supplies
        WHERE current_stock <= minimum_stock

        ORDER BY current_stock ASC, item_name ASC
        LIMIT 8
    ");

    if ($low && mysqli_num_rows($low) > 0):
      while($row=mysqli_fetch_assoc($low)):
        $itemType = (string)($row['item_type'] ?? '');
        $itemName = (string)($row['item_name'] ?? '');
        $currentStock = (int)($row['current_stock'] ?? 0);
        $minimumStock = (int)($row['minimum_stock'] ?? 0);
        $unit = (string)($row['unit'] ?? '');
        $statusText = $currentStock <= 0 ? 'OUT' : 'LOW';
        $statusClass = $currentStock <= 0 ? 'out' : 'low';
    ?>
      <div class="low-item">
        <div style="display:flex;align-items:center;gap:9px;min-width:0">
          <span class="low-type"><?php echo htmlspecialchars($itemType); ?></span>
          <strong><?php echo htmlspecialchars($itemName); ?></strong>
        </div>
        <span style="white-space:nowrap">
          <?php echo number_format($currentStock); ?> / <?php echo number_format($minimumStock); ?>
          <?php if ($unit !== ''): ?>
            <?php echo htmlspecialchars($unit); ?>
          <?php endif; ?>
          <span class="low-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
        </span>
      </div>
    <?php endwhile; else: ?>
      <div style="color:#777;font-size:12px;padding:12px">No low-stock ingredients or supplies.</div>
    <?php endif; ?>
    </div>
   </div>
  </div>

  <div class="side-stack">
   <div class="panel">
    <div class="panel-header"><h2><i class="fa-solid fa-clock"></i> Recent Orders</h2></div>
    <div class="queue-scroll">
     <table class="queue-table">
      <thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Status</th></tr></thead>
      <tbody>
      <?php
      $recent = mysqli_query($conn,"SELECT orders.*, users.first_name, users.last_name FROM orders LEFT JOIN users ON orders.user_id = users.id ORDER BY orders.id DESC LIMIT 5");
      if ($recent && mysqli_num_rows($recent)>0):
       while($r=mysqli_fetch_assoc($recent)):
        $custName=!empty($r['first_name'])?trim($r['first_name'].' '.($r['last_name']??'')):'Walk-in';
        $s=trim((string)($r['status']??'Pending'));
        $sc='pending';
        if(in_array(strtolower($s),['completed','ready']))$sc='success';
        elseif(strtolower($s)==='processing')$sc='processing';
        elseif(strtolower($s)==='cancelled')$sc='danger';
      ?>
       <tr><td>#<?php echo (int)$r['id']; ?></td><td><?php echo htmlspecialchars($custName); ?></td><td>₱<?php echo number_format($r['total'],2); ?></td><td><span class="status <?php echo $sc; ?>"><?php echo htmlspecialchars($s); ?></span></td></tr>
      <?php endwhile; else: ?>
       <tr><td colspan="4" style="text-align:center;color:#777;padding:22px">No recent orders.</td></tr>
      <?php endif; ?>
      </tbody>
     </table>
    </div>
   </div>

   <div class="panel info-card">
    <div class="big-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
    <h3>Inventory Overview</h3>
    <p><?php echo $totalIngredients; ?> ingredients · <?php echo $totalSupplies; ?> supplies<br><strong style="color:#fff"><?php echo $totalLowStock; ?> items</strong> currently need attention.</p>
   </div>
  </div>
 </div>
</div>

<script>
(function(){
    const rangeSelect = document.getElementById('salesRange');
    const customFields = document.getElementById('customDateFields');
    const rangeForm = document.getElementById('salesRangeForm');
    const customStart = document.getElementById('customStart');
    const customEnd = document.getElementById('customEnd');

    if (!rangeSelect || !customFields || !rangeForm) return;

    function toggleCustomFields() {
        const isCustom = rangeSelect.value === 'custom';
        customFields.style.display = isCustom ? 'flex' : 'none';

        if (!isCustom) {
            rangeForm.submit();
        }
    }

    rangeSelect.addEventListener('change', toggleCustomFields);

    rangeForm.addEventListener('submit', function(event) {
        if (rangeSelect.value !== 'custom') return;

        if (!customStart.value || !customEnd.value) {
            event.preventDefault();
            alert('Please select both a From date and a To date.');
            return;
        }

        if (customStart.value > customEnd.value) {
            event.preventDefault();
            alert('The From date cannot be later than the To date.');
        }
    });
})();

const adminLabels=<?php echo json_encode($adminChartLabels); ?>;
const adminRevenue=<?php echo json_encode($adminChartRevenue); ?>;
const adminStatusLabels=<?php echo json_encode(array_keys($adminOrderStatus)); ?>;
const adminStatusValues=<?php echo json_encode(array_values($adminOrderStatus)); ?>;

if(typeof Chart!=='undefined'){
 new Chart(document.getElementById('adminSalesChart'),{
  type:'line',
  data:{labels:adminLabels,datasets:[{label:'Revenue',data:adminRevenue,borderColor:'#d4aa61',backgroundColor:'rgba(212,170,97,.14)',fill:true,tension:.38,pointRadius:4,pointBackgroundColor:'#d4aa61',pointBorderColor:'#111'}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>' ₱'+Number(c.raw).toLocaleString('en-PH',{minimumFractionDigits:2})}}},scales:{x:{grid:{color:'rgba(255,255,255,.05)'},ticks:{color:'#999'}},y:{beginAtZero:true,grid:{color:'rgba(255,255,255,.06)'},ticks:{color:'#999',callback:v=>'₱'+Number(v).toLocaleString('en-PH')}}}}
 });
 new Chart(document.getElementById('adminStatusChart'),{
  type:'doughnut',
  data:{labels:adminStatusLabels,datasets:[{data:adminStatusValues,backgroundColor:['#ff9d22','#2492ff','#24c46a','#cda45e','#ef5350','#8d6e63'],borderColor:'#151515',borderWidth:3,hoverOffset:7}]},
  options:{responsive:true,maintainAspectRatio:false,cutout:'68%',plugins:{legend:{position:'bottom',labels:{color:'#ddd',padding:12,usePointStyle:true,font:{size:10}}}}}
 });
}
</script>
</body>
</html>

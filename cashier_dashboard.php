<?php
session_name('BH_CASHIER_SESSION');
session_start();

if (!isset($_SESSION['role']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

$role = strtolower(trim((string)$_SESSION['role']));
if ($role !== 'cashier' && (int)$_SESSION['role'] !== 3) {
    header("Location: index.php");
    exit();
}

include "db.php";

/* Dashboard metrics */
$today_orders_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM orders WHERE DATE(created_at) = CURDATE()");
$totalOrders = ($today_orders_q) ? mysqli_fetch_assoc($today_orders_q)['total'] : 0;

$pending_orders_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM orders WHERE status = 'Pending'");
$totalPending = ($pending_orders_q) ? mysqli_fetch_assoc($pending_orders_q)['total'] : 0;

$completed_orders_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM orders WHERE status = 'Completed'");
$totalCompleted = ($completed_orders_q) ? mysqli_fetch_assoc($completed_orders_q)['total'] : 0;

$today_sales_q = mysqli_query($conn, "SELECT IFNULL(SUM(total), 0) as total FROM orders WHERE status = 'Completed' AND DATE(created_at) = CURDATE()");
$totalSales = ($today_sales_q) ? mysqli_fetch_assoc($today_sales_q)['total'] : 0.00;

$month_sales_q = mysqli_query($conn, "SELECT IFNULL(SUM(total), 0) as total FROM orders WHERE status = 'Completed' AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())");
$monthSales = ($month_sales_q) ? mysqli_fetch_assoc($month_sales_q)['total'] : 0.00;

$year_sales_q = mysqli_query($conn, "SELECT IFNULL(SUM(total), 0) as total FROM orders WHERE status = 'Completed' AND YEAR(created_at) = YEAR(CURDATE())");
$yearSales = ($year_sales_q) ? mysqli_fetch_assoc($year_sales_q)['total'] : 0.00;
$salesPeriod = $_GET['sales_period'] ?? '7';
$allowedPeriods = ['7','14','30','this_month','last_month','this_year','last_year','custom'];
if (!in_array($salesPeriod, $allowedPeriods, true)) {
    $salesPeriod = '7';
}

$periodLabels = [
    '7' => 'Last 7 Days',
    '14' => 'Last 14 Days',
    '30' => 'Last 30 Days',
    'this_month' => 'This Month',
    'last_month' => 'Last Month',
    'this_year' => 'This Year',
    'last_year' => 'Last Year',
    'custom' => 'Custom Date Range'
];

$startDate = new DateTime('today');
$endDate = new DateTime('today');

switch ($salesPeriod) {
    case '14':
        $startDate->modify('-13 days');
        break;
    case '30':
        $startDate->modify('-29 days');
        break;
    case 'this_month':
        $startDate->modify('first day of this month');
        break;
    case 'last_month':
        $startDate->modify('first day of last month');
        $endDate->modify('last day of last month');
        break;
    case 'this_year':
        $startDate->modify('first day of January');
        break;
    case 'last_year':
        $startDate->modify('first day of January last year');
        $endDate->modify('last day of December last year');
        break;
    case 'custom':
        $customFrom = $_GET['from'] ?? '';
        $customTo = $_GET['to'] ?? '';
        $validFrom = DateTime::createFromFormat('Y-m-d', $customFrom);
        $validTo = DateTime::createFromFormat('Y-m-d', $customTo);

        if ($validFrom && $validTo && $validFrom <= $validTo) {
            $startDate = $validFrom;
            $endDate = $validTo;
        } else {
            $salesPeriod = '7';
            $startDate = new DateTime('today');
            $startDate->modify('-6 days');
            $endDate = new DateTime('today');
        }
        break;
    default:
        $startDate->modify('-6 days');
        break;
}

$fromSql = $startDate->format('Y-m-d');
$toSql = $endDate->format('Y-m-d');

$weeklyRevenue = [];
$weekly_q = mysqli_query($conn, "
    SELECT DATE(created_at) AS sale_date, IFNULL(SUM(total),0) AS revenue
    FROM orders
    WHERE status = 'Completed'
      AND DATE(created_at) BETWEEN '{$fromSql}' AND '{$toSql}'
    GROUP BY DATE(created_at)
    ORDER BY sale_date ASC
");
if ($weekly_q) {
    while ($r = mysqli_fetch_assoc($weekly_q)) {
        $weeklyRevenue[$r['sale_date']] = (float)$r['revenue'];
    }
}

$chartLabels = [];
$chartRevenue = [];

$cursor = clone $startDate;
while ($cursor <= $endDate) {
    $d = $cursor->format('Y-m-d');
    $chartLabels[] = $cursor->format('M j');
    $chartRevenue[] = $weeklyRevenue[$d] ?? 0;
    $cursor->modify('+1 day');
}

$statusCounts = [];
$status_q = mysqli_query($conn, "
    SELECT status, COUNT(*) AS total
    FROM orders
    GROUP BY status
");
if ($status_q) {
    while ($r = mysqli_fetch_assoc($status_q)) {
        $statusCounts[$r['status']] = (int)$r['total'];
    }
}


/* Recent queue */
$recent = mysqli_query($conn, "
    SELECT orders.id, orders.status, orders.created_at, users.first_name, users.last_name
    FROM orders
    LEFT JOIN users ON orders.user_id = users.id
    ORDER BY orders.id DESC
    LIMIT 5
");

function statusBadge($status) {
    $status = trim((string)$status);
    $class = 'pending';

    switch (strtolower($status)) {
        case 'completed':
        case 'ready':
            $class = 'success';
            break;
        case 'processing':
            $class = 'processing';
            break;
        case 'cancelled':
            $class = 'danger';
            break;
    }

    return "<span class='status {$class}'>" . htmlspecialchars($status ?: 'Pending') . "</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cashier Dashboard - BLACKHABIT</title>
<link rel="icon" type="image/x-icon" href="favicon_io/favicon.ico">
<link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

<style>
:root{
    --bh-bg:#0b0b0b;
    --bh-panel:#151515;
    --bh-panel-2:#1a1a1a;
    --bh-border:#292929;
    --bh-gold:#cda45e;
    --bh-gold-light:#e6bd75;
    --bh-text:#f7f7f7;
    --bh-muted:#9c9c9c;
    --bh-green:#24c46a;
    --bh-orange:#ff9d22;
    --bh-blue:#2492ff;
    --bh-red:#ef5350;
}

*{box-sizing:border-box}
body{
    margin:0;
    background:
        radial-gradient(circle at 80% 0%, rgba(205,164,94,.08), transparent 28%),
        var(--bh-bg);
    color:var(--bh-text);
    font-family:'Poppins',sans-serif;
}
.main{
    min-height:100vh;
    padding:0 34px 40px;
    background:linear-gradient(90deg,rgba(255,255,255,.012),transparent 45%);
}
.topbar{
    height:78px;
    display:flex;
    align-items:center;
    gap:22px;
    border-bottom:1px solid #222;
}
.topbar h1{font-size:25px;margin:0;font-weight:700}
.menu-toggle{background:none;border:0;color:#fff;font-size:21px;cursor:pointer}
.profile{margin-left:auto;display:flex;align-items:center;gap:11px}
.profile i{font-size:28px;color:var(--bh-gold)}
.profile h3{font-size:14px;margin:0}
.profile span{font-size:11px;color:var(--bh-muted)}

.dashboard-head{
    display:flex;
    justify-content:space-between;
    align-items:flex-end;
    padding:28px 0 20px;
}
.dashboard-head h2{margin:0;font-size:30px}
.dashboard-head h2 span{color:var(--bh-gold)}
.dashboard-head p{margin:5px 0 0;color:var(--bh-muted);font-size:13px}
.brand-quote{text-align:right;color:var(--bh-gold-light);font-size:20px;font-weight:600;font-style:italic}

.cards{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:15px;
    margin-bottom:16px;
}
.card{
    background:linear-gradient(145deg,#181818,#111);
    border:1px solid var(--bh-border);
    border-radius:15px;
    min-height:112px;
    padding:18px;
    display:flex;
    align-items:center;
    gap:15px;
    box-shadow:0 8px 25px rgba(0,0,0,.18);
}
.card .icon{
    width:52px;height:52px;border-radius:13px;
    display:grid;place-items:center;
    background:rgba(205,164,94,.12);
    color:var(--bh-gold-light);
    font-size:21px;
    border:1px solid rgba(205,164,94,.16);
}
.card h3{margin:0 0 5px;color:#aaa;font-size:11px;text-transform:uppercase;letter-spacing:.4px}
.card h1{margin:0;font-size:25px;line-height:1.1}
.card.revenue-gold{border-left:3px solid var(--bh-gold)}
.card.revenue-green{border-left:3px solid var(--bh-green)}
.card.revenue-blue{border-left:3px solid var(--bh-blue)}

.content-grid{
    display:grid;
    grid-template-columns:minmax(0,1.55fr) minmax(300px,.85fr);
    gap:16px;
}
.panel{
    background:linear-gradient(145deg,#171717,#111);
    border:1px solid var(--bh-border);
    border-radius:15px;
    padding:19px;
    box-shadow:0 8px 25px rgba(0,0,0,.16);
}
.panel-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:15px;
}
.panel-header h2{font-size:16px;margin:0}
.panel-header h2 i{color:var(--bh-gold);margin-right:8px}
.panel-header a{color:var(--bh-gold-light);font-size:12px;text-decoration:none}

.quick-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:11px;
}
.quick-btn{
    min-height:92px;
    padding:16px 10px;
    border:1px solid #303030;
    border-radius:12px;
    background:#202020;
    color:#fff;
    text-decoration:none;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:8px;
    transition:.2s;
}
.quick-btn i{font-size:21px;color:var(--bh-gold-light)}
.quick-btn span{font-size:12px;font-weight:600}
.quick-btn.primary{background:linear-gradient(145deg,#dcb36d,#bd8f4a);color:#111;border-color:#e4bf7d}
.quick-btn.primary i{color:#111}
.quick-btn:hover{transform:translateY(-2px);border-color:var(--bh-gold)}

.queue-table{width:100%;border-collapse:collapse}
.queue-table th{
    text-align:left;padding:11px 9px;
    background:#202020;color:#aaa;
    font-size:10px;text-transform:uppercase;
}
.queue-table td{
    padding:13px 9px;
    border-bottom:1px solid #262626;
    font-size:12px;
}
.queue-table tr:last-child td{border-bottom:0}
.queue-table td:first-child{font-weight:600}

.status{
    display:inline-flex;
    align-items:center;
    padding:5px 10px;
    border-radius:999px;
    font-size:10px;
    font-weight:700;
    white-space:nowrap;
}
.status.pending{background:rgba(255,157,34,.15);color:#ffad3d}
.status.processing{background:rgba(205,164,94,.16);color:#e4bb70}
.status.success{background:rgba(36,196,106,.14);color:#35d978}
.status.danger{background:rgba(239,83,80,.14);color:#ff6b68}

.side-stack{display:grid;gap:16px}
.info-card{
    min-height:118px;
    display:flex;
    flex-direction:column;
    justify-content:center;
}
.info-card .big-icon{
    width:42px;height:42px;border-radius:12px;
    display:grid;place-items:center;
    background:rgba(205,164,94,.12);
    color:var(--bh-gold);
    margin-bottom:10px;
}
.info-card h3{margin:0;font-size:14px}
.info-card p{margin:4px 0 0;color:var(--bh-muted);font-size:11px;line-height:1.6}

.revenue-row{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:15px;
    margin-bottom:16px;
}
.revenue-row .card{min-height:100px}


.chart-grid{
    display:grid;
    grid-template-columns:minmax(0,1.55fr) minmax(280px,.85fr);
    gap:16px;
    margin-bottom:16px;
}
.chart-box{
    height:300px;
    position:relative;
}

.sales-filter-form{
    display:flex;
    align-items:center;
    gap:8px;
}
.custom-dates{
    display:flex;
    align-items:center;
    gap:6px;
}
.custom-dates input{
    width:130px;
    padding:7px 8px;
    border:1px solid #343434;
    border-radius:8px;
    background:#202020;
    color:#eee;
    font-family:inherit;
    font-size:10px;
}
.custom-dates span{color:#888;font-size:10px}
.custom-dates button{
    border:1px solid var(--bh-gold);
    background:var(--bh-gold);
    color:#111;
    border-radius:8px;
    padding:7px 10px;
    font-family:inherit;
    font-size:10px;
    font-weight:700;
    cursor:pointer;
}

.chart-filter{
    border:1px solid #343434;
    background:#202020;
    color:#ddd;
    border-radius:8px;
    padding:7px 10px;
    font-family:inherit;
    font-size:11px;
}
@media(max-width:1100px){
    .chart-grid{grid-template-columns:1fr}
}
@media(max-width:1100px){
    .content-grid{grid-template-columns:1fr}
}
@media(max-width:800px){
    .main{padding:0 16px 30px}
    .cards,.revenue-row{grid-template-columns:1fr}
    .dashboard-head{align-items:flex-start}
    .brand-quote{display:none}
    .quick-grid{grid-template-columns:1fr 1fr}
    .queue-table{min-width:600px}
    .table-scroll{overflow-x:auto}
}

/* =========================================================
   TABLET / iPAD LANDSCAPE RESPONSIVE OVERRIDES
   Keeps desktop layout intact and prevents horizontal overflow.
   ========================================================= */

/* Prevent accidental horizontal scrolling */
html,
body {
    max-width: 100%;
    overflow-x: hidden;
}

.main {
    width: 100%;
    min-width: 0;
}

/* iPad / tablet landscape */
@media screen and (min-width: 768px) and (max-width: 1366px) and (orientation: landscape) {

    .main {
        padding: 0 22px 30px;
    }

    .topbar {
        height: 68px;
        gap: 14px;
    }

    .topbar h1 {
        font-size: 21px;
    }

    .profile {
        gap: 8px;
    }

    .profile i {
        font-size: 25px;
    }

    .profile h3 {
        font-size: 12px;
    }

    .profile span {
        font-size: 10px;
    }

    .dashboard-head {
        padding: 22px 0 16px;
    }

    .dashboard-head h2 {
        font-size: 25px;
    }

    .dashboard-head p {
        font-size: 11px;
    }

    .brand-quote {
        font-size: 16px;
    }

    /* Three metric cards remain in one landscape row */
    .cards,
    .revenue-row {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 11px;
        margin-bottom: 12px;
    }

    .card {
        min-width: 0;
        min-height: 94px;
        padding: 13px;
        gap: 10px;
        border-radius: 13px;
    }

    .card .icon {
        width: 43px;
        height: 43px;
        min-width: 43px;
        border-radius: 11px;
        font-size: 17px;
    }

    .card h3 {
        font-size: 9px;
        margin-bottom: 4px;
        white-space: nowrap;
    }

    .card h1 {
        font-size: 21px;
        white-space: nowrap;
    }

    /* Keep charts side-by-side on normal tablet landscape widths */
    .chart-grid {
        grid-template-columns: minmax(0, 1.35fr) minmax(250px, .85fr);
        gap: 12px;
        margin-bottom: 12px;
    }

    .panel {
        min-width: 0;
        padding: 14px;
        border-radius: 13px;
    }

    .panel-header {
        gap: 8px;
        margin-bottom: 11px;
    }

    .panel-header h2 {
        font-size: 13px;
        white-space: nowrap;
    }

    .panel-header h2 i {
        margin-right: 6px;
    }

    .panel-header a {
        font-size: 10px;
        white-space: nowrap;
    }

    .chart-box {
        height: 245px;
        min-width: 0;
    }

    .chart-filter {
        max-width: 135px;
        padding: 6px 8px;
        font-size: 10px;
    }

    .sales-filter-form {
        min-width: 0;
        justify-content: flex-end;
    }

    .custom-dates {
        flex-wrap: nowrap;
    }

    .custom-dates input {
        width: 105px;
        padding: 6px;
        font-size: 9px;
    }

    .custom-dates span {
        font-size: 9px;
    }

    .custom-dates button {
        padding: 6px 8px;
        font-size: 9px;
    }

    /* Lower dashboard stays in two columns */
    .content-grid {
        grid-template-columns: minmax(0, 1.35fr) minmax(230px, .75fr);
        gap: 12px;
    }

    .side-stack {
        gap: 12px;
    }

    .info-card {
        min-height: 105px;
    }

    .info-card .big-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        margin-bottom: 7px;
    }

    .info-card h3 {
        font-size: 12px;
    }

    .info-card p {
        font-size: 10px;
        line-height: 1.45;
    }

    .quick-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 8px;
    }

    .quick-btn {
        min-height: 78px;
        padding: 10px 6px;
        gap: 6px;
        border-radius: 10px;
    }

    .quick-btn i {
        font-size: 17px;
    }

    .quick-btn span {
        font-size: 10px;
        text-align: center;
    }

    .queue-table th {
        padding: 9px 7px;
        font-size: 9px;
    }

    .queue-table td {
        padding: 10px 7px;
        font-size: 10px;
    }

    .status {
        padding: 4px 8px;
        font-size: 9px;
    }
}

/* Compact tablet landscape:
   e.g. smaller Android tablets / older small iPads */
@media screen and (min-width: 768px) and (max-width: 899px) and (orientation: landscape) {

    .main {
        padding-left: 16px;
        padding-right: 16px;
    }

    .dashboard-head h2 {
        font-size: 22px;
    }

    .dashboard-head p {
        font-size: 10px;
    }

    .brand-quote {
        font-size: 14px;
    }

    .cards,
    .revenue-row {
        gap: 8px;
    }

    .card {
        padding: 10px;
        gap: 8px;
        min-height: 86px;
    }

    .card .icon {
        width: 38px;
        height: 38px;
        min-width: 38px;
        font-size: 15px;
    }

    .card h3 {
        font-size: 8px;
    }

    .card h1 {
        font-size: 18px;
    }

    /* At this narrow landscape width, stacking the chart panels
       prevents squeezed charts while keeping the page landscape. */
    .chart-grid {
        grid-template-columns: 1fr;
    }

    .chart-box {
        height: 220px;
    }

    .content-grid {
        grid-template-columns: 1fr;
    }

    .side-stack {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .info-card {
        min-height: 110px;
    }

    .panel-header {
        flex-wrap: wrap;
    }
}

/* Medium tablet landscape:
   protects the filter/header from becoming crowded. */
@media screen and (min-width: 900px) and (max-width: 1100px) and (orientation: landscape) {

    .chart-grid {
        grid-template-columns: minmax(0, 1.3fr) minmax(250px, .8fr);
    }

    .content-grid {
        grid-template-columns: minmax(0, 1.3fr) minmax(225px, .75fr);
    }

    .panel-header {
        flex-wrap: wrap;
    }

    .sales-filter-form {
        margin-left: auto;
    }
}

/* Extra-wide tablet / iPad Pro landscape */
@media screen and (min-width: 1101px) and (max-width: 1366px) and (orientation: landscape) {

    .main {
        padding-left: 26px;
        padding-right: 26px;
    }

    .cards,
    .revenue-row {
        gap: 13px;
    }

    .card {
        padding: 15px;
    }

    .card h1 {
        font-size: 23px;
    }

    .chart-box {
        height: 275px;
    }
}

/* Touch-friendly tablet controls */
@media (hover: none) and (pointer: coarse) and (min-width: 768px) {

    .menu-toggle,
    .chart-filter,
    .custom-dates button {
        min-height: 38px;
    }

    .quick-btn {
        min-height: 76px;
    }

    .queue-table {
        -webkit-tap-highlight-color: transparent;
    }
}

</style>
</head>

<body>
<?php include "cashier_sidebar.php"; ?>

<div class="main">
    <div class="topbar">
        <button class="menu-toggle" id="menuToggle" aria-label="Menu">
            <i class="fa-solid fa-bars"></i>
        </button>
        <h1>Cashier Dashboard</h1>

        <div class="profile">
            <i class="fa-solid fa-circle-user"></i>
            <div>
                <h3><?php echo htmlspecialchars($_SESSION['name'] ?? 'Cashier Staff'); ?></h3>
                <span>Cashier Operator</span>
            </div>
        </div>
    </div>

    <div class="dashboard-head">
        <div>
            <h2>Welcome, <span><?php echo htmlspecialchars($_SESSION['name'] ?? 'Cashier'); ?></span> 👋</h2>
            <p>Here's an overview of today's orders, sales and customer queue.</p>
        </div>
        <div class="brand-quote">Good Coffee<br>Better Days</div>
    </div>

    <!-- ORDER METRICS -->
    <div class="cards">
        <div class="card">
            <div class="icon"><i class="fa-solid fa-cart-shopping"></i></div>
            <div><h3>Today's Orders</h3><h1><?php echo $totalOrders; ?></h1></div>
        </div>
        <div class="card">
            <div class="icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div><h3>Pending Orders</h3><h1><?php echo $totalPending; ?></h1></div>
        </div>
        <div class="card">
            <div class="icon"><i class="fa-solid fa-circle-check"></i></div>
            <div><h3>Completed Orders</h3><h1><?php echo $totalCompleted; ?></h1></div>
        </div>
    </div>

    <!-- REVENUE METRICS -->
    <div class="revenue-row">
        <div class="card revenue-gold">
            <div class="icon"><i class="fa-solid fa-peso-sign"></i></div>
            <div><h3>Today's Revenue</h3><h1>₱<?php echo number_format($totalSales,2); ?></h1></div>
        </div>
        <div class="card revenue-green">
            <div class="icon"><i class="fa-solid fa-calendar-days"></i></div>
            <div><h3>Monthly Revenue</h3><h1>₱<?php echo number_format($monthSales,2); ?></h1></div>
        </div>
        <div class="card revenue-blue">
            <div class="icon"><i class="fa-solid fa-chart-line"></i></div>
            <div><h3>Yearly Revenue</h3><h1>₱<?php echo number_format($yearSales,2); ?></h1></div>
        </div>
    </div>


    <!-- SALES GRAPH + ORDER STATUS PIE -->
    <div class="chart-grid">
        <div class="panel">
            <div class="panel-header">
                <h2><i class="fa-solid fa-chart-line"></i> Sales Overview</h2>
                <form method="GET" class="sales-filter-form" id="salesFilterForm">
                    <select class="chart-filter" name="sales_period" id="salesPeriod" aria-label="Sales period">
                        <?php foreach ($periodLabels as $value => $label): ?>
                            <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $salesPeriod === $value ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div id="customDates" class="custom-dates" style="<?php echo $salesPeriod === 'custom' ? '' : 'display:none;'; ?>">
                        <input type="date" name="from" value="<?php echo htmlspecialchars($_GET['from'] ?? ''); ?>" aria-label="From date">
                        <span>to</span>
                        <input type="date" name="to" value="<?php echo htmlspecialchars($_GET['to'] ?? ''); ?>" aria-label="To date">
                        <button type="submit">Apply</button>
                    </div>
                </form>
            </div>
            <div class="chart-box">
                <canvas id="salesChart"></canvas>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <h2><i class="fa-solid fa-chart-pie"></i> Order Status</h2>
            </div>
            <div class="chart-box">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
    </div>

    <div class="content-grid">
        <div>
            <!-- QUICK ACTIONS -->
            <div class="panel" style="margin-bottom:16px">
                <div class="panel-header">
                    <h2><i class="fa-solid fa-bolt"></i> Quick Actions</h2>
                </div>
                <div class="quick-grid">
                    <a href="cashier_pos.php" class="quick-btn primary">
                        <i class="fa-solid fa-cash-register"></i><span>POS Terminal</span>
                    </a>
                    <a href="cashier_order_management.php" class="quick-btn">
                        <i class="fa-solid fa-basket-shopping"></i><span>View Orders</span>
                    </a>
                    <a href="inventory_management.php" class="quick-btn">
                        <i class="fa-solid fa-warehouse"></i><span>Inventory</span>
                    </a>
                </div>
            </div>

            <!-- CUSTOMER QUEUE -->
            <div class="panel">
                <div class="panel-header">
                    <h2><i class="fa-solid fa-users"></i> Recent Customer Queue</h2>
                    <a href="cashier_order_management.php">View All Orders →</a>
                </div>

                <div class="table-scroll">
                    <table class="queue-table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Order #</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($recent && mysqli_num_rows($recent) > 0): ?>
                            <?php while($row = mysqli_fetch_assoc($recent)):
                                $displayName = 'Walk-in Customer / Guest';
                                if (!empty($row['first_name'])) {
                                    $displayName = trim($row['first_name'].' '.($row['last_name'] ?? ''));
                                }
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($displayName); ?></td>
                                <td>#BH-<?php echo str_pad((int)$row['id'], 5, '0', STR_PAD_LEFT); ?></td>
                                <td><?php echo statusBadge($row['status']); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="3" style="text-align:center;color:#888;padding:25px">No recent orders.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="side-stack">
            <div class="panel info-card">
                <div class="big-icon"><i class="fa-solid fa-mug-hot"></i></div>
                <h3>BlackHabit Service</h3>
                <p>Keep the counter moving and make every customer experience better.</p>
            </div>

            <div class="panel info-card">
                <div class="big-icon"><i class="fa-solid fa-chart-column"></i></div>
                <h3>Revenue Snapshot</h3>
                <p>Today: <strong style="color:#fff">₱<?php echo number_format($totalSales,2); ?></strong><br>
                This month: <strong style="color:#fff">₱<?php echo number_format($monthSales,2); ?></strong></p>
            </div>

            <div class="panel info-card">
                <div class="big-icon"><i class="fa-solid fa-circle-info"></i></div>
                <h3>Cashier Tools</h3>
                <p>Use POS Terminal for new transactions and View Orders for queue management.</p>
            </div>
        </div>
    </div>
</div>

<script>
const menuToggle = document.getElementById('menuToggle');
if (menuToggle) {
    menuToggle.addEventListener('click', () => {
        document.body.classList.toggle('sidebar-open');
    });
}
</script>

<script>
const salesPeriod = document.getElementById('salesPeriod');
const customDates = document.getElementById('customDates');

if (salesPeriod) {
    salesPeriod.addEventListener('change', function () {
        if (this.value === 'custom') {
            customDates.style.display = 'flex';
        } else {
            customDates.style.display = 'none';
            document.getElementById('salesFilterForm').submit();
        }
    });
}

const salesLabels = <?php echo json_encode($chartLabels); ?>;
const salesValues = <?php echo json_encode($chartRevenue); ?>;
const statusLabels = <?php echo json_encode(array_keys($statusCounts)); ?>;
const statusValues = <?php echo json_encode(array_values($statusCounts)); ?>;

const chartFont = {
    family: 'Poppins, sans-serif'
};

if (typeof Chart === 'undefined') {
    console.error('BLACKHABIT: Chart.js failed to load.');
    document.querySelectorAll('.chart-box').forEach(box => {
        box.innerHTML = '<div style="height:100%;display:grid;place-items:center;color:#888;font-size:12px;">Unable to load chart library. Please check the internet connection.</div>';
    });
}

const salesCanvas = document.getElementById('salesChart');
if (salesCanvas) {
    new Chart(salesCanvas, {
        type: 'line',
        data: {
            labels: salesLabels,
            datasets: [{
                label: 'Revenue',
                data: salesValues,
                borderColor: '#d4aa61',
                backgroundColor: 'rgba(212,170,97,.14)',
                fill: true,
                tension: .38,
                pointRadius: 4,
                pointHoverRadius: 6,
                pointBackgroundColor: '#d4aa61',
                pointBorderColor: '#111'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ₱' + Number(ctx.raw).toLocaleString('en-PH', {
                            minimumFractionDigits: 2
                        })
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255,255,255,.05)' },
                    ticks: { color: '#999', font: chartFont }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(255,255,255,.06)' },
                    ticks: {
                        color: '#999',
                        font: chartFont,
                        callback: value => '₱' + Number(value).toLocaleString('en-PH')
                    }
                }
            }
        }
    });
}

const statusCanvas = document.getElementById('statusChart');
if (statusCanvas) {
    new Chart(statusCanvas, {
        type: 'doughnut',
        data: {
            labels: statusLabels,
            datasets: [{
                data: statusValues,
                backgroundColor: ['#ff9d22','#2492ff','#24c46a','#cda45e','#ef5350','#8d6e63'],
                borderColor: '#151515',
                borderWidth: 3,
                hoverOffset: 7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: '#ddd',
                        padding: 14,
                        usePointStyle: true,
                        font: { ...chartFont, size: 10 }
                    }
                }
            }
        }
    });
}
</script>

</body>
</html>

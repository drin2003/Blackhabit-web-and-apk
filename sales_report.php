<?php
// BLACKHABIT: Admin uses its own independent session.
session_name('BH_ADMIN_SESSION');
session_start();

if (
    !isset($_SESSION['role']) ||
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true
) {
    header("Location: index.php");
    exit();
}

$role = strtolower(trim((string)$_SESSION['role']));
if ($role !== 'admin' && (int)$_SESSION['role'] !== 1) {
    header("Location: index.php");
    exit();
}

include "db.php";

/* ============================================================
   DATE FILTER
============================================================ */

$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate   = trim((string)($_GET['to_date'] ?? ''));

$validFrom = preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $fromDate);
$validTo   = preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $toDate);

if (!$validFrom) $fromDate = '';
if (!$validTo)   $toDate = '';

$hasDateFilter = ($fromDate !== '' && $toDate !== '');

if ($hasDateFilter && $fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$where = " WHERE o.status='Completed' ";

if ($hasDateFilter) {
    $safeFrom = mysqli_real_escape_string($conn, $fromDate);
    $safeTo   = mysqli_real_escape_string($conn, $toDate);
    $where .= " AND DATE(o.created_at) BETWEEN '$safeFrom' AND '$safeTo'";
}

/* ============================================================
   FILTERED SALES SUMMARY
============================================================ */

$summary = mysqli_fetch_assoc(mysqli_query($conn, "
SELECT
    COUNT(DISTINCT o.id) AS total_orders,
    IFNULL(SUM(o.total), 0) AS total_sales,
    IFNULL(SUM(oi.quantity), 0) AS units_sold,
    COUNT(DISTINCT oi.product_id) AS products_sold
FROM orders o
LEFT JOIN order_items oi ON oi.order_id = o.id
$where
"));

if (!$summary) {
    $summary = [
        'total_orders' => 0,
        'total_sales' => 0,
        'units_sold' => 0,
        'products_sold' => 0
    ];
}

/* ============================================================
   BEST SELLING PRODUCTS — RESPECTS DATE FILTER
============================================================ */

$bestProducts = mysqli_query($conn, "
SELECT
    p.id,
    p.name,
    p.category,
    SUM(oi.quantity) AS total_qty,
    SUM(oi.quantity * oi.price) AS revenue
FROM order_items oi
INNER JOIN orders o ON oi.order_id = o.id
INNER JOIN products p ON oi.product_id = p.id
$where
GROUP BY p.id, p.name, p.category
ORDER BY total_qty DESC
LIMIT 10
");

/* ============================================================
   PRODUCTS SOLD — RESPECTS DATE FILTER
============================================================ */

$productPerformance = mysqli_query($conn, "
SELECT
    p.id,
    p.name,
    p.category,
    SUM(oi.quantity) AS total_qty,
    SUM(oi.quantity * oi.price) AS revenue
FROM order_items oi
INNER JOIN orders o ON oi.order_id = o.id
INNER JOIN products p ON oi.product_id = p.id
$where
GROUP BY p.id, p.name, p.category
ORDER BY total_qty DESC, p.name ASC
");



/* ============================================================
   DAILY SALES HISTORY — RESPECTS DATE FILTER
============================================================ */

$dailySales = mysqli_query($conn, "
SELECT
    DATE(o.created_at) AS order_date,
    COUNT(DISTINCT o.id) AS total_orders,
    SUM(o.total) AS revenue
FROM orders o
$where
GROUP BY DATE(o.created_at)
ORDER BY DATE(o.created_at) DESC
");


$chartSales = mysqli_query($conn, "
SELECT DATE(o.created_at) AS order_date, SUM(o.total) AS revenue
FROM orders o
$where
GROUP BY DATE(o.created_at)
ORDER BY DATE(o.created_at) ASC
");

$chartLabels = [];
$chartValues = [];
if ($chartSales) {
    while ($r = mysqli_fetch_assoc($chartSales)) {
        $chartLabels[] = date('M d, Y', strtotime($r['order_date']));
        $chartValues[] = (float)$r['revenue'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
content="width=device-width, initial-scale=1.0">

<title>Sales Report</title>
 <link rel="icon" type="image/x-icon" href="favicon_io/favicon.ico">
<link rel="stylesheet"
href="style.css?v=<?php echo time();?>">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">


<style>
/* ============================================================
   BLACKHABIT SALES REPORT — MODERN DARK UI
============================================================ */
:root{
    --bh-gold:#cfa45f;
    --bh-gold-light:#e2b96e;
    --bh-bg:#0d0d0d;
    --bh-panel:#151515;
    --bh-panel-2:#1a1a1a;
    --bh-border:#292929;
    --bh-text:#f5f5f5;
    --bh-muted:#969696;
    --bh-green:#20c878;
    --bh-blue:#2498ff;
    --bh-purple:#a855f7;
}

.main{
    background:
        radial-gradient(circle at 80% 0%, rgba(207,164,95,.07), transparent 28%),
        #0d0d0d !important;
    min-height:100vh;
    padding-bottom:50px;
}

.topbar{
    border-bottom:1px solid #242424 !important;
    margin-bottom:24px !important;
    padding-bottom:20px !important;
}

.topbar h1{
    font-size:30px !important;
    font-weight:800 !important;
    letter-spacing:-.6px;
}

/* Main analytics panel */
.sales-analytics{
    background:linear-gradient(145deg,#171717,#121212) !important;
    border:1px solid #292929 !important;
    border-radius:16px !important;
    padding:24px !important;
    box-shadow:0 15px 40px rgba(0,0,0,.20);
}

.sales-analytics .panel-header{
    border-bottom:1px solid #292929 !important;
    margin-bottom:18px !important;
    padding-bottom:18px !important;
}

.sales-analytics .panel-header h2{
    font-size:19px !important;
}

.sales-analytics .panel-header h2 i{
    color:var(--bh-gold);
    margin-right:8px;
}

.analytics-subtitle{
    color:var(--bh-muted);
    font-size:13px;
    margin:5px 0 0 29px;
}

/* Filter */
.filter-form{
    display:grid !important;
    grid-template-columns:1fr 1fr auto auto !important;
    gap:14px !important;
    align-items:end !important;
    margin:0 !important;
    padding:0 !important;
}

.filter-field{
    display:flex;
    flex-direction:column;
    gap:7px;
}

.filter-field label{
    color:#c9c9c9;
    font-size:12px;
    font-weight:700;
}

.filter-form input{
    width:100% !important;
    box-sizing:border-box;
    padding:12px 14px !important;
    min-height:44px;
    background:#101010 !important;
    color:#f3f3f3 !important;
    border:1px solid #363636 !important;
    border-radius:9px !important;
    outline:none;
}

.filter-form input:focus{
    border-color:var(--bh-gold) !important;
    box-shadow:0 0 0 3px rgba(207,164,95,.10);
}

.btn{
    min-height:44px;
    border:none !important;
    border-radius:9px !important;
    padding:0 18px !important;
    background:var(--bh-gold) !important;
    color:#111 !important;
    font-weight:800 !important;
    cursor:pointer;
    transition:.2s ease;
}

.btn:hover{
    background:var(--bh-gold-light) !important;
    transform:translateY(-1px);
}

.btn-reset{
    display:flex;
    align-items:center;
    justify-content:center;
    background:#2b2b2b !important;
    color:#eee !important;
    text-decoration:none !important;
}

/* KPI cards */
.cards{
    display:grid !important;
    grid-template-columns:repeat(4,minmax(0,1fr)) !important;
    gap:16px !important;
    margin:18px 0 18px !important;
}

.cards .card{
    position:relative;
    min-height:110px;
    box-sizing:border-box;
    display:flex;
    align-items:center;
    padding:20px !important;
    background:linear-gradient(145deg,#171717,#111) !important;
    border:1px solid #2a2a2a !important;
    border-radius:15px !important;
    overflow:hidden;
}

.cards .card:nth-child(1){border-left:3px solid var(--bh-gold) !important;}
.cards .card:nth-child(2){border-left:3px solid var(--bh-green) !important;}
.cards .card:nth-child(3){border-left:3px solid var(--bh-blue) !important;}
.cards .card:nth-child(4){border-left:3px solid var(--bh-purple) !important;}

.cards .card::before{
    content:"";
    width:46px;
    height:46px;
    border-radius:12px;
    margin-right:15px;
    flex:none;
    background:rgba(207,164,95,.12);
    border:1px solid rgba(207,164,95,.16);
}

.cards .card:nth-child(2)::before{background:rgba(32,200,120,.10);}
.cards .card:nth-child(3)::before{background:rgba(36,152,255,.10);}
.cards .card:nth-child(4)::before{background:rgba(168,85,247,.10);}

.cards .card h3{
    color:#a8a8a8 !important;
    font-size:11px !important;
    text-transform:uppercase;
    letter-spacing:.4px;
    margin:0 0 6px !important;
}

.cards .card h1{
    color:#f7f7f7 !important;
    font-size:25px !important;
    margin:0 0 3px !important;
    font-weight:800;
}

.cards .card p{
    color:#a2a2a2 !important;
    font-size:12px;
    margin:0 !important;
}

/* Graph */
.chart-container{
    position:relative;
    background:linear-gradient(145deg,#171717,#111) !important;
    border:1px solid #292929 !important;
    border-radius:16px !important;
    padding:24px !important;
    margin:0 0 22px !important;
    height:330px !important;
    display:block !important;
    box-sizing:border-box;
}

.chart-container::before{
    content:"Revenue Overview";
    position:absolute;
    top:22px;
    left:24px;
    color:#f1f1f1;
    font-size:18px;
    font-weight:800;
}

.chart-container canvas{
    margin-top:25px;
}

/* Panels and tables */
.panel{
    background:linear-gradient(145deg,#171717,#121212) !important;
    border:1px solid #292929 !important;
    border-radius:16px !important;
    box-shadow:0 12px 35px rgba(0,0,0,.15);
}

.panel-header{
    border-bottom:1px solid #292929 !important;
}

.panel-header h2{
    color:#f3f3f3 !important;
    font-size:18px !important;
}

.panel-header h2 i{
    color:var(--bh-gold);
}

.table-container{
    overflow-x:auto;
}

.product-table{
    width:100%;
    border-collapse:collapse;
    color:#eee;
}

.product-table thead th{
    background:#202020 !important;
    color:var(--bh-gold) !important;
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.4px;
    padding:14px 15px !important;
    border-bottom:1px solid #2b2b2b;
}

.product-table tbody td{
    padding:14px 15px !important;
    border-bottom:1px solid #292929 !important;
    color:#ededed;
    font-size:13px;
}

.product-table tbody tr:hover{
    background:#1d1d1d;
}

.product-table tbody tr:last-child td{
    border-bottom:none !important;
}

.cards .card .kpi-icon{
    position:absolute;
    left:18px;
    top:20px;
    width:44px;
    height:44px;
    border-radius:12px;
    display:flex;
    align-items:center;
    justify-content:center;
    color:var(--bh-gold);
    background:rgba(207,164,95,.12);
    font-size:18px;
}
.cards .card:nth-child(2) .kpi-icon{color:var(--bh-green);background:rgba(32,200,120,.10);}
.cards .card:nth-child(3) .kpi-icon{color:var(--bh-blue);background:rgba(36,152,255,.10);}
.cards .card:nth-child(4) .kpi-icon{color:var(--bh-purple);background:rgba(168,85,247,.10);}
.cards .card > div:not(.kpi-icon){margin-left:60px;}

/* Graph button */
.graph-toggle{
    margin-left:auto;
}

/* Responsive */
@media (max-width:1100px){
    .cards{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    }

    .filter-form{
        grid-template-columns:1fr 1fr !important;
    }

    .filter-form .btn,
    .filter-form .btn-reset{
        width:100%;
    }
}

@media (max-width:700px){
    .cards{
        grid-template-columns:1fr !important;
    }

    .filter-form{
        grid-template-columns:1fr !important;
    }

    .sales-analytics{
        padding:16px !important;
    }

    .chart-container{
        height:280px !important;
        padding:15px !important;
    }
}


.filter-note{
    margin-top:12px;
    color:var(--bh-muted);
    font-size:12px;
}


/* Sales graph toggle */
.graph-toggle-btn{
    margin-left:auto;
    min-width:110px;
    height:36px;
    padding:0 16px;
    border:1px solid #cda45e;
    border-radius:8px;
    background:#cda45e;
    color:#111;
    font-family:Poppins,sans-serif;
    font-size:11px;
    font-weight:700;
    cursor:pointer;
}
.graph-toggle-btn:hover{
    background:#e0b66f;
}
.sales-graph-container{
    margin-top:18px;
    padding:18px;
    background:#151515;
    border:1px solid #292929;
    border-radius:12px;
    min-height:300px;
}
.sales-graph-container canvas{
    width:100% !important;
    max-height:360px;
}


.analytics-header-right{margin-left:auto;display:flex;align-items:center;gap:18px;}
.analytics-header-right .analytics-subtitle{margin:0;}
.graph-toggle-btn{height:36px;padding:0 18px;border:1px solid #c59d5f;border-radius:8px;background:#c59d5f;color:#111;font-family:Poppins,sans-serif;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;}
.graph-toggle-btn:hover{background:#d9ae6d;}
.sales-graph-container{margin-top:22px;padding:20px;background:#151515;border:1px solid #292929;border-radius:14px;}
.graph-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;padding-bottom:12px;border-bottom:1px solid #292929;}
.graph-title h3{margin:0;color:#fff;font-size:16px;}
.graph-title h3 i{color:#c59d5f;margin-right:8px;}
.graph-title span{color:#888;font-size:11px;}
.graph-canvas-wrap{height:340px;position:relative;}
@media(max-width:800px){.analytics-header-right{margin-left:0;flex-direction:column;align-items:flex-start;gap:10px}.graph-toggle-btn{width:100%;}.graph-canvas-wrap{height:280px;}}

</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

<h1>Sales Report Dashboard</h1>

</div>

<div class="panel sales-analytics">

<div class="panel-header">
<h2>
<i class="fa-solid fa-chart-column"></i>
Sales Analytics
</h2>
<div class="analytics-header-right">
<button type="button" class="graph-toggle-btn" id="viewGraphBtn">View Graph</button>
</div>
</div>

<form class="filter-form"
method="GET">

<div class="filter-field">
<label for="from_date">From</label>
<input
id="from_date"
type="date"
name="from_date"
value="<?= htmlspecialchars($_GET['from_date'] ?? '') ?>">
</div>

<div class="filter-field">
<label for="to_date">To</label>
<input
id="to_date"
type="date"
name="to_date"
value="<?= htmlspecialchars($_GET['to_date'] ?? '') ?>">
</div>

<button
class="btn">

Go

</button>

<a
href="sales_report.php"
class="btn btn-reset">

Reset

</a>

</form>

<div class="cards">

<div class="card">
<div class="kpi-icon"><i class="fa-solid fa-peso-sign"></i></div>
<div>
<h3><?= $hasDateFilter ? 'Filtered Sales' : 'Completed Sales' ?></h3>
<h1>₱<?= number_format((float)$summary['total_sales'], 2) ?></h1>
<p><?= number_format((int)$summary['total_orders']) ?> Orders</p>
</div>
</div>

<div class="card">
<div class="kpi-icon"><i class="fa-solid fa-cart-shopping"></i></div>
<div>
<h3>Orders</h3>
<h1><?= number_format((int)$summary['total_orders']) ?></h1>
<p>Completed orders</p>
</div>
</div>

<div class="card">
<div class="kpi-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
<div>
<h3>Products Sold</h3>
<h1><?= number_format((int)$summary['units_sold']) ?></h1>
<p>Total units sold</p>
</div>
</div>

<div class="card">
<div class="kpi-icon"><i class="fa-solid fa-tags"></i></div>
<div>
<h3>Product Types</h3>
<h1><?= number_format((int)$summary['products_sold']) ?></h1>
<p>Different products sold</p>
</div>
</div>

</div>

<div class="sales-graph-container" id="salesGraphContainer" style="display:none;">
    <div class="graph-title">
        <h3><i class="fa-solid fa-chart-line"></i> Sales Graph</h3>
        <span>Revenue by date</span>
    </div>
    <div class="graph-canvas-wrap">
        <canvas id="salesChart"></canvas>
    </div>
</div>

<!-- ================================
TOP 10 BEST SELLING PRODUCTS
================================ -->

<div class="panel" style="margin-top:25px;">

    <div class="panel-header">
        <h2>
            <i class="fa-solid fa-trophy"></i>
            Top 10 Best Selling Products
        </h2>
    </div>

    <div class="table-container">

        <table class="product-table">

            <thead>

                <tr>

                    <th>Rank</th>

                    <th>Product</th>

                    <th>Category</th>

                    <th>Quantity Sold</th>

                    <th>Total Revenue</th>

                </tr>

            </thead>

            <tbody>

            <?php

            $rank = 1;

            if(mysqli_num_rows($bestProducts)>0){

                while($product=mysqli_fetch_assoc($bestProducts)){

            ?>

                <tr>

                    <td><?= $rank++ ?></td>

                    <td><?= htmlspecialchars($product['name']) ?></td>

                    <td><?= htmlspecialchars($product['category']) ?></td>

                    <td><?= number_format($product['total_qty']) ?></td>

                    <td style="color:#c59d5f;font-weight:bold;">
                        ₱<?= number_format($product['revenue'],2) ?>
                    </td>

                </tr>

            <?php

                }

            }else{

            ?>

                <tr>

                    <td colspan="5">

                        No sales found.

                    </td>

                </tr>

            <?php } ?>

            </tbody>

        </table>

    </div>

</div>
<!-- =====================================
PRODUCT SALES FOR SELECTED DATE RANGE
===================================== -->

<div class="panel" style="margin-top:25px;">

    <div class="panel-header">
        <h2>
            <i class="fa-solid fa-box-open"></i>
            Products Sold
        </h2>
        <p class="analytics-subtitle">
            <?= $hasDateFilter
                ? 'Products sold from ' . htmlspecialchars(date('M d, Y', strtotime($fromDate))) . ' to ' . htmlspecialchars(date('M d, Y', strtotime($toDate))) . '.'
                : 'All completed product sales.' ?>
        </p>
    </div>

    <div class="table-container">

        <table class="product-table">

            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Quantity Sold</th>
                    <th>Total Revenue</th>
                </tr>
            </thead>

            <tbody>

            <?php
            $productRank = 1;

            if ($productPerformance && mysqli_num_rows($productPerformance) > 0) {
                while ($item = mysqli_fetch_assoc($productPerformance)) {
            ?>

                <tr>
                    <td><?= $productRank++ ?></td>
                    <td><?= htmlspecialchars($item['name']) ?></td>
                    <td><?= htmlspecialchars($item['category']) ?></td>
                    <td><?= number_format((int)$item['total_qty']) ?></td>
                    <td style="color:#cfa45f;font-weight:800;">
                        ₱<?= number_format((float)$item['revenue'], 2) ?>
                    </td>
                </tr>

            <?php
                }
            } else {
            ?>

                <tr>
                    <td colspan="5">No products sold for the selected date range.</td>
                </tr>

            <?php } ?>

            </tbody>

        </table>

    </div>

</div>

<!-- =====================================
DAILY SALES HISTORY
===================================== -->

<div class="panel" style="margin-top:25px;">

    <div class="panel-header">

        <h2>
            <i class="fa-solid fa-calendar-days"></i>
            Daily Sales History
        </h2>

    </div>

    <div class="table-container">

        <table class="product-table">

            <thead>

                <tr>

                    <th>Date</th>

                    <th>Total Orders</th>

                    <th>Total Revenue</th>

                </tr>

            </thead>

            <tbody>

            <?php

            if($dailySales && mysqli_num_rows($dailySales) > 0){

                while($row = mysqli_fetch_assoc($dailySales)){

            ?>

                <tr>

                    <td><?= htmlspecialchars($row['order_date']) ?></td>

                    <td><?= number_format($row['total_orders']) ?></td>

                    <td style="color:#c59d5f;font-weight:bold;">
                        ₱<?= number_format($row['revenue'],2) ?>
                    </td>

                </tr>

            <?php

                }

            }else{

            ?>

                <tr>

                    <td colspan="3">
                        No sales data found.
                    </td>

                </tr>

            <?php } ?>

            </tbody>

        </table>

    </div>

</div>



<script>
(function () {
    const btn = document.getElementById('viewGraphBtn');
    const graph = document.getElementById('salesGraphContainer');
    const canvas = document.getElementById('salesChart');
    if (!btn || !graph || !canvas) return;

    const labels = <?php echo json_encode($chartLabels, JSON_UNESCAPED_SLASHES); ?>;
    const values = <?php echo json_encode($chartValues, JSON_NUMERIC_CHECK); ?>;

    let chart = null;

    btn.addEventListener('click', function () {
        const show = graph.style.display === 'none' || graph.style.display === '';
        graph.style.display = show ? 'block' : 'none';
        btn.textContent = show ? 'Hide Graph' : 'View Graph';

        if (show && !chart) {
            chart = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Revenue (PHP)',
                        data: values,
                        borderWidth: 2,
                        tension: 0.35,
                        fill: true,
                        pointRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: value => '₱' + Number(value).toLocaleString()
                            }
                        }
                    }
                }
            });
        } else if (show && chart) {
            chart.resize();
        }
    });
})();
</script>

</body>
</html>

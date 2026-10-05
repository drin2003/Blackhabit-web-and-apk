<?php
// BLACKHABIT: Admin uses its own independent session.
session_name('BH_ADMIN_SESSION');
session_start();

if (!isset($_SESSION['role']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$role = strtolower(trim((string)$_SESSION['role']));
if ($role !== 'admin' && (int)$_SESSION['role'] !== 1) {
    header('Location: index.php');
    exit();
}

require_once 'db.php';

$status = trim($_GET['status'] ?? '');
$allowed = ['Pending', 'Processing', 'Ready', 'Completed'];
if (!in_array($status, $allowed, true)) {
    $status = '';
}

$from = trim($_GET['from_date'] ?? '');
$to   = trim($_GET['to_date'] ?? '');

if ($from !== '' && $to === '') $to = $from;
if ($to !== '' && $from === '') $from = $to;
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}

$where = ["orders.status <> 'Cancelled'"];

if ($status !== '') {
    $safeStatus = mysqli_real_escape_string($conn, $status);
    $where[] = "orders.status='{$safeStatus}'";
}

if ($from !== '') {
    $safeFrom = mysqli_real_escape_string($conn, $from);
    $where[] = "DATE(orders.created_at) >= '{$safeFrom}'";
}

if ($to !== '') {
    $safeTo = mysqli_real_escape_string($conn, $to);
    $where[] = "DATE(orders.created_at) <= '{$safeTo}'";
}

$sql = "SELECT orders.*, users.first_name, users.last_name
        FROM orders
        LEFT JOIN users ON users.id = orders.user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY orders.created_at DESC, orders.id DESC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die('Order query failed: ' . htmlspecialchars(mysqli_error($conn)));
}

function orderNumber($id, $created = '') {
    $year = $created ? date('Y', strtotime($created)) : date('Y');
    return 'BH-' . $year . '-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}

function customerName($r) {
    $n = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
    return $n !== '' ? $n : 'Walk-in Customer';
}

function statusClass($s) {
    return strtolower(str_replace(' ', '-', $s));
}

function statusUrl($status, $from, $to) {
    $params = [];

    if ($status !== '') {
        $params['status'] = $status;
    }

    if ($from !== '') {
        $params['from_date'] = $from;
    }

    if ($to !== '') {
        $params['to_date'] = $to;
    }

    return 'admin_order_management.php' . ($params ? '?' . http_build_query($params) : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Orders - BLACKHABIT</title>
<link rel="icon" href="favicon_io/favicon.ico">
<link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

<style>
/* ==========================================================
   ORDER MANAGEMENT - RESPONSIVE TABLE
   Desktop / iPad / Tablet / Phone
   ========================================================== */

.table-container{
    width:100%;
    max-width:100%;
    overflow-x:auto;
    overflow-y:hidden;
    -webkit-overflow-scrolling:touch;
    scrollbar-width:thin;
}

.order-filters{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin:15px 0;
}

.filter-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    padding:9px 13px;
    border:1px solid #333;
    border-radius:9px;
    color:#ddd;
    text-decoration:none;
    background:#181818;
    white-space:nowrap;
}

.filter-btn.active{
    background:#c59d5f;
    color:#111;
    border-color:#c59d5f;
}

.date-filter{
    display:flex;
    gap:10px;
    align-items:flex-end;
    flex-wrap:wrap;
    padding:15px;
    background:#161616;
    border:1px solid #2b2b2b;
    border-radius:10px;
}

.date-filter label{
    display:block;
    color:#aaa;
    font-size:12px;
    margin-bottom:5px;
}

.date-filter input{
    background:#101010;
    color:#fff;
    border:1px solid #333;
    border-radius:7px;
    padding:9px;
    max-width:100%;
}

.date-actions{
    display:flex;
    gap:8px;
    margin-left:auto;
}

.date-actions .btn{
    padding:9px 16px;
}

.status-badge{
    padding:5px 9px;
    border-radius:15px;
    font-size:11px;
    font-weight:700;
    display:inline-block;
    white-space:nowrap;
}

.pending{background:#fff0c2;color:#755800}
.processing{background:#dce9ff;color:#24518c}
.ready{background:#d8f3df;color:#196b35}
.completed{background:#d8f3df;color:#196b35}

.order-number{
    font-weight:700;
    color:#c59d5f;
    white-space:nowrap;
}

.total-link{
    color:#c59d5f;
    font-weight:700;
    text-decoration:none;
    white-space:nowrap;
}

.total-link:hover{
    text-decoration:underline;
}

.instruction{
    max-width:330px;
    min-width:220px;
    color:#ccc;
    font-size:12px;
    line-height:1.55;
}

.empty{
    padding:35px;
    text-align:center;
    color:#888;
}

.queue-note{
    font-size:12px;
    color:#888;
    margin:0 0 10px;
}

.action-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    min-width:72px;
    min-height:36px;
    padding:7px 10px;
    border-radius:7px;
    background:#252525;
    color:#fff;
    text-decoration:none;
    border:1px solid #3a3a3a;
    font-size:12px;
    margin:2px;
    white-space:nowrap;
}

.action-btn:hover{
    border-color:#c59d5f;
    color:#c59d5f;
}

/* ==========================================================
   TABLE
   Keep the order list as a REAL TABLE at every screen size.
   ========================================================== */

.order-management-table{
    width:100% !important;
    min-width:0 !important;
    border-collapse:collapse;
    table-layout:fixed !important;
}

.order-management-table th,
.order-management-table td{
    vertical-align:middle;
    box-sizing:border-box;
}

.order-management-table th{
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.order-management-table td{
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

/* Exact widths for the 9 columns in this table. */
.order-management-table th:nth-child(1),
.order-management-table td:nth-child(1){
    width:11%;
}

.order-management-table th:nth-child(2),
.order-management-table td:nth-child(2){
    width:13%;
}

.order-management-table th:nth-child(3),
.order-management-table td:nth-child(3){
    width:8%;
}

.order-management-table th:nth-child(4),
.order-management-table td:nth-child(4){
    width:8%;
}

.order-management-table th:nth-child(5),
.order-management-table td:nth-child(5){
    width:8%;
}

.order-management-table th:nth-child(6),
.order-management-table td:nth-child(6){
    width:9%;
}

.order-management-table th:nth-child(7),
.order-management-table td:nth-child(7){
    width:12%;
}

.order-management-table th:nth-child(8),
.order-management-table td:nth-child(8){
    width:23%;
}

.order-management-table th:nth-child(9),
.order-management-table td:nth-child(9){
    width:8%;
    text-align:center;
}

.order-management-table td.instruction{
    white-space:normal !important;
    overflow:hidden;
    text-overflow:clip;
    overflow-wrap:anywhere;
    word-break:break-word;
    line-height:1.45;
    max-width:none;
    min-width:0;
}

/* ==========================================================
   iPAD / TABLET
   Keep the table. Make it fit the available viewport as much
   as possible, then allow ONLY the table container to scroll.
   ========================================================== */

@media (min-width:769px) and (max-width:1199px){

    .main{
        width:100%;
        max-width:100%;
        min-width:0;
        padding:20px;
    }

    .panel{
        width:100%;
        max-width:100%;
        min-width:0;
        overflow:hidden;
    }

    .date-filter{
        gap:8px;
    }

    .date-filter > div{
        flex:1 1 170px;
        min-width:0;
    }

    .date-filter input{
        width:100%;
    }

    .date-actions{
        margin-left:0;
    }

    .order-filters{
        gap:8px;
    }

    .filter-btn{
        flex:1 1 135px;
        min-width:0;
    }

    .table-container{
        width:100%;
        max-width:100%;
        overflow-x:auto;
    }

    .order-management-table{
        width:1050px !important;
        min-width:1050px !important;
        table-layout:fixed !important;
    }

    .order-management-table th,
    .order-management-table td{
        padding:10px 8px;
        font-size:12px;
    }

    .order-management-table th:nth-child(8),
    .order-management-table td:nth-child(8){
        min-width:220px;
        max-width:250px;
    }

    .action-btn{
        min-height:38px;
    }
}

/* ==========================================================
   iPAD PORTRAIT
   ========================================================== */

@media (min-width:769px) and (max-width:900px){

    .main{
        padding:15px;
    }

    .topbar h1{
        font-size:22px;
    }

    .panel{
        padding:14px;
    }

    .order-management-table{
        width:1050px !important;
        min-width:1050px !important;
        table-layout:fixed !important;
    }

    .order-management-table th,
    .order-management-table td{
        padding:9px 7px;
        font-size:11px;
    }

    .order-management-table th:nth-child(8),
    .order-management-table td:nth-child(8){
        min-width:200px;
        max-width:220px;
    }
}

/* ==========================================================
   SMALL TABLET / PHONE
   Still a TABLE — never convert to cards.
   The table itself scrolls horizontally inside the panel.
   ========================================================== */

@media (max-width:768px){

    .main{
        width:100%;
        max-width:100%;
        min-width:0;
        padding:14px;
    }

    .panel{
        width:100%;
        max-width:100%;
        min-width:0;
        overflow:hidden;
    }

    .date-filter{
        width:100%;
        flex-direction:column;
        align-items:stretch;
    }

    .date-filter > div{
        width:100%;
    }

    .date-filter input{
        width:100%;
    }

    .date-actions{
        width:100%;
        margin-left:0;
    }

    .date-actions .btn{
        flex:1;
    }

    .order-filters{
        display:grid;
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:8px;
    }

    .filter-btn{
        width:100%;
        min-width:0;
        padding:10px 7px;
        font-size:12px;
    }

    .table-container{
        width:100%;
        max-width:100%;
        overflow-x:auto;
        -webkit-overflow-scrolling:touch;
    }

    .order-management-table{
        display:table !important;
        width:1050px !important;
        min-width:1050px !important;
        table-layout:fixed !important;
    }

    .order-management-table thead{
        display:table-header-group !important;
    }

    .order-management-table tbody{
        display:table-row-group !important;
    }

    .order-management-table tr{
        display:table-row !important;
    }

    .order-management-table th,
    .order-management-table td{
        display:table-cell !important;
        white-space:nowrap;
        font-size:11px;
        padding:9px 7px;
    }

    .order-management-table td::before{
        content:none !important;
        display:none !important;
    }

    .order-management-table th:nth-child(8),
    .order-management-table td:nth-child(8){
        min-width:200px;
        max-width:240px;
        white-space:normal;
    }

    .order-management-table th:nth-child(9),
    .order-management-table td:nth-child(9){
        min-width:85px;
    }
}

/* ==========================================================
   VERY SMALL PHONES
   ========================================================== */

@media (max-width:480px){

    .main{
        padding:10px;
    }

    .panel{
        padding:10px;
    }

    .order-filters{
        grid-template-columns:1fr;
    }

    .order-management-table{
        min-width:950px;
    }

    .order-management-table th,
    .order-management-table td{
        font-size:10px;
        padding:8px 6px;
    }

    .order-management-table th:nth-child(8),
    .order-management-table td:nth-child(8){
        min-width:180px;
        max-width:210px;
    }
}
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

    .order-management-table td.instruction {
        white-space: normal !important;
        overflow-wrap: anywhere;
        word-break: break-word;
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

/* ==========================================================
   ORDER TABLE FINAL FIX
   Prevent clipped columns and kitchen-instruction overlap.
   ========================================================== */
.table-container{
    position:relative;
    width:100% !important;
    max-width:100% !important;
    overflow-x:auto !important;
    overflow-y:hidden !important;
}

.order-management-table{
    width:100% !important;
    min-width:0 !important;
    table-layout:fixed !important;
}

.order-management-table th,
.order-management-table td{
    box-sizing:border-box !important;
}

.order-management-table td.instruction{
    white-space:normal !important;
    overflow:hidden !important;
    overflow-wrap:anywhere !important;
    word-break:break-word !important;
    text-overflow:clip !important;
}

.order-management-table td:last-child{
    text-align:center !important;
    white-space:nowrap !important;
}

@media (max-width:1199px){
    .order-management-table{
        width:1050px !important;
        min-width:1050px !important;
    }
}

@media (max-width:480px){
    .order-management-table{
        width:1050px !important;
        min-width:1050px !important;
    }
}
</style>

</head>

<body>

<?php include 'admin_sidebar.php'; ?>

<div class="main">

    <div class="topbar">
        <button class="menu-toggle" id="menuToggle">
            <i class="fa-solid fa-bars"></i>
        </button>

        <h1>Order Management</h1>

        <div class="profile">
            <i class="fa-solid fa-user-shield" style="font-size:24px;color:#c59d5f"></i>
            <div>
                <h3><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></h3>
                <span>Administrator</span>
            </div>
        </div>
    </div>

    <div class="panel">

        <div class="panel-header">
            <h2><i class="fa-solid fa-list-check"></i> Orders</h2>
        </div>

        <form class="date-filter" method="GET">
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
                <a class="btn btn-reset" href="admin_order_management.php">Reset</a>
            </div>
        </form>

        <div class="order-filters">

            <a class="filter-btn <?php echo $status === '' ? 'active' : ''; ?>"
               href="<?php echo htmlspecialchars(statusUrl('', $from, $to)); ?>">
                <i class="fa-solid fa-list"></i> All Orders
            </a>

            <a class="filter-btn <?php echo $status === 'Pending' ? 'active' : ''; ?>"
               href="<?php echo htmlspecialchars(statusUrl('Pending', $from, $to)); ?>">
                <i class="fa-solid fa-clock"></i> Pending Queue
            </a>

            <a class="filter-btn <?php echo $status === 'Processing' ? 'active' : ''; ?>"
               href="<?php echo htmlspecialchars(statusUrl('Processing', $from, $to)); ?>">
                <i class="fa-solid fa-spinner"></i> Processing
            </a>

            <a class="filter-btn <?php echo $status === 'Ready' ? 'active' : ''; ?>"
               href="<?php echo htmlspecialchars(statusUrl('Ready', $from, $to)); ?>">
                <i class="fa-solid fa-bell"></i> Ready
            </a>

            <a class="filter-btn <?php echo $status === 'Completed' ? 'active' : ''; ?>"
               href="<?php echo htmlspecialchars(statusUrl('Completed', $from, $to)); ?>">
                <i class="fa-solid fa-circle-check"></i> Completed
            </a>

        </div>

        <p class="queue-note">
            Pending orders are the active queue waiting for cashier processing.
            Cancelled orders are excluded from this order list.
        </p>

        <div class="table-container">
            <table class="product-table order-management-table">

                <thead>
                    <tr>
                        <th>Order No.</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Date & Time</th>
                        <th>Kitchen Instructions</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (mysqli_num_rows($result) > 0): ?>

                    <?php while ($row = mysqli_fetch_assoc($result)): ?>

                        <?php
                        $oid = (int)$row['id'];

                        /*
                         * Load optional order-item columns safely.
                         * This prevents a fatal error when the localhost
                         * database has not yet added special_instructions
                         * or addons.
                         */
                        $columnExists = function ($table, $column) use ($conn) {
                            $table = mysqli_real_escape_string($conn, $table);
                            $column = mysqli_real_escape_string($conn, $column);

                            $check = mysqli_query(
                                $conn,
                                "SHOW COLUMNS FROM `$table` LIKE '$column'"
                            );

                            return $check && mysqli_num_rows($check) > 0;
                        };

                        $hasSpecialInstructions = $columnExists(
                            'order_items',
                            'special_instructions'
                        );

                        $hasAddons = $columnExists(
                            'order_items',
                            'addons'
                        );

                        $specialSql = $hasSpecialInstructions
                            ? "oi.special_instructions"
                            : "'' AS special_instructions";

                        $addonsSql = $hasAddons
                            ? "oi.addons"
                            : "'' AS addons";

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
                             LEFT JOIN products p ON p.id = oi.product_id
                             WHERE oi.order_id = $oid
                             ORDER BY oi.id ASC"
                        );

                        $parts = [];

                        while ($it = $itemsQ ? mysqli_fetch_assoc($itemsQ) : null) {

                            $x = number_format((int)$it['quantity']) . 'x ' . ($it['name'] ?? 'Product');

                            $opts = [];

                            foreach (
                                [
                                    'size' => 'Size',
                                    'flavor' => 'Flavor',
                                    'sugar' => 'Sugar',
                                    'ice' => 'Ice'
                                ] as $k => $lab
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

                            $addons = trim((string)($it['addons'] ?? ''));
                            if ($addons !== '' && strcasecmp($addons, 'None') !== 0) {
                                $opts[] = 'Add-ons: ' . $addons;
                            }

                            $special = trim((string)($it['special_instructions'] ?? ''));
                            if ($special !== '') {
                                $opts[] = 'Note: ' . $special;
                            }

                            $parts[] = $x . ($opts ? ' (' . implode(', ', $opts) . ')' : '');
                        }

                        $instruction = $parts
                            ? implode(' • ', $parts)
                            : 'No item instructions recorded.';

                        $type = (($row['order_type'] ?? '') === 'Advance Order')
                            ? 'Pick-up Order'
                            : (($row['order_type'] ?? '') ?: 'Dine-In');
                        ?>

                        <tr>

                            <td data-label="Order No."><span class="order-number">
                                    <?php echo htmlspecialchars(orderNumber($oid, $row['created_at'])); ?>
                                </span>
                            </td>

                            <td data-label="Customer"><?php echo htmlspecialchars(customerName($row)); ?>
                            </td>

                        
                            <td data-label="Total"><a class="total-link"
                                   href="view_order.php?id=<?php echo $oid; ?>">
                                    ₱<?php echo number_format((float)$row['total'], 2); ?>
                                </a>
                            </td>

                            <td data-label="Paid">₱<?php echo number_format((float)($row['paid_amount'] ?? 0), 2); ?>
                            </td>

                            <td data-label="Balance">₱<?php echo number_format((float)($row['balance'] ?? 0), 2); ?>
                            </td>

                            <td data-label="Status"><span class="status-badge <?php echo htmlspecialchars(statusClass($row['status'])); ?>">
                                    <?php echo htmlspecialchars($row['status']); ?>
                                </span>
                            </td>

                            <td data-label="Date & Time"><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($row['created_at']))
                                );
                                ?>
                            </td>

                            <td data-label="Kitchen Instructions" class="instruction">
                                <?php echo htmlspecialchars($instruction); ?>
                            </td>

                            <td data-label="Action"><a class="action-btn"
                                   href="view_order.php?id=<?php echo $oid; ?>">
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

<script>
const t = document.getElementById('menuToggle');
const s = document.getElementById('sidebar');
const o = document.getElementById('sidebarOverlay');

if (t && s) {
    t.onclick = () => {
        s.classList.toggle('active');
        if (o) o.classList.toggle('active');
    };
}

if (o && s) {
    o.onclick = () => {
        s.classList.remove('active');
        o.classList.remove('active');
    };
}

/* Always show the first Order No. column when the page opens. */
document.addEventListener('DOMContentLoaded', function () {
    const tableWrap = document.querySelector('.table-container');
    if (tableWrap) {
        tableWrap.scrollLeft = 0;
    }
});
</script>

</body>
</html>

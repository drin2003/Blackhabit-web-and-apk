<?php
/*
 * BLACKHABIT: Supplies Management supports both Admin and Cashier.
 * Use the correct role-specific session so logging in another role
 * in another tab does not overwrite this page's session.
 */
$referer = strtolower((string)($_SERVER['HTTP_REFERER'] ?? ''));
$roleHint = strtolower(trim((string)($_GET['role'] ?? '')));

if ($roleHint === 'cashier') {
    session_name('BH_CASHIER_SESSION');
} elseif ($roleHint === 'admin') {
    session_name('BH_ADMIN_SESSION');
} elseif (strpos($referer, 'cashier_dashboard.php') !== false || strpos($referer, 'cashier_') !== false) {
    session_name('BH_CASHIER_SESSION');
} elseif (strpos($referer, 'admin_dashboard.php') !== false || strpos($referer, 'admin_') !== false) {
    session_name('BH_ADMIN_SESSION');
} elseif (isset($_COOKIE['BH_CASHIER_SESSION']) && !isset($_COOKIE['BH_ADMIN_SESSION'])) {
    session_name('BH_CASHIER_SESSION');
} elseif (isset($_COOKIE['BH_ADMIN_SESSION']) && !isset($_COOKIE['BH_CASHIER_SESSION'])) {
    session_name('BH_ADMIN_SESSION');
} else {
    session_name('BH_ADMIN_SESSION');
}

session_start();

/* ==========================================================
   SECURITY
========================================================== */

if (!isset($_SESSION['role'])) {
    header("Location: index.php");
    exit();
}

/*
 * Support both text roles (admin/cashier) and the numeric role
 * values used by some Black Habit sessions (1 = admin, 3 = cashier).
 */
$rawRole = $_SESSION['role'];
$role = strtolower(trim((string)$rawRole));

if ($role === '1') {
    $role = 'admin';
} elseif ($role === '3') {
    $role = 'cashier';
}

if ($role !== 'admin' && $role !== 'cashier') {
    header("Location: index.php");
    exit();
}

include "db.php";

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection error.");
}

$isAdmin = ($role === 'admin');

/* ==========================================================
   HELPERS
========================================================== */

/*
 * Inventory quantities must be whole numbers.
 * Do not silently cast "2.5" to 2 because that can corrupt stock.
 */
function validWholeNumber($value, $allowZero = true) {
    if (is_array($value) || is_object($value)) {
        return false;
    }

    $value = trim((string)$value);

    if ($value === '' || !preg_match('/^\d+$/', $value)) {
        return false;
    }

    if (!$allowZero && $value === '0') {
        return false;
    }

    return true;
}

function redirectSupplyManagement() {
    $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
    if ($role === '1') $role = 'admin';
    elseif ($role === '3') $role = 'cashier';
    header("Location: supplies_management.php?role=" . ($role === 'cashier' ? 'cashier' : 'admin'));
    exit();
}

/* ==========================================================
   ADD NEW SUPPLY
========================================================== */

if ($isAdmin && isset($_POST['add_supply'])) {

    $supply_name = trim((string)($_POST['supply_name'] ?? ''));
    $currentStockRaw = $_POST['current_stock'] ?? '';
    $minimumStockRaw = $_POST['minimum_stock'] ?? '';
    $unit = trim((string)($_POST['unit'] ?? ''));

    if ($supply_name === '') {

        $_SESSION['supply_error'] = "Supply name is required.";

    } elseif (!validWholeNumber($currentStockRaw) || !validWholeNumber($minimumStockRaw)) {

        $_SESSION['supply_error'] = "Stock and stock limit must be whole numbers only.";

    } else {

        $current_stock = (int)$currentStockRaw;
        $minimum_stock = (int)$minimumStockRaw;

        if ($unit === '') {

            $_SESSION['supply_error'] = "Unit is required.";

        } else {

            $check = mysqli_prepare(
                $conn,
                "SELECT id FROM supplies WHERE LOWER(TRIM(supply_name)) = LOWER(TRIM(?)) LIMIT 1"
            );

            if (!$check) {
                $_SESSION['supply_error'] = "Unable to check existing supplies: " . mysqli_error($conn);
            } else {
                mysqli_stmt_bind_param($check, "s", $supply_name);

                if (!mysqli_stmt_execute($check)) {
                    $_SESSION['supply_error'] = "Unable to check existing supplies.";
                    mysqli_stmt_close($check);
                    redirectSupplyManagement();
                }

                $checkResult = mysqli_stmt_get_result($check);
                $exists = $checkResult && mysqli_num_rows($checkResult) > 0;
                mysqli_stmt_close($check);

                if ($exists) {

                    $_SESSION['supply_error'] = "This supply already exists.";

                } else {

                    /*
                     * Save the supply and its initial Stock In history as
                     * one transaction. This prevents an inventory record
                     * from being created without its matching history.
                     */
                    mysqli_begin_transaction($conn);

                    try {
                        /*
                         * The hosted supplies table requires category and supplier.
                         * The Add Supply form does not currently ask for those fields,
                         * so use safe default values for a general supply.
                         */
                        $category = 'General';
                        $supplier = 'N/A';

                        $stmt = mysqli_prepare(
                            $conn,
                            "INSERT INTO supplies
                            (supply_name, category, unit, current_stock, minimum_stock, supplier)
                            VALUES (?, ?, ?, ?, ?, ?)"
                        );

                        if (!$stmt) {
                            throw new Exception("Unable to prepare supply record: " . mysqli_error($conn));
                        }

                        mysqli_stmt_bind_param(
                            $stmt,
                            "sssiis",
                            $supply_name,
                            $category,
                            $unit,
                            $current_stock,
                            $minimum_stock,
                            $supplier
                        );

                        if (!mysqli_stmt_execute($stmt)) {
                            $error = mysqli_stmt_error($stmt);
                            mysqli_stmt_close($stmt);
                            throw new Exception("Failed to add supply: " . $error);
                        }

                        $newSupplyId = mysqli_insert_id($conn);
                        mysqli_stmt_close($stmt);

                        if ($current_stock > 0) {
                            $history = mysqli_prepare(
                                $conn,
                                "INSERT INTO stock_in
                                    (ingredient_id, supply_id, quantity, received_by, date_received)
                                 VALUES (NULL, ?, ?, ?, NOW())"
                            );

                            if (!$history) {
                                throw new Exception("Unable to prepare initial Stock In history.");
                            }

                            $receivedBy = trim((string)($_SESSION['name'] ?? 'Administrator'));
                            if ($receivedBy === '') {
                                $receivedBy = 'Administrator';
                            }

                            mysqli_stmt_bind_param(
                                $history,
                                "iis",
                                $newSupplyId,
                                $current_stock,
                                $receivedBy
                            );

                            if (!mysqli_stmt_execute($history)) {
                                $error = mysqli_stmt_error($history);
                                mysqli_stmt_close($history);
                                throw new Exception("Unable to save initial Stock In history: " . $error);
                            }

                            mysqli_stmt_close($history);
                        }

                        mysqli_commit($conn);
                        $_SESSION['supply_success'] = "Supply added successfully.";

                    } catch (Throwable $e) {
                        mysqli_rollback($conn);
                        $_SESSION['supply_error'] = $e->getMessage();
                    }
                }
            }
        }
    }

    redirectSupplyManagement();
}


/* ==========================================================
   UPDATE SUPPLY STOCK
========================================================== */

if ($isAdmin && isset($_POST['action'])) {

    $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
    $qtyRaw = $_POST['change_qty'] ?? '';
    $action = trim((string)($_POST['action'] ?? ''));

    if ($id === false || $id <= 0 || !validWholeNumber($qtyRaw, false)) {
        $_SESSION['supply_error'] = "Please enter a valid whole-number quantity.";
        redirectSupplyManagement();
    }

    $qty = (int)$qtyRaw;

    $supplyStmt = mysqli_prepare(
        $conn,
        "SELECT supply_name, current_stock, unit
         FROM supplies
         WHERE id = ?
         LIMIT 1"
    );

    if (!$supplyStmt) {
        $_SESSION['supply_error'] = "Unable to load supply: " . mysqli_error($conn);
        redirectSupplyManagement();
    }

    mysqli_stmt_bind_param($supplyStmt, "i", $id);

    if (!mysqli_stmt_execute($supplyStmt)) {
        mysqli_stmt_close($supplyStmt);
        $_SESSION['supply_error'] = "Unable to load supply.";
        redirectSupplyManagement();
    }

    $supplyResult = mysqli_stmt_get_result($supplyStmt);
    $supply = $supplyResult ? mysqli_fetch_assoc($supplyResult) : null;
    mysqli_stmt_close($supplyStmt);

    if (!$supply) {
        $_SESSION['supply_error'] = "Supply not found.";
        redirectSupplyManagement();
    }

    mysqli_begin_transaction($conn);

    try {

        if ($action === "add") {

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE supplies
                 SET current_stock = current_stock + ?
                 WHERE id = ?"
            );

            if (!$stmt) {
                throw new Exception("Unable to prepare supply stock update.");
            }

            mysqli_stmt_bind_param($stmt, "ii", $qty, $id);

            if (!mysqli_stmt_execute($stmt)) {
                $error = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
                throw new Exception("Unable to update supply stock: " . $error);
            }

            mysqli_stmt_close($stmt);

            $history = mysqli_prepare(
                $conn,
                "INSERT INTO stock_in
                    (ingredient_id, supply_id, quantity, received_by, date_received)
                 VALUES (NULL, ?, ?, ?, NOW())"
            );

            if (!$history) {
                throw new Exception("Unable to prepare supply Stock In history.");
            }

            $receivedBy = trim((string)($_SESSION['name'] ?? 'Administrator'));
            if ($receivedBy === '') {
                $receivedBy = 'Administrator';
            }

            mysqli_stmt_bind_param($history, "iis", $id, $qty, $receivedBy);

            if (!mysqli_stmt_execute($history)) {
                $error = mysqli_stmt_error($history);
                mysqli_stmt_close($history);
                throw new Exception("Unable to save supply Stock In history: " . $error);
            }

            mysqli_stmt_close($history);

            $_SESSION['supply_success'] =
                $supply['supply_name'] . " stock increased by " . number_format($qty, 0) . ".";

        } elseif ($action === "subtract") {

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE supplies
                 SET current_stock = current_stock - ?
                 WHERE id = ? AND current_stock >= ?"
            );

            if (!$stmt) {
                throw new Exception("Unable to prepare supply stock update.");
            }

            mysqli_stmt_bind_param($stmt, "iii", $qty, $id, $qty);

            if (!mysqli_stmt_execute($stmt)) {
                $error = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
                throw new Exception("Unable to update supply stock: " . $error);
            }

            if (mysqli_stmt_affected_rows($stmt) !== 1) {
                mysqli_stmt_close($stmt);
                throw new Exception("Not enough stock available.");
            }

            mysqli_stmt_close($stmt);

            $history = mysqli_prepare(
                $conn,
                "INSERT INTO stock_out
                    (ingredient_id, supply_id, order_id, category, quantity, reason, released_by, date_out)
                 VALUES (NULL, ?, NULL, 'MANUAL', ?, 'Manual supply stock out', ?, NOW())"
            );

            if (!$history) {
                throw new Exception("Unable to prepare supply Stock Out history.");
            }

            $releasedBy = trim((string)($_SESSION['name'] ?? 'Administrator'));
            if ($releasedBy === '') {
                $releasedBy = 'Administrator';
            }

            mysqli_stmt_bind_param($history, "iis", $id, $qty, $releasedBy);

            if (!mysqli_stmt_execute($history)) {
                $error = mysqli_stmt_error($history);
                mysqli_stmt_close($history);
                throw new Exception("Unable to save supply Stock Out history: " . $error);
            }

            mysqli_stmt_close($history);

            $_SESSION['supply_success'] =
                $supply['supply_name'] . " stock decreased by " . number_format($qty, 0) . ".";

        } else {
            throw new Exception("Invalid inventory action.");
        }

        mysqli_commit($conn);

    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $_SESSION['supply_error'] = $e->getMessage();
    }

    redirectSupplyManagement();
}


/* ==========================================================
   PAGE TITLE
========================================================== */

$search = trim((string)($_GET['search'] ?? ''));

$pageTitle = "Supply Management";

?>
<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?php echo $pageTitle; ?></title>

<link rel="icon" href="favicon_io/favicon.ico">

<link
    rel="stylesheet"
    href="style.css?v=<?php echo time(); ?>"
>
<link rel="stylesheet" href="admin_sidebar.css">

<link
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
>


<style>

/* ==========================================================
   SUPPLY FORM
========================================================== */

.supply-form-panel {
    margin-bottom: 25px;
}

.supply-form-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr auto;
    gap: 15px;
    align-items: end;
}

.supply-form-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.supply-form-group label {
    color: #c59d5f;
    font-weight: 600;
    font-size: 14px;
}

.supply-form-group input,
.supply-form-group select {
    width: 100%;
    padding: 12px 14px;
    border-radius: 8px;
    border: 1px solid #333;
    background: #202020;
    color: white;
    font-family: 'Poppins', sans-serif;
    box-sizing: border-box;
}

.supply-form-group input:focus,
.supply-form-group select:focus {
    outline: none;
    border-color: #c59d5f;
}

.add-supply-btn {
    border: none;
    background: #c59d5f;
    color: #111;
    padding: 12px 20px;
    border-radius: 8px;
    font-family: 'Poppins', sans-serif;
    font-weight: 700;
    cursor: pointer;
    height: 45px;
    white-space: nowrap;
}

.add-supply-btn:hover {
    background: #d6ad6c;
}


/* ==========================================================
   ALERTS
========================================================== */

.supply-alert {
    padding: 13px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-weight: 500;
}

.supply-success {
    background: rgba(40, 167, 69, 0.15);
    border: 1px solid #28a745;
    color: #65d47b;
}

.supply-error {
    background: rgba(220, 53, 69, 0.15);
    border: 1px solid #dc3545;
    color: #ff6b7a;
}


/* ==========================================================
   SUPPLIES INVENTORY TABLE
========================================================== */

.panel {
    background: #151515;
    border: 1px solid #292929;
    border-radius: 14px;
    overflow: hidden;
}

.panel-header {
    padding: 18px 20px;
    border-bottom: 1px solid #292929;
}

.panel-header h2 {
    margin: 0;
    color: #fff;
    font-size: 19px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
}

.panel-header h2 i {
    color: #c59d5f;
}

.supply-search {
    display: flex;
    gap: 8px;
    align-items: center;
    padding: 18px 20px 14px;
    flex-wrap: nowrap;
}

.supply-search-box {
    position: relative;
    flex: 1;
    min-width: 0;
}

.supply-search-box i {
    position: absolute;
    left: 13px;
    top: 50%;
    transform: translateY(-50%);
    color: #777;
    pointer-events: none;
}

.supply-search-box input {
    width: 100%;
    height: 40px;
    box-sizing: border-box;
    padding: 0 13px 0 36px;
    background: #101010;
    color: #fff;
    border: 1px solid #333;
    border-radius: 8px;
    font-family: 'Poppins', sans-serif;
    outline: none;
}

.supply-search-box input:focus {
    border-color: #c59d5f;
}

.supply-search-box input::placeholder {
    color: #6f6f6f;
}

.supply-search-btn,
.supply-reset-btn {
    height: 40px;
    padding: 0 18px;
    border-radius: 8px;
    text-decoration: none;
    border: 1px solid #c59d5f;
    background: #c59d5f;
    color: #111;
    font-family: 'Poppins', sans-serif;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    white-space: nowrap;
}

.supply-reset-btn {
    background: #222;
    color: #ddd;
    border-color: #444;
}

.table-container {
    width: 100%;
    overflow-x: auto;
    padding: 0 18px 18px;
    box-sizing: border-box;
}

.product-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    background: #151515;
}

.product-table thead {
    background: #202020;
}

.product-table th {
    height: 44px;
    padding: 0 12px;
    color: #c59d5f;
    font-size: 12px;
    font-weight: 700;
    text-align: left;
    white-space: nowrap;
    border-bottom: 1px solid #292929;
}

.product-table th:nth-child(1) {
    width: 27%;
}

.product-table th:nth-child(2) {
    width: 12%;
}

.product-table th:nth-child(3) {
    width: 10%;
}

.product-table th:nth-child(4) {
    width: 15%;
}

.product-table th:nth-child(5) {
    width: 36%;
}

.product-table tbody tr {
    height: 66px;
    border-bottom: 1px solid #292929;
    transition: background .15s ease;
}

.product-table tbody tr:hover {
    background: #1b1b1b;
}

.product-table td {
    padding: 10px 12px;
    color: #f0f0f0;
    font-size: 14px;
    vertical-align: middle;
}

.product-table td:first-child {
    color: #fff;
    font-weight: 500;
}

.product-table td:nth-child(2),
.product-table td:nth-child(3),
.product-table td:nth-child(4) {
    color: #f0f0f0;
}

.product-table td:last-child {
    padding-right: 10px;
}

.product-table td:last-child form {
    display: flex !important;
    align-items: center;
    gap: 7px !important;
    flex-wrap: nowrap !important;
}

.qty-input {
    width: 120px;
    height: 42px;
    box-sizing: border-box;
    padding: 0 12px;
    border-radius: 8px;
    border: 1px solid #333;
    background: #1d1d1d;
    color: #fff;
    font-family: 'Poppins', sans-serif;
    outline: none;
}

.qty-input:focus {
    border-color: #c59d5f;
}

.qty-input::placeholder {
    color: #707070;
}

.stock-btn {
    height: 34px;
    border: none;
    border-radius: 7px;
    padding: 0 13px;
    cursor: pointer;
    font-weight: 700;
    font-family: 'Poppins', sans-serif;
    white-space: nowrap;
}

.stock-in {
    background: #c59d5f;
    color: #111;
}

.stock-in:hover {
    background: #d6ad6c;
}

.stock-out {
    background: #dc3545;
    color: #fff;
}

.stock-out:hover {
    background: #ed4658;
}

@media (max-width: 900px) {
    .table-container {
        overflow-x: auto;
    }

    .product-table {
        min-width: 780px;
    }
}

@media (max-width: 600px) {
    .supply-search {
        flex-wrap: wrap;
    }

    .supply-search-box {
        flex-basis: 100%;
    }

    .supply-search-btn,
    .supply-reset-btn {
        flex: 1;
    }
}

/* ==========================================================
   RESPONSIVE
========================================================== */

@media (max-width: 900px) {

    .supply-form-grid {
        grid-template-columns: 1fr 1fr;
    }

    .add-supply-btn {
        width: 100%;
    }
}

@media (max-width: 600px) {

    .supply-form-grid {
        grid-template-columns: 1fr;
    }

    .table-container {
        overflow-x: auto;
    }
}


/* ==========================================================
   ADD SUPPLY BUTTON + MODAL PANEL
========================================================== */

.add-supply-trigger-wrap {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 22px;
}

.add-supply-trigger {
    border: none;
    background: #c59d5f;
    color: #111;
    padding: 11px 18px;
    border-radius: 8px;
    font-family: 'Poppins', sans-serif;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.add-supply-trigger:hover {
    background: #d6ad6c;
}

.supply-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: rgba(0, 0, 0, 0.78);
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.supply-modal.open {
    display: flex;
}

.supply-modal-dialog {
    position: relative;
    width: min(820px, 96vw);
    max-height: 90vh;
    overflow-y: auto;
    box-sizing: border-box;
    background: #161616;
    border: 1px solid #3a3a3a;
    border-radius: 12px;
    padding: 26px;
    box-shadow: 0 25px 80px rgba(0,0,0,.65);
}

.supply-modal-header {
    padding-right: 45px;
    padding-bottom: 17px;
    margin-bottom: 22px;
    border-bottom: 1px solid #2c2c2c;
}

.supply-modal-header h2 {
    margin: 0 0 5px;
    color: #c59d5f;
    font-size: 20px;
}

.supply-modal-header p {
    margin: 0;
    color: #888;
    font-size: 13px;
}

.supply-modal-close {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 38px;
    height: 38px;
    border: none;
    border-radius: 50%;
    background: #292929;
    color: #fff;
    font-size: 18px;
    cursor: pointer;
}

.supply-modal-close:hover {
    background: #3a3a3a;
}

.supply-modal-form-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 18px;
}

.supply-modal-form-grid .supply-form-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.supply-modal-form-grid .supply-form-group label {
    color: #c59d5f;
    font-weight: 600;
    font-size: 14px;
}

.supply-modal-form-grid .supply-form-group input {
    width: 100%;
    box-sizing: border-box;
    padding: 12px 14px;
    border-radius: 8px;
    border: 1px solid #333;
    background: #202020;
    color: #fff;
    font-family: 'Poppins', sans-serif;
}

.supply-modal-form-grid .supply-form-group input:focus {
    outline: none;
    border-color: #c59d5f;
}

.supply-modal-actions {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
    margin-top: 24px;
    padding-top: 18px;
    border-top: 1px solid #2c2c2c;
}

.supply-cancel-btn {
    border: 1px solid #444;
    background: #252525;
    color: #ddd;
    padding: 11px 18px;
    border-radius: 8px;
    font-family: 'Poppins', sans-serif;
    font-weight: 600;
    cursor: pointer;
}

.supply-cancel-btn:hover {
    background: #303030;
}

.supply-modal-actions .add-supply-btn {
    height: auto;
    padding: 11px 18px;
}

@media (max-width: 650px) {
    .supply-modal-form-grid {
        grid-template-columns: 1fr;
    }

    .supply-modal-dialog {
        padding: 20px;
    }

    .supply-modal-actions {
        flex-direction: column-reverse;
        align-items: stretch;
    }

    .supply-modal-actions button {
        width: 100%;
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


<div class="sidebar-overlay" id="sidebarOverlay"></div>


<!-- ==========================================================
     SIDEBAR
========================================================== -->
<?php
if ($role === 'admin') {
    include "admin_sidebar.php";
} elseif ($role === 'cashier') {
    include "cashier_sidebar.php";
}
?>




<!-- ==========================================================
     MAIN
========================================================== -->

<div class="main">


    <!-- TOPBAR -->

    <div class="topbar">

        <button
            class="menu-toggle"
            id="menuToggle"
        >

            <i class="fa-solid fa-bars"></i>

        </button>


        <h1>Supplies Management</h1>


        <div class="profile">

            <i
                class="fa-solid fa-circle-user"
                style="font-size:24px;color:#c59d5f;"
            ></i>


            <div>

                <h3>
                    <?php
                    echo htmlspecialchars(
                        $_SESSION['name'] ?? 'User'
                    );
                    ?>
                </h3>

                <span>
                    <?php
                    echo $isAdmin
                        ? 'Administrator'
                        : 'Cashier';
                    ?>
                </span>

            </div>

        </div>

    </div>


    <!-- WELCOME -->

    <div class="welcome">

        <h2>Supplies Inventory</h2>

        <p>

            View all available supplies.

            <?php if ($isAdmin) { ?>

                You can also add and update supply stock.

            <?php } ?>

        </p>

    </div>


    <!-- ======================================================
         ALERT MESSAGES
    ======================================================= -->

    <?php if (isset($_SESSION['supply_success'])) { ?>

        <div class="supply-alert supply-success">

            <i class="fa-solid fa-circle-check"></i>

            <?php
            echo htmlspecialchars(
                $_SESSION['supply_success']
            );

            unset($_SESSION['supply_success']);
            ?>

        </div>

    <?php } ?>


    <?php if (isset($_SESSION['supply_error'])) { ?>

        <div class="supply-alert supply-error">

            <i class="fa-solid fa-circle-exclamation"></i>

            <?php
            echo htmlspecialchars(
                $_SESSION['supply_error']
            );

            unset($_SESSION['supply_error']);
            ?>

        </div>

    <?php } ?>


    <?php if ($isAdmin) { ?>


    <!-- ======================================================
         ADD SUPPLY
    ======================================================= -->

    <!-- ======================================================
         ADD SUPPLY
    ======================================================= -->

    <div class="add-supply-trigger-wrap">
        <button
            type="button"
            class="add-supply-trigger"
            id="openAddSupply"
        >
            <i class="fa-solid fa-plus"></i>
            Add New Supply
        </button>
    </div>

    <!-- ADD SUPPLY MODAL PANEL -->
    <div
        class="supply-modal"
        id="addSupplyModal"
        aria-hidden="true"
    >
        <div
            class="supply-modal-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="addSupplyTitle"
        >
            <button
                type="button"
                class="supply-modal-close"
                id="closeAddSupply"
                aria-label="Close"
            >
                <i class="fa-solid fa-xmark"></i>
            </button>

            <div class="supply-modal-header">
                <h2 id="addSupplyTitle">
                    <i class="fa-solid fa-plus"></i>
                    Add New Supply
                </h2>
                <p>Add a new supply to your inventory.</p>
            </div>

            <form method="POST" action="supplies_management.php?role=admin">

                <div class="supply-modal-form-grid">

                    <div class="supply-form-group">
                        <label for="supply_name">Supply Name</label>
                        <input
                            type="text"
                            id="supply_name"
                            name="supply_name"
                            placeholder="e.g. Plastic Cups"
                            required
                        >
                    </div>

                    <div class="supply-form-group">
                        <label for="current_stock">Initial Stock</label>
                        <input
                            type="number"
                            id="current_stock"
                            name="current_stock"
                            min="0"
                            step="1"
                            placeholder="0"
                            required
                        >
                    </div>

                    <div class="supply-form-group">
                        <label for="minimum_stock">Stock Limit</label>
                        <input
                            type="number"
                            id="minimum_stock"
                            name="minimum_stock"
                            min="0"
                            step="1"
                            placeholder="20"
                            required
                        >
                    </div>

                    <div class="supply-form-group">
                        <label for="unit">Unit</label>
                        <input
                            type="text"
                            id="unit"
                            name="unit"
                            placeholder="pcs, box, pack"
                            required
                        >
                    </div>

                </div>

                <div class="supply-modal-actions">
                    <button
                        type="button"
                        class="supply-cancel-btn"
                        id="cancelAddSupply"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        name="add_supply"
                        value="1"
                        class="add-supply-btn"
                    >
                        <i class="fa-solid fa-plus"></i>
                        Add Supply
                    </button>
                </div>

            </form>
        </div>
    </div>

    <?php } ?>


    <!-- ======================================================
         SUPPLIES INVENTORY
    ======================================================= -->

    <div class="panel">

        <div class="panel-header">

            <h2>

                <i class="fa-solid fa-boxes-stacked"></i>

                Supplies Inventory

            </h2>

        </div>


        <form method="GET" class="supply-search">
            <input type="hidden" name="role" value="<?php echo htmlspecialchars($role); ?>">
            <div class="supply-search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search supplies...">
            </div>
            <button type="submit" class="supply-search-btn">Search</button>
            <?php if ($search !== '') { ?>
                <a href="supplies_management.php?role=<?php echo urlencode($role); ?>" class="supply-reset-btn">Reset</a>
            <?php } ?>
        </form>

        <div class="table-container">

            <table class="product-table">

                <thead>

                    <tr>

                        <th>Supply</th>
                        <th>Stock</th>
                        <th>Unit</th>
                        <th>Stock Limit</th>

                        <?php if ($isAdmin) { ?>

                            <th>Adjust</th>

                        <?php } ?>

                    </tr>

                </thead>


                <tbody>

                <?php
                $suppliesStmt = mysqli_prepare(
                    $conn,
                    "SELECT *
                     FROM supplies
                     WHERE (? = '' OR supply_name LIKE CONCAT('%', ?, '%'))
                     ORDER BY supply_name ASC"
                );
                mysqli_stmt_bind_param($suppliesStmt, "ss", $search, $search);

                if (!mysqli_stmt_execute($suppliesStmt)) {
                    $supplies = false;
                    $_SESSION['supply_error'] = "Unable to load supplies.";
                } else {
                    $supplies = mysqli_stmt_get_result($suppliesStmt);
                }


                if (
                    $supplies &&
                    mysqli_num_rows($supplies) > 0
                ) {

                    while (
                        $row = mysqli_fetch_assoc($supplies)
                    ) {

                ?>

                    <tr>


                        <!-- SUPPLY NAME -->

                        <td>

                            <?php
                            echo htmlspecialchars(
                                $row['supply_name']
                            );
                            ?>

                        </td>


                        <!-- STOCK -->

                        <td>

                            <?php
                            echo number_format((int)($row['current_stock'] ?? 0), 0);
                            ?>

                        </td>

                        <td>
                            <?php echo htmlspecialchars((string)($row['unit'] ?? '')); ?>
                        </td>

                        <td>
                            <?php echo number_format((int)($row['minimum_stock'] ?? 0), 0); ?>
                        </td>


                        <?php if ($isAdmin) { ?>


                        <!-- ADJUST STOCK -->

                        <td>

                            <form
                                method="POST"
                                style="
                                    display:flex;
                                    gap:8px;
                                    align-items:center;
                                    flex-wrap:wrap;
                                "
                            >

                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?php
                                    echo (int)$row['id'];
                                    ?>"
                                >


                                <input
                                    type="number"
                                    step="1"
                                    min="1"
                                    name="change_qty"
                                    placeholder="Qty"
                                    required
                                    class="qty-input"
                                >


                                <button
                                    type="submit"
                                    name="action"
                                    value="add"
                                    class="stock-btn stock-in"
                                    title="Stock In"
                                >

                                    <i class="fa-solid fa-plus"></i>

                                    IN

                                </button>


                                <button
                                    type="submit"
                                    name="action"
                                    value="subtract"
                                    class="stock-btn stock-out"
                                    title="Stock Out"
                                >

                                    <i class="fa-solid fa-minus"></i>

                                    OUT

                                </button>

                            </form>

                        </td>


                        <?php } ?>


                    </tr>

                <?php

                    }

                } else {

                ?>


                    <tr>

                        <td
                            colspan="<?php
                                echo $isAdmin ? 5 : 4;
                            ?>"
                            style="text-align:center;"
                        >

                            <i class="fa-solid fa-box-open"></i>

                            No supplies found.

                        </td>

                    </tr>


                <?php } ?>

                <?php mysqli_stmt_close($suppliesStmt); ?>

                </tbody>

            </table>

        </div>

    </div>


</div>


<!-- ==========================================================
     MOBILE MENU
========================================================== -->

<script>

const menuToggle =
    document.getElementById("menuToggle");

const sidebar =
    document.getElementById("sidebar");

const overlay =
    document.getElementById("sidebarOverlay");


function toggleMenu() {
    if (!sidebar || !overlay) return;
    sidebar.classList.toggle("active");
    overlay.classList.toggle("active");
}


if (menuToggle) {

    menuToggle.addEventListener(
        "click",
        toggleMenu
    );

}


if (overlay) {

    overlay.addEventListener(
        "click",
        toggleMenu
    );

}

</script>



<script>
(function () {
    const modal = document.getElementById("addSupplyModal");
    const openBtn = document.getElementById("openAddSupply");
    const closeBtn = document.getElementById("closeAddSupply");
    const cancelBtn = document.getElementById("cancelAddSupply");

    if (!modal || !openBtn) return;

    function openSupplyModal() {
        modal.classList.add("open");
        modal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";

        const input = document.getElementById("supply_name");
        if (input) {
            setTimeout(function () {
                input.focus();
            }, 100);
        }
    }

    function closeSupplyModal() {
        modal.classList.remove("open");
        modal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
    }

    openBtn.addEventListener("click", openSupplyModal);

    if (closeBtn) {
        closeBtn.addEventListener("click", closeSupplyModal);
    }

    if (cancelBtn) {
        cancelBtn.addEventListener("click", closeSupplyModal);
    }

    modal.addEventListener("click", function (event) {
        if (event.target === modal) {
            closeSupplyModal();
        }
    });

    document.addEventListener("keydown", function (event) {
        if (
            event.key === "Escape" &&
            modal.classList.contains("open")
        ) {
            closeSupplyModal();
        }
    });
})();
</script>
</body>

</html>
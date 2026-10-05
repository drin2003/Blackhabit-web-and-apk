<?php
/*
 * BLACKHABIT: Inventory is shared by Admin and Cashier,
 * so select the correct role-specific session instead of
 * using the shared default PHP session.
 */
$referer = strtolower((string)($_SERVER['HTTP_REFERER'] ?? ''));
$roleHint = strtolower(trim((string)($_GET['role'] ?? '')));

/*
 * IMPORTANT:
 * Inventory is shared by Admin and Cashier. A cashier must explicitly
 * enter this page with ?role=cashier so the correct role session is used.
 * This avoids falling back to the Admin session when both role cookies exist
 * or when the browser does not send a Referer header.
 */
if ($roleHint === 'cashier') {
    session_name('BH_CASHIER_SESSION');
} elseif ($roleHint === 'admin') {
    session_name('BH_ADMIN_SESSION');
} elseif (
    strpos($referer, 'cashier_dashboard.php') !== false ||
    strpos($referer, 'cashier_') !== false
) {
    session_name('BH_CASHIER_SESSION');
} elseif (
    strpos($referer, 'admin_dashboard.php') !== false ||
    strpos($referer, 'admin_') !== false
) {
    session_name('BH_ADMIN_SESSION');
} elseif (
    isset($_COOKIE['BH_CASHIER_SESSION']) &&
    !isset($_COOKIE['BH_ADMIN_SESSION'])
) {
    session_name('BH_CASHIER_SESSION');
} elseif (
    isset($_COOKIE['BH_ADMIN_SESSION']) &&
    !isset($_COOKIE['BH_CASHIER_SESSION'])
) {
    session_name('BH_ADMIN_SESSION');
} else {
    session_name('BH_ADMIN_SESSION');
}

session_start();

if (!isset($_SESSION['role']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

$role = strtolower(trim((string)$_SESSION['role']));

$isAdmin =
    $role === 'admin' ||
    (int)$_SESSION['role'] === 1;

$isCashier =
    $role === 'cashier' ||
    (int)$_SESSION['role'] === 3;

if (!$isAdmin && !$isCashier) {
    header("Location: index.php");
    exit();
}

include "db.php";

$message = "";
$messageType = "";


/* =========================================================
   ADD INGREDIENT
   ========================================================= */
if ($isAdmin && isset($_POST['add_ingredient'])) {
    $ingredientName = trim((string)($_POST['ingredient_name'] ?? ''));
    $category       = trim((string)($_POST['category'] ?? ''));
    $unit           = trim((string)($_POST['unit'] ?? ''));
    $stockRaw       = trim((string)($_POST['current_stock'] ?? '0'));
    $minimumRaw     = trim((string)($_POST['minimum_stock'] ?? '0'));

    $stock   = (int)$stockRaw;
    $minimum = (int)$minimumRaw;

    if ($ingredientName === '' || $category === '' || $unit === '') {
        $_SESSION['inventory_message'] = "Please complete all ingredient fields.";
        $_SESSION['inventory_type'] = "error";
    } elseif (!preg_match('/^\\d+$/', $stockRaw) || !preg_match('/^\\d+$/', $minimumRaw) || $stock < 0 || $minimum < 0) {
        $_SESSION['inventory_message'] = "Stock and minimum stock must be whole numbers (0 or higher).";
        $_SESSION['inventory_type'] = "error";
    } else {
        $check = mysqli_prepare(
            $conn,
            "SELECT id FROM ingredients WHERE LOWER(ingredient_name) = LOWER(?) LIMIT 1"
        );

        if (!$check) {
            $_SESSION['inventory_message'] = "Unable to check ingredient: " . mysqli_error($conn);
            $_SESSION['inventory_type'] = "error";
        } else {
            mysqli_stmt_bind_param($check, "s", $ingredientName);
            mysqli_stmt_execute($check);
            $checkResult = mysqli_stmt_get_result($check);
            $exists = $checkResult && mysqli_num_rows($checkResult) > 0;
            mysqli_stmt_close($check);

            if ($exists) {
                $_SESSION['inventory_message'] = "That ingredient already exists.";
                $_SESSION['inventory_type'] = "error";
            } else {
                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO ingredients (ingredient_name, category, current_stock, unit, minimum_stock)
                     VALUES (?, ?, ?, ?, ?)"
                );

                if (!$stmt) {
                    $_SESSION['inventory_message'] = "Unable to prepare ingredient insert: " . mysqli_error($conn);
                    $_SESSION['inventory_type'] = "error";
                } else {
                    mysqli_stmt_bind_param($stmt, "ssisi", $ingredientName, $category, $stock, $unit, $minimum);

                    if (mysqli_stmt_execute($stmt)) {
                        $_SESSION['inventory_message'] = "Ingredient added successfully.";
                        $_SESSION['inventory_type'] = "success";
                    } else {
                        $_SESSION['inventory_message'] = "Unable to add ingredient: " . mysqli_stmt_error($stmt);
                        $_SESSION['inventory_type'] = "error";
                    }

                    mysqli_stmt_close($stmt);
                }
            }
        }
    }

    header("Location: inventory_management.php?role=" . ($isCashier ? "cashier" : "admin"));
    exit();
}


/* =========================================================
   MINIMUM STOCK LIMIT
   ========================================================= */
if ($isAdmin && isset($_POST['set_minimum_stock'])) {
    $id=(int)($_POST['id']??0);
    $minRaw=trim((string)($_POST['minimum_stock']??'0'));
    $min=(int)$minRaw;
    if($id<=0 || !preg_match('/^\\d+$/', $minRaw) || $min<0){
        $_SESSION['inventory_message']="Invalid minimum stock limit.";
        $_SESSION['inventory_type']="error";
    } else {
        $stmt=mysqli_prepare($conn,"UPDATE ingredients SET minimum_stock=? WHERE id=?");
        if($stmt){
            mysqli_stmt_bind_param($stmt,"ii",$min,$id);
            if(mysqli_stmt_execute($stmt)){
                $_SESSION['inventory_message']="Minimum stock limit updated.";
                $_SESSION['inventory_type']="success";
            }else{
                $_SESSION['inventory_message']="Unable to update minimum stock: ".mysqli_stmt_error($stmt);
                $_SESSION['inventory_type']="error";
            }
            mysqli_stmt_close($stmt);
        } else {
            $_SESSION['inventory_message']="Unable to prepare minimum stock update: ".mysqli_error($conn);
            $_SESSION['inventory_type']="error";
        }
    }
    header("Location: inventory_management.php?role=" . ($isCashier ? "cashier" : "admin")); exit();
}

/* =========================================================
   STOCK IN / STOCK OUT
   ========================================================= */

if ($isAdmin && isset($_POST['action'])) {

    $id = (int)($_POST['id'] ?? 0);
    $qtyRaw = trim((string)($_POST['change_qty'] ?? ''));
    $qty = (int)$qtyRaw;
    $action = $_POST['action'] ?? '';

    if ($id <= 0 || !preg_match('/^\\d+$/', $qtyRaw) || $qty <= 0) {

        $_SESSION['inventory_message'] = "Please enter a valid quantity.";
        $_SESSION['inventory_type'] = "error";

    } else {

        $ingredientCheck = mysqli_prepare(
            $conn,
            "SELECT ingredient_name, current_stock, unit
             FROM ingredients
             WHERE id = ?
             LIMIT 1"
        );

        if (!$ingredientCheck) {
            $_SESSION['inventory_message'] = "Unable to check ingredient: " . mysqli_error($conn);
            $_SESSION['inventory_type'] = "error";
            header("Location: inventory_management.php?role=" . ($isCashier ? "cashier" : "admin"));
            exit();
        }

        mysqli_stmt_bind_param(
            $ingredientCheck,
            "i",
            $id
        );

        if (!mysqli_stmt_execute($ingredientCheck)) {
            $_SESSION['inventory_message'] = "Unable to read ingredient: " . mysqli_stmt_error($ingredientCheck);
            $_SESSION['inventory_type'] = "error";
            mysqli_stmt_close($ingredientCheck);
            header("Location: inventory_management.php?role=" . ($isCashier ? "cashier" : "admin"));
            exit();
        }

        $ingredientResult = mysqli_stmt_get_result($ingredientCheck);
        $ingredient = $ingredientResult ? mysqli_fetch_assoc($ingredientResult) : null;
        mysqli_stmt_close($ingredientCheck);

        if (!$ingredient) {

            $_SESSION['inventory_message'] = "Ingredient not found.";
            $_SESSION['inventory_type'] = "error";

        } elseif ($action === 'add') {

            mysqli_begin_transaction($conn);

            try {

                $update = mysqli_prepare(
                    $conn,
                    "UPDATE ingredients
                     SET current_stock = current_stock + ?
                     WHERE id = ?"
                );

                if (!$update) {
                    throw new Exception("Unable to prepare ingredient stock update: " . mysqli_error($conn));
                }

                mysqli_stmt_bind_param(
                    $update,
                    "ii",
                    $qty,
                    $id
                );

                if (!mysqli_stmt_execute($update)) {
                    $error = mysqli_stmt_error($update);
                    mysqli_stmt_close($update);
                    throw new Exception("Unable to update ingredient stock: " . $error);
                }

                mysqli_stmt_close($update);

                $history = mysqli_prepare(
                    $conn,
                    "INSERT INTO stock_in
                    (ingredient_id, quantity, received_by, date_received)
                    VALUES (?, ?, ?, NOW())"
                );

                if (!$history) {
                    throw new Exception("Unable to prepare Stock In history: " . mysqli_error($conn));
                }

                $receivedBy = trim((string)($_SESSION['name'] ?? 'Admin'));
                if ($receivedBy === '') {
                    $receivedBy = 'Admin';
                }

                mysqli_stmt_bind_param(
                    $history,
                    "iis",
                    $id,
                    $qty,
                    $receivedBy
                );

                if (!mysqli_stmt_execute($history)) {
                    $error = mysqli_stmt_error($history);
                    mysqli_stmt_close($history);
                    throw new Exception("Unable to save Stock In history: " . $error);
                }

                mysqli_stmt_close($history);

                // Detailed audit history: record the exact stock before and after Stock In.
                $previousStock = (int)$ingredient['current_stock'];
                $newStock = $previousStock + $qty;
                $historyAction = 'Stock In';
                $userId = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])
                    ? (int)$_SESSION['user_id']
                    : null;

                if ($userId === null) {
                    $detailHistory = mysqli_prepare(
                        $conn,
                        "INSERT INTO ingredient_history
                        (ingredient_id, action, quantity, previous_stock, new_stock, user_id, created_at)
                        VALUES (?, ?, ?, ?, ?, NULL, NOW())"
                    );

                    if (!$detailHistory) {
                        throw new Exception("Unable to prepare detailed Stock In history: " . mysqli_error($conn));
                    }

                    mysqli_stmt_bind_param(
                        $detailHistory,
                        "isiii",
                        $id, $historyAction, $qty, $previousStock, $newStock
                    );
                } else {
                    $detailHistory = mysqli_prepare(
                        $conn,
                        "INSERT INTO ingredient_history
                        (ingredient_id, action, quantity, previous_stock, new_stock, user_id, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())"
                    );

                    if (!$detailHistory) {
                        throw new Exception("Unable to prepare detailed Stock In history: " . mysqli_error($conn));
                    }

                    mysqli_stmt_bind_param(
                        $detailHistory,
                        "isiiii",
                        $id, $historyAction, $qty, $previousStock, $newStock, $userId
                    );
                }

                if (!mysqli_stmt_execute($detailHistory)) {
                    $error = mysqli_stmt_error($detailHistory);
                    mysqli_stmt_close($detailHistory);
                    throw new Exception("Unable to save detailed Stock In history: " . $error);
                }

                mysqli_stmt_close($detailHistory);

                mysqli_commit($conn);

                $_SESSION['inventory_message'] =
                    htmlspecialchars($ingredient['ingredient_name']) .
                    " stock increased by " .
                    number_format($qty) .
                    " " .
                    htmlspecialchars($ingredient['unit']) .
                    ".";

                $_SESSION['inventory_type'] = "success";

            } catch (Exception $e) {

                mysqli_rollback($conn);

                $_SESSION['inventory_message'] = $e->getMessage();
                $_SESSION['inventory_type'] = "error";
            }

        } elseif ($action === 'subtract') {

            $currentStock = (int)$ingredient['current_stock'];

            if ($qty > $currentStock) {

                $_SESSION['inventory_message'] =
                    "Not enough stock. Current stock is " .
                    number_format($currentStock) .
                    " " .
                    $ingredient['unit'] .
                    ".";

                $_SESSION['inventory_type'] = "error";

            } else {

                mysqli_begin_transaction($conn);

                try {

                    $update = mysqli_prepare(
                        $conn,
                        "UPDATE ingredients
                         SET current_stock = current_stock - ?
                         WHERE id = ?
                         AND current_stock >= ?"
                    );

                    if (!$update) {
                        throw new Exception("Unable to prepare ingredient stock update: " . mysqli_error($conn));
                    }

                    mysqli_stmt_bind_param(
                        $update,
                        "iii",
                        $qty,
                        $id,
                        $qty
                    );

                    if (!mysqli_stmt_execute($update)) {
                        $error = mysqli_stmt_error($update);
                        mysqli_stmt_close($update);
                        throw new Exception("Unable to update ingredient stock: " . $error);
                    }

                    if (mysqli_stmt_affected_rows($update) <= 0) {
                        mysqli_stmt_close($update);
                        throw new Exception("Stock Out failed. Please check the available stock.");
                    }

                    mysqli_stmt_close($update);

                    $history = mysqli_prepare(
                        $conn,
                        "INSERT INTO stock_out
                        (ingredient_id, order_id, category, quantity, reason, released_by, date_out)
                        VALUES (?, NULL, 'MANUAL', ?, 'Manual stock out', ?, NOW())"
                    );

                    if (!$history) {
                        throw new Exception("Unable to prepare Stock Out history: " . mysqli_error($conn));
                    }

                    $releasedBy = trim((string)($_SESSION['name'] ?? 'Admin'));
                    if ($releasedBy === '') {
                        $releasedBy = 'Admin';
                    }

                    mysqli_stmt_bind_param(
                        $history,
                        "iis",
                        $id,
                        $qty,
                        $releasedBy
                    );

                    if (!mysqli_stmt_execute($history)) {
                        $error = mysqli_stmt_error($history);
                        mysqli_stmt_close($history);
                        throw new Exception("Unable to save Stock Out history: " . $error);
                    }

                    mysqli_stmt_close($history);

                    // Detailed audit history: record the exact stock before and after Stock Out.
                    $previousStock = $currentStock;
                    $newStock = $currentStock - $qty;
                    $historyAction = 'Stock Out';
                    $userId = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])
                        ? (int)$_SESSION['user_id']
                        : null;

                    if ($userId === null) {
                        $detailHistory = mysqli_prepare(
                            $conn,
                            "INSERT INTO ingredient_history
                            (ingredient_id, action, quantity, previous_stock, new_stock, user_id, created_at)
                            VALUES (?, ?, ?, ?, ?, NULL, NOW())"
                        );

                        if (!$detailHistory) {
                            throw new Exception("Unable to prepare detailed Stock Out history: " . mysqli_error($conn));
                        }

                        mysqli_stmt_bind_param(
                            $detailHistory,
                            "isiii",
                            $id, $historyAction, $qty, $previousStock, $newStock
                        );
                    } else {
                        $detailHistory = mysqli_prepare(
                            $conn,
                            "INSERT INTO ingredient_history
                            (ingredient_id, action, quantity, previous_stock, new_stock, user_id, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())"
                        );

                        if (!$detailHistory) {
                            throw new Exception("Unable to prepare detailed Stock Out history: " . mysqli_error($conn));
                        }

                        mysqli_stmt_bind_param(
                            $detailHistory,
                            "isiiii",
                            $id, $historyAction, $qty, $previousStock, $newStock, $userId
                        );
                    }

                    if (!mysqli_stmt_execute($detailHistory)) {
                        $error = mysqli_stmt_error($detailHistory);
                        mysqli_stmt_close($detailHistory);
                        throw new Exception("Unable to save detailed Stock Out history: " . $error);
                    }

                    mysqli_stmt_close($detailHistory);

                    mysqli_commit($conn);

                    $_SESSION['inventory_message'] =
                        htmlspecialchars($ingredient['ingredient_name']) .
                        " stock decreased by " .
                        number_format($qty) .
                        " " .
                        htmlspecialchars($ingredient['unit']) .
                        ".";

                    $_SESSION['inventory_type'] = "success";

                } catch (Exception $e) {

                    mysqli_rollback($conn);

                    $_SESSION['inventory_message'] = $e->getMessage();
                    $_SESSION['inventory_type'] = "error";
                }
            }

        } else {

            $_SESSION['inventory_message'] = "Invalid inventory action.";
            $_SESSION['inventory_type'] = "error";
        }
    }

    header("Location: inventory_management.php?role=" . ($isCashier ? "cashier" : "admin"));
    exit();
}


/* =========================================================
   DISPLAY MESSAGE
   ========================================================= */

if (isset($_SESSION['inventory_message'])) {

    $message = $_SESSION['inventory_message'];
    $messageType = $_SESSION['inventory_type'] ?? "success";

    unset($_SESSION['inventory_message']);
    unset($_SESSION['inventory_type']);
}


/* =========================================================
   GET INGREDIENT CATEGORIES
   ========================================================= */

$categories = mysqli_query(
    $conn,
    "SELECT DISTINCT category
     FROM ingredients
     WHERE category IS NOT NULL
     AND category != ''
     ORDER BY category ASC"
);

if (!$categories) {
    die("Unable to load ingredient categories: " . mysqli_error($conn));
}

/* =========================================================
   INVENTORY HISTORY LOG
   Compatible with databases with or without the optional
   stock_in.supply_id / stock_out.supply_id columns.
   ========================================================= */
$historyRows = [];
$historyError = '';

$hasStockInSupplyId = false;
$hasStockOutSupplyId = false;

$checkColumn = mysqli_query($conn, "SHOW COLUMNS FROM stock_in LIKE 'supply_id'");
if ($checkColumn !== false) {
    $hasStockInSupplyId = mysqli_num_rows($checkColumn) > 0;
    mysqli_free_result($checkColumn);
}

$checkColumn = mysqli_query($conn, "SHOW COLUMNS FROM stock_out LIKE 'supply_id'");
if ($checkColumn !== false) {
    $hasStockOutSupplyId = mysqli_num_rows($checkColumn) > 0;
    mysqli_free_result($checkColumn);
}

if ($hasStockInSupplyId) {
    $stockInSql = "
        SELECT si.stockin_id AS history_id,
               si.ingredient_id,
               si.supply_id,
               COALESCE(i.ingredient_name, s.supply_name) AS item_name,
               COALESCE(i.unit, s.unit, '') AS item_unit,
               si.quantity,
               si.date_received AS action_date,
               'Stock In' AS action_type,
               'N/A' AS order_id
        FROM stock_in si
        LEFT JOIN ingredients i ON i.id = si.ingredient_id
        LEFT JOIN supplies s ON s.id = si.supply_id
        WHERE si.ingredient_id IS NOT NULL OR si.supply_id IS NOT NULL";
} else {
    $stockInSql = "
        SELECT si.stockin_id AS history_id,
               si.ingredient_id,
               NULL AS supply_id,
               i.ingredient_name AS item_name,
               i.unit AS item_unit,
               si.quantity,
               si.date_received AS action_date,
               'Stock In' AS action_type,
               'N/A' AS order_id
        FROM stock_in si
        LEFT JOIN ingredients i ON i.id = si.ingredient_id
        WHERE si.ingredient_id IS NOT NULL";
}

if ($hasStockOutSupplyId) {
    $stockOutSql = "
        SELECT so.stockout_id AS history_id,
               so.ingredient_id,
               so.supply_id,
               COALESCE(i.ingredient_name, s.supply_name) AS item_name,
               COALESCE(i.unit, s.unit, '') AS item_unit,
               so.quantity,
               so.date_out AS action_date,
               'Stock Out' AS action_type,
               COALESCE(CAST(so.order_id AS CHAR), 'N/A') AS order_id
        FROM stock_out so
        LEFT JOIN ingredients i ON i.id = so.ingredient_id
        LEFT JOIN supplies s ON s.id = so.supply_id
        WHERE so.ingredient_id IS NOT NULL OR so.supply_id IS NOT NULL";
} else {
    $stockOutSql = "
        SELECT so.stockout_id AS history_id,
               so.ingredient_id,
               NULL AS supply_id,
               i.ingredient_name AS item_name,
               i.unit AS item_unit,
               so.quantity,
               so.date_out AS action_date,
               'Stock Out' AS action_type,
               COALESCE(CAST(so.order_id AS CHAR), 'N/A') AS order_id
        FROM stock_out so
        LEFT JOIN ingredients i ON i.id = so.ingredient_id
        WHERE so.ingredient_id IS NOT NULL";
}

$historySql = "($stockInSql) UNION ALL ($stockOutSql) ORDER BY action_date DESC, history_id DESC";
$historyResult = mysqli_query($conn, $historySql);

if (!$historyResult) {
    $historyError = mysqli_error($conn);
} else {
    while ($h = mysqli_fetch_assoc($historyResult)) {
        $timestamp = strtotime((string)$h['action_date']);
        $dateKey = $timestamp ? date('Y-m-d', $timestamp) : 'unknown';

        if (!isset($historyRows[$dateKey])) {
            $historyRows[$dateKey] = [
                'action_date' => $h['action_date'],
                'order_ids' => [],
                'movements' => 0,
                'stock_in' => 0,
                'stock_out' => 0,
                'items' => [],
                'transactions' => []
            ];
        }

        $historyRows[$dateKey]['movements']++;
        if ($h['action_type'] === 'Stock In') {
            $historyRows[$dateKey]['stock_in']++;
        } else {
            $historyRows[$dateKey]['stock_out']++;
        }

        $orderId = trim((string)($h['order_id'] ?? ''));
        if ($orderId !== '' && $orderId !== 'N/A' && $orderId !== '0') {
            $historyRows[$dateKey]['order_ids'][$orderId] = true;
        }

        $itemType = !empty($h['supply_id']) ? 'supply' : 'ingredient';
        $itemId = !empty($h['supply_id']) ? $h['supply_id'] : ($h['ingredient_id'] ?? 'x');
        $itemKey = $h['action_type'] . '|' . $itemType . '|' . $itemId;

        if (!isset($historyRows[$dateKey]['items'][$itemKey])) {
            $historyRows[$dateKey]['items'][$itemKey] = [
                'name' => $h['item_name'] ?? 'Unknown Item',
                'unit' => $h['item_unit'] ?? '',
                'action_type' => $h['action_type'],
                'quantity' => 0,
                'order_ids' => [],
                'last_date' => $h['action_date']
            ];
        }

        $historyRows[$dateKey]['items'][$itemKey]['quantity'] += (float)$h['quantity'];
        if ($orderId !== '' && $orderId !== 'N/A' && $orderId !== '0') {
            $historyRows[$dateKey]['items'][$itemKey]['order_ids'][$orderId] = true;
        }
        $historyRows[$dateKey]['items'][$itemKey]['last_date'] = $h['action_date'];
    }

    uasort($historyRows, function ($a, $b) {
        return strtotime((string)$b['action_date']) <=> strtotime((string)$a['action_date']);
    });
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Inventory Management - BLACKHABIT</title>

    <link
        rel="icon"
        type="image/x-icon"
        href="favicon_io/favicon.ico"
    >

    <link
        rel="stylesheet"
        href="style.css?v=<?php echo time(); ?>"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
    >

    <style>

        .inventory-search-panel{
            display:flex;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
            margin-bottom:22px;
            padding:14px;
            background:#151515;
            border:1px solid #2e2e2e;
            border-radius:10px;
        }

        .inventory-search-box{
            position:relative;
            flex:1 1 320px;
            min-width:220px;
        }

        .inventory-search-box i{
            position:absolute;
            left:13px;
            top:50%;
            transform:translateY(-50%);
            color:#888;
        }

        .inventory-search-box input{
            width:100%;
            min-height:42px;
            box-sizing:border-box;
            padding:10px 12px 10px 38px;
            border:1px solid #333;
            border-radius:8px;
            background:#1e1e1e;
            color:#fff;
            outline:none;
            font-family:Poppins,sans-serif;
            font-size:13px;
        }

        .inventory-search-box input:focus{
            border-color:#c59d5f;
        }

        .inventory-search-btn,
        .inventory-reset-btn{
            min-height:42px;
            border:0;
            border-radius:8px;
            padding:0 16px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:7px;
            cursor:pointer;
            font-family:Poppins,sans-serif;
            font-size:12px;
            font-weight:700;
            white-space:nowrap;
        }

        .inventory-search-btn{
            background:#c59d5f;
            color:#000;
        }

        .inventory-reset-btn{
            background:#333;
            color:#fff;
        }

        .inventory-search-btn:hover,
        .inventory-reset-btn:hover{
            opacity:.88;
        }

        .inventory-search-result{
            color:#888;
            font-size:11px;
            min-width:100px;
        }

        .inventory-message {
            margin-bottom: 20px;
            padding: 14px 18px;
            border-radius: 10px;
            font-weight: 500;
        }

        .inventory-message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .inventory-message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .inventory-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .stock-form {
            display: flex;
            align-items: center;
            gap: 5px;
            margin: 0;
        }

        .stock-qty {
            width: 75px;
            padding: 7px 8px;
            border: 1px solid #ddd;
            border-radius: 7px;
            font-family: Poppins, sans-serif;
            outline: none;
        }

        .stock-qty:focus {
            border-color: #c59d5f;
        }

        .stock-btn {
            border: none;
            color: #fff;
            padding: 7px 10px;
            border-radius: 7px;
            cursor: pointer;
            font-family: Poppins, sans-serif;
            font-weight: 600;
        }

        .stock-in-btn {
            background: #28a745;
        }

        .stock-out-btn {
            background: #dc3545;
        }

        .stock-btn:hover {
            opacity: 0.88;
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .badge-warning {
            background: #fff3cd;
            color: #856404;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .badge-danger {
            background: #f8d7da;
            color: #721c24;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .inventory-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .ingredient-add-bar {
            display: flex;
            justify-content: flex-end;
            margin: 0 0 20px;
        }

        .add-ingredient-btn {
            border: 0;
            border-radius: 8px;
            padding: 11px 17px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            background: #c59d5f;
            color: #111;
            font-family: Poppins, sans-serif;
            font-size: 12px;
            font-weight: 700;
        }

        .add-ingredient-btn:hover {
            opacity: .88;
        }

        .ingredient-modal {
            display: none;
            position: fixed;
            z-index: 10000;
            inset: 0;
            background: rgba(0,0,0,.78);
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .ingredient-modal.show {
            display: flex;
        }

        .ingredient-modal-box {
            width: min(520px, 96vw);
            background: #151515;
            border: 1px solid #c59d5f;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,.65);
            overflow: hidden;
        }

        .ingredient-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 17px 20px;
            border-bottom: 1px solid #2d2d2d;
        }

        .ingredient-modal-title {
            margin: 0;
            color: #c59d5f;
            font-size: 17px;
        }

        .ingredient-modal-close {
            width: 34px;
            height: 34px;
            border: 0;
            border-radius: 7px;
            background: #292929;
            color: #fff;
            cursor: pointer;
            font-size: 16px;
        }

        .ingredient-modal-close:hover {
            background: #c59d5f;
            color: #111;
        }

        .ingredient-modal-body {
            padding: 20px;
        }

        .ingredient-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .ingredient-form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .ingredient-form-group.full {
            grid-column: 1 / -1;
        }

        .ingredient-form-group label {
            color: #aaa;
            font-size: 12px;
            font-weight: 600;
        }

        .ingredient-form-group input {
            width: 100%;
            box-sizing: border-box;
            padding: 10px 12px;
            border: 1px solid #333;
            border-radius: 7px;
            background: #101010;
            color: #fff;
            font-family: Poppins, sans-serif;
            outline: none;
        }

        .ingredient-form-group input:focus {
            border-color: #c59d5f;
        }

        .ingredient-modal-footer {
            padding: 0 20px 20px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .ingredient-cancel-btn,
        .ingredient-save-btn {
            border: 0;
            border-radius: 7px;
            padding: 10px 16px;
            cursor: pointer;
            font-family: Poppins, sans-serif;
            font-weight: 700;
            font-size: 12px;
        }

        .ingredient-cancel-btn {
            background: #333;
            color: #fff;
        }

        .ingredient-save-btn {
            background: #c59d5f;
            color: #111;
        }

        @media (max-width: 600px) {
            .ingredient-add-bar {
                justify-content: stretch;
            }

            .add-ingredient-btn {
                width: 100%;
            }

            .ingredient-form-grid {
                grid-template-columns: 1fr;
            }

            .ingredient-form-group.full {
                grid-column: auto;
            }
        }

        .tab-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .tab-btn {
            border: none;
            padding: 11px 18px;
            border-radius: 8px;
            background: #eee;
            cursor: pointer;
            font-family: Poppins, sans-serif;
            font-weight: 600;
        }

        .tab-btn.active {
            background: #c59d5f;
            color: #fff;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        @media (max-width: 900px) {

            .table-container {
                overflow-x: auto;
            }

            .inventory-actions {
                min-width: 230px;
            }

        }

        @media (max-width: 600px) {

            .inventory-search-panel{
                align-items:stretch;
            }

            .inventory-search-box,
            .inventory-search-btn,
            .inventory-reset-btn{
                flex:1 1 100%;
            }

            .inventory-search-result{
                min-width:0;
            }

            .stock-form {
                width: 100%;
            }

            .stock-qty {
                width: 85px;
            }

        }

        /* =========================================================
           INGREDIENT HISTORY LOG
           ========================================================= */

        .history-summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
            width: 100%;
        }

        .history-title {
            margin: 0;
            font-size: 18px;
        }

        .history-subtitle {
            margin: 4px 0 0;
            color: #888;
            font-size: 13px;
        }

        .history-table {
            min-width: 850px;
        }

        .history-table th,
        .history-table td {
            vertical-align: middle;
            white-space: nowrap;
        }

        .history-action {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .history-in {
            background: #d4edda;
            color: #155724;
        }

        .history-mixed {background:#e9dfc8;color:#6d531f}
.history-out {
            background: #f8d7da;
            color: #721c24;
        }

        .history-qty {
            font-weight: 700;
        }

        .history-category {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }

        .order-category {
            background: #e8defc;
            color: #6241a8;
        }

        .stockin-category {
            background: #d4edda;
            color: #155724;
        }

        .manual-category {
            background: #e2e3e5;
            color: #383d41;
        }

        .history-date {
            color: #666;
            font-size: 13px;
        }

        .history-empty {
            text-align: center;
            padding: 45px 20px;
            color: #999;
        }

        .history-empty i {
            display: block;
            font-size: 38px;
            margin-bottom: 10px;
        }

        .history-error {
            padding: 16px;
            border-radius: 10px;
            background: #f8d7da;
            color: #721c24;
        }

        .history-details-btn{
            background:#151515;
            color:#c59d5f;
            border:1px solid #c59d5f;
            border-radius:7px;
            padding:8px 12px;
            cursor:pointer;
            font-family:Poppins,sans-serif;
            font-size:12px;
            font-weight:600;
            display:inline-flex;
            align-items:center;
            gap:6px;
        }
        .history-details-btn:hover{background:#c59d5f;color:#111}
        .history-modal{
            display:none;
            position:fixed;
            z-index:9999;
            inset:0;
            background:rgba(0,0,0,.78);
            align-items:center;
            justify-content:center;
            padding:20px;
        }
        .history-modal.show{display:flex}
        .history-modal-box{
            width:min(850px,96vw);
            max-height:88vh;
            overflow-y:auto;
            background:#151515;
            border:1px solid #c59d5f;
            border-radius:12px;
            box-shadow:0 20px 60px rgba(0,0,0,.65);
        }
        .history-modal-header{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:15px;
            padding:18px 20px;
            border-bottom:1px solid #2d2d2d;
            position:sticky;
            top:0;
            background:#151515;
            z-index:2;
        }
        .history-modal-title{
            margin:0;
            color:#c59d5f;
            font-size:17px;
            font-weight:700;
        }
        .history-modal-close{
            width:34px;
            height:34px;
            border:0;
            border-radius:7px;
            background:#292929;
            color:#fff;
            cursor:pointer;
            font-size:16px;
        }
        .history-modal-close:hover{background:#c59d5f;color:#111}
        .history-modal-body{padding:20px}
        .history-modal-summary{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:10px;
            margin-bottom:18px;
        }
        .history-summary-card{
            background:#1d1d1d;
            border:1px solid #303030;
            border-radius:8px;
            padding:12px;
        }
        .history-summary-card span{
            display:block;
            color:#888;
            font-size:10px;
            text-transform:uppercase;
            margin-bottom:5px;
        }
        .history-summary-card strong{color:#fff;font-size:14px}
        .history-section-title{
            color:#c59d5f;
            font-size:13px;
            font-weight:700;
            margin:18px 0 9px;
        }
        .history-order-list{display:flex;gap:8px;flex-wrap:wrap}
        .history-order-chip{
            background:#252525;
            border:1px solid #444;
            border-radius:7px;
            padding:7px 10px;
            color:#ddd;
            font-size:12px;
        }
        .history-item-grid{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
            gap:8px;
        }
        .history-item-card{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            background:#1b1b1b;
            border:1px solid #292929;
            border-radius:7px;
            padding:11px 12px;
            color:#ddd;
            font-size:12px;
        }
        .history-item-card strong{color:#fff;white-space:nowrap}
        .history-modal-footer{
            padding:0 20px 18px;
            display:flex;
            justify-content:flex-end;
        }
        .history-modal-footer button{
            background:#c59d5f;
            color:#111;
            border:0;
            border-radius:7px;
            padding:9px 16px;
            cursor:pointer;
            font-family:Poppins,sans-serif;
            font-weight:700;
        }
        @media(max-width:650px){
            .history-modal-summary{grid-template-columns:repeat(2,minmax(0,1fr))}
            .history-modal{padding:10px}
            .history-modal-body{padding:15px}
        }


        /* =========================================================
           INVENTORY TABLE UI - MATCHES THE BLACKHABIT REFERENCE
           ========================================================= */
        .inventory-toolbar{
            display:flex;align-items:center;justify-content:space-between;gap:18px;
            margin:0 0 18px;padding:0 2px;
        }
        .inventory-section-title{margin:0;color:#fff;font-size:21px;font-weight:700;}
        .inventory-section-title i{color:#c59d5f;margin-right:8px;}
        .inventory-section-subtitle{margin:5px 0 0;color:#888;font-size:12px;}
        .inventory-table-panel{padding:0;overflow:hidden;}
        .inventory-main-table{width:100%;min-width:900px;border-collapse:collapse;}
        .inventory-main-table th{background:#202020;color:#c59d5f;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.2px;padding:15px 14px;text-align:left;}
        .inventory-main-table td{padding:13px 14px;border-bottom:1px solid #292929;color:#ddd;font-size:12px;vertical-align:middle;}
        .inventory-main-table tbody tr:nth-child(even){background:#191919;}
        .inventory-main-table tbody tr:hover{background:#202020;}
        .inventory-main-table td:first-child,.inventory-main-table th:first-child{width:42px;text-align:center;color:#aaa;}
        .inventory-main-table td strong{color:#fff;}
        .inventory-adjust-wrap{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
        .inventory-adjust-form{gap:6px;}
        .inventory-adjust-input{width:88px!important;background:#1e1e1e!important;color:#fff!important;border:1px solid #333!important;border-radius:7px!important;padding:9px 10px!important;}
        .inventory-adjust-form .stock-btn{padding:9px 11px;min-width:58px;}
        .inventory-table-footer{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:15px 16px;color:#777;font-size:11px;background:#151515;}
        .inventory-pagination{display:flex;gap:7px;align-items:center;}
        .inventory-page-btn{min-width:34px;height:34px;padding:0 9px;border:1px solid #333;border-radius:7px;background:#202020;color:#ddd;cursor:pointer;font-family:Poppins,sans-serif;font-weight:600;}
        .inventory-page-btn:hover{border-color:#c59d5f;color:#c59d5f;}
        .inventory-page-btn.active{background:#c59d5f;color:#111;border-color:#c59d5f;}
        .inventory-page-btn:disabled{opacity:.35;cursor:not-allowed;}
        .inventory-empty-cell{text-align:center!important;padding:45px 20px!important;color:#777!important;}
        .inventory-empty-cell i{display:block;font-size:32px;color:#c59d5f;margin-bottom:8px;}
        .inventory-toolbar .add-ingredient-btn{flex:none;}
        @media(max-width:900px){
            .inventory-toolbar{align-items:flex-start;flex-direction:column;}
            .inventory-toolbar .add-ingredient-btn{width:100%;}
            .inventory-table-footer{align-items:flex-start;flex-direction:column;}
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


<!-- =========================================================
     SHARED ADMIN SIDEBAR
========================================================= -->

<?php
if ($isAdmin) {
    include "admin_sidebar.php";
} elseif ($isCashier) {
    include "cashier_sidebar.php";
}
?>

<!-- =========================================================
     MAIN
     ========================================================= -->

<div class="main">


    <!-- TOPBAR -->

    <div class="topbar">

        <button
            class="menu-toggle"
            id="menuToggle"
        >

            <i class="fa-solid fa-bars"></i>

        </button>

        <h1>
            Inventory Management
        </h1>

        <div class="profile">

            <i
                class="fa-solid <?php echo $isAdmin ? 'fa-user-shield' : 'fa-circle-user'; ?>"
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
                        ? "Administrator"
                        : "Cashier";
                    ?>
                </span>

            </div>

        </div>

    </div>


    <!-- MESSAGE -->

    <?php if ($message !== '') { ?>

        <div class="inventory-message <?php echo $messageType; ?>">

            <i
                class="fa-solid
                <?php echo $messageType === 'success'
                    ? 'fa-circle-check'
                    : 'fa-circle-exclamation'; ?>"
            ></i>

            <?php echo $message; ?>

        </div>

    <?php } ?>


    <!-- INVENTORY SEARCH -->

    <div class="inventory-search-panel">

        <div class="inventory-search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input
                type="text"
                id="inventorySearch"
                placeholder="Search ingredient or supply..."
                autocomplete="off"
            >
        </div>

        <button
            type="button"
            class="inventory-search-btn"
            id="inventorySearchBtn"
        >
            <i class="fa-solid fa-search"></i>
            Search
        </button>

        <button
            type="button"
            class="inventory-reset-btn"
            id="inventoryResetBtn"
        >
            <i class="fa-solid fa-rotate-left"></i>
            Reset
        </button>

        <span
            id="inventorySearchResult"
            class="inventory-search-result"
        ></span>

    </div>


    <!-- TABS -->

    <div class="tab-buttons">

        <button
            class="tab-btn active"
            data-tab="ingredients"
        >

            <i class="fa-solid fa-seedling"></i>

            Ingredients

        </button>

        <button
            class="tab-btn"
            data-tab="supplies"
        >

            <i class="fa-solid fa-boxes-stacked"></i>

            Supplies

        </button>

        <button
            class="tab-btn"
            data-tab="history"
        >

            <i class="fa-solid fa-clock-rotate-left"></i>

            History Log

        </button>

    </div>


    <!-- =====================================================
         INGREDIENTS - TABLE UI
         ===================================================== -->

    <div class="tab-content active" id="ingredients">

        <div class="inventory-toolbar">
            <div>
                <h2 class="inventory-section-title"><i class="fa-solid fa-seedling"></i> Ingredients</h2>
                <p class="inventory-section-subtitle">Manage ingredient stock, minimum levels, and stock movements.</p>
            </div>

            <?php if ($isAdmin) { ?>
                <button type="button" class="add-ingredient-btn" onclick="openIngredientModal()">
                    <i class="fa-solid fa-plus"></i> Add New Ingredient
                </button>
            <?php } ?>
        </div>

        <div class="panel inventory-table-panel">
            <div class="table-container">
                <table class="product-table inventory-main-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>INGREDIENT</th>
                            <th>CATEGORY</th>
                            <th>STOCK</th>
                            <th>MIN</th>
                            <th>STATUS</th>
                            <?php if ($isAdmin) { ?><th>ADJUST</th><?php } ?>
                        </tr>
                    </thead>
                    <tbody id="inventoryIngredientBody">
                    <?php
                    $allIngredients = mysqli_query(
                        $conn,
                        "SELECT * FROM ingredients ORDER BY ingredient_name ASC"
                    );
                    $rowNumber = 1;
                    if ($allIngredients && mysqli_num_rows($allIngredients) > 0) {
                        while ($item = mysqli_fetch_assoc($allIngredients)) {
                            $currentStock = (int)($item['current_stock'] ?? 0);
                            if (isset($item['minimum_stock'])) {
                                $minStock = (int)$item['minimum_stock'];
                            } elseif (isset($item['min_stock'])) {
                                $minStock = (int)$item['min_stock'];
                            } elseif (isset($item['min'])) {
                                $minStock = (int)$item['min'];
                            } else {
                                $minStock = 0;
                            }

                            if ($currentStock <= 0) {
                                $statusBadge = '<span class="badge-danger">Out of Stock</span>';
                                $statusSearch = 'out of stock';
                            } elseif ($minStock > 0 && $currentStock <= $minStock) {
                                $statusBadge = '<span class="badge-warning">Low Stock</span>';
                                $statusSearch = 'low stock';
                            } else {
                                $statusBadge = '<span class="badge-success">In Stock</span>';
                                $statusSearch = 'in stock';
                            }

                            $ingredientName = (string)($item['ingredient_name'] ?? 'Unnamed');
                            $categoryName = (string)($item['category'] ?? 'Uncategorized');
                    ?>
                        <tr class="inventory-main-row"
                            data-name="<?php echo htmlspecialchars(strtolower($ingredientName)); ?>"
                            data-category="<?php echo htmlspecialchars(strtolower($categoryName)); ?>"
                            data-status="<?php echo htmlspecialchars($statusSearch); ?>">
                            <td class="row-number"></td>
                            <td><strong><?php echo htmlspecialchars($ingredientName); ?></strong></td>
                            <td><?php echo htmlspecialchars($categoryName); ?></td>
                            <td><strong><?php echo number_format($currentStock); ?></strong></td>
                            <td><?php echo number_format($minStock); ?></td>
                            <td><?php echo $statusBadge; ?></td>

                            <?php if ($isAdmin) { ?>
                            <td>
                                <div class="inventory-adjust-wrap">
                                    <form method="POST" class="stock-form inventory-adjust-form">
                                        <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                                        <input type="number" name="change_qty" min="1" step="1" required class="stock-qty inventory-adjust-input" placeholder="Qty">
                                        <button type="submit" name="action" value="add" class="stock-btn stock-in-btn" title="Stock In" onclick="return confirm('Add this quantity to stock?');">
                                            <i class="fa-solid fa-arrow-down"></i> IN
                                        </button>
                                    </form>

                                    <form method="POST" class="stock-form inventory-adjust-form">
                                        <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                                        <input type="number" name="change_qty" min="1" step="1" max="<?php echo $currentStock; ?>" required class="stock-qty inventory-adjust-input" placeholder="Qty">
                                        <button type="submit" name="action" value="subtract" class="stock-btn stock-out-btn" title="Stock Out" onclick="return confirm('Remove this quantity from stock?');">
                                            <i class="fa-solid fa-arrow-up"></i> OUT
                                        </button>
                                    </form>
                                </div>
                            </td>
                            <?php } ?>
                        </tr>
                    <?php
                            $rowNumber++;
                        }
                    } else {
                    ?>
                        <tr><td colspan="<?php echo $isAdmin ? 7 : 6; ?>" class="inventory-empty-cell">
                            <i class="fa-solid fa-seedling"></i> No ingredients found.
                        </td></tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>

            <div class="inventory-table-footer">
                <span id="inventoryPaginationInfo">Showing 0 to 0 of 0 ingredients</span>
                <div class="inventory-pagination" id="inventoryPagination"></div>
            </div>
        </div>
    </div>


    <!-- =====================================================
         SUPPLIES
         ===================================================== -->

    <div class="tab-content" id="supplies">
        <div class="panel">
            <div class="panel-header">
                <h2><i class="fa-solid fa-boxes-stacked"></i> Supplies Inventory</h2>
            </div>
            <div class="table-container">
                <table class="product-table">
                    <thead><tr><th>SUPPLY</th><th>STOCK</th><th>UNIT</th></tr></thead>
                    <tbody>
                    <?php
                    $supplies = mysqli_query($conn, "SELECT * FROM supplies ORDER BY supply_name ASC");
                    if ($supplies && mysqli_num_rows($supplies) > 0) {
                        while ($row = mysqli_fetch_assoc($supplies)) {
                    ?>
                        <tr class="inventory-supply-row" data-inventory-name="<?php echo htmlspecialchars(strtolower((string)($row['supply_name'] ?? ''))); ?>">
                            <td><strong><?php echo htmlspecialchars($row['supply_name'] ?? ''); ?></strong></td>
                            <td><?php echo number_format((int)($row['current_stock'] ?? 0)); ?></td>
                            <td><?php echo htmlspecialchars($row['unit'] ?? ''); ?></td>
                        </tr>
                    <?php } } else { ?>
                        <tr><td colspan="3" class="inventory-empty-cell">No supplies found.</td></tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>


    <!-- =====================================================
         INGREDIENT HISTORY LOG
         ===================================================== -->

    <div class="tab-content" id="history">
        <div class="panel">
            <div class="panel-header">
                <div class="history-summary">
                    <div>
                        <h2 class="history-title"><i class="fa-solid fa-clock-rotate-left"></i> Inventory History Log</h2>
                        <p class="history-subtitle">Complete record of Stock In and Stock Out transactions.</p>
                    </div>
                    <span class="badge-success">Live History</span>
                </div>
            </div>
            <div class="table-container">
                <?php if ($historyError !== '') { ?>
                    <div class="history-error"><i class="fa-solid fa-circle-exclamation"></i> Unable to load inventory history: <?php echo htmlspecialchars($historyError); ?></div>
                <?php } elseif (!empty($historyRows)) { ?>
                    <table class="product-table history-table">
                        <thead><tr><th>DATE</th><th>ACTIVITY</th><th>ORDERS</th><th>MOVEMENTS</th><th>DETAILS</th></tr></thead>
                        <tbody>
                        <?php foreach($historyRows as $index=>$history){
                            $orderIds=array_keys($history['order_ids']);
                            $countOrders=count($orderIds);
                            $historyDate=date('M d, Y',strtotime($history['action_date']));
                            $modalId='historyModal_'.$index;
                            $modalItems=[];
                            foreach($history['items'] as $item){
                                $modalItems[]=['name'=>$item['name']??'Unknown Item','action'=>$item['action_type'],'quantity'=>(float)$item['quantity'],'unit'=>$item['unit']??'','orders'=>array_keys($item['order_ids']??[])];
                            }
                            $modalData=['date'=>$historyDate,'stockIn'=>(int)$history['stock_in'],'stockOut'=>(int)$history['stock_out'],'orders'=>$orderIds,'movements'=>(int)$history['movements'],'items'=>$modalItems];
                            $modalJson=htmlspecialchars(json_encode($modalData, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                        ?>
                            <tr>
                                <td class="history-date"><?php echo htmlspecialchars($historyDate); ?></td>
                                <td><?php if($history['stock_in']>0 && $history['stock_out']>0){ ?><span class="history-action history-mixed"><i class="fa-solid fa-right-left"></i> Stock In / Stock Out</span><?php } elseif($history['stock_in']>0){ ?><span class="history-action history-in"><i class="fa-solid fa-arrow-down"></i> Stock In</span><?php } else { ?><span class="history-action history-out"><i class="fa-solid fa-arrow-up"></i> Stock Out</span><?php } ?></td>
                                <td><strong><?php echo $countOrders; ?> <?php echo $countOrders===1?'Order':'Orders'; ?></strong></td>
                                <td><strong><?php echo (int)$history['movements']; ?></strong> <?php echo (int)$history['movements']===1?'movement':'movements'; ?></td>
                                <td><button type="button" class="history-details-btn" data-history="<?php echo $modalJson; ?>" onclick="openHistoryModal(this)"><i class="fa-solid fa-eye"></i> View Details</button></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <div class="history-empty"><i class="fa-solid fa-clock-rotate-left"></i><p>No inventory history yet.</p><small>Stock In and Stock Out transactions will appear here automatically.</small></div>
                <?php } ?>
            </div>
        </div>
    </div>

<!-- ADD INGREDIENT MODAL -->
<?php if ($isAdmin) { ?>
<div id="ingredientModal" class="ingredient-modal" aria-hidden="true">
    <div class="ingredient-modal-box" role="dialog" aria-modal="true" aria-labelledby="ingredientModalTitle">
        <div class="ingredient-modal-header">
            <h3 id="ingredientModalTitle" class="ingredient-modal-title">Add New Ingredient</h3>
            <button type="button" class="ingredient-modal-close" onclick="closeIngredientModal()" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST">
            <div class="ingredient-modal-body">
                <div class="ingredient-form-grid">
                    <div class="ingredient-form-group full">
                        <label for="ingredientName">Ingredient Name</label>
                        <input type="text" id="ingredientName" name="ingredient_name" maxlength="150" required placeholder="e.g. Coffee Beans">
                    </div>

                    <div class="ingredient-form-group">
                        <label for="ingredientCategory">Category</label>
                        <input type="text" id="ingredientCategory" name="category" maxlength="100" required placeholder="e.g. Coffee">
                    </div>

                    <div class="ingredient-form-group">
                        <label for="ingredientUnit">Unit</label>
                        <input type="text" id="ingredientUnit" name="unit" maxlength="30" required placeholder="e.g. kg, pcs, pack">
                    </div>

                    <div class="ingredient-form-group">
                        <label for="ingredientStock">Initial Stock</label>
                        <input type="number" id="ingredientStock" name="current_stock" min="0" step="1" value="0" required>
                    </div>

                    <div class="ingredient-form-group">
                        <label for="ingredientMinimum">Minimum Stock</label>
                        <input type="number" id="ingredientMinimum" name="minimum_stock" min="0" step="1" value="0" required>
                    </div>
                </div>
            </div>

            <div class="ingredient-modal-footer">
                <button type="button" class="ingredient-cancel-btn" onclick="closeIngredientModal()">Cancel</button>
                <button type="submit" name="add_ingredient" value="1" class="ingredient-save-btn">
                    <i class="fa-solid fa-plus"></i> Add Ingredient
                </button>
            </div>
        </form>
    </div>
</div>
<?php } ?>

<!-- INVENTORY HISTORY MODAL -->
<div id="historyModal" class="history-modal" aria-hidden="true">
    <div class="history-modal-box" role="dialog" aria-modal="true" aria-labelledby="historyModalTitle">
        <div class="history-modal-header">
            <h3 id="historyModalTitle" class="history-modal-title">Inventory History</h3>
            <button type="button" class="history-modal-close" onclick="closeHistoryModal()" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="history-modal-body" id="historyModalBody"></div>
        <div class="history-modal-footer">
            <button type="button" onclick="closeHistoryModal()">Close</button>
        </div>
    </div>
</div>

<script>










/* =========================================================
   ADD INGREDIENT MODAL
   ========================================================= */

function openIngredientModal(){
    const modal = document.getElementById('ingredientModal');
    const name = document.getElementById('ingredientName');
    if(!modal) return;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden','false');
    document.body.style.overflow='hidden';
    if(name) name.focus();
}

function closeIngredientModal(){
    const modal = document.getElementById('ingredientModal');
    if(!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden','true');
    document.body.style.overflow='';
}

/* =========================================================
   TABLE SEARCH + PAGINATION
   ========================================================= */
const inventoryRows = Array.from(document.querySelectorAll('.inventory-main-row'));
let inventoryPage = 1;
const inventoryPerPage = 10;

function renderInventoryTable(){
    const keyword = (inventorySearch ? inventorySearch.value : '').trim().toLowerCase();
    const filtered = inventoryRows.filter(row => {
        const name = row.dataset.name || '';
        const category = row.dataset.category || '';
        const status = row.dataset.status || '';
        return !keyword || name.includes(keyword) || category.includes(keyword) || status.includes(keyword);
    });

    const totalPages = Math.max(1, Math.ceil(filtered.length / inventoryPerPage));
    if(inventoryPage > totalPages) inventoryPage = totalPages;
    const start = (inventoryPage - 1) * inventoryPerPage;
    const end = start + inventoryPerPage;

    inventoryRows.forEach(row => row.style.display = 'none');
    filtered.slice(start,end).forEach((row,index) => {
        row.style.display = '';
        const numberCell = row.querySelector('.row-number');
        if(numberCell) numberCell.textContent = start + index + 1;
    });

    if(inventorySearchResult){
        inventorySearchResult.textContent = keyword ? filtered.length + (filtered.length === 1 ? ' result found.' : ' results found.') : '';
    }

    const info = document.getElementById('inventoryPaginationInfo');
    if(info){
        if(filtered.length === 0) info.textContent = 'Showing 0 to 0 of 0 ingredients';
        else info.textContent = 'Showing ' + (start+1) + ' to ' + Math.min(end,filtered.length) + ' of ' + filtered.length + ' ingredients';
    }

    const pagination = document.getElementById('inventoryPagination');
    if(!pagination) return;
    pagination.innerHTML='';

    const prev=document.createElement('button');
    prev.className='inventory-page-btn'; prev.innerHTML='<i class="fa-solid fa-chevron-left"></i>'; prev.disabled=inventoryPage===1;
    prev.onclick=()=>{if(inventoryPage>1){inventoryPage--;renderInventoryTable();}};
    pagination.appendChild(prev);

    for(let i=1;i<=totalPages;i++){
        const btn=document.createElement('button'); btn.className='inventory-page-btn'+(i===inventoryPage?' active':''); btn.textContent=i;
        btn.onclick=()=>{inventoryPage=i;renderInventoryTable();}; pagination.appendChild(btn);
    }

    const next=document.createElement('button');
    next.className='inventory-page-btn'; next.innerHTML='<i class="fa-solid fa-chevron-right"></i>'; next.disabled=inventoryPage===totalPages;
    next.onclick=()=>{if(inventoryPage<totalPages){inventoryPage++;renderInventoryTable();}};
    pagination.appendChild(next);
}

function runInventorySearch(){
    inventoryPage=1;
    renderInventoryTable();
}

if(inventorySearchBtn) inventorySearchBtn.addEventListener('click',runInventorySearch);
if(inventorySearch){
    inventorySearch.addEventListener('keydown',function(event){if(event.key==='Enter'){event.preventDefault();runInventorySearch();}});
    inventorySearch.addEventListener('input',runInventorySearch);
}
if(inventoryResetBtn){
    inventoryResetBtn.addEventListener('click',function(){
        if(inventorySearch) inventorySearch.value='';
        inventoryPage=1;
        renderInventoryTable();
    });
}

renderInventoryTable();


/* =========================================================
   SHARED SIDEBAR MOBILE TOGGLE
   ========================================================= */

const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');

if (menuToggle && sidebar) {
    menuToggle.addEventListener('click', () => {
        sidebar.classList.toggle('active');

        if (overlay) {
            overlay.classList.toggle('active');
        }
    });
}

if (overlay && sidebar) {
    overlay.addEventListener('click', () => {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
    });
}


if(inventorySearchBtn){
    inventorySearchBtn.addEventListener(
        'click',
        runInventorySearch
    );
}

if(inventorySearch){
    inventorySearch.addEventListener(
        'keydown',
        function(event){
            if(event.key === 'Enter'){
                event.preventDefault();
                runInventorySearch();
            }
        }
    );

    inventorySearch.addEventListener(
        'input',
        function(){
            if(inventorySearch.value.trim() === ''){
                runInventorySearch();
            }
        }
    );
}

if(inventoryResetBtn){
    inventoryResetBtn.addEventListener(
        'click',
        function(){

            if(inventorySearch){
                inventorySearch.value = '';
            }

            document.querySelectorAll(
                '.inventory-ingredient-row, .inventory-supply-row'
            ).forEach(row => {
                row.style.display = '';
            });

            document.querySelectorAll(
                '.ingredient-category-panel'
            ).forEach(panel => {
                panel.style.display = '';
            });

            if(inventorySearchResult){
                inventorySearchResult.textContent = '';
            }
        }
    );
}


/* =========================================================
   INVENTORY TABS
   ========================================================= */

const tabButtons =
    document.querySelectorAll('.tab-btn');

const tabContents =
    document.querySelectorAll('.tab-content');

tabButtons.forEach(button => {

    button.addEventListener(
        'click',
        () => {

            const target =
                button.getAttribute('data-tab');

            tabButtons.forEach(btn => {
                btn.classList.remove('active');
            });

            tabContents.forEach(content => {
                content.classList.remove('active');
            });

            button.classList.add('active');

            const targetContent =
                document.getElementById(target);

            if (targetContent) {
                targetContent.classList.add('active');
            }

        }
    );

});


/* =========================================================
   AUTO HIDE MESSAGE
   ========================================================= */

setTimeout(() => {

    const message =
        document.querySelector('.inventory-message');

    if (message) {

        message.style.transition =
            'opacity 0.5s';

        message.style.opacity = '0';

        setTimeout(() => {
            message.remove();
        }, 500);

    }

}, 4000);


function openHistoryModal(button){
    const modal=document.getElementById('historyModal');
    const body=document.getElementById('historyModalBody');
    const title=document.getElementById('historyModalTitle');
    if(!modal || !body || !button) return;

    let data={};
    try{
        data=JSON.parse(button.getAttribute('data-history') || '{}');
    }catch(e){
        body.innerHTML='<div class="history-error">Unable to read this inventory history.</div>';
        modal.classList.add('show');
        modal.setAttribute('aria-hidden','false');
        return;
    }

    title.textContent='Inventory History — '+(data.date || '');

    const orders=Array.isArray(data.orders) ? data.orders : [];
    const items=Array.isArray(data.items) ? data.items : [];

    let html='';
    html += '<div class="history-modal-summary">';
    html += '<div class="history-summary-card"><span>Date</span><strong>'+escapeHistoryHtml(data.date || '')+'</strong></div>';
    html += '<div class="history-summary-card"><span>Stock In</span><strong>'+Number(data.stockIn || 0)+' transaction'+(Number(data.stockIn || 0)===1?'':'s')+'</strong></div>';
    html += '<div class="history-summary-card"><span>Stock Out</span><strong>'+Number(data.stockOut || 0)+' transaction'+(Number(data.stockOut || 0)===1?'':'s')+'</strong></div>';
    html += '<div class="history-summary-card"><span>Movements</span><strong>'+Number(data.movements || 0)+'</strong></div>';
    html += '</div>';

    html += '<div class="history-section-title">Orders</div>';
    if(orders.length){
        html += '<div class="history-order-list">';
        orders.forEach(function(orderId){
            html += '<span class="history-order-chip">Order '+escapeHistoryHtml(String(orderId))+'</span>';
        });
        html += '</div>';
    }else{
        html += '<div class="history-order-list"><span class="history-order-chip">No order-based stock out</span></div>';
    }

    html += '<div class="history-section-title">Inventory Movements</div>';
    if(items.length){
        html += '<div class="history-item-grid">';
        items.forEach(function(item){
            const qty=Number(item.quantity || 0);
            const sign=item.action==='Stock Out' ? '-' : '+';
            let unitText=item.unit ? ' '+escapeHistoryHtml(item.unit) : '';
            let label=escapeHistoryHtml(item.name || 'Unknown Item')+' — '+escapeHistoryHtml(item.action || '');
            if(Array.isArray(item.orders) && item.orders.length){
                label += ' — Order '+item.orders.map(function(id){return escapeHistoryHtml(String(id));}).join(', ');
            }
            html += '<div class="history-item-card"><span>'+label+'</span><strong>'+sign+qty.toLocaleString()+unitText+'</strong></div>';
        });
        html += '</div>';
    }else{
        html += '<div class="history-order-list"><span class="history-order-chip">No inventory movements recorded.</span></div>';
    }

    body.innerHTML=html;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden','false');
    document.body.style.overflow='hidden';
}

function closeHistoryModal(){
    const modal=document.getElementById('historyModal');
    if(!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden','true');
    document.body.style.overflow='';
}

function escapeHistoryHtml(value){
    return String(value)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

document.addEventListener('click',function(event){
    const modal=document.getElementById('historyModal');
    if(modal && event.target===modal){
        closeHistoryModal();
    }
});

document.addEventListener('click',function(event){
    const modal=document.getElementById('ingredientModal');
    if(modal && event.target===modal){
        closeIngredientModal();
    }
});

document.addEventListener('keydown',function(event){
    if(event.key==='Escape'){
        closeHistoryModal();
        closeIngredientModal();
    }
});
</script>

</body>
</html>
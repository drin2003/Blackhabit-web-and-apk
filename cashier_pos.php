<?php
// BLACKHABIT: Cashier uses its own independent session.
session_name('BH_CASHIER_SESSION');
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

if ($role !== 'cashier' && (int)$_SESSION['role'] !== 3) {
    header("Location: index.php");
    exit();
}

include "db.php";


// Safely check whether an optional database column exists.
// This keeps POS compatible with older localhost/GogieHost schemas.
function posColumnExists($conn, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column);
    if ($table === '' || $column === '') return false;

    $sql = "SELECT COUNT(*) AS total
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;

    mysqli_stmt_bind_param($stmt, 'ss', $table, $column);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return false;
    }

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return ((int)($row['total'] ?? 0) > 0);
}

// mysqli_stmt_bind_param requires references when parameters are dynamic.
function posBindDynamic($stmt, $types, array &$values) {
    $params = [$stmt, $types];
    foreach ($values as $key => &$value) {
        $params[] = &$value;
    }
    unset($value);
    return call_user_func_array('mysqli_stmt_bind_param', $params);
}

// Check whether a product is currently available for cashier POS.
// A product is unavailable when its product stock is empty or when any
// required ingredient/supply is at or below its minimum stock.
function productAvailableForPos($conn, $productId, $productStock) {
    if ((int)$productStock <= 0) {
        return false;
    }

    // Required ingredients for this product.
    $stmt = mysqli_prepare($conn, "
        SELECT i.current_stock, i.minimum_stock, r.regular_qty, r.large_qty
        FROM recipes r
        INNER JOIN ingredients i ON i.id = r.ingredient_id
        WHERE r.product_id = ?
    ");
    if (!$stmt) return true;

    mysqli_stmt_bind_param($stmt, 'i', $productId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $required = max((float)($row['regular_qty'] ?? 0), (float)($row['large_qty'] ?? 0));
        if ((float)$row['current_stock'] <= (float)$row['minimum_stock'] ||
            ($required > 0 && (float)$row['current_stock'] < $required)) {
            mysqli_stmt_close($stmt);
            return false;
        }
    }
    mysqli_stmt_close($stmt);

    // Required supplies for this product.
    $stmt = mysqli_prepare($conn, "
        SELECT s.current_stock, s.minimum_stock, ps.regular_qty, ps.large_qty
        FROM product_supplies ps
        INNER JOIN supplies s ON s.id = ps.supply_id
        WHERE ps.product_id = ?
    ");
    if (!$stmt) return true;

    mysqli_stmt_bind_param($stmt, 'i', $productId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $required = max((float)($row['regular_qty'] ?? 0), (float)($row['large_qty'] ?? 0));
        if ((float)$row['current_stock'] <= (float)$row['minimum_stock'] ||
            ($required > 0 && (float)$row['current_stock'] < $required)) {
            mysqli_stmt_close($stmt);
            return false;
        }
    }
    mysqli_stmt_close($stmt);

    return true;
}

$order_success = false;
$error_msg = "";

// =========================================================
// SCANNED CUSTOMER ORDER
// =========================================================
$scanned_order_id = (int)($_GET['scanned_order_id'] ?? 0);
$scanned_order = null;
$scanned_items = [];

if ($scanned_order_id > 0) {
    $stmtScanned = mysqli_prepare($conn, "
        SELECT id, receipt_number, total, status, order_type, created_at
        FROM orders
        WHERE id = ?
        LIMIT 1
    ");

    if ($stmtScanned) {
        mysqli_stmt_bind_param($stmtScanned, "i", $scanned_order_id);
        mysqli_stmt_execute($stmtScanned);
        $resultScanned = mysqli_stmt_get_result($stmtScanned);
        $scanned_order = mysqli_fetch_assoc($resultScanned);

        if ($scanned_order) {
            $stmtItems = mysqli_prepare($conn, "
                SELECT oi.*,
                       p.name, p.stock, p.price AS product_price,
                       p.regular_price AS product_regular_price,
                       p.large_price AS product_large_price
                FROM order_items oi
                INNER JOIN products p ON p.id = oi.product_id
                WHERE oi.order_id = ?
                ORDER BY oi.id ASC
            ");

            if ($stmtItems) {
                mysqli_stmt_bind_param($stmtItems, "i", $scanned_order_id);
                mysqli_stmt_execute($stmtItems);
                $resultItems = mysqli_stmt_get_result($stmtItems);

                while ($itemRow = mysqli_fetch_assoc($resultItems)) {
                    // Some older orders may have 0 saved in order_items.price.
                    // Recover the real server-side product price so a scanned
                    // customer order never appears as ₱0.00.
                    $savedPrice = (float)($itemRow['price'] ?? 0);
                    $size = trim((string)($itemRow['size'] ?? ''));
                    $regularPrice = (float)($itemRow['product_regular_price'] ?? 0);
                    $largePrice = (float)($itemRow['product_large_price'] ?? 0);
                    $productPrice = (float)($itemRow['product_price'] ?? 0);

                    if ($savedPrice > 0) {
                        $resolvedPrice = $savedPrice;
                    } elseif (strcasecmp($size, 'Large') === 0 && $largePrice > 0) {
                        $resolvedPrice = $largePrice;
                    } elseif ($regularPrice > 0) {
                        $resolvedPrice = $regularPrice;
                    } else {
                        $resolvedPrice = $productPrice;
                    }

                    $addonsPrice = (float)($itemRow['addons_price'] ?? 0);

                    $scanned_items[] = [
                        'id' => (int)$itemRow['product_id'],
                        'name' => $itemRow['name'],
                        'price' => $resolvedPrice,
                        'base_price' => $resolvedPrice,
                        'size_price' => (float)($itemRow['size_price'] ?? 0),
                        'addons_price' => $addonsPrice,
                        'addons' => (string)($itemRow['addons'] ?? ''),
                        'special_instructions' => (string)($itemRow['special_instructions'] ?? ''),
                        'category' => (string)($itemRow['category'] ?? ''),
                        'flavor' => (string)($itemRow['flavor'] ?? ''),
                        'size' => (string)($itemRow['size'] ?? ''),
                        'sugar' => (string)($itemRow['sugar'] ?? ''),
                        'ice' => (string)($itemRow['ice'] ?? ''),
                        'quantity' => (int)$itemRow['quantity'],
                        'stock' => (int)$itemRow['stock']
                    ];
                }
            }
        }
    }
}


// Authoritative total for a scanned customer order.
// Prefer the order header total; if an old/corrupt record has 0,
// rebuild it from the recovered order-item prices.
if ($scanned_order) {
    $storedScannedTotal = (float)($scanned_order['total'] ?? 0);
    $itemsScannedTotal = 0.0;
    foreach ($scanned_items as $si) {
        $itemsScannedTotal += (float)$si['price'] * (int)$si['quantity'];
        $itemsScannedTotal += (float)$si['addons_price'] * (int)$si['quantity'];
    }
    $scanned_order['display_total'] = $storedScannedTotal > 0
        ? round($storedScannedTotal, 2)
        : round($itemsScannedTotal, 2);
}

/* ===========================
   DAILY SALES
=========================== */
$daily_sales = 0;
$total_orders = 0;
$products_sold = 0;
/* ===========================
   WEEKLY SALES
=========================== */

$weekly_sales = 0;

$q4 = mysqli_query($conn,"
SELECT IFNULL(SUM(total),0) AS total_sales
FROM orders
WHERE YEARWEEK(created_at,1)=YEARWEEK(CURDATE(),1)
AND status='Completed'
");

/* ===========================
   MONTHLY SALES
=========================== */

$monthly_sales = 0;

$q5 = mysqli_query($conn,"
SELECT IFNULL(SUM(total),0) AS total_sales
FROM orders
WHERE MONTH(created_at)=MONTH(CURDATE())
AND YEAR(created_at)=YEAR(CURDATE())
AND status='Completed'
");

if($q5){

    $r = mysqli_fetch_assoc($q5);

    $monthly_sales = $r['total_sales'];

}

if($q4){

    $r = mysqli_fetch_assoc($q4);

    $weekly_sales = $r['total_sales'];

}


/* ===========================
   YEARLY SALES
=========================== */

$yearly_sales = 0;

$q5 = mysqli_query($conn,"
SELECT IFNULL(SUM(total),0) AS total_sales
FROM orders
WHERE YEAR(created_at)=YEAR(CURDATE())
AND status='Completed'
");

if($q5){

    $r = mysqli_fetch_assoc($q5);

    $yearly_sales = $r['total_sales'];

}

// Today's Sales
$q1 = mysqli_query($conn,"
SELECT IFNULL(SUM(total),0) AS total_sales
FROM orders
WHERE DATE(created_at)=CURDATE()
AND status='Completed'
");

if($q1){
    $r = mysqli_fetch_assoc($q1);
    $daily_sales = $r['total_sales'];
}

// Today's Orders
$q2 = mysqli_query($conn,"
SELECT COUNT(*) AS total_orders
FROM orders
WHERE DATE(created_at)=CURDATE()
");

if($q2){
    $r = mysqli_fetch_assoc($q2);
    $total_orders = $r['total_orders'];
}

// Products Sold Today
$q3 = mysqli_query($conn,"
SELECT IFNULL(SUM(quantity),0) AS qty
FROM order_items oi
INNER JOIN orders o
ON oi.order_id=o.id
WHERE DATE(o.created_at)=CURDATE()
");

if($q3){
    $r = mysqli_fetch_assoc($q3);
    $products_sold = $r['qty'];
}


/* ===========================
   PROCESS ORDER
   Cashier creates/accepts the order only.
   Inventory is deducted when the order is completed in view_order.php.
=========================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $cart_items = json_decode($_POST['cart_items'] ?? '[]', true);
    $existing_order_id = (int)($_POST['existing_order_id'] ?? 0);

    // Cashier payment information.
    $payment_method = trim((string)($_POST['payment_method'] ?? 'Cash'));
    $payment_method = in_array($payment_method, ['Cash', 'GCash'], true) ? $payment_method : 'Cash';

    $amount_received = round((float)($_POST['amount_received'] ?? 0), 2);
    $payment_total = 0.0;
    $change_amount = 0.0;

    if (!is_array($cart_items)) $cart_items = [];

    if (!empty($cart_items)) {
        mysqli_begin_transaction($conn);
        try {
            if ($existing_order_id > 0) {
                $check = mysqli_prepare($conn, "SELECT id, status, total FROM orders WHERE id=? FOR UPDATE");
                if (!$check) throw new Exception('Unable to load customer order.');
                mysqli_stmt_bind_param($check, 'i', $existing_order_id);
                mysqli_stmt_execute($check);
                $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
                if (!$existing) throw new Exception('Customer order not found.');
                if (in_array(strtolower(trim((string)$existing['status'])), ['completed','cancelled'], true)) {
                    throw new Exception('This customer order can no longer be processed.');
                }
                $order_id = (int)$existing['id'];

                // Use the customer's stored order total as the payment total.
                $payment_total = round((float)($existing['total'] ?? 0), 2);

                // A QR order is already stored in the database. Do not
                // recalculate or replace its total from the cashier's
                // browser payload. This prevents the ₱0.00 / Invalid order
                // total error when an older order item has a missing price.
                $existingTotal = (float)($existing['total'] ?? 0);
                if ($existingTotal <= 0) {
                    $recalc = 0.0;
                    $totalStmt = mysqli_prepare($conn, "
                        SELECT oi.quantity, oi.price, oi.addons_price, oi.size,
                               p.price AS product_price,
                               p.regular_price AS product_regular_price,
                               p.large_price AS product_large_price
                        FROM order_items oi
                        INNER JOIN products p ON p.id = oi.product_id
                        WHERE oi.order_id = ?
                    ");
                    if (!$totalStmt) throw new Exception('Unable to calculate customer order total.');
                    mysqli_stmt_bind_param($totalStmt, 'i', $order_id);
                    mysqli_stmt_execute($totalStmt);
                    $totalResult = mysqli_stmt_get_result($totalStmt);
                    while ($tr = mysqli_fetch_assoc($totalResult)) {
                        $itemPrice = (float)($tr['price'] ?? 0);
                        if ($itemPrice <= 0) {
                            if (strcasecmp((string)($tr['size'] ?? ''), 'Large') === 0 && (float)$tr['product_large_price'] > 0) {
                                $itemPrice = (float)$tr['product_large_price'];
                            } elseif ((float)$tr['product_regular_price'] > 0) {
                                $itemPrice = (float)$tr['product_regular_price'];
                            } else {
                                $itemPrice = (float)$tr['product_price'];
                            }
                        }
                        $recalc += ($itemPrice + (float)($tr['addons_price'] ?? 0)) * max(1, (int)$tr['quantity']);
                    }
                    mysqli_stmt_close($totalStmt);
                    $recalc = round($recalc, 2);
                    if ($recalc <= 0) throw new Exception('Invalid order total.');

                    $fixTotal = mysqli_prepare($conn, "UPDATE orders SET total=? WHERE id=?");
                    if (!$fixTotal) throw new Exception('Unable to repair customer order total.');
                    mysqli_stmt_bind_param($fixTotal, 'di', $recalc, $order_id);
                    mysqli_stmt_execute($fixTotal);
                    mysqli_stmt_close($fixTotal);
                }

                $update = mysqli_prepare($conn, "UPDATE orders SET status='Processing' WHERE id=? AND status IN ('Pending','Scanned','Processing')");
                mysqli_stmt_bind_param($update, 'i', $order_id);
                mysqli_stmt_execute($update);

                // Record the cashier's payment for a scanned customer order.
                if ($payment_method === 'Cash') {
                    if ($amount_received + 0.0001 < $payment_total) {
                        throw new Exception(
                            'Amount received is not enough. Please enter at least ₱' .
                            number_format($payment_total, 2)
                        );
                    }
                    $change_amount = round($amount_received - $payment_total, 2);
                } else {
                    $amount_received = $payment_total;
                    $change_amount = 0.0;
                }

                if (
                    posColumnExists($conn, 'orders', 'payment_method') &&
                    posColumnExists($conn, 'orders', 'payment_type') &&
                    posColumnExists($conn, 'orders', 'paid_amount') &&
                    posColumnExists($conn, 'orders', 'balance')
                ) {
                    $payUpdate = mysqli_prepare($conn, "
                        UPDATE orders
                        SET payment_method=?, payment_type='Full Payment', paid_amount=?, balance=0
                        WHERE id=?
                    ");
                    if (!$payUpdate) throw new Exception('Unable to save payment information.');
                    mysqli_stmt_bind_param($payUpdate, 'sdi', $payment_method, $amount_received, $order_id);
                    if (!mysqli_stmt_execute($payUpdate)) {
                        throw new Exception('Unable to save payment information: ' . mysqli_stmt_error($payUpdate));
                    }
                    mysqli_stmt_close($payUpdate);
                }
            } else {
                $total_amount = 0;
                foreach ($cart_items as $item) {
                    $qty = max(1, (int)($item['quantity'] ?? 0));
                    $unitPrice = (float)($item['price'] ?? $item['base_price'] ?? 0)
                                   + (float)($item['addons_price'] ?? 0);
                    $total_amount += $unitPrice * $qty;
                }
                if ($total_amount <= 0) throw new Exception('Invalid order total.');

                $payment_total = round($total_amount, 2);

                // A cashier-confirmed walk-in order is sent to the kitchen immediately.
                // Save payment information when the database has the standard payment columns.
                $hasPaymentColumns =
                    posColumnExists($conn, 'orders', 'payment_method') &&
                    posColumnExists($conn, 'orders', 'payment_type') &&
                    posColumnExists($conn, 'orders', 'paid_amount') &&
                    posColumnExists($conn, 'orders', 'balance');

                if ($payment_method === 'Cash') {
                    if ($amount_received + 0.0001 < $payment_total) {
                        throw new Exception(
                            'Amount received is not enough. Please enter at least ₱' .
                            number_format($payment_total, 2)
                        );
                    }
                    $change_amount = round($amount_received - $payment_total, 2);
                } else {
                    $amount_received = $payment_total;
                    $change_amount = 0.0;
                }

                if ($hasPaymentColumns) {
                    $stmt = mysqli_prepare($conn, "
                        INSERT INTO orders
                        (total, status, order_type, payment_method, payment_type, paid_amount, balance, created_at)
                        VALUES (?, 'Processing', 'Over-the-Counter', ?, 'Full Payment', ?, 0, NOW())
                    ");
                    if (!$stmt) throw new Exception('Unable to create order.');
                    mysqli_stmt_bind_param($stmt, 'dsd', $total_amount, $payment_method, $amount_received);
                } else {
                    $stmt = mysqli_prepare($conn, "
                        INSERT INTO orders (total, status, order_type, created_at)
                        VALUES (?, 'Processing', 'Over-the-Counter', NOW())
                    ");
                    if (!$stmt) throw new Exception('Unable to create order.');
                    mysqli_stmt_bind_param($stmt, 'd', $total_amount);
                }

                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception('Unable to create order: ' . mysqli_stmt_error($stmt));
                }
                mysqli_stmt_close($stmt);
                $order_id = mysqli_insert_id($conn);
            }

            // Save order items. Do not deduct stock here; this prevents double deduction.
            // The production database may not have all newer order_items columns.
            // Only insert optional columns that actually exist.
            if ($existing_order_id <= 0) {
                $optionalColumns = [
                    'addons' => 's',
                    'addons_price' => 'd',
                    'size_price' => 'd',
                    'special_instructions' => 's',
                    'flavor' => 's',
                    'size' => 's',
                    'sugar' => 's',
                    'ice' => 's'
                ];

                $availableColumns = [];
                foreach ($optionalColumns as $column => $type) {
                    if (posColumnExists($conn, 'order_items', $column)) {
                        $availableColumns[$column] = $type;
                    }
                }

                // These four columns are the core order_items fields used by the POS.
                $insertColumns = ['order_id', 'product_id', 'quantity', 'price'];
                $insertTypes = 'iiid';

                foreach ($availableColumns as $column => $type) {
                    $insertColumns[] = $column;
                    $insertTypes .= $type;
                }

                $placeholders = implode(',', array_fill(0, count($insertColumns), '?'));
                $saveSql = 'INSERT INTO order_items (' . implode(',', $insertColumns) . ') VALUES (' . $placeholders . ')';
                $save = mysqli_prepare($conn, $saveSql);
                if (!$save) {
                    throw new Exception('Unable to save order items: ' . mysqli_error($conn));
                }

                foreach ($cart_items as $item) {
                    $productId = (int)($item['id'] ?? 0);
                    $quantity = max(1, (int)($item['quantity'] ?? 0));
                    $basePrice = (float)($item['base_price'] ?? $item['price'] ?? 0);
                    $addonsPrice = (float)($item['addons_price'] ?? 0);
                    $sizePrice = (float)($item['size_price'] ?? 0);
                    $addons = (string)($item['addons'] ?? '');
                    $special = (string)($item['special_instructions'] ?? '');
                    $flavor = (string)($item['flavor'] ?? '');
                    $size = (string)($item['size'] ?? '');
                    $sugar = (string)($item['sugar'] ?? '');
                    $ice = (string)($item['ice'] ?? '');

                    if ($productId <= 0) {
                        throw new Exception('Invalid product selected.');
                    }

                    $productCheck = mysqli_prepare($conn, "
                        SELECT id, stock, name, category, price, regular_price, large_price
                        FROM products
                        WHERE id=?
                        FOR UPDATE
                    ");
                    if (!$productCheck) {
                        throw new Exception('Unable to verify product stock.');
                    }

                    mysqli_stmt_bind_param($productCheck, 'i', $productId);
                    if (!mysqli_stmt_execute($productCheck)) {
                        $error = mysqli_stmt_error($productCheck);
                        mysqli_stmt_close($productCheck);
                        throw new Exception('Unable to verify product stock: ' . $error);
                    }

                    $productResult = mysqli_stmt_get_result($productCheck);
                    $product = $productResult ? mysqli_fetch_assoc($productResult) : null;
                    mysqli_stmt_close($productCheck);

                    if (!$product) {
                        throw new Exception('Product not found.');
                    }

                    if ((int)$product['stock'] <= 0 || (int)$product['stock'] < $quantity) {
                        throw new Exception('Not enough product stock for ' . $product['name'] . '.');
                    }

                    $sizeCategories = ['Milk Tea','Milk Tea Creamcheese','Fruit Tea','Non Coffee','Frappes'];
                    $productCategory = (string)($product['category'] ?? '');
                    $postedSize = trim((string)($item['size'] ?? ''));

                    if (in_array($productCategory, $sizeCategories, true)) {
                        if (!in_array($postedSize, ['Regular', 'Large'], true)) {
                            throw new Exception('Please select a size for ' . $product['name'] . '.');
                        }

                        $basePrice = $postedSize === 'Large'
                            ? (float)$product['large_price']
                            : (float)$product['regular_price'];
                        $sizePrice = 0;
                        $size = $postedSize;
                    } else {
                        $basePrice = (float)$product['price'];
                        $sizePrice = 0;
                        $size = '';
                    }

                    $allValues = [
                        'order_id' => $order_id,
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'price' => $basePrice,
                        'addons' => $addons,
                        'addons_price' => $addonsPrice,
                        'size_price' => $sizePrice,
                        'special_instructions' => $special,
                        'flavor' => $flavor,
                        'size' => $size,
                        'sugar' => $sugar,
                        'ice' => $ice
                    ];

                    $values = [];
                    foreach ($insertColumns as $column) {
                        $values[$column] = $allValues[$column] ?? null;
                    }

                    if (!posBindDynamic($save, $insertTypes, $values)) {
                        throw new Exception('Unable to bind order item data: ' . mysqli_stmt_error($save));
                    }

                    if (!mysqli_stmt_execute($save)) {
                        throw new Exception('Unable to save order item: ' . mysqli_stmt_error($save));
                    }
                }

                mysqli_stmt_close($save);
            }

            mysqli_commit($conn);

            // Order was successfully processed.
            // Clear the current order and reset QR mode so the cashier
            // can immediately start a completely new order.
            $order_success = true;
            $scanned_order_id = 0;
            $scanned_order = null;
            $scanned_items = [];
            $last_order_id = $order_id;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $error_msg = $e->getMessage();
        }
    } else {
        $error_msg = 'Order is empty.';
    }
}

/* ===========================
   LOAD PRODUCTS
=========================== */

$product_query=mysqli_query($conn,"SELECT * FROM products ORDER BY name ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Terminal - BLACKHABIT</title>
     <link rel="icon" type="image/x-icon" href="favicon_io/favicon.ico">
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">

    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

<style>
:root{
    --bh-gold:#c59d5f;
    --bh-gold-light:#d8b477;
    --bh-bg:#0f0f0f;
    --bh-panel:#181818;
    --bh-card:#1d1d1d;
    --bh-border:#303030;
    --bh-muted:#8b8b8b;
}

.main{
    padding-bottom:35px;
}

.topbar{
    margin-bottom:18px;
}

.topbar h1{
    margin:0;
}

/* =========================
   SALES SUMMARY
========================= */
.dashboard-cards{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:12px;
    margin:0 0 20px;
}

.card{
    background:var(--bh-card);
    border:1px solid var(--bh-border);
    border-radius:12px;
    padding:16px 17px;
    min-width:0;
    transition:.2s ease;
}

.card:hover{
    border-color:#4a4a4a;
    transform:translateY(-1px);
}

.card h3{
    margin:0;
    color:#8f8f8f;
    font-size:11px;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:.4px;
}

.card h1{
    margin:7px 0 0;
    color:var(--bh-gold-light);
    font-size:24px;
    line-height:1.15;
}

/* =========================
   NOTIFICATIONS
========================= */
.toast{
    padding:11px 14px;
    border-radius:9px;
    margin:0 0 16px;
    font-size:13px;
    font-weight:500;
}

.toast-ok{
    background:#102d19;
    border:1px solid #24733a;
    color:#55d879;
}

.toast-warn{
    background:#351719;
    border:1px solid #713036;
    color:#ff9b9b;
}

/* =========================
   QR SCANNED ORDER
========================= */
.qr-order-banner{
    margin:0 0 14px;
    padding:12px 15px;
    border:1px solid #765d35;
    border-radius:10px;
    background:#211c14;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    color:#fff;
}

.qr-order-banner strong{
    display:block;
    color:var(--bh-gold-light);
    font-size:13px;
}

.qr-order-banner span{
    display:block;
    color:#999;
    font-size:11px;
    margin-top:3px;
}

.qr-cancel{
    color:#aaa;
    text-decoration:none;
    font-size:11px;
    padding:7px 10px;
    border:1px solid #444;
    border-radius:7px;
}

.qr-cancel:hover{
    color:#fff;
    border-color:#777;
}

.scanned-lock-note{
    grid-column:1/-1;
    padding:10px 12px;
    border:1px solid #5c492c;
    border-radius:9px;
    background:#1e1a14;
    color:#b9aa8e;
    font-size:11px;
}

/* =========================
   POS MAIN AREA
========================= */
.pos-wrapper{
    display:grid;
    grid-template-columns:minmax(0,1fr) 360px;
    gap:18px;
    align-items:start;
}

.menu-panel{
    background:var(--bh-panel);
    border:1px solid var(--bh-border);
    border-radius:13px;
    padding:16px;
    min-width:0;
}

.menu-heading{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    margin-bottom:13px;
}

.menu-heading h2{
    margin:0;
    font-size:17px;
}

.menu-heading span{
    color:#777;
    font-size:11px;
}

.search-box{
    position:relative;
    margin-bottom:14px;
}

.search-box i{
    position:absolute;
    left:13px;
    top:50%;
    transform:translateY(-50%);
    color:#777;
    pointer-events:none;
}

.search-box input{
    width:100%;
    box-sizing:border-box;
    background:#111;
    border:1px solid #303030;
    border-radius:9px;
    color:#fff;
    padding:11px 12px 11px 38px;
    outline:none;
    font-family:Poppins,sans-serif;
    font-size:12px;
}

.search-box input:focus{
    border-color:var(--bh-gold);
}

/* Product grid */
.menu-section{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:12px;
    max-height:calc(100vh - 350px);
    min-height:420px;
    overflow-y:auto;
    padding-right:4px;
    align-content:start;
}

.menu-section::-webkit-scrollbar,
.basket-stream::-webkit-scrollbar{
    width:5px;
}

.menu-section::-webkit-scrollbar-thumb,
.basket-stream::-webkit-scrollbar-thumb{
    background:#3b3b3b;
    border-radius:10px;
}

.item-node{
    position:relative;
    background:var(--bh-card);
    border:1px solid var(--bh-border);
    border-radius:11px;
    padding:15px 13px 13px;
    min-height:112px;
    text-align:left;
    cursor:pointer;
    transition:.18s ease;
}

.item-node:hover{
    border-color:var(--bh-gold);
    transform:translateY(-2px);
    background:#202020;
}

.item-node h3{
    margin:0 24px 9px 0;
    color:#f5f5f5;
    font-size:14px;
    line-height:1.3;
}

.item-cost{
    margin:0 0 5px;
    color:var(--bh-gold-light);
    font-size:16px;
    font-weight:700;
}

.item-availability{
    margin:0;
    color:#858585;
    font-size:10px;
}

.add-icon{
    position:absolute;
    right:11px;
    bottom:11px;
    width:27px;
    height:27px;
    display:grid;
    place-items:center;
    border-radius:7px;
    background:#2a241b;
    color:var(--bh-gold-light);
    font-size:11px;
}

.item-node:hover .add-icon{
    background:var(--bh-gold);
    color:#111;
}

.scanned-locked{
    cursor:not-allowed;
    opacity:.42;
    transform:none !important;
}

/* =========================
   CURRENT ORDER
========================= */
.checkout-node{
    background:var(--bh-panel);
    border:1px solid var(--bh-border);
    border-radius:13px;
    padding:17px;
    position:sticky;
    top:15px;
    min-height:470px;
    display:flex;
    flex-direction:column;
}

.checkout-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding-bottom:13px;
    border-bottom:1px solid #292929;
}

.checkout-head h2{
    margin:0;
    font-size:18px;
}

.order-count{
    background:#262019;
    color:var(--bh-gold-light);
    border:1px solid #4d3c24;
    border-radius:20px;
    padding:4px 9px;
    font-size:10px;
    font-weight:600;
}

.basket-stream{
    flex:1;
    min-height:190px;
    max-height:390px;
    overflow-y:auto;
    padding:10px 2px;
}

.empty-basket{
    height:100%;
    min-height:180px;
    display:grid;
    place-items:center;
    text-align:center;
    color:#666;
    font-size:12px;
}

.empty-basket i{
    display:block;
    font-size:27px;
    margin-bottom:8px;
    color:#3e3e3e;
}

.basket-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:11px 2px;
    border-bottom:1px solid #292929;
}

.basket-info{
    min-width:0;
}

.basket-name{
    display:block;
    color:#eee;
    font-size:12px;
    font-weight:600;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    max-width:190px;
}

.basket-price{
    color:#888;
    font-size:10px;
    margin-top:3px;
}

.basket-actions{
    display:flex;
    align-items:center;
    gap:4px;
    flex-shrink:0;
}

.qty-btn,
.remove-btn{
    width:26px;
    height:26px;
    border:1px solid #3a3a3a;
    border-radius:6px;
    background:#222;
    color:#ddd;
    cursor:pointer;
    font-size:12px;
}

.qty-btn:hover{
    border-color:var(--bh-gold);
    color:var(--bh-gold-light);
}

.remove-btn{
    color:#e77777;
}

.remove-btn:hover{
    border-color:#9b3e3e;
    background:#32191b;
}

.qty-value{
    min-width:20px;
    text-align:center;
    color:#eee;
    font-size:11px;
}

.bill-box{
    border-top:1px solid #292929;
    padding-top:13px;
}

.bill-subtotal,
.bill-sum{
    display:flex;
    align-items:center;
    justify-content:space-between;
}

.bill-subtotal{
    color:#888;
    font-size:11px;
    margin-bottom:7px;
}

.bill-sum{
    color:var(--bh-gold-light);
    font-size:22px;
    font-weight:700;
}

.action-submit{
    width:100%;
    padding:13px 14px;
    margin-top:13px;
    border:0;
    background:var(--bh-gold);
    color:#111;
    font-family:Poppins,sans-serif;
    font-size:13px;
    font-weight:700;
    cursor:pointer;
    border-radius:8px;
    transition:.18s ease;
}

.action-submit:hover:not(:disabled){
    background:#d8b477;
    transform:translateY(-1px);
}

.action-submit.is-disabled{
    opacity:.35;
    cursor:not-allowed;
}

.action-submit:not(.is-disabled):hover{
    background:#d8b477;
    transform:translateY(-1px);
}

button,
input,
select,
textarea,
.item-node,
.qr-scan-btn,
.qty-btn,
.remove-btn,
.clear-sale,
.size-option,
.size-add-btn,
.order-confirm-submit,
.order-confirm-cancel,
.qr-cancel-btn,
.qr-close{
    touch-action:manipulation;
}

.clear-sale{
    width:100%;
    padding:10px;
    margin-top:7px;
    border:1px solid #3b3b3b;
    background:transparent;
    color:#999;
    border-radius:8px;
    cursor:pointer;
    font-family:Poppins,sans-serif;
    font-size:11px;
}

.clear-sale:hover{
    color:#fff;
    border-color:#666;
}

/* =========================
   RESPONSIVE
========================= */
@media(max-width:1200px){
    .dashboard-cards{
        grid-template-columns:repeat(3,minmax(0,1fr));
    }

    .pos-wrapper{
        grid-template-columns:minmax(0,1fr) 330px;
    }

    .menu-section{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
}

@media(max-width:900px){
    .dashboard-cards{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .pos-wrapper{
        grid-template-columns:1fr;
    }

    .checkout-node{
        position:relative;
        top:auto;
    }

    .menu-section{
        max-height:none;
        min-height:0;
    }
}

@media(max-width:600px){
    .dashboard-cards{
        grid-template-columns:1fr;
    }

    .menu-section{
        grid-template-columns:1fr;
    }

    .qr-order-banner{
        align-items:flex-start;
    }
}


/* =========================
   QR SCANNER MODAL
========================= */
.qr-scan-btn{
    width:100%;
    margin-top:9px;
    padding:10px 12px;
    border:1px solid #5c492c;
    background:#211c14;
    color:var(--bh-gold-light);
    border-radius:8px;
    font-family:Poppins,sans-serif;
    font-size:12px;
    font-weight:600;
    cursor:pointer;
}
.qr-scan-btn:hover{background:#2a2318;border-color:var(--bh-gold)}
.qr-modal{
    position:fixed;
    inset:0;
    z-index:9999;
    display:none;
    align-items:center;
    justify-content:center;
    padding:18px;
    background:rgba(0,0,0,.78);
}
.qr-modal.active{display:flex}
.qr-modal-card{
    width:min(520px,100%);
    max-height:92vh;
    overflow:auto;
    background:#181818;
    border:1px solid #393939;
    border-radius:16px;
    padding:20px;
    box-shadow:0 20px 60px rgba(0,0,0,.5);
}
.qr-modal-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px}
.qr-modal-head h3{margin:0;font-size:18px}
.qr-close{width:34px;height:34px;border:1px solid #3d3d3d;background:#222;color:#aaa;border-radius:8px;cursor:pointer}
.qr-close:hover{color:#fff;border-color:#666}
.qr-modal-desc{margin:0 0 15px;color:#999;font-size:12px}
#posQrReader{width:100%;overflow:hidden;border-radius:12px;background:#0d0d0d;min-height:80px}
.qr-modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}
.qr-status{font-size:12px;color:#999;margin-top:10px;min-height:18px}
.qr-cancel-btn{padding:9px 14px;border:1px solid #3d3d3d;background:#222;color:#ddd;border-radius:8px;cursor:pointer}
.qr-cancel-btn:hover{border-color:#666}


/* =========================
   WALK-IN SIZE SELECTION
========================= */
.size-select-modal{
    position:fixed;
    inset:0;
    z-index:10001;
    display:none;
    align-items:center;
    justify-content:center;
    padding:18px;
    background:rgba(0,0,0,.78);
}
.size-select-modal.active{display:flex}
.size-select-card{
    width:min(430px,100%);
    background:#181818;
    border:1px solid #3b3b3b;
    border-radius:16px;
    padding:22px;
    box-shadow:0 20px 60px rgba(0,0,0,.55);
}
.size-select-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:5px;
}
.size-select-head h3{margin:0;font-size:19px}
.size-select-close{
    width:34px;
    height:34px;
    border:1px solid #3d3d3d;
    background:#222;
    color:#aaa;
    border-radius:8px;
    cursor:pointer;
}
.size-select-close:hover{color:#fff;border-color:#666}
.size-select-category{margin:0 0 16px;color:#888;font-size:11px}
.size-options{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.size-option{
    border:1px solid #3a3a3a;
    background:#202020;
    color:#eee;
    border-radius:10px;
    padding:14px 12px;
    cursor:pointer;
    text-align:left;
    font-family:Poppins,sans-serif;
}
.size-option:hover{border-color:#c59d5f}
.size-option.selected{
    border-color:#c59d5f;
    background:#2a241b;
    box-shadow:0 0 0 1px #c59d5f inset;
}
.size-option strong{display:block;font-size:14px}
.size-option span{display:block;color:#c59d5f;font-size:13px;margin-top:3px}
.size-select-actions{display:flex;gap:10px;margin-top:18px}
.size-select-actions button{
    flex:1;
    padding:12px;
    border-radius:9px;
    font-family:inherit;
    font-weight:600;
    cursor:pointer;
}
.size-cancel-btn{background:#222;border:1px solid #444;color:#ddd}
.size-add-btn{background:#cda35f;border:1px solid #cda35f;color:#111}
.size-add-btn:disabled{opacity:.4;cursor:not-allowed}

.order-confirm-modal{
    position:fixed;
    inset:0;
    z-index:10000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:12px;
    box-sizing:border-box;
    background:rgba(0,0,0,.78);
    overflow:hidden;
}
.order-confirm-modal.active{display:flex}

.order-confirm-card{
    width:min(520px,100%);
    max-height:calc(100vh - 24px);
    max-height:calc(100dvh - 24px);
    box-sizing:border-box;
    overflow-y:auto;
    overflow-x:hidden;
    overscroll-behavior:contain;
    -webkit-overflow-scrolling:touch;
    background:#181818;
    border:1px solid #3b3b3b;
    border-radius:16px;
    padding:22px;
    box-shadow:0 20px 60px rgba(0,0,0,.55);
    scrollbar-width:thin;
    scrollbar-color:#555 transparent;
}
.order-confirm-card::-webkit-scrollbar{width:7px}
.order-confirm-card::-webkit-scrollbar-track{background:transparent}
.order-confirm-card::-webkit-scrollbar-thumb{background:#555;border-radius:10px}
.order-confirm-card::-webkit-scrollbar-thumb:hover{background:#777}

.order-confirm-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:16px;
}
.order-confirm-head h3{margin:0;font-size:19px}
.order-confirm-close{
    width:34px;
    height:34px;
    flex:0 0 34px;
    border:1px solid #3d3d3d;
    background:#222;
    color:#aaa;
    border-radius:8px;
    cursor:pointer;
}
.order-confirm-meta{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;
    margin-bottom:15px;
}
.order-confirm-meta div{
    background:#202020;
    border:1px solid #303030;
    border-radius:9px;
    padding:10px 12px;
    min-width:0;
}
.order-confirm-meta small{
    display:block;
    color:#888;
    font-size:10px;
    text-transform:uppercase;
    margin-bottom:3px;
}
.order-confirm-meta strong{
    font-size:13px;
    overflow-wrap:anywhere;
}
.order-confirm-items{
    max-height:260px;
    overflow:auto;
    border-top:1px solid #303030;
    border-bottom:1px solid #303030;
    margin:10px 0 14px;
}
.order-confirm-item{
    display:flex;
    justify-content:space-between;
    gap:12px;
    padding:10px 2px;
    border-bottom:1px solid #292929;
    font-size:12px;
}
.order-confirm-item:last-child{border-bottom:0}
.order-confirm-total{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin:12px 0 18px;
    font-weight:600;
}
.order-confirm-total span:last-child{
    font-size:21px;
    color:#d1aa68;
}
.payment-box{
    margin:12px 0 16px;
    padding:14px;
    background:#202020;
    border:1px solid #303030;
    border-radius:10px;
    box-sizing:border-box;
}
.payment-method-row{
    display:flex;
    gap:8px;
    margin-bottom:12px;
}
.payment-method-btn{
    min-width:0;
}
.payment-input{
    max-width:100%;
}
.order-confirm-actions{
    display:flex;
    gap:10px;
    position:sticky;
    bottom:0;
    padding-top:4px;
    background:#181818;
}
.order-confirm-actions button{
    flex:1;
    min-width:0;
    padding:12px;
    border-radius:9px;
    font-family:inherit;
    font-weight:600;
    cursor:pointer;
}
.order-confirm-cancel{background:#222;border:1px solid #444;color:#ddd}
.order-confirm-submit{background:#cda35f;border:1px solid #cda35f;color:#111}
.order-confirm-submit:hover{filter:brightness(1.06)}

@media(max-width:600px){
    .order-confirm-modal{
        padding:8px;
    }
    .order-confirm-card{
        width:100%;
        max-height:calc(100vh - 16px);
        max-height:calc(100dvh - 16px);
        padding:16px;
        border-radius:14px;
    }
    .order-confirm-meta{
        grid-template-columns:1fr;
    }
    .order-confirm-actions{
        flex-direction:column;
    }
}
.payment-box{
    margin:12px 0 16px;
    padding:14px;
    background:#202020;
    border:1px solid #303030;
    border-radius:10px;
}
.payment-box label{
    display:block;
    color:#aaa;
    font-size:11px;
    margin-bottom:6px;
    font-weight:600;
    text-transform:uppercase;
}
.payment-method-row{
    display:flex;
    gap:8px;
    margin-bottom:12px;
}
.payment-method-btn{
    flex:1;
    padding:10px;
    border:1px solid #3d3d3d;
    background:#181818;
    color:#ddd;
    border-radius:8px;
    cursor:pointer;
    font-family:inherit;
    font-weight:600;
}
.payment-method-btn.active{
    border-color:#c59d5f;
    background:#2a241b;
    color:#d8b477;
}
.payment-input{
    width:100%;
    box-sizing:border-box;
    padding:11px 12px;
    background:#111;
    border:1px solid #444;
    border-radius:8px;
    color:#fff;
    font-family:inherit;
    font-size:15px;
    outline:none;
}
.payment-input:focus{border-color:#c59d5f}
.payment-change{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-top:10px;
    padding-top:10px;
    border-top:1px solid #303030;
    color:#aaa;
    font-size:12px;
}
.payment-change strong{color:#70d88a;font-size:18px}
.payment-error{
    margin-top:8px;
    color:#ff9b9b;
    font-size:11px;
    min-height:16px;
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

/* ---------- POS ---------- */
@media screen and (min-width: 768px) and (max-width: 1366px) and (orientation: landscape) {

    .dashboard-cards {
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 9px;
        margin-bottom: 14px;
    }

    .dashboard-cards .card {
        padding: 12px 11px;
    }

    .dashboard-cards .card h3 {
        font-size: 8.5px;
        white-space: nowrap;
    }

    .dashboard-cards .card h1 {
        font-size: 20px;
        white-space: nowrap;
    }

    .pos-wrapper {
        grid-template-columns: minmax(0, 1fr) minmax(270px, 31%);
        gap: 12px;
        min-width: 0;
    }

    .menu-panel,
    .checkout-node {
        min-width: 0;
    }

    .menu-panel {
        padding: 13px;
    }

    .menu-heading {
        gap: 10px;
    }

    .menu-heading h2 {
        font-size: 15px;
    }

    .menu-heading span {
        font-size: 9px;
    }

    .search-box input {
        min-height: 40px;
    }

    .menu-section {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 8px;
        min-height: 0;
        max-height: calc(100vh - 320px);
    }

    .item-node {
        min-height: 100px;
        padding: 12px 10px 10px;
    }

    .item-node h3 {
        font-size: 12px;
        margin-bottom: 7px;
    }

    .item-cost {
        font-size: 14px;
    }

    .item-availability {
        font-size: 9px;
    }

    .checkout-node {
        position: sticky;
        top: 10px;
        min-height: 420px;
        max-height: calc(100vh - 105px);
        padding: 13px;
    }

    .checkout-head h2 {
        font-size: 16px;
    }

    .basket-stream {
        min-height: 140px;
        max-height: 270px;
    }

    .basket-name {
        max-width: 140px;
    }

    .bill-sum {
        font-size: 20px;
    }

    .action-submit {
        min-height: 42px;
        padding: 10px 12px;
    }

    .clear-sale {
        min-height: 38px;
    }
}

@media screen and (min-width: 768px) and (max-width: 899px) and (orientation: landscape) {

    .dashboard-cards {
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 6px;
    }

    .dashboard-cards .card {
        padding: 10px 8px;
    }

    .dashboard-cards .card h3 {
        font-size: 7.5px;
    }

    .dashboard-cards .card h1 {
        font-size: 17px;
    }

    .pos-wrapper {
        grid-template-columns: minmax(0, 1fr) 285px;
        gap: 9px;
    }

    .menu-section {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        max-height: calc(100vh - 305px);
    }

    .checkout-node {
        min-height: 400px;
        max-height: calc(100vh - 100px);
    }

    .basket-name {
        max-width: 120px;
    }
}

</style>

</head>

<body>

<?php include "cashier_sidebar.php"; ?>

<div class="main">

<div class="topbar">

<h1>Point of Sale</h1>

</div>


<div class="dashboard-cards">

    <div class="card">
        <h3>Today's Sales</h3>
        <h1>₱<?php echo number_format($daily_sales,2); ?></h1>
    </div>

    <div class="card">
        <h3>Today's Orders</h3>
        <h1><?php echo number_format($total_orders); ?></h1>
    </div>

    <div class="card">
        <h3>Products Sold</h3>
        <h1><?php echo number_format($products_sold); ?></h1>
    </div>

    <div class="card">
        <h3>Weekly Revenue</h3>
        <h1>₱<?php echo number_format($weekly_sales,2); ?></h1>
    </div>

    <div class="card">
        <h3>Monthly Revenue</h3>
        <h1>₱<?php echo number_format($monthly_sales,2); ?></h1>
    </div>

</div>

<?php if($order_success){ ?>
<div class="toast toast-ok">
    <i class="fa-solid fa-circle-check"></i>
    Order created successfully!
</div>
<?php } ?>
<?php if (!empty($last_order_id)) { ?>
<div class="toast toast-ok" style="display:flex;gap:10px;align-items:center;">
    <span>Order #<?php echo (int)$last_order_id; ?> created successfully.</span>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-left:auto;">
        <a href="print_kitchen_order.php?id=<?php echo (int)$last_order_id; ?>" target="_blank" class="btn" style="background:#c59d5f;color:#111;">
            <i class="fa-solid fa-print"></i> Print Kitchen Order
        </a>
        <a href="print_receipt.php?id=<?php echo (int)$last_order_id; ?>" target="_blank" class="btn" style="background:#2e7d32;color:#fff;">
            <i class="fa-solid fa-receipt"></i> Print Customer Receipt
        </a>
    </div>
</div>
<?php } ?>

<?php if(!empty($error_msg)){ ?>
<div class="toast toast-warn">
    <i class="fa-solid fa-circle-exclamation"></i>
    <?php echo htmlspecialchars($error_msg); ?>
</div>
<?php } ?>

<?php if ($scanned_order): ?>
<div class="qr-order-banner">
    <div>
        <strong>
            <i class="fa-solid fa-qrcode"></i>
            Customer Order Scanned
        </strong>
        <span>
            Receipt: <?php echo htmlspecialchars($scanned_order['receipt_number'] ?? ''); ?>
            · Order #<?php echo (int)$scanned_order['id']; ?>
        </span>
    </div>

    <a href="cashier_pos.php" class="qr-cancel">
        Cancel
    </a>
</div>
<?php endif; ?>

<div class="pos-wrapper">

    <!-- PRODUCT MENU -->
    <section class="menu-panel">

        <div class="menu-heading">
            <div>
                <h2><i class="fa-solid fa-utensils" style="color:#c59d5f;margin-right:7px;"></i>Products</h2>
            </div>
            <span><?php echo $scanned_order ? 'QR order mode' : 'Tap a product to add'; ?></span>
        </div>

        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input
                type="search"
                id="productSearch"
                placeholder="Search products..."
                autocomplete="off"
            >
        </div>

        <div class="menu-section" id="productGrid">

            <?php if ($scanned_order): ?>
            <div class="scanned-lock-note">
                <i class="fa-solid fa-lock"></i>
                This order came from the customer's QR. Products are locked.
                Review the order on the right, then select <strong>Process Order</strong>.
            </div>
            <?php endif; ?>

            <?php
            while($p=mysqli_fetch_assoc($product_query)){
                $productName = $p['name'] ?? '';
                $posAvailable = productAvailableForPos($conn, (int)$p['id'], (int)$p['stock']);
                $locked = $scanned_order || !$posAvailable;
            ?>

            <div
                class="item-node <?php echo $locked ? 'scanned-locked' : ''; ?>"
                data-product-name="<?php echo htmlspecialchars(strtolower($productName), ENT_QUOTES); ?>"
                <?php if (!$locked): ?>
                onclick="openProductForWalkIn(
                    <?php echo (int)$p['id']; ?>,
                    '<?php echo htmlspecialchars($productName,ENT_QUOTES); ?>',
                    '<?php echo htmlspecialchars($p['category'] ?? '',ENT_QUOTES); ?>',
                    <?php echo (float)($p['regular_price'] ?? $p['price'] ?? 0); ?>,
                    <?php echo (float)($p['large_price'] ?? 0); ?>,
                    <?php echo (int)$p['stock']; ?>
                )"
                <?php endif; ?>
            >

                <h3><?php echo htmlspecialchars($productName); ?></h3>

                <p class="item-cost">
                    <?php if($posAvailable): ?>
                        ₱<?php echo number_format((float)$p['price'],2); ?>
                    <?php else: ?>
                        NOT AVAILABLE
                    <?php endif; ?>
                </p>

                <p class="item-availability">
                    <i class="fa-solid fa-box"></i>
                    <?php echo $posAvailable ? 'Available' : 'Not Available'; ?>
                </p>

                <span class="add-icon">
                    <i class="fa-solid <?php echo $posAvailable ? 'fa-plus' : 'fa-ban'; ?>"></i>
                </span>

            </div>

            <?php } ?>

            <div
                id="noProductResults"
                style="display:none;grid-column:1/-1;text-align:center;padding:35px 15px;color:#666;font-size:12px;"
            >
                <i class="fa-solid fa-magnifying-glass" style="display:block;font-size:24px;margin-bottom:8px;color:#444;"></i>
                No products found.
            </div>

        </div>

    </section>

    <!-- CURRENT ORDER -->
    <aside class="checkout-node">

        <div class="checkout-head">
            <h2>
                <i class="fa-solid fa-receipt" style="color:#c59d5f;margin-right:7px;"></i>
                Current Order
            </h2>

            <span class="order-count" id="orderCount">0 items</span>
        </div>

        <div class="basket-stream" id="basketStream"></div>

        <div class="bill-box">

            <div class="bill-subtotal">
                <span>Subtotal</span>
                <span id="billSubtotal">₱0.00</span>
            </div>

            <div class="bill-sum">
                <span>Total</span>
                <span id="billTotalDisplay">₱0.00</span>
            </div>

            <form method="POST" id="submissionGate">

                <input
                    type="hidden"
                    name="cart_items"
                    id="formPayload"
                >

                <?php if ($scanned_order_id > 0): ?>
                <input
                    type="hidden"
                    name="existing_order_id"
                    value="<?php echo (int)$scanned_order_id; ?>"
                >
                <?php endif; ?>

                <button
                    type="button"
                    class="action-submit is-disabled"
                    id="submitBtn"
                    aria-disabled="true"
                >
                    <i class="fa-solid fa-check"></i>
                    Process Order
                </button>

            </form>

            <?php if (!$scanned_order): ?>
            <button type="button" class="qr-scan-btn" id="openQrScannerBtn">
                <i class="fa-solid fa-qrcode"></i>
                Scan Customer QR
            </button>
            <?php endif; ?>

            <?php if (!$scanned_order): ?>
            <button type="button" class="clear-sale" onclick="clearBasket()">
                <i class="fa-solid fa-trash-can"></i>
                Clear Order
            </button>
            <?php endif; ?>

        </div>

    </aside>

</div>

<!-- WALK-IN SIZE SELECTION -->
<div class="size-select-modal" id="sizeSelectModal" aria-hidden="true">
    <div class="size-select-card">
        <div class="size-select-head">
            <h3><i class="fa-solid fa-ruler-horizontal" style="color:#cda35f;margin-right:7px;"></i>Select Size</h3>
            <button type="button" class="size-select-close" onclick="closeSizeSelector()">×</button>
        </div>
        <p class="size-select-category" id="sizeSelectProductName">Choose a size for this product.</p>

        <div class="size-options" id="sizeOptions"></div>

        <div class="size-select-actions">
            <button type="button" class="size-cancel-btn" onclick="closeSizeSelector()">Cancel</button>
            <button type="button" class="size-add-btn" id="sizeAddBtn" onclick="confirmSizeSelection()" disabled>
                <i class="fa-solid fa-plus"></i> Add to Order
            </button>
        </div>
    </div>
</div>

<!-- POS QR SCANNER -->
<div class="order-confirm-modal" id="orderConfirmModal" aria-hidden="true">
    <div class="order-confirm-card">
        <div class="order-confirm-head">
            <h3><i class="fa-solid fa-clipboard-check" style="color:#cda35f;margin-right:7px;"></i>Confirm Order</h3>
            <button type="button" class="order-confirm-close" onclick="closeOrderConfirmation()">×</button>
        </div>

        <div class="order-confirm-meta">
            <div><small>Customer</small><strong id="confirmCustomer"><?php echo $scanned_order ? 'Application Customer' : 'Walk-in Customer'; ?></strong></div>
            <div><small>Order Type</small><strong id="confirmOrderType"><?php echo $scanned_order ? htmlspecialchars(($scanned_order['order_type'] ?? '—') === 'Advance Order' ? 'Pick-up Order' : ($scanned_order['order_type'] ?? '—')) : '—'; ?></strong></div>
            <?php if ($scanned_order): ?>
            <div><small>Receipt / Order #</small><strong><?php echo htmlspecialchars($scanned_order['receipt_number'] ?? '—'); ?> / #<?php echo (int)$scanned_order['id']; ?></strong></div>
            <?php endif; ?>
        </div>

        <div class="order-confirm-items" id="confirmItems"></div>
        <div class="order-confirm-total"><span>Total</span><span id="confirmTotal">₱0.00</span></div>

        <div class="payment-box">
            <label>Payment Method</label>
            <div class="payment-method-row">
                <button type="button" class="payment-method-btn active" id="posCashBtn" onclick="selectPosPayment('Cash')">
                    <i class="fa-solid fa-money-bill-wave"></i> Cash
                </button>
                <button type="button" class="payment-method-btn" id="posGcashBtn" onclick="selectPosPayment('GCash')">
                    <i class="fa-solid fa-mobile-screen-button"></i> GCash
                </button>
            </div>

            <div id="cashPaymentFields">
                <label for="posAmountReceived">Amount Received</label>
                <input
                    type="number"
                    id="posAmountReceived"
                    class="payment-input"
                    min="0"
                    step="0.01"
                    inputmode="decimal"
                    placeholder="0.00"
                    oninput="updatePosChange()"
                >
                <div class="payment-change">
                    <span>Change</span>
                    <strong id="posChange">₱0.00</strong>
                </div>
                <div class="payment-error" id="posPaymentError"></div>
            </div>

            <div id="gcashPaymentFields" style="display:none;">
                <div style="color:#999;font-size:11px;line-height:1.5;">
                    Use this only when the payment has already been received through GCash/PayMongo.
                </div>
            </div>
        </div>

        <div class="order-confirm-actions">
            <button type="button" class="order-confirm-cancel" onclick="closeOrderConfirmation()">Cancel</button>
            <button type="button" class="order-confirm-submit" onclick="confirmAndSendOrder()"><i class="fa-solid fa-paper-plane"></i> Confirm &amp; Send to Kitchen</button>
        </div>
    </div>
</div>

<div class="qr-modal" id="posQrModal" aria-hidden="true">
    <div class="qr-modal-card" role="dialog" aria-modal="true" aria-labelledby="posQrTitle">
        <div class="qr-modal-head">
            <h3 id="posQrTitle"><i class="fa-solid fa-qrcode" style="color:#c59d5f;margin-right:7px"></i>Scan Customer QR</h3>
            <button type="button" class="qr-close" onclick="closePosQrScanner()" aria-label="Close scanner">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <p class="qr-modal-desc">Scan the QR code displayed on the customer's mobile application.</p>
        <div id="posQrReader"></div>
        <div class="qr-status" id="posQrStatus">Starting camera...</div>
        <div class="qr-modal-actions">
            <button type="button" class="qr-cancel-btn" onclick="closePosQrScanner()">Cancel</button>
        </div>
    </div>
</div>

<script>
let basket = <?php echo json_encode($scanned_items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const scannedOrderMode = <?php echo $scanned_order ? 'true' : 'false'; ?>;
const scannedOrderTotal = <?php echo $scanned_order ? json_encode((float)($scanned_order['display_total'] ?? 0)) : '0'; ?>;

let posQrScanner = null;
let posQrScanning = false;
let posQrHandlingResult = false;

let posPaymentMethod = <?php
    echo $scanned_order
        ? json_encode((string)($scanned_order['payment_method'] ?? 'Cash'))
        : json_encode('Cash');
?>;
if (!['Cash','GCash'].includes(posPaymentMethod)) {
    posPaymentMethod = 'Cash';
}
let posPaymentTotal = 0;

function selectPosPayment(method){
    posPaymentMethod = method === 'GCash' ? 'GCash' : 'Cash';

    const cashBtn = document.getElementById('posCashBtn');
    const gcashBtn = document.getElementById('posGcashBtn');
    const cashFields = document.getElementById('cashPaymentFields');
    const gcashFields = document.getElementById('gcashPaymentFields');

    if(cashBtn) cashBtn.classList.toggle('active', posPaymentMethod === 'Cash');
    if(gcashBtn) gcashBtn.classList.toggle('active', posPaymentMethod === 'GCash');
    if(cashFields) cashFields.style.display = posPaymentMethod === 'Cash' ? 'block' : 'none';
    if(gcashFields) gcashFields.style.display = posPaymentMethod === 'GCash' ? 'block' : 'none';

    if(posPaymentMethod === 'GCash'){
        const input = document.getElementById('posAmountReceived');
        if(input) input.value = posPaymentTotal.toFixed(2);
        updatePosChange();
    }
}

function updatePosChange(){
    const input = document.getElementById('posAmountReceived');
    const changeEl = document.getElementById('posChange');
    const errorEl = document.getElementById('posPaymentError');
    const received = Number(input ? input.value : 0) || 0;
    const change = Math.max(0, received - posPaymentTotal);

    if(changeEl){
        changeEl.textContent = '₱' + change.toLocaleString('en-US',{
            minimumFractionDigits:2,
            maximumFractionDigits:2
        });
    }

    if(errorEl){
        if(posPaymentMethod === 'Cash' && received + 0.0001 < posPaymentTotal){
            errorEl.textContent = 'Amount received must be at least ₱' +
                posPaymentTotal.toLocaleString('en-US',{
                    minimumFractionDigits:2,
                    maximumFractionDigits:2
                });
        }else{
            errorEl.textContent = '';
        }
    }
}

function openOrderConfirmation(){
    if(!basket || basket.length === 0) return;
    const modal = document.getElementById('orderConfirmModal');
    const items = document.getElementById('confirmItems');
    const totalEl = document.getElementById('confirmTotal');
    if(!modal || !items || !totalEl) return;

    let total = 0;
    items.innerHTML = '';
    basket.forEach(function(item){
        const qty = Number(item.quantity) || 0;
        const price = (Number(item.price) || Number(item.base_price) || 0)
                    + (Number(item.addons_price) || 0);
        const line = qty * price;
        total += line;
        const details = [];
        if (item.size) details.push('Size: ' + item.size);
        if (item.flavor) details.push('Flavor: ' + item.flavor);
        if (item.sugar) details.push('Sugar: ' + item.sugar);
        if (item.ice) details.push('Ice: ' + item.ice);
        if (item.addons) details.push('Add-ons: ' + item.addons);
        if (item.special_instructions) details.push('Note: ' + item.special_instructions);
        const meta = details.length ? `<small style="display:block;color:#999;margin-top:3px">${escapeHtml(details.join(' • '))}</small>` : '';
        items.innerHTML += `<div class="order-confirm-item"><span>${escapeHtml(item.name)} × ${qty}${meta}</span><strong>₱${line.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</strong></div>`;
    });
    // For QR orders, the database order total is authoritative.
    // For walk-in orders, use the basket calculation.
    if (scannedOrderMode && Number(scannedOrderTotal) > 0) {
        total = Number(scannedOrderTotal);
    }
    posPaymentTotal = Number(total) || 0;

    const amountInput = document.getElementById('posAmountReceived');
    if (amountInput) {
        if (posPaymentMethod === 'Cash') {
            amountInput.value = '';
        } else {
            amountInput.value = posPaymentTotal.toFixed(2);
        }
    }

    totalEl.textContent = '₱' + total.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    selectPosPayment(posPaymentMethod);
    updatePosChange();

    modal.classList.add('active');
    modal.setAttribute('aria-hidden','false');
    document.body.style.overflow = 'hidden';

    // Start at the top every time the confirmation window is opened.
    const card = modal.querySelector('.order-confirm-card');
    if (card) card.scrollTop = 0;
}

function closeOrderConfirmation(){
    const modal = document.getElementById('orderConfirmModal');
    if(!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden','true');
    document.body.style.overflow = '';
}

function confirmAndSendOrder(){
    const form = document.getElementById('submissionGate');
    if(!form || !basket || basket.length === 0) return;

    for (const item of basket) {
        if ((Number(item.quantity) || 0) <= 0) {
            alert('Invalid item quantity.');
            return;
        }
        if (Number(item.stock) > 0 && Number(item.quantity) > Number(item.stock)) {
            alert('Quantity for ' + item.name + ' exceeds available stock.');
            return;
        }
    }

    const receivedInput = document.getElementById('posAmountReceived');
    const received = Number(receivedInput ? receivedInput.value : 0) || 0;

    if(posPaymentMethod === 'Cash' && received + 0.0001 < posPaymentTotal){
        updatePosChange();
        alert(
            'Amount received is not enough. Please enter at least ₱' +
            posPaymentTotal.toLocaleString('en-US',{
                minimumFractionDigits:2,
                maximumFractionDigits:2
            }) + '.'
        );
        if(receivedInput) receivedInput.focus();
        return;
    }

    const finalPaid = posPaymentMethod === 'Cash' ? received : posPaymentTotal;

    const payload = document.getElementById('formPayload');
    if(payload) payload.value = JSON.stringify(basket);

    // Send payment data to PHP so it is saved with the order.
    setOrCreateHidden(form, 'payment_method', posPaymentMethod);
    setOrCreateHidden(form, 'amount_received', finalPaid.toFixed(2));

    closeOrderConfirmation();

    const submitter = document.createElement('input');
    submitter.type = 'hidden';
    submitter.name = 'place_order';
    submitter.value = '1';
    form.appendChild(submitter);
    form.submit();
}

function setOrCreateHidden(form, name, value){
    let input = form.querySelector('input[name="' + name + '"]');
    if(!input){
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        form.appendChild(input);
    }
    input.value = value;
}

function openPosQrScanner(){
    const modal = document.getElementById('posQrModal');
    if(!modal) return;
    modal.classList.add('active');
    modal.setAttribute('aria-hidden','false');
    document.body.style.overflow = 'hidden';
    startPosQrScanner();
}

function closePosQrScanner(){
    const modal = document.getElementById('posQrModal');
    if(!modal) return;
    const finish = function(){
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden','true');
        document.body.style.overflow = '';
        const reader = document.getElementById('posQrReader');
        if(reader) reader.innerHTML = '';
        const status = document.getElementById('posQrStatus');
        if(status) status.textContent = '';
        posQrScanner = null;
        posQrScanning = false;
        posQrHandlingResult = false;
    };
    if(posQrScanner && posQrScanning){
        posQrScanner.stop().then(finish).catch(finish);
    }else{
        finish();
    }
}

function getQrBoxSize(){
    const reader = document.getElementById('posQrReader');
    const width = reader ? reader.clientWidth : 320;
    const edge = Math.max(180, Math.min(300, Math.floor(width * 0.72)));
    return {width: edge, height: edge};
}

function qrScannerConfig(){
    return {
        fps: 10,
        aspectRatio: 1.0,
        qrbox: getQrBoxSize(),
        disableFlip: false
    };
}

function startPosQrScanner(){
    const status = document.getElementById('posQrStatus');
    const reader = document.getElementById('posQrReader');

    if(!window.Html5Qrcode){
        if(status) status.textContent = 'QR scanner library could not be loaded. Check your internet connection.';
        return;
    }
    if(posQrScanning) return;

    posQrHandlingResult = false;
    if(reader) reader.innerHTML = '';
    posQrScanner = new Html5Qrcode('posQrReader');
    posQrScanning = true;
    if(status) status.textContent = 'Requesting camera access...';

    const onScanError = function(){ /* normal camera scan miss; ignore */ };
    const config = qrScannerConfig();

    // First try the rear/environment camera. This works well on phones/tablets
    // and avoids forcing a hard camera device ID that may differ by browser.
    posQrScanner.start(
        {facingMode:{exact:'environment'}},
        config,
        handlePosQrResult,
        onScanError
    ).then(function(){
        if(status) status.textContent = 'Point the camera at the customer QR code.';
    }).catch(function(firstError){
        console.warn('Environment camera start failed:', firstError);

        // Fallback: ask the browser for its available cameras and select a
        // rear/back/environment camera when one is reported.
        if(status) status.textContent = 'Trying the available camera...';

        if(typeof Html5Qrcode.getCameras !== 'function'){
            throw firstError;
        }

        return Html5Qrcode.getCameras().then(function(cameras){
            if(!cameras || cameras.length === 0){
                throw new Error('No camera was found on this device.');
            }

            const rear = cameras.find(function(camera){
                return /back|rear|environment|world/i.test(camera.label || '');
            });
            const cameraId = rear ? rear.id : cameras[cameras.length - 1].id;

            return posQrScanner.start(
                cameraId,
                config,
                handlePosQrResult,
                onScanError
            );
        }).then(function(){
            if(status) status.textContent = 'Point the camera at the customer QR code.';
        });
    }).catch(function(error){
        posQrScanning = false;
        posQrHandlingResult = false;
        console.error('QR camera error:', error);

        let message = 'Unable to start camera. Please allow camera access and try again.';
        const name = error && error.name ? error.name : '';
        const text = String(error && error.message ? error.message : error).toLowerCase();

        if(name === 'NotAllowedError' || text.includes('permission') || text.includes('not allowed')){
            message = 'Camera access was denied. Allow camera access for this website, then try again.';
        }else if(name === 'NotFoundError' || text.includes('no camera') || text.includes('not found')){
            message = 'No camera was found on this device.';
        }else if(name === 'NotReadableError' || text.includes('in use') || text.includes('not readable')){
            message = 'The camera is already being used by another app or browser tab.';
        }else if(location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1'){
            message = 'Camera scanning requires HTTPS on this device. Open the POS using your HTTPS website address.';
        }

        if(status) status.textContent = message;
    });
}

function handlePosQrResult(decodedText){
    if(posQrHandlingResult) return;

    const code = String(decodedText || '').trim();
    if(!code) return;

    posQrHandlingResult = true;
    const status = document.getElementById('posQrStatus');
    if(status) status.textContent = 'QR detected. Loading customer order...';

    // scan_order.php is a normal PHP page/redirect endpoint, not a JSON API.
    // Do not call response.json(); navigate directly after scanning.
    const goToOrder = function(){
        window.location.href = 'scan_order.php?code=' + encodeURIComponent(code);
    };

    if(posQrScanner && posQrScanning){
        posQrScanner.stop().catch(function(){}).then(function(){
            posQrScanning = false;
            goToOrder();
        });
    }else{
        goToOrder();
    }
}

document.addEventListener('keydown', function(event){
    if(event.key === 'Escape'){
        const sizeModal = document.getElementById('sizeSelectModal');
        if(sizeModal && sizeModal.classList.contains('active')) closeSizeSelector();
        const confirmModal = document.getElementById('orderConfirmModal');
        if(confirmModal && confirmModal.classList.contains('active')) closeOrderConfirmation();
        const modal = document.getElementById('posQrModal');
        if(modal && modal.classList.contains('active')) closePosQrScanner();
    }
});


document.addEventListener("DOMContentLoaded", function(){

    updateBasket();

    // Universal mouse + touch event handling for POS controls.
    const submitBtn = document.getElementById("submitBtn");
    if(submitBtn){
        submitBtn.addEventListener("click", function(event){
            event.preventDefault();
            if(submitBtn.getAttribute("aria-disabled") === "true" || !basket || basket.length === 0){
                return;
            }
            openOrderConfirmation();
        });
    }

    const qrButton = document.getElementById("openQrScannerBtn");
    if(qrButton){
        qrButton.addEventListener("click", function(event){
            event.preventDefault();
            openPosQrScanner();
        });
    }

    const search = document.getElementById("productSearch");
    const productCards = document.querySelectorAll(".item-node");
    const noResults = document.getElementById("noProductResults");

    if(search){
        search.addEventListener("input", function(){

            const keyword = this.value.trim().toLowerCase();
            let visible = 0;

            productCards.forEach(function(card){

                const name = card.dataset.productName || "";

                if(name.includes(keyword)){
                    card.style.display = "";
                    visible++;
                }else{
                    card.style.display = "none";
                }

            });

            if(noResults){
                noResults.style.display = visible === 0 ? "block" : "none";
            }
        });
    }

});

const sizeCategories = [
    'Milk Tea',
    'Milk Tea Creamcheese',
    'Fruit Tea',
    'Non Coffee',
    'Frappes'
];

let pendingProduct = null;
let pendingSelectedSize = '';

function openProductForWalkIn(id, name, category, regularPrice, largePrice, stock){

    if(scannedOrderMode) return;

    if(Number(stock) <= 0){
        alert('This product is currently not available.');
        return;
    }

    if(sizeCategories.includes(category)){
        pendingProduct = {
            id:Number(id),
            name:String(name),
            category:String(category),
            regularPrice:Number(regularPrice) || 0,
            largePrice:Number(largePrice) || 0,
            stock:Number(stock)
        };

        pendingSelectedSize = 'Regular';

        const modal = document.getElementById('sizeSelectModal');
        const title = document.getElementById('sizeSelectProductName');
        const options = document.getElementById('sizeOptions');
        const addBtn = document.getElementById('sizeAddBtn');

        if(!modal || !title || !options || !addBtn) return;

        title.textContent = pendingProduct.name + ' • ' + pendingProduct.category;

        const largeEnabled = pendingProduct.largePrice > 0;

        options.innerHTML = `
            <button type="button" class="size-option selected" data-size="Regular" onclick="selectWalkInSize('Regular')">
                <strong>Regular</strong>
                <span>₱${pendingProduct.regularPrice.toFixed(2)}</span>
            </button>
            ${largeEnabled ? `
            <button type="button" class="size-option" data-size="Large" onclick="selectWalkInSize('Large')">
                <strong>Large</strong>
                <span>₱${pendingProduct.largePrice.toFixed(2)}</span>
            </button>` : ''}
        `;

        addBtn.disabled = false;
        modal.classList.add('active');
        modal.setAttribute('aria-hidden','false');
        return;
    }

    addWalkInItem(id, name, category, Number(regularPrice) || 0, 0, '', Number(stock) || 0);
}

function selectWalkInSize(size){
    if(!pendingProduct) return;

    pendingSelectedSize = size;

    document.querySelectorAll('#sizeOptions .size-option').forEach(function(button){
        button.classList.toggle('selected', button.dataset.size === size);
    });
}

function closeSizeSelector(){
    const modal = document.getElementById('sizeSelectModal');
    if(!modal) return;

    modal.classList.remove('active');
    modal.setAttribute('aria-hidden','true');
    pendingProduct = null;
    pendingSelectedSize = '';
}

function confirmSizeSelection(){
    if(!pendingProduct) return;

    const p = pendingProduct;
    const selectedSize = pendingSelectedSize || 'Regular';
    const selectedPrice = selectedSize === 'Large' ? p.largePrice : p.regularPrice;
    // Size has its own fixed price; never treat Large as a surcharge.
    const sizePrice = 0;

    addWalkInItem(
        p.id,
        p.name,
        p.category,
        selectedPrice,
        0,
        selectedSize,
        p.stock
    );

    closeSizeSelector();
}

function addWalkInItem(id, name, category, basePrice, sizePrice, size, stock){

    if(scannedOrderMode) return;

    const item = basket.find(function(p){
        return Number(p.id) === Number(id) &&
               String(p.size || '') === String(size || '');
    });

    if(item){
        if(item.quantity < Number(stock)){
            item.quantity++;
        }else{
            alert('Maximum stock reached for this product/size.');
            return;
        }
    }else{
        basket.push({
            id:Number(id),
            name:String(name),
            price:Number(basePrice) || 0,
            base_price:Number(basePrice) || 0,
            size_price:0,
            addons_price:0,
            addons:'',
            category:String(category || ''),
            flavor:'',
            size:String(size || ''),
            sugar:'',
            ice:'',
            special_instructions:'',
            quantity:1,
            stock:Number(stock) || 0
        });
    }

    updateBasket();
}

function pushToBasket(id, name, price, stock){
    // Backward-compatible wrapper for any remaining calls.
    openProductForWalkIn(id, name, '', Number(price) || 0, 0, stock);
}

function changeQty(id, change, size){

    if(scannedOrderMode) return;

    let item = basket.find(function(p){
        return Number(p.id) === Number(id) &&
               String(p.size || '') === String(size || '');
    });

    if(!item) return;

    item.quantity += change;

    if(item.quantity <= 0){
        basket = basket.filter(function(p){
            return !(Number(p.id) === Number(id) &&
                     String(p.size || '') === String(size || ''));
        });
        updateBasket();
        return;
    }

    if(item.quantity > item.stock){
        item.quantity = item.stock;
    }

    updateBasket();
}

function removeItem(id, size){

    if(scannedOrderMode) return;

    basket = basket.filter(function(p){
        return !(Number(p.id) === Number(id) &&
                 String(p.size || '') === String(size || ''));
    });

    updateBasket();
}

function clearBasket(){

    if(scannedOrderMode) return;

    if(basket.length === 0) return;

    if(!confirm("Clear the current order?")) return;

    basket = [];
    updateBasket();
}

function updateBasket(){

    const basketStream = document.getElementById("basketStream");
    const totalDisplay = document.getElementById("billTotalDisplay");
    const subtotalDisplay = document.getElementById("billSubtotal");
    const payload = document.getElementById("formPayload");
    const submit = document.getElementById("submitBtn");
    const countDisplay = document.getElementById("orderCount");

    basketStream.innerHTML = "";

    let total = 0;
    let itemCount = 0;

    if(basket.length === 0){

        basketStream.innerHTML = `
            <div class="empty-basket">
                <div>
                    <i class="fa-solid fa-basket-shopping"></i>
                    No items added
                </div>
            </div>
        `;

        totalDisplay.textContent = "₱0.00";
        subtotalDisplay.textContent = "₱0.00";
        payload.value = "";
        submit.disabled = false;
        submit.classList.add("is-disabled");
        submit.setAttribute("aria-disabled", "true");
        countDisplay.textContent = "0 items";
        return;
    }

    basket.forEach(function(item){

        const price = (Number(item.price) || Number(item.base_price) || 0)
            + (Number(item.addons_price) || 0);
        const quantity = Number(item.quantity) || 0;

        total += price * quantity;
        itemCount += quantity;

        const controls = scannedOrderMode
            ? `
                <span class="qty-value">×${quantity}</span>
              `
            : `
                <div class="basket-actions">
                    <button type="button" class="qty-btn" onclick="changeQty(${item.id},-1,'${escapeHtml(item.size || '')}')">−</button>
                    <span class="qty-value">${quantity}</span>
                    <button type="button" class="qty-btn" onclick="changeQty(${item.id},1,'${escapeHtml(item.size || '')}')">+</button>
                    <button type="button" class="remove-btn" onclick="removeItem(${item.id},'${escapeHtml(item.size || '')}')">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
              `;

        basketStream.innerHTML += `
            <div class="basket-row">

                <div class="basket-info">
                    <span class="basket-name">${escapeHtml(item.name)}</span>
                    <div class="basket-price">
                        ₱${price.toFixed(2)} × ${quantity}
                        ${item.size ? ' • ' + escapeHtml(item.size) : ''}
                        ${item.addons ? ' • Add-ons: ' + escapeHtml(item.addons) : ''}
                    </div>
                </div>

                ${controls}

            </div>
        `;
    });

    // For QR orders, display the authoritative order total saved on the order.
    if (scannedOrderMode && Number(scannedOrderTotal) > 0) {
        total = Number(scannedOrderTotal);
    }

    const formattedTotal = "₱" + total.toLocaleString("en-US",{
        minimumFractionDigits:2,
        maximumFractionDigits:2
    });

    totalDisplay.textContent = formattedTotal;
    subtotalDisplay.textContent = formattedTotal;
    countDisplay.textContent = itemCount + (itemCount === 1 ? " item" : " items");

    payload.value = JSON.stringify(basket);
    submit.disabled = false;
    submit.classList.remove("is-disabled");
    submit.setAttribute("aria-disabled", "false");
}

function escapeHtml(value){

    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

/* Prevent accidental double-click */
const submissionGate = document.getElementById("submissionGate");

if(submissionGate){

    submissionGate.addEventListener("submit", function(){

        const submit = document.getElementById("submitBtn");

        submit.disabled = true;
        submit.innerHTML = `
            <i class="fa-solid fa-spinner fa-spin"></i>
            Processing...
        `;
    });
}
</script>

</body>

</html>
<?php

/*
|--------------------------------------------------------------------------
| BLACKHABIT - VIEW ORDER
|--------------------------------------------------------------------------
| Admin  : BH_ADMIN_SESSION
| Cashier: BH_CASHIER_SESSION
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| SELECT CORRECT SESSION BEFORE session_start()
|--------------------------------------------------------------------------
*/

$from = strtolower(trim((string)($_GET['from'] ?? '')));
$referer = strtolower((string)($_SERVER['HTTP_REFERER'] ?? ''));

if ($from === 'cashier') {
    session_name('BH_CASHIER_SESSION');
} elseif ($from === 'admin') {
    session_name('BH_ADMIN_SESSION');
} elseif (strpos($referer, 'cashier_') !== false) {
    session_name('BH_CASHIER_SESSION');
} elseif (strpos($referer, 'admin_') !== false) {
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


/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['role']) ||
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true
) {
    header('Location: index.php');
    exit();
}

$sessionRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$sessionRoleId = (int)($_SESSION['role'] ?? 0);

$isAdmin =
    $sessionRole === 'admin' ||
    $sessionRoleId === 1;

$isCashier =
    $sessionRole === 'cashier' ||
    in_array($sessionRoleId, [2, 3], true);

if (!$isAdmin && !$isCashier) {
    header('Location: index.php');
    exit();
}

$currentFrom = $isCashier ? 'cashier' : 'admin';


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

require_once 'db.php';


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function blackHabitOrderNumber(
    int $id,
    string $createdAt = ''
): string {
    $year = date(
        'Y',
        $createdAt !== ''
            ? strtotime($createdAt)
            : time()
    );

    return 'BH-' .
        $year .
        '-' .
        str_pad(
            (string)$id,
            6,
            '0',
            STR_PAD_LEFT
        );
}


/*
|--------------------------------------------------------------------------
| REDIRECT BACK TO CORRECT ORDER PAGE
|--------------------------------------------------------------------------
*/

function redirectToOrder(
    int $orderId,
    string $type,
    string $message
): void {

    global $currentFrom;

    $url =
        'view_order.php?id=' .
        $orderId .
        '&from=' .
        urlencode($currentFrom);

    if ($type === 'success') {
        $url .= '&success=' . urlencode($message);
    } else {
        $url .= '&error=' . urlencode($message);
    }

    header('Location: ' . $url);
    exit();
}


/*
|--------------------------------------------------------------------------
| FIREBASE NOTIFICATION
|--------------------------------------------------------------------------
*/

$fcmHelperAvailable = false;
$fcmTokenColumnAvailable = false;

$fcmHelperPath =
    __DIR__ . '/send_fcm_notification.php';

if (is_file($fcmHelperPath)) {
    require_once $fcmHelperPath;

    $fcmHelperAvailable =
        function_exists('sendFCMNotification');
}

try {

    $columnCheck = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM orders LIKE 'fcm_token'"
    );

    $fcmTokenColumnAvailable =
        $columnCheck &&
        mysqli_num_rows($columnCheck) > 0;

} catch (Throwable $e) {

    $fcmTokenColumnAvailable = false;
}


function sendOrderStatusNotification(
    mysqli $conn,
    int $orderId,
    string $title,
    string $body
): void {

    global
        $fcmHelperAvailable,
        $fcmTokenColumnAvailable;

    if (!$fcmHelperAvailable) {
        return;
    }

    if (!$fcmTokenColumnAvailable) {
        return;
    }

    try {

        $stmt = mysqli_prepare(
            $conn,
            "SELECT fcm_token
             FROM orders
             WHERE id = ?
             LIMIT 1"
        );

        if (!$stmt) {
            return;
        }

        mysqli_stmt_bind_param(
            $stmt,
            'i',
            $orderId
        );

        mysqli_stmt_execute($stmt);

        $result =
            mysqli_stmt_get_result($stmt);

        $row =
            $result
                ? mysqli_fetch_assoc($result)
                : null;

        mysqli_stmt_close($stmt);

        if (!$row) {
            return;
        }

        $token =
            trim((string)($row['fcm_token'] ?? ''));

        if ($token === '') {
            return;
        }

        sendFCMNotification(
            $token,
            $title,
            $body,
            $orderId
        );

    } catch (Throwable $e) {

        error_log(
            'BLACK HABIT FCM error: ' .
            $e->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| RECEIVE PAYMENT
|--------------------------------------------------------------------------
*/

if (isset($_POST['receive_payment'])) {

    if (!$isCashier) {
        redirectToOrder(
            (int)($_POST['order_id'] ?? 0),
            'error',
            'Unauthorized.'
        );
    }

    $orderId =
        (int)($_POST['order_id'] ?? 0);

    $payment =
        (float)($_POST['payment'] ?? 0);

    if ($orderId <= 0 || $payment <= 0) {
        redirectToOrder(
            $orderId,
            'error',
            'Please enter a valid payment amount.'
        );
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            total,
            paid_amount,
            balance,
            order_type,
            status
         FROM orders
         WHERE id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        redirectToOrder(
            $orderId,
            'error',
            'Unable to load payment information.'
        );
    }

    mysqli_stmt_bind_param(
        $stmt,
        'i',
        $orderId
    );

    mysqli_stmt_execute($stmt);

    $result =
        mysqli_stmt_get_result($stmt);

    $orderPayment =
        $result
            ? mysqli_fetch_assoc($result)
            : null;

    mysqli_stmt_close($stmt);

    if (!$orderPayment) {
        redirectToOrder(
            $orderId,
            'error',
            'Order not found.'
        );
    }

    if (
        ($orderPayment['order_type'] ?? '') !==
        'Advance Order'
    ) {
        redirectToOrder(
            $orderId,
            'error',
            'This is not a Pick-up Order.'
        );
    }

    if (
        ($orderPayment['status'] ?? '') !==
        'Pending'
    ) {
        redirectToOrder(
            $orderId,
            'error',
            'This order has already been processed.'
        );
    }

    $total =
        (float)($orderPayment['total'] ?? 0);

    $paid =
        (float)($orderPayment['paid_amount'] ?? 0);

    $balance =
        (float)($orderPayment['balance'] ?? 0);

    if ($payment > $balance) {
        $payment = $balance;
    }

    $newPaid = $paid + $payment;
    $newBalance = $total - $newPaid;

    if ($newBalance < 0) {
        $newBalance = 0;
    }

    if ($newPaid <= 0) {
        $paymentStatus = 'Unpaid';
    } elseif ($newBalance <= 0) {
        $paymentStatus = 'Fully Paid';
    } else {
        $paymentStatus = 'Partially Paid';
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE orders
         SET
            paid_amount = ?,
            balance = ?,
            payment_status = ?
         WHERE id = ?
         AND status = 'Pending'"
    );

    if (!$stmt) {
        redirectToOrder(
            $orderId,
            'error',
            'Unable to update payment.'
        );
    }

    mysqli_stmt_bind_param(
        $stmt,
        'ddsi',
        $newPaid,
        $newBalance,
        $paymentStatus,
        $orderId
    );

    $success =
        mysqli_stmt_execute($stmt);

    mysqli_stmt_close($stmt);

    if (!$success) {
        redirectToOrder(
            $orderId,
            'error',
            'Payment could not be saved.'
        );
    }

    redirectToOrder(
        $orderId,
        'success',
        'Payment received successfully.'
    );
}


/*
|--------------------------------------------------------------------------
| PROCESS ORDER
|--------------------------------------------------------------------------
*/

if (isset($_POST['process_order'])) {

    if (!$isCashier) {
        redirectToOrder(
            (int)($_POST['order_id'] ?? 0),
            'error',
            'Unauthorized.'
        );
    }

    $orderId =
        (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        redirectToOrder(
            $orderId,
            'error',
            'Invalid order.'
        );
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE orders
         SET status = 'Processing'
         WHERE id = ?
         AND status = 'Pending'"
    );

    if (!$stmt) {
        redirectToOrder(
            $orderId,
            'error',
            'Unable to process order.'
        );
    }

    mysqli_stmt_bind_param(
        $stmt,
        'i',
        $orderId
    );

    $success =
        mysqli_stmt_execute($stmt) &&
        mysqli_stmt_affected_rows($stmt) === 1;

    mysqli_stmt_close($stmt);

    if (!$success) {
        redirectToOrder(
            $orderId,
            'error',
            'Order could not be moved to Processing.'
        );
    }

    sendOrderStatusNotification(
        $conn,
        $orderId,
        'Order Processing',
        "Your BLACK HABIT order #{$orderId} is now being prepared."
    );

    redirectToOrder(
        $orderId,
        'success',
        'Order is now Processing.'
    );
}


/*
|--------------------------------------------------------------------------
| RECIPE QUANTITY / INVENTORY UNIT CONVERSION
|--------------------------------------------------------------------------
|
| Recipe Management uses base quantities for automatic formulas:
|   grams       -> kg when ingredient stock is stored in kg
|   milliliters -> L  when ingredient stock is stored in liters
|   pieces      -> unchanged
|
| Decimal values <= 1 for kg/L are already stored in the inventory unit.
| This keeps view_order.php consistent with Recipe Management.
|--------------------------------------------------------------------------
*/
function recipeQuantityForUnit($recipeQty, $unit): float
{
    $recipeQty = (float)$recipeQty;
    $unit = strtolower(trim((string)$unit));

    if ($recipeQty <= 0) {
        return 0.0;
    }

    switch ($unit) {
        case 'kg':
        case 'kgs':
        case 'kilogram':
        case 'kilograms':
            return $recipeQty > 1
                ? $recipeQty / 1000
                : $recipeQty;

        case 'l':
        case 'lt':
        case 'liter':
        case 'liters':
        case 'litre':
        case 'litres':
            return $recipeQty > 1
                ? $recipeQty / 1000
                : $recipeQty;

        case 'g':
        case 'gram':
        case 'grams':
        case 'ml':
        case 'milliliter':
        case 'milliliters':
        case 'millilitre':
        case 'millilitres':
        case 'pc':
        case 'pcs':
        case 'piece':
        case 'pieces':
        case 'unit':
        case 'units':
            return $recipeQty;

        default:
            return $recipeQty;
    }
}

/*
|--------------------------------------------------------------------------
| MARK READY + INVENTORY DEDUCTION
|--------------------------------------------------------------------------
*/

if (isset($_POST['ready_order'])) {

    if (!$isCashier) {
        redirectToOrder(
            (int)($_POST['order_id'] ?? 0),
            'error',
            'Unauthorized.'
        );
    }

    $orderId =
        (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        redirectToOrder(
            $orderId,
            'error',
            'Invalid order.'
        );
    }

    mysqli_begin_transaction($conn);

    try {

        /*
        |--------------------------------------------------------------------------
        | ORDER
        |--------------------------------------------------------------------------
        */

        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                id,
                status,
                order_type,
                total,
                paid_amount,
                balance
             FROM orders
             WHERE id = ?
             FOR UPDATE"
        );

        if (!$stmt) {
            throw new Exception(
                'Unable to load order.'
            );
        }

        mysqli_stmt_bind_param(
            $stmt,
            'i',
            $orderId
        );

        mysqli_stmt_execute($stmt);

        $result =
            mysqli_stmt_get_result($stmt);

        $orderReady =
            $result
                ? mysqli_fetch_assoc($result)
                : null;

        mysqli_stmt_close($stmt);

        if (!$orderReady) {
            throw new Exception(
                'Order not found.'
            );
        }

        if (
            ($orderReady['status'] ?? '') !==
            'Processing'
        ) {
            throw new Exception(
                'Only Processing orders can be marked as Ready.'
            );
        }

        if (
            ($orderReady['order_type'] ?? '') ===
            'Advance Order' &&
            (float)($orderReady['balance'] ?? 0) > 0
        ) {
            throw new Exception(
                'Pick-up Order must be fully paid before it can be marked as Ready.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | ORDER ITEMS
        |--------------------------------------------------------------------------
        */

        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                oi.id,
                oi.product_id,
                oi.quantity,
                oi.size,
                p.name,
                p.stock
             FROM order_items oi
             LEFT JOIN products p
                ON p.id = oi.product_id
             WHERE oi.order_id = ?
             ORDER BY oi.id ASC"
        );

        if (!$stmt) {
            throw new Exception(
                'Unable to load order items.'
            );
        }

        mysqli_stmt_bind_param(
            $stmt,
            'i',
            $orderId
        );

        mysqli_stmt_execute($stmt);

        $itemsResult =
            mysqli_stmt_get_result($stmt);

        mysqli_stmt_close($stmt);

        if (
            !$itemsResult ||
            mysqli_num_rows($itemsResult) === 0
        ) {
            throw new Exception(
                'This order has no products.'
            );
        }


        $productDeductions = [];
        $ingredientDeductions = [];
        $supplyDeductions = [];


        /*
        |--------------------------------------------------------------------------
        | BUILD DEDUCTIONS
        |--------------------------------------------------------------------------
        */

        while (
            $item =
            mysqli_fetch_assoc($itemsResult)
        ) {

            $productId =
                (int)($item['product_id'] ?? 0);

            $orderQty =
                (float)($item['quantity'] ?? 0);

            $productName =
                (string)($item['name'] ?? 'Unknown Product');

            if (
                $productId <= 0 ||
                $orderQty <= 0
            ) {
                throw new Exception(
                    'Invalid product quantity in order.'
                );
            }

            if (
                !isset(
                    $productDeductions[$productId]
                )
            ) {
                $productDeductions[$productId] = [
                    'product_name' =>
                        $productName,
                    'quantity' => 0,
                    'current_stock' =>
                        (float)($item['stock'] ?? 0)
                ];
            }

            $productDeductions[$productId]['quantity']
                += $orderQty;


            /*
            |--------------------------------------------------------------------------
            | SIZE
            |--------------------------------------------------------------------------
            */

            $size =
                trim((string)($item['size'] ?? ''));

            $qtyColumn =
                stripos($size, 'large') !== false
                    ? 'large_qty'
                    : 'regular_qty';


            /*
            |--------------------------------------------------------------------------
            | INGREDIENTS
            |--------------------------------------------------------------------------
            */

            $sql = "
                SELECT
                    r.ingredient_id,
                    r.$qtyColumn AS quantity_required,
                    i.ingredient_name,
                    i.current_stock,
                    i.unit
                FROM recipes r
                INNER JOIN ingredients i
                    ON i.id = r.ingredient_id
                WHERE r.product_id = ?
                FOR UPDATE
            ";

            $stmt =
                mysqli_prepare(
                    $conn,
                    $sql
                );

            if (!$stmt) {
                throw new Exception(
                    'Unable to load ingredient recipe for ' .
                    $productName . '.'
                );
            }

            mysqli_stmt_bind_param(
                $stmt,
                'i',
                $productId
            );

            mysqli_stmt_execute($stmt);

            $recipeResult =
                mysqli_stmt_get_result($stmt);

            mysqli_stmt_close($stmt);

            while (
                $recipe =
                mysqli_fetch_assoc($recipeResult)
            ) {

                $ingredientId =
                    (int)$recipe['ingredient_id'];

                $required =
                    (float)$recipe['quantity_required'];

                $currentStock =
                    (float)$recipe['current_stock'];

                if (
                    $ingredientId <= 0 ||
                    $required < 0
                ) {
                    throw new Exception(
                        'Invalid ingredient recipe for ' .
                        $productName . '.'
                    );
                }

                // Recipe quantities are normalized to the inventory unit
                // before stock validation and deduction.
                $requiredInStockUnit =
                    recipeQuantityForUnit(
                        $required,
                        (string)($recipe['unit'] ?? '')
                    );

                $needed =
                    $requiredInStockUnit * $orderQty;

                if (
                    !isset(
                        $ingredientDeductions[
                            $ingredientId
                        ]
                    )
                ) {
                    $ingredientDeductions[
                        $ingredientId
                    ] = [
                        'ingredient_name' =>
                            $recipe['ingredient_name'],
                        'unit' =>
                            $recipe['unit'],
                        'quantity' => 0,
                        'current_stock' =>
                            $currentStock
                    ];
                }

                $ingredientDeductions[
                    $ingredientId
                ]['quantity'] += $needed;
            }


            /*
            |--------------------------------------------------------------------------
            | SUPPLIES
            |--------------------------------------------------------------------------
            */

            $sql = "
                SELECT
                    ps.supply_id,
                    ps.$qtyColumn AS quantity_required,
                    s.supply_name,
                    s.current_stock,
                    s.unit
                FROM product_supplies ps
                INNER JOIN supplies s
                    ON s.id = ps.supply_id
                WHERE ps.product_id = ?
                FOR UPDATE
            ";

            $stmt =
                mysqli_prepare(
                    $conn,
                    $sql
                );

            if (!$stmt) {
                throw new Exception(
                    'Unable to load supply mapping for ' .
                    $productName . '.'
                );
            }

            mysqli_stmt_bind_param(
                $stmt,
                'i',
                $productId
            );

            mysqli_stmt_execute($stmt);

            $supplyResult =
                mysqli_stmt_get_result($stmt);

            mysqli_stmt_close($stmt);

            while (
                $supply =
                mysqli_fetch_assoc($supplyResult)
            ) {

                $supplyId =
                    (int)$supply['supply_id'];

                $required =
                    (float)$supply['quantity_required'];

                $currentStock =
                    (float)$supply['current_stock'];

                if (
                    $supplyId <= 0 ||
                    $required < 0
                ) {
                    throw new Exception(
                        'Invalid supply mapping for ' .
                        $productName . '.'
                    );
                }

                // Supply recipes use the same unit-normalization rule.
                $requiredInStockUnit =
                    recipeQuantityForUnit(
                        $required,
                        (string)($supply['unit'] ?? '')
                    );

                $needed =
                    $requiredInStockUnit * $orderQty;

                if (
                    !isset(
                        $supplyDeductions[$supplyId]
                    )
                ) {
                    $supplyDeductions[
                        $supplyId
                    ] = [
                        'supply_name' =>
                            $supply['supply_name'],
                        'unit' =>
                            $supply['unit'],
                        'quantity' => 0,
                        'current_stock' =>
                            $currentStock
                    ];
                }

                $supplyDeductions[
                    $supplyId
                ]['quantity'] += $needed;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDATE PRODUCT STOCK
        |--------------------------------------------------------------------------
        */

        foreach (
            $productDeductions
            as $deduction
        ) {

            if (
                $deduction['quantity'] >
                $deduction['current_stock']
            ) {
                throw new Exception(
                    'Not enough product stock for ' .
                    $deduction['product_name'] .
                    '. Required: ' .
                    number_format(
                        $deduction['quantity'],
                        0
                    ) .
                    ', Available: ' .
                    number_format(
                        $deduction['current_stock'],
                        0
                    ) .
                    '.'
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDATE INGREDIENT STOCK
        |--------------------------------------------------------------------------
        */

        foreach (
            $ingredientDeductions
            as $deduction
        ) {

            if (
                $deduction['quantity'] >
                $deduction['current_stock']
            ) {
                throw new Exception(
                    'Not enough ' .
                    $deduction['ingredient_name'] .
                    '. Required: ' .
                    number_format(
                        $deduction['quantity'],
                        2
                    ) .
                    ' ' .
                    $deduction['unit'] .
                    ', Available: ' .
                    number_format(
                        $deduction['current_stock'],
                        2
                    ) .
                    ' ' .
                    $deduction['unit'] .
                    '.'
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDATE SUPPLIES
        |--------------------------------------------------------------------------
        */

        foreach (
            $supplyDeductions
            as $deduction
        ) {

            if (
                $deduction['quantity'] >
                $deduction['current_stock']
            ) {
                throw new Exception(
                    'Not enough supply: ' .
                    $deduction['supply_name'] .
                    '. Required: ' .
                    number_format(
                        $deduction['quantity'],
                        2
                    ) .
                    ' ' .
                    $deduction['unit'] .
                    ', Available: ' .
                    number_format(
                        $deduction['current_stock'],
                        2
                    ) .
                    ' ' .
                    $deduction['unit'] .
                    '.'
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | DEDUCT PRODUCTS
        |--------------------------------------------------------------------------
        */

        foreach (
            $productDeductions
            as $productId => $deduction
        ) {

            $qty =
                (float)$deduction['quantity'];

            if ($qty <= 0) {
                continue;
            }

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE products
                 SET stock = stock - ?
                 WHERE id = ?
                 AND stock >= ?"
            );

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare product stock deduction.'
                );
            }

            mysqli_stmt_bind_param(
                $stmt,
                'did',
                $qty,
                $productId,
                $qty
            );

            if (
                !mysqli_stmt_execute($stmt) ||
                mysqli_stmt_affected_rows($stmt) !== 1
            ) {
                mysqli_stmt_close($stmt);

                throw new Exception(
                    'Unable to deduct product stock for ' .
                    $deduction['product_name'] .
                    '.'
                );
            }

            mysqli_stmt_close($stmt);
        }


        /*
        |--------------------------------------------------------------------------
        | DEDUCT INGREDIENTS
        |--------------------------------------------------------------------------
        */

        foreach (
            $ingredientDeductions
            as $ingredientId => $deduction
        ) {

            $qty =
                (float)$deduction['quantity'];

            if ($qty <= 0) {
                continue;
            }

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE ingredients
                 SET current_stock =
                     current_stock - ?
                 WHERE id = ?
                 AND current_stock >= ?"
            );

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare ingredient deduction.'
                );
            }

            mysqli_stmt_bind_param(
                $stmt,
                'did',
                $qty,
                $ingredientId,
                $qty
            );

            if (
                !mysqli_stmt_execute($stmt) ||
                mysqli_stmt_affected_rows($stmt) !== 1
            ) {
                mysqli_stmt_close($stmt);

                throw new Exception(
                    'Unable to deduct ingredient ' .
                    $deduction['ingredient_name'] .
                    '.'
                );
            }

            mysqli_stmt_close($stmt);

            $reason =
                'Automatic deduction from order';

            $releasedBy =
                'System';

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO stock_out
                (
                    ingredient_id,
                    supply_id,
                    quantity,
                    reason,
                    released_by,
                    date_out,
                    order_id
                )
                VALUES
                (?, NULL, ?, ?, ?, NOW(), ?)"
            );

            if (!$stmt) {
                throw new Exception(
                    'Unable to record ingredient Stock Out.'
                );
            }

            mysqli_stmt_bind_param(
                $stmt,
                'idssi',
                $ingredientId,
                $qty,
                $reason,
                $releasedBy,
                $orderId
            );

            if (!mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);

                throw new Exception(
                    'Unable to record ingredient Stock Out.'
                );
            }

            mysqli_stmt_close($stmt);
        }


        /*
        |--------------------------------------------------------------------------
        | DEDUCT SUPPLIES
        |--------------------------------------------------------------------------
        */

        foreach (
            $supplyDeductions
            as $supplyId => $deduction
        ) {

            $qty =
                (float)$deduction['quantity'];

            if ($qty <= 0) {
                continue;
            }

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE supplies
                 SET current_stock =
                     current_stock - ?
                 WHERE id = ?
                 AND current_stock >= ?"
            );

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare supply deduction.'
                );
            }

            mysqli_stmt_bind_param(
                $stmt,
                'did',
                $qty,
                $supplyId,
                $qty
            );

            if (
                !mysqli_stmt_execute($stmt) ||
                mysqli_stmt_affected_rows($stmt) !== 1
            ) {
                mysqli_stmt_close($stmt);

                throw new Exception(
                    'Unable to deduct supply ' .
                    $deduction['supply_name'] .
                    '.'
                );
            }

            mysqli_stmt_close($stmt);

            $reason =
                'Automatic deduction from order';

            $releasedBy =
                'System';

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO stock_out
                (
                    ingredient_id,
                    supply_id,
                    quantity,
                    reason,
                    released_by,
                    date_out,
                    order_id
                )
                VALUES
                (NULL, ?, ?, ?, ?, NOW(), ?)"
            );

            if (!$stmt) {
                throw new Exception(
                    'Unable to record supply Stock Out.'
                );
            }

            mysqli_stmt_bind_param(
                $stmt,
                'idssi',
                $supplyId,
                $qty,
                $reason,
                $releasedBy,
                $orderId
            );

            if (!mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);

                throw new Exception(
                    'Unable to record supply Stock Out.'
                );
            }

            mysqli_stmt_close($stmt);
        }


        /*
        |--------------------------------------------------------------------------
        | MARK READY
        |--------------------------------------------------------------------------
        */

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE orders
             SET status = 'Ready'
             WHERE id = ?
             AND status = 'Processing'"
        );

        if (!$stmt) {
            throw new Exception(
                'Unable to update order status.'
            );
        }

        mysqli_stmt_bind_param(
            $stmt,
            'i',
            $orderId
        );

        if (
            !mysqli_stmt_execute($stmt) ||
            mysqli_stmt_affected_rows($stmt) !== 1
        ) {
            mysqli_stmt_close($stmt);

            throw new Exception(
                'Unable to mark the order as Ready.'
            );
        }

        mysqli_stmt_close($stmt);

        mysqli_commit($conn);


        sendOrderStatusNotification(
            $conn,
            $orderId,
            'Order Ready',
            "Your BLACK HABIT order #{$orderId} is ready for pickup!"
        );

        redirectToOrder(
            $orderId,
            'success',
            'Order is Ready. Inventory was deducted successfully.'
        );

    } catch (Throwable $e) {

        mysqli_rollback($conn);

        $message =
            $e->getMessage();

        if (
            stripos(
                $message,
                'Not enough '
            ) === 0
        ) {

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE orders
                 SET status = 'Pending'
                 WHERE id = ?
                 AND status = 'Processing'"
            );

            if ($stmt) {

                mysqli_stmt_bind_param(
                    $stmt,
                    'i',
                    $orderId
                );

                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }

        redirectToOrder(
            $orderId,
            'error',
            $message
        );
    }
}


/*
|--------------------------------------------------------------------------
| COMPLETE ORDER
|--------------------------------------------------------------------------
*/

if (isset($_POST['complete_order'])) {

    if (!$isCashier) {
        redirectToOrder(
            (int)($_POST['order_id'] ?? 0),
            'error',
            'Unauthorized.'
        );
    }

    $orderId =
        (int)($_POST['order_id'] ?? 0);

    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            id,
            status,
            order_type,
            balance
         FROM orders
         WHERE id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        redirectToOrder(
            $orderId,
            'error',
            'Unable to load order.'
        );
    }

    mysqli_stmt_bind_param(
        $stmt,
        'i',
        $orderId
    );

    mysqli_stmt_execute($stmt);

    $result =
        mysqli_stmt_get_result($stmt);

    $orderComplete =
        $result
            ? mysqli_fetch_assoc($result)
            : null;

    mysqli_stmt_close($stmt);

    if (!$orderComplete) {
        redirectToOrder(
            $orderId,
            'error',
            'Order not found.'
        );
    }

    if (
        ($orderComplete['status'] ?? '') !==
        'Ready'
    ) {
        redirectToOrder(
            $orderId,
            'error',
            'Only Ready orders can be completed.'
        );
    }

    if (
        ($orderComplete['order_type'] ?? '') ===
        'Advance Order' &&
        (float)($orderComplete['balance'] ?? 0) > 0
    ) {
        redirectToOrder(
            $orderId,
            'error',
            'Pick-up Order must be fully paid first.'
        );
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE orders
         SET status = 'Completed'
         WHERE id = ?
         AND status = 'Ready'"
    );

    if (!$stmt) {
        redirectToOrder(
            $orderId,
            'error',
            'Unable to complete order.'
        );
    }

    mysqli_stmt_bind_param(
        $stmt,
        'i',
        $orderId
    );

    $success =
        mysqli_stmt_execute($stmt) &&
        mysqli_stmt_affected_rows($stmt) === 1;

    mysqli_stmt_close($stmt);

    if (!$success) {
        redirectToOrder(
            $orderId,
            'error',
            'Unable to complete order.'
        );
    }

    sendOrderStatusNotification(
        $conn,
        $orderId,
        'Order Completed',
        "Your BLACK HABIT order #{$orderId} has been completed. Thank you!"
    );

    header(
        'Location: cashier_order_management.php?success=completed'
    );
    exit();
}


/*
|--------------------------------------------------------------------------
| CANCEL ORDER
|--------------------------------------------------------------------------
*/

if (isset($_POST['cancel_order'])) {

    if (!$isCashier) {
        redirectToOrder(
            (int)($_POST['order_id'] ?? 0),
            'error',
            'Unauthorized.'
        );
    }

    $orderId =
        (int)($_POST['order_id'] ?? 0);

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE orders
         SET status = 'Cancelled'
         WHERE id = ?
         AND status IN ('Pending','Processing')"
    );

    if (!$stmt) {
        redirectToOrder(
            $orderId,
            'error',
            'Unable to cancel order.'
        );
    }

    mysqli_stmt_bind_param(
        $stmt,
        'i',
        $orderId
    );

    $success =
        mysqli_stmt_execute($stmt) &&
        mysqli_stmt_affected_rows($stmt) === 1;

    mysqli_stmt_close($stmt);

    if (!$success) {
        redirectToOrder(
            $orderId,
            'error',
            'Order could not be cancelled.'
        );
    }

    sendOrderStatusNotification(
        $conn,
        $orderId,
        'Order Cancelled',
        "Your BLACK HABIT order #{$orderId} has been cancelled."
    );

    header(
        'Location: cashier_order_management.php?success=cancelled'
    );
    exit();
}


/*
|--------------------------------------------------------------------------
| LOAD ORDER
|--------------------------------------------------------------------------
*/

$orderId =
    (int)($_GET['id'] ?? 0);

if ($orderId <= 0) {
    die('Invalid Order ID.');
}


/*
|--------------------------------------------------------------------------
| ORDER INFORMATION
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        orders.*,
        users.first_name,
        users.middle_name,
        users.last_name
     FROM orders
     LEFT JOIN users
        ON orders.user_id = users.id
     WHERE orders.id = ?
     LIMIT 1"
);

if (!$stmt) {
    die(
        'Unable to prepare order query: ' .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $stmt,
    'i',
    $orderId
);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

$orderData =
    $result
        ? mysqli_fetch_assoc($result)
        : null;

mysqli_stmt_close($stmt);

if (!$orderData) {
    die('Order not found.');
}


/*
|--------------------------------------------------------------------------
| CUSTOMER
|--------------------------------------------------------------------------
*/

$name = trim(
    ($orderData['first_name'] ?? '') .
    ' ' .
    ($orderData['middle_name'] ?? '') .
    ' ' .
    ($orderData['last_name'] ?? '')
);

if ($name === '') {
    $name = 'Dine-In Customer';
}


/*
|--------------------------------------------------------------------------
| ITEMS
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        oi.*,
        p.name
     FROM order_items oi
     LEFT JOIN products p
        ON oi.product_id = p.id
     WHERE oi.order_id = ?
     ORDER BY oi.id ASC"
);

if (!$stmt) {
    die(
        'Unable to prepare order items query: ' .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $stmt,
    'i',
    $orderId
);

mysqli_stmt_execute($stmt);

$orderItems =
    mysqli_stmt_get_result($stmt);

mysqli_stmt_close($stmt);


/*
|--------------------------------------------------------------------------
| PAYMENT
|--------------------------------------------------------------------------
*/

$totalAmount =
    (float)($orderData['total'] ?? 0);

$paidAmount =
    (float)($orderData['paid_amount'] ?? 0);

$balance =
    (float)($orderData['balance'] ?? 0);

if ($balance < 0) {
    $balance = 0;
}

if (
    $balance <= 0 &&
    $totalAmount > 0
) {
    $paymentStatus = 'Fully Paid';
} elseif ($paidAmount > 0) {
    $paymentStatus = 'Partially Paid';
} else {
    $paymentStatus = 'Unpaid';
}


/*
|--------------------------------------------------------------------------
| MESSAGES
|--------------------------------------------------------------------------
*/

$successMessage =
    isset($_GET['success'])
        ? urldecode((string)$_GET['success'])
        : '';

$errorMessage =
    isset($_GET['error'])
        ? urldecode((string)$_GET['error'])
        : '';


/*
|--------------------------------------------------------------------------
| DISPLAY VALUES
|--------------------------------------------------------------------------
*/

$orderNumber =
    blackHabitOrderNumber(
        (int)$orderData['id'],
        (string)($orderData['created_at'] ?? '')
    );

$rawOrderType =
    trim((string)($orderData['order_type'] ?? ''));

$displayOrderType =
    $rawOrderType === 'Advance Order'
        ? 'Pick-up Order'
        : ($rawOrderType !== ''
            ? $rawOrderType
            : 'N/A');

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    View Order - BLACKHABIT
</title>

<link
    rel="stylesheet"
    href="style.css?v=<?php echo time(); ?>"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
>

<style>

.order-page{
    max-width:1100px;
    margin:40px auto;
}

.alert{
    padding:14px 18px;
    border-radius:8px;
    margin-bottom:20px;
    font-weight:600;
}

.alert-success{
    background:#d1e7dd;
    color:#0f5132;
    border:1px solid #badbcc;
}

.alert-error{
    background:#f8d7da;
    color:#842029;
    border:1px solid #f5c2c7;
}

.payment-summary{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:15px;
    margin:20px 0;
}

.payment-card{
    background:#181818;
    border:1px solid #2b2b2b;
    border-radius:10px;
    padding:18px;
}

.payment-card small{
    display:block;
    color:#aaa;
    margin-bottom:7px;
}

.payment-card strong{
    font-size:22px;
    color:#fff;
}

.action-row{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-top:20px;
}

.action-row form{
    margin:0;
}

.product-table td,
.product-table th{
    vertical-align:middle;
}

@media(max-width:700px){

    .order-page{
        margin:20px 10px;
    }

    .payment-summary{
        grid-template-columns:1fr;
    }

}

</style>

</head>

<body>

<div class="panel order-page">

    <!-- HEADER -->

    <div class="panel-header">

        <h2>
            <i class="fa-solid fa-receipt"></i>
            Order Details
        </h2>

        <a
            href="<?php echo e(
                $isCashier
                    ? 'cashier_order_management.php'
                    : 'admin_order_management.php'
            ); ?>"
            class="btn"
            style="
                width:auto;
                display:inline-flex;
                align-items:center;
                gap:8px;
            "
        >
            <i class="fa-solid fa-arrow-left"></i>
            Back
        </a>

    </div>


    <!-- PRINT BUTTONS -->

    <div
        style="
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            margin-top:12px;
        "
    >

        <a
            href="print_kitchen_order.php?id=<?php
                echo (int)$orderData['id'];
            ?>&from=<?php
                echo e($currentFrom);
            ?>"
            target="_blank"
            class="btn"
            style="
                background:#c59d5f;
                color:#111;
            "
        >
            <i class="fa-solid fa-print"></i>
            Print Kitchen Order
        </a>

    </div>


    <!-- SUCCESS -->

    <?php if ($successMessage !== ''): ?>

        <div class="alert alert-success">

            <i class="fa-solid fa-circle-check"></i>

            <?php echo e($successMessage); ?>

        </div>

    <?php endif; ?>


    <!-- ERROR -->

    <?php if ($errorMessage !== ''): ?>

        <div class="alert alert-error">

            <i class="fa-solid fa-circle-exclamation"></i>

            <?php echo e($errorMessage); ?>

        </div>

    <?php endif; ?>


    <!-- ORDER INFORMATION -->

    <table class="product-table">

        <tr>

            <td>
                <strong>Order Number</strong>
            </td>

            <td>

                <strong
                    style="color:#c59d5f;"
                >
                    <?php echo e($orderNumber); ?>
                </strong>

                <?php if (
                    $rawOrderType === 'Advance Order'
                ): ?>

                    <a
                        href="print_receipt.php?id=<?php
                            echo (int)$orderData['id'];
                        ?>&from=<?php
                            echo e($currentFrom);
                        ?>"
                        target="_blank"
                        style="
                            margin-left:12px;
                            color:#c59d5f;
                            text-decoration:none;
                        "
                    >
                        <i class="fa-solid fa-receipt"></i>
                        Print Black Habit Receipt
                    </a>

                <?php endif; ?>

            </td>

        </tr>


        <tr>

            <td>
                <strong>Customer</strong>
            </td>

            <td>
                <?php echo e($name); ?>
            </td>

        </tr>


        <tr>

            <td>
                <strong>Order Type</strong>
            </td>

            <td>
                <?php echo e($displayOrderType); ?>
            </td>

        </tr>


        <tr>

            <td>
                <strong>Payment Method</strong>
            </td>

            <td>
                <?php echo e(
                    $orderData['payment_method']
                        ?? 'N/A'
                ); ?>
            </td>

        </tr>


        <tr>

            <td>
                <strong>Payment Type</strong>
            </td>

            <td>
                <?php echo e(
                    $orderData['payment_type']
                        ?? 'N/A'
                ); ?>
            </td>

        </tr>


        <tr>

            <td>
                <strong>Status</strong>
            </td>

            <td>

                <?php
                $status =
                    $orderData['status'] ?? 'Pending';
                ?>

                <?php if ($status === 'Completed'): ?>

                    <span class="badge success">
                        <i class="fa-solid fa-circle-check"></i>
                        Completed
                    </span>

                <?php elseif ($status === 'Cancelled'): ?>

                    <span class="badge danger">
                        <i class="fa-solid fa-ban"></i>
                        Cancelled
                    </span>

                <?php elseif ($status === 'Processing'): ?>

                    <span
                        class="badge"
                        style="
                            background:#2196f3;
                            color:#fff;
                        "
                    >
                        <i class="fa-solid fa-spinner"></i>
                        Processing
                    </span>

                <?php elseif ($status === 'Ready'): ?>

                    <span
                        class="badge"
                        style="
                            background:#9c27b0;
                            color:#fff;
                        "
                    >
                        <i class="fa-solid fa-bell"></i>
                        Ready
                    </span>

                <?php else: ?>

                    <span class="badge warning">
                        <i class="fa-solid fa-clock"></i>
                        Pending
                    </span>

                <?php endif; ?>

            </td>

        </tr>


        <tr>

            <td>
                <strong>Date Ordered</strong>
            </td>

            <td>

                <?php

                if (!empty($orderData['created_at'])) {

                    echo e(
                        date(
                            'M d, Y g:i A',
                            strtotime(
                                $orderData['created_at']
                            )
                        )
                    );

                } else {

                    echo 'N/A';

                }

                ?>

            </td>

        </tr>

    </table>


    <!-- PAYMENT SUMMARY -->

    <div class="payment-summary">

        <div class="payment-card">

            <small>Total Amount</small>

            <strong>
                ₱<?php echo number_format(
                    $totalAmount,
                    2
                ); ?>
            </strong>

        </div>


        <div class="payment-card">

            <small>Paid Amount</small>

            <strong>
                ₱<?php echo number_format(
                    $paidAmount,
                    2
                ); ?>
            </strong>

        </div>


        <div class="payment-card">

            <small>Remaining Balance</small>

            <strong
                style="
                    color:
                    <?php echo $balance > 0
                        ? '#dc3545'
                        : '#28a745'; ?>;
                "
            >
                ₱<?php echo number_format(
                    $balance,
                    2
                ); ?>
            </strong>

        </div>

    </div>


    <!-- PAYMENT STATUS -->

    <div style="margin-bottom:20px;">

        <strong>
            Payment Status:
        </strong>

        <?php if (
            $paymentStatus === 'Fully Paid'
        ): ?>

            <span class="badge success">
                <i class="fa-solid fa-circle-check"></i>
                Fully Paid
            </span>

        <?php elseif (
            $paymentStatus === 'Partially Paid'
        ): ?>

            <span class="badge warning">
                <i class="fa-solid fa-clock"></i>
                Partially Paid
            </span>

        <?php else: ?>

            <span class="badge danger">
                <i class="fa-solid fa-circle-exclamation"></i>
                Unpaid
            </span>

        <?php endif; ?>

    </div>


    <!-- PRODUCTS -->

    <h3>
        <i class="fa-solid fa-mug-hot"></i>
        Products Ordered
    </h3>


    <div class="table-container">

        <table class="product-table">

            <thead>

                <tr>

                    <th>Product</th>
                    <th>Flavor</th>
                    <th>Size</th>
                    <th>Sugar</th>
                    <th>Ice</th>
                    <th>Qty</th>
                    <th>Price</th>

                </tr>

            </thead>

            <tbody>

            <?php if (
                $orderItems &&
                mysqli_num_rows($orderItems) > 0
            ): ?>

                <?php while (
                    $item =
                    mysqli_fetch_assoc($orderItems)
                ): ?>

                    <tr>

                        <td>
                            <?php echo e(
                                $item['name']
                                    ?? 'Unknown Product'
                            ); ?>
                        </td>

                        <td>
                            <?php echo e(
                                $item['flavor']
                                    ?? 'N/A'
                            ); ?>
                        </td>

                        <td>
                            <?php echo e(
                                $item['size']
                                    ?? 'Regular'
                            ); ?>
                        </td>

                        <td>
                            <?php echo e(
                                $item['sugar']
                                    ?? 'N/A'
                            ); ?>
                        </td>

                        <td>
                            <?php echo e(
                                $item['ice']
                                    ?? 'N/A'
                            ); ?>
                        </td>

                        <td>
                            <?php echo number_format(
                                (int)($item['quantity'] ?? 0)
                            ); ?>
                        </td>

                        <td>
                            ₱<?php echo number_format(
                                (float)($item['price'] ?? 0),
                                2
                            ); ?>
                        </td>

                    </tr>

                <?php endwhile; ?>

            <?php else: ?>

                <tr>

                    <td
                        colspan="7"
                        style="
                            text-align:center;
                            padding:35px;
                            color:#999;
                        "
                    >
                        No products found.
                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>


    <!-- GRAND TOTAL -->

    <div
        style="
            text-align:right;
            margin:25px 0;
        "
    >

        <h2>

            Grand Total

            <span
                style="color:#c59d5f;"
            >
                ₱<?php echo number_format(
                    $totalAmount,
                    2
                ); ?>
            </span>

        </h2>

    </div>


    <!-- CASHIER ACTIONS -->

    <?php if (
        $isCashier &&
        in_array(
            $status,
            [
                'Pending',
                'Processing',
                'Ready'
            ],
            true
        )
    ): ?>


        <!-- RECEIVE PAYMENT -->

        <?php if (
            $rawOrderType === 'Advance Order' &&
            $balance > 0
        ): ?>

            <div
                class="panel"
                style="margin-bottom:20px;"
            >

                <div class="panel-header">

                    <h3>
                        <i class="fa-solid fa-money-bill-wave"></i>
                        Receive Payment
                    </h3>

                </div>

                <form method="POST">

                    <input
                        type="hidden"
                        name="order_id"
                        value="<?php echo (int)$orderId; ?>"
                    >

                    <label>
                        Payment Amount
                    </label>

                    <input
                        type="number"
                        name="payment"
                        step="0.01"
                        min="0.01"
                        max="<?php echo e(
                            number_format(
                                $balance,
                                2,
                                '.',
                                ''
                            )
                        ); ?>"
                        required
                        class="form-control"
                        placeholder="Enter payment amount"
                    >

                    <br>

                    <button
                        type="submit"
                        name="receive_payment"
                        class="btn"
                        style="background:#0d6efd;"
                    >
                        <i class="fa-solid fa-wallet"></i>
                        Receive Payment
                    </button>

                </form>

            </div>

        <?php endif; ?>


        <!-- ORDER ACTIONS -->

        <div class="action-row">


            <?php if ($status === 'Pending'): ?>

                <form method="POST">

                    <input
                        type="hidden"
                        name="order_id"
                        value="<?php echo (int)$orderId; ?>"
                    >

                    <button
                        type="submit"
                        name="process_order"
                        class="btn"
                        style="background:#2196f3;"
                        onclick="
                            return confirm(
                                'Start processing this order?'
                            );
                        "
                    >
                        <i class="fa-solid fa-spinner"></i>
                        Process Order
                    </button>

                </form>

            <?php endif; ?>


            <?php if ($status === 'Processing'): ?>

                <form method="POST">

                    <input
                        type="hidden"
                        name="order_id"
                        value="<?php echo (int)$orderId; ?>"
                    >

                    <button
                        type="submit"
                        name="ready_order"
                        class="btn"
                        style="background:#9c27b0;"
                        onclick="
                            return confirm(
                                'Mark this order as Ready?'
                            );
                        "
                    >
                        <i class="fa-solid fa-bell"></i>
                        Mark as Ready
                    </button>

                </form>

            <?php endif; ?>


            <?php if ($status === 'Ready'): ?>

                <?php
                $canComplete =
                    $rawOrderType !== 'Advance Order' ||
                    $balance <= 0;
                ?>

                <?php if ($canComplete): ?>

                    <form method="POST">

                        <input
                            type="hidden"
                            name="order_id"
                            value="<?php echo (int)$orderId; ?>"
                        >

                        <button
                            type="submit"
                            name="complete_order"
                            class="btn"
                            style="background:#28a745;"
                            onclick="
                                return confirm(
                                    'Complete this order?'
                                );
                            "
                        >
                            <i class="fa-solid fa-circle-check"></i>
                            Complete Order
                        </button>

                    </form>

                <?php else: ?>

                    <button
                        class="btn"
                        style="
                            background:#777;
                            cursor:not-allowed;
                        "
                        disabled
                    >
                        <i class="fa-solid fa-lock"></i>
                        Complete Payment First
                    </button>

                <?php endif; ?>

            <?php endif; ?>


            <?php if (
                $status === 'Pending' ||
                $status === 'Processing'
            ): ?>

                <form method="POST">

                    <input
                        type="hidden"
                        name="order_id"
                        value="<?php echo (int)$orderId; ?>"
                    >

                    <button
                        type="submit"
                        name="cancel_order"
                        class="btn"
                        style="background:#dc3545;"
                        onclick="
                            return confirm(
                                'Cancel this order?'
                            );
                        "
                    >
                        <i class="fa-solid fa-ban"></i>
                        Cancel Order
                    </button>

                </form>

            <?php endif; ?>

        </div>


        <div
            style="
                margin-top:15px;
                padding:14px;
                border-radius:8px;
                background:#181818;
                border:1px solid #2b2b2b;
                color:#aaa;
            "
        >
            <i class="fa-solid fa-circle-info"></i>

            Inventory ingredients and supplies
            are validated and deducted automatically
            when the order is marked as Ready.

        </div>

    <?php endif; ?>


    <!-- ADMIN -->

    <?php if ($isAdmin): ?>

        <div
            style="
                margin-top:20px;
                padding:14px;
                border-radius:8px;
                background:#181818;
                border:1px solid #2b2b2b;
                color:#aaa;
            "
        >

            <i class="fa-solid fa-eye"></i>

            Admin monitoring mode:
            order status and payment actions
            are handled by the cashier.

        </div>

    <?php endif; ?>

</div>

</body>

</html>
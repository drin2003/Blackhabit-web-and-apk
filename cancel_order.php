```php
<?php

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';

if (ob_get_level()) {
    ob_clean();
}

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

function respond($data, $code = 200)
{
    http_response_code($code);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| TABLE EXISTS
|--------------------------------------------------------------------------
*/

function tableExists($conn, $table)
{
    $table = mysqli_real_escape_string($conn, $table);

    $result = mysqli_query(
        $conn,
        "SHOW TABLES LIKE '$table'"
    );

    return $result && mysqli_num_rows($result) > 0;
}

/*
|--------------------------------------------------------------------------
| DATABASE CHECK
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !$conn) {
    respond([
        'success' => false,
        'message' => 'Database connection failed.'
    ], 500);
}

/*
|--------------------------------------------------------------------------
| REQUEST METHOD
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond([
        'success' => false,
        'message' => 'POST request required.'
    ], 405);
}

/*
|--------------------------------------------------------------------------
| READ JSON
|--------------------------------------------------------------------------
*/

$rawInput = file_get_contents('php://input');

$data = json_decode($rawInput, true);

if (!is_array($data)) {
    respond([
        'success' => false,
        'message' => 'Invalid JSON request.'
    ], 400);
}

/*
|--------------------------------------------------------------------------
| GET ORDER ID
|--------------------------------------------------------------------------
*/

$order_id = isset($data['order_id'])
    ? (int)$data['order_id']
    : 0;

/*
|--------------------------------------------------------------------------
| USER ID
|--------------------------------------------------------------------------
|
| user_id may be NULL/0 for Dine-In orders because Dine-In
| does not require customer login.
|
|--------------------------------------------------------------------------
*/

$user_id = isset($data['user_id'])
    ? (int)$data['user_id']
    : 0;

if ($order_id <= 0) {
    respond([
        'success' => false,
        'message' => 'Invalid order ID.'
    ], 400);
}

/*
|--------------------------------------------------------------------------
| START TRANSACTION
|--------------------------------------------------------------------------
*/

mysqli_begin_transaction($conn);

try {

    /*
    |--------------------------------------------------------------------------
    | GET ORDER
    |--------------------------------------------------------------------------
    */

    if ($user_id > 0) {

        /*
        | Logged-in customer
        */

        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                id,
                user_id,
                order_type,
                total,
                paid_amount,
                balance,
                status,
                payment_status
             FROM orders
             WHERE id = ?
               AND user_id = ?
             LIMIT 1
             FOR UPDATE"
        );

        if (!$stmt) {
            throw new Exception(
                'Unable to prepare order query: ' .
                mysqli_error($conn)
            );
        }

        mysqli_stmt_bind_param(
            $stmt,
            'ii',
            $order_id,
            $user_id
        );

    } else {

        /*
        | Dine-In / guest customer
        |
        | user_id may be NULL.
        */

        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                id,
                user_id,
                order_type,
                total,
                paid_amount,
                balance,
                status,
                payment_status
             FROM orders
             WHERE id = ?
             LIMIT 1
             FOR UPDATE"
        );

        if (!$stmt) {
            throw new Exception(
                'Unable to prepare guest order query: ' .
                mysqli_error($conn)
            );
        }

        mysqli_stmt_bind_param(
            $stmt,
            'i',
            $order_id
        );
    }

    /*
    |--------------------------------------------------------------------------
    | EXECUTE ORDER QUERY
    |--------------------------------------------------------------------------
    */

    if (!mysqli_stmt_execute($stmt)) {

        $error = mysqli_stmt_error($stmt);

        mysqli_stmt_close($stmt);

        throw new Exception(
            'Unable to read order: ' . $error
        );
    }

    $result = mysqli_stmt_get_result($stmt);

    if (!$result) {

        $error = mysqli_stmt_error($stmt);

        mysqli_stmt_close($stmt);

        throw new Exception(
            'Unable to retrieve order: ' . $error
        );
    }

    $order = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    /*
    |--------------------------------------------------------------------------
    | ORDER NOT FOUND
    |--------------------------------------------------------------------------
    */

    if (!$order) {
        throw new Exception(
            'Order not found.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ORDER OWNERSHIP / ORDER TYPE SAFETY
    |--------------------------------------------------------------------------
    |
    | user_id = 0 is only valid for guest Dine-In orders.
    | A guest request must never be able to cancel a Pick-up/Advance Order
    | just by knowing its numeric order ID.
    |
    */
    if ($user_id === 0) {
        $orderType = strtolower(
            trim((string)($order['order_type'] ?? ''))
        );

        $isDineIn =
            $orderType === 'dine in' ||
            $orderType === 'dine-in' ||
            $orderType === 'dine_in';

        if (!$isDineIn) {
            throw new Exception(
                'Only Dine-In orders can be cancelled without a customer login.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ORDER STATUS
    |--------------------------------------------------------------------------
    */

    $status = strtolower(
        trim(
            (string)$order['status']
        )
    );

    /*
    |--------------------------------------------------------------------------
    | ALREADY CANCELLED
    |--------------------------------------------------------------------------
    */

    if ($status === 'cancelled') {

        mysqli_commit($conn);

        respond([
            'success' => true,
            'message' => 'Order is already cancelled.',
            'order_id' => $order_id,
            'status' => 'Cancelled'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ONLY PENDING ORDERS
    |--------------------------------------------------------------------------
    */

    if ($status !== 'pending') {

        throw new Exception(
            'Only pending orders can be cancelled. Current status: ' .
            $order['status']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | IMPORTANT INVENTORY RULE
    |--------------------------------------------------------------------------
    |
    | Your current Black Habit system does NOT deduct inventory
    | when create_order.php creates the order.
    |
    | Inventory is deducted later by the cashier when the order
    | reaches the appropriate processing stage.
    |
    | Therefore:
    |
    | Pending -> Cancelled
    |
    | DOES NOT RESTORE INVENTORY.
    |
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | CANCEL ORDER
    |--------------------------------------------------------------------------
    */

    if ($user_id > 0) {

        /*
        | Logged-in customer
        */

        $updateStmt = mysqli_prepare(
            $conn,
            "UPDATE orders
             SET
                status = 'Cancelled',
                payment_status = 'Failed'
             WHERE id = ?
               AND user_id = ?
               AND status = 'Pending'
             LIMIT 1"
        );

        if (!$updateStmt) {
            throw new Exception(
                'Unable to prepare cancellation query: ' .
                mysqli_error($conn)
            );
        }

        mysqli_stmt_bind_param(
            $updateStmt,
            'ii',
            $order_id,
            $user_id
        );

    } else {

        /*
        | Guest / Dine-In customer
        */

        $updateStmt = mysqli_prepare(
            $conn,
            "UPDATE orders
             SET
                status = 'Cancelled',
                payment_status = 'Failed'
             WHERE id = ?
               AND status = 'Pending'
             LIMIT 1"
        );

        if (!$updateStmt) {
            throw new Exception(
                'Unable to prepare guest cancellation query: ' .
                mysqli_error($conn)
            );
        }

        mysqli_stmt_bind_param(
            $updateStmt,
            'i',
            $order_id
        );
    }

    /*
    |--------------------------------------------------------------------------
    | EXECUTE CANCELLATION
    |--------------------------------------------------------------------------
    */

    if (!mysqli_stmt_execute($updateStmt)) {

        $error = mysqli_stmt_error($updateStmt);

        mysqli_stmt_close($updateStmt);

        throw new Exception(
            'Unable to cancel order: ' . $error
        );
    }

    $affectedRows = mysqli_stmt_affected_rows($updateStmt);

    mysqli_stmt_close($updateStmt);

    /*
    |--------------------------------------------------------------------------
    | VERIFY UPDATE
    |--------------------------------------------------------------------------
    */

    if ($affectedRows !== 1) {

        throw new Exception(
            'Order could not be cancelled. The order may no longer be pending.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE PAYMENT RECORD
    |--------------------------------------------------------------------------
    */

    if (tableExists($conn, 'payments')) {

        /*
        | Check whether payment_status exists.
        */

        $columnCheck = mysqli_query(
            $conn,
            "SHOW COLUMNS FROM payments LIKE 'payment_status'"
        );

        if (
            $columnCheck &&
            mysqli_num_rows($columnCheck) > 0
        ) {

            $paymentStmt = mysqli_prepare(
                $conn,
                "UPDATE payments
                 SET payment_status = 'Failed'
                 WHERE order_id = ?"
            );

            if (!$paymentStmt) {

                throw new Exception(
                    'Unable to prepare payment update: ' .
                    mysqli_error($conn)
                );
            }

            mysqli_stmt_bind_param(
                $paymentStmt,
                'i',
                $order_id
            );

            if (!mysqli_stmt_execute($paymentStmt)) {

                $error = mysqli_stmt_error($paymentStmt);

                mysqli_stmt_close($paymentStmt);

                throw new Exception(
                    'Unable to update payment status: ' .
                    $error
                );
            }

            mysqli_stmt_close($paymentStmt);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | UNLOCK PROMO
    |--------------------------------------------------------------------------
    */

    if (tableExists($conn, 'customer_product_promos')) {

        $promoStmt = mysqli_prepare(
            $conn,
            "UPDATE customer_product_promos
             SET status = 'Unlocked'
             WHERE order_id = ?"
        );

        if ($promoStmt) {

            mysqli_stmt_bind_param(
                $promoStmt,
                'i',
                $order_id
            );

            if (!mysqli_stmt_execute($promoStmt)) {

                $error = mysqli_stmt_error($promoStmt);

                mysqli_stmt_close($promoStmt);

                throw new Exception(
                    'Unable to unlock promo: ' .
                    $error
                );
            }

            mysqli_stmt_close($promoStmt);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    if (!mysqli_commit($conn)) {

        throw new Exception(
            'Unable to commit cancellation.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SUCCESS
    |--------------------------------------------------------------------------
    */

    respond([
        'success' => true,
        'message' => 'Order cancelled successfully.',
        'order_id' => $order_id,
        'status' => 'Cancelled'
    ]);

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | ROLLBACK
    |--------------------------------------------------------------------------
    */

    mysqli_rollback($conn);

    /*
    |--------------------------------------------------------------------------
    | LOG ERROR
    |--------------------------------------------------------------------------
    */

    error_log(
        'BLACK HABIT CANCEL ORDER ERROR: ' .
        $e->getMessage()
    );

    /*
    |--------------------------------------------------------------------------
    | DEBUG RESPONSE
    |--------------------------------------------------------------------------
    |
    | Keep this temporarily while testing.
    | Once everything works, change it back to a generic message.
    |
    |--------------------------------------------------------------------------
    */

    respond([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
}

mysqli_close($conn);

?>
```

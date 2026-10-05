<?php

/*
|--------------------------------------------------------------------------
| BLACKHABIT - CUSTOMER RECEIPT
|--------------------------------------------------------------------------
*/

$from =
    strtolower(
        trim((string)($_GET['from'] ?? ''))
    );

$referer =
    strtolower(
        (string)($_SERVER['HTTP_REFERER'] ?? '')
    );


/*
|--------------------------------------------------------------------------
| SELECT CORRECT SESSION
|--------------------------------------------------------------------------
*/

if ($from === 'cashier') {

    session_name('BH_CASHIER_SESSION');

} elseif ($from === 'admin') {

    session_name('BH_ADMIN_SESSION');

} elseif (
    strpos($referer, 'cashier_') !== false
) {

    session_name('BH_CASHIER_SESSION');

} elseif (
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


/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

$role =
    strtolower(
        trim((string)($_SESSION['role'] ?? ''))
    );

$roleId =
    (int)($_SESSION['role'] ?? 0);

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !(
        $role === 'admin' ||
        $role === 'cashier' ||
        in_array($roleId, [1, 2, 3], true)
    )
) {

    die(
        'Your session has expired. Please log in again.'
    );
}


require_once 'db.php';

// Always display Black Habit receipt timestamps in Philippine time.
date_default_timezone_set('Asia/Manila');


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

function money($value): string
{
    return '' .
        number_format(
            (float)$value,
            2
        );
}


/*
|--------------------------------------------------------------------------
| THERMAL TEXT RECEIPT HELPERS
|--------------------------------------------------------------------------
| The current Windows backup printer uses Generic / Text Only on COM6.
| The physical receipt therefore needs fixed-width text rather than CSS
| positioning. 32 columns are used for the 58mm RPP02N roll.
*/

function thermalCenter(string $text, int $width = 32): string
{
    $text = trim($text);
    $len = function_exists('mb_strlen')
        ? mb_strlen($text, 'UTF-8')
        : strlen($text);

    if ($len >= $width) {
        return substr($text, 0, $width);
    }

    $left = (int)floor(($width - $len) / 2);
    return str_repeat(' ', $left) . $text;
}

function thermalTwoColumn(
    string $left,
    string $right,
    int $width = 32,
    int $gap = 2
): string {
    $rightLen = function_exists('mb_strlen')
        ? mb_strlen($right, 'UTF-8')
        : strlen($right);

    $leftMax = max(1, $width - $rightLen - $gap);

    if (
        (function_exists('mb_strlen') ? mb_strlen($left, 'UTF-8') : strlen($left))
        > $leftMax
    ) {
        $left = function_exists('mb_substr')
            ? mb_substr($left, 0, $leftMax, 'UTF-8')
            : substr($left, 0, $leftMax);
    }

    $leftLen = function_exists('mb_strlen')
        ? mb_strlen($left, 'UTF-8')
        : strlen($left);

    return $left . str_repeat(' ', max(1, $width - $leftLen - $rightLen)) . $right;
}

function thermalWrap(string $text, int $width = 32): array
{
    $text = trim(preg_replace('/\s+/', ' ', $text));

    if ($text === '') {
        return [];
    }

    $words = preg_split('/\s+/', $text);
    $lines = [];
    $line = '';

    foreach ($words as $word) {
        $test = ($line === '') ? $word : $line . ' ' . $word;
        $len = function_exists('mb_strlen')
            ? mb_strlen($test, 'UTF-8')
            : strlen($test);

        if ($len <= $width) {
            $line = $test;
        } else {
            if ($line !== '') {
                $lines[] = $line;
            }

            while (
                (function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word))
                > $width
            ) {
                $lines[] = function_exists('mb_substr')
                    ? mb_substr($word, 0, $width, 'UTF-8')
                    : substr($word, 0, $width);

                $word = function_exists('mb_substr')
                    ? mb_substr($word, $width, null, 'UTF-8')
                    : substr($word, $width);
            }

            $line = $word;
        }
    }

    if ($line !== '') {
        $lines[] = $line;
    }

    return $lines;
}

function columnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {

    $table =
        mysqli_real_escape_string(
            $conn,
            $table
        );

    $column =
        mysqli_real_escape_string(
            $conn,
            $column
        );

    $result =
        mysqli_query(
            $conn,
            "SHOW COLUMNS
             FROM `$table`
             LIKE '$column'"
        );

    return
        $result &&
        mysqli_num_rows($result) > 0;
}


/*
|--------------------------------------------------------------------------
| ORDER ID
|--------------------------------------------------------------------------
*/

$orderId =
    (int)($_GET['id'] ?? 0);

if ($orderId <= 0) {
    exit('Invalid order number.');
}


/*
|--------------------------------------------------------------------------
| OPTIONAL ORDER COLUMNS
|--------------------------------------------------------------------------
*/

$hasReceiptNumber =
    columnExists(
        $conn,
        'orders',
        'receipt_number'
    );

$hasUserId =
    columnExists(
        $conn,
        'orders',
        'user_id'
    );

$hasPaid =
    columnExists(
        $conn,
        'orders',
        'paid_amount'
    );

$hasBalance =
    columnExists(
        $conn,
        'orders',
        'balance'
    );

$hasPaymentMethod =
    columnExists(
        $conn,
        'orders',
        'payment_method'
    );

$hasPaymentType =
    columnExists(
        $conn,
        'orders',
        'payment_type'
    );


/*
|--------------------------------------------------------------------------
| ORDER QUERY
|--------------------------------------------------------------------------
*/

$orderFields = [
    'o.id',
    'o.total',
    'o.status',
    'o.order_type',
    'o.created_at'
];

if ($hasReceiptNumber) {
    $orderFields[] =
        'o.receipt_number';
}

if ($hasPaid) {
    $orderFields[] =
        'o.paid_amount';
}

if ($hasBalance) {
    $orderFields[] =
        'o.balance';
}

if ($hasPaymentMethod) {
    $orderFields[] =
        'o.payment_method';
}

if ($hasPaymentType) {
    $orderFields[] =
        'o.payment_type';
}

if ($hasUserId) {

    $orderFields[] =
        'u.first_name';

    $orderFields[] =
        'u.last_name';
}


$sql =
    'SELECT ' .
    implode(
        ', ',
        $orderFields
    ) .
    ' FROM orders o';


if ($hasUserId) {

    $sql .=
        ' LEFT JOIN users u
          ON u.id = o.user_id';
}


$sql .=
    ' WHERE o.id = ?
      LIMIT 1';


$stmt =
    mysqli_prepare(
        $conn,
        $sql
    );

if (!$stmt) {
    exit('Unable to load order.');
}

mysqli_stmt_bind_param(
    $stmt,
    'i',
    $orderId
);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

$order =
    $result
        ? mysqli_fetch_assoc($result)
        : null;

mysqli_stmt_close($stmt);

if (!$order) {
    exit('Order not found.');
}


/*
|--------------------------------------------------------------------------
| ORDER ITEMS
|--------------------------------------------------------------------------
*/

$itemFields = [
    'oi.id',
    'oi.quantity',
    'oi.price',
    'p.name AS product_name'
];

$optionalItemColumns = [
    'addons',
    'addons_price',
    'size_price',
    'special_instructions',
    'flavor',
    'size',
    'sugar',
    'ice'
];

foreach (
    $optionalItemColumns
    as $column
) {

    if (
        columnExists(
            $conn,
            'order_items',
            $column
        )
    ) {

        $itemFields[] =
            'oi.' . $column;
    }
}


$itemSql =
    'SELECT ' .
    implode(
        ', ',
        $itemFields
    ) .
    '
     FROM order_items oi
     LEFT JOIN products p
        ON p.id = oi.product_id
     WHERE oi.order_id = ?
     ORDER BY oi.id ASC';


$itemStmt =
    mysqli_prepare(
        $conn,
        $itemSql
    );

if (!$itemStmt) {
    exit('Unable to load order items.');
}

mysqli_stmt_bind_param(
    $itemStmt,
    'i',
    $orderId
);

mysqli_stmt_execute($itemStmt);

$itemResult =
    mysqli_stmt_get_result($itemStmt);

$items = [];

if ($itemResult) {

    while (
        $row =
        mysqli_fetch_assoc($itemResult)
    ) {

        $items[] = $row;
    }
}

mysqli_stmt_close($itemStmt);


/*
|--------------------------------------------------------------------------
| PHILIPPINE DATE/TIME FORMAT
|--------------------------------------------------------------------------
| The orders.created_at value is retrieved using the MySQL session timezone
| configured in db.php.  If the value already contains a timezone offset,
| it is converted to Asia/Manila before display.
*/
function formatReceiptDateTime($value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return date('M d, Y h:i A');
    }

    try {
        // If the DB value contains an explicit timezone/offset, honor it.
        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $value)) {
            $date = new DateTime($value);
        } else {
            // MySQL DATETIME has no timezone information.
            // Black Habit stores/displays its business time as Philippine time.
            $date = new DateTime($value, new DateTimeZone('Asia/Manila'));
        }

        $date->setTimezone(new DateTimeZone('Asia/Manila'));

        return $date->format('M d, Y h:i A');
    } catch (Exception $e) {
        return date('M d, Y h:i A');
    }
}

/*
|--------------------------------------------------------------------------
| DISPLAY VALUES
|--------------------------------------------------------------------------
*/

$createdAt =
    $order['created_at']
        ?? date('Y-m-d H:i:s');

$orderNumber =
    'BH-' .
    (new DateTime(
        $createdAt,
        new DateTimeZone('Asia/Manila')
    ))->format('Y') .
    '-' .
    str_pad(
        (string)$orderId,
        6,
        '0',
        STR_PAD_LEFT
    );


$rawType =
    trim(
        (string)(
            $order['order_type'] ?? ''
        )
    );

$normalizedType = strtolower(
    preg_replace('/\s+/', ' ', $rawType)
);

if ($normalizedType === 'advance order' ||
    $normalizedType === 'pick up' ||
    $normalizedType === 'pick-up' ||
    $normalizedType === 'pick-up order' ||
    $normalizedType === 'pickup' ||
    $normalizedType === 'pickup order') {

    $displayType =
        'Pick-Up';

} elseif ($normalizedType === 'dine in' ||
          $normalizedType === 'dine-in' ||
          $normalizedType === 'dine in order') {

    $displayType =
        'Dine-In';

} else {

    // BLACK HABIT uses only two order types: Dine-In and Pick-Up.
    // Keep the receipt consistent with the application.
    $displayType =
        'Dine-In';
}


$customer =
    trim(
        ($order['first_name'] ?? '') .
        ' ' .
        ($order['last_name'] ?? '')
    );

$total =
    (float)($order['total'] ?? 0);


/*
|--------------------------------------------------------------------------
| VAT
|--------------------------------------------------------------------------
*/

$vat =
    round(
        $total * 12 / 112,
        2
    );

$vatable =
    round(
        $total - $vat,
        2
    );


$paid =
    $hasPaid
        ? max(
            0,
            (float)($order['paid_amount'] ?? 0)
        )
        : 0;

$balance =
    $hasBalance
        ? max(
            0,
            (float)($order['balance'] ?? 0)
        )
        : 0;

/*
 * Amount received is the amount entered by the cashier.
 * Change is calculated automatically for full-payment orders.
 *
 * If the customer has not paid enough yet, change is ₱0.00
 * and the existing balance remains available below.
 */
$change =
    $paid > $total
        ? round($paid - $total, 2)
        : 0;

$paymentMethod =
    trim(
        (string)(
            $order['payment_method'] ?? ''
        )
    );

$paymentType =
    trim(
        (string)(
            $order['payment_type'] ?? ''
        )
    );

if ($paymentMethod === '') {
    $paymentMethod = 'Cash';
}

/*
|--------------------------------------------------------------------------
| BUILD 58MM FIXED-WIDTH RECEIPT
|--------------------------------------------------------------------------
*/

$thermalWidth = 32;
$thermalLines = [];

$thermalLines[] = thermalCenter('BLACK HABIT', $thermalWidth);
$thermalLines[] = thermalCenter('MILK TEA AND SHAWARMA', $thermalWidth);
$thermalLines[] = thermalCenter('GOOD HABITS, BETTER DAYS', $thermalWidth);
$thermalLines[] = str_repeat('-', $thermalWidth);
$thermalLines[] = thermalCenter('RECEIPT', $thermalWidth);
$thermalLines[] = thermalCenter(strtoupper($displayType), $thermalWidth);
$thermalLines[] = str_repeat('-', $thermalWidth);
$thermalLines[] = thermalTwoColumn('Order #', $orderNumber, $thermalWidth);
$thermalLines[] = thermalTwoColumn(
    'Date',
    formatReceiptDateTime($createdAt),
    $thermalWidth
);

if ($rawType !== 'Dine In' && $rawType !== 'Dine-In' && $customer !== '') {
    foreach (thermalWrap('Customer: ' . $customer, $thermalWidth) as $line) {
        $thermalLines[] = $line;
    }
}

$thermalLines[] = str_repeat('-', $thermalWidth);

foreach ($items as $item) {
    $qty = max(1, (int)($item['quantity'] ?? 1));
    $base = (float)($item['price'] ?? 0);
    $addonsPrice = (float)($item['addons_price'] ?? 0);
    $lineTotal = ($base + $addonsPrice) * $qty;

    $itemName = (string)($item['product_name'] ?? 'Product');
    if ($qty > 1) {
        $itemName .= ' x' . $qty;
    }

    $nameLines = thermalWrap($itemName, 23);
    if (!$nameLines) {
        $nameLines = ['Product'];
    }

    $thermalLines[] = thermalTwoColumn(
        $nameLines[0],
        money($lineTotal),
        $thermalWidth,
        1
    );

    for ($i = 1; $i < count($nameLines); $i++) {
        $thermalLines[] = '  ' . $nameLines[$i];
    }

    $details = [];

    foreach (['size', 'flavor', 'sugar', 'ice'] as $field) {
        if (
            isset($item[$field]) &&
            trim((string)$item[$field]) !== ''
        ) {
            $details[] = ucfirst($field) . ': ' . $item[$field];
        }
    }

    if (
        isset($item['addons']) &&
        trim((string)$item['addons']) !== ''
    ) {
        $details[] = 'Add-ons: ' . $item['addons'];
    }

    if (
        isset($item['special_instructions']) &&
        trim((string)$item['special_instructions']) !== ''
    ) {
        $details[] = 'Note: ' . $item['special_instructions'];
    }

    foreach ($details as $detail) {
        foreach (thermalWrap('  ' . $detail, $thermalWidth) as $detailLine) {
            $thermalLines[] = $detailLine;
        }
    }
}

$thermalLines[] = str_repeat('-', $thermalWidth);
$thermalLines[] = thermalTwoColumn('VATable Sales', money($vatable), $thermalWidth);
$thermalLines[] = thermalTwoColumn('VAT (12%)', money($vat), $thermalWidth);
$thermalLines[] = thermalTwoColumn('TOTAL', money($total), $thermalWidth);
$thermalLines[] = str_repeat('-', $thermalWidth);
$thermalLines[] = thermalTwoColumn('Payment Method', $paymentMethod, $thermalWidth);

if ($paymentType !== '') {
    $thermalLines[] = thermalTwoColumn('Payment Type', $paymentType, $thermalWidth);
}

$thermalLines[] = thermalTwoColumn('Amount Received', money($paid), $thermalWidth);
$thermalLines[] = thermalTwoColumn('Change', money($change), $thermalWidth);

if ($balance > 0) {
    $thermalLines[] = thermalTwoColumn('Balance', money($balance), $thermalWidth);
}

$thermalLines[] = str_repeat('-', $thermalWidth);
$thermalLines[] = thermalCenter('T H A N K  Y O U !', $thermalWidth);
$thermalLines[] = thermalCenter('GOOD HABITS, BETTER DAYS', $thermalWidth);

?>

<!doctype html>

<html lang="en">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>BLACK HABIT - Receipt <?php echo e($orderNumber); ?></title>

<style>
* { box-sizing: border-box; }
html, body { margin:0; padding:0; width:100%; }
body {
    background:#eee;
    color:#111;
    font-family:"Courier New", Courier, monospace;
    font-size:10px;
    line-height:1.22;
}
.receipt {
    width:58mm;
    max-width:58mm;
    margin:18px auto;
    padding:3.5mm 2.4mm;
    background:#fff;
}
.center { text-align:center; }
.brand {
    font-family:Arial, Helvetica, sans-serif;
    font-size:15px;
    font-weight:900;
    letter-spacing:1.4px;
    line-height:1.05;
}
.title {
    margin-top:2px;
    font-size:8px;
    font-weight:700;
    letter-spacing:.7px;
}
.store-note {
    margin-top:2px;
    font-size:7.5px;
    letter-spacing:.5px;
}
.receipt-title {
    margin:4px 0 1px;
    font-family:Arial, Helvetica, sans-serif;
    font-size:14px;
    font-weight:900;
    letter-spacing:1.2px;
}
.line {
    border-top:1px dashed #111;
    margin:5px 0;
}
.meta {
    font-size:8.5px;
    text-align:left;
}
.meta div {
    display:grid;
    grid-template-columns: 32% 68%;
    width:100%;
    margin:1.5px 0;
}
.meta strong {
    font-weight:700;
    white-space:nowrap;
}
.meta span {
    text-align:right;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:clip;
}
.items {
    width:100%;
    border-collapse:collapse;
    table-layout:fixed;
}
.items th { display:none; }
.items td {
    padding:3px 0;
    vertical-align:top;
}
.items td:first-child { width:70%; padding-right:3px; }
.items td:last-child {
    width:30%;
    text-align:right;
    white-space:nowrap;
}
.name {
    font-weight:700;
    line-height:1.15;
    overflow-wrap:anywhere;
}
.qty { font-weight:400; }
.detail {
    margin-top:1px;
    padding-left:6px;
    font-size:7.8px;
    line-height:1.15;
    overflow-wrap:anywhere;
}
.totals, .payment { font-size:8.8px; }
.totals div, .payment div {
    display:grid;
    grid-template-columns: 58% 42%;
    width:100%;
    margin:2px 0;
}
.totals span:first-child, .payment span:first-child {
    font-weight:700;
    white-space:nowrap;
}
.totals span:last-child, .payment span:last-child, .payment strong {
    text-align:right;
    white-space:nowrap;
}
.total {
    margin-top:4px !important;
    padding-top:4px;
    border-top:1px dashed #111;
    font-size:11px;
    font-weight:900;
}
.thank {
    margin-top:6px;
    text-align:center;
    font-family:Arial, Helvetica, sans-serif;
    font-size:9px;
    font-weight:900;
    letter-spacing:1.2px;
}
.footer {
    margin-top:2px;
    font-size:7px;
    font-weight:700;
    letter-spacing:.6px;
}

.thermal-print {
    display:none;
}

.thermal-print pre {
    margin:0;
    padding:0;
    white-space:pre;
    font-family:"Courier New", Courier, monospace;
    font-size:10px;
    line-height:1.15;
    font-weight:700;
}

.print { margin:14px auto; text-align:center; }
.print button {
    border:0;
    border-radius:5px;
    padding:10px 18px;
    background:#111;
    color:#fff;
    font-weight:700;
    cursor:pointer;
}
@media print {
    @page { size:58mm auto; margin:0; }
    html, body {
        width:58mm;
        min-width:58mm;
        margin:0;
        padding:0;
        background:#fff;
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }

    /* Generic / Text Only needs literal fixed-width text. */
    .receipt { display:none !important; }
    .thermal-print {
        display:block !important;
        width:58mm;
        max-width:58mm;
        margin:0;
        padding:2mm 2mm;
        background:#fff;
    }

    .print { display:none !important; }
}
</style>

</head>
<body>

<div class="receipt">

    <div class="center brand">
        BLACK HABIT
    </div>

    <div class="center title">
        MILK TEA AND SHAWARMA
    </div>

    <div class="center store-note">
        GOOD HABITS, BETTER DAYS
    </div>

    <div class="line"></div>

    <div class="center receipt-title">
        RECEIPT
    </div>

    <div class="center order-heading">
        <?php echo e(strtoupper($displayType)); ?>
    </div>

    <div class="line"></div>

    <div class="meta">

        <div>
            <strong>Order #</strong>
            <span><?php echo e($orderNumber); ?></span>
        </div>

        <div>
            <strong>Date</strong>
            <span>
                <?php echo e(
                    date(
                        'M d, Y h:i A',
                        strtotime($createdAt)
                    )
                ); ?>
            </span>
        </div>

        <?php if ($rawType !== 'Dine In' && $rawType !== 'Dine-In'): ?>

            <?php if ($customer !== ''): ?>
                <div>
                    <strong>Customer</strong>
                    <span><?php echo e($customer); ?></span>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </div>

    <div class="line"></div>

    <table class="items">

        <thead>
            <tr>
                <th>ITEM</th>
                <th>AMOUNT</th>
            </tr>
        </thead>

        <tbody>

        <?php foreach ($items as $item): ?>

            <?php

            $qty =
                max(
                    1,
                    (int)(
                        $item['quantity'] ?? 1
                    )
                );

            $base =
                (float)(
                    $item['price'] ?? 0
                );

            $addonsPrice =
                (float)(
                    $item['addons_price'] ?? 0
                );

            $unit =
                $base +
                $addonsPrice;

            $lineTotal =
                $unit *
                $qty;

            $details = [];

            foreach (
                [
                    'size',
                    'flavor',
                    'sugar',
                    'ice'
                ]
                as $field
            ) {

                if (
                    isset($item[$field]) &&
                    trim((string)$item[$field]) !== ''
                ) {

                    $details[] =
                        ucfirst($field) .
                        ': ' .
                        $item[$field];
                }
            }

            if (
                isset($item['addons']) &&
                trim((string)$item['addons']) !== ''
            ) {

                $details[] =
                    'Add-ons: ' .
                    $item['addons'];
            }

            if (
                isset($item['special_instructions']) &&
                trim((string)$item['special_instructions']) !== ''
            ) {

                $details[] =
                    'Note: ' .
                    $item['special_instructions'];
            }

            ?>

            <tr>

                <td>

                    <div class="name">
                        <?php echo e(
                            $item['product_name']
                                ?? 'Product'
                        ); ?>

                        <?php if ($qty > 1): ?>
                            <span class="qty">
                                × <?php echo $qty; ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($details): ?>

                        <div class="detail">

                            <?php echo e(
                                implode(
                                    ' • ',
                                    $details
                                )
                            ); ?>

                        </div>

                    <?php endif; ?>

                </td>

                <td>
                    <?php echo money($lineTotal); ?>
                </td>

            </tr>

        <?php endforeach; ?>

        </tbody>

    </table>

    <div class="line"></div>

    <div class="totals">

        <div>
            <span>VATable Sales</span>
            <span><?php echo money($vatable); ?></span>
        </div>

        <div>
            <span>VAT (12%)</span>
            <span><?php echo money($vat); ?></span>
        </div>

        <div class="total">
            <span>TOTAL</span>
            <span><?php echo money($total); ?></span>
        </div>

    </div>

    <div class="line"></div>

    <div class="payment">

        <div>
            <span>Payment Method</span>
            <strong><?php echo e($paymentMethod); ?></strong>
        </div>

        <?php if ($paymentType !== ''): ?>
            <div>
                <span>Payment Type</span>
                <span><?php echo e($paymentType); ?></span>
            </div>
        <?php endif; ?>

        <div>
            <span>Amount Received</span>
            <strong><?php echo money($paid); ?></strong>
        </div>

        <div>
            <span>Change</span>
            <strong><?php echo money($change); ?></strong>
        </div>

        <?php if ($balance > 0): ?>
            <div>
                <span>Balance</span>
                <strong><?php echo money($balance); ?></strong>
            </div>
        <?php endif; ?>

    </div>

    <div class="line"></div>

    <div class="thank">
        T H A N K  Y O U !
    </div>

    <div class="center footer">
        GOOD HABITS, BETTER DAYS
    </div>

</div>

<div class="thermal-print">
    <pre><?php echo e(implode("\n", $thermalLines)); ?></pre>
</div>

<div class="print">
    <button
        type="button"
        onclick="window.print()"
    >
        Print Receipt
    </button>
</div>

</body>

</html>

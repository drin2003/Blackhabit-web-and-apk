<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/db.php';

function respond(array $data, int $code = 200): void
{
    http_response_code($code);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function showHtmlResult(bool $success, string $message): void
{
    $title = $success
        ? 'EMAIL VERIFIED'
        : 'VERIFICATION FAILED';

    $icon = $success ? '✓' : '✕';
    $color = $success ? '#4CAF50' : '#e53935';

    $safeMessage = htmlspecialchars(
        $message,
        ENT_QUOTES,
        'UTF-8'
    );

    echo '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>BLACK HABIT</title>

    <style>
        body {
            margin: 0;
            padding: 0;
            background: #262424;
            font-family: Arial, sans-serif;
            color: white;
        }

        .container {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            box-sizing: border-box;
        }

        .card {
            width: 100%;
            max-width: 500px;
            background: #333333;
            border-radius: 20px;
            padding: 40px 25px;
            text-align: center;
            box-sizing: border-box;
        }

        .logo {
            font-size: 32px;
            font-weight: bold;
            letter-spacing: 5px;
            color: #f1b812;
            margin-bottom: 30px;
        }

        .icon {
            font-size: 70px;
            color: ' . $color . ';
            margin-bottom: 20px;
        }

        h1 {
            font-size: 26px;
            margin: 0 0 20px;
        }

        .message {
            color: #dddddd;
            font-size: 16px;
            line-height: 1.6;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="card">
            <div class="logo">BLACK HABIT</div>

            <div class="icon">' . $icon . '</div>

            <h1>' . $title . '</h1>

            <div class="message">
                ' . $safeMessage . '
            </div>
        </div>
    </div>
</body>
</html>
';

    exit;
}

function verifyAccount(
    mysqli $conn,
    string $email,
    string $code,
    bool $isBrowser
): void {

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        if ($isBrowser) {
            showHtmlResult(
                false,
                'Invalid email address.'
            );
        }

        respond([
            'status' => false,
            'message' => 'Invalid email address.'
        ], 400);
    }

    /*
     * Registration creates a six-digit numeric verification code.
     */
    if (!preg_match('/^[0-9]{6}$/', $code)) {

        if ($isBrowser) {
            showHtmlResult(
                false,
                'Invalid verification code.'
            );
        }

        respond([
            'status' => false,
            'message' => 'Verification code must be exactly 6 digits.'
        ], 400);
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            id,
            email,
            email_verified,
            verification_code,
            verification_expires
         FROM users
         WHERE LOWER(email) = ?
         LIMIT 1"
    );

    if (!$stmt) {
        throw new Exception(
            'User lookup prepare failed: ' . mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param(
        $stmt,
        's',
        $email
    );

    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);

        throw new Exception(
            'User lookup execute failed: ' . $error
        );
    }

    $result = mysqli_stmt_get_result($stmt);

    if ($result === false) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);

        throw new Exception(
            'Unable to read user result: ' . $error
        );
    }

    $user = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    if (!$user) {

        if ($isBrowser) {
            showHtmlResult(
                false,
                'Account not found.'
            );
        }

        respond([
            'status' => false,
            'message' => 'Account not found.'
        ], 404);
    }

    /*
     * Make verification idempotent. If the user clicks the Gmail
     * verification link twice, the second request does not fail.
     */
    if ((int)($user['email_verified'] ?? 0) === 1) {

        if ($isBrowser) {
            showHtmlResult(
                true,
                'Your BLACK HABIT account is already verified.'
            );
        }

        respond([
            'status' => true,
            'message' => 'Email is already verified.',
            'user_id' => (int)$user['id'],
            'email_verified' => true
        ]);
    }

    /*
     * Use hash_equals() rather than a direct string comparison.
     */
    $storedCode = (string)($user['verification_code'] ?? '');

    if (
        $storedCode === '' ||
        !hash_equals($storedCode, $code)
    ) {

        if ($isBrowser) {
            showHtmlResult(
                false,
                'Incorrect verification code.'
            );
        }

        respond([
            'status' => false,
            'message' => 'Incorrect verification code.'
        ], 400);
    }

    /*
     * Check the expiration timestamp.
     */
    $expires = (string)($user['verification_expires'] ?? '');
    $expiresTimestamp = $expires !== ''
        ? strtotime($expires)
        : false;

    if (
        $expiresTimestamp === false ||
        $expiresTimestamp < time()
    ) {

        if ($isBrowser) {
            showHtmlResult(
                false,
                'Your verification code has expired. Please request a new code.'
            );
        }

        respond([
            'status' => false,
            'message' => 'Verification code has expired. Please request a new code.'
        ], 400);
    }

    /*
     * Mark the account as verified and immediately invalidate the
     * verification code so it cannot be reused.
     */
    $userId = (int)$user['id'];

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE users
         SET
            email_verified = 1,
            verification_code = NULL,
            verification_expires = NULL
         WHERE id = ?
           AND email_verified = 0
         LIMIT 1"
    );

    if (!$stmt) {
        throw new Exception(
            'Verification update prepare failed: ' .
            mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param(
        $stmt,
        'i',
        $userId
    );

    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);

        throw new Exception(
            'Verification update execute failed: ' . $error
        );
    }

    $affectedRows = mysqli_stmt_affected_rows($stmt);

    mysqli_stmt_close($stmt);

    if ($affectedRows !== 1) {
        if ($isBrowser) {
            showHtmlResult(
                false,
                'The account could not be verified. Please request a new verification code.'
            );
        }

        respond([
            'status' => false,
            'message' => 'The account could not be verified. Please request a new verification code.'
        ], 409);
    }

    if ($isBrowser) {
        showHtmlResult(
            true,
            'Your BLACK HABIT account has been successfully verified. You can now return to the app and log in.'
        );
    }

    respond([
        'status' => true,
        'message' => 'Email verified successfully! You can now log in to BLACK HABIT.',
        'user_id' => $userId,
        'email' => $user['email'],
        'email_verified' => true
    ]);
}

try {

    if (!isset($conn) || !($conn instanceof mysqli)) {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            showHtmlResult(
                false,
                'Database connection failed.'
            );
        }

        respond([
            'status' => false,
            'message' => 'Database connection failed.'
        ], 500);
    }

    /*
     * GET = verification link opened from Gmail.
     */
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        $email = strtolower(
            trim((string)($_GET['email'] ?? ''))
        );

        $code = trim(
            (string)($_GET['code'] ?? '')
        );

        if ($email === '' || $code === '') {
            showHtmlResult(
                false,
                'Invalid verification link.'
            );
        }

        verifyAccount(
            $conn,
            $email,
            $code,
            true
        );
    }

    /*
     * POST = Flutter application.
     */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond([
            'status' => false,
            'message' => 'Only GET and POST requests are allowed.'
        ], 405);
    }

    $rawInput = file_get_contents('php://input');

    if ($rawInput === false || trim($rawInput) === '') {
        respond([
            'status' => false,
            'message' => 'Empty request body.'
        ], 400);
    }

    $data = json_decode(
        $rawInput,
        true
    );

    if (!is_array($data)) {
        respond([
            'status' => false,
            'message' => 'Invalid JSON.'
        ], 400);
    }

    $email = strtolower(
        trim((string)($data['email'] ?? ''))
    );

    $code = trim(
        (string)($data['code'] ?? '')
    );

    if ($email === '' || $code === '') {
        respond([
            'status' => false,
            'message' => 'Email and verification code are required.'
        ], 400);
    }

    verifyAccount(
        $conn,
        $email,
        $code,
        false
    );

} catch (Throwable $e) {

    error_log(
        'BLACK HABIT VERIFY ERROR: ' .
        $e->getMessage()
    );

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        showHtmlResult(
            false,
            'Could not verify your account.'
        );
    }

    respond([
        'status' => false,
        'message' => 'Could not verify the account.'
    ], 500);
}

if (isset($conn) && $conn instanceof mysqli) {
    mysqli_close($conn);
}

?>

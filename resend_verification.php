<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/send_verification_email.php';

function respond(array $data, int $code = 200): void
{
    http_response_code($code);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond([
            'status' => false,
            'message' => 'Only POST requests are allowed.'
        ], 405);
    }

    if (!isset($conn) || !($conn instanceof mysqli)) {
        respond([
            'status' => false,
            'message' => 'Database connection failed.'
        ], 500);
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

    if ($email === '') {
        respond([
            'status' => false,
            'message' => 'Email is required.'
        ], 400);
    }

    if (
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        !preg_match('/@gmail\.com$/i', $email)
    ) {
        respond([
            'status' => false,
            'message' => 'Please use a valid Gmail address.'
        ], 400);
    }

    /*
     * Find the existing customer account.
     */
    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            id,
            first_name,
            middle_name,
            last_name,
            email,
            email_verified
         FROM users
         WHERE LOWER(email) = ?
         LIMIT 1"
    );

    if (!$stmt) {
        throw new Exception(
            'User lookup prepare failed: ' .
            mysqli_error($conn)
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
        respond([
            'status' => false,
            'message' => 'Account not found.'
        ], 404);
    }

    /*
     * Do not issue another code to an account that is already verified.
     */
    if ((int)($user['email_verified'] ?? 0) === 1) {
        respond([
            'status' => false,
            'message' => 'This account is already verified.'
        ], 409);
    }

    /*
     * Generate a new six-digit code.
     */
    $verificationCode = str_pad(
        (string)random_int(0, 999999),
        6,
        '0',
        STR_PAD_LEFT
    );

    /*
     * Registration and verification use a 2-minute expiration.
     */
    $expiresIn = 120;

    $verificationExpires = date(
        'Y-m-d H:i:s',
        time() + $expiresIn
    );

    /*
     * Save the new code.
     */
    $userId = (int)$user['id'];

    $update = mysqli_prepare(
        $conn,
        "UPDATE users
         SET
            verification_code = ?,
            verification_expires = ?,
            email_verified = 0
         WHERE id = ?
           AND email_verified = 0
         LIMIT 1"
    );

    if (!$update) {
        throw new Exception(
            'Update prepare failed: ' .
            mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param(
        $update,
        'ssi',
        $verificationCode,
        $verificationExpires,
        $userId
    );

    if (!mysqli_stmt_execute($update)) {
        $error = mysqli_stmt_error($update);
        mysqli_stmt_close($update);

        throw new Exception(
            'Update execute failed: ' . $error
        );
    }

    $affectedRows = mysqli_stmt_affected_rows($update);

    mysqli_stmt_close($update);

    if ($affectedRows !== 1) {
        respond([
            'status' => false,
            'message' => 'Unable to create a new verification code. Please try again.'
        ], 409);
    }

    /*
     * Build the same name format used during registration.
     */
    $nameParts = [
        trim((string)($user['first_name'] ?? '')),
        trim((string)($user['middle_name'] ?? '')),
        trim((string)($user['last_name'] ?? ''))
    ];

    $nameParts = array_values(
        array_filter(
            $nameParts,
            static function ($part) {
                return $part !== '';
            }
        )
    );

    $fullName = implode(
        ' ',
        $nameParts
    );

    if ($fullName === '') {
        $fullName = 'BLACK HABIT Customer';
    }

    /*
     * Send the new code through the existing mail function.
     */
    $emailSent = false;

    try {

        $emailSent = (bool)sendVerificationEmail(
            $user['email'],
            $fullName,
            $verificationCode
        );

    } catch (Throwable $mailError) {

        error_log(
            'BLACK HABIT RESEND EMAIL ERROR: ' .
            $mailError->getMessage()
        );

        $emailSent = false;
    }

    /*
     * If sending fails, invalidate the newly-created code.
     * This prevents a code from remaining active when the customer
     * never received it.
     */
    if (!$emailSent) {

        $clearStmt = mysqli_prepare(
            $conn,
            "UPDATE users
             SET
                verification_code = NULL,
                verification_expires = NULL
             WHERE id = ?
             LIMIT 1"
        );

        if ($clearStmt) {

            mysqli_stmt_bind_param(
                $clearStmt,
                'i',
                $userId
            );

            mysqli_stmt_execute($clearStmt);
            mysqli_stmt_close($clearStmt);
        }

        error_log(
            'BLACK HABIT RESEND: verification email failed for ' .
            $user['email']
        );

        respond([
            'status' => false,
            'message' => 'The verification email could not be sent. Please try again.'
        ], 503);
    }

    respond([
        'status' => true,
        'message' => 'A new verification code has been sent to your Gmail. The code expires in 2 minutes.',
        'email' => $user['email'],
        'expires_in' => $expiresIn
    ], 200);

} catch (Throwable $e) {

    error_log(
        'BLACK HABIT RESEND ERROR: ' .
        $e->getMessage()
    );

    respond([
        'status' => false,
        'message' => 'Unable to send a new verification code.'
    ], 500);
}

if (isset($conn) && $conn instanceof mysqli) {
    mysqli_close($conn);
}

?>

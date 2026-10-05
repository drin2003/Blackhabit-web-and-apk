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

    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        respond([
            'status' => false,
            'message' => 'Empty request body.'
        ], 400);
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        respond([
            'status' => false,
            'message' => 'Invalid JSON.'
        ], 400);
    }

    $fullName = trim(
        preg_replace(
            '/\s+/',
            ' ',
            (string)($data['full_name'] ?? '')
        )
    );

    $email = strtolower(
        trim((string)($data['email'] ?? ''))
    );

    $password = (string)($data['password'] ?? '');

    /*
     * Required registration fields.
     */
    if (
        $fullName === '' ||
        $email === '' ||
        $password === ''
    ) {
        respond([
            'status' => false,
            'message' => 'Full name, email, and password are required.'
        ], 400);
    }

    if (strlen($fullName) < 2) {
        respond([
            'status' => false,
            'message' => 'Please enter a valid name.'
        ], 400);
    }

    /*
     * Current Black Habit registration uses Gmail verification.
     */
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
     * Password requirements:
     * - minimum 8 characters
     * - at least 1 uppercase letter
     * - at least 1 number
     * - at least 1 special character
     */
    if (strlen($password) < 8) {
        respond([
            'status' => false,
            'message' => 'Password must be at least 8 characters.'
        ], 400);
    }

    if (!preg_match('/[A-Z]/', $password)) {
        respond([
            'status' => false,
            'message' => 'Password must contain at least 1 uppercase letter.'
        ], 400);
    }

    if (!preg_match('/[0-9]/', $password)) {
        respond([
            'status' => false,
            'message' => 'Password must contain at least 1 number.'
        ], 400);
    }

    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        respond([
            'status' => false,
            'message' => 'Password must contain at least 1 special character.'
        ], 400);
    }

    /*
     * Split full name into the existing users-table fields.
     *
     * Example:
     * Juan                    -> first_name = Juan
     * Juan Dela                -> first_name = Juan, last_name = Dela
     * Juan Dela Cruz           -> first_name = Juan,
     *                             middle_name = Dela,
     *                             last_name = Cruz
     */
    $parts = preg_split(
        '/\s+/',
        $fullName,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    $firstName = '';
    $middleName = null;
    $lastName = '';

    if (count($parts) === 1) {

        $firstName = $parts[0];

    } elseif (count($parts) === 2) {

        $firstName = $parts[0];
        $lastName = $parts[1];

    } else {

        $firstName = $parts[0];
        $lastName = $parts[count($parts) - 1];

        $middleParts = array_slice(
            $parts,
            1,
            count($parts) - 2
        );

        $middleName = implode(
            ' ',
            $middleParts
        );
    }

    /*
     * Check whether this email already exists.
     */
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, email_verified
         FROM users
         WHERE LOWER(email) = ?
         LIMIT 1"
    );

    if (!$stmt) {
        throw new Exception(
            'SELECT prepare failed: ' . mysqli_error($conn)
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
            'SELECT execute failed: ' . $error
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

    $existingUser = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    /*
     * Generate a new 6-digit verification code.
     * It is valid for 2 minutes, matching the current system.
     */
    $verificationCode = str_pad(
        (string)random_int(0, 999999),
        6,
        '0',
        STR_PAD_LEFT
    );

    $verificationExpires = date(
        'Y-m-d H:i:s',
        time() + 120
    );

    /*
     * Never store the user's password as plain text.
     */
    $passwordHash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    if ($passwordHash === false) {
        throw new Exception(
            'Password hashing failed.'
        );
    }

    /*
     * Customer role is 3 in the existing system.
     */
    $role = 3;

    if ($existingUser) {

        /*
         * A verified account cannot be registered again using
         * the same email.
         */
        if ((int)($existingUser['email_verified'] ?? 0) === 1) {
            respond([
                'status' => false,
                'message' => 'This email is already registered.'
            ], 409);
        }

        /*
         * If an account exists but is still unverified,
         * update its registration details and issue a new code.
         */
        $userId = (int)$existingUser['id'];

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE users
             SET
                first_name = ?,
                middle_name = ?,
                last_name = ?,
                password = ?,
                verification_code = ?,
                verification_expires = ?,
                email_verified = 0
             WHERE id = ?
             LIMIT 1"
        );

        if (!$stmt) {
            throw new Exception(
                'UPDATE prepare failed: ' . mysqli_error($conn)
            );
        }

        mysqli_stmt_bind_param(
            $stmt,
            'ssssssi',
            $firstName,
            $middleName,
            $lastName,
            $passwordHash,
            $verificationCode,
            $verificationExpires,
            $userId
        );

        if (!mysqli_stmt_execute($stmt)) {
            $error = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);

            throw new Exception(
                'UPDATE execute failed: ' . $error
            );
        }

        mysqli_stmt_close($stmt);

    } else {

        /*
         * Create a new customer account.
         */
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO users
            (
                first_name,
                middle_name,
                last_name,
                email,
                password,
                role,
                email_verified,
                verification_code,
                verification_expires
            )
            VALUES
            (?, ?, ?, ?, ?, ?, 0, ?, ?)"
        );

        if (!$stmt) {
            throw new Exception(
                'INSERT prepare failed: ' . mysqli_error($conn)
            );
        }

        mysqli_stmt_bind_param(
            $stmt,
            'sssssiss',
            $firstName,
            $middleName,
            $lastName,
            $email,
            $passwordHash,
            $role,
            $verificationCode,
            $verificationExpires
        );

        if (!mysqli_stmt_execute($stmt)) {
            $error = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);

            throw new Exception(
                'INSERT execute failed: ' . $error
            );
        }

        $userId = mysqli_insert_id($conn);

        mysqli_stmt_close($stmt);

        if ($userId <= 0) {
            throw new Exception(
                'Account was created but user ID could not be obtained.'
            );
        }
    }

    /*
     * Send the verification code through the existing
     * send_verification_email.php function.
     */
    $emailSent = false;
    $emailError = '';

    try {

        $emailSent = (bool)sendVerificationEmail(
            $email,
            $fullName,
            $verificationCode
        );

        if (!$emailSent) {
            $emailError =
                'sendVerificationEmail() returned false.';
        }

    } catch (Throwable $mailError) {

        $emailError = $mailError->getMessage();

        error_log(
            'BLACK HABIT EMAIL ERROR: ' .
            $emailError
        );
    }

    /*
     * Do not expose internal mail/server errors to the customer.
     */
    if ($emailSent) {

        respond([
            'status' => true,
            'message' => 'Registration successful. A verification code has been sent to your Gmail.',
            'user_id' => (int)$userId,
            'email' => $email,
            'email_verified' => false,
            'requires_verification' => true,
            'email_sent' => true
        ], 201);

    }

    /*
     * The account exists, but verification email failed.
     * The client can use the verification/resend flow.
     */
    respond([
        'status' => false,
        'message' => 'Account was created, but the verification email could not be sent. Please try again.',
        'user_id' => (int)$userId,
        'email' => $email,
        'email_verified' => false,
        'requires_verification' => true,
        'email_sent' => false
    ], 503);

} catch (Throwable $e) {

    error_log(
        'BLACK HABIT REGISTER ERROR: ' .
        $e->getMessage()
    );

    respond([
        'status' => false,
        'message' => 'Registration failed.'
    ], 500);
}

if (isset($conn) && $conn instanceof mysqli) {
    mysqli_close($conn);
}

?>

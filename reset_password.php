<?php
/**
 * BLACK HABIT - Reset Password
 *
 * Works with forgot_password.php:
 * - Receives the raw reset token from the email link.
 * - password_resets stores SHA-256(token), not the raw token.
 * - Reset tokens expire after 15 minutes.
 * - Token is deleted after a successful password change.
 * - Flutter can also use a 6-digit code through this same file.
 * - Flutter code reset uses users.verification_code and users.verification_expires.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail_config.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function isValidToken(string $token): bool
{
    return (bool) preg_match('/^[a-f0-9]{64}$/i', $token);
}

function passwordIsValid(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[0-9]/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);
}

function isValidEmail(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function isValidResetCode(string $code): bool
{
    return (bool) preg_match('/^\d{6}$/', $code);
}

/**
 * Flutter reset flow:
 * email + 6-digit verification_code stored on users.
 * This does NOT use password_resets.
 */
function resetPasswordByCode(
    mysqli $conn,
    string $email,
    string $code,
    string $newPassword,
    string $confirmPassword
): array {
    if (!isValidEmail($email)) {
        return ['success' => false, 'status' => 400, 'message' => 'Invalid email address.'];
    }

    if (!preg_match('/@gmail\.com$/i', $email)) {
        return ['success' => false, 'status' => 400, 'message' => 'Please use a Gmail address.'];
    }

    if (!isValidResetCode($code)) {
        return ['success' => false, 'status' => 400, 'message' => 'Please enter the 6-digit reset code.'];
    }

    if ($newPassword === '' || $confirmPassword === '') {
        return ['success' => false, 'status' => 400, 'message' => 'Please enter and confirm your new password.'];
    }

    if ($newPassword !== $confirmPassword) {
        return ['success' => false, 'status' => 400, 'message' => 'Passwords do not match.'];
    }

    if (!passwordIsValid($newPassword)) {
        return [
            'success' => false,
            'status' => 400,
            'message' => 'Password must be at least 8 characters and contain at least 1 uppercase letter, 1 number, and 1 special character.'
        ];
    }

    $sql = "
        SELECT
            id,
            email,
            email_verified,
            verification_code,
            verification_expires
        FROM users
        WHERE LOWER(email) = LOWER(?)
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return ['success' => false, 'status' => 500, 'message' => 'Unable to verify the reset code.'];
    }

    $stmt->bind_param('s', $email);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$user) {
        return ['success' => false, 'status' => 404, 'message' => 'Account not found.'];
    }

    if ((int)$user['email_verified'] !== 1) {
        return ['success' => false, 'status' => 403, 'message' => 'Your email address is not verified.'];
    }

    $storedCode = trim((string)($user['verification_code'] ?? ''));
    $expiresValue = trim((string)($user['verification_expires'] ?? ''));
    $expiresAt = $expiresValue !== '' ? strtotime($expiresValue) : false;

    if ($storedCode === '' || !hash_equals($storedCode, $code)) {
        return ['success' => false, 'status' => 400, 'message' => 'Invalid reset code.'];
    }

    if ($expiresAt === false || $expiresAt <= time()) {
        // Clear expired code.
        $clear = $conn->prepare(
            "UPDATE users SET verification_code = NULL, verification_expires = NULL WHERE id = ? LIMIT 1"
        );

        if ($clear) {
            $userId = (int)$user['id'];
            $clear->bind_param('i', $userId);
            $clear->execute();
            $clear->close();
        }

        return ['success' => false, 'status' => 400, 'message' => 'The reset code has expired. Please request a new code.'];
    }

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

    if ($passwordHash === false) {
        return ['success' => false, 'status' => 500, 'message' => 'Unable to create a secure password.'];
    }

    $userId = (int)$user['id'];

    try {
        $conn->begin_transaction();

        $update = $conn->prepare(
            "UPDATE users
             SET password = ?, verification_code = NULL, verification_expires = NULL
             WHERE id = ?
             LIMIT 1"
        );

        if (!$update) {
            throw new Exception('Unable to prepare password update.');
        }

        $update->bind_param('si', $passwordHash, $userId);

        if (!$update->execute() || $update->affected_rows < 1) {
            $update->close();
            throw new Exception('Password update failed.');
        }

        $update->close();
        $conn->commit();

        return [
            'success' => true,
            'status' => 200,
            'message' => 'Password reset successfully. You can now log in with your new password.'
        ];
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }

        return [
            'success' => false,
            'status' => 500,
            'message' => 'Unable to reset your password. Please try again.'
        ];
    }
}

function getResetRecord(mysqli $conn, string $token): ?array
{
    if (!isValidToken($token)) {
        return null;
    }

    $tokenHash = hash('sha256', $token);

    $sql = "
        SELECT
            pr.id AS reset_id,
            pr.user_id,
            pr.email,
            pr.expires_at,
            u.email_verified
        FROM password_resets pr
        INNER JOIN users u ON u.id = pr.user_id
        WHERE pr.token_hash = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();

    $result = $stmt->get_result();
    $record = $result ? $result->fetch_assoc() : null;

    $stmt->close();

    if (!$record) {
        return null;
    }

    if ((int)$record['email_verified'] !== 1) {
        return null;
    }

    $expiresAt = strtotime((string)$record['expires_at']);
    if ($expiresAt === false || $expiresAt <= time()) {
        return null;
    }

    return $record;
}

function renderPage(string $title, string $message, bool $success = false, ?string $token = null): void
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

    $form = '';

    if ($token !== null) {
        $safeToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        $form = <<<HTML
        <form method="POST" action="reset_password.php" class="reset-form" autocomplete="off">
            <input type="hidden" name="token" value="{$safeToken}">

            <div class="field">
                <label for="new_password">New Password</label>
                <input
                    type="password"
                    id="new_password"
                    name="new_password"
                    minlength="8"
                    required
                    autocomplete="new-password"
                    placeholder="Enter your new password"
                >
            </div>

            <div class="field">
                <label for="confirm_password">Confirm Password</label>
                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    minlength="8"
                    required
                    autocomplete="new-password"
                    placeholder="Re-enter your new password"
                >
            </div>

            <div class="requirements">
                <strong>Password requirements:</strong>
                <ul>
                    <li>At least 8 characters</li>
                    <li>At least 1 uppercase letter</li>
                    <li>At least 1 number</li>
                    <li>At least 1 special character</li>
                </ul>
            </div>

            <button type="submit">RESET PASSWORD</button>
        </form>
HTML;
    }

    $statusClass = $success ? 'success' : 'error';

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$safeTitle} | BLACK HABIT</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            background: #f7f3e8;
            color: #241b13;
            font-family: Arial, Helvetica, sans-serif;
        }

        .card {
            width: 100%;
            max-width: 460px;
            background: #ffffff;
            border: 1px solid #e5dcc9;
            border-radius: 18px;
            padding: 30px 24px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }

        .brand {
            text-align: center;
            margin-bottom: 24px;
        }

        .brand h1 {
            margin: 0;
            font-size: 28px;
            letter-spacing: 2px;
            color: #111111;
        }

        .brand p {
            margin: 7px 0 0;
            color: #77705f;
            font-size: 13px;
        }

        h2 {
            margin: 0 0 12px;
            text-align: center;
            font-size: 22px;
        }

        .message {
            text-align: center;
            line-height: 1.6;
            color: #5d5548;
            margin-bottom: 20px;
        }

        .error {
            color: #9b1c1c;
        }

        .success {
            color: #286b36;
        }

        .field {
            margin-bottom: 17px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: 700;
            font-size: 14px;
        }

        input[type="password"] {
            width: 100%;
            height: 48px;
            padding: 0 14px;
            border: 1px solid #cfc5b2;
            border-radius: 10px;
            outline: none;
            font-size: 15px;
            background: #fff;
        }

        input[type="password"]:focus {
            border-color: #6b4f2a;
            box-shadow: 0 0 0 3px rgba(107, 79, 42, 0.10);
        }

        .requirements {
            margin: 5px 0 20px;
            padding: 13px 15px;
            border-radius: 10px;
            background: #f8f5ed;
            color: #625a4e;
            font-size: 13px;
            line-height: 1.5;
        }

        .requirements strong {
            color: #33291f;
        }

        .requirements ul {
            margin: 7px 0 0 18px;
            padding: 0;
        }

        button {
            width: 100%;
            height: 48px;
            border: 0;
            border-radius: 10px;
            background: #111111;
            color: #ffffff;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: .5px;
            cursor: pointer;
        }

        button:hover {
            background: #2b2b2b;
        }

        .footer {
            margin-top: 22px;
            text-align: center;
            color: #8a8275;
            font-size: 12px;
        }

        @media (max-width: 480px) {
            body {
                padding: 15px 10px;
            }

            .card {
                padding: 25px 18px;
                border-radius: 14px;
            }

            .brand h1 {
                font-size: 25px;
            }
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="brand">
            <h1>BLACK HABIT</h1>
            <p>Secure Account Recovery</p>
        </div>

        <h2>{$safeTitle}</h2>
        <div class="message {$statusClass}">{$safeMessage}</div>

        {$form}

        <div class="footer">
            Password reset requests are temporary and can only be used once.
        </div>
    </main>
</body>
</html>
HTML;

    exit;
}

/*
 * The reset link is intentionally a GET request:
 * reset_password.php?token=...
 *
 * The actual password change happens only through POST.
 */
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $token = trim((string)($_GET['token'] ?? ''));

    $record = getResetRecord($conn, $token);

    if (!$record) {
        renderPage(
            'Invalid or Expired Link',
            "This password reset link is invalid, expired, or has already been used.\nPlease request a new password reset link from BLACK HABIT."
        );
    }

    renderPage(
        'Reset Password',
        "Create a new password for your BLACK HABIT account."
        . "\nYour reset link is valid for a limited time.",
        false,
        $token
    );
}

if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');

    renderPage(
        'Method Not Allowed',
        'Please open the password reset link from your email.'
    );
}

/*
 * Accept normal browser form POST.
 * Also accept JSON for possible future Flutter/API use.
 */
$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
$data = [];

if (strpos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
} else {
    $data = $_POST;
}

/*
 * ============================================================
 * FLUTTER 6-DIGIT CODE FLOW
 * ============================================================
 *
 * Flutter sends:
 * {
 *   "email": "...@gmail.com",
 *   "code": "123456",
 *   "new_password": "...",
 *   "confirm_password": "..."
 * }
 *
 * This uses users.verification_code and users.verification_expires.
 * No reset_password_api.php is required.
 */
$flutterEmail = trim((string)($data['email'] ?? ''));
$flutterCode = trim((string)($data['code'] ?? ''));
$hasFlutterFields = array_key_exists('email', $data)
    || array_key_exists('code', $data)
    || array_key_exists('new_password', $data)
    || array_key_exists('confirm_password', $data);

if (
    strpos($contentType, 'application/json') !== false
    && $hasFlutterFields
    && !array_key_exists('token', $data)
) {
    $result = resetPasswordByCode(
        $conn,
        strtolower($flutterEmail),
        $flutterCode,
        (string)($data['new_password'] ?? ''),
        (string)($data['confirm_password'] ?? '')
    );

    // Flutter must always receive HTTP 200 for expected validation errors.
    // The JSON "success" field tells Flutter whether the operation worked.
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => (bool)$result['success'],
        'status'  => (bool)$result['success'],
        'message' => (string)$result['message']
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

/*
 * ============================================================
 * EXISTING WEBSITE TOKEN FLOW
 * ============================================================
 */
$token = trim((string)($data['token'] ?? ''));
$newPassword = (string)($data['new_password'] ?? $data['password'] ?? '');
$confirmPassword = (string)($data['confirm_password'] ?? $data['confirmPassword'] ?? '');

if ($token === '' || !isValidToken($token)) {
    http_response_code(400);

    if (strpos($contentType, 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Invalid password reset token.'
        ]);
        exit;
    }

    renderPage(
        'Invalid Reset Link',
        'The password reset link is invalid.'
    );
}

if ($newPassword === '' || $confirmPassword === '') {
    http_response_code(400);

    if (strpos($contentType, 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Please enter and confirm your new password.'
        ]);
        exit;
    }

    renderPage(
        'Missing Password',
        'Please enter and confirm your new password.',
        false,
        $token
    );
}

if ($newPassword !== $confirmPassword) {
    http_response_code(400);

    if (strpos($contentType, 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Passwords do not match.'
        ]);
        exit;
    }

    renderPage(
        'Passwords Do Not Match',
        'The new password and confirmation password do not match.',
        false,
        $token
    );
}

if (!passwordIsValid($newPassword)) {
    http_response_code(400);

    if (strpos($contentType, 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Password must be at least 8 characters and contain at least 1 uppercase letter, 1 number, and 1 special character.'
        ]);
        exit;
    }

    renderPage(
        'Invalid Password',
        "Your password must have:\n• At least 8 characters\n• At least 1 uppercase letter\n• At least 1 number\n• At least 1 special character",
        false,
        $token
    );
}

$record = getResetRecord($conn, $token);

if (!$record) {
    http_response_code(400);

    if (strpos($contentType, 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'This password reset link is invalid, expired, or has already been used.'
        ]);
        exit;
    }

    renderPage(
        'Invalid or Expired Link',
        "This password reset link is invalid, expired, or has already been used.\nPlease request a new reset link."
    );
}

$userId = (int)$record['user_id'];
$passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

if ($passwordHash === false) {
    http_response_code(500);

    if (strpos($contentType, 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Unable to create a secure password.'
        ]);
        exit;
    }

    renderPage(
        'Reset Failed',
        'Unable to create a secure password. Please try again.'
    );
}

try {
    $conn->begin_transaction();

    $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ? LIMIT 1");
    if (!$update) {
        throw new Exception('Unable to prepare password update.');
    }

    $update->bind_param('si', $passwordHash, $userId);

    if (!$update->execute() || $update->affected_rows < 1) {
        $update->close();
        throw new Exception('Password update failed.');
    }

    $update->close();

    /*
     * Delete the token immediately after a successful password change.
     * This makes the reset link single-use.
     */
    $delete = $conn->prepare("DELETE FROM password_resets WHERE id = ? LIMIT 1");
    if (!$delete) {
        throw new Exception('Unable to prepare reset-token deletion.');
    }

    $resetId = (int)$record['reset_id'];
    $delete->bind_param('i', $resetId);

    if (!$delete->execute()) {
        $delete->close();
        throw new Exception('Reset-token deletion failed.');
    }

    $delete->close();

    $conn->commit();
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
        // Ignore rollback errors.
    }

    http_response_code(500);

    if (strpos($contentType, 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Unable to reset your password. Please try again.'
        ]);
        exit;
    }

    renderPage(
        'Reset Failed',
        'Unable to reset your password. Please try again.'
    );
}

if (strpos($contentType, 'application/json') !== false) {
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => true,
        'message' => 'Password reset successfully. You can now log in with your new password.'
    ]);
    exit;
}

renderPage(
    'Password Reset Successful',
    "Your password has been changed successfully.\n\nYou can now return to the BLACK HABIT app and log in using your new password.",
    true
);
?>

<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . "/db.php";

try {

    $rawInput = file_get_contents("php://input");
    $data = json_decode($rawInput, true);

    if (!is_array($data)) {
        echo json_encode([
            "status" => false,
            "message" => "Invalid JSON data."
        ]);
        exit;
    }

    $email = strtolower(trim($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if ($email === '' || $password === '') {
        echo json_encode([
            "status" => false,
            "message" => "Email and password are required."
        ]);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            "status" => false,
            "message" => "Please enter a valid email address."
        ]);
        exit;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            id,
            first_name,
            middle_name,
            last_name,
            email,
            password,
            role,
            email_verified
         FROM users
         WHERE email = ?
         LIMIT 1"
    );

    if (!$stmt) {
        throw new Exception("Prepare failed.");
    }

    mysqli_stmt_bind_param($stmt, "s", $email);

    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Execute failed.");
    }

    $result = mysqli_stmt_get_result($stmt);

    $user = $result
        ? mysqli_fetch_assoc($result)
        : null;

    mysqli_stmt_close($stmt);

    if (!$user) {
        echo json_encode([
            "status" => false,
            "message" => "Email not registered."
        ]);
        exit;
    }

    if (!password_verify($password, $user['password'])) {
        echo json_encode([
            "status" => false,
            "message" => "Invalid password."
        ]);
        exit;
    }

    $emailVerified = (int)$user['email_verified'] === 1;

    if (!$emailVerified) {
        echo json_encode([
            "status" => false,
            "message" => "Please verify your Gmail address before logging in.",
            "email_verified" => false,
            "requires_verification" => true,
            "email" => $user['email'],
            "user_id" => (int)$user['id']
        ]);
        exit;
    }

    echo json_encode([
        "status" => true,
        "message" => "Login successful.",
        "user_id" => (int)$user['id'],
        "email" => $user['email'],
        "first_name" => $user['first_name'],
        "middle_name" => $user['middle_name'],
        "last_name" => $user['last_name'],
        "role" => (int)$user['role'],
        "email_verified" => true
    ]);

} catch (Throwable $e) {

    error_log(
        "BLACK HABIT LOGIN ERROR: " .
        $e->getMessage()
    );

    echo json_encode([
        "status" => false,
        "message" => "Unable to process login."
    ]);
}

mysqli_close($conn);

?>
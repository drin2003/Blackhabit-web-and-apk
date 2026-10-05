<?php
// BLACKHABIT: Admin uses its own independent session.
session_name('BH_ADMIN_SESSION');
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

if ($role !== 'admin' && (int)$_SESSION['role'] !== 1) {
    header("Location: index.php");
    exit();
}

include "db.php";


if (isset($_GET['delete_user'])) {
    $id = (int)$_GET['delete_user'];

    if ($id > 0) {
        $stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    header("Location: manage_account.php");
    exit();
}

$data = [];
$roleQuery = mysqli_query($conn, "
    SELECT role, COUNT(*) AS total
    FROM users
    GROUP BY role
");

if ($roleQuery) {
    while ($row = mysqli_fetch_assoc($roleQuery)) {
        $data[] = $row;
    }
}

$users_result = mysqli_query($conn, "
    SELECT *
    FROM users
    ORDER BY id DESC
");

if (!$users_result) {
    die("SQL Error: " . mysqli_error($conn));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Accounts - BLACKHABIT</title>
<link rel="icon" type="image/x-icon" href="favicon_io/favicon.ico">
<link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="admin_sidebar.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
</head>
<body>

<?php include "admin_sidebar.php"; ?>

<div class="main">

    <div class="topbar">
        <button class="menu-toggle" id="menuToggle">
            <i class="fa-solid fa-bars"></i>
        </button>

        <h1>Account &amp; Access Control</h1>

        <div class="profile">
            <i class="fa-solid fa-circle-user" style="font-size:24px;color:#c59d5f;"></i>
            <div>
                <h3><?php echo htmlspecialchars($_SESSION['name'] ?? 'Administrator'); ?></h3>
                <span>Administrator</span>
            </div>
        </div>
    </div>

    <div class="welcome">
        <h2>User Management Workspace</h2>
        <p>Review system roles distributions, accounts records, and system permissions.</p>
    </div>

    <div class="cards">
        <?php foreach ($data as $role_info) { ?>
            <div class="card">
                <div>
                    <h3>
                        Total
                        <?php echo ucfirst(htmlspecialchars($role_info['role'] ?? 'User')); ?>s
                    </h3>
                    <h1><?php echo (int)$role_info['total']; ?></h1>
                </div>

                <div class="icon">
                    <i class="fa-solid <?php echo (($role_info['role'] ?? '') === 'admin') ? 'fa-user-shield' : 'fa-user'; ?>"></i>
                </div>
            </div>
        <?php } ?>
    </div>

    <div class="panel">
        <div class="panel-header">
            <h2>
                <i class="fa-solid fa-users-gear"></i>
                System Accounts Registry
            </h2>
        </div>

        <div class="table-container">
            <table class="product-table">
                <thead>
                    <tr>
                        <th>Account Holder Name</th>
                        <th>Email Address</th>
                        <th>Assigned System Role</th>
                        <th style="width:150px;">Action</th>
                    </tr>
                </thead>

                <tbody>
                <?php while ($user = mysqli_fetch_assoc($users_result)) { ?>
                    <?php
                    $fullName = trim(
                        ($user['first_name'] ?? '') . ' ' .
                        ($user['middle_name'] ?? '') . ' ' .
                        ($user['last_name'] ?? '')
                    );

                    $displayName = $fullName !== ''
                        ? $fullName
                        : 'No Name Provided';

                    $userRole = strtolower(trim((string)($user['role'] ?? 'customer')));
                    $roleClass = $userRole === 'admin' ? 'warning' : 'success';
                    ?>
                    <tr>
                        <td style="text-align:left;padding-left:20px;">
                            <?php echo htmlspecialchars($displayName); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($user['email'] ?? ''); ?>
                        </td>

                        <td>
                            <span class="badge <?php echo $roleClass; ?>">
                                <?php echo strtoupper(htmlspecialchars($userRole)); ?>
                            </span>
                        </td>

                        <td>
                            <a
                                class="delete-btn"
                                onclick="return confirm('Revoke account permissions permanently?')"
                                href="?delete_user=<?php echo (int)$user['id']; ?>"
                            >
                                Remove
                            </a>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

</body>
</html>

<?php
/**
 * login.php
 * Handle user login.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// If already logged in, go to dashboard
if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Simplest possible check:
    // Typically you'd fetch the user from DB and verify password_hash
    // For demonstration, let's do a real DB fetch
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT user_id, password_hash FROM users WHERE username = :username AND active = 1");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['user_id'];
        header('Location: dashboard.php');
        exit;
    } else {
        $message = 'Invalid credentials';
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>OTF Calendar - Login</title>
</head>
<body>
    <h1>Login to OTF Calendar</h1>
    <?php if ($message): ?>
        <p style="color:red;"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <form method="post">
        <label for="username">Username:</label>
        <input type="text" name="username" required><br>

        <label for="password">Password:</label>
        <input type="password" name="password" required><br>

        <button type="submit">Log In</button>
    </form>
</body>
</html>

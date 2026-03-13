<?php
require_once 'functions.php';

$message = null;
$messageType = null;

if (isAuthenticated()) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($username === DASHBOARD_USER && $password === DASHBOARD_PASS) {
        $_SESSION['auth_user'] = DASHBOARD_USER;
        header('Location: dashboard.php');
        exit;
    }

    $message = 'Invalid username or password.';
    $messageType = 'error';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Service Panel Login</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 0; background: #f4f6fb; color: #1f2937; }
    .container { max-width: 1100px; margin: 2rem auto; background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
    h1 { margin-top: 0; }
    .alert { padding: .75rem 1rem; border-radius: 8px; margin: 1rem 0; }
    .alert.success { background: #ecfdf3; color: #166534; border: 1px solid #86efac; }
    .alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
    .login-form { max-width: 360px; margin: 4rem auto; }
    input, button { padding: .65rem .75rem; border-radius: 8px; border: 1px solid #d1d5db; font-size: 1rem; }
    input { width: 100%; margin-bottom: .75rem; box-sizing: border-box; }
    button { border: none; background: #2563eb; color: white; cursor: pointer; }
    button:hover { background: #1d4ed8; }
  </style>
</head>
<body>
  <div class="container">
    <div class="login-form">
      <h1>Service Panel Login</h1>
      <p>Sign in with your admin account.</p>
      <?php if ($message !== null): ?>
        <div class="alert <?= htmlspecialchars($messageType ?? 'error') ?>"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="form" value="login">
        <label>
          Username
          <input type="text" name="username" required autocomplete="username">
        </label>
        <label>
          Password
          <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button type="submit">Login</button>
      </form>
    </div>
  </div>
</body>
</html>
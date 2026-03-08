<?php
session_start();

const DASHBOARD_USER = 'admin';
const DASHBOARD_PASS = 'db2026';
const SYSTEMCTL_PATH = '/bin/systemctl';
const USE_SUDO_FOR_SYSTEMCTL = true;

function isAuthenticated(): bool
{
    return isset($_SESSION['auth_user']) && $_SESSION['auth_user'] === DASHBOARD_USER;
}

function sanitizeServiceName(string $service): ?string
{
    $service = trim($service);
    if (preg_match('/^[A-Za-z0-9@._:-]+\.service$/', $service) !== 1) {
        return null;
    }

    return $service;
}

function runSystemctl(string $command): array
{
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);

    return [
        'output' => $output,
        'exitCode' => $exitCode,
    ];
}

function buildSystemctlCommand(string $args): string
{
    $prefix = USE_SUDO_FOR_SYSTEMCTL ? 'sudo ' : '';
    return $prefix . SYSTEMCTL_PATH . ' ' . $args;
}

function buildOverview(array $services): array
{
    $summary = [
        'total' => count($services),
        'active' => 0,
        'inactive' => 0,
        'failed' => 0,
        'other' => 0,
    ];

    foreach ($services as $service) {
        $state = $service['active'] ?? 'unknown';
        if (array_key_exists($state, $summary)) {
            $summary[$state]++;
        } else {
            $summary['other']++;
        }
    }

    return $summary;
}

$message = null;
$messageType = null;

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: /');
    exit;
}

if (!isAuthenticated() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($username === DASHBOARD_USER && $password === DASHBOARD_PASS) {
        $_SESSION['auth_user'] = DASHBOARD_USER;
        header('Location: /');
        exit;
    }

    $message = 'Invalid username or password.';
    $messageType = 'error';
}

if (isAuthenticated() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'service_action') {
    $allowedActions = ['start', 'stop', 'restart', 'reload'];
    $action = $_POST['action'] ?? '';
    $service = sanitizeServiceName($_POST['service'] ?? '');

    if (!in_array($action, $allowedActions, true) || $service === null) {
        $message = 'Invalid action or service name.';
        $messageType = 'error';
    } else {
        $result = runSystemctl(buildSystemctlCommand(sprintf('%s %s', escapeshellarg($action), escapeshellarg($service))));
        if ($result['exitCode'] === 0) {
            $message = sprintf('Successfully ran "%s" on %s.', $action, $service);
            $messageType = 'success';
        } else {
            $message = sprintf('Failed to run "%s" on %s.', $action, $service);
            $messageType = 'error';
            if (!empty($result['output'])) {
                $message .= ' Details: ' . implode(' ', $result['output']);
            }
        }
    }
}

$services = [];
$overview = null;
$serviceQueryError = null;
if (isAuthenticated()) {
    $result = runSystemctl(buildSystemctlCommand('list-units --type=service --all --no-legend --no-pager'));
    if ($result['exitCode'] !== 0) {
        $serviceQueryError = implode(' ', $result['output']);
    }

    foreach ($result['output'] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $parts = preg_split('/\s+/', $line, 5);
        if (!$parts || count($parts) < 4) {
            continue;
        }

        $services[] = [
            'name' => $parts[0],
            'load' => $parts[1] ?? 'unknown',
            'active' => $parts[2] ?? 'unknown',
            'sub' => $parts[3] ?? 'unknown',
            'description' => $parts[4] ?? '',
        ];
    }

    usort($services, static function (array $a, array $b): int {
        return strcmp($a['name'], $b['name']);
    });

    $overview = buildOverview($services);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Service Panel</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 0; background: #f4f6fb; color: #1f2937; }
    .container { max-width: 1100px; margin: 2rem auto; background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
    h1 { margin-top: 0; }
    .topbar { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
    .alert { padding: .75rem 1rem; border-radius: 8px; margin: 1rem 0; }
    .alert.success { background: #ecfdf3; color: #166534; border: 1px solid #86efac; }
    .alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
    .login-form { max-width: 360px; margin: 4rem auto; }
    input, button { padding: .65rem .75rem; border-radius: 8px; border: 1px solid #d1d5db; font-size: 1rem; }
    input { width: 100%; margin-bottom: .75rem; box-sizing: border-box; }
    button { border: none; background: #2563eb; color: white; cursor: pointer; }
    button:hover { background: #1d4ed8; }
    table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
    th, td { padding: .7rem; border-bottom: 1px solid #e5e7eb; font-size: .95rem; text-align: left; }
    .actions { display: flex; gap: .4rem; flex-wrap: wrap; }
    .actions button { font-size: .85rem; padding: .45rem .6rem; }
    .tag { display: inline-block; padding: .2rem .45rem; border-radius: 999px; font-size: .8rem; }
    .tag.active { background: #dcfce7; color: #166534; }
    .tag.inactive { background: #fee2e2; color: #991b1b; }
    .tag.unknown { background: #e5e7eb; color: #374151; }
    .overview { display: grid; grid-template-columns: repeat(5, minmax(120px, 1fr)); gap: .75rem; margin: 1rem 0 1.4rem; }
    .card { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 10px; padding: .8rem; }
    .card .label { font-size: .8rem; color: #475569; }
    .card .value { font-size: 1.3rem; font-weight: 700; }
  </style>
</head>
<body>
  <div class="container">
    <?php if (!isAuthenticated()): ?>
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
    <?php else: ?>
      <div class="topbar">
        <div>
          <h1>Systemd Service Dashboard</h1>
          <p>Logged in as <strong><?= htmlspecialchars($_SESSION['auth_user']) ?></strong></p>
        </div>
        <a href="/?logout=1">Logout</a>
      </div>

      <?php if ($message !== null): ?>
        <div class="alert <?= htmlspecialchars($messageType ?? 'error') ?>"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>

      <?php if ($serviceQueryError !== null): ?>
        <div class="alert error">
          Unable to read services from systemd. <?= htmlspecialchars($serviceQueryError) ?>
        </div>
      <?php endif; ?>

      <?php if ($overview !== null): ?>
        <div class="overview">
          <div class="card"><div class="label">Total</div><div class="value"><?= (int) $overview['total'] ?></div></div>
          <div class="card"><div class="label">Active</div><div class="value"><?= (int) $overview['active'] ?></div></div>
          <div class="card"><div class="label">Inactive</div><div class="value"><?= (int) $overview['inactive'] ?></div></div>
          <div class="card"><div class="label">Failed</div><div class="value"><?= (int) $overview['failed'] ?></div></div>
          <div class="card"><div class="label">Other</div><div class="value"><?= (int) $overview['other'] ?></div></div>
        </div>
      <?php endif; ?>

      <table>
        <thead>
          <tr>
            <th>Service</th>
            <th>Load</th>
            <th>Status</th>
            <th>Sub-state</th>
            <th>Description</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($services as $service): ?>
            <?php
              $statusClass = 'unknown';
              if ($service['active'] === 'active') {
                  $statusClass = 'active';
              } elseif ($service['active'] === 'inactive' || $service['active'] === 'failed') {
                  $statusClass = 'inactive';
              }
            ?>
            <tr>
              <td><?= htmlspecialchars($service['name']) ?></td>
              <td><?= htmlspecialchars($service['load']) ?></td>
              <td><span class="tag <?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($service['active']) ?></span></td>
              <td><?= htmlspecialchars($service['sub']) ?></td>
              <td><?= htmlspecialchars($service['description']) ?></td>
              <td>
                <form method="post" class="actions">
                  <input type="hidden" name="form" value="service_action">
                  <input type="hidden" name="service" value="<?= htmlspecialchars($service['name']) ?>">
                  <button type="submit" name="action" value="start">Start</button>
                  <button type="submit" name="action" value="stop">Stop</button>
                  <button type="submit" name="action" value="restart">Restart</button>
                  <button type="submit" name="action" value="reload">Reload</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</body>
</html>

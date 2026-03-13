<?php
session_start();

const DASHBOARD_USER = 'admin';
const DASHBOARD_PASS = 'RohitS5612@1819225612';
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
?>
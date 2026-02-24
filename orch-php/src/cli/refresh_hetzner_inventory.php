<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/hetzner.php';

$companyId = null;
$projectId = null;
$limit = 100;

foreach ($argv as $arg) {
    if (!is_string($arg)) {
        continue;
    }
    if (str_starts_with($arg, '--company=')) {
        $value = (int) substr($arg, 10);
        if ($value > 0) {
            $companyId = $value;
        }
    } elseif (str_starts_with($arg, '--project=')) {
        $value = (int) substr($arg, 10);
        if ($value > 0) {
            $projectId = $value;
        }
    } elseif (str_starts_with($arg, '--limit=')) {
        $value = (int) substr($arg, 8);
        if ($value > 0) {
            $limit = $value;
        }
    }
}

if ($limit < 1) {
    $limit = 1;
}
if ($limit > 1000) {
    $limit = 1000;
}

$lockStmt = db()->query("SELECT pg_try_advisory_lock(hashtext('omninoc_hetzner_inventory_refresh')) AS locked");
$lockedRaw = $lockStmt !== false ? $lockStmt->fetchColumn() : false;
$locked = ($lockedRaw === true || $lockedRaw === 't' || $lockedRaw === 1 || $lockedRaw === '1');
if (!$locked) {
    echo json_encode([
        'ok' => false,
        'message' => 'Outra execucao de refresh de inventario ja esta em andamento.',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}

try {
    $report = sync_all_hetzner_inventory($companyId, $projectId, $limit);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    db()->query("SELECT pg_advisory_unlock(hashtext('omninoc_hetzner_inventory_refresh'))");
}

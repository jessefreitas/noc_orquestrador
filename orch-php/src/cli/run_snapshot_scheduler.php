<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/snapshot_policy.php';

$limit = 20;
if (isset($argv[1]) && is_numeric($argv[1])) {
    $limit = (int) $argv[1];
}
if ($limit < 1) {
    $limit = 1;
}
if ($limit > 500) {
    $limit = 500;
}

$result = run_due_snapshot_policies($limit);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

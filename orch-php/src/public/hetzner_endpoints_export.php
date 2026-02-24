<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/hetzner_catalog.php';

require_auth();
$user = current_user();
if ($user === null) {
    redirect('/login.php');
}

$format = strtolower(trim((string) ($_GET['format'] ?? 'json')));
$catalog = hetzner_endpoint_catalog();
$grouped = hetzner_endpoint_catalog_grouped();

if ($format === 'md' || $format === 'markdown') {
    if (!headers_sent()) {
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="hetzner_endpoints_catalog.md"');
    }

    echo "# Hetzner Cloud API - Endpoints Catalog\n\n";
    echo "Base URL: `https://api.hetzner.cloud/v1`\n\n";
    echo "Total endpoints: `" . count($catalog) . "`\n\n";
    echo "Generated at: `" . gmdate('c') . "`\n\n";

    foreach ($grouped as $category => $operations) {
        echo "## " . $category . "\n\n";
        foreach ($operations as $operation) {
            $method = (string) ($operation['method'] ?? 'GET');
            $path = (string) ($operation['path'] ?? '/');
            $label = (string) ($operation['label'] ?? '');
            $id = (string) ($operation['id'] ?? '');
            echo "- `" . $method . " " . $path . "` | " . $label . " | `" . $id . "`\n";
        }
        echo "\n";
    }
    exit;
}

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="hetzner_endpoints_catalog.json"');
}

echo json_encode(
    [
        'provider' => 'hetzner',
        'base_url' => 'https://api.hetzner.cloud/v1',
        'generated_at' => gmdate('c'),
        'total' => count($catalog),
        'endpoints' => $catalog,
    ],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);


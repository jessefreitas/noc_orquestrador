<?php
declare(strict_types=1);

function hetzner_catalog_file_path(): string
{
    return __DIR__ . '/data/hetzner_endpoints_catalog.txt';
}

/**
 * @return array<int,array<string,string>>
 */
function hetzner_endpoint_catalog(): array
{
    $path = hetzner_catalog_file_path();
    if (!is_file($path)) {
        return [];
    }

    $rows = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($rows)) {
        return [];
    }

    $catalog = [];
    foreach ($rows as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = explode('|', $trimmed);
        if (count($parts) < 5) {
            continue;
        }

        $category = trim((string) $parts[0]);
        $method = strtoupper(trim((string) $parts[1]));
        $pathValue = trim((string) $parts[2]);
        $label = trim((string) $parts[3]);
        $operationId = trim((string) $parts[4]);

        if ($category === '' || $method === '' || $pathValue === '' || $label === '' || $operationId === '') {
            continue;
        }

        $catalog[] = [
            'category' => $category,
            'method' => $method,
            'path' => $pathValue,
            'label' => $label,
            'id' => $operationId,
        ];
    }

    return $catalog;
}

/**
 * @return array<string,array<int,array<string,string>>>
 */
function hetzner_endpoint_catalog_grouped(): array
{
    $grouped = [];
    foreach (hetzner_endpoint_catalog() as $operation) {
        $category = (string) ($operation['category'] ?? 'Other');
        if (!array_key_exists($category, $grouped)) {
            $grouped[$category] = [];
        }
        $grouped[$category][] = $operation;
    }
    ksort($grouped);
    return $grouped;
}

function hetzner_endpoint_by_id(string $operationId): ?array
{
    foreach (hetzner_endpoint_catalog() as $operation) {
        if ((string) ($operation['id'] ?? '') === $operationId) {
            return $operation;
        }
    }
    return null;
}

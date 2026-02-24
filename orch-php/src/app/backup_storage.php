<?php
declare(strict_types=1);

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/tenancy.php';

function backup_storage_provider_catalog(): array
{
    return [
        'cloudflare_r2' => [
            'label' => 'Cloudflare R2',
            'default_force_path_style' => true,
        ],
        'aws_s3' => [
            'label' => 'Amazon S3',
            'default_force_path_style' => false,
        ],
    ];
}

function backup_storage_migration_hint_command(): string
{
    return 'docker compose -f orch-php/docker-compose.yml exec -T db psql -U noc_user -d noc_orquestrador -f /docker-entrypoint-initdb.d/005-backup-storage.sql';
}

function backup_storage_ready(): bool
{
    static $ready = null;
    if (is_bool($ready)) {
        return $ready;
    }

    try {
        $stmt = db()->query(
            "SELECT
                to_regclass('public.global_backup_storages') IS NOT NULL
                AND to_regclass('public.company_backup_policies') IS NOT NULL"
        );
        $ready = (bool) $stmt->fetchColumn();
    } catch (Throwable $exception) {
        $ready = false;
    }

    return $ready;
}

function backup_storage_normalize_provider(string $provider): string
{
    $normalized = strtolower(trim($provider));
    return array_key_exists($normalized, backup_storage_provider_catalog()) ? $normalized : '';
}

function backup_storage_provider_label(string $provider): string
{
    $normalized = backup_storage_normalize_provider($provider);
    if ($normalized === '') {
        return strtoupper(trim($provider));
    }

    $catalog = backup_storage_provider_catalog();
    $meta = $catalog[$normalized] ?? [];
    return (string) ($meta['label'] ?? strtoupper($normalized));
}

function backup_storage_secret_hint(string $secret): string
{
    $trimmed = trim($secret);
    if ($trimmed === '') {
        return '';
    }

    if (strlen($trimmed) <= 8) {
        return str_repeat('*', strlen($trimmed));
    }

    return substr($trimmed, 0, 4) . '...' . substr($trimmed, -4);
}

/**
 * @param array<string,mixed> $payload
 */
function create_global_backup_storage(
    int $userId,
    string $provider,
    array $payload
): int {
    if (!backup_storage_ready()) {
        throw new RuntimeException(
            'Estrutura de backup storage nao encontrada. Rode a migration: ' . backup_storage_migration_hint_command()
        );
    }

    $normalizedProvider = backup_storage_normalize_provider($provider);
    if ($normalizedProvider === '') {
        throw new InvalidArgumentException('Provider de storage invalido.');
    }

    $name = trim((string) ($payload['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Nome do storage e obrigatorio.');
    }

    $accessKeyId = trim((string) ($payload['access_key_id'] ?? ''));
    if ($accessKeyId === '') {
        throw new InvalidArgumentException('Access key id e obrigatorio.');
    }

    $secretAccessKey = trim((string) ($payload['secret_access_key'] ?? ''));
    if ($secretAccessKey === '') {
        throw new InvalidArgumentException('Secret access key e obrigatorio.');
    }

    $catalog = backup_storage_provider_catalog();
    $providerMeta = $catalog[$normalizedProvider] ?? [];
    $defaultForcePathStyle = (bool) ($providerMeta['default_force_path_style'] ?? false);

    $normalizeOptional = static function ($value): ?string {
        $trimmed = trim((string) $value);
        return $trimmed !== '' ? $trimmed : null;
    };

    $stmt = db()->prepare(
        'INSERT INTO global_backup_storages (
            provider,
            name,
            account_identifier,
            endpoint_url,
            region,
            default_bucket,
            access_key_id_ciphertext,
            secret_access_key_ciphertext,
            access_key_hint,
            secret_key_hint,
            force_path_style,
            created_by
        ) VALUES (
            :provider,
            :name,
            :account_identifier,
            :endpoint_url,
            :region,
            :default_bucket,
            :access_key_id_ciphertext,
            :secret_access_key_ciphertext,
            :access_key_hint,
            :secret_key_hint,
            :force_path_style,
            :created_by
        )
        RETURNING id'
    );

    $stmt->execute([
        'provider' => $normalizedProvider,
        'name' => $name,
        'account_identifier' => $normalizeOptional($payload['account_identifier'] ?? null),
        'endpoint_url' => $normalizeOptional($payload['endpoint_url'] ?? null),
        'region' => $normalizeOptional($payload['region'] ?? null),
        'default_bucket' => $normalizeOptional($payload['default_bucket'] ?? null),
        'access_key_id_ciphertext' => encrypt_secret($accessKeyId),
        'secret_access_key_ciphertext' => encrypt_secret($secretAccessKey),
        'access_key_hint' => backup_storage_secret_hint($accessKeyId),
        'secret_key_hint' => backup_storage_secret_hint($secretAccessKey),
        'force_path_style' => isset($payload['force_path_style'])
            ? ((bool) $payload['force_path_style'] ? 1 : 0)
            : ($defaultForcePathStyle ? 1 : 0),
        'created_by' => $userId,
    ]);

    $storageId = (int) $stmt->fetchColumn();

    audit_log(
        null,
        null,
        $userId,
        'platform.backup_storage.created',
        'global_backup_storage',
        (string) $storageId,
        null,
        [
            'provider' => $normalizedProvider,
            'name' => $name,
        ]
    );

    return $storageId;
}

function list_global_backup_storages(bool $onlyActive = false): array
{
    if (!backup_storage_ready()) {
        return [];
    }

    $where = $onlyActive ? 'WHERE gbs.status = :status' : '';
    $stmt = db()->prepare(
        "SELECT
            gbs.id,
            gbs.provider,
            gbs.name,
            gbs.account_identifier,
            gbs.endpoint_url,
            gbs.region,
            gbs.default_bucket,
            gbs.access_key_hint,
            gbs.secret_key_hint,
            gbs.force_path_style,
            gbs.status,
            gbs.created_by,
            gbs.created_at,
            gbs.updated_at,
            COALESCE(usage_stats.enabled_companies, 0) AS enabled_companies
         FROM global_backup_storages gbs
         LEFT JOIN (
            SELECT
                global_backup_storage_id,
                COUNT(*) FILTER (WHERE enabled = TRUE) AS enabled_companies
            FROM company_backup_policies
            GROUP BY global_backup_storage_id
         ) usage_stats ON usage_stats.global_backup_storage_id = gbs.id
         {$where}
         ORDER BY gbs.created_at DESC"
    );

    if ($onlyActive) {
        $stmt->execute(['status' => 'active']);
    } else {
        $stmt->execute();
    }

    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function set_global_backup_storage_status(int $userId, int $storageId, string $status): void
{
    if (!backup_storage_ready()) {
        throw new RuntimeException(
            'Estrutura de backup storage nao encontrada. Rode a migration: ' . backup_storage_migration_hint_command()
        );
    }

    $normalizedStatus = strtolower(trim($status));
    if (!in_array($normalizedStatus, ['active', 'inactive'], true)) {
        throw new InvalidArgumentException('Status invalido.');
    }

    $stmt = db()->prepare(
        'UPDATE global_backup_storages
         SET status = :status,
             updated_at = NOW()
         WHERE id = :id'
    );
    $stmt->execute([
        'status' => $normalizedStatus,
        'id' => $storageId,
    ]);

    audit_log(
        null,
        null,
        $userId,
        'platform.backup_storage.status_changed',
        'global_backup_storage',
        (string) $storageId,
        null,
        ['status' => $normalizedStatus]
    );
}

function global_backup_storage_exists(int $storageId): bool
{
    if (!backup_storage_ready()) {
        return false;
    }

    $stmt = db()->prepare(
        'SELECT 1
         FROM global_backup_storages
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $storageId]);
    return $stmt->fetchColumn() !== false;
}

function get_company_backup_policy(int $userId, int $companyId): ?array
{
    if (!backup_storage_ready()) {
        return null;
    }

    if (!user_has_company_access($userId, $companyId)) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT
            cbp.id,
            cbp.company_id,
            cbp.global_backup_storage_id,
            cbp.enabled,
            cbp.billing_enabled,
            cbp.monthly_price,
            cbp.currency,
            cbp.postgres_bucket,
            cbp.postgres_prefix,
            cbp.retention_days,
            cbp.notes,
            cbp.created_at,
            cbp.updated_at,
            gbs.name AS storage_name,
            gbs.provider AS storage_provider,
            gbs.status AS storage_status
         FROM company_backup_policies cbp
         LEFT JOIN global_backup_storages gbs ON gbs.id = cbp.global_backup_storage_id
         WHERE cbp.company_id = :company_id
         LIMIT 1'
    );
    $stmt->execute(['company_id' => $companyId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return [
            'company_id' => $companyId,
            'global_backup_storage_id' => null,
            'enabled' => false,
            'billing_enabled' => false,
            'monthly_price' => null,
            'currency' => 'BRL',
            'postgres_bucket' => null,
            'postgres_prefix' => 'postgres',
            'retention_days' => 30,
            'notes' => null,
            'storage_name' => null,
            'storage_provider' => null,
            'storage_status' => null,
        ];
    }

    return $row;
}

/**
 * @param array<string,mixed> $payload
 */
function save_company_backup_policy(int $userId, int $companyId, array $payload): void
{
    if (!backup_storage_ready()) {
        throw new RuntimeException(
            'Estrutura de backup storage nao encontrada. Rode a migration: ' . backup_storage_migration_hint_command()
        );
    }

    if (!user_has_company_access($userId, $companyId)) {
        throw new RuntimeException('Sem acesso a empresa selecionada.');
    }

    $enabled = (bool) ($payload['enabled'] ?? false);
    $billingEnabled = (bool) ($payload['billing_enabled'] ?? false);
    $storageIdRaw = (int) ($payload['global_backup_storage_id'] ?? 0);
    $storageId = $storageIdRaw > 0 ? $storageIdRaw : null;

    if ($enabled && $storageId === null) {
        throw new InvalidArgumentException('Selecione o storage global para habilitar backup.');
    }
    if ($storageId !== null && !global_backup_storage_exists($storageId)) {
        throw new InvalidArgumentException('Storage global selecionado e invalido.');
    }

    $postgresBucket = trim((string) ($payload['postgres_bucket'] ?? ''));
    if ($enabled && $postgresBucket === '') {
        throw new InvalidArgumentException('Bucket Postgres e obrigatorio quando backup estiver habilitado.');
    }

    $postgresPrefix = trim((string) ($payload['postgres_prefix'] ?? 'postgres'));
    if ($postgresPrefix === '') {
        $postgresPrefix = 'postgres';
    }

    $retentionDays = (int) ($payload['retention_days'] ?? 30);
    if ($retentionDays < 1 || $retentionDays > 3650) {
        throw new InvalidArgumentException('Retencao deve ficar entre 1 e 3650 dias.');
    }

    $currency = strtoupper(trim((string) ($payload['currency'] ?? 'BRL')));
    if ($currency === '') {
        $currency = 'BRL';
    }
    if (strlen($currency) > 10) {
        throw new InvalidArgumentException('Moeda invalida.');
    }

    $monthlyPriceRaw = trim((string) ($payload['monthly_price'] ?? ''));
    $monthlyPrice = null;
    if ($monthlyPriceRaw !== '') {
        $normalizedPrice = str_replace(',', '.', $monthlyPriceRaw);
        if (!is_numeric($normalizedPrice)) {
            throw new InvalidArgumentException('Valor mensal invalido.');
        }
        $monthlyPrice = number_format((float) $normalizedPrice, 2, '.', '');
        if ((float) $monthlyPrice < 0) {
            throw new InvalidArgumentException('Valor mensal nao pode ser negativo.');
        }
    } elseif ($billingEnabled) {
        throw new InvalidArgumentException('Informe o valor mensal quando a cobranca estiver habilitada.');
    }

    $notes = trim((string) ($payload['notes'] ?? ''));
    $notes = $notes !== '' ? $notes : null;

    $stmt = db()->prepare(
        'INSERT INTO company_backup_policies (
            company_id,
            global_backup_storage_id,
            enabled,
            billing_enabled,
            monthly_price,
            currency,
            postgres_bucket,
            postgres_prefix,
            retention_days,
            notes,
            created_by,
            updated_by
         ) VALUES (
            :company_id,
            :global_backup_storage_id,
            :enabled,
            :billing_enabled,
            :monthly_price,
            :currency,
            :postgres_bucket,
            :postgres_prefix,
            :retention_days,
            :notes,
            :created_by,
            :updated_by
         )
         ON CONFLICT (company_id) DO UPDATE
         SET global_backup_storage_id = EXCLUDED.global_backup_storage_id,
             enabled = EXCLUDED.enabled,
             billing_enabled = EXCLUDED.billing_enabled,
             monthly_price = EXCLUDED.monthly_price,
             currency = EXCLUDED.currency,
             postgres_bucket = EXCLUDED.postgres_bucket,
             postgres_prefix = EXCLUDED.postgres_prefix,
             retention_days = EXCLUDED.retention_days,
             notes = EXCLUDED.notes,
             updated_by = EXCLUDED.updated_by,
             updated_at = NOW()'
    );

    $stmt->execute([
        'company_id' => $companyId,
        'global_backup_storage_id' => $storageId,
        'enabled' => $enabled ? 1 : 0,
        'billing_enabled' => $billingEnabled ? 1 : 0,
        'monthly_price' => $monthlyPrice,
        'currency' => $currency,
        'postgres_bucket' => $postgresBucket !== '' ? $postgresBucket : null,
        'postgres_prefix' => $postgresPrefix,
        'retention_days' => $retentionDays,
        'notes' => $notes,
        'created_by' => $userId,
        'updated_by' => $userId,
    ]);

    audit_log(
        $companyId,
        null,
        $userId,
        'company.backup_policy.updated',
        'company_backup_policy',
        (string) $companyId,
        null,
        [
            'enabled' => $enabled,
            'billing_enabled' => $billingEnabled,
            'global_backup_storage_id' => $storageId,
            'retention_days' => $retentionDays,
        ]
    );
}

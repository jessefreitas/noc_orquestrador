<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/observability_config.php';

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'project-' . time();
}

function provider_catalog(): array
{
    return [
        'hetzner' => 'Hetzner',
        'cloudflare' => 'Cloudflare',
        'n8n' => 'N8N',
        'portainer' => 'Portainer',
        'mega' => 'Mega',
        'proxmox' => 'ProxMox',
        'llm' => 'LLM',
    ];
}

function provider_label(string $providerType): string
{
    $key = strtolower(trim($providerType));
    $catalog = provider_catalog();
    return $catalog[$key] ?? strtoupper($key);
}

function list_user_companies(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT c.id, c.name, c.legal_name, c.tax_id, c.billing_email, c.phone,
                c.alert_email, c.alert_phone, c.alert_whatsapp, c.timezone, c.notes,
                c.status, cu.role
         FROM companies c
         INNER JOIN company_users cu ON cu.company_id = c.id
         WHERE cu.user_id = :user_id
           AND c.status = :status
         ORDER BY c.name'
    );
    $stmt->execute([
        'user_id' => $userId,
        'status' => 'active',
    ]);
    return $stmt->fetchAll();
}

function list_company_projects(int $userId, int $companyId): array
{
    $stmt = db()->prepare(
        'SELECT p.id, p.company_id, p.name, p.slug, p.capabilities, p.management_api_base_url, p.status
         FROM projects p
         INNER JOIN company_users cu ON cu.company_id = p.company_id
         WHERE cu.user_id = :user_id
           AND p.company_id = :company_id
           AND p.status = :status
         ORDER BY p.name'
    );
    $stmt->execute([
        'user_id' => $userId,
        'company_id' => $companyId,
        'status' => 'active',
    ]);

    $rows = $stmt->fetchAll();
    if (!is_array($rows)) {
        return [];
    }

    return array_values(array_filter(
        $rows,
        static fn (array $row): bool => infer_provider_type_from_project($row) !== null
    ));
}

function list_company_projects_all(int $userId, int $companyId): array
{
    $stmt = db()->prepare(
        'SELECT p.id, p.company_id, p.name, p.slug, p.capabilities, p.management_api_base_url, p.status
         FROM projects p
         INNER JOIN company_users cu ON cu.company_id = p.company_id
         WHERE cu.user_id = :user_id
           AND p.company_id = :company_id
         ORDER BY p.name'
    );
    $stmt->execute([
        'user_id' => $userId,
        'company_id' => $companyId,
    ]);
    return $stmt->fetchAll();
}

function list_accessible_projects(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT p.id,
                p.company_id,
                p.name,
                p.slug,
                p.status,
                p.capabilities,
                p.management_api_base_url,
                c.name AS company_name
         FROM projects p
         INNER JOIN companies c ON c.id = p.company_id
         INNER JOIN company_users cu ON cu.company_id = p.company_id
         WHERE cu.user_id = :user_id
           AND p.status = :status
         ORDER BY c.name, p.name'
    );
    $stmt->execute([
        'user_id' => $userId,
        'status' => 'active',
    ]);
    $rows = $stmt->fetchAll();
    if (!is_array($rows)) {
        return [];
    }

    return array_values(array_filter(
        $rows,
        static fn (array $row): bool => infer_provider_type_from_project($row) !== null
    ));
}

function get_project_for_user(int $userId, int $projectId): ?array
{
    $stmt = db()->prepare(
        'SELECT p.id,
                p.company_id,
                p.name,
                p.slug,
                p.status,
                p.capabilities,
                p.management_api_base_url,
                c.name AS company_name
         FROM projects p
         INNER JOIN companies c ON c.id = p.company_id
         INNER JOIN company_users cu ON cu.company_id = p.company_id
         WHERE cu.user_id = :user_id
           AND p.id = :project_id
         LIMIT 1'
    );
    $stmt->execute([
        'user_id' => $userId,
        'project_id' => $projectId,
    ]);

    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function user_has_company_access(int $userId, int $companyId): bool
{
    $stmt = db()->prepare(
        'SELECT 1
         FROM company_users
         WHERE user_id = :user_id
           AND company_id = :company_id
         LIMIT 1'
    );
    $stmt->execute([
        'user_id' => $userId,
        'company_id' => $companyId,
    ]);

    return $stmt->fetchColumn() !== false;
}

function get_user_company_role(int $userId, int $companyId): ?string
{
    $stmt = db()->prepare(
        'SELECT role
         FROM company_users
         WHERE user_id = :user_id
           AND company_id = :company_id
         LIMIT 1'
    );
    $stmt->execute([
        'user_id' => $userId,
        'company_id' => $companyId,
    ]);

    $role = $stmt->fetchColumn();
    if (!is_string($role) || trim($role) === '') {
        return null;
    }

    return strtolower(trim($role));
}

function user_can_manage_company_context(int $userId, int $companyId, ?array $user = null): bool
{
    if ($companyId <= 0) {
        return false;
    }

    if (is_platform_owner($user)) {
        return true;
    }

    $role = get_user_company_role($userId, $companyId);
    return in_array($role, ['owner', 'admin'], true);
}

function user_has_project_access(int $userId, int $companyId, int $projectId): bool
{
    $stmt = db()->prepare(
        'SELECT 1
         FROM projects p
         INNER JOIN company_users cu ON cu.company_id = p.company_id
         WHERE cu.user_id = :user_id
           AND p.company_id = :company_id
           AND p.id = :project_id
           AND p.status = :status
         LIMIT 1'
    );
    $stmt->execute([
        'user_id' => $userId,
        'company_id' => $companyId,
        'project_id' => $projectId,
        'status' => 'active',
    ]);

    return $stmt->fetchColumn() !== false;
}

function load_user_context(int $userId): array
{
    start_session();

    $companies = list_user_companies($userId);
    if ($companies === []) {
        return [
            'companies' => [],
            'projects' => [],
            'services' => [],
            'services_by_company' => [],
            'company_id' => null,
            'project_id' => null,
            'service_id' => null,
            'company' => null,
            'project' => null,
            'service' => null,
        ];
    }

    $servicesByCompany = [];
    foreach ($companies as $companyRow) {
        $companyRowId = (int) ($companyRow['id'] ?? 0);
        if ($companyRowId <= 0) {
            continue;
        }
        $servicesByCompany[$companyRowId] = list_company_projects($userId, $companyRowId);
    }

    $selectedCompanyId = (int) ($_SESSION['company_id'] ?? 0);
    if ($selectedCompanyId === 0 || !user_has_company_access($userId, $selectedCompanyId)) {
        $selectedCompanyId = (int) $companies[0]['id'];
    }

    $projects = $servicesByCompany[$selectedCompanyId] ?? [];
    $selectedProjectId = (int) ($_SESSION['project_id'] ?? 0);
    $projectExists = false;
    foreach ($projects as $project) {
        if ((int) $project['id'] === $selectedProjectId) {
            $projectExists = true;
            break;
        }
    }
    if (!$projectExists) {
        $selectedProjectId = 0;
    }

    $_SESSION['company_id'] = $selectedCompanyId;
    $_SESSION['project_id'] = $selectedProjectId;

    $selectedCompany = null;
    foreach ($companies as $company) {
        if ((int) $company['id'] === $selectedCompanyId) {
            $selectedCompany = $company;
            break;
        }
    }

    $selectedProject = null;
    foreach ($projects as $project) {
        if ((int) $project['id'] === $selectedProjectId) {
            $selectedProject = $project;
            break;
        }
    }

    return [
        'companies' => $companies,
        'projects' => $projects,
        'services' => $projects,
        'services_by_company' => $servicesByCompany,
        'company_id' => $selectedCompanyId,
        'project_id' => $selectedProjectId > 0 ? $selectedProjectId : null,
        'service_id' => $selectedProjectId > 0 ? $selectedProjectId : null,
        'company' => $selectedCompany,
        'project' => $selectedProject,
        'service' => $selectedProject,
    ];
}

function set_user_context(int $userId, int $companyId, int $projectId): bool
{
    if (!user_has_company_access($userId, $companyId)) {
        return false;
    }

    if ($projectId <= 0) {
        start_session();
        $_SESSION['company_id'] = $companyId;
        $_SESSION['project_id'] = 0;
        return true;
    }

    if (!user_has_project_access($userId, $companyId, $projectId)) {
        return false;
    }

    start_session();
    $_SESSION['company_id'] = $companyId;
    $_SESSION['project_id'] = $projectId;
    return true;
}

/**
 * @param array<string,string|null> $payload
 */
function create_company(int $userId, string $name, array $payload = []): int
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Nome da empresa e obrigatorio.');
    }

    $normalize = static function (?string $value): ?string {
        $trimmed = trim((string) $value);
        return $trimmed !== '' ? $trimmed : null;
    };

    $stmt = db()->prepare(
        'INSERT INTO companies (
            name,
            legal_name,
            tax_id,
            billing_email,
            phone,
            alert_email,
            alert_phone,
            alert_whatsapp,
            timezone,
            notes
         )
         VALUES (
            :name,
            :legal_name,
            :tax_id,
            :billing_email,
            :phone,
            :alert_email,
            :alert_phone,
            :alert_whatsapp,
            :timezone,
            :notes
         )
         RETURNING id'
    );
    $stmt->execute([
        'name' => $name,
        'legal_name' => $normalize($payload['legal_name'] ?? null),
        'tax_id' => $normalize($payload['tax_id'] ?? null),
        'billing_email' => $normalize($payload['billing_email'] ?? null),
        'phone' => $normalize($payload['phone'] ?? null),
        'alert_email' => $normalize($payload['alert_email'] ?? null),
        'alert_phone' => $normalize($payload['alert_phone'] ?? null),
        'alert_whatsapp' => $normalize($payload['alert_whatsapp'] ?? null),
        'timezone' => $normalize($payload['timezone'] ?? null) ?? 'America/Sao_Paulo',
        'notes' => $normalize($payload['notes'] ?? null),
    ]);
    $companyId = (int) $stmt->fetchColumn();

    $linkStmt = db()->prepare(
        'INSERT INTO company_users (company_id, user_id, role)
         VALUES (:company_id, :user_id, :role)'
    );
    $linkStmt->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
        'role' => 'owner',
    ]);

    audit_log(
        $companyId,
        null,
        $userId,
        'company.created',
        'company',
        (string) $companyId,
        null,
        ['name' => $name]
    );

    return $companyId;
}

function get_company_for_user(int $userId, int $companyId): ?array
{
    $stmt = db()->prepare(
        'SELECT c.id,
                c.name,
                c.legal_name,
                c.tax_id,
                c.billing_email,
                c.phone,
                c.alert_email,
                c.alert_phone,
                c.alert_whatsapp,
                c.timezone,
                c.notes,
                c.status,
                c.created_at,
                c.updated_at,
                cu.role
         FROM companies c
         INNER JOIN company_users cu ON cu.company_id = c.id
         WHERE cu.user_id = :user_id
           AND c.id = :company_id
         LIMIT 1'
    );
    $stmt->execute([
        'user_id' => $userId,
        'company_id' => $companyId,
    ]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * @param array<string,string|null> $payload
 */
function update_company_profile(int $userId, int $companyId, array $payload): void
{
    if (!user_has_company_access($userId, $companyId)) {
        throw new RuntimeException('Sem acesso a empresa selecionada.');
    }

    $name = trim((string) ($payload['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Nome da empresa e obrigatorio.');
    }

    $stmt = db()->prepare(
        'UPDATE companies
         SET name = :name,
             legal_name = :legal_name,
             tax_id = :tax_id,
             billing_email = :billing_email,
             phone = :phone,
             alert_email = :alert_email,
             alert_phone = :alert_phone,
             alert_whatsapp = :alert_whatsapp,
             timezone = :timezone,
             notes = :notes,
             updated_at = NOW()
         WHERE id = :company_id'
    );

    $normalize = static function (?string $value): ?string {
        $trimmed = trim((string) $value);
        return $trimmed !== '' ? $trimmed : null;
    };

    $stmt->execute([
        'name' => $name,
        'legal_name' => $normalize($payload['legal_name'] ?? null),
        'tax_id' => $normalize($payload['tax_id'] ?? null),
        'billing_email' => $normalize($payload['billing_email'] ?? null),
        'phone' => $normalize($payload['phone'] ?? null),
        'alert_email' => $normalize($payload['alert_email'] ?? null),
        'alert_phone' => $normalize($payload['alert_phone'] ?? null),
        'alert_whatsapp' => $normalize($payload['alert_whatsapp'] ?? null),
        'timezone' => $normalize($payload['timezone'] ?? null) ?? 'America/Sao_Paulo',
        'notes' => $normalize($payload['notes'] ?? null),
        'company_id' => $companyId,
    ]);

    audit_log(
        $companyId,
        null,
        $userId,
        'company.updated',
        'company',
        (string) $companyId,
        null,
        ['name' => $name]
    );
}

function archive_company(int $userId, int $companyId): void
{
    if (!user_has_company_access($userId, $companyId)) {
        throw new RuntimeException('Sem acesso a empresa selecionada.');
    }

    $stmt = db()->prepare(
        'UPDATE companies
         SET status = :status,
             updated_at = NOW()
         WHERE id = :company_id'
    );
    $stmt->execute([
        'status' => 'archived',
        'company_id' => $companyId,
    ]);

    audit_log(
        $companyId,
        null,
        $userId,
        'company.archived',
        'company',
        (string) $companyId,
        null,
        ['status' => 'archived']
    );
}

function list_company_alert_contacts(int $userId, int $companyId): array
{
    if (!user_has_company_access($userId, $companyId)) {
        return [];
    }

    $stmt = db()->prepare(
        'SELECT id,
                company_id,
                name,
                role,
                email,
                phone,
                whatsapp,
                receive_incident_alerts,
                receive_billing_alerts,
                status,
                created_at,
                updated_at
         FROM company_alert_contacts
         WHERE company_id = :company_id
         ORDER BY created_at DESC'
    );
    $stmt->execute(['company_id' => $companyId]);
    return $stmt->fetchAll();
}

/**
 * @param array<string,mixed> $payload
 */
function create_company_alert_contact(int $userId, int $companyId, array $payload): int
{
    if (!user_has_company_access($userId, $companyId)) {
        throw new RuntimeException('Sem acesso a empresa selecionada.');
    }

    $name = trim((string) ($payload['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Nome do contato e obrigatorio.');
    }

    $normalize = static function (?string $value): ?string {
        $trimmed = trim((string) $value);
        return $trimmed !== '' ? $trimmed : null;
    };

    $stmt = db()->prepare(
        'INSERT INTO company_alert_contacts (
            company_id,
            name,
            role,
            email,
            phone,
            whatsapp,
            receive_incident_alerts,
            receive_billing_alerts
        ) VALUES (
            :company_id,
            :name,
            :role,
            :email,
            :phone,
            :whatsapp,
            :receive_incident_alerts,
            :receive_billing_alerts
        )
        RETURNING id'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'name' => $name,
        'role' => $normalize($payload['role'] ?? null),
        'email' => $normalize($payload['email'] ?? null),
        'phone' => $normalize($payload['phone'] ?? null),
        'whatsapp' => $normalize($payload['whatsapp'] ?? null),
        'receive_incident_alerts' => (bool) ($payload['receive_incident_alerts'] ?? false),
        'receive_billing_alerts' => (bool) ($payload['receive_billing_alerts'] ?? false),
    ]);
    $contactId = (int) $stmt->fetchColumn();

    audit_log(
        $companyId,
        null,
        $userId,
        'company.alert_contact.created',
        'company_alert_contact',
        (string) $contactId,
        null,
        ['name' => $name]
    );

    return $contactId;
}

function delete_company_alert_contact(int $userId, int $companyId, int $contactId): void
{
    if (!user_has_company_access($userId, $companyId)) {
        throw new RuntimeException('Sem acesso a empresa selecionada.');
    }

    $stmt = db()->prepare(
        'DELETE FROM company_alert_contacts
         WHERE id = :id
           AND company_id = :company_id'
    );
    $stmt->execute([
        'id' => $contactId,
        'company_id' => $companyId,
    ]);

    audit_log(
        $companyId,
        null,
        $userId,
        'company.alert_contact.deleted',
        'company_alert_contact',
        (string) $contactId,
        null,
        null
    );
}

function create_project(
    int $userId,
    int $companyId,
    string $name,
    ?string $slug = null,
    ?string $managementApiBaseUrl = null,
    array $capabilities = []
): int {
    if (!user_has_company_access($userId, $companyId)) {
        throw new RuntimeException('Sem acesso a empresa selecionada.');
    }

    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Nome do projeto e obrigatorio.');
    }

    $projectSlug = slugify($slug === null || trim($slug) === '' ? $name : $slug);
    $capabilitiesJson = $capabilities === []
        ? '{"servers": true, "apis": true, "domains": true, "observability": true, "costs": true, "snapshots": true, "hetzner": false, "cloudflare": false, "n8n": false, "portainer": false, "mega": false, "proxmox": false, "llm": false}'::jsonb
        : ':capabilities::jsonb';

    if ($capabilities === []) {
        $stmt = db()->prepare(
            "INSERT INTO projects (
                company_id,
                name,
                slug,
                management_api_base_url,
                capabilities
            ) VALUES (
                :company_id,
                :name,
                :slug,
                :management_api_base_url,
                {$capabilitiesJson}
            )
            RETURNING id"
        );

        $stmt->execute([
            'company_id' => $companyId,
            'name' => $name,
            'slug' => $projectSlug,
            'management_api_base_url' => trim((string) $managementApiBaseUrl) !== '' ? trim((string) $managementApiBaseUrl) : null,
        ]);
    } else {
        $stmt = db()->prepare(
            "INSERT INTO projects (
                company_id,
                name,
                slug,
                management_api_base_url,
                capabilities
            ) VALUES (
                :company_id,
                :name,
                :slug,
                :management_api_base_url,
                {$capabilitiesJson}
            )
            RETURNING id"
        );

        $stmt->execute([
            'company_id' => $companyId,
            'name' => $name,
            'slug' => $projectSlug,
            'management_api_base_url' => trim((string) $managementApiBaseUrl) !== '' ? trim((string) $managementApiBaseUrl) : null,
            'capabilities' => json_encode($capabilities, JSON_UNESCAPED_SLASHES),
        ]);
    }

    $projectId = (int) $stmt->fetchColumn();

    audit_log(
        $companyId,
        $projectId,
        $userId,
        'project.created',
        'project',
        (string) $projectId,
        null,
        [
            'name' => $name,
            'slug' => $projectSlug,
        ]
    );

    try {
        ensure_project_observability_defaults($companyId, $projectId, $userId, true);
    } catch (Throwable $exception) {
        // Nao bloquear onboarding por falha de seed de observabilidade.
    }

    return $projectId;
}

function list_company_service_bindings(int $userId, int $companyId): array
{
    if (!user_has_company_access($userId, $companyId)) {
        return [];
    }

    $projects = list_company_projects_all($userId, $companyId);
    $bindings = [];
    foreach ($projects as $project) {
        $providerType = infer_provider_type_from_project($project);
        if ($providerType === null) {
            continue;
        }
        if (!array_key_exists($providerType, $bindings)) {
            $bindings[$providerType] = [];
        }
        $bindings[$providerType][] = $project;
    }

    return $bindings;
}

function enabled_provider_types_for_company(int $userId, int $companyId): array
{
    $bindings = list_company_service_bindings($userId, $companyId);
    $enabled = [];
    foreach ($bindings as $providerType => $projects) {
        foreach ($projects as $project) {
            if ((string) ($project['status'] ?? '') === 'active') {
                $enabled[] = $providerType;
                break;
            }
        }
    }
    sort($enabled);
    return $enabled;
}

function sync_company_enabled_providers(int $userId, int $companyId, array $enabledProviders): void
{
    if (!user_has_company_access($userId, $companyId)) {
        throw new RuntimeException('Sem acesso a empresa selecionada.');
    }

    $catalog = provider_catalog();
    $normalized = [];
    foreach ($enabledProviders as $providerType) {
        $key = strtolower(trim((string) $providerType));
        if ($key !== '' && array_key_exists($key, $catalog)) {
            $normalized[$key] = true;
        }
    }

    $bindings = list_company_service_bindings($userId, $companyId);
    $company = get_company_for_user($userId, $companyId);
    $companyName = is_array($company) ? (string) ($company['name'] ?? 'Empresa') : 'Empresa';

    foreach ($catalog as $providerType => $providerLabel) {
        $isEnabled = isset($normalized[$providerType]);
        $providerProjects = $bindings[$providerType] ?? [];
        $hasActive = false;

        foreach ($providerProjects as $project) {
            if ((string) ($project['status'] ?? '') === 'active') {
                $hasActive = true;
                break;
            }
        }

        if ($isEnabled && !$hasActive) {
            if ($providerProjects !== []) {
                $projectToReactivate = $providerProjects[0];
                $stmt = db()->prepare(
                    'UPDATE projects
                     SET status = :status
                     WHERE id = :project_id
                       AND company_id = :company_id'
                );
                $stmt->execute([
                    'status' => 'active',
                    'project_id' => (int) $projectToReactivate['id'],
                    'company_id' => $companyId,
                ]);
                audit_log(
                    $companyId,
                    (int) $projectToReactivate['id'],
                    $userId,
                    'service.binding.enabled',
                    'project',
                    (string) ((int) $projectToReactivate['id']),
                    ['status' => $projectToReactivate['status'] ?? null],
                    ['status' => 'active', 'provider' => $providerType]
                );
                try {
                    ensure_project_observability_defaults($companyId, (int) $projectToReactivate['id'], $userId, true);
                } catch (Throwable $exception) {
                    // Nao bloquear ativacao do servico por falha de seed de observabilidade.
                }
            } else {
                $defaultName = $providerLabel . ' - ' . $companyName;
                $defaultSlug = slugify($providerType . '-' . $companyName);
                create_project(
                    $userId,
                    $companyId,
                    $defaultName,
                    $defaultSlug,
                    null,
                    default_capabilities_for_provider($providerType)
                );
            }
        }

        if (!$isEnabled && $hasActive) {
            foreach ($providerProjects as $project) {
                if ((string) ($project['status'] ?? '') !== 'active') {
                    continue;
                }
                $stmt = db()->prepare(
                    'UPDATE projects
                     SET status = :status
                     WHERE id = :project_id
                       AND company_id = :company_id'
                );
                $stmt->execute([
                    'status' => 'inactive',
                    'project_id' => (int) $project['id'],
                    'company_id' => $companyId,
                ]);
                audit_log(
                    $companyId,
                    (int) $project['id'],
                    $userId,
                    'service.binding.disabled',
                    'project',
                    (string) ((int) $project['id']),
                    ['status' => $project['status'] ?? null],
                    ['status' => 'inactive', 'provider' => $providerType]
                );
            }
        }
    }
}

function infer_provider_type_from_project(array $project): ?string
{
    $providers = array_keys(provider_catalog());

    $directType = strtolower(trim((string) ($project['provider_type'] ?? '')));
    if (in_array($directType, $providers, true)) {
        return $directType;
    }

    $capabilities = [];
    $rawCapabilities = $project['capabilities'] ?? [];
    if (is_array($rawCapabilities)) {
        $capabilities = $rawCapabilities;
    } elseif (is_string($rawCapabilities) && trim($rawCapabilities) !== '') {
        $decoded = json_decode($rawCapabilities, true);
        if (is_array($decoded)) {
            $capabilities = $decoded;
        }
    }

    $capabilityType = strtolower(trim((string) ($capabilities['provider'] ?? $capabilities['provider_type'] ?? '')));
    if (in_array($capabilityType, $providers, true)) {
        return $capabilityType;
    }

    $enabledProviders = [];
    foreach ($providers as $provider) {
        $value = $capabilities[$provider] ?? false;
        if ($value === true || $value === 1 || $value === '1') {
            $enabledProviders[] = $provider;
        }
    }
    if (count($enabledProviders) === 1) {
        return $enabledProviders[0];
    }

    $nameSlug = strtolower(trim((string) (($project['name'] ?? '') . ' ' . ($project['slug'] ?? ''))));
    foreach ($providers as $provider) {
        if ($nameSlug !== '' && str_contains($nameSlug, $provider)) {
            return $provider;
        }
    }

    return null;
}

function context_provider_type(array $context): ?string
{
    $service = $context['service'] ?? $context['project'] ?? null;
    if (!is_array($service)) {
        return null;
    }

    return infer_provider_type_from_project($service);
}

function default_capabilities_for_provider(string $providerType): array
{
    $provider = strtolower(trim($providerType));

    $defaults = [
        'servers' => true,
        'apis' => true,
        'domains' => true,
        'observability' => true,
        'costs' => true,
        'snapshots' => true,
        'hetzner' => false,
        'cloudflare' => false,
        'n8n' => false,
        'portainer' => false,
        'mega' => false,
        'proxmox' => false,
        'llm' => false,
    ];

    if ($provider === '' || !in_array($provider, array_keys(provider_catalog()), true)) {
        return $defaults;
    }

    $defaults['provider'] = $provider;
    $defaults[$provider] = true;

    if ($provider === 'cloudflare') {
        $defaults['servers'] = false;
    }

    if ($provider === 'n8n') {
        $defaults['domains'] = false;
    }

    if ($provider === 'llm') {
        $defaults['servers'] = false;
        $defaults['apis'] = false;
        $defaults['domains'] = false;
        $defaults['observability'] = false;
        $defaults['costs'] = false;
        $defaults['snapshots'] = false;
    }

    return $defaults;
}

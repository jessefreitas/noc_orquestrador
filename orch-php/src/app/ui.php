<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function ui_project_capabilities(array $context): array
{
    $project = $context['project'] ?? null;
    if (!is_array($project)) {
        return [];
    }

    $raw = $project['capabilities'] ?? [];
    if (is_array($raw)) {
        return $raw;
    }

    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

function ui_capability_enabled(array $capabilities, string $key): bool
{
    if (!array_key_exists($key, $capabilities)) {
        return true;
    }

    $value = $capabilities[$key];
    return $value === true || $value === 1 || $value === '1';
}

function ui_page_start(string $title): void
{
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    ?>
<!doctype html>
<html lang="pt-BR" data-theme="dark" data-bs-theme="dark">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <script>
      (function () {
        var saved = "dark";
        try { saved = localStorage.getItem("mega_theme") || "dark"; } catch (error) {}
        var theme = saved === "light" ? "light" : "dark";
        document.documentElement.setAttribute("data-theme", theme);
        document.documentElement.setAttribute("data-bs-theme", theme);
      })();
    </script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/vendor/adminlte/css/adminlte.min.css">
    <link rel="stylesheet" href="/assets/css/mega-theme.css">
  </head>
  <body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
    <?php
}

function ui_navigation(
    string $active,
    array $user,
    array $context,
    ?array $flash
): void {
    $isPlatformOwner = is_platform_owner_effective($user);
    $isImpersonating = is_impersonating();
    $authUser = current_auth_user();
    $impersonation = impersonation_info();
    $companyId = $context['company_id'] ?? null;
    $projectId = $context['service_id'] ?? $context['project_id'] ?? null;
    $companies = $context['companies'] ?? [];
    $projects = $context['services'] ?? $context['projects'] ?? [];
    $servicesByCompany = $context['services_by_company'] ?? [];
    if (!is_array($servicesByCompany)) {
        $servicesByCompany = [];
    }
    $currentCompanyServices = [];
    if (is_int($companyId) && array_key_exists($companyId, $servicesByCompany) && is_array($servicesByCompany[$companyId])) {
        $currentCompanyServices = $servicesByCompany[$companyId];
    }
    if ($currentCompanyServices === []) {
        $currentCompanyServices = is_array($projects) ? $projects : [];
    }
    $projects = $currentCompanyServices;
    $capabilities = ui_project_capabilities($context);
    $hasServiceContext = is_int($projectId) && $projectId > 0;
    $currentProvider = function_exists('context_provider_type') ? context_provider_type($context) : null;

    $providers = [
        'hetzner' => ['label' => 'Hetzner', 'icon' => 'bi-hdd-network', 'landing' => '/hetzner.php', 'dashboard' => '/hetzner_dashboard.php'],
        'cloudflare' => ['label' => 'Cloudflare', 'icon' => 'bi-cloud', 'landing' => '/domains.php', 'dashboard' => '/domains.php'],
        'n8n' => ['label' => 'N8N', 'icon' => 'bi-bezier2', 'landing' => '/n8n.php', 'dashboard' => '/n8n.php'],
        'portainer' => ['label' => 'Portainer', 'icon' => 'bi-box-seam', 'landing' => '/portainer.php?tab=cadastro', 'dashboard' => '/portainer.php?tab=dashboard'],
        'mega' => ['label' => 'Mega', 'icon' => 'bi-lightning-charge', 'landing' => '/mega.php', 'dashboard' => '/mega.php'],
        'proxmox' => ['label' => 'ProxMox', 'icon' => 'bi-server', 'landing' => '/proxmox.php', 'dashboard' => '/proxmox.php'],
        'llm' => ['label' => 'LLM', 'icon' => 'bi-robot', 'landing' => '/llm.php', 'dashboard' => '/llm.php'],
    ];
    $formatServiceOptionLabel = static function (array $serviceRow, array $providerCounts): string {
        $providerType = null;
        if (function_exists('infer_provider_type_from_project')) {
            $providerType = infer_provider_type_from_project($serviceRow);
        }

        $baseLabel = trim((string) ($serviceRow['name'] ?? 'Fornecedor'));
        if (is_string($providerType) && $providerType !== '') {
            if (function_exists('provider_label')) {
                $baseLabel = provider_label($providerType);
            } else {
                $baseLabel = strtoupper($providerType);
            }
        }

        $slug = trim((string) ($serviceRow['slug'] ?? ''));
        if ($providerType !== null && ($providerCounts[$providerType] ?? 0) > 1 && $slug !== '') {
            return $baseLabel . ' - ' . $slug;
        }

        return $baseLabel;
    };

    $providerCountsForCurrentCompany = [];
    $providerDefaultProjectId = [];
    foreach ($projects as $projectRow) {
        $providerType = function_exists('infer_provider_type_from_project')
            ? infer_provider_type_from_project($projectRow)
            : null;
        if (!is_string($providerType) || $providerType === '') {
            continue;
        }
        if (!array_key_exists($providerType, $providerDefaultProjectId)) {
            $providerDefaultProjectId[$providerType] = (int) ($projectRow['id'] ?? 0);
        }
        if (!array_key_exists($providerType, $providerCountsForCurrentCompany)) {
            $providerCountsForCurrentCompany[$providerType] = 0;
        }
        $providerCountsForCurrentCompany[$providerType]++;
    }

    $enabledProviderTypesForCompany = [];
    foreach (array_keys($providerCountsForCurrentCompany) as $providerType) {
        $enabledProviderTypesForCompany[$providerType] = true;
    }

    $identityName = (string) ($user['name'] ?? 'Usuario');
    $identityEmail = (string) ($user['email'] ?? '-');
    $identityMode = $isImpersonating ? 'Emulacao ativa' : 'Sessao normal';
    $operatorEmail = is_array($authUser) ? (string) ($authUser['email'] ?? '') : '';
    $contextCompanyName = (string) ($context['company']['name'] ?? 'Sem empresa');
    $contextServiceName = (string) ($context['project']['name'] ?? 'Sem fornecedor');
    $navPortainerTab = strtolower(trim((string) ($_GET['tab'] ?? 'cadastro')));
    if (!in_array($navPortainerTab, ['dashboard', 'cadastro', 'services', 'volumes', 'networks', 'containers', 'stacks'], true)) {
        $navPortainerTab = 'cadastro';
    }
    if ($currentProvider !== null && function_exists('provider_label')) {
        $contextProviderLabel = provider_label($currentProvider);
    } elseif ($currentProvider !== null) {
        $contextProviderLabel = strtoupper($currentProvider);
    } else {
        $contextProviderLabel = 'Sem tipo';
    }
    ?>
    <div class="app-wrapper">
      <nav class="app-header navbar navbar-expand bg-body">
        <div class="container-fluid">
          <ul class="navbar-nav">
            <li class="nav-item">
              <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button">
                <i class="bi bi-list"></i>
              </a>
            </li>
          </ul>

          <form method="post" action="/switch_context.php" class="d-flex align-items-center gap-2 me-3 context-switch-form" id="context-switch-form">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/', ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="service_id" value="0">
            <select id="context-company-select" name="company_id" class="form-select form-select-sm context-switch-select">
              <?php if ($companies === []): ?>
                <option value="">Sem empresas</option>
              <?php else: ?>
                <?php foreach ($companies as $company): ?>
                  <option value="<?= (int) $company['id'] ?>" <?= (int) $company['id'] === (int) $companyId ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) $company['name'], ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
            <button type="submit" class="btn btn-sm theme-toggle-btn context-apply-btn">Aplicar contexto</button>
          </form>

          <div class="d-none d-xl-flex align-items-center gap-2 me-3 context-info-wrap">
            <div class="context-info-chip">
              <div class="small text-body-secondary lh-1">Identidade atual</div>
              <div class="small fw-semibold lh-1 mt-1">
                <?= htmlspecialchars($identityName, ENT_QUOTES, 'UTF-8') ?>
              </div>
              <div class="small text-body-secondary lh-1 mt-1">
                <?= htmlspecialchars($identityEmail, ENT_QUOTES, 'UTF-8') ?>
                <?php if ($isImpersonating && $operatorEmail !== ''): ?>
                  <span class="ms-1">(gestor: <?= htmlspecialchars($operatorEmail, ENT_QUOTES, 'UTF-8') ?>)</span>
                <?php endif; ?>
              </div>
              <div class="small <?= $isImpersonating ? 'text-warning-emphasis' : 'text-success-emphasis' ?> lh-1 mt-1">
                <?= htmlspecialchars($identityMode, ENT_QUOTES, 'UTF-8') ?>
              </div>
            </div>
            <div class="context-info-chip context-info-chip-wide">
              <div class="small text-body-secondary lh-1">Contexto operacional</div>
              <div class="small fw-semibold lh-1 mt-1 context-value-main">
                <?= htmlspecialchars($contextCompanyName, ENT_QUOTES, 'UTF-8') ?>
              </div>
              <div class="small text-body-secondary lh-1 mt-1 context-value-sub">
                <?= htmlspecialchars($contextServiceName, ENT_QUOTES, 'UTF-8') ?>
              </div>
              <div class="small text-info-emphasis lh-1 mt-1">
                <?= htmlspecialchars($contextProviderLabel, ENT_QUOTES, 'UTF-8') ?>
              </div>
            </div>
          </div>

          <ul class="navbar-nav ms-auto">
            <?php if ($isImpersonating): ?>
              <li class="nav-item d-flex align-items-center me-2">
                <span class="badge text-bg-warning">
                  Emulando: <?= htmlspecialchars((string) ($user['email'] ?? 'usuario'), ENT_QUOTES, 'UTF-8') ?>
                </span>
              </li>
              <li class="nav-item me-2">
                <form method="post" action="/impersonate.php" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="action" value="stop">
                  <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/', ENT_QUOTES, 'UTF-8') ?>">
                  <button type="submit" class="btn btn-sm btn-outline-warning">Parar emulacao</button>
                </form>
              </li>
            <?php endif; ?>
            <li class="nav-item d-flex align-items-center me-2">
              <button type="button" class="btn btn-sm theme-toggle-btn" data-theme-toggle>
                <i class="bi bi-sun-fill" data-theme-icon></i>
                <span class="ms-1 d-none d-md-inline" data-theme-label>Light</span>
              </button>
            </li>
            <li class="nav-item">
              <span class="nav-link">
                <?= htmlspecialchars((string) ($user['name'] ?? 'Usuario'), ENT_QUOTES, 'UTF-8') ?>
                <?php if ($isImpersonating && is_array($authUser)): ?>
                  <small class="text-body-secondary">(global: <?= htmlspecialchars((string) ($authUser['email'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>)</small>
                <?php endif; ?>
              </span>
            </li>
            <li class="nav-item">
              <form method="post" action="/logout.php" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn-link nav-link">Sair</button>
              </form>
            </li>
          </ul>
        </div>
      </nav>

      <aside class="app-sidebar bg-body-secondary shadow">
        <div class="sidebar-brand">
          <a href="/" class="brand-link">
            <span class="brand-text fw-light">OmniNOC</span>
          </a>
        </div>
        <div class="sidebar-wrapper">
          <nav class="mt-2">
            <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="menu">
              <li class="nav-header text-uppercase">Global</li>
              <?php if ($isPlatformOwner): ?>
                <li class="nav-item">
                  <a href="/projects.php" class="nav-link <?= $active === 'projects' ? 'active' : '' ?>">
                    <i class="nav-icon bi bi-buildings"></i>
                    <p>Empresas e Fornecedores</p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="/backup_storage.php" class="nav-link <?= $active === 'backup_storage' ? 'active' : '' ?>">
                    <i class="nav-icon bi bi-hdd-rack"></i>
                    <p>Storage Backup</p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="/impersonate.php" class="nav-link <?= $active === 'impersonate' ? 'active' : '' ?>">
                    <i class="nav-icon bi bi-person-bounding-box"></i>
                    <p>Emular Cliente</p>
                  </a>
                </li>
              <?php endif; ?>
              <li class="nav-header text-uppercase">Operacao</li>
              <?php if ($projects === []): ?>
                <li class="nav-item">
                  <span class="nav-link disabled">
                    <i class="nav-icon bi bi-info-circle"></i>
                    <p>Empresa sem fornecedores configurados</p>
                  </span>
                </li>
              <?php else: ?>
                <?php foreach ($providers as $providerKey => $provider): ?>
                  <?php if (!array_key_exists($providerKey, $enabledProviderTypesForCompany)): ?>
                    <?php continue; ?>
                  <?php endif; ?>
                  <?php
                  $providerMenuOpen = $currentProvider === $providerKey || $active === $providerKey;
                  $providerIsActive = $providerMenuOpen;
                  $providerContextReady = $hasServiceContext && $currentProvider === $providerKey;
                  $providerTargetProjectId = (int) ($providerDefaultProjectId[$providerKey] ?? 0);
                  $providerLinkClass = $providerIsActive ? 'active' : '';
                  $providerDashboardUrl = (string) ($provider['dashboard'] ?? '/');
                  $providerLandingUrl = (string) ($provider['landing'] ?? '/');
                  ?>
                  <li class="nav-item has-treeview <?= $providerMenuOpen ? 'menu-open' : '' ?>">
                    <a href="#" class="nav-link <?= $providerLinkClass ?>">
                      <i class="nav-icon bi <?= htmlspecialchars((string) $provider['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                      <p>
                        <?= htmlspecialchars((string) $provider['label'], ENT_QUOTES, 'UTF-8') ?>
                        <i class="nav-arrow bi bi-chevron-right"></i>
                      </p>
                    </a>
                    <ul class="nav nav-treeview js-sortable-submenu" data-submenu-group="provider-<?= htmlspecialchars($providerKey, ENT_QUOTES, 'UTF-8') ?>" data-company-id="<?= (int) $companyId ?>">
                      <?php if ($providerKey !== 'portainer'): ?>
                        <li class="nav-item">
                          <a
                            href="<?= $providerContextReady ? htmlspecialchars($providerDashboardUrl, ENT_QUOTES, 'UTF-8') : '#' ?>"
                            class="nav-link <?= $active === 'dashboard' && $currentProvider === $providerKey ? 'active' : '' ?><?= $providerContextReady || $providerTargetProjectId > 0 ? ' js-provider-switch' : ' disabled' ?>"
                            data-provider-target-id="<?= $providerTargetProjectId ?>"
                            data-provider-redirect-to="<?= htmlspecialchars($providerDashboardUrl, ENT_QUOTES, 'UTF-8') ?>"
                          >
                            <i class="nav-icon bi bi-speedometer2"></i>
                            <p>Dashboard</p>
                          </a>
                        </li>
                      <?php endif; ?>
                      <?php if ($providerKey !== 'portainer'): ?>
                        <li class="nav-item">
                          <a
                            href="<?= $providerContextReady ? htmlspecialchars($providerLandingUrl, ENT_QUOTES, 'UTF-8') : '#' ?>"
                            class="nav-link <?= in_array($active, [$providerKey], true) && $currentProvider === $providerKey ? 'active' : '' ?><?= $providerContextReady || $providerTargetProjectId > 0 ? ' js-provider-switch' : ' disabled' ?>"
                            data-provider-target-id="<?= $providerTargetProjectId ?>"
                            data-provider-redirect-to="<?= htmlspecialchars($providerLandingUrl, ENT_QUOTES, 'UTF-8') ?>"
                          >
                            <i class="nav-icon bi bi-key"></i>
                            <p><?= $providerKey === 'hetzner' ? 'Projetos' : 'Contas' ?></p>
                          </a>
                        </li>
                      <?php endif; ?>
                      <?php if ($providerKey === 'portainer'): ?>
                        <li class="nav-item">
                          <a
                            href="<?= $providerContextReady ? '/portainer.php?tab=dashboard' : '#' ?>"
                            class="nav-link <?= $active === 'portainer' && $navPortainerTab === 'dashboard' ? 'active' : '' ?><?= $providerContextReady || $providerTargetProjectId > 0 ? ' js-provider-switch' : ' disabled' ?>"
                            data-provider-target-id="<?= $providerTargetProjectId ?>"
                            data-provider-redirect-to="/portainer.php?tab=dashboard"
                          >
                            <i class="nav-icon bi bi-speedometer2"></i>
                            <p>Dashboard</p>
                          </a>
                        </li>
                        <li class="nav-item">
                          <a
                            href="<?= $providerContextReady ? '/portainer.php?tab=cadastro' : '#' ?>"
                            class="nav-link <?= $active === 'portainer' && $navPortainerTab === 'cadastro' ? 'active' : '' ?><?= $providerContextReady || $providerTargetProjectId > 0 ? ' js-provider-switch' : ' disabled' ?>"
                            data-provider-target-id="<?= $providerTargetProjectId ?>"
                            data-provider-redirect-to="/portainer.php?tab=cadastro"
                          >
                            <i class="nav-icon bi bi-key"></i>
                            <p>Cadastro</p>
                          </a>
                        </li>
                        <li class="nav-item">
                          <a
                            href="<?= $providerContextReady ? '/portainer.php?tab=services' : '#' ?>"
                            class="nav-link <?= $active === 'portainer' && $navPortainerTab === 'services' ? 'active' : '' ?><?= $providerContextReady || $providerTargetProjectId > 0 ? ' js-provider-switch' : ' disabled' ?>"
                            data-provider-target-id="<?= $providerTargetProjectId ?>"
                            data-provider-redirect-to="/portainer.php?tab=services"
                          >
                            <i class="nav-icon bi bi-wrench-adjustable"></i>
                            <p>Servicos</p>
                          </a>
                        </li>
                        <li class="nav-item">
                          <a
                            href="<?= $providerContextReady ? '/portainer.php?tab=volumes' : '#' ?>"
                            class="nav-link <?= $active === 'portainer' && $navPortainerTab === 'volumes' ? 'active' : '' ?><?= $providerContextReady || $providerTargetProjectId > 0 ? ' js-provider-switch' : ' disabled' ?>"
                            data-provider-target-id="<?= $providerTargetProjectId ?>"
                            data-provider-redirect-to="/portainer.php?tab=volumes"
                          >
                            <i class="nav-icon bi bi-hdd-stack"></i>
                            <p>Volumes</p>
                          </a>
                        </li>
                        <li class="nav-item">
                          <a
                            href="<?= $providerContextReady ? '/portainer.php?tab=networks' : '#' ?>"
                            class="nav-link <?= $active === 'portainer' && $navPortainerTab === 'networks' ? 'active' : '' ?><?= $providerContextReady || $providerTargetProjectId > 0 ? ' js-provider-switch' : ' disabled' ?>"
                            data-provider-target-id="<?= $providerTargetProjectId ?>"
                            data-provider-redirect-to="/portainer.php?tab=networks"
                          >
                            <i class="nav-icon bi bi-diagram-3"></i>
                            <p>Redes</p>
                          </a>
                        </li>
                        <li class="nav-item">
                          <a
                            href="<?= $providerContextReady ? '/portainer.php?tab=containers' : '#' ?>"
                            class="nav-link <?= $active === 'portainer' && $navPortainerTab === 'containers' ? 'active' : '' ?><?= $providerContextReady || $providerTargetProjectId > 0 ? ' js-provider-switch' : ' disabled' ?>"
                            data-provider-target-id="<?= $providerTargetProjectId ?>"
                            data-provider-redirect-to="/portainer.php?tab=containers"
                          >
                            <i class="nav-icon bi bi-box-seam"></i>
                            <p>Containers</p>
                          </a>
                        </li>
                        <li class="nav-item">
                          <a
                            href="<?= $providerContextReady ? '/portainer.php?tab=stacks' : '#' ?>"
                            class="nav-link <?= $active === 'portainer' && $navPortainerTab === 'stacks' ? 'active' : '' ?><?= $providerContextReady || $providerTargetProjectId > 0 ? ' js-provider-switch' : ' disabled' ?>"
                            data-provider-target-id="<?= $providerTargetProjectId ?>"
                            data-provider-redirect-to="/portainer.php?tab=stacks"
                          >
                            <i class="nav-icon bi bi-layers"></i>
                            <p>Stacks</p>
                          </a>
                        </li>
                      <?php endif; ?>
                      <?php if ($providerKey === 'hetzner' && $isPlatformOwner): ?>
                        <li class="nav-item">
                          <?php $providerTargetProjectId = (int) ($providerDefaultProjectId[$providerKey] ?? 0); ?>
                          <a
                            href="<?= $providerContextReady ? '/hetzner_operations.php' : '#' ?>"
                            class="nav-link <?= $active === 'hetzner_operations' ? 'active' : '' ?><?= $providerContextReady || $providerTargetProjectId > 0 ? ' js-provider-switch' : ' disabled' ?>"
                            data-provider-target-id="<?= $providerTargetProjectId ?>"
                            data-provider-redirect-to="/hetzner_operations.php"
                          >
                            <i class="nav-icon bi bi-diagram-2"></i>
                            <p>API Explorer</p>
                          </a>
                        </li>
                      <?php endif; ?>
                      <?php if ($providerKey === 'hetzner'): ?>
                        <li class="nav-item">
                          <?php $providerTargetProjectId = (int) ($providerDefaultProjectId[$providerKey] ?? 0); ?>
                          <a
                            href="<?= $providerContextReady ? '/hetzner_jobs.php' : '#' ?>"
                            class="nav-link <?= $active === 'hetzner_jobs' ? 'active' : '' ?><?= $providerContextReady || $providerTargetProjectId > 0 ? ' js-provider-switch' : ' disabled' ?>"
                            data-provider-target-id="<?= $providerTargetProjectId ?>"
                            data-provider-redirect-to="/hetzner_jobs.php"
                          >
                            <i class="nav-icon bi bi-clock-history"></i>
                            <p>Jobs</p>
                          </a>
                        </li>
                      <?php endif; ?>
                      <?php if ($providerKey !== 'portainer' && ui_capability_enabled($capabilities, 'servers')): ?>
                        <li class="nav-item">
                          <?php $providerTargetProjectId = (int) ($providerDefaultProjectId[$providerKey] ?? 0); ?>
                          <a
                            href="<?= $providerContextReady ? '/servers.php' : '#' ?>"
                            class="nav-link <?= $active === 'servers' ? 'active' : '' ?><?= $providerContextReady || $providerTargetProjectId > 0 ? ' js-provider-switch' : ' disabled' ?>"
                            data-provider-target-id="<?= $providerTargetProjectId ?>"
                            data-provider-redirect-to="/servers.php"
                          >
                            <i class="nav-icon bi bi-server"></i>
                            <p>Servidores</p>
                          </a>
                        </li>
                      <?php endif; ?>
                      <?php if ($isPlatformOwner && ui_capability_enabled($capabilities, 'apis')): ?>
                        <li class="nav-item">
                          <?php $providerTargetProjectId = (int) ($providerDefaultProjectId[$providerKey] ?? 0); ?>
                          <a
                            href="<?= $providerContextReady ? '/apis.php' : '#' ?>"
                            class="nav-link <?= $active === 'apis' ? 'active' : '' ?><?= $providerContextReady || $providerTargetProjectId > 0 ? ' js-provider-switch' : ' disabled' ?>"
                            data-provider-target-id="<?= $providerTargetProjectId ?>"
                            data-provider-redirect-to="/apis.php"
                          >
                            <i class="nav-icon bi bi-diagram-3"></i>
                            <p>APIs</p>
                          </a>
                        </li>
                      <?php endif; ?>
                      <?php if ($providerKey === 'cloudflare' && (ui_capability_enabled($capabilities, 'domains') || ui_capability_enabled($capabilities, 'cloudflare'))): ?>
                        <li class="nav-item">
                          <?php $providerTargetProjectId = (int) ($providerDefaultProjectId[$providerKey] ?? 0); ?>
                          <a
                            href="<?= $providerContextReady ? '/domains.php' : '#' ?>"
                            class="nav-link <?= $active === 'domains' ? 'active' : '' ?><?= $providerContextReady || $providerTargetProjectId > 0 ? ' js-provider-switch' : ' disabled' ?>"
                            data-provider-target-id="<?= $providerTargetProjectId ?>"
                            data-provider-redirect-to="/domains.php"
                          >
                            <i class="nav-icon bi bi-globe2"></i>
                            <p>Dominios</p>
                          </a>
                        </li>
                      <?php endif; ?>
                      <?php if ($providerKey !== 'portainer' && $isPlatformOwner && ui_capability_enabled($capabilities, 'observability')): ?>
                        <li class="nav-item">
                          <?php $providerTargetProjectId = (int) ($providerDefaultProjectId[$providerKey] ?? 0); ?>
                          <a
                            href="<?= $providerContextReady ? '/observability.php' : '#' ?>"
                            class="nav-link <?= $active === 'observability' ? 'active' : '' ?><?= $providerContextReady || $providerTargetProjectId > 0 ? ' js-provider-switch' : ' disabled' ?>"
                            data-provider-target-id="<?= $providerTargetProjectId ?>"
                            data-provider-redirect-to="/observability.php"
                          >
                            <i class="nav-icon bi bi-activity"></i>
                            <p>Observabilidade</p>
                          </a>
                        </li>
                      <?php endif; ?>
                      <?php if ($providerKey !== 'portainer' && ui_capability_enabled($capabilities, 'costs')): ?>
                        <li class="nav-item">
                          <?php $providerTargetProjectId = (int) ($providerDefaultProjectId[$providerKey] ?? 0); ?>
                          <a
                            href="<?= $providerContextReady ? '/costs.php' : '#' ?>"
                            class="nav-link <?= $active === 'costs' ? 'active' : '' ?><?= $providerContextReady || $providerTargetProjectId > 0 ? ' js-provider-switch' : ' disabled' ?>"
                            data-provider-target-id="<?= $providerTargetProjectId ?>"
                            data-provider-redirect-to="/costs.php"
                          >
                            <i class="nav-icon bi bi-currency-dollar"></i>
                            <p>Custos e Metas</p>
                          </a>
                        </li>
                      <?php endif; ?>
                      <?php if ($providerKey !== 'portainer' && ui_capability_enabled($capabilities, 'snapshots')): ?>
                        <li class="nav-item">
                          <?php $providerTargetProjectId = (int) ($providerDefaultProjectId[$providerKey] ?? 0); ?>
                          <a
                            href="<?= $providerContextReady ? '/snapshots.php' : '#' ?>"
                            class="nav-link <?= $active === 'snapshots' ? 'active' : '' ?><?= $providerContextReady || $providerTargetProjectId > 0 ? ' js-provider-switch' : ' disabled' ?>"
                            data-provider-target-id="<?= $providerTargetProjectId ?>"
                            data-provider-redirect-to="/snapshots.php"
                          >
                            <i class="nav-icon bi bi-camera-reels"></i>
                            <p>Snapshots</p>
                          </a>
                        </li>
                      <?php endif; ?>
                      <li class="nav-item">
                        <?php $providerTargetProjectId = (int) ($providerDefaultProjectId[$providerKey] ?? 0); ?>
                        <a
                          href="<?= $providerContextReady ? '/settings.php' : '#' ?>"
                          class="nav-link <?= $active === 'settings' ? 'active' : '' ?><?= $providerContextReady || $providerTargetProjectId > 0 ? ' js-provider-switch' : ' disabled' ?>"
                          data-provider-target-id="<?= $providerTargetProjectId ?>"
                          data-provider-redirect-to="/settings.php"
                        >
                          <i class="nav-icon bi bi-gear"></i>
                          <p>Config e Acesso</p>
                        </a>
                      </li>
                    </ul>
                  </li>
                <?php endforeach; ?>
              <?php endif; ?>
              <li class="nav-header text-uppercase">Documentacao</li>
              <li class="nav-item">
                <a href="/provider_docs.php" class="nav-link <?= $active === 'provider_docs' ? 'active' : '' ?>">
                  <i class="nav-icon bi bi-journal-code"></i>
                  <p>Docs Fornecedores</p>
                </a>
              </li>
            </ul>
          </nav>
        </div>
      </aside>

      <form id="provider-context-switch-form" method="post" action="/switch_context.php" class="d-none">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="company_id" value="<?= (int) $companyId ?>">
        <input type="hidden" name="service_id" value="0">
        <input type="hidden" name="redirect_to" value="/">
      </form>
      <script>
        (function () {
          var links = document.querySelectorAll('.js-provider-switch');
          var form = document.getElementById('provider-context-switch-form');
          if (!links || links.length === 0 || !form) {
            return;
          }
          var serviceField = form.querySelector('input[name="service_id"]');
          var redirectField = form.querySelector('input[name="redirect_to"]');
          if (!serviceField || !redirectField) {
            return;
          }

          links.forEach(function (link) {
            link.addEventListener('click', function (event) {
              var href = String(link.getAttribute('href') || '');
              if (href !== '#') {
                return;
              }
              var serviceId = parseInt(String(link.getAttribute('data-provider-target-id') || '0'), 10);
              var redirectTo = String(link.getAttribute('data-provider-redirect-to') || '/');
              if (!serviceId || serviceId <= 0) {
                return;
              }
              event.preventDefault();
              serviceField.value = String(serviceId);
              redirectField.value = redirectTo.startsWith('/') ? redirectTo : '/';
              form.submit();
            });
          });
        })();

        (function () {
          var lists = document.querySelectorAll('.js-sortable-submenu');
          if (!lists || lists.length === 0) {
            return;
          }

          var buildItemKey = function (item) {
            var link = item.querySelector('a.nav-link');
            if (!link) {
              return '';
            }
            var redirect = String(link.getAttribute('data-provider-redirect-to') || '').trim();
            if (redirect !== '') {
              return redirect;
            }
            var href = String(link.getAttribute('href') || '').trim();
            if (href !== '' && href !== '#') {
              return href;
            }
            return String(link.textContent || '').trim().toLowerCase();
          };

          lists.forEach(function (list) {
            var group = String(list.getAttribute('data-submenu-group') || '').trim();
            var companyId = String(list.getAttribute('data-company-id') || '0').trim();
            if (group === '') {
              return;
            }
            var storageKey = 'omninoc.submenu.order.' + companyId + '.' + group;
            var items = Array.prototype.slice.call(list.querySelectorAll(':scope > li.nav-item'));
            if (items.length < 2) {
              return;
            }

            var savedOrder = [];
            try {
              var raw = localStorage.getItem(storageKey);
              savedOrder = raw ? JSON.parse(raw) : [];
            } catch (error) {
              savedOrder = [];
            }

            if (Array.isArray(savedOrder) && savedOrder.length > 0) {
              var rank = {};
              savedOrder.forEach(function (key, index) {
                rank[String(key)] = index;
              });
              items.sort(function (a, b) {
                var keyA = buildItemKey(a);
                var keyB = buildItemKey(b);
                var idxA = Object.prototype.hasOwnProperty.call(rank, keyA) ? rank[keyA] : 9999;
                var idxB = Object.prototype.hasOwnProperty.call(rank, keyB) ? rank[keyB] : 9999;
                if (idxA === idxB) {
                  return 0;
                }
                return idxA < idxB ? -1 : 1;
              });
              items.forEach(function (item) { list.appendChild(item); });
            }

            var persistOrder = function () {
              var keys = Array.prototype.slice.call(list.querySelectorAll(':scope > li.nav-item')).map(buildItemKey).filter(function (key) {
                return key !== '';
              });
              try {
                localStorage.setItem(storageKey, JSON.stringify(keys));
              } catch (error) {}
            };

            var dragItem = null;
            Array.prototype.slice.call(list.querySelectorAll(':scope > li.nav-item')).forEach(function (item) {
              item.setAttribute('draggable', 'true');
              item.classList.add('submenu-draggable-item');
              item.addEventListener('dragstart', function (event) {
                dragItem = item;
                item.classList.add('is-dragging');
                if (event.dataTransfer) {
                  event.dataTransfer.effectAllowed = 'move';
                }
              });
              item.addEventListener('dragend', function () {
                item.classList.remove('is-dragging');
                dragItem = null;
                persistOrder();
              });
            });

            list.addEventListener('dragover', function (event) {
              if (!dragItem) {
                return;
              }
              event.preventDefault();
              var target = event.target.closest('li.nav-item');
              if (!target || target === dragItem || target.parentElement !== list) {
                return;
              }
              var rect = target.getBoundingClientRect();
              var before = event.clientY < rect.top + (rect.height / 2);
              if (before) {
                list.insertBefore(dragItem, target);
              } else {
                list.insertBefore(dragItem, target.nextSibling);
              }
            });
          });
        })();
      </script>

      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid pt-3">
            <?php if (is_array($flash)): ?>
              <div class="alert alert-<?= htmlspecialchars((string) ($flash['type'] ?? 'info'), ENT_QUOTES, 'UTF-8') ?>" role="alert">
                <?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
              </div>
            <?php endif; ?>
    <?php
}

function ui_page_end(): void
{
    ?>
          </div>
        </div>
      </main>
    </div>
    <script src="/vendor/adminlte/js/adminlte.min.js"></script>
    <script src="/assets/js/theme.js"></script>
  </body>
</html>
    <?php
}


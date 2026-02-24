<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/flash.php';
require_once __DIR__ . '/../app/hetzner.php';
require_once __DIR__ . '/../app/tenancy.php';
require_once __DIR__ . '/../app/ui.php';

require_auth();
$user = current_user();
if ($user === null) {
    redirect('/login.php');
}

$context = load_user_context((int) $user['id']);
$companyId = $context['company_id'] ?? null;
$projectId = $context['project_id'] ?? null;
$providerType = context_provider_type($context);
$flash = flash_pull();

$rows = [];
$summary = [
    'servers' => 0,
    'monthly_brl' => 0.0,
    'mtd_brl' => 0.0,
    'forecast_brl' => 0.0,
    'hourly_brl' => 0.0,
];
$byServer = [];
$byRegion = [];
$fxRates = fetch_brl_exchange_rates();

if (is_int($companyId) && is_int($projectId) && $providerType === 'hetzner') {
    $rows = list_project_servers($companyId, $projectId);
    foreach ($rows as $server) {
        if (!is_array($server)) {
            continue;
        }
        $estimate = hetzner_server_cost_estimate($server);
        $currency = (string) ($estimate['currency'] ?? 'EUR');

        $monthlyBrl = convert_amount_to_brl($estimate['monthly_gross'] ?? null, $currency, $fxRates);
        $mtdBrl = convert_amount_to_brl($estimate['mtd_gross'] ?? null, $currency, $fxRates);
        $forecastBrl = convert_amount_to_brl($estimate['forecast_month_gross'] ?? null, $currency, $fxRates);
        $hourlyBrl = convert_amount_to_brl($estimate['hourly_gross'] ?? null, $currency, $fxRates);
        if ($currency === 'BRL') {
            $monthlyBrl = $monthlyBrl ?? (is_numeric($estimate['monthly_gross'] ?? null) ? (float) $estimate['monthly_gross'] : null);
            $mtdBrl = $mtdBrl ?? (is_numeric($estimate['mtd_gross'] ?? null) ? (float) $estimate['mtd_gross'] : null);
            $forecastBrl = $forecastBrl ?? (is_numeric($estimate['forecast_month_gross'] ?? null) ? (float) $estimate['forecast_month_gross'] : null);
            $hourlyBrl = $hourlyBrl ?? (is_numeric($estimate['hourly_gross'] ?? null) ? (float) $estimate['hourly_gross'] : null);
        }

        $summary['servers']++;
        $summary['monthly_brl'] += $monthlyBrl ?? 0.0;
        $summary['mtd_brl'] += $mtdBrl ?? 0.0;
        $summary['forecast_brl'] += $forecastBrl ?? 0.0;
        $summary['hourly_brl'] += $hourlyBrl ?? 0.0;

        $serverName = trim((string) ($server['name'] ?? 'server'));
        if ($serverName === '') {
            $serverName = 'server';
        }
        $region = trim((string) ($server['datacenter'] ?? '-'));
        if ($region === '') {
            $region = '-';
        }

        $byServer[] = [
            'name' => $serverName,
            'monthly_brl' => round((float) ($monthlyBrl ?? 0.0), 2),
            'mtd_brl' => round((float) ($mtdBrl ?? 0.0), 2),
            'forecast_brl' => round((float) ($forecastBrl ?? 0.0), 2),
            'region' => $region,
        ];

        if (!array_key_exists($region, $byRegion)) {
            $byRegion[$region] = 0.0;
        }
        $byRegion[$region] += (float) ($monthlyBrl ?? 0.0);
    }
}

usort($byServer, static function (array $a, array $b): int {
    return ((float) ($b['monthly_brl'] ?? 0.0)) <=> ((float) ($a['monthly_brl'] ?? 0.0));
});

$chartServerNames = [];
$chartServerMonthly = [];
$chartServerMtd = [];
$chartServerForecast = [];
foreach ($byServer as $item) {
    $chartServerNames[] = (string) ($item['name'] ?? '-');
    $chartServerMonthly[] = (float) ($item['monthly_brl'] ?? 0.0);
    $chartServerMtd[] = (float) ($item['mtd_brl'] ?? 0.0);
    $chartServerForecast[] = (float) ($item['forecast_brl'] ?? 0.0);
}

$chartRegionData = [];
foreach ($byRegion as $region => $total) {
    $chartRegionData[] = ['name' => $region, 'value' => round((float) $total, 2)];
}

$daysInMonth = (int) date('t');
$currentDay = (int) date('j');
$dailyMean = $daysInMonth > 0 ? ((float) $summary['monthly_brl'] / $daysInMonth) : 0.0;
$lineDays = [];
$lineMtd = [];
$lineForecast = [];
$currentMtd = 0.0;
for ($d = 1; $d <= $daysInMonth; $d++) {
    $lineDays[] = (string) $d;
    $currentMtd += $dailyMean;
    $lineForecast[] = round($currentMtd, 2);
    $lineMtd[] = $d <= $currentDay ? round($currentMtd, 2) : null;
}

$fmtBrl = static function (float $value): string {
    return 'R$ ' . number_format($value, 2, ',', '.');
};

ui_page_start('OmniNOC | Custos e Metas');
ui_navigation('costs', $user, $context, $flash);
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h3 class="mb-1">Custos e Metas</h3>
    <small class="text-body-secondary">Consolidado financeiro por tenant/fornecedor.</small>
  </div>
</div>

<?php if (!is_int($companyId) || !is_int($projectId)): ?>
  <div class="alert alert-warning">Selecione empresa e fornecedor no topo para visualizar custos.</div>
<?php elseif ($providerType !== 'hetzner'): ?>
  <div class="alert alert-warning">Painel de custos detalhado disponivel neste momento para o provider Hetzner.</div>
<?php else: ?>
  <div class="row mb-3">
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card border-0" style="background: linear-gradient(135deg, rgba(0,191,255,0.18), rgba(0,191,255,0.06));">
        <div class="card-body">
          <small class="text-body-secondary">Mensal (BRL)</small>
          <h4 class="mb-0"><?= htmlspecialchars($fmtBrl((float) $summary['monthly_brl']), ENT_QUOTES, 'UTF-8') ?></h4>
        </div>
      </div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card border-0" style="background: linear-gradient(135deg, rgba(46,204,113,0.18), rgba(46,204,113,0.06));">
        <div class="card-body">
          <small class="text-body-secondary">MTD (BRL)</small>
          <h4 class="mb-0"><?= htmlspecialchars($fmtBrl((float) $summary['mtd_brl']), ENT_QUOTES, 'UTF-8') ?></h4>
        </div>
      </div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card border-0" style="background: linear-gradient(135deg, rgba(255,193,7,0.2), rgba(255,193,7,0.08));">
        <div class="card-body">
          <small class="text-body-secondary">Forecast mes (BRL)</small>
          <h4 class="mb-0"><?= htmlspecialchars($fmtBrl((float) $summary['forecast_brl']), ENT_QUOTES, 'UTF-8') ?></h4>
        </div>
      </div>
    </div>
    <div class="col-lg-3 col-sm-6 mb-3">
      <div class="card border-0" style="background: linear-gradient(135deg, rgba(255,99,132,0.2), rgba(255,99,132,0.08));">
        <div class="card-body">
          <small class="text-body-secondary">Servidores no consolidado</small>
          <h4 class="mb-0"><?= number_format((int) $summary['servers'], 0, ',', '.') ?></h4>
        </div>
      </div>
    </div>
  </div>

  <div class="row">
    <div class="col-xl-7 mb-3">
      <div class="card h-100">
        <div class="card-header"><strong>Custo por servidor (BRL)</strong></div>
        <div class="card-body">
          <div id="costs-server-chart" style="height: 340px;"></div>
        </div>
      </div>
    </div>
    <div class="col-xl-5 mb-3">
      <div class="card h-100">
        <div class="card-header"><strong>Distribuicao por regiao/DC</strong></div>
        <div class="card-body">
          <div id="costs-region-chart" style="height: 340px;"></div>
        </div>
      </div>
    </div>
  </div>

  <div class="row">
    <div class="col-12 mb-3">
      <div class="card">
        <div class="card-header"><strong>Curva MTD x Forecast (BRL)</strong></div>
        <div class="card-body">
          <div id="costs-trend-chart" style="height: 300px;"></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><strong>Tabela de consolidado por servidor</strong></div>
    <div class="card-body p-0">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Servidor</th>
            <th>Regiao/DC</th>
            <th>Mensal (BRL)</th>
            <th>MTD (BRL)</th>
            <th>Forecast (BRL)</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($byServer === []): ?>
            <tr><td colspan="5" class="text-center text-body-secondary py-3">Sem dados de custo no contexto atual.</td></tr>
          <?php else: ?>
            <?php foreach ($byServer as $item): ?>
              <tr>
                <td><?= htmlspecialchars((string) ($item['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($item['region'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($fmtBrl((float) ($item['monthly_brl'] ?? 0.0)), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($fmtBrl((float) ($item['mtd_brl'] ?? 0.0)), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($fmtBrl((float) ($item['forecast_brl'] ?? 0.0)), ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js"></script>
  <script>
  (function () {
    var serverNames = <?= json_encode($chartServerNames, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var serverMonthly = <?= json_encode($chartServerMonthly, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var serverMtd = <?= json_encode($chartServerMtd, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var serverForecast = <?= json_encode($chartServerForecast, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var regionData = <?= json_encode($chartRegionData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var lineDays = <?= json_encode($lineDays, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var lineMtd = <?= json_encode($lineMtd, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var lineForecast = <?= json_encode($lineForecast, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    var axisColor = '#a8b0c2';
    var gridLine = 'rgba(168,176,194,0.12)';
    var textColor = '#dbe2f2';

    var serverNode = document.getElementById('costs-server-chart');
    if (serverNode) {
      var serverChart = echarts.init(serverNode);
      serverChart.setOption({
        backgroundColor: 'transparent',
        tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
        legend: { textStyle: { color: textColor } },
        grid: { left: 8, right: 12, top: 36, bottom: 8, containLabel: true },
        xAxis: {
          type: 'value',
          axisLabel: { color: axisColor, formatter: function (v) { return 'R$ ' + Number(v).toLocaleString('pt-BR'); } },
          splitLine: { lineStyle: { color: gridLine } }
        },
        yAxis: {
          type: 'category',
          data: serverNames,
          axisLabel: { color: axisColor },
          axisLine: { lineStyle: { color: gridLine } }
        },
        series: [
          { name: 'Mensal', type: 'bar', data: serverMonthly, itemStyle: { color: '#00bcd4', borderRadius: [0, 6, 6, 0] } },
          { name: 'MTD', type: 'bar', data: serverMtd, itemStyle: { color: '#2ecc71', borderRadius: [0, 6, 6, 0] } },
          { name: 'Forecast', type: 'bar', data: serverForecast, itemStyle: { color: '#f5b700', borderRadius: [0, 6, 6, 0] } }
        ]
      });
      window.addEventListener('resize', function () { serverChart.resize(); });
    }

    var regionNode = document.getElementById('costs-region-chart');
    if (regionNode) {
      var regionChart = echarts.init(regionNode);
      regionChart.setOption({
        backgroundColor: 'transparent',
        tooltip: { trigger: 'item', formatter: '{b}<br/>R$ {c} ({d}%)' },
        legend: { bottom: 0, textStyle: { color: textColor } },
        series: [{
          name: 'Regiao',
          type: 'pie',
          radius: ['42%', '72%'],
          center: ['50%', '45%'],
          avoidLabelOverlap: true,
          itemStyle: { borderColor: '#171b23', borderWidth: 2 },
          label: { color: textColor, formatter: '{b}' },
          data: regionData
        }]
      });
      window.addEventListener('resize', function () { regionChart.resize(); });
    }

    var trendNode = document.getElementById('costs-trend-chart');
    if (trendNode) {
      var trendChart = echarts.init(trendNode);
      trendChart.setOption({
        backgroundColor: 'transparent',
        tooltip: { trigger: 'axis' },
        legend: { textStyle: { color: textColor } },
        grid: { left: 8, right: 12, top: 36, bottom: 8, containLabel: true },
        xAxis: {
          type: 'category',
          data: lineDays,
          axisLabel: { color: axisColor },
          axisLine: { lineStyle: { color: gridLine } }
        },
        yAxis: {
          type: 'value',
          axisLabel: { color: axisColor, formatter: function (v) { return 'R$ ' + Number(v).toLocaleString('pt-BR'); } },
          splitLine: { lineStyle: { color: gridLine } }
        },
        series: [
          {
            name: 'MTD',
            type: 'line',
            smooth: true,
            data: lineMtd,
            symbol: 'circle',
            symbolSize: 6,
            lineStyle: { width: 3, color: '#2ecc71' },
            itemStyle: { color: '#2ecc71' },
            areaStyle: { color: 'rgba(46,204,113,0.15)' }
          },
          {
            name: 'Forecast',
            type: 'line',
            smooth: true,
            data: lineForecast,
            symbol: 'none',
            lineStyle: { width: 2, type: 'dashed', color: '#f5b700' }
          }
        ]
      });
      window.addEventListener('resize', function () { trendChart.resize(); });
    }
  })();
  </script>
<?php endif; ?>
<?php
ui_page_end();


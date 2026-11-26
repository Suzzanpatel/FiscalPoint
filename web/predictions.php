<?php
/**
 * ML Predictions page.
 *
 * Shows, for the logged-in user:
 *   - spending forecast for this month and next month (gradient-boosted model, 80 % interval)
 *   - per-category forecast table
 *   - probability of exceeding this month's budget
 *   - unusual transactions flagged by the anomaly detector
 *   - how the model compares with simple baselines (rolling-origin backtest)
 * All numbers come from the Python ML service via includes/ml_client.php.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/ml_client.php';
$uid = require_login();

$forecast = ml_request('/v1/forecast', ['user_id' => $uid, 'horizon' => 2]);
$serviceOnline = $forecast['status'] !== 0;
$risk = $serviceOnline ? ml_request('/v1/budget-risk', ['user_id' => $uid]) : null;
$anomalies = $serviceOnline ? ml_request('/v1/anomalies', ['user_id' => $uid, 'months' => 3, 'limit' => 8]) : null;
$modelInfo = $serviceOnline ? ml_request('/v1/models') : null;

$fc = $forecast['ok'] ? $forecast['data'] : null;
$months = $fc['months'] ?? [];
$current = $months[0] ?? null;

// Chart data: last 12 months of actual totals + forecast with interval
$chartLabels = [];
$chartActual = [];
$chartForecast = [];
$chartLow = [];
$chartHigh = [];
foreach ($fc['history'] ?? [] as $point) {
    $chartLabels[] = date('M Y', strtotime($point['period'] . '-01'));
    $chartActual[] = $point['partial'] ? null : $point['total'];
    $chartForecast[] = null;
    $chartLow[] = null;
    $chartHigh[] = null;
}
foreach ($months as $i => $month) {
    $label = date('M Y', strtotime($month['period'] . '-01'));
    $index = array_search($label, $chartLabels, true);
    if ($index === false) {
        $chartLabels[] = $label;
        $chartActual[] = null;
        $index = count($chartLabels) - 1;
    }
    $chartForecast[$index] = $month['total']['expected'];
    $chartLow[$index] = $month['total']['low'];
    $chartHigh[$index] = $month['total']['high'];
}
// connect the forecast line to the last complete month
for ($i = count($chartActual) - 1; $i >= 0; $i--) {
    if ($chartActual[$i] !== null) {
        $chartForecast[$i] = $chartActual[$i];
        $chartLow[$i] = $chartActual[$i];
        $chartHigh[$i] = $chartActual[$i];
        break;
    }
}
$spentThisMonth = null;
foreach ($fc['history'] ?? [] as $point) {
    if ($point['partial']) {
        $spentThisMonth = $point['total'];
    }
}
$metrics = $modelInfo['data']['metrics']['forecasting'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ML Predictions - FiscalPoint</title>
    <link rel="stylesheet" href="<?= e(asset('css/predictions.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
    <script src="<?= e(asset('vendor/chartjs/chart.umd.min.js')) ?>"></script>
    <style>
        /* predictions.css centres .main-content vertically; with tall content that pushed the
           first cards above the top of the page where they could not be scrolled to. */
        .main-content { align-items: stretch; justify-content: flex-start; }
        .prediction-card { max-width: 1000px; margin-bottom: 18px; }
        .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin: 10px 0 4px; }
        .kpi { background: #f5f4fa; border-radius: 10px; padding: 12px 14px; }
        .kpi .label { font-size: 13px; color: #555; }
        .kpi .value { font-size: 22px; font-weight: 700; margin-top: 4px; }
        .kpi .sub { font-size: 12px; color: #666; margin-top: 2px; }
        .chart-box { height: 320px; position: relative; }
        table.ml { width: 100%; border-collapse: collapse; font-size: 14px; }
        table.ml th, table.ml td { padding: 7px 8px; border-bottom: 1px solid #e5e5e5; text-align: right; }
        table.ml th:first-child, table.ml td:first-child { text-align: left; }
        table.ml th { background: #f0eef7; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; color: #fff; font-size: 13px; font-weight: 600; }
        .bar { height: 10px; border-radius: 5px; background: #e9e7f1; overflow: hidden; }
        .bar span { display: block; height: 100%; }
        .muted { color: #666; font-size: 13px; }
        .warning { background: #fff6e0; border-left: 4px solid #e8a33a; padding: 8px 12px; border-radius: 6px; margin: 8px 0; color: #5a4300; }
        .tabs button { border: 1px solid #c9c4dd; background: #fff; padding: 6px 12px; border-radius: 6px; cursor: pointer; }
        .tabs button.active { background: #6B6487; color: #fff; border-color: #6B6487; }
    </style>
</head>
<body>
<header>
    <img src="<?= e(asset('img/logo.png')) ?>" alt="Logo" class="logo" onclick="location.href='<?= e(url('landing.html')) ?>'">
</header>

<?php include __DIR__ . '/includes/sidebar_user.php'; ?>

<div class="server-status <?= $serviceOnline ? 'server-online' : 'server-offline' ?>">
    <i class="fas <?= $serviceOnline ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
    ML service: <?= $serviceOnline ? 'Online' : 'Offline' ?>
</div>

<main class="main-content">

<?php if (!$serviceOnline): ?>
    <div class="prediction-card">
        <h2><i class="fas fa-brain"></i> ML Predictions</h2>
        <div class="error-message">
            <i class="fas fa-exclamation-triangle"></i> <?= e($forecast['error']) ?>
            <ol>
                <li>Install the Python dependencies: <code>pip install -r ml/requirements.txt</code></li>
                <li>Train the models (once): <code>cd ml &amp;&amp; python -m fiscalpoint_ml.train</code></li>
                <li>Start the service: <code>python -m fiscalpoint_ml.api</code></li>
                <li>Reload this page.</li>
            </ol>
        </div>
    </div>
<?php elseif (!$forecast['ok']): ?>
    <div class="prediction-card">
        <h2><i class="fas fa-brain"></i> ML Predictions</h2>
        <div class="error-message"><i class="fas fa-exclamation-triangle"></i> <?= e($forecast['error']) ?></div>
    </div>
<?php elseif (!$current): ?>
    <div class="prediction-card">
        <h2><i class="fas fa-brain"></i> ML Predictions</h2>
        <p><?= e($fc['message'] ?? 'Not enough data for a forecast yet.') ?></p>
        <p class="muted">Keep adding expenses: forecasts appear once you have at least one complete month of history.</p>
    </div>
<?php else: ?>

    <!-- Forecast overview -->
    <div class="prediction-card">
        <h2><i class="fas fa-chart-line"></i> Spending forecast</h2>
        <?php foreach ($fc['warnings'] as $warning): ?>
            <div class="warning"><?= e($warning) ?></div>
        <?php endforeach; ?>
        <div class="kpis">
            <?php foreach ($months as $i => $month): ?>
                <div class="kpi">
                    <div class="label"><?= $i === 0 ? 'Expected this month' : 'Expected next month' ?> (<?= e($month['label']) ?>)</div>
                    <div class="value"><?= e(money($month['total']['expected'])) ?></div>
                    <div class="sub">80% range <?= e(money($month['total']['low'])) ?> – <?= e(money($month['total']['high'])) ?></div>
                </div>
            <?php endforeach; ?>
            <?php if ($spentThisMonth !== null): ?>
                <div class="kpi">
                    <div class="label">Spent so far this month</div>
                    <div class="value"><?= e(money($spentThisMonth)) ?></div>
                    <div class="sub">as of <?= e(date('j M Y')) ?></div>
                </div>
            <?php endif; ?>
        </div>
        <div class="chart-box"><canvas id="forecastChart"></canvas></div>
        <p class="muted">Model: gradient-boosted trees trained on the spending history of all users, with lag, rolling-average,
            seasonality and trend features. The shaded band is an 80% prediction interval.</p>
    </div>

    <!-- Budget risk -->
    <div class="prediction-card">
        <h2><i class="fas fa-shield-alt"></i> Budget risk for <?= e(date('F Y')) ?></h2>
        <?php if ($risk && $risk['ok'] && isset($risk['data']['probability_over_budget'])):
            $r = $risk['data'];
            [$riskLabel, $riskColor] = risk_badge($r['risk_level']);
            $pct = (int) round($r['probability_over_budget'] * 100); ?>
            <p><span class="badge" style="background: <?= e($riskColor) ?>"><?= e($riskLabel) ?></span>
               &nbsp;<strong><?= $pct ?>%</strong> chance of exceeding your budget of <?= e(money($r['budget'])) ?>.</p>
            <div class="bar"><span style="width: <?= $pct ?>%; background: <?= e($riskColor) ?>"></span></div>
            <div class="kpis">
                <div class="kpi"><div class="label">Spent so far</div><div class="value"><?= e(money($r['spent_to_date'])) ?></div>
                    <div class="sub">day <?= (int) $r['days_elapsed'] ?> of <?= (int) $r['days_in_month'] ?></div></div>
                <div class="kpi"><div class="label">Projected month-end</div><div class="value"><?= e(money($r['projected_total'])) ?></div>
                    <div class="sub">range <?= e(money($r['projected_low'])) ?> – <?= e(money($r['projected_high'])) ?></div></div>
                <div class="kpi"><div class="label">Remaining budget</div><div class="value"><?= e(money($r['remaining_budget'])) ?></div>
                    <div class="sub">you usually spend <?= (int) round($r['typical_share_spent'] * 100) ?>% of a month's total by today</div></div>
            </div>
        <?php elseif ($risk && $risk['ok']): ?>
            <p><?= e($risk['data']['message'] ?? 'No budget set for this month.') ?>
               <a href="<?= e(url('setbudget.php')) ?>">Set a budget</a> to see your risk of overspending.</p>
        <?php else: ?>
            <p class="muted"><?= e($risk['error'] ?? 'Budget risk is unavailable.') ?></p>
        <?php endif; ?>
    </div>

    <!-- Category forecast -->
    <div class="prediction-card">
        <h2><i class="fas fa-layer-group"></i> Forecast by category</h2>
        <div class="tabs">
            <?php foreach ($months as $i => $month): ?>
                <button type="button" class="<?= $i === 0 ? 'active' : '' ?>" data-tab="m<?= $i ?>"><?= e($month['label']) ?></button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($months as $i => $month): ?>
            <table class="ml" id="m<?= $i ?>" style="<?= $i === 0 ? '' : 'display:none;' ?> margin-top:10px;">
                <tr><th>Category</th><th>Expected</th><th>80% range</th><th>Last month</th><th>3-month avg</th><th>Change</th></tr>
                <?php foreach ($month['categories'] as $c):
                    $base = $c['avg_3_months'] ?? 0;
                    $change = $base > 0 ? ($c['expected'] - $base) / $base * 100 : null; ?>
                    <tr>
                        <td><?= e($c['category']) ?></td>
                        <td><strong><?= e(money($c['expected'])) ?></strong></td>
                        <td><?= e(money($c['low'] < 10 ? 0 : $c['low'])) ?> – <?= e(money($c['high'])) ?></td>
                        <td><?= $c['last_month'] === null ? '–' : e(money($c['last_month'])) ?></td>
                        <td><?= $c['avg_3_months'] === null ? '–' : e(money($c['avg_3_months'])) ?></td>
                        <td style="color: <?= $change !== null && $change > 10 ? '#d9534f' : ($change !== null && $change < -10 ? '#2e9e6b' : '#333') ?>">
                            <?= $change === null ? '–' : e(sprintf('%+.0f%%', $change)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endforeach; ?>
    </div>

    <!-- Anomalies -->
    <div class="prediction-card">
        <h2><i class="fas fa-search-dollar"></i> Unusual transactions (last 3 months)</h2>
        <?php if ($anomalies && $anomalies['ok'] && !empty($anomalies['data']['anomalies'])): ?>
            <table class="ml">
                <tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Why it was flagged</th></tr>
                <?php foreach ($anomalies['data']['anomalies'] as $a): ?>
                    <tr>
                        <td><?= e(date('d-m-Y', strtotime($a['date']))) ?></td>
                        <td><?= e($a['category']) ?></td>
                        <td><?= e($a['description']) ?></td>
                        <td><?= e(money($a['amount'])) ?></td>
                        <td style="text-align:left;"><?= e($a['reason']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <p class="muted">Flagged by an Isolation Forest on how each amount compares with your own history in that category.
                Unusual does not mean wrong: it is a prompt to double-check.</p>
        <?php elseif ($anomalies && $anomalies['ok']): ?>
            <p>Nothing unusual found in your recent spending.</p>
        <?php else: ?>
            <p class="muted"><?= e($anomalies['error'] ?? 'Anomaly detection is unavailable.') ?></p>
        <?php endif; ?>
    </div>

    <!-- Model quality -->
    <?php if ($metrics): ?>
    <div class="prediction-card">
        <h2><i class="fas fa-flask"></i> How accurate is this?</h2>
        <p>The forecasting model is tested by <em>rolling-origin backtesting</em>: it is retrained on past months only and asked to
            forecast the following month, over the last <?= (int) ($metrics['n_origins'] ?? 6) ?> months.</p>
        <table class="ml">
            <tr><th>Method</th><th>Average error per category</th><th>Average error per monthly total</th></tr>
            <?php foreach ($metrics['category_level'] as $name => $m): ?>
                <tr<?= $name === $metrics['champion'] ? ' style="font-weight:700;"' : '' ?>>
                    <td><?= e(ucwords(str_replace('_', ' ', $name))) ?><?= $name === $metrics['champion'] ? ' (used)' : '' ?></td>
                    <td><?= e(money($m['mae'])) ?></td>
                    <td><?= e(money($metrics['user_total_level'][$name]['mae'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p class="muted">
            <?= e(sprintf('%.0f%% lower error than the best simple baseline (%s); the 80%% interval contained the actual value %.0f%% of the time.',
                $metrics['mae_improvement_vs_best_baseline'] * 100, str_replace('_', ' ', $metrics['best_baseline']), $metrics['interval_coverage_80'] * 100)) ?>
            Trained on <?= e($modelInfo['data']['data']['source'] ?? 'unknown data') ?>.
        </p>
    </div>
    <?php endif; ?>

<?php endif; ?>
</main>

<?php if ($current): ?>
<script>
    document.querySelectorAll('.tabs button').forEach(function (button) {
        button.addEventListener('click', function () {
            document.querySelectorAll('.tabs button').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('table.ml[id^="m"]').forEach(t => t.style.display = 'none');
            button.classList.add('active');
            document.getElementById(button.dataset.tab).style.display = '';
        });
    });

    const rupees = v => '₹' + Number(v).toLocaleString('en-IN', { maximumFractionDigits: 0 });
    new Chart(document.getElementById('forecastChart'), {
        type: 'line',
        data: {
            labels: <?= js($chartLabels) ?>,
            datasets: [
                { label: 'Forecast range (high)', data: <?= js($chartHigh) ?>, borderWidth: 0, pointRadius: 0,
                  backgroundColor: 'rgba(107, 100, 135, 0.18)', fill: '+1', spanGaps: true },
                { label: 'Forecast range (low)', data: <?= js($chartLow) ?>, borderWidth: 0, pointRadius: 0, fill: false, spanGaps: true },
                { label: 'Forecast', data: <?= js($chartForecast) ?>, borderColor: '#6B6487', borderDash: [6, 4],
                  pointRadius: 4, fill: false, spanGaps: true },
                { label: 'Actual monthly spending', data: <?= js($chartActual) ?>, borderColor: '#2a6fdb',
                  backgroundColor: '#2a6fdb', pointRadius: 3, fill: false }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { filter: item => !item.text.startsWith('Forecast range') } },
                tooltip: { callbacks: { label: ctx => ctx.parsed.y === null ? '' : ctx.dataset.label + ': ' + rupees(ctx.parsed.y) } }
            },
            scales: { y: { beginAtZero: true, ticks: { callback: v => rupees(v) } } }
        }
    });
</script>
<?php endif; ?>
</body>
</html>

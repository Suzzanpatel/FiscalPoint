<?php
/** Admin: status and evaluation metrics of the trained ML models. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ml_client.php';
$adminId = require_admin();

$health = ml_request('/health', null, 3);
$info = $health['ok'] ? ml_request('/v1/models', null, 5) : null;
$meta = ($info && $info['ok']) ? $info['data'] : null;
$fc = $meta['metrics']['forecasting'] ?? null;
$cat = $meta['metrics']['categorizer'] ?? null;
$an = $meta['metrics']['anomaly'] ?? null;
$br = $meta['metrics']['budget_risk'] ?? null;
$pct = static fn($v) => $v === null ? '–' : number_format($v * 100, 1) . '%';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ML Models - FiscalPoint Admin</title>
    <link rel="stylesheet" href="<?= e(asset('css/admin_registered_users.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
    <style>
        .ml-wrap { margin-left: 22%; margin-right: 3%; padding: 10px 0 40px; }
        .ml-card { background: #fff; color: #222; border-radius: 12px; padding: 16px 20px; margin-bottom: 16px; }
        .ml-card h2 { margin-top: 0; }
        .ml-card table { width: 100%; border-collapse: collapse; }
        .ml-card th, .ml-card td { border-bottom: 1px solid #e5e5e5; padding: 6px 8px; text-align: left; color: #222; background: none; }
        .ok { color: #2e9e6b; } .bad { color: #d9534f; }
        code { background: #f2f2f2; padding: 1px 5px; border-radius: 4px; }
    </style>
</head>
<body>
<header>
    <img src="<?= e(asset('img/logo.png')) ?>" alt="Logo" class="logo" onclick="location.href='<?= e(url('landing.html')) ?>'">
</header>
<?php include __DIR__ . '/../includes/sidebar_admin.php'; ?>

<div class="ml-wrap">
    <h1 style="text-align:center;">Machine-learning models</h1>

    <div class="ml-card">
        <h2>Service status</h2>
        <?php if (!$health['ok']): ?>
            <p class="bad"><i class="fas fa-times-circle"></i> <?= e($health['error']) ?></p>
        <?php else: ?>
            <p class="ok"><i class="fas fa-check-circle"></i> Online (version <?= e($health['data']['version']) ?>)</p>
            <table>
                <tr><th>Model</th><th>Loaded</th></tr>
                <?php foreach ($health['data']['models_loaded'] as $name => $loaded): ?>
                    <tr><td><?= e(str_replace('_', ' ', $name)) ?></td>
                        <td class="<?= $loaded ? 'ok' : 'bad' ?>"><?= $loaded ? 'yes' : 'no' ?></td></tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        <p>Retrain from the command line: <code>cd ml &amp;&amp; python -m fiscalpoint_ml.train --source db --augment-synthetic</code>,
            then restart the service. (Training is deliberately not triggerable from the web.)</p>
    </div>

    <?php if ($meta): ?>
    <div class="ml-card">
        <h2>Training run</h2>
        <table>
            <tr><th>Trained at</th><td><?= e($meta['trained_at']) ?></td></tr>
            <tr><th>Data</th><td><?= e($meta['data']['source']) ?> — <?= e(number_format($meta['data']['n_transactions'])) ?> transactions,
                    <?= (int) $meta['data']['n_users'] ?> users, <?= e($meta['data']['first_date']) ?> to <?= e($meta['data']['last_date']) ?></td></tr>
            <tr><th>Versions</th><td>package <?= e($meta['package_version']) ?>, scikit-learn <?= e($meta['sklearn_version']) ?></td></tr>
        </table>
    </div>

    <?php if ($fc): ?>
    <div class="ml-card">
        <h2>Spending forecast (rolling-origin backtest, <?= (int) $fc['n_origins'] ?> months)</h2>
        <table>
            <tr><th>Model</th><th>MAE per category</th><th>WAPE</th><th>MAE per monthly total</th></tr>
            <?php foreach ($fc['category_level'] as $name => $m): ?>
                <tr<?= $name === $fc['champion'] ? ' style="font-weight:700"' : '' ?>>
                    <td><?= e($name) ?></td><td><?= e(money($m['mae'])) ?></td><td><?= e($pct($m['wape'])) ?></td>
                    <td><?= e(money($fc['user_total_level'][$name]['mae'])) ?></td></tr>
            <?php endforeach; ?>
        </table>
        <p>Improvement over best baseline: <strong><?= e($pct($fc['mae_improvement_vs_best_baseline'])) ?></strong> ·
           80% interval coverage: <strong><?= e($pct($fc['interval_coverage_80'])) ?></strong></p>
    </div>
    <?php endif; ?>

    <?php if ($cat): ?>
    <div class="ml-card">
        <h2>Expense categorizer (hold-out set of <?= e(number_format($cat['n_test'])) ?>)</h2>
        <p>Accuracy <strong><?= e($pct($cat['accuracy'])) ?></strong> · macro-F1 <strong><?= e(number_format($cat['macro_f1'], 3)) ?></strong> ·
           top-3 accuracy <strong><?= e($pct($cat['top3_accuracy'])) ?></strong></p>
    </div>
    <?php endif; ?>

    <?php if ($an && !empty($an['evaluated'])): ?>
    <div class="ml-card">
        <h2>Anomaly detector (labelled synthetic anomalies)</h2>
        <table>
            <tr><th>Method</th><th>Precision</th><th>Recall</th><th>F1</th></tr>
            <tr style="font-weight:700"><td>Isolation Forest + ratio rule</td><td><?= e($pct($an['precision'])) ?></td><td><?= e($pct($an['recall'])) ?></td><td><?= e(number_format($an['f1'], 3)) ?></td></tr>
            <?php foreach ($an['baselines'] as $name => $b): ?>
                <tr><td><?= e($name) ?></td><td><?= e($pct($b['precision'])) ?></td><td><?= e($pct($b['recall'])) ?></td><td><?= e(number_format($b['f1'], 3)) ?></td></tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($br): ?>
    <div class="ml-card">
        <h2>Budget-overrun risk calibration</h2>
        <table>
            <tr><th>Checkpoint</th><th>Brier score</th><th>No-skill Brier</th><th>ROC-AUC</th></tr>
            <?php foreach ($br as $name => $entry): ?>
                <tr><td><?= e(str_replace('_', ' ', $name)) ?></td><td><?= e(number_format($entry['brier_score'], 3)) ?></td>
                    <td><?= e(number_format($entry['brier_score_no_skill'], 3)) ?></td><td><?= e(isset($entry['roc_auc']) ? number_format($entry['roc_auc'], 3) : '–') ?></td></tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>
    <?php elseif ($info && !$info['ok']): ?>
        <div class="ml-card"><p class="bad"><?= e($info['error']) ?></p></div>
    <?php endif; ?>
</div>
</body>
</html>

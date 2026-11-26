<?php
/** Sidebar navigation for regular users. Requires includes/bootstrap.php. */
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$nav = function (string $file, string $icon, string $label) use ($currentPage): string {
    $active = $currentPage === $file ? ' class="active"' : '';
    return '<li' . $active . '><a href="' . e(url($file)) . '"><i class="fas ' . $icon . '"></i> <strong>' . e($label) . '</strong></a></li>';
};
?>
<style>.sidebar { top: 60px; } /* anchor to the top so it stays put on long pages */ .sidebar .menu > li { margin-bottom: 1.15em; } .sidebar .menu > li.active > a { text-decoration: underline; }</style>
<aside class="sidebar">
    <div class="profile">
        <img src="<?= e(asset('img/profile.png')) ?>" alt="Profile Image" class="avatar">
    </div>
    <ul class="menu">
        <?= $nav('dashboard.php', 'fa-tachometer-alt', 'Dashboard') ?>
        <?= $nav('addincome.php', 'fa-wallet', 'Income') ?>
        <?= $nav('setbudget.php', 'fa-coins', 'Budget') ?>
        <?= $nav('addexpense.php', 'fa-plus-circle', 'Add Expense') ?>
        <li class="dropdown">
            <a href="#"><i class="fas fa-chart-bar"></i> <strong><em>Graph Reports:</em></strong></a>
            <ul>
                <li><a href="<?= e(url('linegraph.php')) ?>"><i class="fas fa-chart-line"></i> Line Graph Report</a></li>
                <li><a href="<?= e(url('piegraph.php')) ?>"><i class="fas fa-chart-pie"></i> Pie Graph Report</a></li>
            </ul>
        </li>
        <li class="dropdown">
            <a href="#"><i class="fas fa-table"></i> <strong><em>Tabular Reports:</em></strong></a>
            <ul>
                <li><a href="<?= e(url('tabularreport.php')) ?>"><i class="fas fa-list-alt"></i> All Expenses</a></li>
                <li><a href="<?= e(url('categorywisereport.php')) ?>"><i class="fas fa-layer-group"></i> Category-wise Expense</a></li>
            </ul>
        </li>
        <?= $nav('insights.php', 'fa-lightbulb', 'Insights') ?>
        <?= $nav('predictions.php', 'fa-brain', 'ML Predictions') ?>
        <?= $nav('profile.php', 'fa-user', 'Profile') ?>
        <?= $nav('query.php', 'fa-question-circle', 'Query') ?>
        <?= $nav('logout.php', 'fa-sign-out-alt', 'Logout') ?>
    </ul>
</aside>

<?php
/** Sidebar navigation for administrators. Requires includes/bootstrap.php. */
$adminLinks = [
    ['admin/admin_category.php', 'fa-tags', 'Category'],
    ['admin/admin_registered_users.php', 'fa-user-friends', 'Reg Users'],
    ['admin/admin_query.php', 'fa-question-circle', 'Query'],
    ['admin/admin_ml.php', 'fa-brain', 'ML Models'],
    ['admin/add_admin.php', 'fa-user-plus', 'Add Admin'],
    ['admin/manage_admin.php', 'fa-user-cog', 'Manage Admin'],
    ['admin/admin_profile.php', 'fa-id-card', 'Profile'],
    ['logout.php', 'fa-sign-out-alt', 'Logout'],
];
?>
<style>.sidebar { top: 60px; } /* anchor to the top so it stays put on long pages */ .sidebar .menu > li { margin-bottom: 1.15em; } .sidebar .menu > li.active > a { text-decoration: underline; }</style>
<aside class="sidebar">
    <div class="profile">
        <img src="<?= e(asset('img/profile.png')) ?>" alt="Admin Profile" class="avatar">
    </div>
    <ul class="menu">
        <?php foreach ($adminLinks as [$file, $icon, $label]): ?>
            <li><a href="<?= e(url($file)) ?>"><i class="fas <?= e($icon) ?>"></i> <strong><?= e($label) ?></strong></a></li>
        <?php endforeach; ?>
    </ul>
</aside>

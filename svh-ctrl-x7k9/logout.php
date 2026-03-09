<?php

require_once __DIR__ . '/../includes/admin.php';

if (admin_is_logged_in()) {
    admin_db()->logAdminAction('admin_logout', 'PHP admin panel logout', admin_ip());
}

admin_logout();
admin_flash('success', 'Сесію завершено.');
admin_redirect('login.php');


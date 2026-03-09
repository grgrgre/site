<?php

require_once __DIR__ . '/../includes/admin.php';

if (admin_is_logged_in()) {
    admin_redirect('index.php');
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();

    $email = admin_post_field('email', 120);
    $password = (string) ($_POST['password'] ?? '');

    if (admin_is_login_rate_limited()) {
        admin_db()->logAdminAction('admin_login_rate_limited', 'Blocked excessive PHP admin login attempts', admin_ip());
        $error = 'Забагато спроб входу. Спробуйте ще раз приблизно через 15 хвилин.';
    } elseif (admin_login($email, $password)) {
        admin_redirect('index.php');
    } else {
        admin_record_failed_login();
        admin_db()->logAdminAction('admin_login_failed', 'Failed PHP admin login attempt', admin_ip());
        $error = 'Невірний email або пароль адміністратора.';
    }
}

$configured = is_admin_password_configured();
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Вхід | SvityazHOME Admin</title>
  <meta name="robots" content="noindex,nofollow">
  <link rel="icon" type="image/png" href="/assets/images/favicon/favicon-32x32.png">
  <link rel="stylesheet" href="<?= admin_e(admin_asset_url('admin.css')) ?>">
</head>
<body class="admin-auth">
  <main class="admin-auth__layout">
    <section class="admin-auth__intro">
      <p class="admin-auth__eyebrow">Адмінпанель</p>
      <h1 class="admin-auth__title">SvityazHOME керування сайтом</h1>
      <p class="admin-auth__subtitle">Один вхід для контенту, номерів, фото, відгуків і заявок без редагування коду вручну.</p>

      <div class="admin-auth__list" aria-label="Можливості адмінки">
        <div class="admin-auth__list-item">
          <strong>Контент і SEO</strong>
          <span>Hero, блоки головної сторінки, FAQ, контакти та базові метадані.</span>
        </div>
        <div class="admin-auth__list-item">
          <strong>Номери та галерея</strong>
          <span>Ціни, описи, правила, фото й обкладинки в одному місці.</span>
        </div>
        <div class="admin-auth__list-item">
          <strong>Заявки та відгуки</strong>
          <span>Швидка модерація, зміна статусів і контроль поточних звернень гостей.</span>
        </div>
      </div>

      <div class="admin-auth__actions">
        <a class="admin-button--ghost" href="/" target="_blank" rel="noopener noreferrer">Відкрити сайт</a>
        <a class="admin-button--ghost" href="/booking/" target="_blank" rel="noopener noreferrer">Сторінка бронювання</a>
      </div>

      <p class="admin-auth__footer">Секретний шлях до адмінки краще додатково захистити на рівні хостингу або `.htaccess`, якщо хостинг це дозволяє.</p>
    </section>

    <section class="admin-auth__card" aria-labelledby="admin-login-title">
      <p class="admin-auth__eyebrow">Захищений вхід</p>
      <h2 class="admin-auth__card-title" id="admin-login-title">Увійти в панель</h2>
      <p class="admin-auth__card-text">Використовуйте email адміністратора та пароль, заданий у конфігурації сайту.</p>

      <?php if ($error !== ''): ?>
        <div class="admin-alert admin-alert--error" role="alert"><?= admin_e($error) ?></div>
      <?php endif; ?>

      <?php if (!$configured): ?>
        <div class="admin-alert admin-alert--error" role="alert">
          Пароль адміністратора ще не налаштований. Додайте `ADMIN_PASSWORD` у `.env` або створіть `storage/data/admin-auth.json`.
        </div>
      <?php endif; ?>

      <form class="admin-form" method="post" action="<?= admin_e(admin_url('login.php')) ?>">
        <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
        <div class="admin-field">
          <label for="email">Email адміністратора</label>
          <input id="email" name="email" type="email" value="<?= admin_e(get_admin_login_email()) ?>" autocomplete="username" inputmode="email" required autofocus>
          <p class="admin-hint">Типовий логін для цього сайту: <?= admin_e(get_admin_login_email()) ?></p>
        </div>
        <div class="admin-field">
          <label for="password">Пароль</label>
          <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <div class="admin-actions">
          <button class="admin-button" type="submit"<?= $configured ? '' : ' disabled' ?>>Увійти</button>
        </div>
      </form>
    </section>
  </main>
</body>
</html>

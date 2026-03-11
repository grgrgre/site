<?php
$redirectTarget = '/telegram-app-v4/';
$query = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
if ($query !== '') {
    $redirectTarget .= '?' . $query;
}
header('Location: ' . $redirectTarget, true, 302);
exit;

header_remove('X-Powered-By');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://telegram.org; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'; base-uri 'self'; frame-ancestors 'self' https://web.telegram.org https://*.telegram.org https://t.me;");
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>SvityazHOME Telegram App</title>
  <link rel="stylesheet" href="./app.css?v=20260311-3">
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
  <script src="./app.js?v=20260311-3" defer></script>
</head>
<body>
  <div class="app-shell">
    <header class="hero-card">
      <div>
        <p class="eyebrow">SvityazHOME</p>
        <h1>Заявки в Telegram</h1>
        <p class="hero-copy" id="viewerLabel">Підключення до Mini App...</p>
      </div>
      <button class="ghost-button" id="refreshButton" type="button">Оновити</button>
    </header>

    <section class="stats-grid" aria-label="Підсумки">
      <article class="stat-card">
        <span class="stat-label">Нові</span>
        <strong class="stat-value" id="newCount">0</strong>
      </article>
      <article class="stat-card">
        <span class="stat-label">Усього</span>
        <strong class="stat-value" id="totalCount">0</strong>
      </article>
    </section>

    <section class="toolbar">
      <label class="search-box">
        <span>Пошук</span>
        <input id="searchInput" type="search" placeholder="ID, ім'я, телефон або email">
      </label>
      <button class="soft-button" id="clearSearchButton" type="button">Очистити</button>
    </section>

    <main class="content-grid">
      <section class="panel list-panel">
        <div class="panel-head">
          <h2>Список заявок</h2>
          <p id="listHint">Оберіть заявку для перегляду.</p>
        </div>
        <div class="booking-list" id="bookingList" aria-live="polite"></div>
      </section>

      <section class="panel detail-panel">
        <div class="panel-head">
          <h2>Деталі</h2>
          <p id="detailHint">Деталі з'являться тут.</p>
        </div>
        <div class="detail-view" id="detailView" aria-live="polite"></div>
      </section>
    </main>
  </div>
</body>
</html>

<?php

require_once __DIR__ . '/../includes/admin.php';

admin_require_login();

$content = svh_read_site_content();
$reviews = admin_reviews_data();
$bookings = admin_booking_rows();
$recentActions = admin_db()->getAdminLog(8);
$roomFiles = glob(DATA_DIR . 'rooms/room-*.json') ?: [];

$pendingBookings = 0;
foreach ($bookings as $booking) {
    if (in_array((string) ($booking['status'] ?? ''), ['new', 'new_email_failed'], true)) {
        $pendingBookings++;
    }
}

$approvedReviews = count($reviews['approved']);
$pendingReviews = count($reviews['pending']);
$questionsCount = count($reviews['questions']);
$roomsCount = count($roomFiles);

admin_render_header('Огляд', 'index.php');
?>
<section class="admin-grid admin-grid--stats">
  <article class="admin-card admin-stat">
    <p class="admin-stat__value"><?= $roomsCount ?></p>
    <p class="admin-stat__label">Номерів у JSON</p>
  </article>
  <article class="admin-card admin-stat">
    <p class="admin-stat__value"><?= $approvedReviews ?></p>
    <p class="admin-stat__label">Опублікованих відгуків</p>
  </article>
  <article class="admin-card admin-stat">
    <p class="admin-stat__value"><?= $pendingReviews ?></p>
    <p class="admin-stat__label">Відгуків на модерації</p>
  </article>
  <article class="admin-card admin-stat">
    <p class="admin-stat__value"><?= $pendingBookings ?></p>
    <p class="admin-stat__label">Нових запитів бронювання</p>
  </article>
</section>

<section class="admin-panel admin-spaced">
  <div class="admin-panel__head">
    <div>
      <h2>З чим треба працювати зараз</h2>
      <p class="admin-panel__hint">Тут зібрано те, що найчастіше вимагає уваги без пошуку по всій адмінці.</p>
    </div>
  </div>
  <div class="admin-kpi-grid">
    <article class="admin-kpi">
      <p class="admin-kpi__label">Нові заявки</p>
      <p class="admin-kpi__value"><?= $pendingBookings ?></p>
      <p class="admin-hint">Заявки зі статусом `new` або `new_email_failed`.</p>
    </article>
    <article class="admin-kpi">
      <p class="admin-kpi__label">Модерація відгуків</p>
      <p class="admin-kpi__value"><?= $pendingReviews ?></p>
      <p class="admin-hint">Очікують публікації або редагування.</p>
    </article>
    <article class="admin-kpi">
      <p class="admin-kpi__label">Питання гостей</p>
      <p class="admin-kpi__value"><?= $questionsCount ?></p>
      <p class="admin-hint">Запити, які варто переглянути вручну.</p>
    </article>
  </div>
</section>

<section class="admin-grid admin-spaced">
  <article class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h2>Швидкі дії</h2>
        <p class="admin-panel__hint">Найпотрібніші сценарії редагування без зайвих переходів.</p>
      </div>
    </div>
    <div class="admin-card-grid">
      <a class="admin-card-link" href="<?= admin_e(admin_url('content.php')) ?>">
        <span class="admin-card-link__title">Головна та SEO</span>
        <span class="admin-card-link__text">Оновити hero, переваги, FAQ і базові мета-теги сторінок.</span>
      </a>
      <a class="admin-card-link" href="<?= admin_e(admin_url('files.php')) ?>">
        <span class="admin-card-link__title">Файли + AI</span>
        <span class="admin-card-link__text">Відкрити HTML/CSS/JS, швидко переписати файл через AI і оновити зображення.</span>
      </a>
      <a class="admin-card-link" href="<?= admin_e(admin_url('rooms.php')) ?>">
        <span class="admin-card-link__title">Номери</span>
        <span class="admin-card-link__text">Змінити місткість, ціну, описи, правила та зображення номерів.</span>
      </a>
      <a class="admin-card-link" href="<?= admin_e(admin_url('requests.php')) ?>">
        <span class="admin-card-link__title">Запити</span>
        <span class="admin-card-link__text">Переглянути контакти гостей і швидко змінити статус бронювання.</span>
      </a>
      <a class="admin-card-link" href="<?= admin_e(admin_url('reviews.php')) ?>">
        <span class="admin-card-link__title">Відгуки</span>
        <span class="admin-card-link__text">Модерувати публікацію, редагувати тексти та тримати порядок у відгуках.</span>
      </a>
      <a class="admin-card-link" href="<?= admin_e(admin_url('gallery.php')) ?>">
        <span class="admin-card-link__title">Галерея</span>
        <span class="admin-card-link__text">Додавати фото, alt-тексти та категорії без втручання в код.</span>
      </a>
      <a class="admin-card-link" href="<?= admin_e(admin_url('contacts.php')) ?>">
        <span class="admin-card-link__title">Контакти</span>
        <span class="admin-card-link__text">Оновити телефон, email, адресу, мапу та соцмережі сайту.</span>
      </a>
    </div>
  </article>

  <article class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h2>Поточний стан сайту</h2>
        <p class="admin-panel__hint">Швидка довідка по публічному контенту без відкриття окремих сторінок.</p>
      </div>
    </div>
    <dl class="admin-detail-list">
      <div>
        <dt>Останнє оновлення контенту</dt>
        <dd><?= admin_e((string) ($content['updated_at'] ?? 'ще не збережено')) ?></dd>
      </div>
      <div>
        <dt>Телефон на сайті</dt>
        <dd><?= admin_e((string) ($content['contacts']['phone_label'] ?? '')) ?></dd>
      </div>
      <div>
        <dt>Головний CTA</dt>
        <dd><?= admin_e((string) ($content['home']['hero']['primary_cta_text'] ?? '')) ?></dd>
      </div>
      <div>
        <dt>Опублікованих відгуків</dt>
        <dd><?= $approvedReviews ?></dd>
      </div>
    </dl>
    <div class="admin-actions">
      <a class="admin-button" href="/">Відкрити сайт</a>
      <a class="admin-button--ghost" href="<?= admin_e(admin_url('content.php')) ?>">Редагувати контент</a>
    </div>
  </article>
</section>

<section class="admin-grid admin-grid--split admin-spaced">
  <article class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h2>Останні запити</h2>
        <p class="admin-panel__hint">Останні бронювання, які надійшли із форми.</p>
      </div>
      <a class="admin-button--ghost" href="<?= admin_e(admin_url('requests.php')) ?>">Усі запити</a>
    </div>
    <div class="admin-request-list">
      <?php foreach (array_slice($bookings, 0, 4) as $booking): ?>
        <?php $statusMeta = admin_booking_status_meta((string) ($booking['status'] ?? 'new')); ?>
        <article class="admin-request-card">
          <div class="admin-request-card__head">
            <h3 class="admin-request-card__title"><?= admin_e((string) $booking['name']) ?></h3>
            <span class="admin-badge admin-badge--<?= admin_e((string) $statusMeta['tone']) ?>"><?= admin_e((string) $statusMeta['label']) ?></span>
          </div>
          <div class="admin-request-card__meta">
            <span><?= admin_e((string) $booking['booking_id']) ?></span>
            <span><?= admin_e((string) $booking['phone']) ?></span>
            <span><?= admin_e((string) $booking['room_code']) ?></span>
            <span><?= admin_e((string) $booking['checkin_date']) ?> → <?= admin_e((string) $booking['checkout_date']) ?></span>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if (empty($bookings)): ?>
        <div class="admin-empty">Нових заявок поки немає.</div>
      <?php endif; ?>
    </div>
  </article>

  <article class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h2>Останні дії адміністратора</h2>
        <p class="admin-panel__hint">Лог основних змін і службових подій.</p>
      </div>
    </div>
    <div class="admin-list">
      <?php foreach ($recentActions as $action): ?>
        <div class="admin-list-item">
          <div class="admin-list-item__meta">
            <span class="admin-badge"><?= admin_e((string) $action['action']) ?></span>
            <span><?= admin_e((string) $action['created_at']) ?></span>
          </div>
          <p><?= admin_e((string) ($action['details'] ?? '')) ?></p>
        </div>
      <?php endforeach; ?>
      <?php if (empty($recentActions)): ?>
        <div class="admin-empty">Історія дій поки порожня.</div>
      <?php endif; ?>
    </div>
  </article>
</section>
<?php
admin_render_footer();

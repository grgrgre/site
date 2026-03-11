<?php

require_once __DIR__ . '/../includes/admin.php';

admin_require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();

    $id = (int) ($_POST['id'] ?? 0);
    $status = admin_post_field('status', 32);
    $allowed = ['new', 'waiting', 'confirmed', 'rejected', 'cancelled', 'done', 'new_email_failed', 'spam_honeypot'];

    if ($id > 0 && in_array($status, $allowed, true)) {
        $stmt = admin_pdo()->prepare('UPDATE bookings SET status = :status WHERE id = :id');
        $stmt->execute([
            ':status' => $status,
            ':id' => $id,
        ]);
        admin_db()->logAdminAction('booking_status', 'Updated booking #' . $id . ' to ' . $status, admin_ip());
        admin_flash('success', 'Статус заявки оновлено.');
    }

    admin_redirect('requests.php');
}

$bookings = admin_booking_rows();
$statusOptions = admin_booking_status_options();
$newCount = 0;
$confirmedCount = 0;
$closedCount = 0;

foreach ($bookings as $booking) {
    $status = (string) ($booking['status'] ?? '');
    if (in_array($status, ['new', 'new_email_failed', 'waiting'], true)) {
        $newCount++;
    } elseif ($status === 'confirmed') {
        $confirmedCount++;
    } elseif (in_array($status, ['rejected', 'cancelled', 'done', 'spam_honeypot'], true)) {
        $closedCount++;
    }
}

admin_render_header('Запити бронювання', 'requests.php');
?>
<section class="admin-panel">
  <div class="admin-panel__head">
    <div>
      <h2>Поточний потік заявок</h2>
      <p class="admin-panel__hint">Картки нижче зручніші за стару таблицю: одразу видно контакт, дати, повідомлення і потрібну дію.</p>
    </div>
  </div>
  <div class="admin-kpi-grid">
    <article class="admin-kpi">
      <p class="admin-kpi__label">Потребують відповіді</p>
      <p class="admin-kpi__value"><?= $newCount ?></p>
    </article>
    <article class="admin-kpi">
      <p class="admin-kpi__label">Підтверджені</p>
      <p class="admin-kpi__value"><?= $confirmedCount ?></p>
    </article>
    <article class="admin-kpi">
      <p class="admin-kpi__label">Закриті / архів</p>
      <p class="admin-kpi__value"><?= $closedCount ?></p>
    </article>
  </div>
</section>

<section class="admin-panel admin-spaced">
  <div class="admin-panel__head">
    <div>
      <h2>Усі заявки</h2>
      <p class="admin-panel__hint">Оновлення статусу відбувається тут же в картці.</p>
    </div>
  </div>
  <div class="admin-request-list">
    <?php foreach ($bookings as $booking): ?>
      <?php $statusMeta = admin_booking_status_meta((string) ($booking['status'] ?? 'new')); ?>
      <article class="admin-request-card" id="booking-<?= admin_e((string) ($booking['booking_id'] ?? '')) ?>">
        <div class="admin-request-card__head">
          <div>
            <h3 class="admin-request-card__title"><?= admin_e((string) ($booking['name'] ?? '')) ?></h3>
            <div class="admin-request-card__meta">
              <span><?= admin_e((string) ($booking['booking_id'] ?? '')) ?></span>
              <span><?= admin_e((string) ($booking['created_at'] ?? '')) ?></span>
            </div>
          </div>
          <span class="admin-badge admin-badge--<?= admin_e((string) $statusMeta['tone']) ?>"><?= admin_e((string) $statusMeta['label']) ?></span>
        </div>

        <div class="admin-request-card__meta">
          <span><strong><?= admin_e((string) ($booking['phone'] ?? '')) ?></strong></span>
          <span><?= admin_e((string) ($booking['email'] ?? 'email не вказано')) ?></span>
          <span><?= admin_e((string) ($booking['room_code'] ?? '')) ?></span>
          <span><?= admin_e((string) ($booking['guests'] ?? '')) ?> гостей</span>
          <span><?= admin_e((string) ($booking['checkin_date'] ?? '')) ?> → <?= admin_e((string) ($booking['checkout_date'] ?? '')) ?></span>
        </div>

        <div class="admin-request-card__message">
          <?= admin_e((string) ($booking['message'] ?? 'Без повідомлення від гостя.')) ?>
        </div>

        <div class="admin-request-card__actions">
          <form class="admin-inline-form" method="post" action="<?= admin_e(admin_url('requests.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= admin_e((string) ($booking['id'] ?? 0)) ?>">
            <select name="status">
              <?php foreach ($statusOptions as $status => $label): ?>
                <option value="<?= admin_e($status) ?>"<?= (($booking['status'] ?? '') === $status) ? ' selected' : '' ?>><?= admin_e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="admin-button" type="submit">Зберегти статус</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
    <?php if (empty($bookings)): ?>
      <div class="admin-empty">Заявок поки що немає.</div>
    <?php endif; ?>
  </div>
</section>
<?php
admin_render_footer();

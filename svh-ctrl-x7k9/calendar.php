<?php

require_once __DIR__ . '/../includes/admin.php';

admin_require_login();

$pdo = admin_pdo();
$today = date('Y-m-d');
$fromDate = sanitize_text_field((string) ($_GET['from'] ?? $today), 10);
if (!svh_is_iso_date($fromDate)) {
    $fromDate = $today;
}

$days = (int) ($_GET['days'] ?? 7);
if (!in_array($days, [7, 14, 30], true)) {
    $days = 7;
}

$roomFilter = svh_normalize_room_code((string) ($_GET['room'] ?? '')) ?? '';
$roomOptions = svh_room_catalog();

$buildRedirect = static function () use ($fromDate, $days, $roomFilter): string {
    $params = [
        'from' => $fromDate,
        'days' => $days,
    ];
    if ($roomFilter !== '') {
        $params['room'] = $roomFilter;
    }

    return 'calendar.php?' . http_build_query($params);
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();

    $action = admin_post_field('action', 32);
    if ($action === 'add_block') {
        $roomCode = svh_normalize_room_code(admin_post_field('room_code', 20)) ?? '';
        $startDate = admin_post_field('start_date', 10);
        $endDate = admin_post_field('end_date', 10);
        $eventType = admin_post_field('event_type', 32);
        $title = admin_post_field('title', 120);
        $note = admin_post_multiline('note', 1000);

        if ($roomCode === '' || !isset($roomOptions[$roomCode])) {
            admin_flash('error', 'Оберіть коректний номер.');
        } elseif (!svh_is_iso_date($startDate) || !svh_is_iso_date($endDate) || $endDate <= $startDate) {
            admin_flash('error', 'Вкажіть коректний діапазон дат для блокування.');
        } elseif (!in_array($eventType, svh_calendar_blocking_event_types(), true)) {
            admin_flash('error', 'Некоректний тип блокування.');
        } else {
            $conflicts = svh_find_room_conflicts($pdo, $roomCode, $startDate, $endDate);
            if (($conflicts['has_conflict'] ?? false) === true) {
                admin_flash('error', 'На цей діапазон уже є активне бронювання або блокування.');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO calendar_events (room_code, start_date, end_date, event_type, title, note, status)
                    VALUES (:room_code, :start_date, :end_date, :event_type, :title, :note, 'active')
                ");
                $stmt->execute([
                    ':room_code' => $roomCode,
                    ':start_date' => $startDate,
                    ':end_date' => $endDate,
                    ':event_type' => $eventType,
                    ':title' => $title !== '' ? $title : ($eventType === 'maintenance' ? 'Технічна пауза' : 'Ручний блок'),
                    ':note' => $note,
                ]);
                admin_db()->logAdminAction('calendar_block_add', $roomCode . ' ' . $startDate . ' → ' . $endDate, admin_ip());
                admin_flash('success', 'Блокування номера створено.');
            }
        }
    } elseif ($action === 'cancel_block') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE calendar_events SET status = 'cancelled' WHERE id = :id");
            $stmt->execute([':id' => $id]);
            admin_db()->logAdminAction('calendar_block_cancel', 'event_id=' . $id, admin_ip());
            admin_flash('success', 'Блокування знято.');
        }
    }

    admin_redirect($buildRedirect());
}

$calendar = svh_calendar_build_matrix($pdo, $fromDate, $days, $roomFilter !== '' ? $roomFilter : null);
$todaySummary = svh_day_occupancy_summary($pdo, $today);
$tomorrowSummary = svh_day_occupancy_summary($pdo, svh_date_add_days($today, 1));
$activeBlocks = svh_fetch_calendar_events_for_range(
    $pdo,
    $fromDate,
    $calendar['to_date'] ?? svh_date_add_days($fromDate, $days),
    $roomFilter !== '' ? [$roomFilter] : []
);

$calendarCounts = [
    'confirmed' => 0,
    'waiting' => 0,
    'blocked' => 0,
    'free' => 0,
];

foreach (($calendar['matrix'] ?? []) as $roomRow) {
    foreach (($roomRow['days'] ?? []) as $cell) {
        $status = strtolower(trim((string) ($cell['status'] ?? 'free')));
        if (!array_key_exists($status, $calendarCounts)) {
            $status = 'free';
        }
        $calendarCounts[$status]++;
    }
}

$formatDayLabel = static function (string $date): string {
    $ts = strtotime($date);
    return $ts === false ? $date : date('d.m', $ts);
};

$formatCellTitle = static function (array $item): string {
    if (($item['type'] ?? '') === 'event') {
        return trim((string) ($item['title'] ?? '')) ?: 'Блок';
    }

    $name = trim((string) ($item['title'] ?? '')) ?: 'Гість';
    $guests = (int) ($item['guests'] ?? 0);
    if ($guests > 0) {
        return $name . ' · ' . $guests . ' гост.';
    }
    return $name;
};

admin_render_header('Календар', 'calendar.php');
?>
<section class="admin-panel">
  <div class="admin-panel__head">
    <div>
      <h2>Календар по номерах і датах</h2>
      <p class="admin-panel__hint">У сітці блокуються тільки `waiting`, `confirmed` і ручні блоки. `new` не займає номер.</p>
    </div>
  </div>
  <form class="admin-form admin-calendar-filters" method="get" action="<?= admin_e(admin_url('calendar.php')) ?>">
    <label>Початок
      <input class="input" type="date" name="from" value="<?= admin_e($fromDate) ?>">
    </label>
    <label>Період
      <select class="input" name="days">
        <?php foreach ([7 => '7 днів', 14 => '14 днів', 30 => '30 днів'] as $value => $label): ?>
          <option value="<?= admin_e((string) $value) ?>"<?= $days === $value ? ' selected' : '' ?>><?= admin_e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Номер
      <select class="input" name="room">
        <option value="">Усі номери</option>
        <?php foreach ($roomOptions as $roomCode => $room): ?>
          <option value="<?= admin_e($roomCode) ?>"<?= $roomFilter === $roomCode ? ' selected' : '' ?>><?= admin_e((string) ($room['title'] ?? $roomCode)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="admin-actions">
      <button class="admin-button" type="submit">Показати</button>
      <a class="admin-button--ghost" href="<?= admin_e(admin_url('calendar.php')) ?>">Скинути</a>
    </div>
  </form>
  <div class="admin-calendar-legend">
    <span class="admin-calendar-pill is-free">Вільно</span>
    <span class="admin-calendar-pill is-waiting">Очікує</span>
    <span class="admin-calendar-pill is-confirmed">Зайнято</span>
    <span class="admin-calendar-pill is-blocked">Блок</span>
  </div>
  <div class="admin-kpi-grid">
    <article class="admin-kpi"><p class="admin-kpi__label">Клітинок вільно</p><p class="admin-kpi__value"><?= (int) $calendarCounts['free'] ?></p></article>
    <article class="admin-kpi"><p class="admin-kpi__label">Очікує</p><p class="admin-kpi__value"><?= (int) $calendarCounts['waiting'] ?></p></article>
    <article class="admin-kpi"><p class="admin-kpi__label">Підтверджено</p><p class="admin-kpi__value"><?= (int) $calendarCounts['confirmed'] ?></p></article>
    <article class="admin-kpi"><p class="admin-kpi__label">Заблоковано</p><p class="admin-kpi__value"><?= (int) $calendarCounts['blocked'] ?></p></article>
  </div>
</section>

<section class="admin-grid admin-grid--split admin-spaced">
  <article class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h2>Оперативно</h2>
        <p class="admin-panel__hint">Швидкий зріз на сьогодні і завтра.</p>
      </div>
    </div>
    <div class="admin-list">
      <div class="admin-list-item">
        <div class="admin-list-item__meta"><span class="admin-badge">Сьогодні</span><span><?= admin_e($todaySummary['date'] ?? $today) ?></span></div>
        <p>Заїзди: <?= count($todaySummary['arrivals'] ?? []) ?> · Виїзди: <?= count($todaySummary['departures'] ?? []) ?></p>
      </div>
      <div class="admin-list-item">
        <div class="admin-list-item__meta"><span class="admin-badge">Завтра</span><span><?= admin_e($tomorrowSummary['date'] ?? svh_date_add_days($today, 1)) ?></span></div>
        <p>Заїзди: <?= count($tomorrowSummary['arrivals'] ?? []) ?> · Виїзди: <?= count($tomorrowSummary['departures'] ?? []) ?></p>
      </div>
    </div>
  </article>

  <article class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h2>Ручне блокування</h2>
        <p class="admin-panel__hint">Для ремонту, технічної паузи або внутрішнього резерву.</p>
      </div>
    </div>
    <form class="admin-form admin-form__grid" method="post" action="<?= admin_e(admin_url($buildRedirect())) ?>">
      <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="add_block">
      <label>Номер
        <select class="input" name="room_code" required>
          <option value="">Оберіть номер</option>
          <?php foreach ($roomOptions as $roomCode => $room): ?>
            <option value="<?= admin_e($roomCode) ?>"><?= admin_e((string) ($room['title'] ?? $roomCode)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Початок
        <input class="input" type="date" name="start_date" value="<?= admin_e($fromDate) ?>" required>
      </label>
      <label>Кінець
        <input class="input" type="date" name="end_date" value="<?= admin_e(svh_date_add_days($fromDate, 1)) ?>" required>
      </label>
      <label>Тип
        <select class="input" name="event_type" required>
          <option value="manual_block">Ручний блок</option>
          <option value="maintenance">Технічна пауза</option>
        </select>
      </label>
      <label>Назва
        <input class="input" type="text" name="title" maxlength="120" placeholder="Наприклад: ремонт">
      </label>
      <label>Нотатка
        <textarea class="input" name="note" maxlength="1000" placeholder="Необов’язково"></textarea>
      </label>
      <div class="admin-actions">
        <button class="admin-button" type="submit">Заблокувати</button>
      </div>
    </form>
  </article>
</section>

<section class="admin-panel admin-spaced">
  <div class="admin-panel__head">
    <div>
      <h2>Сітка зайнятості</h2>
      <p class="admin-panel__hint">Клік по клітинці бронювання веде до списку заявок.</p>
    </div>
  </div>
  <div class="admin-calendar-wrap">
    <table class="admin-calendar-table">
      <thead>
        <tr>
          <th>Номер</th>
          <?php foreach (($calendar['dates'] ?? []) as $date): ?>
            <th><?= admin_e($formatDayLabel($date)) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach (($calendar['matrix'] ?? []) as $roomCode => $row): ?>
          <tr>
            <th><?= admin_e((string) (($row['room']['title'] ?? $roomCode))) ?></th>
            <?php foreach (($row['days'] ?? []) as $date => $cell): ?>
              <?php
              $status = strtolower(trim((string) ($cell['status'] ?? 'free')));
              $items = is_array($cell['items'] ?? null) ? $cell['items'] : [];
              $firstItem = $items[0] ?? null;
              $bookingLink = null;
              if (is_array($firstItem) && ($firstItem['type'] ?? '') === 'booking' && trim((string) ($firstItem['booking_id'] ?? '')) !== '') {
                  $bookingLink = admin_url('requests.php#booking-' . rawurlencode((string) $firstItem['booking_id']));
              }
              ?>
              <td class="admin-calendar-cell is-<?= admin_e($status) ?>">
                <?php if ($bookingLink !== null): ?>
                  <a class="admin-calendar-cell__link" href="<?= admin_e($bookingLink) ?>">
                    <strong><?= admin_e($status === 'confirmed' ? 'X' : ($status === 'waiting' ? 'W' : '•')) ?></strong>
                    <span><?= admin_e($formatCellTitle($firstItem)) ?></span>
                  </a>
                <?php elseif (is_array($firstItem)): ?>
                  <div class="admin-calendar-cell__content">
                    <strong><?= admin_e($status === 'blocked' ? 'B' : '•') ?></strong>
                    <span><?= admin_e($formatCellTitle($firstItem)) ?></span>
                  </div>
                <?php else: ?>
                  <div class="admin-calendar-cell__content">
                    <strong>.</strong>
                    <span>вільно</span>
                  </div>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="admin-panel admin-spaced">
  <div class="admin-panel__head">
    <div>
      <h2>Активні блоки</h2>
      <p class="admin-panel__hint">Ручні блокування і технічні паузи в поточному діапазоні.</p>
    </div>
  </div>
  <div class="admin-list">
    <?php foreach ($activeBlocks as $block): ?>
      <div class="admin-list-item">
        <div class="admin-list-item__meta">
          <span class="admin-badge"><?= admin_e((string) ($block['room_code'] ?? '')) ?></span>
          <span><?= admin_e((string) ($block['start_date'] ?? '')) ?> → <?= admin_e((string) ($block['end_date'] ?? '')) ?></span>
        </div>
        <p><?= admin_e((string) ($block['title'] ?? 'Блок')) ?><?= trim((string) ($block['note'] ?? '')) !== '' ? ' — ' . admin_e((string) $block['note']) : '' ?></p>
        <form class="admin-inline-form" method="post" action="<?= admin_e(admin_url($buildRedirect())) ?>">
          <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
          <input type="hidden" name="action" value="cancel_block">
          <input type="hidden" name="id" value="<?= admin_e((string) ($block['id'] ?? 0)) ?>">
          <button class="admin-button--ghost" type="submit">Зняти блок</button>
        </form>
      </div>
    <?php endforeach; ?>
    <?php if (empty($activeBlocks)): ?>
      <div class="admin-empty">Активних ручних блокувань у вибраному діапазоні немає.</div>
    <?php endif; ?>
  </div>
</section>
<?php
admin_render_footer();

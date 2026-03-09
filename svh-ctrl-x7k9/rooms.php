<?php

require_once __DIR__ . '/../includes/admin.php';

admin_require_login();

$roomId = (int) ($_GET['id'] ?? ($_POST['room_id'] ?? 1));
if ($roomId < 1 || $roomId > 20) {
    $roomId = 1;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();

    $roomId = (int) ($_POST['room_id'] ?? $roomId);
    if ($roomId < 1 || $roomId > 20) {
        $roomId = 1;
    }

    $images = admin_post_list('images');
    $cover = admin_post_field('cover', 255);
    if ($cover === '' && isset($images[0])) {
      $cover = $images[0];
    }

    $room = [
        'title' => admin_post_field('title', 120),
        'summary' => admin_post_text('summary', 280),
        'description' => admin_post_text('description', 5000),
        'type' => admin_post_field('type', 24),
        'capacity' => max(1, min(12, (int) ($_POST['capacity'] ?? 2))),
        'pricePerNight' => max(0, (int) ($_POST['pricePerNight'] ?? 0)),
        'amenities' => admin_post_list('amenities'),
        'rules' => admin_post_list('rules'),
        'images' => $images,
        'cover' => $cover,
    ];

    if (admin_write_room($roomId, $room)) {
        admin_db()->logAdminAction('room_save', 'Updated room ' . $roomId, admin_ip());
        admin_flash('success', 'Номер ' . $roomId . ' оновлено.');
    } else {
        admin_flash('error', 'Не вдалося зберегти номер.');
    }

    admin_redirect('rooms.php?id=' . $roomId);
}

$room = admin_read_room($roomId);
$roomTitle = (string) ($room['title'] ?? ('Номер ' . $roomId));
$roomCover = (string) ($room['cover'] ?? ($room['images'][0] ?? '/assets/images/placeholders/no-image.svg'));
$roomCapacity = (int) ($room['capacity'] ?? 2);
$roomPrice = (int) ($room['pricePerNight'] ?? 0);
$roomAmenities = is_array($room['amenities'] ?? null) ? $room['amenities'] : [];
$roomRules = is_array($room['rules'] ?? null) ? $room['rules'] : [];

admin_render_header('Номери', 'rooms.php');
?>
<section class="admin-panel">
  <div class="admin-panel__head">
    <div>
      <h2>Оберіть номер</h2>
      <p class="admin-panel__hint">Спочатку виберіть номер, далі редагуйте опис, ціну, правила та фотографії в одному місці.</p>
    </div>
  </div>
  <div class="admin-room-picker">
    <?php for ($i = 1; $i <= 20; $i++): ?>
      <a class="<?= $i === $roomId ? 'is-active' : '' ?>" href="<?= admin_e(admin_url('rooms.php?id=' . $i)) ?>">Номер <?= $i ?></a>
    <?php endfor; ?>
  </div>
</section>

<div class="admin-split-layout admin-spaced">
  <form class="admin-form" method="post" action="<?= admin_e(admin_url('rooms.php?id=' . $roomId)) ?>">
    <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
    <input type="hidden" name="room_id" value="<?= $roomId ?>">

    <section class="admin-panel">
      <div class="admin-panel__head">
        <div>
          <h2>Основне</h2>
          <p class="admin-panel__hint">Назва, тип, місткість і ціна показуються в списку номерів та деталях сторінки.</p>
        </div>
      </div>
      <div class="admin-form__grid">
        <div class="admin-field">
          <label for="title">Назва</label>
          <input id="title" name="title" value="<?= admin_e($roomTitle) ?>">
        </div>
        <div class="admin-field">
          <label for="type">Тип</label>
          <select id="type" name="type">
            <?php foreach (['standard' => 'Стандарт', 'lux' => 'Люкс', 'bunk' => 'Двоярусний', 'economy' => 'Економ', 'future' => 'У підготовці'] as $key => $label): ?>
              <option value="<?= admin_e($key) ?>"<?= (($room['type'] ?? '') === $key) ? ' selected' : '' ?>><?= admin_e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-field">
          <label for="capacity">Місткість</label>
          <input id="capacity" name="capacity" type="number" min="1" max="12" value="<?= admin_e((string) $roomCapacity) ?>">
        </div>
        <div class="admin-field">
          <label for="pricePerNight">Ціна за ніч, грн</label>
          <input id="pricePerNight" name="pricePerNight" type="number" min="0" step="50" value="<?= admin_e((string) $roomPrice) ?>">
        </div>
        <div class="admin-field">
          <label for="cover">Обкладинка</label>
          <input id="cover" name="cover" value="<?= admin_e((string) ($room['cover'] ?? '')) ?>">
          <p class="admin-hint">Якщо поле пусте, буде взято перше фото зі списку нижче.</p>
        </div>
      </div>
    </section>

    <section class="admin-panel">
      <div class="admin-panel__head">
        <div>
          <h2>Опис</h2>
          <p class="admin-panel__hint">Короткий опис працює як анонс, детальний текст використовується на сторінці номера.</p>
        </div>
      </div>
      <div class="admin-form__stack">
        <div class="admin-field">
          <label for="summary">Короткий опис</label>
          <textarea id="summary" name="summary"><?= admin_e((string) ($room['summary'] ?? '')) ?></textarea>
        </div>
        <div class="admin-field">
          <label for="description">Детальний опис</label>
          <textarea id="description" name="description"><?= admin_e((string) ($room['description'] ?? '')) ?></textarea>
        </div>
      </div>
    </section>

    <section class="admin-panel">
      <div class="admin-panel__head">
        <div>
          <h2>Зручності та правила</h2>
          <p class="admin-panel__hint">Кожен рядок стане окремим пунктом у списку на публічній сторінці номера.</p>
        </div>
      </div>
      <div class="admin-form__grid">
        <div class="admin-field">
          <label for="amenities">Зручності</label>
          <textarea id="amenities" name="amenities"><?= admin_e(implode("\n", $roomAmenities)) ?></textarea>
        </div>
        <div class="admin-field">
          <label for="rules">Правила</label>
          <textarea id="rules" name="rules"><?= admin_e(implode("\n", $roomRules)) ?></textarea>
        </div>
      </div>
    </section>

    <section class="admin-panel">
      <div class="admin-panel__head">
        <div>
          <h2>Медіа</h2>
          <p class="admin-panel__hint">Один рядок дорівнює одному шляху до зображення у сховищі.</p>
        </div>
      </div>
      <div class="admin-field">
        <label for="images">Зображення</label>
        <textarea id="images" name="images"><?= admin_e(implode("\n", $room['images'] ?? [])) ?></textarea>
      </div>
    </section>

    <div class="admin-actions">
      <button class="admin-button" type="submit">Зберегти номер</button>
      <a class="admin-button--ghost" href="<?= admin_e('/rooms/room-' . $roomId . '/') ?>" target="_blank" rel="noopener noreferrer">Відкрити публічну сторінку</a>
    </div>
  </form>

  <aside class="admin-room-preview">
    <div class="admin-room-preview__media">
      <img src="<?= admin_e($roomCover) ?>" alt="<?= admin_e($roomTitle) ?>">
    </div>
    <h2><?= admin_e($roomTitle) ?></h2>
    <div class="admin-room-preview__stats">
      <div>
        <span class="admin-muted-strong">Тип</span>
        <strong><?= admin_e((string) ($room['type'] ?? 'standard')) ?></strong>
      </div>
      <div>
        <span class="admin-muted-strong">Місткість</span>
        <strong><?= admin_e((string) $roomCapacity) ?> гостей</strong>
      </div>
      <div>
        <span class="admin-muted-strong">Ціна</span>
        <strong><?= $roomPrice > 0 ? admin_e((string) $roomPrice . ' грн') : 'не вказано' ?></strong>
      </div>
    </div>

    <?php if (!empty($roomAmenities)): ?>
      <h3>Зручності</h3>
      <div class="admin-chip-list">
        <?php foreach (array_slice($roomAmenities, 0, 8) as $amenity): ?>
          <span class="admin-chip"><?= admin_e((string) $amenity) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($roomRules)): ?>
      <h3>Правила</h3>
      <div class="admin-chip-list">
        <?php foreach (array_slice($roomRules, 0, 6) as $rule): ?>
          <span class="admin-chip"><?= admin_e((string) $rule) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </aside>
</div>
<?php
admin_render_footer();

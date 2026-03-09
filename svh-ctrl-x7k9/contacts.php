<?php

require_once __DIR__ . '/../includes/admin.php';

admin_require_login();

$content = svh_read_site_content();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();

    $content['contacts'] = [
        'phone' => admin_post_field('phone', 32),
        'phone_label' => admin_post_field('phone_label', 40),
        'email' => admin_post_field('email', 120),
        'address' => admin_post_field('address', 180),
        'instagram_url' => admin_post_field('instagram_url', 255),
        'instagram_label' => admin_post_field('instagram_label', 80),
        'tiktok_url' => admin_post_field('tiktok_url', 255),
        'tiktok_label' => admin_post_field('tiktok_label', 80),
        'booking_note' => admin_post_text('booking_note', 300),
        'map_url' => admin_post_field('map_url', 255),
    ];

    if (svh_write_site_content($content)) {
        admin_db()->logAdminAction('contacts_save', 'Updated contact block', admin_ip());
        admin_flash('success', 'Контакти оновлено.');
    } else {
        admin_flash('error', 'Не вдалося зберегти контакти.');
    }

    admin_redirect('contacts.php');
}

admin_render_header('Контакти', 'contacts.php');
?>
<form class="admin-form" method="post" action="<?= admin_e(admin_url('contacts.php')) ?>">
  <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
  <section class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h2>Основні контакти</h2>
        <p class="admin-panel__hint">Усе, що тут зміните, піде на головну, сторінку бронювання, футер і інші контактні блоки сайту.</p>
      </div>
      <a class="admin-button--ghost" href="/" target="_blank" rel="noopener noreferrer">Подивитися сайт</a>
    </div>
    <div class="admin-form__grid">
      <div class="admin-field">
        <label for="phone">Телефон у форматі `tel:`</label>
        <input id="phone" name="phone" value="<?= admin_e((string) ($content['contacts']['phone'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="phone_label">Телефон для показу</label>
        <input id="phone_label" name="phone_label" value="<?= admin_e((string) ($content['contacts']['phone_label'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="email">Email</label>
        <input id="email" name="email" value="<?= admin_e((string) ($content['contacts']['email'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="address">Адреса</label>
        <input id="address" name="address" value="<?= admin_e((string) ($content['contacts']['address'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="instagram_url">Instagram URL</label>
        <input id="instagram_url" name="instagram_url" value="<?= admin_e((string) ($content['contacts']['instagram_url'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="instagram_label">Instagram label</label>
        <input id="instagram_label" name="instagram_label" value="<?= admin_e((string) ($content['contacts']['instagram_label'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="tiktok_url">TikTok URL</label>
        <input id="tiktok_url" name="tiktok_url" value="<?= admin_e((string) ($content['contacts']['tiktok_url'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="tiktok_label">TikTok label</label>
        <input id="tiktok_label" name="tiktok_label" value="<?= admin_e((string) ($content['contacts']['tiktok_label'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="map_url">Посилання на мапу</label>
        <input id="map_url" name="map_url" value="<?= admin_e((string) ($content['contacts']['map_url'] ?? '')) ?>">
      </div>
    </div>
    <div class="admin-field">
      <label for="booking_note">Коротка примітка про бронювання</label>
      <textarea id="booking_note" name="booking_note"><?= admin_e((string) ($content['contacts']['booking_note'] ?? '')) ?></textarea>
    </div>
  </section>
  <div class="admin-actions">
    <button class="admin-button" type="submit">Зберегти контакти</button>
    <a class="admin-button--ghost" href="/booking/" target="_blank" rel="noopener noreferrer">Відкрити бронювання</a>
  </div>
</form>
<?php
admin_render_footer();

<?php

require_once __DIR__ . '/../includes/admin.php';

admin_require_login();

function admin_gallery_upload(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > MAX_UPLOAD_SIZE) {
        return null;
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return null;
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string) finfo_file($finfo, $tmp) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
    } elseif (function_exists('mime_content_type')) {
        $mime = (string) mime_content_type($tmp);
    }

    $extMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($extMap[$mime])) {
        return null;
    }

    $baseName = preg_replace('/[^a-z0-9-]+/i', '-', pathinfo((string) ($file['name'] ?? 'gallery'), PATHINFO_FILENAME)) ?: 'gallery';
    $baseName = trim((string) $baseName, '-');
    if ($baseName === '') {
        $baseName = 'gallery';
    }

    $targetName = $baseName . '-' . date('Ymd-His') . '.' . $extMap[$mime];
    $targetDir = UPLOADS_DIR . 'site/';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        return null;
    }

    $targetPath = $targetDir . $targetName;
    if (!move_uploaded_file($tmp, $targetPath)) {
        return null;
    }

    return '/storage/uploads/site/' . $targetName;
}

$content = svh_read_site_content();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();
    $action = (string) ($_POST['action'] ?? 'save_gallery');

    if ($action === 'upload_gallery') {
        $uploadedPath = admin_gallery_upload($_FILES['gallery_image'] ?? []);
        if ($uploadedPath === null) {
            admin_flash('error', 'Завантаження не вдалося. Дозволені JPG, PNG, WEBP до 5 МБ.');
            admin_redirect('gallery.php');
        }

        $content['gallery']['items'][] = [
            'src' => $uploadedPath,
            'alt' => admin_post_field('upload_alt', 180),
            'category' => admin_post_field('upload_category', 80),
            'featured' => isset($_POST['upload_featured']),
        ];
        svh_write_site_content($content);
        admin_db()->logAdminAction('gallery_upload', 'Uploaded gallery image ' . $uploadedPath, admin_ip());
        admin_flash('success', 'Зображення додано в галерею.');
        admin_redirect('gallery.php');
    }

    $srcList = $_POST['gallery_src'] ?? [];
    $altList = $_POST['gallery_alt'] ?? [];
    $categoryList = $_POST['gallery_category'] ?? [];
    $featuredList = $_POST['gallery_featured'] ?? [];
    $items = [];

    foreach ($srcList as $index => $src) {
        $src = sanitize_text_field((string) $src, 255);
        if ($src === '') {
            continue;
        }

        $items[] = [
            'src' => $src,
            'alt' => sanitize_text_field((string) ($altList[$index] ?? ''), 180),
            'category' => sanitize_text_field((string) ($categoryList[$index] ?? ''), 80),
            'featured' => array_key_exists((string) $index, $featuredList),
        ];
    }

    $content['gallery']['title'] = admin_post_field('gallery_title', 160);
    $content['gallery']['subtitle'] = admin_post_text('gallery_subtitle', 260);
    $content['gallery']['items'] = $items;

    if (svh_write_site_content($content)) {
        admin_db()->logAdminAction('gallery_save', 'Updated gallery list', admin_ip());
        admin_flash('success', 'Галерею оновлено.');
    } else {
        admin_flash('error', 'Не вдалося зберегти галерею.');
    }

    admin_redirect('gallery.php');
}

admin_render_header('Галерея', 'gallery.php');
?>
<section class="admin-panel">
  <div class="admin-panel__head">
    <div>
      <h2>Завантажити нове фото</h2>
      <p class="admin-panel__hint">Додавайте фото одразу в галерею. Після завантаження його можна відредагувати нижче разом з alt і категорією.</p>
    </div>
  </div>
  <form class="admin-form" method="post" action="<?= admin_e(admin_url('gallery.php')) ?>" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
    <input type="hidden" name="action" value="upload_gallery">
    <div class="admin-form__grid">
      <div class="admin-field">
        <label for="gallery_image">Файл</label>
        <input id="gallery_image" name="gallery_image" type="file" accept=".jpg,.jpeg,.png,.webp" required>
      </div>
      <div class="admin-field">
        <label for="upload_alt">Alt текст</label>
        <input id="upload_alt" name="upload_alt">
      </div>
      <div class="admin-field">
        <label for="upload_category">Категорія</label>
        <input id="upload_category" name="upload_category" value="Територія">
      </div>
      <div class="admin-field">
        <label><input type="checkbox" name="upload_featured"> Виділити як велике фото</label>
      </div>
    </div>
    <div class="admin-actions">
      <button class="admin-button" type="submit">Завантажити</button>
    </div>
  </form>
</section>

<form class="admin-form admin-spaced" method="post" action="<?= admin_e(admin_url('gallery.php')) ?>">
  <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
  <input type="hidden" name="action" value="save_gallery">
  <section class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h2>Налаштування сторінки</h2>
        <p class="admin-panel__hint">Заголовок і підзаголовок використовуються у верхній частині публічної сторінки галереї.</p>
      </div>
      <a class="admin-button--ghost" href="/gallery/" target="_blank" rel="noopener noreferrer">Відкрити галерею</a>
    </div>
    <div class="admin-field">
      <label for="gallery_title">Заголовок сторінки</label>
      <input id="gallery_title" name="gallery_title" value="<?= admin_e((string) ($content['gallery']['title'] ?? '')) ?>">
    </div>
    <div class="admin-field">
      <label for="gallery_subtitle">Підзаголовок</label>
      <textarea id="gallery_subtitle" name="gallery_subtitle"><?= admin_e((string) ($content['gallery']['subtitle'] ?? '')) ?></textarea>
    </div>
  </section>

  <section class="admin-panel admin-spaced">
    <div class="admin-panel__head">
      <div>
        <h2>Поточні фото</h2>
        <p class="admin-panel__hint">Тут редагуються підписи, категорії та великі акценти для сітки галереї.</p>
      </div>
    </div>
    <div class="admin-gallery-grid">
      <?php foreach (($content['gallery']['items'] ?? []) as $index => $item): ?>
        <div class="admin-gallery-item">
          <img src="<?= admin_e((string) ($item['src'] ?? '')) ?>" alt="<?= admin_e((string) ($item['alt'] ?? '')) ?>">
          <input type="hidden" name="gallery_src[<?= $index ?>]" value="<?= admin_e((string) ($item['src'] ?? '')) ?>">
          <div class="admin-field">
            <label for="gallery_alt_<?= $index ?>">Alt</label>
            <input id="gallery_alt_<?= $index ?>" name="gallery_alt[<?= $index ?>]" value="<?= admin_e((string) ($item['alt'] ?? '')) ?>">
          </div>
          <div class="admin-field">
            <label for="gallery_category_<?= $index ?>">Категорія</label>
            <input id="gallery_category_<?= $index ?>" name="gallery_category[<?= $index ?>]" value="<?= admin_e((string) ($item['category'] ?? '')) ?>">
          </div>
          <label><input type="checkbox" name="gallery_featured[<?= $index ?>]"<?= !empty($item['featured']) ? ' checked' : '' ?>> Велике фото</label>
        </div>
      <?php endforeach; ?>
      <?php if (empty($content['gallery']['items'])): ?>
        <div class="admin-empty">У галереї ще немає фото.</div>
      <?php endif; ?>
    </div>
    <div class="admin-actions">
      <button class="admin-button" type="submit">Зберегти галерею</button>
    </div>
  </section>
</form>
<?php
admin_render_footer();

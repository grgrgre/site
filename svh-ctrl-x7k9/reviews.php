<?php

require_once __DIR__ . '/../includes/admin.php';

admin_require_login();

$data = admin_reviews_data();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();

    $action = (string) ($_POST['action'] ?? '');
    $reviewId = (int) ($_POST['review_id'] ?? 0);
    [$bucket, $index, $item] = admin_find_review($data, $reviewId);

    if ($action === 'approve' && $bucket === 'pending' && is_array($item)) {
        unset($data['pending'][$index]);
        $data['approved'][] = $item;
        $data['pending'] = array_values($data['pending']);
        admin_save_reviews_data($data);
        admin_db()->logAdminAction('review_approve', 'Approved review ' . $reviewId, admin_ip());
        admin_flash('success', 'Відгук опубліковано.');
        admin_redirect('reviews.php');
    }

    if ($action === 'delete' && $bucket !== '' && $index >= 0) {
        unset($data[$bucket][$index]);
        $data[$bucket] = array_values($data[$bucket]);
        admin_save_reviews_data($data);
        admin_db()->logAdminAction('review_delete', 'Deleted review ' . $reviewId, admin_ip());
        admin_flash('success', 'Запис видалено.');
        admin_redirect('reviews.php');
    }

    if ($action === 'save') {
        $status = (string) ($_POST['status'] ?? 'approved');
        if (!in_array($status, ['approved', 'pending'], true)) {
            $status = 'approved';
        }

        $updated = [
            'id' => $reviewId > 0 ? $reviewId : max(100, (int) ($data['nextId'] ?? 100)),
            'name' => admin_post_field('name', 80),
            'text' => admin_post_text('text', 1500),
            'rating' => max(1, min(5, (int) ($_POST['rating'] ?? 5))),
            'topic' => admin_post_field('topic', 40),
            'source' => admin_post_field('source', 40),
            'date' => admin_post_field('date', 32),
            'created_at' => (string) ($item['created_at'] ?? date('c')),
            'images' => [],
        ];

        if ($bucket !== '' && $index >= 0) {
            unset($data[$bucket][$index]);
            $data[$bucket] = array_values($data[$bucket]);
        } else {
            $data['nextId'] = ((int) ($data['nextId'] ?? 100)) + 1;
        }

        $data[$status][] = $updated;
        admin_save_reviews_data($data);
        admin_db()->logAdminAction('review_save', 'Saved review ' . $updated['id'], admin_ip());
        admin_flash('success', 'Відгук збережено.');
        admin_redirect('reviews.php?edit=' . $updated['id']);
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
[$editBucket, $editIndex, $editItem] = admin_find_review($data, $editId);
if (!is_array($editItem)) {
    $editBucket = 'approved';
    $editItem = [
        'id' => 0,
        'name' => '',
        'text' => '',
        'rating' => 5,
        'topic' => 'general',
        'source' => 'site',
        'date' => date('Y-m-d'),
    ];
}

$approvedCount = count($data['approved']);
$pendingCount = count($data['pending']);
$questionsCount = count($data['questions']);

admin_render_header('Відгуки', 'reviews.php');
?>
<section class="admin-panel">
  <div class="admin-panel__head">
    <div>
      <h2>Редактор відгуку</h2>
      <p class="admin-panel__hint">Тут можна створити новий відгук, відредагувати існуючий або підготувати текст перед публікацією.</p>
    </div>
  </div>
  <div class="admin-kpi-grid">
    <article class="admin-kpi">
      <p class="admin-kpi__label">Опубліковані</p>
      <p class="admin-kpi__value"><?= $approvedCount ?></p>
    </article>
    <article class="admin-kpi">
      <p class="admin-kpi__label">На модерації</p>
      <p class="admin-kpi__value"><?= $pendingCount ?></p>
    </article>
    <article class="admin-kpi">
      <p class="admin-kpi__label">Питання гостей</p>
      <p class="admin-kpi__value"><?= $questionsCount ?></p>
    </article>
  </div>

  <form class="admin-form" method="post" action="<?= admin_e(admin_url('reviews.php')) ?>">
    <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="review_id" value="<?= admin_e((string) ($editItem['id'] ?? 0)) ?>">
    <div class="admin-form__grid">
      <div class="admin-field">
        <label for="name">Ім'я</label>
        <input id="name" name="name" value="<?= admin_e((string) ($editItem['name'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="date">Дата</label>
        <input id="date" name="date" value="<?= admin_e((string) ($editItem['date'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="rating">Оцінка</label>
        <input id="rating" name="rating" type="number" min="1" max="5" value="<?= admin_e((string) ($editItem['rating'] ?? 5)) ?>">
      </div>
      <div class="admin-field">
        <label for="status">Статус</label>
        <select id="status" name="status">
          <option value="approved"<?= $editBucket === 'approved' ? ' selected' : '' ?>>Опубліковано</option>
          <option value="pending"<?= $editBucket === 'pending' ? ' selected' : '' ?>>На модерації</option>
        </select>
      </div>
      <div class="admin-field">
        <label for="topic">Тема</label>
        <select id="topic" name="topic">
          <?php foreach (['general', 'rooms', 'territory', 'service', 'location'] as $topic): ?>
            <option value="<?= admin_e($topic) ?>"<?= (($editItem['topic'] ?? '') === $topic) ? ' selected' : '' ?>><?= admin_e($topic) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <label for="source">Джерело</label>
        <input id="source" name="source" value="<?= admin_e((string) ($editItem['source'] ?? 'site')) ?>">
      </div>
    </div>
    <div class="admin-field">
      <label for="text">Текст відгуку</label>
      <textarea id="text" name="text"><?= admin_e((string) ($editItem['text'] ?? '')) ?></textarea>
    </div>
    <div class="admin-actions">
      <button class="admin-button" type="submit">Зберегти відгук</button>
      <a class="admin-button--ghost" href="<?= admin_e(admin_url('reviews.php')) ?>">Новий відгук</a>
    </div>
  </form>
</section>

<section class="admin-review-columns admin-spaced">
  <article class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h2>На модерації</h2>
        <p class="admin-panel__hint">Тут залишайте лише ті відгуки, які ще треба перевірити або допрацювати.</p>
      </div>
    </div>
    <div class="admin-list">
      <?php foreach ($data['pending'] as $review): ?>
        <div class="admin-list-item">
          <div class="admin-list-item__meta">
            <span class="admin-badge admin-badge--warning">pending</span>
            <span><?= admin_e((string) ($review['name'] ?? '')) ?></span>
          </div>
          <p><?= admin_e((string) ($review['text'] ?? '')) ?></p>
          <div class="admin-list-item__actions">
            <form class="admin-inline-form" method="post" action="<?= admin_e(admin_url('reviews.php')) ?>">
              <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="review_id" value="<?= admin_e((string) ($review['id'] ?? 0)) ?>">
              <button class="admin-button" type="submit">Опублікувати</button>
            </form>
            <a class="admin-button--ghost" href="<?= admin_e(admin_url('reviews.php?edit=' . ((int) ($review['id'] ?? 0)))) ?>">Редагувати</a>
            <form class="admin-inline-form" method="post" action="<?= admin_e(admin_url('reviews.php')) ?>">
              <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="review_id" value="<?= admin_e((string) ($review['id'] ?? 0)) ?>">
              <button class="admin-button--danger" type="submit">Видалити</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($data['pending'])): ?>
        <div class="admin-empty">Немає відгуків, що очікують модерації.</div>
      <?php endif; ?>
    </div>
  </article>

  <article class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h2>Опубліковані</h2>
        <p class="admin-panel__hint">Вже видимі на сайті. Звідси їх можна швидко відредагувати або прибрати.</p>
      </div>
    </div>
    <div class="admin-list">
      <?php foreach ($data['approved'] as $review): ?>
        <div class="admin-list-item">
          <div class="admin-list-item__meta">
            <span class="admin-badge">approved</span>
            <span><?= admin_e((string) ($review['name'] ?? '')) ?></span>
            <span><?= admin_e((string) ($review['date'] ?? '')) ?></span>
          </div>
          <p><?= admin_e((string) ($review['text'] ?? '')) ?></p>
          <div class="admin-list-item__actions">
            <a class="admin-button--ghost" href="<?= admin_e(admin_url('reviews.php?edit=' . ((int) ($review['id'] ?? 0)))) ?>">Редагувати</a>
            <form class="admin-inline-form" method="post" action="<?= admin_e(admin_url('reviews.php')) ?>">
              <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="review_id" value="<?= admin_e((string) ($review['id'] ?? 0)) ?>">
              <button class="admin-button--danger" type="submit">Видалити</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($data['approved'])): ?>
        <div class="admin-empty">Опублікованих відгуків поки що немає.</div>
      <?php endif; ?>
    </div>
  </article>
</section>

<section class="admin-panel admin-spaced">
  <div class="admin-panel__head">
    <div>
      <h2>Питання гостей</h2>
      <p class="admin-panel__hint">Поки без окремої відповіді з адмінки, але тут можна швидко переглянути контекст запиту.</p>
    </div>
  </div>
  <div class="admin-list">
    <?php foreach ($data['questions'] as $question): ?>
      <div class="admin-list-item">
        <div class="admin-list-item__meta">
          <span class="admin-badge admin-badge--warning">question</span>
          <span><?= admin_e((string) ($question['name'] ?? '')) ?></span>
          <span><?= admin_e((string) ($question['contact'] ?? '')) ?></span>
        </div>
        <p><?= admin_e((string) ($question['text'] ?? '')) ?></p>
      </div>
    <?php endforeach; ?>
    <?php if (empty($data['questions'])): ?>
      <div class="admin-empty">Питань від гостей поки немає.</div>
    <?php endif; ?>
  </div>
</section>
<?php
admin_render_footer();

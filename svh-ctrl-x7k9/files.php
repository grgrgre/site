<?php

require_once __DIR__ . '/../includes/admin.php';

admin_require_login();

$fileRoots = [
    ['path' => '/', 'label' => 'Корінь сайту'],
    ['path' => '/about', 'label' => 'Сторінка "Про нас"'],
    ['path' => '/booking', 'label' => 'Бронювання'],
    ['path' => '/gallery', 'label' => 'Галерея'],
    ['path' => '/reviews', 'label' => 'Відгуки'],
    ['path' => '/rooms', 'label' => 'Номери'],
    ['path' => '/ozero-svityaz', 'label' => 'Озеро Світязь'],
    ['path' => '/assets/css', 'label' => 'CSS'],
    ['path' => '/assets/js', 'label' => 'JavaScript'],
    ['path' => '/storage/data/rooms', 'label' => 'JSON номерів'],
];

$imageRoots = [
    ['path' => '/storage/uploads/site', 'label' => 'Фото сайту'],
    ['path' => '/storage/uploads/yard', 'label' => 'Фото території'],
    ['path' => '/storage/uploads/rooms', 'label' => 'Фото номерів'],
    ['path' => '/assets/images', 'label' => 'Публічні assets'],
];

$studioConfig = [
    'csrfToken' => admin_csrf_token(),
    'filesApi' => '/api/files.php',
    'aiApi' => '/api/ai-studio.php',
    'fileRoots' => $fileRoots,
    'imageRoots' => $imageRoots,
];

admin_render_header('Файли + AI', 'files.php');
?>
<section class="admin-panel">
  <div class="admin-panel__head">
    <div>
      <h2>HTML-редактор і AI-асистент</h2>
      <p class="admin-panel__hint">Тут можна відкрити HTML/CSS/JS/JSON файл, відредагувати його вручну або попросити AI переписати фрагмент під ваше завдання. Для зображень доступна генерація нового варіанта або редагування існуючого файлу.</p>
    </div>
    <a class="admin-button--ghost" href="/" target="_blank" rel="noopener noreferrer">Подивитися сайт</a>
  </div>

  <div class="admin-workspace" id="admin-ai-studio">
    <aside class="admin-workspace__sidebar">
      <section class="admin-panel admin-panel--embedded">
        <div class="admin-panel__head">
          <div>
            <h3>Файли сайту</h3>
            <p class="admin-panel__hint">Оберіть зону сайту і відкрийте потрібний файл.</p>
          </div>
        </div>
        <div class="admin-field">
          <label for="studio-file-root">Розділ</label>
          <select id="studio-file-root"></select>
        </div>
        <div class="admin-actions admin-actions--tight">
          <button class="admin-button--ghost" type="button" id="studio-file-root-refresh">Оновити список</button>
        </div>
        <div class="admin-status" id="studio-file-status" role="status" aria-live="polite">Завантаження списку файлів…</div>
        <div class="admin-browser-list" id="studio-file-list"></div>
      </section>

      <section class="admin-panel admin-panel--embedded">
        <div class="admin-panel__head">
          <div>
            <h3>Зображення</h3>
            <p class="admin-panel__hint">Оберіть папку з фото і файл, який треба змінити або використати як основу для AI-редагування.</p>
          </div>
        </div>
        <div class="admin-field">
          <label for="studio-image-root">Папка зображень</label>
          <select id="studio-image-root"></select>
        </div>
        <div class="admin-actions admin-actions--tight">
          <button class="admin-button--ghost" type="button" id="studio-image-root-refresh">Оновити фото</button>
        </div>
        <div class="admin-status" id="studio-image-status" role="status" aria-live="polite">Завантаження списку зображень…</div>
        <div class="admin-browser-list" id="studio-image-list"></div>
      </section>
    </aside>

    <div class="admin-workspace__main">
      <section class="admin-panel admin-panel--embedded">
        <div class="admin-panel__head">
          <div>
            <h3>Редактор файлу</h3>
            <p class="admin-panel__hint">Збереження відбувається через існуючий files API з бекапом. AI лише підготує новий варіант; остаточне збереження контролюєте ви.</p>
          </div>
        </div>
        <div class="admin-field">
          <label for="studio-file-path">Шлях до файлу</label>
          <div class="admin-inline-input">
            <input id="studio-file-path" type="text" placeholder="/index.html">
            <button class="admin-button--ghost" type="button" id="studio-open-file">Відкрити</button>
          </div>
        </div>
        <div class="admin-editor-meta" id="studio-file-meta">Файл ще не відкрито.</div>
        <div class="admin-status" id="studio-save-status" role="status" aria-live="polite">Відкрийте файл, щоб почати редагування.</div>
        <div class="admin-field">
          <label for="studio-editor">Вміст файлу</label>
          <textarea id="studio-editor" class="admin-code-editor" spellcheck="false" placeholder="Тут з’явиться код або HTML вибраного файлу."></textarea>
        </div>
        <div class="admin-actions">
          <button class="admin-button" type="button" id="studio-save-file">Зберегти файл</button>
          <a class="admin-button--ghost" href="/" target="_blank" rel="noopener noreferrer">Відкрити сайт у новій вкладці</a>
        </div>
      </section>

      <section class="admin-grid admin-grid--two admin-spaced">
        <article class="admin-panel">
          <div class="admin-panel__head">
            <div>
              <h3>AI для HTML і текстових файлів</h3>
              <p class="admin-panel__hint">Опишіть задачу звичайною мовою: переписати hero, змінити CTA, скоротити текст, оновити блок або виправити структуру.</p>
            </div>
          </div>
          <div class="admin-field">
            <label for="studio-ai-instruction">Що треба змінити</label>
            <textarea id="studio-ai-instruction" placeholder="Наприклад: онови hero на головній, зроби текст коротшим і більш преміальним, залиш стек та існуючу структуру HTML."></textarea>
          </div>
          <div class="admin-status" id="studio-ai-status" role="status" aria-live="polite">AI-підказка з’явиться тут.</div>
          <div class="admin-note-box" id="studio-ai-summary">AI ще не запускався.</div>
          <div class="admin-actions">
            <button class="admin-button" type="button" id="studio-ai-rewrite">Переписати через AI</button>
            <button class="admin-button--ghost" type="button" id="studio-ai-apply" disabled>Підставити в редактор</button>
          </div>
        </article>

        <article class="admin-panel">
          <div class="admin-panel__head">
            <div>
              <h3>AI для зображень</h3>
              <p class="admin-panel__hint">Можна згенерувати нове фото або змінити вибране. Якщо ввімкнути заміну, новий файл перезапише поточний у `storage/uploads` з резервною копією.</p>
            </div>
          </div>
          <div class="admin-form__stack">
            <div class="admin-field">
              <label for="studio-image-path">Шлях до зображення</label>
              <input id="studio-image-path" type="text" placeholder="/storage/uploads/site/example.webp">
            </div>
            <div class="admin-field">
              <label for="studio-image-prompt">Prompt для AI</label>
              <textarea id="studio-image-prompt" placeholder="Наприклад: зроби фото двору більш світлим, теплим, сучасним, без людей, з натуральною зеленню і акуратною терасою."></textarea>
            </div>
            <div class="admin-form__grid admin-form__grid--compact">
              <div class="admin-field">
                <label for="studio-image-size">Розмір</label>
                <select id="studio-image-size">
                  <option value="1024x1024">1024 × 1024</option>
                  <option value="1536x1024">1536 × 1024</option>
                  <option value="1024x1536">1024 × 1536</option>
                  <option value="auto">Auto</option>
                </select>
              </div>
              <div class="admin-field">
                <label for="studio-image-quality">Якість</label>
                <select id="studio-image-quality">
                  <option value="medium">Medium</option>
                  <option value="high">High</option>
                  <option value="low">Low</option>
                  <option value="auto">Auto</option>
                </select>
              </div>
              <div class="admin-field">
                <label for="studio-image-format">Формат</label>
                <select id="studio-image-format">
                  <option value="webp">WEBP</option>
                  <option value="png">PNG</option>
                  <option value="jpeg">JPEG</option>
                </select>
              </div>
              <div class="admin-field">
                <label for="studio-image-background">Фон</label>
                <select id="studio-image-background">
                  <option value="auto">Auto</option>
                  <option value="opaque">Opaque</option>
                  <option value="transparent">Transparent</option>
                </select>
              </div>
            </div>
            <label class="admin-checkbox-row">
              <input id="studio-image-replace" type="checkbox">
              <span>Замінити поточний файл, якщо вибрано зображення зі `storage/uploads`</span>
            </label>
          </div>
          <div class="admin-status" id="studio-image-run-status" role="status" aria-live="polite">AI-генерація зображень ще не запускалась.</div>
          <div class="admin-preview-card">
            <div class="admin-preview-card__meta" id="studio-image-result-meta">Результат з’явиться тут.</div>
            <img id="studio-image-preview" class="admin-image-preview" alt="AI preview" hidden>
          </div>
          <div class="admin-actions">
            <button class="admin-button" type="button" id="studio-image-run">Запустити AI</button>
            <button class="admin-button--ghost" type="button" id="studio-image-copy" disabled>Скопіювати шлях</button>
            <button class="admin-button--ghost" type="button" id="studio-image-insert" disabled>Вставити шлях у редактор</button>
          </div>
        </article>
      </section>
    </div>
  </div>
</section>

<script id="admin-ai-studio-config" type="application/json"><?= json_encode($studioConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= admin_e(admin_asset_url('admin-files.js')) ?>"></script>
<?php
admin_render_footer();

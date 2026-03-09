<?php

require_once __DIR__ . '/../includes/admin.php';

admin_require_login();

function admin_parse_pairs(string $raw): array
{
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $items = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || !str_contains($line, '|')) {
            continue;
        }

        [$title, $text] = array_map('trim', explode('|', $line, 2));
        if ($title === '' || $text === '') {
            continue;
        }

        $items[] = [
            'title' => sanitize_text_field($title, 120),
            'text' => sanitize_multiline_text($text, 500),
        ];
    }

    return $items;
}

function admin_parse_faq(string $raw): array
{
    $pairs = admin_parse_pairs($raw);
    $faq = [];

    foreach ($pairs as $item) {
        $faq[] = [
            'question' => $item['title'],
            'answer' => $item['text'],
        ];
    }

    return $faq;
}

$content = svh_read_site_content();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_verify_csrf();

    $content['home']['hero'] = [
        'eyebrow' => admin_post_field('hero_eyebrow', 120),
        'title' => admin_post_field('hero_title', 160),
        'accent' => admin_post_field('hero_accent', 160),
        'subtitle' => admin_post_text('hero_subtitle', 400),
        'primary_cta_text' => admin_post_field('hero_primary_text', 80),
        'primary_cta_url' => admin_post_field('hero_primary_url', 180),
        'secondary_cta_text' => admin_post_field('hero_secondary_text', 80),
        'secondary_cta_url' => admin_post_field('hero_secondary_url', 180),
        'highlights' => admin_post_list('hero_highlights'),
    ];

    $content['home']['intro'] = [
        'label' => admin_post_field('intro_label', 80),
        'title' => admin_post_field('intro_title', 160),
        'text' => admin_post_text('intro_text', 320),
    ];

    $content['home']['story'] = [
        'label' => admin_post_field('story_label', 80),
        'title' => admin_post_field('story_title', 160),
        'text' => admin_post_text('story_text', 800),
        'image' => admin_post_field('story_image', 255),
        'image_alt' => admin_post_field('story_image_alt', 160),
    ];

    $content['home']['cta'] = [
        'title' => admin_post_field('cta_title', 160),
        'text' => admin_post_text('cta_text', 320),
        'primary_text' => admin_post_field('cta_primary_text', 80),
        'primary_url' => admin_post_field('cta_primary_url', 180),
        'secondary_text' => admin_post_field('cta_secondary_text', 80),
        'secondary_url' => admin_post_field('cta_secondary_url', 180),
    ];

    $content['home']['benefits'] = admin_parse_pairs(admin_post_text('benefits_raw', 2400));
    $content['home']['faq'] = admin_parse_faq(admin_post_text('faq_raw', 3200));

    foreach (['home', 'about', 'gallery', 'reviews', 'booking'] as $page) {
        $content['seo'][$page] = [
            'title' => admin_post_field('seo_' . $page . '_title', 180),
            'description' => admin_post_text('seo_' . $page . '_description', 320),
        ];
    }

    if (svh_write_site_content($content)) {
        admin_db()->logAdminAction('content_save', 'Updated homepage and SEO content', admin_ip());
        admin_flash('success', 'Контент головної сторінки та SEO оновлено.');
    } else {
        admin_flash('error', 'Не вдалося зберегти контент.');
    }

    admin_redirect('content.php');
}

$benefitsRaw = implode("\n", array_map(
    static fn(array $item): string => trim((string) ($item['title'] ?? '')) . ' | ' . trim((string) ($item['text'] ?? '')),
    $content['home']['benefits'] ?? []
));
$faqRaw = implode("\n", array_map(
    static fn(array $item): string => trim((string) ($item['question'] ?? '')) . ' | ' . trim((string) ($item['answer'] ?? '')),
    $content['home']['faq'] ?? []
));

admin_render_header('Контент', 'content.php');
?>
<form class="admin-form" method="post" action="<?= admin_e(admin_url('content.php')) ?>">
  <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">

  <section class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h2>Як користуватись цим розділом</h2>
        <p class="admin-panel__hint">Тут редагуються тексти та SEO без зміни шаблонів. Кнопки, hero, FAQ і блоки головної беруть дані саме звідси.</p>
      </div>
      <a class="admin-button--ghost" href="/" target="_blank" rel="noopener noreferrer">Відкрити сайт</a>
    </div>
    <div class="admin-card-grid">
      <div class="admin-card-link">
        <span class="admin-card-link__title">Hero</span>
        <span class="admin-card-link__text">Великий перший екран: заголовок, підзаголовок, CTA та короткі факти.</span>
      </div>
      <div class="admin-card-link">
        <span class="admin-card-link__title">Блоки головної</span>
        <span class="admin-card-link__text">Переваги, історія садиби, фінальний заклик до бронювання.</span>
      </div>
      <div class="admin-card-link">
        <span class="admin-card-link__title">SEO</span>
        <span class="admin-card-link__text">Title і description для основних сторінок сайту.</span>
      </div>
    </div>
  </section>

  <section class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h2>Hero</h2>
        <p class="admin-panel__hint">Перший екран головної сторінки. Тут найважливіші формулювання для конверсії.</p>
      </div>
    </div>
    <div class="admin-form__grid">
      <div class="admin-field">
        <label for="hero_eyebrow">Підзаголовок</label>
        <input id="hero_eyebrow" name="hero_eyebrow" value="<?= admin_e((string) ($content['home']['hero']['eyebrow'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="hero_title">Заголовок</label>
        <input id="hero_title" name="hero_title" value="<?= admin_e((string) ($content['home']['hero']['title'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="hero_accent">Акцентний рядок</label>
        <input id="hero_accent" name="hero_accent" value="<?= admin_e((string) ($content['home']['hero']['accent'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="hero_highlights">Короткі факти</label>
        <textarea id="hero_highlights" name="hero_highlights"><?= admin_e(implode("\n", $content['home']['hero']['highlights'] ?? [])) ?></textarea>
      </div>
    </div>
    <div class="admin-field">
      <label for="hero_subtitle">Опис</label>
      <textarea id="hero_subtitle" name="hero_subtitle"><?= admin_e((string) ($content['home']['hero']['subtitle'] ?? '')) ?></textarea>
    </div>
    <div class="admin-form__grid">
      <div class="admin-field">
        <label for="hero_primary_text">Текст головної кнопки</label>
        <input id="hero_primary_text" name="hero_primary_text" value="<?= admin_e((string) ($content['home']['hero']['primary_cta_text'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="hero_primary_url">Посилання головної кнопки</label>
        <input id="hero_primary_url" name="hero_primary_url" value="<?= admin_e((string) ($content['home']['hero']['primary_cta_url'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="hero_secondary_text">Текст другої кнопки</label>
        <input id="hero_secondary_text" name="hero_secondary_text" value="<?= admin_e((string) ($content['home']['hero']['secondary_cta_text'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="hero_secondary_url">Посилання другої кнопки</label>
        <input id="hero_secondary_url" name="hero_secondary_url" value="<?= admin_e((string) ($content['home']['hero']['secondary_cta_url'] ?? '')) ?>">
      </div>
    </div>
  </section>

  <section class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h2>Блоки головної</h2>
        <p class="admin-panel__hint">Секція переваг і короткий вступ про формат відпочинку в садибі.</p>
      </div>
    </div>
    <div class="admin-form__grid">
      <div class="admin-field">
        <label for="intro_label">Мітка секції переваг</label>
        <input id="intro_label" name="intro_label" value="<?= admin_e((string) ($content['home']['intro']['label'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="intro_title">Заголовок секції переваг</label>
        <input id="intro_title" name="intro_title" value="<?= admin_e((string) ($content['home']['intro']['title'] ?? '')) ?>">
      </div>
    </div>
    <div class="admin-field">
      <label for="intro_text">Текст секції переваг</label>
      <textarea id="intro_text" name="intro_text"><?= admin_e((string) ($content['home']['intro']['text'] ?? '')) ?></textarea>
    </div>
    <div class="admin-field">
      <label for="benefits_raw">Переваги</label>
      <textarea id="benefits_raw" name="benefits_raw"><?= admin_e($benefitsRaw) ?></textarea>
      <p class="admin-hint">Один рядок = `Заголовок | Опис`.</p>
    </div>
  </section>

  <section class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h2>Історія та CTA</h2>
        <p class="admin-panel__hint">Блок про садибу та фінальний заклик, який гість бачить перед бронюванням.</p>
      </div>
    </div>
    <div class="admin-form__grid">
      <div class="admin-field">
        <label for="story_label">Мітка секції</label>
        <input id="story_label" name="story_label" value="<?= admin_e((string) ($content['home']['story']['label'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="story_title">Заголовок секції</label>
        <input id="story_title" name="story_title" value="<?= admin_e((string) ($content['home']['story']['title'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="story_image">Фото секції</label>
        <input id="story_image" name="story_image" value="<?= admin_e((string) ($content['home']['story']['image'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="story_image_alt">Alt для фото</label>
        <input id="story_image_alt" name="story_image_alt" value="<?= admin_e((string) ($content['home']['story']['image_alt'] ?? '')) ?>">
      </div>
    </div>
    <div class="admin-field">
      <label for="story_text">Текст про садибу</label>
      <textarea id="story_text" name="story_text"><?= admin_e((string) ($content['home']['story']['text'] ?? '')) ?></textarea>
    </div>
    <div class="admin-form__grid">
      <div class="admin-field">
        <label for="cta_title">CTA заголовок</label>
        <input id="cta_title" name="cta_title" value="<?= admin_e((string) ($content['home']['cta']['title'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="cta_text">CTA текст</label>
        <input id="cta_text" name="cta_text" value="<?= admin_e((string) ($content['home']['cta']['text'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="cta_primary_text">CTA кнопка 1</label>
        <input id="cta_primary_text" name="cta_primary_text" value="<?= admin_e((string) ($content['home']['cta']['primary_text'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="cta_primary_url">CTA кнопка 1 URL</label>
        <input id="cta_primary_url" name="cta_primary_url" value="<?= admin_e((string) ($content['home']['cta']['primary_url'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="cta_secondary_text">CTA кнопка 2</label>
        <input id="cta_secondary_text" name="cta_secondary_text" value="<?= admin_e((string) ($content['home']['cta']['secondary_text'] ?? '')) ?>">
      </div>
      <div class="admin-field">
        <label for="cta_secondary_url">CTA кнопка 2 URL</label>
        <input id="cta_secondary_url" name="cta_secondary_url" value="<?= admin_e((string) ($content['home']['cta']['secondary_url'] ?? '')) ?>">
      </div>
    </div>
  </section>

  <section class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h2>FAQ</h2>
        <p class="admin-panel__hint">Один рядок дорівнює одному питанню. Формат: `Питання | Відповідь`.</p>
      </div>
    </div>
    <div class="admin-field">
      <label for="faq_raw">Питання та відповіді</label>
      <textarea id="faq_raw" name="faq_raw"><?= admin_e($faqRaw) ?></textarea>
      <p class="admin-hint admin-code-note">Один рядок = `Питання | Відповідь`.</p>
    </div>
  </section>

  <section class="admin-panel">
    <div class="admin-panel__head">
      <div>
        <h2>SEO</h2>
        <p class="admin-panel__hint">Короткі мета-дані для пошуку та соцмереж. Без спаму і без дублювання ключових слів.</p>
      </div>
    </div>
    <?php foreach (['home' => 'Головна', 'about' => 'Про нас', 'gallery' => 'Галерея', 'reviews' => 'Відгуки', 'booking' => 'Бронювання'] as $pageKey => $label): ?>
      <div class="admin-form__grid">
        <div class="admin-field">
          <label for="seo_<?= admin_e($pageKey) ?>_title"><?= admin_e($label) ?>: Title</label>
          <input id="seo_<?= admin_e($pageKey) ?>_title" name="seo_<?= admin_e($pageKey) ?>_title" value="<?= admin_e((string) ($content['seo'][$pageKey]['title'] ?? '')) ?>">
        </div>
        <div class="admin-field">
          <label for="seo_<?= admin_e($pageKey) ?>_description"><?= admin_e($label) ?>: Description</label>
          <input id="seo_<?= admin_e($pageKey) ?>_description" name="seo_<?= admin_e($pageKey) ?>_description" value="<?= admin_e((string) ($content['seo'][$pageKey]['description'] ?? '')) ?>">
        </div>
      </div>
    <?php endforeach; ?>
  </section>

  <div class="admin-actions">
    <button class="admin-button" type="submit">Зберегти контент</button>
    <a class="admin-button--ghost" href="/" target="_blank" rel="noopener noreferrer">Подивитися результат</a>
  </div>
</form>
<?php
admin_render_footer();

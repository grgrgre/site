# 🏠 SvityazHOME

**Офіційний сайт садиби «SvityazHOME» біля озера Світязь**

> Затишна сімейна садиба біля озера Світязь. Комфортні номери для 2–8 гостей, 5–10 хв до пляжу, зелена територія з альтанками, BBQ та Wi-Fi.

---

## 📂 Структура проєкту

```
svityazhome.com.ua/
│
├── index.html                  # Головна сторінка
├── 404.html                    # Сторінка помилки 404
├── manifest.json               # PWA маніфест
├── robots.txt                  # Правила для пошуковиків
├── sitemap.xml                 # Карта сайту (XML)
├── .htaccess                   # Конфігурація Apache
├── .editorconfig               # Стандарти форматування коду
│
├── about/                      # Сторінка «Про нас»
│   └── index.html
│
├── rooms/                      # Список номерів
│   ├── index.html
│   └── room-{1..20}/           # Окремі сторінки номерів
│       └── index.html
│
├── booking/                    # Бронювання
│   └── index.html
│
├── gallery/                    # Фотогалерея
│   └── index.html
│
├── reviews/                    # Відгуки гостей
│   └── index.html
│
├── ozero-svityaz/              # Інформація про озеро Світязь
│   └── index.html
│
├── assets/                     # Статичні ресурси
│   ├── css/
│   │   └── site.css            # Єдиний CSS для публічних сторінок
│   ├── js/
│   │   ├── app.js              # Головний JavaScript
│   │   └── easter-egg.js       # 🎣 Пасхалка «Рибалка на Світязі»
│   └── images/
│       ├── favicon/            # Іконки PWA та favicon
│       ├── icons/              # SVG-іконки (соцмережі, контакти)
│       └── placeholders/       # Заглушки (no-image)
│
├── api/                        # PHP API (бекенд)
│   ├── config.php              # Конфігурація, паролі, ліміти
│   ├── database.php            # SQLite обгортка
│   ├── reviews.php             # API відгуків
│   ├── rooms.php               # API номерів
│   ├── lake.php                # API довідки про озеро Світязь
│   ├── files.php               # API завантаження файлів
│   ├── chat.php                # AI-чат асистент
│   ├── health.php              # Health endpoint для моніторингу
│   └── telegram.php            # Telegram webhook (модерація/бронювання)
│
├── storage/                    # 💾 Дані (НЕ перезаливати при оновленні!)
│   ├── .htaccess               # Захист від прямого доступу
│   ├── data/
│   │   ├── svityazhome.db      # SQLite база даних
│   │   ├── reviews.json        # Відгуки (legacy JSON)
│   │   ├── room-images.json    # Прив'язка фото до номерів
│   │   ├── lake-guide.json     # Структурована довідка про Світязь
│   │   ├── content-changes.json # Зміни контенту з адмінки
│   │   └── rooms/              # JSON-дані номерів
│   │       └── room-{1..20}.json
│   └── uploads/                # Завантажені файли
│       ├── reviews/            # Фото від гостей
│       ├── site/               # Контентні фото головної сторінки
│       ├── yard/               # Контентні фото території
│       └── rooms/              # Фото номерів (room-1/ ... room-20/)
│
├── svh-ctrl-x7k9/             # 🔐 Адмін-панель (секретний шлях)
│   ├── login.php               # Вхід в адмінку
│   ├── index.php               # Дашборд
│   ├── content.php             # Контент і SEO
│   ├── rooms.php               # Номери
│   ├── reviews.php             # Відгуки
│   ├── contacts.php            # Контакти
│   ├── gallery.php             # Галерея
│   ├── requests.php            # Заявки
│   └── assets/
│       ├── admin.css           # Стилі адмінки
│
└── tools/                      # 🧰 Локальні утиліти та інфра-скрипти
    ├── autonomy.sh
    ├── autonomy-tasks.php
    ├── predeploy-check.sh
    ├── server-host.sh
    ├── server-health.sh
    ├── server-host-stop.sh
    ├── telegram-webhook.sh
    ├── task-done.sh
    └── fix_encoding.py
```

---

## 🛠 Технології

| Компонент | Технологія |
|-----------|-----------|
| **Frontend** | HTML5, CSS3 (Custom Properties), Vanilla JS |
| **Backend** | PHP 7.4+, SQLite |
| **PWA** | Service Worker, Web App Manifest |
| **Теми** | Light / Dark (автоматично + перемикач) |
| **Анімації** | CSS transitions, IntersectionObserver |
| **Шрифти** | Google Fonts (Fraunces, Manrope) |
| **Аналітика** | Google Analytics (G-MHTVH59XZD) |
| **SEO** | Schema.org, Open Graph, Twitter Cards, Sitemap |

---

## 🚀 Розгортання

### Вимоги до хостингу

- **Apache** з mod_rewrite, mod_expires, mod_deflate, mod_headers
- **PHP** 7.4+ з розширеннями: SQLite3, PDO, JSON, mbstring
- **HTTPS** сертифікат (Let's Encrypt або хостинг-провайдер)

### Інструкція

1. Завантажте всі файли на хостинг у кореневу папку домену
2. Переконайтесь, що `.htaccess` активний (AllowOverride All)
3. Папка `storage/` повинна мати права на запис (`chmod 755`)
4. Папка `storage/uploads/` — права `chmod 755`

### FTPS deploy (стійкий доступ)

Щоб не вводити FTP-доступи заново після збоїв сесії, використовуйте локальний профіль `.env.ftps` + утиліту `tools/ftps.sh`.

1. Створіть `.env.ftps` (файл уже ігнорується git через правило `.env.*`):

```bash
cat > .env.ftps <<'EOF'
FTPS_HOST=svityazhome.com.ua
FTPS_PORT=21
FTPS_PROTOCOL=ftps
FTPS_USER=botdeploy@svityazhome.com.ua
FTPS_PASSWORD="your-password"
FTPS_REMOTE_ROOT=/public_html
# Опційно: якщо сертифікат FTP-хоста не збігається з доменом
FTPS_INSECURE=0
EOF
chmod 600 .env.ftps
```

2. Базові команди:

```bash
./tools/ftps.sh check
./tools/ftps.sh ls .
./tools/ftps.sh put ./index.html index.html
./tools/ftps.sh get index.html ./tmp/index.remote.html
```

3. Захист від випадкового витоку:

- `put` блокує upload із чутливих шляхів: `*.env*`, `.git/`, `.local/`, `storage/backups/`, `storage/logs/`, `deploy/`.
- Якщо хостинг повертає TLS-помилку на домені сайту, тимчасово можна встановити `FTPS_INSECURE=1` (краще виправити FTP host на той, що відповідає сертифікату).
- Якщо бачиш `Server denied you to change to the given directory`, встанови `FTPS_REMOTE_ROOT=/` (типово для chroot FTP-акаунтів).
- Після завершення робіт змініть пароль FTP-акаунта в панелі хостингу.

### ⚠️ Важливо при оновленні сайту

> **НЕ перезаливайте папку `storage/`!**
> Там зберігається база даних, відгуки та завантажені файли гостей.

### 🔐 Як зробити storage недоторканим

- У `storage/.htaccess` вже ввімкнено deny для чутливих файлів і backup-архівів `.zip`.
- `storage/data/reviews.json` закритий для прямого веб-доступу (тільки через API).
- У корені сайту блокуйте прямий доступ до `*.zip`, `*.sql`, `*.db` та інших дампів.
- Не відкривайте `storage/` публічно через CDN/файловий менеджер хостингу.
- Рекомендовані права:
  - папки `storage/`, `storage/data/`, `storage/uploads/`, `storage/backups/`: `750` або `755`
  - файли в `storage/data/`: `640`
- Оновлюйте сайт без перезапису каталогу `storage/`.

### 💾 Автобекап storage (ZIP)

- Адмінка тепер підтримує створення ZIP-бекапу `storage` (data/uploads/logs) з кнопки **«Бекап storage»**.
- Є автоматичний періодичний backup при зміні даних (щоб не втратити контент між ручними бекапами).
- Архіви зберігаються в `storage/backups/` і завантажуються тільки через авторизований API адмінки.
- У модалі backup також є **завантаження ZIP на сайт** (імпорт у `storage/backups/`) і подальше безпечне скачування.
- Додано відновлення з ZIP: адмінка створює pre-restore backup і виконує авторозпакування в `storage/data`, `storage/uploads`, `storage/logs`.
- Для upload великих ZIP перевірте PHP-ліміти `post_max_size` та `upload_max_filesize`.
- Нова схема імен backup (більш читабельна):
  - `storage-backup-YYYY-MM-DD_HH-mm-ss__reason.zip`
  - приклад: `storage-backup-2026-03-03_09-15-00__manual-admin.zip`
  - в адмінці показується людський тип/причина: `Ручний`, `Авто`, `Перед відновленням`, `Імпорт`.

---

## 💻 Локальна розробка (Linux + Windows)

### 🏗️ Локальний хост серверного рівня (Nginx + PHP-FPM)

Це режим, максимально наближений до production:

- Nginx reverse proxy + PHP-FPM (без вбудованого `php -S`)
- gzip, security headers, статичний кеш
- `client_max_body_size=512M` для backup ZIP
- healthcheck контейнерів

1. Запустіть стек:

```bash
./tools/server-host.sh
```

2. Перевірте healthcheck:

```bash
./tools/server-health.sh
```

3. Зупинка:

```bash
./tools/server-host-stop.sh
```

Скрипт працює у двох режимах:

- якщо є Docker: `Nginx + PHP-FPM` (compose),
- якщо Docker відсутній: автоматично використає локальний `frankenphp`.

За замовчуванням URL: `http://127.0.0.1:8080/`  
Порт можна змінити: `SERVER_HTTP_PORT=9090 ./tools/server-host.sh`

### Linux

```bash
./localhost.sh
```

- За замовчуванням: `http://127.0.0.1:8000/`
- Автоматично відкривається: `http://127.0.0.1:8000/local.html`
- Можна змінити порт/хост:

```bash
HOST=0.0.0.0 PORT=8080 ./localhost.sh
```

- Можна змінити стартову сторінку:

```bash
START_PAGE=index.html ./localhost.sh
```

### Windows

```bat
localhost.bat
```

- Скрипт автоматично бере корінь проєкту з поточної папки.
- `php` береться з `PATH`, а якщо не знайдено, використовується `C:\php\php.exe`.

### 🔔 Звук після завершення команди (Linux)

```bash
./tools/task-done.sh --test
./tools/task-done.sh ./localhost.sh
```

- Скрипт програє звук і показує desktop-нотифікацію після завершення команди.
- Можна поставити свій звук:

```bash
DONE_SOUND_FILE=/path/to/your-sound.oga ./tools/task-done.sh ./localhost.sh
```

- За замовчуванням є сповіщення на старт і на завершення команди.
- Вимкнути сповіщення про старт:

```bash
DONE_NOTIFY_START=0 ./tools/task-done.sh ./localhost.sh
```

- Повністю вимкнути desktop-сповіщення (залишити лише звук):

```bash
DONE_NOTIFY=0 ./tools/task-done.sh ./localhost.sh
```

- Для GNOME можна вказати desktop-entry (щоб банери стабільніше показувались):

```bash
DONE_NOTIFY_DESKTOP_ENTRY=org.gnome.Terminal ./tools/task-done.sh ./localhost.sh
```

### 🔒 Ранній доступ до сайту (через інтерфейс сайту)

Проєкт використовує один env-файл: `.env`.

Створення `.env`:

```bash
cp .env.example .env
```

У `.env` можна увімкнути пароль раннього доступу, який вводиться в модальному вікні на сайті (без браузерного Basic Auth popup):

```bash
SITE_EARLY_ACCESS_ENABLED=1
SITE_EARLY_ACCESS_PASSWORD="change-me-strong-password"
SITE_EARLY_ACCESS_ALLOW_ADMIN_PASSWORD=1
SITE_EARLY_ACCESS_TTL=86400
```

- `SITE_EARLY_ACCESS_PASSWORD` — окремий пароль раннього доступу.
- `SITE_EARLY_ACCESS_ALLOW_ADMIN_PASSWORD` — дозволяє вхід у ранній доступ також паролем адмінки.
- `SITE_EARLY_ACCESS_TTL` — тривалість сесії доступу в секундах.
- Якщо нові `SITE_EARLY_ACCESS_*` не задані, автоматично використаються legacy `SITE_LOCK_ENABLED` і `SITE_LOCK_PASSWORD`.

Після зміни перезапусти `./localhost.sh`.

### ✅ Перевірка перед деплоєм

```bash
./tools/predeploy-check.sh
```

- Скрипт перевіряє PHP/JSON/Python/Shell/HTML та базові HTTP-роути.
- За замовчуванням: `BASE_URL=http://127.0.0.1:8000`.
- Опційно можна увімкнути booking smoke-тест:

```bash
RUN_BOOKING_SMOKE=1 ./tools/predeploy-check.sh
```

---

## 🤖 Автономний режим

Скрипти для роботи сайту без ручного контролю:

```bash
./tools/autonomy.sh check
./tools/autonomy.sh nightly
```

- `check`: локальний health + перевірка публічного `/api/health.php`
- `nightly`: плановий backup + `VACUUM` SQLite + health-check
- Telegram-алерти (опційно): `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID` у `.env`

### Авто-розклад (cron)

Показати готовий блок:

```bash
./tools/autonomy.sh print-cron
```

Встановити в crontab поточного користувача:

```bash
./tools/autonomy.sh install-cron
```

Або використати шаблон: `deploy/autonomy-cron.example`.

---

## 🤖 Telegram бот (модерація + бронювання)

Бот працює через приватний webhook: `/api/telegram.php`.

Можливості:
- модерація відгуків: `pending`, `approve`, `reject`
- додавання публічного відгуку: `add_review`
- перегляд бронювань: `bookings`, `booking`
- відповідь гостю на email з заявки: `reply`

### Env для бота

У `.env`:

```bash
TELEGRAM_BOT_TOKEN="..."
TELEGRAM_ADMIN_CHAT_IDS="123456789,-1001234567890"
TELEGRAM_WEBHOOK_SECRET="..."
TELEGRAM_WEBHOOK_URL="https://svityazhome.com.ua/api/telegram.php"
TELEGRAM_ADMIN_ACCESS_PASSWORD="strong-password"
TELEGRAM_NOTIFY_REVIEWS=1
TELEGRAM_NOTIFY_QUESTIONS=1
TELEGRAM_NOTIFY_BOOKINGS=1
TELEGRAM_NOTIFY_ADMIN_ACTIONS=1
```

- `TELEGRAM_ADMIN_CHAT_IDS` — список дозволених chat_id (через кому).
- якщо `TELEGRAM_ADMIN_CHAT_IDS` порожній, використовується `TELEGRAM_CHAT_ID`.
- `TELEGRAM_WEBHOOK_SECRET` перевіряється по заголовку `X-Telegram-Bot-Api-Secret-Token`.
- `TELEGRAM_ADMIN_ACCESS_PASSWORD` — пароль для входу з нового пристрою через `/login`.
- `TELEGRAM_NOTIFY_*` — Telegram-сповіщення про нові заявки/відгуки/питання та дії в адмінці.

### Підключення webhook

```bash
./tools/telegram-webhook.sh set
./tools/telegram-webhook.sh set-commands
./tools/telegram-webhook.sh info
```

### Команди бота

- Простий режим (без технічних команд): натискайте кнопки `📋 Відгуки`, `📅 Заявки`, `🔎 Обрати заявку`, `✉️ Відповісти на заявку`, `🟢 Заїзд сьогодні`, `🟠 Заїзд завтра`, `🔵 Виїзд сьогодні`, `🏠 Змінити номер`, `🔍 Пошук заявки`, `📊 Сьогодні`, `🧾 Останні дії`, `ℹ️ Статус`.
- Також бот розуміє звичайний текст: `Відгуки`, `Заявки`, `BKYYYYMMDD-XXXXXX`, `Схвалити 123`, `Відхилити 123`.
- У режимі заявок ID вводити не обовʼязково: відкрийте заявку кнопкою, натисніть `✉️ Відповісти на заявку`, далі просто напишіть текст.
- Для зміни номера без ID: відкрийте заявку кнопкою, натисніть `🏠 Змінити номер`, далі виберіть номер `1-20` кнопкою (або надішліть числом).
- Для статусу заявки без ID: відкрийте заявку і натисніть `✅ Завершити заявку` або `🆕 Повернути в нові`.
- Пошук без ID: натисніть `🔍 Пошук заявки` і надішліть телефон/імʼя/ID (наприклад `Пошук 093...`).
- Канал відповіді обирається автоматично: `email` (якщо є), інакше `телефон` (кнопка дзвінка + підготовлений текст).
- `/pending [N]`
- `/whoami`
- `/approve ID`
- `/reject ID`
- `/add_review Ім'я | 5 | topic | Текст | room_id`
- `/bookings [N]`
- `/booking BKYYYYMMDD-XXXXXX`
- `/reply BKYYYYMMDD-XXXXXX | текст` (або на 2 рядки: перший рядок ID, другий текст; або через кнопки без ID)
- `/change_room BKYYYYMMDD-XXXXXX | room-5` (або `Змінити номер BK... 5`)
- `/find запит` (пошук заявки за телефоном/іменем/ID)
- `/today` (короткий звіт за сьогодні)
- `/arrivals [today|tomorrow]` (заїзди за день)
- `/departures [today]` (виїзди за день)
- `/actions [N]` (останні дії в адмінці)
- `/login пароль` (вхід з нового пристрою)
- `/logout`
- `/status`
- `/help`
- `/menu`

---

## 🔐 Адмін-панель

- **URL:** `https://svityazhome.com.ua/svh-ctrl-x7k9/`
- **Вхід:** через `login.php` із сесійною PHP-авторизацією
- **Пароль:** задається через `ADMIN_PASSWORD` у `.env` або `storage/data/admin-auth.json`
- **Захист:** рекомендується додатковий `.htaccess`/host-level захист для секретного шляху

### Можливості адмінки

- Управління головним контентом і SEO
- Управління номерами (ціни, опис, зручності)
- Управління фото галереї
- Перегляд та модерація відгуків
- Редагування контактів
- Перегляд і обробка booking request заявок

### 🗂️ Дані номерів з JSON (без ручного редагування HTML)

- Джерело даних номерів: `storage/data/rooms/room-{1..20}.json`.
- Фото номерів зберігаються у `storage/uploads/rooms/` (публічна data-зона для медіа).
- При першому запиті до `/api/rooms.php?action=list` JSON автоматично ініціалізується з існуючих `rooms/room-*/index.html` (one-time bootstrap).
- Після цього контент номерів на сайті береться з JSON через API, тому для оновлень не треба вручну правити HTML сторінки номерів.
- JSON редагується через PHP-адмінку: `/svh-ctrl-x7k9/rooms.php`.

---

## 🎣 Пасхалка

Секретна гра «Рибалка на Світязі»:

- **Клавіатура:** наберіть `fish`, `світязь` або `svityaz`
- **Мобілка:** 5 швидких тапів по футеру

---

## 📋 API Ендпоінти

| Метод | URL | Опис |
|-------|-----|------|
| GET | `/api/reviews.php` | Список відгуків |
| POST | `/api/reviews.php` | Додати відгук |
| GET | `/api/rooms.php` | Дані номерів |
| POST | `/api/files.php` | Завантажити файл |
| POST | `/api/chat.php` | AI-чат запит |
| GET | `/api/health.php` | Статус сервісу (моніторинг) |
| GET/POST | `/api/telegram.php` | Приватний webhook Telegram-бота |
| POST | `/api/telegram-admin.php` | Admin-дії для Telegram-бота (ресет) |
| GET/POST | `/api/access.php` | Перевірка пароля раннього доступу |

---

## 📱 Контакти

- **Сайт:** [svityazhome.com.ua](https://svityazhome.com.ua)
- **Телефон:** +380 93 857 85 40
- **Instagram:** [@svityazhome](https://instagram.com/svityazhome)
- **TikTok:** [@svityazhome](https://tiktok.com/@svityazhome)
- **Адреса:** вул. Лісова 55, с. Світязь, Волинська область

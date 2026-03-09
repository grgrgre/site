<?php
/**
 * SvityazHOME Database Class
 * SQLite database for reviews and admin data
 */

class Database {
    private static $instance = null;
    private $pdo;
    private $dbPath;

    private function __construct() {
        $this->dbPath = dirname(__DIR__) . '/storage/data/svityazhome.db';
        $this->ensureDirectory();
        $this->connect();
        $this->createTables();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function ensureDirectory() {
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function connect() {
        try {
            $this->pdo = new PDO('sqlite:' . $this->dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            // Security: enable foreign keys
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            // Concurrency hardening for SQLite under write bursts.
            try {
                $this->pdo->exec('PRAGMA journal_mode = WAL');
            } catch (PDOException $walError) {
                error_log('SQLite WAL mode is unavailable: ' . $walError->getMessage());
            }
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        } catch (PDOException $e) {
            error_log('Database connection error: ' . $e->getMessage());
            throw new Exception('Database connection failed');
        }
    }

    private function createTables() {
        // Reviews table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS reviews (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                text TEXT NOT NULL,
                rating INTEGER DEFAULT 5,
                topic TEXT DEFAULT 'general',
                source TEXT DEFAULT 'site',
                status TEXT DEFAULT 'pending',
                images TEXT DEFAULT '[]',
                ip_address TEXT,
                user_agent TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                moderated_at DATETIME,
                moderated_by TEXT
            )
        ");

        // Questions table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS questions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                contact TEXT,
                topic TEXT DEFAULT 'other',
                text TEXT NOT NULL,
                status TEXT DEFAULT 'new',
                ip_address TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                answered_at DATETIME,
                answer TEXT
            )
        ");

        // Admin sessions table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS admin_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token TEXT UNIQUE NOT NULL,
                ip_address TEXT,
                user_agent TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                last_activity DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Rate limiting table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_address TEXT NOT NULL,
                action TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Admin log table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS admin_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                action TEXT NOT NULL,
                details TEXT,
                ip_address TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Booking requests table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS bookings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                booking_id TEXT UNIQUE NOT NULL,
                name TEXT NOT NULL,
                phone TEXT NOT NULL,
                email TEXT,
                checkin_date TEXT NOT NULL,
                checkout_date TEXT NOT NULL,
                guests INTEGER NOT NULL,
                room_code TEXT NOT NULL,
                message TEXT,
                consent INTEGER NOT NULL DEFAULT 0,
                honeypot_triggered INTEGER NOT NULL DEFAULT 0,
                ip_address TEXT,
                user_agent TEXT,
                email_sent INTEGER NOT NULL DEFAULT 0,
                email_transport TEXT,
                email_error TEXT,
                status TEXT NOT NULL DEFAULT 'new',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // AI chat runtime settings (single-row config).
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS ai_settings (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                model TEXT NOT NULL DEFAULT 'gpt-4o-mini',
                system_prompt TEXT NOT NULL,
                knowledge_base TEXT NOT NULL DEFAULT '',
                temperature REAL NOT NULL DEFAULT 0.8,
                max_tokens INTEGER NOT NULL DEFAULT 250,
                presence_penalty REAL NOT NULL DEFAULT 0.6,
                frequency_penalty REAL NOT NULL DEFAULT 0.3,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // AI prompt versions history.
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS ai_prompt_versions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                settings_hash TEXT NOT NULL,
                model TEXT NOT NULL,
                system_prompt TEXT NOT NULL,
                knowledge_base TEXT NOT NULL DEFAULT '',
                temperature REAL NOT NULL,
                max_tokens INTEGER NOT NULL,
                presence_penalty REAL NOT NULL,
                frequency_penalty REAL NOT NULL,
                change_summary TEXT NOT NULL DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Create indexes
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_reviews_status ON reviews(status)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_reviews_topic ON reviews(topic)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_rate_limits_ip ON rate_limits(ip_address, action)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_admin_sessions_token ON admin_sessions(token)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_bookings_booking_id ON bookings(booking_id)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_bookings_created_at ON bookings(created_at)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_ai_prompt_versions_created_at ON ai_prompt_versions(created_at)");

        $this->ensureAiSettingsRow();
    }

    public function getPdo() {
        return $this->pdo;
    }

    // ========== REVIEWS ==========
    
    public function getApprovedReviews($topic = null, $limit = 100) {
        $sql = "SELECT * FROM reviews WHERE status = 'approved'";
        $params = [];
        
        if ($topic && $topic !== 'all') {
            $sql .= " AND topic = :topic";
            $params[':topic'] = $topic;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT :limit";
        
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $reviews = $stmt->fetchAll();
        
        // Decode images JSON
        foreach ($reviews as &$review) {
            $review['images'] = json_decode($review['images'], true) ?: [];
        }
        
        return $reviews;
    }

    public function getPendingReviews() {
        $stmt = $this->pdo->query("SELECT * FROM reviews WHERE status = 'pending' ORDER BY created_at DESC");
        $reviews = $stmt->fetchAll();
        
        foreach ($reviews as &$review) {
            $review['images'] = json_decode($review['images'], true) ?: [];
        }
        
        return $reviews;
    }

    public function getAllReviews() {
        $stmt = $this->pdo->query("SELECT * FROM reviews ORDER BY created_at DESC");
        $reviews = $stmt->fetchAll();
        
        foreach ($reviews as &$review) {
            $review['images'] = json_decode($review['images'], true) ?: [];
        }
        
        return $reviews;
    }

    public function addReview($name, $text, $rating, $topic, $images = [], $ip = null, $userAgent = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO reviews (name, text, rating, topic, images, ip_address, user_agent, status)
            VALUES (:name, :text, :rating, :topic, :images, :ip, :ua, 'pending')
        ");
        
        $stmt->execute([
            ':name' => $name,
            ':text' => $text,
            ':rating' => $rating,
            ':topic' => $topic,
            ':images' => json_encode($images),
            ':ip' => $ip,
            ':ua' => $userAgent
        ]);
        
        return $this->pdo->lastInsertId();
    }

    public function approveReview($id) {
        $stmt = $this->pdo->prepare("
            UPDATE reviews 
            SET status = 'approved', moderated_at = CURRENT_TIMESTAMP 
            WHERE id = :id
        ");
        return $stmt->execute([':id' => $id]);
    }

    public function rejectReview($id) {
        $stmt = $this->pdo->prepare("
            UPDATE reviews 
            SET status = 'rejected', moderated_at = CURRENT_TIMESTAMP 
            WHERE id = :id
        ");
        return $stmt->execute([':id' => $id]);
    }

    public function deleteReview($id) {
        $stmt = $this->pdo->prepare("DELETE FROM reviews WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function getReviewById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM reviews WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $review = $stmt->fetch();
        
        if ($review) {
            $review['images'] = json_decode($review['images'], true) ?: [];
        }
        
        return $review;
    }

    // ========== QUESTIONS ==========
    
    public function getQuestions($status = null) {
        $sql = "SELECT * FROM questions";
        $params = [];
        
        if ($status) {
            $sql .= " WHERE status = :status";
            $params[':status'] = $status;
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function addQuestion($name, $contact, $topic, $text, $ip = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO questions (name, contact, topic, text, ip_address)
            VALUES (:name, :contact, :topic, :text, :ip)
        ");
        
        return $stmt->execute([
            ':name' => $name,
            ':contact' => $contact,
            ':topic' => $topic,
            ':text' => $text,
            ':ip' => $ip
        ]);
    }

    public function answerQuestion($id, $answer) {
        $stmt = $this->pdo->prepare("
            UPDATE questions 
            SET answer = :answer, answered_at = CURRENT_TIMESTAMP, status = 'answered' 
            WHERE id = :id
        ");
        return $stmt->execute([':id' => $id, ':answer' => $answer]);
    }

    public function deleteQuestion($id) {
        $stmt = $this->pdo->prepare("DELETE FROM questions WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    // ========== RATE LIMITING ==========
    
    public function checkRateLimit($ip, $action, $maxAttempts = 5, $windowSeconds = 3600) {
        // Keep enough history for larger windows (daily/monthly quotas).
        $cleanupWindow = max(86400, (int) $windowSeconds * 2);
        $cleanupStmt = $this->pdo->prepare("
            DELETE FROM rate_limits
            WHERE created_at < datetime('now', '-' || :cleanup || ' seconds')
        ");
        $cleanupStmt->execute([':cleanup' => $cleanupWindow]);
        
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count FROM rate_limits 
            WHERE ip_address = :ip 
            AND action = :action 
            AND created_at > datetime('now', '-' || :window || ' seconds')
        ");
        
        $stmt->execute([
            ':ip' => $ip,
            ':action' => $action,
            ':window' => $windowSeconds
        ]);
        
        $result = $stmt->fetch();
        return $result['count'] < $maxAttempts;
    }

    public function recordRateLimit($ip, $action) {
        $stmt = $this->pdo->prepare("
            INSERT INTO rate_limits (ip_address, action) VALUES (:ip, :action)
        ");
        return $stmt->execute([':ip' => $ip, ':action' => $action]);
    }

    // ========== ADMIN SESSIONS ==========
    
    public function createAdminSession($ip, $userAgent) {
        // Prefix invalidates legacy leaked tokens that were plain hex.
        $token = 'svh2_' . bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO admin_sessions (token, ip_address, user_agent, expires_at)
            VALUES (:token, :ip, :ua, :expires)
        ");
        
        $stmt->execute([
            ':token' => $token,
            ':ip' => $ip,
            ':ua' => $userAgent,
            ':expires' => $expiresAt
        ]);
        
        return $token;
    }

    public function validateAdminSession($token) {
        if (!is_string($token) || !preg_match('/^svh2_[a-f0-9]{64}$/', $token)) {
            return false;
        }

        $stmt = $this->pdo->prepare("
            SELECT * FROM admin_sessions 
            WHERE token = :token 
            AND expires_at > datetime('now')
        ");
        $stmt->execute([':token' => $token]);
        $session = $stmt->fetch();
        
        if ($session) {
            // Update last activity
            $this->pdo->prepare("
                UPDATE admin_sessions SET last_activity = CURRENT_TIMESTAMP WHERE token = :token
            ")->execute([':token' => $token]);
        }
        
        return $session !== false;
    }

    public function deleteAdminSession($token) {
        $stmt = $this->pdo->prepare("DELETE FROM admin_sessions WHERE token = :token");
        return $stmt->execute([':token' => $token]);
    }

    public function cleanExpiredSessions() {
        $this->pdo->exec("DELETE FROM admin_sessions WHERE expires_at < datetime('now')");
    }

    // ========== AI SETTINGS ==========

    private function defaultAiSettings(): array
    {
        return [
            'model' => 'gpt-4o-mini',
            'system_prompt' => "Ти — AI-керівник SvityazHOME. Твій стиль: харизматичний, лаконічний, з дрібкою іронії.\nТвоє завдання: продавати відпочинок, а не просто видавати довідку.\n\nПРАВИЛА:\n1. Пиши як людина: коротко, без слів \"надаємо\", \"здійснюємо\".\n2. Якщо клієнт згадує конкурентів, без токсичності пояснюй переваги SvityazHOME: спокій, чесні умови, сучасний сервіс.\n3. Будь чесним у фактах: до озера близько 800 м, це 10 хвилин пішки.\n4. Якщо не знаєш точної відповіді — скажи: \"Тут я в танку, набери власника, він розрулить: +380938578540\".\n5. Відповідай переважно українською, але підлаштовуйся під мову гостя.\n6. Не вигадуй ціни, правила та наявність номерів. Коли даних недостатньо — запропонуй уточнення телефоном.",
            'knowledge_base' => "Адреса: Світязь, вул. Лісова 55.\nТиха зона.\nWi-Fi по всій території.\nПарковка безкоштовна.\nЄ альтанки та зона BBQ.\nТелефон: +380938578540.",
            'temperature' => 0.8,
            'max_tokens' => 250,
            'presence_penalty' => 0.6,
            'frequency_penalty' => 0.3,
        ];
    }

    private function normalizeAiText(string $text, int $limit): string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $text));
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        if (mb_strlen($value) > $limit) {
            $value = mb_substr($value, 0, $limit);
        }
        return $value;
    }

    private function normalizeAiSettings(array $input): array
    {
        $defaults = $this->defaultAiSettings();
        $maxTokensHard = defined('OPENAI_CHAT_MAX_TOKENS_HARD') ? (int) OPENAI_CHAT_MAX_TOKENS_HARD : 1600;
        $maxTokensHard = max(128, $maxTokensHard);

        $modelRaw = trim((string) ($input['model'] ?? $defaults['model']));
        if (!preg_match('/^[a-zA-Z0-9._:-]{2,80}$/', $modelRaw)) {
            $modelRaw = (string) $defaults['model'];
        }

        $systemPrompt = $this->normalizeAiText((string) ($input['system_prompt'] ?? $defaults['system_prompt']), 16000);
        if ($systemPrompt === '') {
            $systemPrompt = (string) $defaults['system_prompt'];
        }

        $knowledgeBase = $this->normalizeAiText((string) ($input['knowledge_base'] ?? $defaults['knowledge_base']), 16000);

        $temperature = (float) ($input['temperature'] ?? $defaults['temperature']);
        $temperature = max(0.0, min(1.2, $temperature));

        $maxTokens = (int) ($input['max_tokens'] ?? $defaults['max_tokens']);
        $maxTokens = max(64, min($maxTokensHard, $maxTokens));

        $presencePenalty = (float) ($input['presence_penalty'] ?? $defaults['presence_penalty']);
        $presencePenalty = max(-2.0, min(2.0, $presencePenalty));

        $frequencyPenalty = (float) ($input['frequency_penalty'] ?? $defaults['frequency_penalty']);
        $frequencyPenalty = max(-2.0, min(2.0, $frequencyPenalty));

        return [
            'model' => $modelRaw,
            'system_prompt' => $systemPrompt,
            'knowledge_base' => $knowledgeBase,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'presence_penalty' => $presencePenalty,
            'frequency_penalty' => $frequencyPenalty,
        ];
    }

    private function aiSettingsHash(array $settings): string
    {
        $payload = json_encode([
            'model' => (string) ($settings['model'] ?? ''),
            'system_prompt' => (string) ($settings['system_prompt'] ?? ''),
            'knowledge_base' => (string) ($settings['knowledge_base'] ?? ''),
            'temperature' => (float) ($settings['temperature'] ?? 0),
            'max_tokens' => (int) ($settings['max_tokens'] ?? 0),
            'presence_penalty' => (float) ($settings['presence_penalty'] ?? 0),
            'frequency_penalty' => (float) ($settings['frequency_penalty'] ?? 0),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return sha1((string) $payload);
    }

    private function persistAiPromptVersion(array $safeSettings, string $changeSummary = ''): void
    {
        $hash = $this->aiSettingsHash($safeSettings);
        $lastStmt = $this->pdo->query("SELECT settings_hash FROM ai_prompt_versions ORDER BY id DESC LIMIT 1");
        $lastHash = is_object($lastStmt) ? (string) (($lastStmt->fetch()['settings_hash'] ?? '')) : '';
        if ($lastHash !== '' && hash_equals($lastHash, $hash)) {
            return;
        }

        $summary = $this->normalizeAiText($changeSummary, 500);
        $stmt = $this->pdo->prepare("
            INSERT INTO ai_prompt_versions (
                settings_hash, model, system_prompt, knowledge_base, temperature, max_tokens, presence_penalty, frequency_penalty, change_summary
            )
            VALUES (
                :settings_hash, :model, :system_prompt, :knowledge_base, :temperature, :max_tokens, :presence_penalty, :frequency_penalty, :change_summary
            )
        ");
        $stmt->execute([
            ':settings_hash' => $hash,
            ':model' => $safeSettings['model'],
            ':system_prompt' => $safeSettings['system_prompt'],
            ':knowledge_base' => $safeSettings['knowledge_base'],
            ':temperature' => $safeSettings['temperature'],
            ':max_tokens' => $safeSettings['max_tokens'],
            ':presence_penalty' => $safeSettings['presence_penalty'],
            ':frequency_penalty' => $safeSettings['frequency_penalty'],
            ':change_summary' => $summary,
        ]);
    }

    private function ensureAiSettingsRow(): void
    {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) AS cnt FROM ai_settings WHERE id = 1");
            $count = (int) (($stmt->fetch()['cnt'] ?? 0));
            if ($count > 0) {
                return;
            }
            $this->saveAiSettings($this->defaultAiSettings(), 'Ініціалізація дефолтного профілю AI');
        } catch (Throwable $e) {
            error_log('AI settings bootstrap failed: ' . $e->getMessage());
        }
    }

    public function getAiSettingsDefaults(): array
    {
        return $this->defaultAiSettings();
    }

    public function getAiSettings(): array
    {
        $this->ensureAiSettingsRow();
        $stmt = $this->pdo->query("SELECT * FROM ai_settings WHERE id = 1 LIMIT 1");
        $row = $stmt ? $stmt->fetch() : null;
        if (!is_array($row)) {
            return $this->normalizeAiSettings($this->defaultAiSettings());
        }

        $normalized = $this->normalizeAiSettings($row);
        $normalized['updated_at'] = (string) ($row['updated_at'] ?? '');
        return $normalized;
    }

    public function getAiPromptVersions(int $limit = 30): array
    {
        $safeLimit = max(1, min(200, (int) $limit));
        $stmt = $this->pdo->prepare("
            SELECT id, model, system_prompt, knowledge_base, temperature, max_tokens, presence_penalty, frequency_penalty, change_summary, created_at
            FROM ai_prompt_versions
            ORDER BY id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        $result = [];
        foreach ($rows as $row) {
            $normalized = $this->normalizeAiSettings((array) $row);
            $normalized['id'] = (int) ($row['id'] ?? 0);
            $normalized['change_summary'] = (string) ($row['change_summary'] ?? '');
            $normalized['created_at'] = (string) ($row['created_at'] ?? '');
            $result[] = $normalized;
        }
        return $result;
    }

    public function getAiPromptVersionById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT id, model, system_prompt, knowledge_base, temperature, max_tokens, presence_penalty, frequency_penalty, change_summary, created_at
            FROM ai_prompt_versions
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $normalized = $this->normalizeAiSettings($row);
        $normalized['id'] = (int) ($row['id'] ?? 0);
        $normalized['change_summary'] = (string) ($row['change_summary'] ?? '');
        $normalized['created_at'] = (string) ($row['created_at'] ?? '');
        return $normalized;
    }

    public function saveAiSettings(array $settings, string $changeSummary = ''): bool
    {
        $safe = $this->normalizeAiSettings($settings);
        $current = null;
        try {
            $currentRowStmt = $this->pdo->query("SELECT * FROM ai_settings WHERE id = 1 LIMIT 1");
            $currentRow = is_object($currentRowStmt) ? $currentRowStmt->fetch() : null;
            if (is_array($currentRow)) {
                $current = $this->normalizeAiSettings($currentRow);
            }
        } catch (Throwable $e) {
            $current = null;
        }

        $changed = true;
        if (is_array($current)) {
            $changed = !hash_equals($this->aiSettingsHash($current), $this->aiSettingsHash($safe));
        }

        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO ai_settings (
                id, model, system_prompt, knowledge_base, temperature, max_tokens, presence_penalty, frequency_penalty, updated_at
            )
            VALUES (
                1, :model, :system_prompt, :knowledge_base, :temperature, :max_tokens, :presence_penalty, :frequency_penalty, CURRENT_TIMESTAMP
            )
        ");

        $ok = $stmt->execute([
            ':model' => $safe['model'],
            ':system_prompt' => $safe['system_prompt'],
            ':knowledge_base' => $safe['knowledge_base'],
            ':temperature' => $safe['temperature'],
            ':max_tokens' => $safe['max_tokens'],
            ':presence_penalty' => $safe['presence_penalty'],
            ':frequency_penalty' => $safe['frequency_penalty'],
        ]);

        if ($ok && $changed) {
            try {
                $this->persistAiPromptVersion($safe, $changeSummary);
            } catch (Throwable $e) {
                error_log('AI prompt version save failed: ' . $e->getMessage());
            }
        }

        return $ok;
    }

    // ========== ADMIN LOG ==========

    private function telegramAdminChatIds(): array
    {
        $raw = '';
        if (defined('TELEGRAM_ADMIN_CHAT_IDS')) {
            $raw = trim((string) TELEGRAM_ADMIN_CHAT_IDS);
        }
        if ($raw === '' && defined('TELEGRAM_CHAT_ID')) {
            $raw = trim((string) TELEGRAM_CHAT_ID);
        }
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ids = [];
        foreach ($parts as $part) {
            $value = trim((string) $part);
            if ($value === '' || preg_match('/^-?\d{3,20}$/', $value) !== 1) {
                continue;
            }
            $ids[] = $value;
        }
        return array_values(array_unique($ids));
    }

    private function telegramRequest(string $apiMethod, array $params = []): ?array
    {
        if (!defined('TELEGRAM_BOT_TOKEN')) {
            return null;
        }

        $token = trim((string) TELEGRAM_BOT_TOKEN);
        if ($token === '') {
            return null;
        }

        $url = 'https://api.telegram.org/bot' . $token . '/' . $apiMethod;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_POSTFIELDS => http_build_query($params),
            ]);
            $raw = curl_exec($ch);
            if ($raw === false) {
                curl_close($ch);
                return null;
            }
            curl_close($ch);
            $decoded = json_decode((string) $raw, true);
            return is_array($decoded) ? $decoded : null;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($params),
                'timeout' => 12,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function shouldNotifyAdminAction(string $action): bool
    {
        if (defined('TELEGRAM_NOTIFY_ADMIN_ACTIONS') && TELEGRAM_NOTIFY_ADMIN_ACTIONS === false) {
            return false;
        }

        $normalized = strtolower(trim($action));
        if ($normalized === '' || $normalized === 'auth_login') {
            return false;
        }
        return true;
    }

    private function notifyAdminAction(string $action, ?string $details = null, ?string $ip = null): void
    {
        if (!$this->shouldNotifyAdminAction($action)) {
            return;
        }

        $chats = $this->telegramAdminChatIds();
        if (empty($chats)) {
            return;
        }

        $lines = [
            '🛠️ Зміна в адмінці',
            'Дія: ' . trim((string) $action),
        ];
        $detailsText = trim((string) ($details ?? ''));
        if ($detailsText !== '') {
            $lines[] = 'Деталі: ' . mb_substr($detailsText, 0, 400);
        }
        $ipText = trim((string) ($ip ?? ''));
        if ($ipText !== '') {
            $lines[] = 'IP: ' . $ipText;
        }
        $lines[] = 'Час: ' . date('Y-m-d H:i:s');
        $message = implode("\n", $lines);

        foreach ($chats as $chatId) {
            $this->telegramRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $message,
                'disable_web_page_preview' => 'true',
            ]);
        }
    }
    
    public function logAdminAction($action, $details = null, $ip = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO admin_log (action, details, ip_address)
            VALUES (:action, :details, :ip)
        ");
        $ok = $stmt->execute([
            ':action' => $action,
            ':details' => $details,
            ':ip' => $ip
        ]);
        if ($ok) {
            try {
                $this->notifyAdminAction((string) $action, $details !== null ? (string) $details : null, $ip !== null ? (string) $ip : null);
            } catch (Throwable $e) {
                error_log('Admin action Telegram notify failed: ' . $e->getMessage());
            }
        }
        return $ok;
    }

    public function getAdminLog($limit = 100) {
        $stmt = $this->pdo->prepare("SELECT * FROM admin_log ORDER BY created_at DESC LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ========== MIGRATION FROM JSON ==========
    
    public function migrateFromJson($jsonFile) {
        if (!file_exists($jsonFile)) {
            return false;
        }
        
        $data = json_decode(file_get_contents($jsonFile), true);
        if (!$data) {
            return false;
        }
        
        // Check if already migrated
        $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM reviews");
        if ($stmt->fetch()['count'] > 0) {
            return true; // Already has data
        }
        
        // Migrate approved reviews
        if (isset($data['approved'])) {
            foreach ($data['approved'] as $review) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO reviews (name, text, rating, topic, source, images, status, created_at)
                    VALUES (:name, :text, :rating, :topic, :source, :images, 'approved', :date)
                ");
                $stmt->execute([
                    ':name' => $review['name'] ?? 'Гість',
                    ':text' => $review['text'] ?? '',
                    ':rating' => $review['rating'] ?? 5,
                    ':topic' => $review['topic'] ?? 'general',
                    ':source' => $review['source'] ?? 'site',
                    ':images' => json_encode($review['images'] ?? []),
                    ':date' => $review['date'] ?? date('Y-m-d')
                ]);
            }
        }
        
        // Migrate pending reviews
        if (isset($data['pending'])) {
            foreach ($data['pending'] as $review) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO reviews (name, text, rating, topic, images, status, created_at)
                    VALUES (:name, :text, :rating, :topic, :images, 'pending', :date)
                ");
                $stmt->execute([
                    ':name' => $review['name'] ?? 'Гість',
                    ':text' => $review['text'] ?? '',
                    ':rating' => $review['rating'] ?? 5,
                    ':topic' => $review['topic'] ?? 'general',
                    ':images' => json_encode($review['images'] ?? []),
                    ':date' => $review['date'] ?? date('Y-m-d')
                ]);
            }
        }
        
        // Migrate questions
        if (isset($data['questions'])) {
            foreach ($data['questions'] as $q) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO questions (name, contact, topic, text, created_at)
                    VALUES (:name, :contact, :topic, :text, :date)
                ");
                $stmt->execute([
                    ':name' => $q['name'] ?? 'Гість',
                    ':contact' => $q['contact'] ?? '',
                    ':topic' => $q['topic'] ?? 'other',
                    ':text' => $q['text'] ?? '',
                    ':date' => $q['date'] ?? date('Y-m-d')
                ]);
            }
        }
        
        return true;
    }

    // ========== STATISTICS ==========
    
    public function getStats() {
        $stats = [];
        
        $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM reviews WHERE status = 'approved'");
        $stats['approved_reviews'] = $stmt->fetch()['count'];
        
        $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM reviews WHERE status = 'pending'");
        $stats['pending_reviews'] = $stmt->fetch()['count'];
        
        $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM questions WHERE status = 'new'");
        $stats['new_questions'] = $stmt->fetch()['count'];
        
        $stmt = $this->pdo->query("SELECT AVG(rating) as avg FROM reviews WHERE status = 'approved'");
        $stats['avg_rating'] = round($stmt->fetch()['avg'] ?? 5, 1);
        
        return $stats;
    }
}

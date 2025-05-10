<?php
/**
 * Quran Study Hub
 * Single PHP file web application for Quran study.
 * Author: Yasin Ullah
 * Country: Pakistan
 */

// --- Configuration ---
define('DB_PATH', __DIR__ . '/quran_study_hub.sqlite');
define('DATA_AM_PATH', __DIR__ . '/data.AM'); // Path to your data.AM file
define('SITE_NAME', 'Quran Study Hub');
define('ADMIN_EMAIL', 'admin@example.com'); // Default admin email for initial setup
define('ADMIN_PASSWORD', 'adminpassword'); // Default admin password (CHANGE THIS!)
define('PASSWORD_ALGORITHM', PASSWORD_BCRYPT);
define('CSRF_TOKEN_NAME', 'csrf_token');
define('SESSION_LIFETIME', 86400); // 1 day

// --- Database Initialization ---
function db_connect() {
    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $db;
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

function db_init($db) {
    $db->exec("PRAGMA foreign_keys = ON;");

    // Users table
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        email TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        role TEXT DEFAULT 'user', -- 'user', 'admin'
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // Quran Ayahs table
    $db->exec("CREATE TABLE IF NOT EXISTS ayahs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        surah_id INTEGER NOT NULL,
        ayah_number INTEGER NOT NULL,
        arabic_text TEXT NOT NULL,
        UNIQUE(surah_id, ayah_number)
    );");

    // Surahs table
    $db->exec("CREATE TABLE IF NOT EXISTS surahs (
        id INTEGER PRIMARY KEY,
        arabic_name TEXT NOT NULL,
        english_name TEXT NOT NULL,
        ayah_count INTEGER NOT NULL,
        revelation_type TEXT -- 'Meccan', 'Medinan'
    );");

    // Translations table
    $db->exec("CREATE TABLE IF NOT EXISTS translations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ayah_id INTEGER NOT NULL,
        language TEXT NOT NULL, -- e.g., 'ur', 'en'
        text TEXT NOT NULL,
        version_name TEXT NOT NULL,
        status TEXT DEFAULT 'pending', -- 'pending', 'approved', 'rejected'
        is_default BOOLEAN DEFAULT 0,
        contributor_id INTEGER, -- NULL for admin/imported
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ayah_id) REFERENCES ayahs(id) ON DELETE CASCADE,
        FOREIGN KEY (contributor_id) REFERENCES users(id) ON DELETE SET NULL
    );");

    // Tafasir table
    $db->exec("CREATE TABLE IF NOT EXISTS tafasir (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        surah_id INTEGER, -- NULL for whole Quran tafsir
        ayah_id INTEGER, -- NULL for Surah tafsir
        text TEXT NOT NULL,
        version_name TEXT NOT NULL,
        status TEXT DEFAULT 'pending', -- 'pending', 'approved', 'rejected'
        is_default BOOLEAN DEFAULT 0,
        contributor_id INTEGER, -- NULL for admin/imported
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (surah_id) REFERENCES surahs(id) ON DELETE CASCADE,
        FOREIGN KEY (ayah_id) REFERENCES ayahs(id) ON DELETE CASCADE,
        FOREIGN KEY (contributor_id) REFERENCES users(id) ON DELETE SET NULL
    );");

    // Word Meanings table
    $db->exec("CREATE TABLE IF NOT EXISTS word_meanings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ayah_id INTEGER NOT NULL,
        word_index INTEGER NOT NULL, -- 0-based index of the word in the Arabic text
        arabic_word TEXT NOT NULL,
        meaning TEXT NOT NULL,
        grammar_notes TEXT,
        version_name TEXT NOT NULL,
        status TEXT DEFAULT 'pending', -- 'pending', 'approved', 'rejected'
        is_default BOOLEAN DEFAULT 0,
        contributor_id INTEGER, -- NULL for admin/imported
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ayah_id) REFERENCES ayahs(id) ON DELETE CASCADE,
        FOREIGN KEY (contributor_id) REFERENCES users(id) ON DELETE SET NULL
    );");

    // Bookmarks table
    $db->exec("CREATE TABLE IF NOT EXISTS bookmarks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        ayah_id INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, ayah_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (ayah_id) REFERENCES ayahs(id) ON DELETE CASCADE
    );");

    // Private Notes table
    $db->exec("CREATE TABLE IF NOT EXISTS private_notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        ayah_id INTEGER NOT NULL,
        note TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, ayah_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (ayah_id) REFERENCES ayahs(id) ON DELETE CASCADE
    );");

    // Hifz Progress table
    $db->exec("CREATE TABLE IF NOT EXISTS hifz_progress (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        surah_id INTEGER NOT NULL,
        ayah_number INTEGER NOT NULL,
        status TEXT DEFAULT 'not_started', -- 'not_started', 'learning', 'memorized', 'review'
        last_reviewed DATETIME,
        next_review DATETIME,
        srs_level INTEGER DEFAULT 0, -- Spaced Repetition System level
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (surah_id) REFERENCES surahs(id) ON DELETE CASCADE,
        UNIQUE(user_id, surah_id, ayah_number)
    );");

    // User Reading Log table
    $db->exec("CREATE TABLE IF NOT EXISTS user_reading_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        ayah_id INTEGER NOT NULL,
        read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (ayah_id) REFERENCES ayahs(id) ON DELETE CASCADE
    );");

    // Site Settings table (for admin)
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT
    );");

    // Create default admin user if not exists
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $hashed_password = password_hash(ADMIN_PASSWORD, PASSWORD_ALGORITHM);
        $stmt = $db->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->execute(['admin', ADMIN_EMAIL, $hashed_password, 'admin']);
    }
}

// --- Data Import ---
function import_quran_data($db) {
    if (!file_exists(DATA_AM_PATH)) {
        return "Error: data.AM file not found at " . DATA_AM_PATH;
    }

    $lines = file(DATA_AM_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return "Error reading data.AM file.";
    }

    $db->beginTransaction();
    try {
        // Clear existing data (optional, for fresh import)
        // $db->exec("DELETE FROM ayahs;");
        // $db->exec("DELETE FROM surahs;");
        // $db->exec("DELETE FROM translations;"); // Be careful with this if you have user translations

        // Insert Surah names and metadata (manual or from a separate source)
        // This is a placeholder. You'll need a more complete list.
        $surahs_data = [
            [1, 'الفاتحة', 'Al-Fatiha', 7, 'Meccan'],
            [2, 'البقرة', 'Al-Baqarah', 286, 'Medinan'],
            // ... add all 114 surahs
        ];
        $stmt_surah = $db->prepare("INSERT OR IGNORE INTO surahs (id, arabic_name, english_name, ayah_count, revelation_type) VALUES (?, ?, ?, ?, ?)");
        foreach ($surahs_data as $surah) {
            $stmt_surah->execute($surah);
        }

        $stmt_ayah = $db->prepare("INSERT INTO ayahs (surah_id, ayah_number, arabic_text) VALUES (?, ?, ?)");
        $stmt_translation = $db->prepare("INSERT INTO translations (ayah_id, language, text, version_name, status, is_default) VALUES (?, ?, ?, ?, ?, ?)");

        $ayah_count = 0;
        foreach ($lines as $line) {
            // Regex to parse the line
            if (preg_match('/^(.*?) ترجمہ: (.*?)<br\/>س (\d{3}) آ (\d{3})$/u', $line, $matches)) {
                $arabic_text = trim($matches[1]);
                $urdu_translation = trim($matches[2]);
                $surah_id = (int)$matches[3];
                $ayah_number = (int)$matches[4];

                // Insert Ayah
                $stmt_ayah->execute([$surah_id, $ayah_number, $arabic_text]);
                $ayah_id = $db->lastInsertId();

                // Insert Urdu Translation (as default imported)
                $stmt_translation->execute([$ayah_id, 'ur', $urdu_translation, 'Imported Urdu', 'approved', 1]);

                $ayah_count++;
            } else {
                // Log lines that fail to parse
                error_log("Failed to parse line: " . $line);
            }
        }

        $db->commit();
        return "Successfully imported " . $ayah_count . " ayahs.";

    } catch (PDOException $e) {
        $db->rollBack();
        return "Data import failed: " . $e->getMessage();
    }
}

// --- Backup and Restore ---
function backup_database() {
    $backup_dir = __DIR__ . '/backups';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0777, true);
    }
    $backup_file = $backup_dir . '/quran_study_hub_backup_' . date('Ymd_His') . '.sqlite';

    try {
        // Use SQLite's built-in backup API
        $db = db_connect();
        $backup_db = new PDO('sqlite:' . $backup_file);
        $backup_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $sqlite_backup = $backup_db->sqliteCreateFunction('sqlite_backup', function($source_db, $dest_db) {
            $source_db->backup($dest_db);
        }, 2);

        $db->sqliteBackup($backup_db);

        return "Database backed up successfully to " . basename($backup_file);
    } catch (PDOException $e) {
        return "Database backup failed: " . $e->getMessage();
    }
}

function restore_database($backup_file_path) {
    if (!file_exists($backup_file_path)) {
        return "Error: Backup file not found.";
    }

    // Ensure the backup file is within the backups directory for security
    $backup_dir = __DIR__ . '/backups';
    $real_backup_path = realpath($backup_file_path);
    $real_backup_dir = realpath($backup_dir);

    if ($real_backup_path === false || strpos($real_backup_path, $real_backup_dir) !== 0) {
        return "Error: Invalid backup file path.";
    }

    // Close existing connections before replacing the file
    // This is tricky in a single-file script. A better approach might involve
    // a maintenance mode or external script. For simplicity here, we'll just
    // attempt to replace the file, which might fail if the DB is in use.
    // A more robust solution would involve PDO::close() if available or
    // ensuring no active connections during restore.
    // For this single-file example, we'll rely on file system operations.

    $current_db_path = DB_PATH;
    $temp_db_path = $current_db_path . '.temp_restore';

    try {
        // Copy the backup to a temporary file
        if (!copy($backup_file_path, $temp_db_path)) {
            return "Error copying backup file.";
        }

        // Replace the main database file
        // This might fail if the database is actively being used by the current request.
        // In a real application, you'd need a more sophisticated approach (e.g.,
        // stopping the web server, replacing the file, restarting).
        // For this example, we'll just try and hope it works in simple cases.
        if (rename($temp_db_path, $current_db_path)) {
             // Re-establish connection after rename
             global $db; // Assuming $db is a global variable holding the connection
             $db = db_connect(); // Reconnect to the new database file
             return "Database restored successfully from " . basename($backup_file_path);
        } else {
            // If rename fails, try to clean up the temp file
            if (file_exists($temp_db_path)) {
                unlink($temp_db_path);
            }
            return "Error replacing database file. Database might be in use.";
        }

    } catch (Exception $e) {
        // Clean up temp file on exception
        if (file_exists($temp_db_path)) {
            unlink($temp_db_path);
        }
        return "Database restore failed: " . $e->getMessage();
    }
}


// --- Authentication and Authorization ---
session_set_cookie_params(SESSION_LIFETIME);
session_start();

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function get_user_id() {
    return is_logged_in() ? $_SESSION['user_id'] : null;
}

function get_user_role() {
    return is_logged_in() ? $_SESSION['user_role'] : 'public';
}

function is_admin() {
    return get_user_role() === 'admin';
}

function require_login() {
    if (!is_logged_in()) {
        redirect('?page=login');
    }
}

function require_admin() {
    if (!is_admin()) {
        // Optionally show an error or redirect
        die("Access Denied: Admins only.");
    }
}

function login($db, $email, $password) {
    $stmt = $db->prepare("SELECT id, username, password, role FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_role'] = $user['role'];
        generate_csrf_token(); // Generate CSRF token on login
        return true;
    }
    return false;
}

function logout() {
    session_unset();
    session_destroy();
    // Clear session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    redirect('?page=home');
}

// --- CSRF Protection ---
function generate_csrf_token() {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
}

function validate_csrf_token($token) {
    return isset($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

// --- Helper Functions ---
function redirect($url) {
    header("Location: " . $url);
    exit();
}

function get_surahs($db) {
    $stmt = $db->query("SELECT * FROM surahs ORDER BY id ASC");
    return $stmt->fetchAll();
}

function get_surah_by_id($db, $surah_id) {
    $stmt = $db->prepare("SELECT * FROM surahs WHERE id = ?");
    $stmt->execute([$surah_id]);
    return $stmt->fetch();
}

function get_ayahs_by_surah($db, $surah_id) {
    $stmt = $db->prepare("SELECT * FROM ayahs WHERE surah_id = ? ORDER BY ayah_number ASC");
    $stmt->execute([$surah_id]);
    return $stmt->fetchAll();
}

function get_ayah_by_id($db, $ayah_id) {
    $stmt = $db->prepare("SELECT a.*, s.arabic_name as surah_arabic_name, s.english_name as surah_english_name FROM ayahs a JOIN surahs s ON a.surah_id = s.id WHERE a.id = ?");
    $stmt->execute([$ayah_id]);
    return $stmt->fetch();
}

function get_ayah_by_surah_ayah($db, $surah_id, $ayah_number) {
    $stmt = $db->prepare("SELECT a.*, s.arabic_name as surah_arabic_name, s.english_name as surah_english_name FROM ayahs a JOIN surahs s ON a.surah_id = s.id WHERE a.surah_id = ? AND a.ayah_number = ?");
    $stmt->execute([$surah_id, $ayah_number]);
    return $stmt->fetch();
}


function get_translations($db, $ayah_id, $status = 'approved') {
    $stmt = $db->prepare("SELECT * FROM translations WHERE ayah_id = ? AND status = ? ORDER BY is_default DESC, version_name ASC");
    $stmt->execute([$ayah_id, $status]);
    return $stmt->fetchAll();
}

function get_tafasir($db, $surah_id = null, $ayah_id = null, $status = 'approved') {
    $sql = "SELECT * FROM tafasir WHERE status = ?";
    $params = [$status];
    if ($surah_id !== null) {
        $sql .= " AND surah_id = ?";
        $params[] = $surah_id;
    }
    if ($ayah_id !== null) {
        $sql .= " AND ayah_id = ?";
        $params[] = $ayah_id;
    }
    $sql .= " ORDER BY is_default DESC, version_name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_word_meanings($db, $ayah_id, $status = 'approved') {
    $stmt = $db->prepare("SELECT * FROM word_meanings WHERE ayah_id = ? AND status = ? ORDER BY word_index ASC, is_default DESC, version_name ASC");
    $stmt->execute([$ayah_id, $status]);
    return $stmt->fetchAll();
}

function get_user_bookmark($db, $user_id, $ayah_id) {
    $stmt = $db->prepare("SELECT * FROM bookmarks WHERE user_id = ? AND ayah_id = ?");
    $stmt->execute([$user_id, $ayah_id]);
    return $stmt->fetch();
}

function add_user_bookmark($db, $user_id, $ayah_id) {
    if (!get_user_bookmark($db, $user_id, $ayah_id)) {
        $stmt = $db->prepare("INSERT INTO bookmarks (user_id, ayah_id) VALUES (?, ?)");
        return $stmt->execute([$user_id, $ayah_id]);
    }
    return false; // Bookmark already exists
}

function remove_user_bookmark($db, $user_id, $ayah_id) {
    $stmt = $db->prepare("DELETE FROM bookmarks WHERE user_id = ? AND ayah_id = ?");
    return $stmt->execute([$user_id, $ayah_id]);
}

function get_user_private_note($db, $user_id, $ayah_id) {
    $stmt = $db->prepare("SELECT * FROM private_notes WHERE user_id = ? AND ayah_id = ?");
    $stmt->execute([$user_id, $ayah_id]);
    return $stmt->fetch();
}

function save_user_private_note($db, $user_id, $ayah_id, $note) {
    $existing_note = get_user_private_note($db, $user_id, $ayah_id);
    if ($existing_note) {
        $stmt = $db->prepare("UPDATE private_notes SET note = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        return $stmt->execute([$note, $existing_note['id']]);
    } else {
        $stmt = $db->prepare("INSERT INTO private_notes (user_id, ayah_id, note) VALUES (?, ?, ?)");
        return $stmt->execute([$user_id, $ayah_id, $note]);
    }
}

function delete_user_private_note($db, $user_id, $ayah_id) {
    $stmt = $db->prepare("DELETE FROM private_notes WHERE user_id = ? AND ayah_id = ?");
    return $stmt->execute([$user_id, $ayah_id]);
}

function log_user_reading($db, $user_id, $ayah_id) {
    // Prevent logging the same ayah multiple times in a very short period
    $stmt_check = $db->prepare("SELECT COUNT(*) FROM user_reading_log WHERE user_id = ? AND ayah_id = ? AND read_at > datetime('now', '-1 minute')");
    $stmt_check->execute([$user_id, $ayah_id]);
    if ($stmt_check->fetchColumn() == 0) {
        $stmt = $db->prepare("INSERT INTO user_reading_log (user_id, ayah_id) VALUES (?, ?)");
        $stmt->execute([$user_id, $ayah_id]);
    }
}

function get_user_reading_report($db, $user_id, $period = 'daily') {
    $sql = "SELECT DATE(read_at) as read_date, COUNT(DISTINCT ayah_id) as ayah_count
            FROM user_reading_log
            WHERE user_id = ?";
    $params = [$user_id];

    switch ($period) {
        case 'daily':
            $sql .= " AND read_at >= DATE('now', '-7 day')"; // Last 7 days
            $group_by = "DATE(read_at)";
            break;
        case 'monthly':
            $sql .= " AND read_at >= DATE('now', '-1 year')"; // Last 12 months
            $group_by = "STRFTIME('%Y-%m', read_at)";
            break;
        case 'yearly':
            $sql .= " AND read_at >= DATE('now', '-5 year')"; // Last 5 years
            $group_by = "STRFTIME('%Y', read_at)";
            break;
        default:
            $sql .= " AND read_at >= DATE('now', '-7 day')";
            $group_by = "DATE(read_at)";
            break;
    }

    $sql .= " GROUP BY " . $group_by . " ORDER BY " . $group_by . " ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_hifz_progress($db, $user_id, $surah_id = null) {
    $sql = "SELECT hp.*, a.ayah_number, s.arabic_name as surah_arabic_name, s.english_name as surah_english_name
            FROM hifz_progress hp
            JOIN ayahs a ON hp.surah_id = a.surah_id AND hp.ayah_number = a.ayah_number
            JOIN surahs s ON hp.surah_id = s.id
            WHERE hp.user_id = ?";
    $params = [$user_id];
    if ($surah_id !== null) {
        $sql .= " AND hp.surah_id = ?";
        $params[] = $surah_id;
    }
    $sql .= " ORDER BY hp.surah_id ASC, hp.ayah_number ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function update_hifz_progress($db, $user_id, $surah_id, $ayah_number, $status, $srs_level = null) {
    $ayah = get_ayah_by_surah_ayah($db, $surah_id, $ayah_number);
    if (!$ayah) {
        return false; // Ayah not found
    }

    $existing_progress = $db->prepare("SELECT * FROM hifz_progress WHERE user_id = ? AND surah_id = ? AND ayah_number = ?");
    $existing_progress->execute([$user_id, $surah_id, $ayah_number]);
    $progress = $existing_progress->fetch();

    $next_review = null;
    $current_srs_level = $progress ? $progress['srs_level'] : 0;

    if ($srs_level !== null) {
        $current_srs_level = $srs_level;
        // Simple SRS interval calculation (can be more complex)
        $intervals = [0, 1, 3, 7, 15, 30, 90, 180, 365]; // Days
        $interval = isset($intervals[$current_srs_level]) ? $intervals[$current_srs_level] : end($intervals);
        $next_review = date('Y-m-d H:i:s', strtotime("+" . $interval . " days"));
    }

    if ($progress) {
        $sql = "UPDATE hifz_progress SET status = ?, last_reviewed = CURRENT_TIMESTAMP";
        $params = [$status];
        if ($srs_level !== null) {
            $sql .= ", srs_level = ?, next_review = ?";
            $params[] = $current_srs_level;
            $params[] = $next_review;
        }
        $sql .= " WHERE id = ?";
        $params[] = $progress['id'];
        $stmt = $db->prepare($sql);
        return $stmt->execute($params);
    } else {
        $sql = "INSERT INTO hifz_progress (user_id, surah_id, ayah_number, status, last_reviewed, next_review, srs_level) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?)";
        $stmt = $db->prepare($sql);
        return $stmt->execute([$user_id, $surah_id, $ayah_number, $status, $next_review, $current_srs_level]);
    }
}

function get_content_suggestions($db, $status = 'pending') {
    $stmt = $db->prepare("
        SELECT
            'translation' as type, t.id, t.ayah_id, NULL as surah_id, t.text, t.version_name, t.status, t.created_at, u.username as contributor_name,
            a.surah_id as ayah_surah_id, a.ayah_number as ayah_ayah_number, a.arabic_text as ayah_arabic_text
        FROM translations t
        JOIN users u ON t.contributor_id = u.id
        JOIN ayahs a ON t.ayah_id = a.id
        WHERE t.status = ?

        UNION ALL

        SELECT
            'tafsir' as type, tf.id, tf.ayah_id, tf.surah_id, tf.text, tf.version_name, tf.status, tf.created_at, u.username as contributor_name,
            a.surah_id as ayah_surah_id, a.ayah_number as ayah_ayah_number, a.arabic_text as ayah_arabic_text
        FROM tafasir tf
        JOIN users u ON tf.contributor_id = u.id
        LEFT JOIN ayahs a ON tf.ayah_id = a.id -- Tafsir can be for Surah or Ayah
        WHERE tf.status = ?

        UNION ALL

        SELECT
            'word_meaning' as type, wm.id, wm.ayah_id, NULL as surah_id, wm.meaning as text, wm.version_name, wm.status, wm.created_at, u.username as contributor_name,
            a.surah_id as ayah_surah_id, a.ayah_number as ayah_ayah_number, a.arabic_text as ayah_arabic_text
        FROM word_meanings wm
        JOIN users u ON wm.contributor_id = u.id
        JOIN ayahs a ON wm.ayah_id = a.id
        WHERE wm.status = ?

        ORDER BY created_at ASC
    ");
    $stmt->execute([$status, $status, $status]);
    return $stmt->fetchAll();
}

function update_content_status($db, $type, $id, $status) {
    $table = '';
    switch ($type) {
        case 'translation': $table = 'translations'; break;
        case 'tafsir': $table = 'tafasir'; break;
        case 'word_meaning': $table = 'word_meanings'; break;
        default: return false;
    }
    $stmt = $db->prepare("UPDATE " . $table . " SET status = ? WHERE id = ?");
    return $stmt->execute([$status, $id]);
}

function set_default_content_version($db, $type, $id, $is_default) {
    $table = '';
    $id_column = 'id';
    $group_column = ''; // Column to group by for setting others to non-default
    switch ($type) {
        case 'translation': $table = 'translations'; $group_column = 'ayah_id'; break;
        case 'tafsir': $table = 'tafasir'; $group_column = 'ayah_id'; // Or surah_id
            // This is complex as tafsir can be per ayah or per surah.
            // For simplicity, let's assume default is per ayah or per surah.
            // A better schema might separate these.
            // For now, let's handle ayah_id if present, otherwise surah_id.
            $stmt_item = $db->prepare("SELECT ayah_id, surah_id FROM tafasir WHERE id = ?");
            $stmt_item->execute([$id]);
            $item = $stmt_item->fetch();
            if ($item && $item['ayah_id']) {
                 $group_column = 'ayah_id';
                 $group_value = $item['ayah_id'];
            } elseif ($item && $item['surah_id']) {
                 $group_column = 'surah_id';
                 $group_value = $item['surah_id'];
            } else {
                 return false; // Cannot determine group
            }
            break;
        case 'word_meaning': $table = 'word_meanings'; $group_column = 'ayah_id'; break;
        default: return false;
    }

    $db->beginTransaction();
    try {
        // Set all others in the group to not default
        if ($is_default && $group_column && isset($group_value)) {
             $stmt_reset = $db->prepare("UPDATE " . $table . " SET is_default = 0 WHERE " . $group_column . " = ?");
             $stmt_reset->execute([$group_value]);
        } elseif ($is_default && $group_column == 'ayah_id' && $type != 'tafsir') {
             // For translations/word_meanings, get the ayah_id first
             $stmt_item = $db->prepare("SELECT ayah_id FROM " . $table . " WHERE id = ?");
             $stmt_item->execute([$id]);
             $item = $stmt_item->fetch();
             if ($item) {
                 $stmt_reset = $db->prepare("UPDATE " . $table . " SET is_default = 0 WHERE ayah_id = ?");
                 $stmt_reset->execute([$item['ayah_id']]);
             }
        }


        // Set the specific item to default/not default
        $stmt_set = $db->prepare("UPDATE " . $table . " SET is_default = ? WHERE id = ?");
        $result = $stmt_set->execute([$is_default ? 1 : 0, $id]);

        $db->commit();
        return $result;

    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Error setting default content: " . $e->getMessage());
        return false;
    }
}


// --- Search Functionality ---
function search_quran($db, $query, $surah_id = null, $juz_id = null, $user_id = null) {
    // Basic search implementation
    // For full-text search, you'd typically use FTS5 extension in SQLite
    // This is a simple LIKE search for demonstration

    $sql = "SELECT a.id, a.surah_id, a.ayah_number, a.arabic_text, s.arabic_name as surah_arabic_name, s.english_name as surah_english_name
            FROM ayahs a
            JOIN surahs s ON a.surah_id = s.id
            WHERE a.arabic_text LIKE ? "; // Search Arabic text

    $params = ['%' . $query . '%'];

    // Add search in default translation
    $sql .= " OR a.id IN (SELECT ayah_id FROM translations WHERE text LIKE ? AND is_default = 1 AND status = 'approved')";
    $params[] = '%' . $query . '%';

    // Add search in default tafsir (if applicable - tafsir is per ayah/surah)
    // This is simplified; a real search would need to handle tafsir structure
    $sql .= " OR a.id IN (SELECT ayah_id FROM tafasir WHERE text LIKE ? AND is_default = 1 AND status = 'approved')";
    $params[] = '%' . $query . '%';

    // Add search in default word meanings
    $sql .= " OR a.id IN (SELECT ayah_id FROM word_meanings WHERE meaning LIKE ? AND is_default = 1 AND status = 'approved')";
    $params[] = '%' . $query . '%';


    if ($surah_id !== null) {
        $sql .= " AND a.surah_id = ?";
        $params[] = $surah_id;
    }

    // Juz filtering requires mapping Juz to Surah/Ayah ranges, which is complex.
    // Skipping Juz filter for this basic implementation.

    // Search user's private notes if logged in
    if ($user_id !== null) {
        $sql .= " OR a.id IN (SELECT ayah_id FROM private_notes WHERE user_id = ? AND note LIKE ?)";
        $params[] = $user_id;
        $params[] = '%' . $query . '%';
    }

    $sql .= " ORDER BY a.surah_id ASC, a.ayah_number ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}


// --- Routing and Request Handling ---
$db = db_connect();
db_init($db); // Initialize database schema if not exists

$page = $_GET['page'] ?? 'home';
$action = $_POST['action'] ?? $_GET['action'] ?? null;

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action) {
    // Validate CSRF token for POST requests
    if (!isset($_POST[CSRF_TOKEN_NAME]) || !validate_csrf_token($_POST[CSRF_TOKEN_NAME])) {
        die("CSRF token validation failed.");
    }

    switch ($action) {
        case 'login':
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            if (login($db, $email, $password)) {
                redirect('?page=dashboard'); // Redirect to dashboard or home after login
            } else {
                $error = "Invalid email or password.";
                $page = 'login'; // Stay on login page with error
            }
            break;
        case 'logout':
            logout();
            break;
        case 'register':
            // Basic registration (add validation and error handling)
            $username = $_POST['username'] ?? '';
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if ($password !== $confirm_password) {
                $error = "Passwords do not match.";
                $page = 'register';
            } else {
                $hashed_password = password_hash($password, PASSWORD_ALGORITHM);
                try {
                    $stmt = $db->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
                    if ($stmt->execute([$username, $email, $hashed_password])) {
                        // Auto-login after registration
                        if (login($db, $email, $password)) {
                             redirect('?page=dashboard');
                        } else {
                             $success = "Registration successful. Please login.";
                             $page = 'login';
                        }
                    } else {
                        $error = "Registration failed. Email or username might be taken.";
                        $page = 'register';
                    }
                } catch (PDOException $e) {
                    $error = "Registration failed: " . $e->getMessage();
                    $page = 'register';
                }
            }
            break;
        case 'add_bookmark':
            require_login();
            $ayah_id = $_POST['ayah_id'] ?? null;
            if ($ayah_id) {
                add_user_bookmark($db, get_user_id(), $ayah_id);
            }
            // Redirect back or send JSON response
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=home');
            break;
        case 'remove_bookmark':
            require_login();
            $ayah_id = $_POST['ayah_id'] ?? null;
            if ($ayah_id) {
                remove_user_bookmark($db, get_user_id(), $ayah_id);
            }
            // Redirect back or send JSON response
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=home');
            break;
        case 'save_note':
            require_login();
            $ayah_id = $_POST['ayah_id'] ?? null;
            $note = $_POST['note'] ?? '';
            if ($ayah_id) {
                save_user_private_note($db, get_user_id(), $ayah_id, $note);
            }
            // Redirect back or send JSON response
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=home');
            break;
        case 'delete_note':
             require_login();
             $ayah_id = $_POST['ayah_id'] ?? null;
             if ($ayah_id) {
                 delete_user_private_note($db, get_user_id(), $ayah_id);
             }
             // Redirect back or send JSON response
             redirect($_SERVER['HTTP_REFERER'] ?? '?page=home');
             break;
        case 'suggest_translation':
        case 'suggest_tafsir':
        case 'suggest_word_meaning':
            require_login();
            $ayah_id = $_POST['ayah_id'] ?? null;
            $surah_id = $_POST['surah_id'] ?? null; // For Surah Tafsir
            $text = $_POST['text'] ?? '';
            $version_name = $_POST['version_name'] ?? 'User Suggestion';
            $word_index = $_POST['word_index'] ?? null; // For word meaning
            $arabic_word = $_POST['arabic_word'] ?? null; // For word meaning

            if ($action === 'suggest_translation' && $ayah_id && $text) {
                $stmt = $db->prepare("INSERT INTO translations (ayah_id, language, text, version_name, status, contributor_id) VALUES (?, ?, ?, ?, 'pending', ?)");
                $stmt->execute([$ayah_id, 'user_lang', $text, $version_name, get_user_id()]);
                $message = "Translation suggestion submitted for approval.";
            } elseif ($action === 'suggest_tafsir' && ($ayah_id || $surah_id) && $text) {
                 $stmt = $db->prepare("INSERT INTO tafasir (ayah_id, surah_id, text, version_name, status, contributor_id) VALUES (?, ?, ?, ?, 'pending', ?)");
                 $stmt->execute([$ayah_id, $surah_id, $text, $version_name, get_user_id()]);
                 $message = "Tafsir suggestion submitted for approval.";
            } elseif ($action === 'suggest_word_meaning' && $ayah_id && $word_index !== null && $arabic_word && $text) {
                 $stmt = $db->prepare("INSERT INTO word_meanings (ayah_id, word_index, arabic_word, meaning, version_name, status, contributor_id) VALUES (?, ?, ?, ?, ?, 'pending', ?)");
                 $stmt->execute([$ayah_id, $word_index, $arabic_word, $text, $version_name, get_user_id()]);
                 $message = "Word meaning suggestion submitted for approval.";
            } else {
                $error = "Invalid suggestion data.";
            }
            // Redirect back or show message
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=home');
            break;
        case 'update_hifz_progress':
            require_login();
            $surah_id = $_POST['surah_id'] ?? null;
            $ayah_number = $_POST['ayah_number'] ?? null;
            $status = $_POST['status'] ?? null;
            $srs_level = $_POST['srs_level'] ?? null; // Optional, for SRS updates

            if ($surah_id && $ayah_number && $status) {
                update_hifz_progress($db, get_user_id(), $surah_id, $ayah_number, $status, $srs_level);
            }
            // Redirect back or send JSON response
            redirect($_SERVER['HTTP_REFERER'] ?? '?page=hifz');
            break;
        // Admin Actions
        case 'admin_approve_content':
        case 'admin_reject_content':
            require_admin();
            $content_type = $_POST['content_type'] ?? null;
            $content_id = $_POST['content_id'] ?? null;
            $status = ($action === 'admin_approve_content') ? 'approved' : 'rejected';
            if ($content_type && $content_id) {
                update_content_status($db, $content_type, $content_id, $status);
            }
            redirect('?page=admin&section=content_moderation');
            break;
        case 'admin_set_default_content':
             require_admin();
             $content_type = $_POST['content_type'] ?? null;
             $content_id = $_POST['content_id'] ?? null;
             $is_default = isset($_POST['is_default']); // Checkbox value

             if ($content_type && $content_id) {
                 set_default_content_version($db, $content_type, $content_id, $is_default);
             }
             redirect('?page=admin&section=content_management'); // Or back to content list
             break;
        case 'admin_import_data':
            require_admin();
            $import_result = import_quran_data($db);
            $_SESSION['message'] = $import_result; // Store message in session
            redirect('?page=admin&section=data_management');
            break;
        case 'admin_backup_db':
            require_admin();
            $backup_result = backup_database();
            $_SESSION['message'] = $backup_result;
            redirect('?page=admin&section=data_management');
            break;
        case 'admin_restore_db':
            require_admin();
            $backup_file = $_POST['backup_file'] ?? null;
            if ($backup_file) {
                $restore_result = restore_database(__DIR__ . '/backups/' . basename($backup_file)); // Sanitize filename
                $_SESSION['message'] = $restore_result;
            } else {
                $_SESSION['message'] = "No backup file selected.";
            }
            redirect('?page=admin&section=data_management');
            break;
        // Add other POST actions (e.g., admin user management, settings)
    }
}

// Generate CSRF token for forms
if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
    generate_csrf_token();
}
$csrf_token = $_SESSION[CSRF_TOKEN_NAME];

// --- HTML Structure and Page Rendering ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Basic CSS for layout and typography */
        body {
            font-family: sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            color: #333;
        }
        .container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 0 20px;
        }
        header {
            background: #0056b3;
            color: #fff;
            padding: 10px 0;
            text-align: center;
        }
        header h1 {
            margin: 0;
            font-size: 2em;
        }
        nav {
            background: #004085;
            color: #fff;
            padding: 10px 0;
            text-align: center;
        }
        nav a {
            color: #fff;
            text-decoration: none;
            margin: 0 15px;
            font-size: 1.1em;
        }
        nav a:hover {
            text-decoration: underline;
        }
        .content {
            background: #fff;
            padding: 20px;
            margin-top: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .ayah {
            border-bottom: 1px solid #eee;
            padding: 15px 0;
            margin-bottom: 15px;
        }
        .ayah:last-child {
            border-bottom: none;
        }
        .ayah .arabic {
            font-family: 'Amiri', serif;
            font-size: 1.8em;
            text-align: right;
            direction: rtl;
            margin-bottom: 10px;
            line-height: 2.5; /* Adjust line height for Arabic */
        }
        .ayah .translation {
            font-family: 'Noto Nastaliq Urdu', serif;
            font-size: 1.1em;
            text-align: right;
            direction: rtl;
            color: #555;
            margin-top: 5px;
        }
        .ayah .meta {
            font-size: 0.9em;
            color: #888;
            margin-top: 10px;
        }
        .ayah .actions {
            margin-top: 10px;
            font-size: 0.9em;
        }
        .ayah .actions button, .ayah .actions a {
            margin-right: 10px;
            cursor: pointer;
            border: none;
            background: none;
            color: #0056b3;
            text-decoration: none;
        }
        .ayah .actions button:hover, .ayah .actions a:hover {
            text-decoration: underline;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="password"],
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box; /* Include padding and border in element's total width and height */
        }
        .btn {
            background-color: #0056b3;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1em;
        }
        .btn:hover {
            background-color: #004085;
        }
        .alert {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .admin-panel nav a {
            color: #333;
            margin: 0 10px;
        }
        .admin-panel nav {
            background: #eee;
            padding: 10px;
            margin-bottom: 20px;
        }
        .admin-panel .content-item {
            border: 1px solid #ccc;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 4px;
        }
        .admin-panel .content-item h4 {
            margin-top: 0;
        }
        .admin-panel .content-item form {
            display: inline-block;
            margin-left: 10px;
        }
        .admin-panel .content-item button {
            padding: 5px 10px;
            font-size: 0.9em;
        }

        /* Tilawat Mode CSS (Prefixed) */
        .tilawat-mode-body {
            background-color: #000 !important;
            color: #fff !important;
            overflow: hidden; /* Hide scrollbar in paginated mode */
        }
        .tilawat-mode-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
            background-color: #000; /* Ensure container is also black */
        }
        .tilawat-mode-ayah {
            border-bottom: 1px solid #333;
            padding: 15px 0;
            margin-bottom: 15px;
        }
        .tilawat-mode-ayah:last-child {
            border-bottom: none;
        }
        .tilawat-mode-ayah .arabic {
            font-family: 'Amiri', serif;
            font-size: 2.5em; /* Larger font */
            text-align: center; /* Center Arabic */
            direction: rtl;
            margin-bottom: 15px;
            line-height: 2.8;
            color: #fff;
        }
        .tilawat-mode-ayah .translation {
            font-family: 'Noto Nastaliq Urdu', serif;
            font-size: 1.2em;
            text-align: center; /* Center translation */
            direction: rtl;
            color: #ccc;
            margin-top: 10px;
        }
        .tilawat-mode-controls {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.1);
            padding: 10px;
            border-radius: 5px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .tilawat-mode-controls label {
            color: #fff;
            font-size: 0.9em;
        }
        .tilawat-mode-controls input[type="number"],
        .tilawat-mode-controls input[type="range"],
        .tilawat-mode-controls select {
            padding: 5px;
            border-radius: 3px;
            border: none;
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
        }
        .tilawat-mode-controls button {
             background: rgba(255, 255, 255, 0.2);
             color: #fff;
             border: none;
             padding: 5px 10px;
             border-radius: 3px;
             cursor: pointer;
        }
        .tilawat-mode-controls button:hover {
             background: rgba(255, 255, 255, 0.3);
        }
        .tilawat-mode-icon {
            position: fixed;
            top: 10px;
            right: 10px;
            font-size: 1.5em;
            color: #fff;
            cursor: pointer;
            z-index: 1001;
        }
        .tilawat-mode-hidden {
            display: none;
        }
        .tilawat-mode-highlight {
            background-color: rgba(255, 255, 0, 0.3); /* Highlight playing ayah */
        }
        .tilawat-mode-pagination {
            text-align: center;
            margin-top: 20px;
            color: #fff;
        }
        .tilawat-mode-pagination button {
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
            border: none;
            padding: 8px 15px;
            margin: 0 5px;
            border-radius: 4px;
            cursor: pointer;
        }
        .tilawat-mode-pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Word Meaning Popover (Basic) */
        .word-meaning-popover {
            position: absolute;
            background: #fff;
            border: 1px solid #ccc;
            padding: 10px;
            border-radius: 4px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            z-index: 10;
            max-width: 200px;
            font-size: 0.9em;
            display: none; /* Hidden by default */
        }
        .word-meaning-popover strong {
            display: block;
            margin-bottom: 5px;
        }
        .arabic-word-clickable {
            cursor: pointer;
            text-decoration: underline;
            text-decoration-style: dotted;
        }

        /* Charts (Basic CSS Chart Example) */
        .chart-container {
            margin-top: 20px;
            padding: 15px;
            border: 1px solid #eee;
            border-radius: 8px;
            background: #f9f9f9;
        }
        .chart-bar {
            display: flex;
            align-items: flex-end;
            height: 150px; /* Fixed height for chart area */
            border-left: 1px solid #ccc;
            border-bottom: 1px solid #ccc;
            padding-left: 5px;
            position: relative;
        }
        .bar {
            flex-grow: 1;
            margin: 0 2px;
            background-color: #007bff;
            width: 20px; /* Fixed width for bars */
            position: relative;
            bottom: 0;
            text-align: center;
            color: white;
            font-size: 0.8em;
            display: flex;
            justify-content: center;
            align-items: flex-end;
        }
        .bar span {
            position: absolute;
            top: -20px;
            left: 50%;
            transform: translateX(-50%);
            color: #333;
            font-size: 0.7em;
        }
        .chart-labels {
            display: flex;
            justify-content: space-around;
            font-size: 0.8em;
            margin-top: 5px;
        }
        .chart-label {
            flex-grow: 1;
            text-align: center;
        }

    </style>
</head>
<body>

    <header>
        <h1><?php echo SITE_NAME; ?></h1>
        <div class="header-actions">
            <?php if (is_logged_in()): ?>
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</span>
                <form action="" method="post" style="display: inline;">
                    <input type="hidden" name="action" value="logout">
                    <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo $csrf_token; ?>">
                    <button type="submit" class="btn" style="padding: 5px 10px;">Logout</button>
                </form>
                <i class="fas fa-book-open tilawat-mode-icon" title="Tilawat Mode"></i>
            <?php else: ?>
                <a href="?page=login" class="btn" style="padding: 5px 10px;">Login</a>
                <a href="?page=register" class="btn" style="padding: 5px 10px;">Register</a>
            <?php endif; ?>
        </div>
    </header>

    <nav>
        <a href="?page=home">Home</a>
        <a href="?page=quran">Read Quran</a>
        <?php if (is_logged_in()): ?>
            <a href="?page=dashboard">Dashboard</a>
            <a href="?page=hifz">Hifz Companion</a>
            <a href="?page=reports">Reading Reports</a>
            <a href="?page=settings">Settings</a>
        <?php endif; ?>
        <?php if (is_admin()): ?>
            <a href="?page=admin">Admin Panel</a>
        <?php endif; ?>
        <form action="" method="get" style="display: inline-block; margin-left: 20px;">
            <input type="hidden" name="page" value="search">
            <input type="text" name="query" placeholder="Search Quran..." value="<?php echo htmlspecialchars($_GET['query'] ?? ''); ?>">
            <button type="submit" class="btn" style="padding: 5px 10px;">Search</button>
        </form>
    </nav>

    <div class="container">
        <?php
        // Display messages
        if (isset($error)) {
            echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
        }
        if (isset($success)) {
            echo '<div class="alert alert-success">' . htmlspecialchars($success) . '</div>';
        }
        // Display session messages (e.g., from redirects)
        if (isset($_SESSION['message'])) {
            echo '<div class="alert alert-success">' . htmlspecialchars($_SESSION['message']) . '</div>';
            unset($_SESSION['message']); // Clear the message after displaying
        }

        // --- Page Content ---
        switch ($page) {
            case 'home':
                ?>
                <div class="content">
                    <h2>Welcome to Quran Study Hub</h2>
                    <p>Your personal platform for studying the Holy Quran.</p>
                    <?php if (!is_logged_in()): ?>
                        <p><a href="?page=register">Register</a> or <a href="?page=login">Login</a> to unlock personalized features like bookmarks, notes, and Hifz tracking.</p>
                    <?php endif; ?>
                    <h3>Quick Links</h3>
                    <ul>
                        <li><a href="?page=quran">Read the Quran</a></li>
                        <li><a href="?page=quran&surah=1&ayah=1">Start from Al-Fatiha</a></li>
                        <?php if (is_logged_in()): ?>
                            <li><a href="?page=dashboard">Go to Dashboard</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php
                break;

            case 'quran':
                $surah_id = $_GET['surah'] ?? null;
                $ayah_number = $_GET['ayah'] ?? null;

                $surahs = get_surahs($db);

                if ($surah_id) {
                    $current_surah = get_surah_by_id($db, $surah_id);
                    if ($current_surah) {
                        $ayahs = get_ayahs_by_surah($db, $surah_id);
                        ?>
                        <div class="content">
                            <h2><?php echo htmlspecialchars($current_surah['arabic_name']); ?> - <?php echo htmlspecialchars($current_surah['english_name']); ?></h2>
                            <p>Surah <?php echo $surah_id; ?> (<?php echo $current_surah['revelation_type']; ?>, <?php echo $current_surah['ayah_count']; ?> Ayahs)</p>

                            <!-- Surah/Ayah Navigation -->
                            <div class="navigation" style="margin-bottom: 20px;">
                                <label for="surah-select">Go to Surah:</label>
                                <select id="surah-select" onchange="window.location.href='?page=quran&surah=' + this.value">
                                    <option value="">Select Surah</option>
                                    <?php foreach ($surahs as $s): ?>
                                        <option value="<?php echo $s['id']; ?>" <?php echo ($s['id'] == $surah_id) ? 'selected' : ''; ?>>
                                            <?php echo $s['id'] . '. ' . htmlspecialchars($s['english_name']) . ' (' . htmlspecialchars($s['arabic_name']) . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($ayahs): ?>
                                    <label for="ayah-select">Go to Ayah:</label>
                                    <select id="ayah-select" onchange="window.location.href='?page=quran&surah=<?php echo $surah_id; ?>&ayah=' + this.value">
                                        <option value="">Select Ayah</option>
                                        <?php for ($i = 1; $i <= $current_surah['ayah_count']; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo ($i == $ayah_number) ? 'selected' : ''; ?>>
                                                Ayah <?php echo $i; ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                <?php endif; ?>
                            </div>

                            <?php
                            foreach ($ayahs as $ayah):
                                $translations = get_translations($db, $ayah['id']);
                                $tafasir = get_tafasir($db, null, $ayah['id']); // Get Ayah-specific tafsir
                                $word_meanings = get_word_meanings($db, $ayah['id']);
                                $user_note = is_logged_in() ? get_user_private_note($db, get_user_id(), $ayah['id']) : null;
                                $is_bookmarked = is_logged_in() ? get_user_bookmark($db, get_user_id(), $ayah['id']) : false;

                                // Log reading activity for logged-in users
                                if (is_logged_in()) {
                                    log_user_reading($db, get_user_id(), $ayah['id']);
                                }
                                ?>
                                <div class="ayah" id="ayah-<?php echo $ayah['id']; ?>" data-surah="<?php echo $ayah['surah_id']; ?>" data-ayah="<?php echo $ayah['ayah_number']; ?>">
                                    <div class="arabic">
                                        <?php
                                        // Display Arabic text with clickable words for meanings
                                        $arabic_words = explode(' ', $ayah['arabic_text']);
                                        $word_index = 0;
                                        foreach ($arabic_words as $word) {
                                            $clean_word = trim($word, ".,;!?:()[]{}<>\"'«»‘’“”"); // Basic cleaning
                                            $meaning_found = false;
                                            foreach ($word_meanings as $wm) {
                                                if ($wm['word_index'] == $word_index) {
                                                    echo '<span class="arabic-word-clickable" data-meaning="' . htmlspecialchars($wm['meaning']) . '" data-grammar="' . htmlspecialchars($wm['grammar_notes'] ?? '') . '">' . htmlspecialchars($word) . '</span> ';
                                                    $meaning_found = true;
                                                    break;
                                                }
                                            }
                                            if (!$meaning_found) {
                                                echo htmlspecialchars($word) . ' ';
                                            }
                                            $word_index++;
                                        }
                                        ?>
                                    </div>
                                    <div class="translation">
                                        <?php
                                        $default_translation = array_filter($translations, function($t) { return $t['is_default']; });
                                        if (!empty($default_translation)) {
                                            echo htmlspecialchars(reset($default_translation)['text']);
                                        } elseif (!empty($translations)) {
                                            // Fallback to the first approved translation
                                            echo htmlspecialchars($translations[0]['text']);
                                        } else {
                                            echo "<em>No translation available.</em>";
                                        }
                                        ?>
                                    </div>
                                    <?php if (!empty($tafasir)): ?>
                                        <div class="tafsir" style="margin-top: 10px; font-size: 0.9em; color: #666;">
                                            <strong>Tafsir:</strong>
                                            <?php
                                            $default_tafsir = array_filter($tafasir, function($t) { return $t['is_default']; });
                                            if (!empty($default_tafsir)) {
                                                echo nl2br(htmlspecialchars(reset($default_tafsir)['text']));
                                            } elseif (!empty($tafasir)) {
                                                echo nl2br(htmlspecialchars($tafasir[0]['text']));
                                            }
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($user_note): ?>
                                        <div class="user-note" style="margin-top: 10px; font-size: 0.9em; color: #007bff;">
                                            <strong>Your Note:</strong> <?php echo nl2br(htmlspecialchars($user_note['note'])); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="meta">
                                        (Surah <?php echo $ayah['surah_id']; ?>: Ayah <?php echo $ayah['ayah_number']; ?>)
                                    </div>
                                    <?php if (is_logged_in()): ?>
                                        <div class="actions">
                                            <form action="" method="post" style="display: inline;">
                                                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="ayah_id" value="<?php echo $ayah['id']; ?>">
                                                <?php if ($is_bookmarked): ?>
                                                    <input type="hidden" name="action" value="remove_bookmark">
                                                    <button type="submit"><i class="fas fa-bookmark"></i> Remove Bookmark</button>
                                                <?php else: ?>
                                                    <input type="hidden" name="action" value="add_bookmark">
                                                    <button type="submit"><i class="far fa-bookmark"></i> Add Bookmark</button>
                                                <?php endif; ?>
                                            </form>
                                            <button class="toggle-note-form" data-ayah-id="<?php echo $ayah['id']; ?>"><i class="far fa-sticky-note"></i> <?php echo $user_note ? 'Edit Note' : 'Add Note'; ?></button>
                                            <?php if ($user_note): ?>
                                                 <form action="" method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this note?');">
                                                     <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo $csrf_token; ?>">
                                                     <input type="hidden" name="action" value="delete_note">
                                                     <input type="hidden" name="ayah_id" value="<?php echo $ayah['id']; ?>">
                                                     <button type="submit" style="color: red;"><i class="fas fa-trash-alt"></i> Delete Note</button>
                                                 </form>
                                            <?php endif; ?>
                                            <button class="toggle-suggest-form" data-ayah-id="<?php echo $ayah['id']; ?>"><i class="fas fa-lightbulb"></i> Suggest Content</button>
                                            <!-- Audio Playback (Placeholder) -->
                                            <button class="play-ayah" data-surah="<?php echo $ayah['surah_id']; ?>" data-ayah="<?php echo $ayah['ayah_number']; ?>"><i class="fas fa-play"></i> Play Ayah</button>
                                        </div>
                                        <div class="note-form" id="note-form-<?php echo $ayah['id']; ?>" style="display: none; margin-top: 10px;">
                                            <form action="" method="post">
                                                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="action" value="save_note">
                                                <input type="hidden" name="ayah_id" value="<?php echo $ayah['id']; ?>">
                                                <textarea name="note" rows="3" placeholder="Write your private note here..."><?php echo htmlspecialchars($user_note['note'] ?? ''); ?></textarea><br>
                                                <button type="submit" class="btn">Save Note</button>
                                                <button type="button" class="btn cancel-note-form">Cancel</button>
                                            </form>
                                        </div>
                                         <div class="suggest-form" id="suggest-form-<?php echo $ayah['id']; ?>" style="display: none; margin-top: 10px;">
                                             <h4>Suggest Content for Ayah <?php echo $ayah['surah_id'] . ':' . $ayah['ayah_number']; ?></h4>
                                             <form action="" method="post">
                                                 <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo $csrf_token; ?>">
                                                 <input type="hidden" name="ayah_id" value="<?php echo $ayah['id']; ?>">
                                                 <div class="form-group">
                                                     <label for="suggestion_type_<?php echo $ayah['id']; ?>">Suggestion Type:</label>
                                                     <select name="action" id="suggestion_type_<?php echo $ayah['id']; ?>">
                                                         <option value="suggest_translation">Translation</option>
                                                         <option value="suggest_tafsir">Tafsir</option>
                                                         <!-- Word meaning suggestion is more complex, requires word index -->
                                                         <!-- <option value="suggest_word_meaning">Word Meaning</option> -->
                                                     </select>
                                                 </div>
                                                  <div class="form-group">
                                                      <label for="version_name_<?php echo $ayah['id']; ?>">Version Name (Optional):</label>
                                                      <input type="text" name="version_name" id="version_name_<?php echo $ayah['id']; ?>" value="User Suggestion">
                                                  </div>
                                                 <div class="form-group">
                                                     <label for="suggestion_text_<?php echo $ayah['id']; ?>">Content:</label>
                                                     <textarea name="text" id="suggestion_text_<?php echo $ayah['id']; ?>" rows="4" required></textarea>
                                                 </div>
                                                 <button type="submit" class="btn">Submit Suggestion</button>
                                                 <button type="button" class="btn cancel-suggest-form">Cancel</button>
                                             </form>
                                         </div>
                                    <?php endif; ?>
                                </div>
                                <?php
                            endforeach;
                            ?>
                        </div>
                        <?php
                    } else {
                        echo '<div class="content"><p>Surah not found.</p></div>';
                    }
                } else {
                    // Display Surah Index
                    ?>
                    <div class="content">
                        <h2>Quran Index</h2>
                        <ul>
                            <?php foreach ($surahs as $surah): ?>
                                <li>
                                    <a href="?page=quran&surah=<?php echo $surah['id']; ?>">
                                        <?php echo $surah['id']; ?>. <?php echo htmlspecialchars($surah['english_name']); ?> (<?php echo htmlspecialchars($surah['arabic_name']); ?>) - <?php echo $surah['ayah_count']; ?> Ayahs
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php
                }
                break;

            case 'login':
                if (is_logged_in()) {
                    redirect('?page=dashboard');
                }
                ?>
                <div class="content">
                    <h2>Login</h2>
                    <form action="" method="post">
                        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="login">
                        <div class="form-group">
                            <label for="email">Email:</label>
                            <input type="email" id="email" name="email" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Password:</label>
                            <input type="password" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn">Login</button>
                    </form>
                    <p>Don't have an account? <a href="?page=register">Register here</a>.</p>
                </div>
                <?php
                break;

            case 'register':
                if (is_logged_in()) {
                    redirect('?page=dashboard');
                }
                ?>
                <div class="content">
                    <h2>Register</h2>
                    <form action="" method="post">
                        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="register">
                        <div class="form-group">
                            <label for="username">Username:</label>
                            <input type="text" id="username" name="username" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Email:</label>
                            <input type="email" id="email" name="email" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Password:</label>
                            <input type="password" id="password" name="password" required>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm Password:</label>
                            <input type="password" id="confirm_password" name="confirm_password" required>
                        </div>
                        <button type="submit" class="btn">Register</button>
                    </form>
                    <p>Already have an account? <a href="?page=login">Login here</a>.</p>
                </div>
                <?php
                break;

            case 'dashboard':
                require_login();
                ?>
                <div class="content">
                    <h2>User Dashboard</h2>
                    <p>Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>!</p>
                    <h3>Your Activity</h3>
                    <ul>
                        <li><a href="?page=reports">View Reading Reports</a></li>
                        <li><a href="?page=hifz">Manage Hifz Progress</a></li>
                        <li><a href="?page=bookmarks">View Bookmarks</a></li>
                        <li><a href="?page=notes">View Private Notes</a></li>
                    </ul>
                    <h3>Continue Reading</h3>
                    <?php
                    // Fetch last read ayah (simple implementation: last logged ayah)
                    $stmt_last_read = $db->prepare("SELECT l.ayah_id, a.surah_id, a.ayah_number, s.english_name
                                                    FROM user_reading_log l
                                                    JOIN ayahs a ON l.ayah_id = a.id
                                                    JOIN surahs s ON a.surah_id = s.id
                                                    WHERE l.user_id = ?
                                                    ORDER BY l.read_at DESC LIMIT 1");
                    $stmt_last_read->execute([get_user_id()]);
                    $last_read = $stmt_last_read->fetch();

                    if ($last_read) {
                        echo "<p>Last read: Surah " . htmlspecialchars($last_read['english_name']) . " (" . $last_read['surah_id'] . ") Ayah " . $last_read['ayah_number'] . "</p>";
                        echo '<p><a href="?page=quran&surah=' . $last_read['surah_id'] . '&ayah=' . $last_read['ayah_number'] . '" class="btn">Continue Reading</a></p>';
                    } else {
                        echo "<p>You haven't started reading yet. <a href='?page=quran' class='btn'>Start Reading</a></p>";
                    }
                    ?>
                </div>
                <?php
                break;

            case 'bookmarks':
                require_login();
                $stmt_bookmarks = $db->prepare("SELECT b.*, a.surah_id, a.ayah_number, a.arabic_text, s.english_name as surah_english_name
                                                FROM bookmarks b
                                                JOIN ayahs a ON b.ayah_id = a.id
                                                JOIN surahs s ON a.surah_id = s.id
                                                WHERE b.user_id = ?
                                                ORDER BY a.surah_id ASC, a.ayah_number ASC");
                $stmt_bookmarks->execute([get_user_id()]);
                $bookmarks = $stmt_bookmarks->fetchAll();
                ?>
                <div class="content">
                    <h2>Your Bookmarks</h2>
                    <?php if (empty($bookmarks)): ?>
                        <p>You have no bookmarks yet.</p>
                    <?php else: ?>
                        <ul>
                            <?php foreach ($bookmarks as $bookmark): ?>
                                <li>
                                    <a href="?page=quran&surah=<?php echo $bookmark['surah_id']; ?>&ayah=<?php echo $bookmark['ayah_number']; ?>">
                                        Surah <?php echo htmlspecialchars($bookmark['surah_english_name']); ?> (<?php echo $bookmark['surah_id']; ?>): Ayah <?php echo $bookmark['ayah_number']; ?>
                                    </a>
                                    <form action="" method="post" style="display: inline; margin-left: 10px;">
                                        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="remove_bookmark">
                                        <input type="hidden" name="ayah_id" value="<?php echo $bookmark['ayah_id']; ?>">
                                        <button type="submit" style="color: red; background: none; border: none; cursor: pointer;"><i class="fas fa-trash-alt"></i> Remove</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <?php
                break;

            case 'notes':
                 require_login();
                 $stmt_notes = $db->prepare("SELECT n.*, a.surah_id, a.ayah_number, a.arabic_text, s.english_name as surah_english_name
                                             FROM private_notes n
                                             JOIN ayahs a ON n.ayah_id = a.id
                                             JOIN surahs s ON a.surah_id = s.id
                                             WHERE n.user_id = ?
                                             ORDER BY a.surah_id ASC, a.ayah_number ASC");
                 $stmt_notes->execute([get_user_id()]);
                 $notes = $stmt_notes->fetchAll();
                 ?>
                 <div class="content">
                     <h2>Your Private Notes</h2>
                     <?php if (empty($notes)): ?>
                         <p>You have no private notes yet.</p>
                     <?php else: ?>
                         <ul>
                             <?php foreach ($notes as $note): ?>
                                 <li>
                                     <strong><a href="?page=quran&surah=<?php echo $note['surah_id']; ?>&ayah=<?php echo $note['ayah_number']; ?>">
                                         Surah <?php echo htmlspecialchars($note['surah_english_name']); ?> (<?php echo $note['surah_id']; ?>): Ayah <?php echo $note['ayah_number']; ?>
                                     </a></strong>
                                     <p><?php echo nl2br(htmlspecialchars($note['note'])); ?></p>
                                     <form action="" method="post" style="display: inline; margin-left: 10px;" onsubmit="return confirm('Are you sure you want to delete this note?');">
                                         <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo $csrf_token; ?>">
                                         <input type="hidden" name="action" value="delete_note">
                                         <input type="hidden" name="ayah_id" value="<?php echo $note['ayah_id']; ?>">
                                         <button type="submit" style="color: red; background: none; border: none; cursor: pointer;"><i class="fas fa-trash-alt"></i> Delete</button>
                                     </form>
                                 </li>
                             <?php endforeach; ?>
                         </ul>
                     <?php endif; ?>
                 </div>
                 <?php
                 break;

            case 'hifz':
                require_login();
                $hifz_progress = get_hifz_progress($db, get_user_id());
                $surahs = get_surahs($db); // Needed for dropdown
                ?>
                <div class="content">
                    <h2>Hifz Companion</h2>
                    <p>Track your memorization progress.</p>

                    <h3>Your Progress</h3>
                    <?php if (empty($hifz_progress)): ?>
                        <p>You haven't started tracking Hifz yet. Select an Ayah below to begin.</p>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Surah:Ayah</th>
                                    <th>Status</th>
                                    <th>SRS Level</th>
                                    <th>Last Reviewed</th>
                                    <th>Next Review</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hifz_progress as $progress): ?>
                                    <tr>
                                        <td>
                                            <a href="?page=quran&surah=<?php echo $progress['surah_id']; ?>&ayah=<?php echo $progress['ayah_number']; ?>">
                                                <?php echo htmlspecialchars($progress['surah_english_name']); ?> (<?php echo $progress['surah_id']; ?>): <?php echo $progress['ayah_number']; ?>
                                            </a>
                                        </td>
                                        <td><?php echo htmlspecialchars($progress['status']); ?></td>
                                        <td><?php echo $progress['srs_level']; ?></td>
                                        <td><?php echo $progress['last_reviewed'] ? date('Y-m-d', strtotime($progress['last_reviewed'])) : 'N/A'; ?></td>
                                        <td><?php echo $progress['next_review'] ? date('Y-m-d', strtotime($progress['next_review'])) : 'N/A'; ?></td>
                                        <td>
                                            <form action="" method="post" style="display: inline;">
                                                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="action" value="update_hifz_progress">
                                                <input type="hidden" name="surah_id" value="<?php echo $progress['surah_id']; ?>">
                                                <input type="hidden" name="ayah_number" value="<?php echo $progress['ayah_number']; ?>">
                                                <select name="status" onchange="this.form.submit()">
                                                    <option value="not_started" <?php echo $progress['status'] == 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                                                    <option value="learning" <?php echo $progress['status'] == 'learning' ? 'selected' : ''; ?>>Learning</option>
                                                    <option value="memorized" <?php echo $progress['status'] == 'memorized' ? 'selected' : ''; ?>>Memorized</option>
                                                    <option value="review" <?php echo $progress['status'] == 'review' ? 'selected' : ''; ?>>Needs Review</option>
                                                </select>
                                                <!-- SRS Rating (Optional, could be separate buttons) -->
                                                <?php if ($progress['status'] == 'review' || $progress['status'] == 'memorized'): ?>
                                                    <select name="srs_level" onchange="this.form.submit()">
                                                        <option value="<?php echo $progress['srs_level']; ?>">Rate Recall</option>
                                                        <option value="<?php echo max(0, $progress['srs_level'] - 1); ?>">Hard</option>
                                                        <option value="<?php echo $progress['srs_level']; ?>">Good</option>
                                                        <option value="<?php echo $progress['srs_level'] + 1; ?>">Easy</option>
                                                    </select>
                                                <?php endif; ?>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <h3>Add Ayah to Hifz Tracking</h3>
                    <form action="" method="post">
                        <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="update_hifz_progress">
                        <input type="hidden" name="status" value="learning"> <!-- Default to learning when adding -->
                        <div class="form-group">
                            <label for="hifz_surah_id">Surah:</label>
                            <select id="hifz_surah_id" name="surah_id" required>
                                <option value="">Select Surah</option>
                                <?php foreach ($surahs as $surah): ?>
                                    <option value="<?php echo $surah['id']; ?>"><?php echo $surah['id'] . '. ' . htmlspecialchars($surah['english_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="hifz_ayah_number">Ayah Number:</label>
                            <!-- This could be dynamic based on selected surah -->
                            <input type="number" id="hifz_ayah_number" name="ayah_number" min="1" required>
                        </div>
                        <button type="submit" class="btn">Add to Hifz</button>
                    </form>

                    <!-- Test Mode (Placeholder) -->
                    <h3>Test Mode</h3>
                    <p>Coming Soon: Test your memorization by reciting or writing from memory.</p>

                </div>
                <?php
                break;

            case 'reports':
                require_login();
                $period = $_GET['period'] ?? 'daily';
                $reading_data = get_user_reading_report($db, get_user_id(), $period);

                // Prepare data for a simple CSS bar chart
                $labels = [];
                $values = [];
                $max_value = 0;
                foreach ($reading_data as $row) {
                    $labels[] = $row['read_date']; // Or format based on period
                    $values[] = $row['ayah_count'];
                    if ($row['ayah_count'] > $max_value) {
                        $max_value = $row['ayah_count'];
                    }
                }
                $chart_height = 150; // px
                ?>
                <div class="content">
                    <h2>Reading Reports</h2>
                    <p>Track your reading activity.</p>

                    <form action="" method="get">
                        <input type="hidden" name="page" value="reports">
                        <label for="report_period">Select Period:</label>
                        <select name="period" id="report_period" onchange="this.form.submit()">
                            <option value="daily" <?php echo $period == 'daily' ? 'selected' : ''; ?>>Last 7 Days</option>
                            <option value="monthly" <?php echo $period == 'monthly' ? 'selected' : ''; ?>>Last 12 Months</option>
                            <option value="yearly" <?php echo $period == 'yearly' ? 'selected' : ''; ?>>Last 5 Years</option>
                        </select>
                    </form>

                    <h3>Ayahs Read Over Time (<?php echo ucfirst($period); ?>)</h3>

                    <?php if (empty($reading_data)): ?>
                        <p>No reading data available for this period.</p>
                    <?php else: ?>
                        <div class="chart-container">
                            <div class="chart-bar">
                                <?php
                                foreach ($values as $index => $value) {
                                    $bar_height = ($max_value > 0) ? ($value / $max_value) * $chart_height : 0;
                                    echo '<div class="bar" style="height: ' . $bar_height . 'px;"><span>' . $value . '</span></div>';
                                }
                                ?>
                            </div>
                            <div class="chart-labels">
                                <?php foreach ($labels as $label): ?>
                                    <div class="chart-label"><?php echo htmlspecialchars($label); ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Add other reports like Surahs completed etc. -->

                </div>
                <?php
                break;

            case 'settings':
                require_login();
                // User settings form (e.g., change password, preferred translation version)
                ?>
                <div class="content">
                    <h2>Settings</h2>
                    <p>Manage your account settings.</p>
                    <!-- Add forms for changing password, email, etc. -->
                    <h3>Account</h3>
                    <p>Change Password (Coming Soon)</p>
                    <p>Change Email (Coming Soon)</p>

                    <h3>Reading Preferences</h3>
                    <p>Preferred Translation (Coming Soon)</p>
                    <p>Preferred Tafsir (Coming Soon)</p>
                    <p>Tilawat Mode Settings (Managed via Tilawat UI)</p>

                    <h3>Reminders</h3>
                    <p>Set Reading Reminders (Coming Soon - requires browser notification API)</p>
                    <p>Set Hifz Review Reminders (Coming Soon)</p>

                </div>
                <?php
                break;

            case 'search':
                $query = $_GET['query'] ?? '';
                $surah_filter = $_GET['surah_filter'] ?? null;
                // $juz_filter = $_GET['juz_filter'] ?? null; // Juz filter not implemented in search function

                $search_results = [];
                if ($query) {
                    $search_results = search_quran($db, $query, $surah_filter, null, is_logged_in() ? get_user_id() : null);
                }
                $surahs = get_surahs($db); // For filter dropdown
                ?>
                <div class="content">
                    <h2>Search Results</h2>
                    <form action="" method="get">
                        <input type="hidden" name="page" value="search">
                        <div class="form-group">
                            <label for="search_query">Search Query:</label>
                            <input type="text" id="search_query" name="query" value="<?php echo htmlspecialchars($query); ?>" required>
                        </div>
                         <div class="form-group">
                             <label for="surah_filter">Filter by Surah:</label>
                             <select id="surah_filter" name="surah_filter">
                                 <option value="">All Surahs</option>
                                 <?php foreach ($surahs as $surah): ?>
                                     <option value="<?php echo $surah['id']; ?>" <?php echo ($surah_filter == $surah['id']) ? 'selected' : ''; ?>>
                                         <?php echo $surah['id'] . '. ' . htmlspecialchars($surah['english_name']); ?>
                                     </option>
                                 <?php endforeach; ?>
                             </select>
                         </div>
                        <button type="submit" class="btn">Search</button>
                    </form>

                    <?php if ($query): ?>
                        <h3>Results for "<?php echo htmlspecialchars($query); ?>"</h3>
                        <?php if (empty($search_results)): ?>
                            <p>No results found.</p>
                        <?php else: ?>
                            <ul>
                                <?php foreach ($search_results as $result): ?>
                                    <li>
                                        <a href="?page=quran&surah=<?php echo $result['surah_id']; ?>&ayah=<?php echo $result['ayah_number']; ?>">
                                            Surah <?php echo htmlspecialchars($result['surah_english_name']); ?> (<?php echo $result['surah_id']; ?>): Ayah <?php echo $result['ayah_number']; ?>
                                        </a>
                                        <!-- Display snippet with highlighting (basic) -->
                                        <p>...<?php echo str_replace(htmlspecialchars($query), '<strong>' . htmlspecialchars($query) . '</strong>', htmlspecialchars(substr($result['arabic_text'], max(0, strpos($result['arabic_text'], $query) - 50), 100))); ?>...</p>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php
                break;

            case 'admin':
                require_admin();
                $admin_section = $_GET['section'] ?? 'dashboard';
                ?>
                <div class="content admin-panel">
                    <h2>Admin Panel</h2>
                    <nav>
                        <a href="?page=admin&section=dashboard">Dashboard</a>
                        <a href="?page=admin&section=users">User Management</a>
                        <a href="?page=admin&section=content_management">Content Management</a>
                        <a href="?page=admin&section=content_moderation">Content Moderation</a>
                        <a href="?page=admin&section=data_management">Data Management</a>
                        <a href="?page=admin&section=settings">Site Settings</a>
                        <a href="?page=admin&section=analytics">Analytics</a>
                    </nav>

                    <?php
                    switch ($admin_section) {
                        case 'dashboard':
                            ?>
                            <h3>Admin Dashboard</h3>
                            <p>Overview of site activity.</p>
                            <ul>
                                <li>Total Users: <?php echo $db->query("SELECT COUNT(*) FROM users")->fetchColumn(); ?></li>
                                <li>Total Ayahs: <?php echo $db->query("SELECT COUNT(*) FROM ayahs")->fetchColumn(); ?></li>
                                <li>Pending Suggestions: <?php echo $db->query("SELECT COUNT(*) FROM translations WHERE status = 'pending'")->fetchColumn() + $db->query("SELECT COUNT(*) FROM tafasir WHERE status = 'pending'")->fetchColumn() + $db->query("SELECT COUNT(*) FROM word_meanings WHERE status = 'pending'")->fetchColumn(); ?></li>
                                <!-- Add more stats -->
                            </ul>
                            <?php
                            break;
                        case 'users':
                            $users = $db->query("SELECT * FROM users")->fetchAll();
                            ?>
                            <h3>User Management</h3>
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Created At</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><?php echo $user['id']; ?></td>
                                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['role']); ?></td>
                                            <td><?php echo $user['created_at']; ?></td>
                                            <td>
                                                <!-- Add Edit/Delete User actions (with confirmation) -->
                                                <button disabled>Edit</button>
                                                <?php if ($user['role'] !== 'admin'): ?>
                                                    <button disabled>Delete</button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php
                            break;
                        case 'content_management':
                            // Admin can add/edit content directly
                            ?>
                            <h3>Content Management</h3>
                            <p>Add/Edit Translations, Tafasir, Word Meanings.</p>
                            <!-- Forms/interfaces for adding/editing content -->
                            <p>Coming Soon: Interface to add/edit content directly.</p>

                            <h4>Manage Default Versions</h4>
                            <p>Select which version of Translation/Tafsir is shown by default.</p>
                            <!-- List approved content and allow setting default -->
                            <?php
                            $approved_translations = $db->query("SELECT t.*, a.surah_id, a.ayah_number, s.english_name as surah_english_name FROM translations t JOIN ayahs a ON t.ayah_id = a.id JOIN surahs s ON a.surah_id = s.id WHERE t.status = 'approved' ORDER BY a.surah_id, a.ayah_number, t.version_name")->fetchAll();
                            $approved_tafasir = $db->query("SELECT tf.*, a.surah_id as ayah_surah_id, a.ayah_number as ayah_ayah_number, s.english_name as surah_english_name FROM tafasir tf LEFT JOIN ayahs a ON tf.ayah_id = a.id LEFT JOIN surahs s ON tf.surah_id = s.id WHERE tf.status = 'approved' ORDER BY tf.surah_id, tf.ayah_id, tf.version_name")->fetchAll();
                            $approved_word_meanings = $db->query("SELECT wm.*, a.surah_id, a.ayah_number, s.english_name as surah_english_name FROM word_meanings wm JOIN ayahs a ON wm.ayah_id = a.id JOIN surahs s ON a.surah_id = s.id WHERE wm.status = 'approved' ORDER BY a.surah_id, a.ayah_number, wm.word_index, wm.version_name")->fetchAll();
                            ?>
                            <h4>Approved Translations</h4>
                            <ul>
                                <?php foreach ($approved_translations as $item): ?>
                                    <li>
                                        Surah <?php echo $item['surah_id']; ?>: Ayah <?php echo $item['ayah_number']; ?> - Version: <?php echo htmlspecialchars($item['version_name']); ?>
                                        <form action="" method="post" style="display: inline; margin-left: 10px;">
                                            <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="action" value="admin_set_default_content">
                                            <input type="hidden" name="content_type" value="translation">
                                            <input type="hidden" name="content_id" value="<?php echo $item['id']; ?>">
                                            <label>
                                                <input type="checkbox" name="is_default" value="1" <?php echo $item['is_default'] ? 'checked' : ''; ?> onchange="this.form.submit()"> Default
                                            </label>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                             <h4>Approved Tafasir</h4>
                             <ul>
                                 <?php foreach ($approved_tafasir as $item): ?>
                                     <li>
                                         <?php if ($item['ayah_id']): ?>
                                             Surah <?php echo $item['ayah_surah_id']; ?>: Ayah <?php echo $item['ayah_ayah_number']; ?>
                                         <?php elseif ($item['surah_id']): ?>
                                             Surah <?php echo $item['surah_id']; ?> (<?php echo htmlspecialchars($item['surah_english_name']); ?>)
                                         <?php endif; ?>
                                         - Version: <?php echo htmlspecialchars($item['version_name']); ?>
                                         <form action="" method="post" style="display: inline; margin-left: 10px;">
                                             <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo $csrf_token; ?>">
                                             <input type="hidden" name="action" value="admin_set_default_content">
                                             <input type="hidden" name="content_type" value="tafsir">
                                             <input type="hidden" name="content_id" value="<?php echo $item['id']; ?>">
                                             <label>
                                                 <input type="checkbox" name="is_default" value="1" <?php echo $item['is_default'] ? 'checked' : ''; ?> onchange="this.form.submit()"> Default
                                             </label>
                                         </form>
                                     </li>
                                 <?php endforeach; ?>
                             </ul>
                             <h4>Approved Word Meanings</h4>
                             <ul>
                                 <?php foreach ($approved_word_meanings as $item): ?>
                                     <li>
                                         Surah <?php echo $item['surah_id']; ?>: Ayah <?php echo $item['ayah_number']; ?>, Word Index <?php echo $item['word_index']; ?> - Version: <?php echo htmlspecialchars($item['version_name']); ?>
                                         <form action="" method="post" style="display: inline; margin-left: 10px;">
                                             <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo $csrf_token; ?>">
                                             <input type="hidden" name="action" value="admin_set_default_content">
                                             <input type="hidden" name="content_type" value="word_meaning">
                                             <input type="hidden" name="content_id" value="<?php echo $item['id']; ?>">
                                             <label>
                                                 <input type="checkbox" name="is_default" value="1" <?php echo $item['is_default'] ? 'checked' : ''; ?> onchange="this.form.submit()"> Default
                                             </label>
                                         </form>
                                     </li>
                                 <?php endforeach; ?>
                             </ul>

                            <?php
                            break;
                        case 'content_moderation':
                            $pending_suggestions = get_content_suggestions($db, 'pending');
                            ?>
                            <h3>Content Moderation (Pending Suggestions)</h3>
                            <?php if (empty($pending_suggestions)): ?>
                                <p>No pending content suggestions.</p>
                            <?php else: ?>
                                <?php foreach ($pending_suggestions as $suggestion): ?>
                                    <div class="content-item">
                                        <h4><?php echo ucfirst($suggestion['type']); ?> Suggestion</h4>
                                        <p><strong>Contributor:</strong> <?php echo htmlspecialchars($suggestion['contributor_name']); ?></p>
                                        <p><strong>Ayah:</strong> Surah <?php echo $suggestion['ayah_surah_id']; ?>: Ayah <?php echo $suggestion['ayah_ayah_number']; ?></p>
                                        <?php if ($suggestion['type'] === 'word_meaning'): ?>
                                            <p><strong>Word Index:</strong> <?php echo $suggestion['word_index']; ?></p>
                                            <p><strong>Arabic Word:</strong> <?php echo htmlspecialchars($suggestion['arabic_word']); ?></p>
                                            <p><strong>Meaning:</strong> <?php echo nl2br(htmlspecialchars($suggestion['text'])); ?></p>
                                        <?php else: ?>
                                            <p><strong>Version Name:</strong> <?php echo htmlspecialchars($suggestion['version_name']); ?></p>
                                            <p><strong>Content:</strong> <?php echo nl2br(htmlspecialchars($suggestion['text'])); ?></p>
                                        <?php endif; ?>
                                        <p><strong>Suggested On:</strong> <?php echo $suggestion['created_at']; ?></p>

                                        <form action="" method="post" style="display: inline;">
                                            <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="action" value="admin_approve_content">
                                            <input type="hidden" name="content_type" value="<?php echo $suggestion['type']; ?>">
                                            <input type="hidden" name="content_id" value="<?php echo $suggestion['id']; ?>">
                                            <button type="submit" class="btn">Approve</button>
                                        </form>
                                        <form action="" method="post" style="display: inline;">
                                            <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="action" value="admin_reject_content">
                                            <input type="hidden" name="content_type" value="<?php echo $suggestion['type']; ?>">
                                            <input type="hidden" name="content_id" value="<?php echo $suggestion['id']; ?>">
                                            <button type="submit" class="btn" style="background-color: #dc3545;">Reject</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php
                            break;
                        case 'data_management':
                            $backup_files = glob(__DIR__ . '/backups/*.sqlite');
                            rsort($backup_files); // Sort by date descending
                            ?>
                            <h3>Data Management</h3>
                            <h4>Import Quran Data</h4>
                            <p>Import initial Quran text and Urdu translation from the <code>data.AM</code> file.</p>
                            <form action="" method="post">
                                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="admin_import_data">
                                <button type="submit" class="btn" onclick="return confirm('This will import data from data.AM. Existing Ayahs/default translations might be affected depending on import logic. Proceed?');">Import Data from data.AM</button>
                            </form>

                            <h4>Database Backup</h4>
                            <p>Create a backup of the SQLite database.</p>
                            <form action="" method="post">
                                <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="admin_backup_db">
                                <button type="submit" class="btn">Create Backup</button>
                            </form>

                            <h4>Database Restore</h4>
                            <p>Restore the database from a backup file. <strong>Warning: This will overwrite the current database.</strong></p>
                            <?php if (empty($backup_files)): ?>
                                <p>No backup files found.</p>
                            <?php else: ?>
                                <form action="" method="post" onsubmit="return confirm('Are you sure you want to restore the database? This will overwrite all current data.');">
                                    <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="admin_restore_db">
                                    <div class="form-group">
                                        <label for="backup_file">Select Backup File:</label>
                                        <select name="backup_file" id="backup_file" required>
                                            <option value="">-- Select a backup --</option>
                                            <?php foreach ($backup_files as $file): ?>
                                                <option value="<?php echo htmlspecialchars(basename($file)); ?>"><?php echo htmlspecialchars(basename($file)); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" class="btn" style="background-color: #dc3545;">Restore Database</button>
                                </form>
                            <?php endif; ?>

                            <?php
                            break;
                        case 'settings':
                            // Site settings (e.g., default language, themes - basic)
                            ?>
                            <h3>Site Settings</h3>
                            <p>Configure site-wide settings.</p>
                            <p>Coming Soon: Interface for site settings.</p>
                            <?php
                            break;
                        case 'analytics':
                            // Platform analytics (e.g., total users, active users, popular surahs)
                            ?>
                            <h3>Platform Analytics</h3>
                            <p>View site usage statistics.</p>
                            <p>Coming Soon: Analytics reports.</p>
                            <?php
                            break;
                        default:
                            echo '<p>Admin section not found.</p>';
                            break;
                    }
                    ?>
                </div>
                <?php
                break;

            default:
                echo '<div class="content"><p>Page not found.</p></div>';
                break;
        }
        ?>
    </div>

    <!-- Word Meaning Popover -->
    <div class="word-meaning-popover" id="wordMeaningPopover">
        <strong>Meaning:</strong> <span id="popoverMeaning"></span><br>
        <strong>Grammar:</strong> <span id="popoverGrammar"></span>
    </div>

    <!-- Tilawat Mode Container (Initially hidden) -->
    <div id="tilawatModeContainer" class="tilawat-mode-hidden">
        <div class="tilawat-mode-controls">
            <label for="tilawat-surah">Surah:</label>
            <select id="tilawat-surah">
                 <option value="">Select Surah</option>
                 <?php
                 // Need to fetch surahs again for JS dropdown
                 $surahs_for_js = get_surahs($db);
                 foreach ($surahs_for_js as $s): ?>
                     <option value="<?php echo $s['id']; ?>"><?php echo $s['id'] . '. ' . htmlspecialchars($s['english_name']); ?></option>
                 <?php endforeach; ?>
            </select>
            <label for="tilawat-ayah">Ayah:</label>
            <input type="number" id="tilawat-ayah" min="1" value="1">
            <button id="tilawat-go">Go</button>

            <label for="tilawat-lines">Lines/Page:</label>
            <input type="number" id="tilawat-lines" min="5" max="50" value="15">

            <label for="tilawat-font-size">Font Size:</label>
            <input type="range" id="tilawat-font-size" min="1.5" max="3.5" step="0.1" value="2.5">

            <label for="tilawat-view-mode">View Mode:</label>
            <select id="tilawat-view-mode">
                <option value="paginated">Paginated</option>
                <option value="continuous">Continuous Scroll</option>
            </select>

            <button id="tilawat-exit">Exit Tilawat</button>
        </div>
        <div id="tilawatContent">
            <!-- Ayahs will be loaded here by JS -->
        </div>
         <div id="tilawatPagination" class="tilawat-mode-pagination tilawat-mode-hidden">
             <button id="tilawat-prev-page">Previous Page</button>
             <span id="tilawat-page-info"></span>
             <button id="tilawat-next-page">Next Page</button>
         </div>
    </div>


    <script>
        // --- JavaScript for UI Interactions ---

        document.addEventListener('DOMContentLoaded', function() {
            // Toggle Private Note Form
            document.querySelectorAll('.toggle-note-form').forEach(button => {
                button.addEventListener('click', function() {
                    const ayahId = this.dataset.ayahId;
                    const form = document.getElementById('note-form-' + ayahId);
                    if (form) {
                        form.style.display = form.style.display === 'none' ? 'block' : 'none';
                    }
                });
            });

            document.querySelectorAll('.cancel-note-form').forEach(button => {
                 button.addEventListener('click', function() {
                     this.closest('.note-form').style.display = 'none';
                 });
             });

            // Toggle Suggest Content Form
            document.querySelectorAll('.toggle-suggest-form').forEach(button => {
                button.addEventListener('click', function() {
                    const ayahId = this.dataset.ayahId;
                    const form = document.getElementById('suggest-form-' + ayahId);
                    if (form) {
                        form.style.display = form.style.display === 'none' ? 'block' : 'none';
                    }
                });
            });

            document.querySelectorAll('.cancel-suggest-form').forEach(button => {
                 button.addEventListener('click', function() {
                     this.closest('.suggest-form').style.display = 'none';
                 });
             });


            // Word Meaning Popover
            const popover = document.getElementById('wordMeaningPopover');
            const popoverMeaning = document.getElementById('popoverMeaning');
            const popoverGrammar = document.getElementById('popoverGrammar');

            document.querySelectorAll('.arabic-word-clickable').forEach(wordSpan => {
                wordSpan.addEventListener('mouseover', function(e) {
                    const meaning = this.dataset.meaning;
                    const grammar = this.dataset.grammar;

                    popoverMeaning.textContent = meaning || 'N/A';
                    popoverGrammar.textContent = grammar || 'N/A';

                    popover.style.display = 'block';
                    popover.style.left = (e.pageX + 10) + 'px';
                    popover.style.top = (e.pageY + 10) + 'px';
                });

                wordSpan.addEventListener('mouseout', function() {
                    popover.style.display = 'none';
                });
            });

            // Basic Audio Playback (Placeholder)
            document.querySelectorAll('.play-ayah').forEach(button => {
                button.addEventListener('click', function() {
                    const surah = this.dataset.surah;
                    const ayah = this.dataset.ayah;
                    // Replace with actual audio playback logic
                    alert('Playing audio for Surah ' + surah + ' Ayah ' + ayah + ' (Placeholder)');
                    // Example using a hypothetical audio source:
                    // const audio = new Audio(`https://cdn.everyayah.com/audio/Abdul_Basit_Mujawwad_192kbps/${String(surah).padStart(3, '0')}${String(ayah).padStart(3, '0')}.mp3`);
                    // audio.play();
                });
            });


            // --- Tilawat Mode JavaScript ---
            const tilawatIcon = document.querySelector('.tilawat-mode-icon');
            const mainContent = document.querySelector('.container');
            const tilawatContainer = document.getElementById('tilawatModeContainer');
            const tilawatContentDiv = document.getElementById('tilawatContent');
            const tilawatExitButton = document.getElementById('tilawat-exit');
            const tilawatSurahSelect = document.getElementById('tilawat-surah');
            const tilawatAyahInput = document.getElementById('tilawat-ayah');
            const tilawatGoButton = document.getElementById('tilawat-go');
            const tilawatLinesInput = document.getElementById('tilawat-lines');
            const tilawatFontSizeInput = document.getElementById('tilawat-font-size');
            const tilawatViewModeSelect = document.getElementById('tilawat-view-mode');
            const tilawatPagination = document.getElementById('tilawatPagination');
            const tilawatPrevPageButton = document.getElementById('tilawat-prev-page');
            const tilawatNextPageButton = document.getElementById('tilawat-next-page');
            const tilawatPageInfoSpan = document.getElementById('tilawat-page-info');


            let currentTilawatSurah = 1;
            let currentTilawatAyah = 1;
            let tilawatAyahs = []; // Array to hold ayahs for the current surah
            let ayahsPerPage = parseInt(tilawatLinesInput.value);
            let currentTilawatPage = 0;
            let totalTilawatPages = 0;
            let tilawatViewMode = tilawatViewModeSelect.value; // 'paginated' or 'continuous'

            // Load Tilawat settings from localStorage (simple persistence)
            const savedTilawatSettings = JSON.parse(localStorage.getItem('tilawatSettings')) || {};
            currentTilawatSurah = savedTilawatSettings.surah || 1;
            currentTilawatAyah = savedTilawatSettings.ayah || 1;
            ayahsPerPage = savedTilawatSettings.lines || 15;
            tilawatLinesInput.value = ayahsPerPage;
            tilawatFontSizeInput.value = savedTilawatSettings.fontSize || 2.5;
            tilawatViewMode = savedTilawatSettings.viewMode || 'paginated';
            tilawatViewModeSelect.value = tilawatViewMode;


            function saveTilawatSettings() {
                const settings = {
                    surah: currentTilawatSurah,
                    ayah: currentTilawatAyah,
                    lines: ayahsPerPage,
                    fontSize: parseFloat(tilawatFontSizeInput.value),
                    viewMode: tilawatViewModeSelect.value
                };
                localStorage.setItem('tilawatSettings', JSON.stringify(settings));
            }

            function applyTilawatStyles() {
                document.body.classList.add('tilawat-mode-body');
                tilawatContainer.classList.remove('tilawat-mode-hidden');
                mainContent.classList.add('tilawat-mode-hidden');
                document.querySelectorAll('.tilawat-mode-ayah .arabic').forEach(el => {
                    el.style.fontSize = tilawatFontSizeInput.value + 'em';
                });
                 // Hide/show pagination based on view mode
                 if (tilawatViewMode === 'paginated') {
                     tilawatPagination.classList.remove('tilawat-mode-hidden');
                     document.body.style.overflow = 'hidden'; // Hide scrollbar
                 } else {
                     tilawatPagination.classList.add('tilawat-mode-hidden');
                     document.body.style.overflow = 'auto'; // Show scrollbar
                 }
            }

            function removeTilawatStyles() {
                document.body.classList.remove('tilawat-mode-body');
                tilawatContainer.classList.add('tilawat-mode-hidden');
                mainContent.classList.remove('tilawat-mode-hidden');
                document.body.style.overflow = 'auto'; // Restore scrollbar
            }

            async function loadTilawatSurah(surahId) {
                 // Fetch ayahs for the surah (replace with actual PHP endpoint call)
                 // For this single file, we'll simulate fetching or use a pre-loaded structure if possible
                 // A real implementation would need a PHP endpoint like ?action=get_ayahs_json&surah=X
                 // For now, let's assume we can access ayahs data (this is not ideal in a single file)
                 // A better approach is to fetch via a dedicated endpoint.

                 // --- SIMULATED FETCH (Replace with actual fetch) ---
                 // This requires the PHP to output JSON data for ayahs when requested
                 // We'd need a new 'action' or 'page' handler for this.
                 // Example: fetch(`?action=get_ayahs_json&surah=${surahId}`)
                 // .then(response => response.json())
                 // .then(data => { tilawatAyahs = data; renderTilawatPage(); });
                 // --- END SIMULATED FETCH ---

                 // As a workaround for the single file constraint without AJAX:
                 // We cannot dynamically fetch data easily.
                 // A simple approach is to pre-render all ayahs in a hidden div
                 // and then manipulate their display. This is memory intensive for the whole Quran.
                 // A better approach is essential for a real app (dedicated endpoint).

                 // Let's assume for demonstration we have a way to get the ayahs array:
                 // This is NOT a good practice for large data in a single file.
                 // You MUST implement a proper endpoint for fetching data.

                 // Placeholder: In a real app, this would be a fetch call:
                 // const response = await fetch(`?action=get_ayahs_for_tilawat&surah=${surahId}`);
                 // tilawatAyahs = await response.json();

                 // Fallback for single file without AJAX:
                 // We cannot easily load ayahs dynamically per surah without AJAX.
                 // The Tilawat mode will be limited or require pre-loading all ayahs (not recommended).
                 // Let's make a compromise: Tilawat mode will only work if the ayahs for the current
                 // surah are already present on the page (e.g., from the main quran view).
                 // This is a significant limitation of the single-file constraint.

                 // Find ayahs already rendered on the page for the current surah
                 tilawatAyahs = Array.from(document.querySelectorAll(`.ayah[data-surah="${surahId}"]`)).map(ayahDiv => {
                     return {
                         id: ayahDiv.id.replace('ayah-', ''),
                         surah_id: parseInt(ayahDiv.dataset.surah),
                         ayah_number: parseInt(ayahDiv.dataset.ayah),
                         arabic_html: ayahDiv.querySelector('.arabic').innerHTML, // Get rendered HTML
                         translation_html: ayahDiv.querySelector('.translation') ? ayahDiv.querySelector('.translation').innerHTML : '' // Get rendered HTML
                     };
                 });

                 if (tilawatAyahs.length === 0) {
                     tilawatContentDiv.innerHTML = "<p style='color: red;'>Error: Could not load ayahs for Tilawat mode. Please view the Surah in the main interface first.</p>";
                     totalTilawatPages = 0;
                     currentTilawatPage = 0;
                     updateTilawatPagination();
                     return;
                 }

                 currentTilawatSurah = surahId;
                 tilawatAyahInput.value = currentTilawatAyah; // Update input
                 tilawatSurahSelect.value = surahId; // Update select

                 if (tilawatViewMode === 'paginated') {
                     totalTilawatPages = Math.ceil(tilawatAyahs.length / ayahsPerPage);
                     // Find the page containing the current ayah
                     const currentAyahIndex = tilawatAyahs.findIndex(a => a.ayah_number === currentTilawatAyah);
                     currentTilawatPage = currentAyahIndex !== -1 ? Math.floor(currentAyahIndex / ayahsPerPage) : 0;
                     renderTilawatPage();
                 } else { // Continuous scroll
                     renderTilawatContinuous();
                     // Scroll to the current ayah
                     const targetAyahElement = tilawatContentDiv.querySelector(`.tilawat-mode-ayah[data-ayah="${currentTilawatAyah}"]`);
                     if (targetAyahElement) {
                         targetAyahElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                     }
                 }
                 saveTilawatSettings(); // Save current position
            }

            function renderTilawatPage() {
                tilawatContentDiv.innerHTML = ''; // Clear previous content
                const start = currentTilawatPage * ayahsPerPage;
                const end = start + ayahsPerPage;
                const ayahsToDisplay = tilawatAyahs.slice(start, end);

                ayahsToDisplay.forEach(ayah => {
                    const ayahDiv = document.createElement('div');
                    ayahDiv.classList.add('tilawat-mode-ayah');
                    ayahDiv.dataset.surah = ayah.surah_id;
                    ayahDiv.dataset.ayah = ayah.ayah_number;
                    ayahDiv.id = `tilawat-ayah-${ayah.id}`; // Use original ayah ID

                    ayahDiv.innerHTML = `
                        <div class="arabic" style="font-size: ${tilawatFontSizeInput.value}em;">${ayah.arabic_html}</div>
                        <div class="translation">${ayah.translation_html}</div>
                    `;
                    tilawatContentDiv.appendChild(ayahDiv);
                });

                updateTilawatPagination();
            }

            function renderTilawatContinuous() {
                 tilawatContentDiv.innerHTML = ''; // Clear previous content
                 tilawatAyahs.forEach(ayah => {
                     const ayahDiv = document.createElement('div');
                     ayahDiv.classList.add('tilawat-mode-ayah');
                     ayahDiv.dataset.surah = ayah.surah_id;
                     ayahDiv.dataset.ayah = ayah.ayah_number;
                     ayahDiv.id = `tilawat-ayah-${ayah.id}`; // Use original ayah ID

                     ayahDiv.innerHTML = `
                         <div class="arabic" style="font-size: ${tilawatFontSizeInput.value}em;">${ayah.arabic_html}</div>
                         <div class="translation">${ayah.translation_html}</div>
                     `;
                     tilawatContentDiv.appendChild(ayahDiv);
                 });
                 // Intersection Observer for tracking current ayah in continuous mode
                 setupIntersectionObserver();
            }


            function updateTilawatPagination() {
                tilawatPageInfoSpan.textContent = `Page ${currentTilawatPage + 1} of ${totalTilawatPages}`;
                tilawatPrevPageButton.disabled = currentTilawatPage <= 0;
                tilawatNextPageButton.disabled = currentTilawatPage >= totalTilawatPages - 1;
            }

            function goToTilawatPage(pageIndex) {
                if (pageIndex >= 0 && pageIndex < totalTilawatPages) {
                    currentTilawatPage = pageIndex;
                    renderTilawatPage();
                    // Update current ayah to the first ayah on the new page
                    if (tilawatAyahs[currentTilawatPage * ayahsPerPage]) {
                         currentTilawatAyah = tilawatAyahs[currentTilawatPage * ayahsPerPage].ayah_number;
                         tilawatAyahInput.value = currentTilawatAyah;
                         saveTilawatSettings();
                    }
                }
            }

            function goToTilawatAyah(surahId, ayahNumber) {
                 // Find the ayah in the loaded data
                 const ayahIndex = tilawatAyahs.findIndex(a => a.surah_id === surahId && a.ayah_number === ayahNumber);

                 if (ayahIndex !== -1) {
                     currentTilawatSurah = surahId;
                     currentTilawatAyah = ayahNumber;
                     tilawatSurahSelect.value = surahId;
                     tilawatAyahInput.value = ayahNumber;

                     if (tilawatViewMode === 'paginated') {
                         const targetPage = Math.floor(ayahIndex / ayahsPerPage);
                         goToTilawatPage(targetPage);
                     } else { // Continuous scroll
                         const targetAyahElement = tilawatContentDiv.querySelector(`.tilawat-mode-ayah[data-surah="${surahId}"][data-ayah="${ayahNumber}"]`);
                         if (targetAyahElement) {
                             targetAyahElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                         }
                     }
                     saveTilawatSettings();
                 } else {
                     // If ayah not found in current surah's loaded data, try loading the surah
                     loadTilawatSurah(surahId).then(() => {
                         // After loading, try scrolling again
                         const targetAyahElement = tilawatContentDiv.querySelector(`.tilawat-mode-ayah[data-surah="${surahId}"][data-ayah="${ayahNumber}"]`);
                         if (targetAyahElement) {
                             currentTilawatAyah = ayahNumber; // Update current ayah after loading
                             tilawatAyahInput.value = ayahNumber;
                             if (tilawatViewMode === 'paginated') {
                                 const ayahIndexAfterLoad = tilawatAyahs.findIndex(a => a.surah_id === surahId && a.ayah_number === ayahNumber);
                                 const targetPage = Math.floor(ayahIndexAfterLoad / ayahsPerPage);
                                 goToTilawatPage(targetPage);
                             } else {
                                 targetAyahElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                             }
                             saveTilawatSettings();
                         } else {
                             console.error("Ayah not found after loading surah.");
                         }
                     }).catch(error => {
                         console.error("Error loading surah for Tilawat:", error);
                     });
                 }
            }


            // Intersection Observer for Continuous Scroll
            let observer = null;
            function setupIntersectionObserver() {
                if (observer) observer.disconnect(); // Disconnect previous observer

                observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const ayahElement = entry.target;
                            const surahId = parseInt(ayahElement.dataset.surah);
                            const ayahNumber = parseInt(ayahElement.dataset.ayah);
                            // Update current position as the first visible ayah
                            currentTilawatSurah = surahId;
                            currentTilawatAyah = ayahNumber;
                            tilawatSurahSelect.value = surahId;
                            tilawatAyahInput.value = ayahNumber;
                            saveTilawatSettings();
                        }
                    });
                }, {
                    root: tilawatContentDiv, // Observe within the tilawat content area
                    rootMargin: '0px',
                    threshold: 0.5 // Trigger when 50% of the item is visible
                });

                // Observe all ayah elements in the tilawat content
                tilawatContentDiv.querySelectorAll('.tilawat-mode-ayah').forEach(ayahElement => {
                    observer.observe(ayahElement);
                });
            }


            // Event Listeners for Tilawat Mode
            if (tilawatIcon) {
                tilawatIcon.addEventListener('click', function() {
                    applyTilawatStyles();
                    // Load the last read surah/ayah or default to 1:1
                    loadTilawatSurah(currentTilawatSurah).then(() => {
                         // After loading, go to the specific ayah
                         goToTilawatAyah(currentTilawatSurah, currentTilawatAyah);
                    });
                });
            }

            if (tilawatExitButton) {
                tilawatExitButton.addEventListener('click', function() {
                    removeTilawatStyles();
                    // Optionally scroll back to the ayah in the main view
                    const mainAyahElement = document.getElementById(`ayah-${tilawatAyahs.find(a => a.surah_id === currentTilawatSurah && a.ayah_number === currentTilawatAyah)?.id}`);
                    if (mainAyahElement) {
                        mainAyahElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                });
            }

            if (tilawatGoButton) {
                tilawatGoButton.addEventListener('click', function() {
                    const targetSurah = parseInt(tilawatSurahSelect.value);
                    const targetAyah = parseInt(tilawatAyahInput.value);
                    if (!isNaN(targetSurah) && !isNaN(targetAyah)) {
                        // Check if target surah is the current one
                        if (targetSurah === currentTilawatSurah) {
                            goToTilawatAyah(targetSurah, targetAyah);
                        } else {
                            // Load the new surah and then go to the ayah
                            loadTilawatSurah(targetSurah).then(() => {
                                goToTilawatAyah(targetSurah, targetAyah);
                            });
                        }
                    }
                });
            }

            if (tilawatLinesInput) {
                tilawatLinesInput.addEventListener('change', function() {
                    ayahsPerPage = parseInt(this.value);
                    if (tilawatViewMode === 'paginated') {
                        totalTilawatPages = Math.ceil(tilawatAyahs.length / ayahsPerPage);
                        // Recalculate current page based on current ayah
                        const currentAyahIndex = tilawatAyahs.findIndex(a => a.surah_id === currentTilawatSurah && a.ayah_number === currentTilawatAyah);
                        currentTilawatPage = currentAyahIndex !== -1 ? Math.floor(currentAyahIndex / ayahsPerPage) : 0;
                        renderTilawatPage();
                    }
                    saveTilawatSettings();
                });
            }

            if (tilawatFontSizeInput) {
                tilawatFontSizeInput.addEventListener('input', function() {
                    document.querySelectorAll('.tilawat-mode-ayah .arabic').forEach(el => {
                        el.style.fontSize = this.value + 'em';
                    });
                    saveTilawatSettings();
                });
            }

            if (tilawatViewModeSelect) {
                tilawatViewModeSelect.addEventListener('change', function() {
                    tilawatViewMode = this.value;
                    if (tilawatViewMode === 'paginated') {
                        totalTilawatPages = Math.ceil(tilawatAyahs.length / ayahsPerPage);
                        // Recalculate current page based on current ayah
                        const currentAyahIndex = tilawatAyahs.findIndex(a => a.surah_id === currentTilawatSurah && a.ayah_number === currentTilawatAyah);
                        currentTilawatPage = currentAyahIndex !== -1 ? Math.floor(currentAyahIndex / ayahsPerPage) : 0;
                        renderTilawatPage();
                        tilawatPagination.classList.remove('tilawat-mode-hidden');
                        document.body.style.overflow = 'hidden';
                        if (observer) observer.disconnect(); // Stop observing in paginated mode
                    } else { // Continuous
                        renderTilawatContinuous();
                        tilawatPagination.classList.add('tilawat-mode-hidden');
                        document.body.style.overflow = 'auto';
                        // Scroll to the current ayah after rendering
                        const targetAyahElement = tilawatContentDiv.querySelector(`.tilawat-mode-ayah[data-surah="${currentTilawatSurah}"][data-ayah="${currentTilawatAyah}"]`);
                        if (targetAyahElement) {
                            targetAyahElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }
                    saveTilawatSettings();
                });
            }

            if (tilawatPrevPageButton) {
                tilawatPrevPageButton.addEventListener('click', function() {
                    goToTilawatPage(currentTilawatPage - 1);
                });
            }

            if (tilawatNextPageButton) {
                tilawatNextPageButton.addEventListener('click', function() {
                    goToTilawatPage(currentTilawatPage + 1);
                });
            }

            // Keyboard Navigation (Left/Right arrows for pages)
            document.addEventListener('keydown', function(event) {
                if (!tilawatContainer.classList.contains('tilawat-mode-hidden') && tilawatViewMode === 'paginated') {
                    if (event.key === 'ArrowLeft') {
                        goToTilawatPage(currentTilawatPage - 1);
                    } else if (event.key === 'ArrowRight') {
                        goToTilawatPage(currentTilawatPage + 1);
                    }
                }
            });

            // Swipe Navigation (Basic - requires touch events)
            let touchStartX = 0;
            if (tilawatContainer) {
                tilawatContainer.addEventListener('touchstart', function(event) {
                    if (tilawatViewMode === 'paginated') {
                        touchStartX = event.touches[0].clientX;
                    }
                });

                tilawatContainer.addEventListener('touchend', function(event) {
                    if (tilawatViewMode === 'paginated') {
                        const touchEndX = event.changedTouches[0].clientX;
                        const swipeDistance = touchEndX - touchStartX;
                        const swipeThreshold = 50; // Minimum distance for a swipe

                        if (swipeDistance > swipeThreshold) {
                            // Swiped right (go to previous page)
                            goToTilawatPage(currentTilawatPage - 1);
                        } else if (swipeDistance < -swipeThreshold) {
                            // Swiped left (go to next page)
                            goToTilawatPage(currentTilawatPage + 1);
                        }
                    }
                });
            }

            // Populate Tilawat Surah Select on load
            // This is already done in PHP rendering, but if using AJAX, would need to fetch.
            // For single file, the PHP rendered options are used.

        }); // End DOMContentLoaded
    </script>

</body>
</html>
<?php
// Close the database connection (optional, PHP closes automatically at script end)
$db = null;
?>
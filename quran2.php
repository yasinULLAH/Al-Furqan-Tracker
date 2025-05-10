<?php
ob_start();
/**
 * Quran Study Hub
 * Author: Yasin Ullah
 * Pakistani
 */

// Configuration
define('DB_PATH', __DIR__ . '/quran_study_hub.sqlite');
define('DATA_FILE', __DIR__ . '/data.AM');
define('APP_NAME', 'Quran Study Hub');
define('ADMIN_EMAIL', 'admin@example.com'); // Replace with actual admin email

// Database Initialization
function init_db() {
    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create tables if they don't exist
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            role TEXT DEFAULT 'user', -- 'user', 'admin'
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS quran (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            surah_number INTEGER NOT NULL,
            ayah_number INTEGER NOT NULL,
            arabic_text TEXT NOT NULL,
            urdu_translation TEXT NOT NULL,
            juz INTEGER,
            hizb INTEGER,
            UNIQUE(surah_number, ayah_number)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS tafsir (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quran_id INTEGER NOT NULL,
            source TEXT, -- e.g., 'Ibn Kathir', 'Maududi'
            text TEXT NOT NULL,
            version INTEGER DEFAULT 1,
            status TEXT DEFAULT 'pending', -- 'pending', 'approved', 'rejected'
            created_by INTEGER, -- user id
            approved_by INTEGER, -- admin id
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (quran_id) REFERENCES quran(id),
            FOREIGN KEY (created_by) REFERENCES users(id),
            FOREIGN KEY (approved_by) REFERENCES users(id)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS word_meanings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quran_id INTEGER NOT NULL,
            word TEXT NOT NULL,
            meaning TEXT NOT NULL,
            version INTEGER DEFAULT 1,
            status TEXT DEFAULT 'pending', -- 'pending', 'approved', 'rejected'
            created_by INTEGER, -- user id
            approved_by INTEGER, -- admin id
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (quran_id) REFERENCES quran(id),
            FOREIGN KEY (created_by) REFERENCES users(id),
            FOREIGN KEY (approved_by) REFERENCES users(id)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS user_reading_progress (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            quran_id INTEGER NOT NULL,
            read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (quran_id) REFERENCES quran(id)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS user_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER UNIQUE NOT NULL,
            last_read_quran_id INTEGER,
            tilawat_mode_settings TEXT, -- JSON string
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (last_read_quran_id) REFERENCES quran(id)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS user_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            quran_id INTEGER NOT NULL,
            note TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (quran_id) REFERENCES quran(id)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS reminders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            type TEXT NOT NULL, -- 'reading', 'hifz'
            time TEXT NOT NULL, -- HH:MM format
            days TEXT, -- Comma-separated days (e.g., 'Mon,Tue,Wed')
            message TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )");

        // Check if Quran data is already imported
        $count = $db->query("SELECT COUNT(*) FROM quran")->fetchColumn();
        if ($count == 0) {
            import_quran_data($db);
        }

    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}

// Import Quran Data
function import_quran_data($db) {
    if (!file_exists(DATA_FILE)) {
        echo "<p>Error: Quran data file '" . DATA_FILE . "' not found. Please place the data.AM file in the same directory as the PHP script.</p>";
        return;
    }

    $file = fopen(DATA_FILE, 'r');
    if (!$file) {
        echo "<p>Error: Could not open Quran data file for reading.</p>";
        return;
    }

    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO quran (surah_number, ayah_number, arabic_text, urdu_translation) VALUES (?, ?, ?, ?)");

    $line_num = 0;
    while (($line = fgets($file)) !== false) {
        $line_num++;
        $line = trim($line);
        if (empty($line)) continue;

        // Parse the line
        $parts = explode('<br/>س ', $line);
        if (count($parts) != 2) {
            echo "<p>Warning: Skipping malformed line " . $line_num . ": " . htmlspecialchars($line) . "</p>";
            continue;
        }

        $text_part = $parts[0];
        $meta_part = $parts[1];

        $text_parts = explode(' ترجمہ: ', $text_part);
        if (count($text_parts) != 2) {
            echo "<p>Warning: Skipping malformed text part on line " . $line_num . ": " . htmlspecialchars($text_part) . "</p>";
            continue;
        }
        $arabic_text = trim($text_parts[0]);
        $urdu_translation = trim($text_parts[1]);

        $meta_parts = explode(' آ ', $meta_part);
        if (count($meta_parts) != 2) {
            echo "<p>Warning: Skipping malformed meta part on line " . $line_num . ": " . htmlspecialchars($meta_part) . "</p>";
            continue;
        }
        $surah_number_str = trim($meta_parts[0]);
        $ayah_number_str = trim($meta_parts[1]);

        $surah_number = (int)$surah_number_str;
        $ayah_number = (int)$ayah_number_str;

        if ($surah_number <= 0 || $ayah_number <= 0) {
             echo "<p>Warning: Skipping invalid surah/ayah number on line " . $line_num . ": Surah " . htmlspecialchars($surah_number_str) . ", Ayah " . htmlspecialchars($ayah_number_str) . "</p>";
             continue;
        }

        try {
            $stmt->execute([$surah_number, $ayah_number, $arabic_text, $urdu_translation]);
        } catch (PDOException $e) {
            echo "<p>Error inserting data for line " . $line_num . ": " . $e->getMessage() . "</p>";
            $db->rollBack();
            fclose($file);
            return;
        }
    }

    $db->commit();
    fclose($file);
    echo "<p>Quran data import complete.</p>";
}

// Database Connection
function get_db() {
    static $db = null;
    if ($db === null) {
        try {
            $db = new PDO('sqlite:' . DB_PATH);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("Database connection error: " . $e->getMessage());
        }
    }
    return $db;
}

// Authentication Functions
function hash_password($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

function create_user($email, $password, $role = 'user') {
    $db = get_db();
    $hashed_password = hash_password($password);
    try {
        $stmt = $db->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, ?)");
        $stmt->execute([$email, $hashed_password, $role]);
        return $db->lastInsertId();
    } catch (PDOException $e) {
        // Handle duplicate email or other errors
        return false;
    }
}

function get_user_by_email($email) {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function get_user_by_id($user_id) {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function login_user($email, $password) {
    $user = get_user_by_email($email);
    if ($user && verify_password($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        return true;
    }
    return false;
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function is_admin() {
    return is_logged_in() && $_SESSION['user_role'] === 'admin';
}

function logout_user() {
    session_unset();
    session_destroy();
}

// Quran Data Retrieval
function get_surahs() {
    $db = get_db();
    $stmt = $db->query("SELECT DISTINCT surah_number FROM quran ORDER BY surah_number");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function get_ayahs_by_surah($surah_number) {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM quran WHERE surah_number = ? ORDER BY ayah_number");
    $stmt->execute([$surah_number]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_ayah_by_surah_ayah($surah_number, $ayah_number) {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM quran WHERE surah_number = ? AND ayah_number = ?");
    $stmt->execute([$surah_number, $ayah_number]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function get_ayah_by_id($quran_id) {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM quran WHERE id = ?");
    $stmt->execute([$quran_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function get_tafsir_by_quran_id($quran_id, $status = 'approved') {
    $db = get_db();
    $stmt = $db->prepare("SELECT t.*, u.email as created_by_email FROM tafsir t LEFT JOIN users u ON t.created_by = u.id WHERE t.quran_id = ? AND t.status = ? ORDER BY t.version DESC");
    $stmt->execute([$quran_id, $status]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_word_meanings_by_quran_id($quran_id, $status = 'approved') {
    $db = get_db();
    $stmt = $db->prepare("SELECT wm.*, u.email as created_by_email FROM word_meanings wm LEFT JOIN users u ON wm.created_by = u.id WHERE wm.quran_id = ? AND wm.status = ? ORDER BY wm.version DESC");
    $stmt->execute([$quran_id, $status]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Content Contribution
function add_tafsir($quran_id, $source, $text, $user_id) {
    $db = get_db();
    $status = is_admin() ? 'approved' : 'pending';
    $approved_by = is_admin() ? $user_id : null;

    // Check for existing tafsir for versioning (basic)
    $latest_version = $db->prepare("SELECT MAX(version) FROM tafsir WHERE quran_id = ? AND source = ?");
    $latest_version->execute([$quran_id, $source]);
    $version = $latest_version->fetchColumn() + 1;

    $stmt = $db->prepare("INSERT INTO tafsir (quran_id, source, text, version, status, created_by, approved_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    return $stmt->execute([$quran_id, $source, $text, $version, $status, $user_id, $approved_by]);
}

function add_word_meaning($quran_id, $word, $meaning, $user_id) {
    $db = get_db();
    $status = is_admin() ? 'approved' : 'pending';
    $approved_by = is_admin() ? $user_id : null;

    // Check for existing meaning for versioning (basic)
    $latest_version = $db->prepare("SELECT MAX(version) FROM word_meanings WHERE quran_id = ? AND word = ?");
    $latest_version->execute([$quran_id, $word]);
    $version = $latest_version->fetchColumn() + 1;

    $stmt = $db->prepare("INSERT INTO word_meanings (quran_id, word, meaning, version, status, created_by, approved_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    return $stmt->execute([$quran_id, $word, $meaning, $version, $status, $user_id, $approved_by]);
}

function get_pending_contributions() {
    if (!is_admin()) return [];
    $db = get_db();
    $pending_tafsir = $db->query("SELECT t.*, q.surah_number, q.ayah_number, u.email as created_by_email FROM tafsir t JOIN quran q ON t.quran_id = q.id LEFT JOIN users u ON t.created_by = u.id WHERE t.status = 'pending'")->fetchAll(PDO::FETCH_ASSOC);
    $pending_word_meanings = $db->query("SELECT wm.*, q.surah_number, q.ayah_number, u.email as created_by_email FROM word_meanings wm JOIN quran q ON wm.quran_id = q.id LEFT JOIN users u ON wm.created_by = u.id WHERE wm.status = 'pending'")->fetchAll(PDO::FETCH_ASSOC);
    return ['tafsir' => $pending_tafsir, 'word_meanings' => $pending_word_meanings];
}

function approve_contribution($type, $id) {
    if (!is_admin()) return false;
    $db = get_db();
    $user_id = $_SESSION['user_id'];
    if ($type === 'tafsir') {
        $stmt = $db->prepare("UPDATE tafsir SET status = 'approved', approved_by = ? WHERE id = ? AND status = 'pending'");
        return $stmt->execute([$user_id, $id]);
    } elseif ($type === 'word_meaning') {
        $stmt = $db->prepare("UPDATE word_meanings SET status = 'approved', approved_by = ? WHERE id = ? AND status = 'pending'");
        return $stmt->execute([$user_id, $id]);
    }
    return false;
}

function reject_contribution($type, $id) {
    if (!is_admin()) return false;
    $db = get_db();
    if ($type === 'tafsir') {
        $stmt = $db->prepare("UPDATE tafsir SET status = 'rejected' WHERE id = ? AND status = 'pending'");
        return $stmt->execute([$id]);
    } elseif ($type === 'word_meaning') {
        $stmt = $db->prepare("UPDATE word_meanings SET status = 'rejected' WHERE id = ? AND status = 'pending'");
        return $stmt->execute([$id]);
    }
    return false;
}

// User Reading Tracking
function track_reading($quran_id) {
    if (!is_logged_in()) return false;
    $db = get_db();
    $user_id = $_SESSION['user_id'];
    $stmt = $db->prepare("INSERT INTO user_reading_progress (user_id, quran_id) VALUES (?, ?)");
    return $stmt->execute([$user_id, $quran_id]);
}

function get_last_read_quran_id($user_id) {
    $db = get_db();
    $stmt = $db->prepare("SELECT last_read_quran_id FROM user_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['last_read_quran_id'] : null;
}

function set_last_read_quran_id($user_id, $quran_id) {
    $db = get_db();
    // Use INSERT OR REPLACE to handle both insert and update
    $stmt = $db->prepare("INSERT OR REPLACE INTO user_settings (user_id, last_read_quran_id) VALUES (?, ?)");
    return $stmt->execute([$user_id, $quran_id]);
}

// User Reading Reports
function get_reading_report($user_id, $period = 'daily') {
    $db = get_db();
    $sql = "";
    $params = [$user_id];

    switch ($period) {
        case 'daily':
            $sql = "SELECT DATE(read_at) as date, COUNT(DISTINCT quran_id) as ayahs_read FROM user_reading_progress WHERE user_id = ? GROUP BY DATE(read_at) ORDER BY date DESC";
            break;
        case 'monthly':
            $sql = "SELECT STRFTIME('%Y-%m', read_at) as period, COUNT(DISTINCT quran_id) as ayahs_read FROM user_reading_progress WHERE user_id = ? GROUP BY period ORDER BY period DESC";
            break;
        case 'yearly':
            $sql = "SELECT STRFTIME('%Y', read_at) as period, COUNT(DISTINCT quran_id) as ayahs_read FROM user_reading_progress WHERE user_id = ? GROUP BY period ORDER BY period DESC";
            break;
        default:
            return [];
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// User Settings (Tilawat Mode)
function get_user_settings($user_id) {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM user_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($settings && $settings['tilawat_mode_settings']) {
        $settings['tilawat_mode_settings'] = json_decode($settings['tilawat_mode_settings'], true);
    } else {
        $settings['tilawat_mode_settings'] = []; // Default empty settings
    }
    return $settings;
}

function save_user_settings($user_id, $settings) {
    $db = get_db();
    $tilawat_mode_settings_json = json_encode($settings['tilawat_mode_settings']);
    $last_read_quran_id = $settings['last_read_quran_id'] ?? null;

    // Use INSERT OR REPLACE to handle both insert and update
    $stmt = $db->prepare("INSERT OR REPLACE INTO user_settings (user_id, last_read_quran_id, tilawat_mode_settings) VALUES (?, ?, ?)");
    return $stmt->execute([$user_id, $last_read_quran_id, $tilawat_mode_settings_json]);
}

// Reminders
function add_reminder($user_id, $type, $time, $days, $message) {
    $db = get_db();
    $stmt = $db->prepare("INSERT INTO reminders (user_id, type, time, days, message) VALUES (?, ?, ?, ?, ?)");
    return $stmt->execute([$user_id, $type, $time, $days, $message]);
}

function get_reminders($user_id) {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM reminders WHERE user_id = ? ORDER BY time");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function delete_reminder($reminder_id, $user_id) {
    $db = get_db();
    $stmt = $db->prepare("DELETE FROM reminders WHERE id = ? AND user_id = ?");
    return $stmt->execute([$reminder_id, $user_id]);
}

// Search
function search_quran($query) {
    $db = get_db();
    // Basic fuzzy search using LIKE
    $search_term = '%' . $query . '%';
    $stmt = $db->prepare("SELECT q.* FROM quran q WHERE q.arabic_text LIKE ? OR q.urdu_translation LIKE ? ORDER BY q.surah_number, q.ayah_number");
    $stmt->execute([$search_term, $search_term]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function search_tafsir($query) {
    $db = get_db();
    $search_term = '%' . $query . '%';
    $stmt = $db->prepare("SELECT t.*, q.surah_number, q.ayah_number FROM tafsir t JOIN quran q ON t.quran_id = q.id WHERE t.text LIKE ? AND t.status = 'approved' ORDER BY q.surah_number, q.ayah_number, t.version DESC");
    $stmt->execute([$search_term]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function search_notes($user_id, $query) {
    $db = get_db();
    $search_term = '%' . $query . '%';
    $stmt = $db->prepare("SELECT un.*, q.surah_number, q.ayah_number FROM user_notes un JOIN quran q ON un.quran_id = q.id WHERE un.user_id = ? AND un.note LIKE ? ORDER BY q.surah_number, q.ayah_number, un.created_at DESC");
    $stmt->execute([$user_id, $search_term]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// User Notes
function add_note($user_id, $quran_id, $note) {
    $db = get_db();
    $stmt = $db->prepare("INSERT INTO user_notes (user_id, quran_id, note) VALUES (?, ?, ?)");
    return $stmt->execute([$user_id, $quran_id, $note]);
}

function get_notes_by_quran_id($user_id, $quran_id) {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM user_notes WHERE user_id = ? AND quran_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id, $quran_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function delete_note($note_id, $user_id) {
    $db = get_db();
    $stmt = $db->prepare("DELETE FROM user_notes WHERE id = ? AND user_id = ?");
    return $stmt->execute([$note_id, $user_id]);
}

// Admin Panel Functions
function get_all_users() {
    if (!is_admin()) return [];
    $db = get_db();
    $stmt = $db->query("SELECT id, email, role, created_at FROM users");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function update_user_role($user_id, $role) {
    if (!is_admin()) return false;
    $db = get_db();
    $stmt = $db->prepare("UPDATE users SET role = ? WHERE id = ?");
    return $stmt->execute([$role, $user_id]);
}

function delete_user($user_id) {
    if (!is_admin()) return false;
    $db = get_db();
    // Delete related data first (notes, progress, settings, reminders, contributions)
    $db->beginTransaction();
    try {
        $stmt_notes = $db->prepare("DELETE FROM user_notes WHERE user_id = ?");
        $stmt_notes->execute([$user_id]);

        $stmt_progress = $db->prepare("DELETE FROM user_reading_progress WHERE user_id = ?");
        $stmt_progress->execute([$user_id]);

        $stmt_settings = $db->prepare("DELETE FROM user_settings WHERE user_id = ?");
        $stmt_settings->execute([$user_id]);

        $stmt_reminders = $db->prepare("DELETE FROM reminders WHERE user_id = ?");
        $stmt_reminders->execute([$user_id]);

        // Note: Contributions created by the user are not deleted, just the user record.
        // Their contributions remain but the created_by link might become invalid if not handled.
        // For simplicity here, we just delete the user.

        $stmt_user = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt_user->execute([$user_id]);

        $db->commit();
        return true;
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Error deleting user " . $user_id . ": " . $e->getMessage());
        return false;
    }
}

function backup_database() {
    if (!is_admin()) return false;
    $backup_file = 'backup_' . date('Ymd_His') . '.sqlite';
    $backup_path = __DIR__ . '/' . $backup_file;

    if (copy(DB_PATH, $backup_path)) {
        return $backup_file;
    } else {
        return false;
    }
}

function restore_database($backup_file) {
    if (!is_admin()) return false;
    $backup_path = __DIR__ . '/' . basename($backup_file); // Sanitize filename

    if (!file_exists($backup_path)) {
        return false;
    }

    // Close existing DB connection before replacing the file
    $db = null;

    if (copy($backup_path, DB_PATH)) {
        // Re-initialize DB connection after restore
        $db = get_db();
        return true;
    } else {
        return false;
    }
}

function get_backups() {
    if (!is_admin()) return [];
    $backups = glob(__DIR__ . '/backup_*.sqlite');
    usort($backups, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    return array_map('basename', $backups);
}

function delete_backup($backup_file) {
    if (!is_admin()) return false;
    $backup_path = __DIR__ . '/' . basename($backup_file); // Sanitize filename
    if (file_exists($backup_path)) {
        return unlink($backup_path);
    }
    return false;
}


// Helper to get Surah Name (basic placeholder)
function get_surah_name($surah_number) {
    // In a real app, you'd have a lookup table or array for Surah names
    $surah_names = [
        1 => 'Al-Fatiha', 2 => 'Al-Baqarah', /* ... add more ... */ 114 => 'An-Nas'
    ];
    return $surah_names[$surah_number] ?? 'Surah ' . $surah_number;
}

// Routing and Request Handling
session_start();
init_db();

$action = $_GET['action'] ?? $_POST['action'] ?? 'home';
$message = '';
$error = '';

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($action) {
        case 'register':
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            if (empty($email) || empty($password)) {
                $error = "Email and password are required.";
            } elseif (get_user_by_email($email)) {
                $error = "Email already registered.";
            } else {
                if (create_user($email, $password)) {
                    $message = "Registration successful. You can now login.";
                    // Optional: Auto-login after registration
                    // login_user($email, $password);
                    // header('Location: ?action=dashboard'); exit;
                } else {
                    $error = "Registration failed. Please try again.";
                }
            }
            $action = 'login_form'; // Show login form after registration attempt
            break;

        case 'login':
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            if (login_user($email, $password)) {
                header('Location: ?action=dashboard');
                exit;
            } else {
                $error = "Invalid email or password.";
                $action = 'login_form'; // Show login form again
            }
            break;

        case 'logout':
            logout_user();
            header('Location: ?action=home');
            exit;

        case 'add_tafsir':
            if (is_logged_in()) {
                $quran_id = $_POST['quran_id'] ?? 0;
                $source = $_POST['source'] ?? '';
                $text = $_POST['text'] ?? '';
                if ($quran_id && !empty($text)) {
                    if (add_tafsir($quran_id, $source, $text, $_SESSION['user_id'])) {
                        $message = is_admin() ? "Tafsir added successfully." : "Tafsir suggestion submitted for approval.";
                    } else {
                        $error = "Failed to add tafsir.";
                    }
                } else {
                    $error = "Missing required fields for tafsir.";
                }
                // Redirect back to the ayah page
                $ayah_info = get_ayah_by_id($quran_id);
                if ($ayah_info) {
                     header('Location: ?action=read&surah=' . $ayah_info['surah_number'] . '&ayah=' . $ayah_info['ayah_number']);
                     exit;
                } else {
                     header('Location: ?action=dashboard');
                     exit;
                }
            } else {
                $error = "You must be logged in to add tafsir.";
                $action = 'login_form';
            }
            break;

        case 'add_word_meaning':
             if (is_logged_in()) {
                $quran_id = $_POST['quran_id'] ?? 0;
                $word = $_POST['word'] ?? '';
                $meaning = $_POST['meaning'] ?? '';
                if ($quran_id && !empty($word) && !empty($meaning)) {
                    if (add_word_meaning($quran_id, $word, $meaning, $_SESSION['user_id'])) {
                        $message = is_admin() ? "Word meaning added successfully." : "Word meaning suggestion submitted for approval.";
                    } else {
                        $error = "Failed to add word meaning.";
                    }
                } else {
                    $error = "Missing required fields for word meaning.";
                }
                 // Redirect back to the ayah page
                $ayah_info = get_ayah_by_id($quran_id);
                if ($ayah_info) {
                     header('Location: ?action=read&surah=' . $ayah_info['surah_number'] . '&ayah=' . $ayah_info['ayah_number']);
                     exit;
                } else {
                     header('Location: ?action=dashboard');
                     exit;
                }
            } else {
                $error = "You must be logged in to add word meanings.";
                $action = 'login_form';
            }
            break;

        case 'approve_contribution':
            if (is_admin()) {
                $type = $_POST['type'] ?? '';
                $id = $_POST['id'] ?? 0;
                if (($type === 'tafsir' || $type === 'word_meaning') && $id > 0) {
                    if (approve_contribution($type, $id)) {
                        $message = ucfirst($type) . " contribution approved.";
                    } else {
                        $error = "Failed to approve " . $type . " contribution.";
                    }
                } else {
                    $error = "Invalid contribution type or ID.";
                }
                $action = 'admin_panel';
            } else {
                $error = "Unauthorized access.";
                $action = 'home';
            }
            break;

        case 'reject_contribution':
            if (is_admin()) {
                $type = $_POST['type'] ?? '';
                $id = $_POST['id'] ?? 0;
                if (($type === 'tafsir' || $type === 'word_meaning') && $id > 0) {
                    if (reject_contribution($type, $id)) {
                        $message = ucfirst($type) . " contribution rejected.";
                    } else {
                        $error = "Failed to reject " . $type . " contribution.";
                    }
                } else {
                    $error = "Invalid contribution type or ID.";
                }
                $action = 'admin_panel';
            } else {
                $error = "Unauthorized access.";
                $action = 'home';
            }
            break;

        case 'track_reading':
            if (is_logged_in()) {
                $quran_id = $_POST['quran_id'] ?? 0;
                if ($quran_id > 0) {
                    track_reading($quran_id); // No message needed, happens in background
                }
            }
            // This action is typically called via JS, no page redirect needed
            exit;

        case 'save_settings':
            if (is_logged_in()) {
                $user_id = $_SESSION['user_id'];
                $settings = get_user_settings($user_id); // Get existing settings
                $settings['last_read_quran_id'] = $_POST['last_read_quran_id'] ?? $settings['last_read_quran_id'];
                $settings['tilawat_mode_settings'] = json_decode($_POST['tilawat_mode_settings'] ?? '{}', true); // Expect JSON string from JS

                if (save_user_settings($user_id, $settings)) {
                    $message = "Settings saved.";
                } else {
                    $error = "Failed to save settings.";
                }
            } else {
                $error = "You must be logged in to save settings.";
            }
             // Redirect back to the page where settings were saved (e.g., dashboard or read page)
             // For simplicity, redirect to dashboard
             header('Location: ?action=dashboard');
             exit;

        case 'add_reminder':
            if (is_logged_in()) {
                $user_id = $_SESSION['user_id'];
                $type = $_POST['type'] ?? '';
                $time = $_POST['time'] ?? '';
                $days = isset($_POST['days']) ? implode(',', (array)$_POST['days']) : null;
                $message_text = $_POST['message'] ?? '';

                if (!empty($type) && !empty($time)) {
                    if (add_reminder($user_id, $type, $time, $days, $message_text)) {
                        $message = "Reminder added.";
                    } else {
                        $error = "Failed to add reminder.";
                    }
                } else {
                    $error = "Reminder type and time are required.";
                }
            } else {
                $error = "You must be logged in to add reminders.";
            }
            $action = 'reminders';
            break;

        case 'delete_reminder':
            if (is_logged_in()) {
                $reminder_id = $_POST['reminder_id'] ?? 0;
                if ($reminder_id > 0) {
                    if (delete_reminder($reminder_id, $_SESSION['user_id'])) {
                        $message = "Reminder deleted.";
                    } else {
                        $error = "Failed to delete reminder.";
                    }
                } else {
                    $error = "Invalid reminder ID.";
                }
            } else {
                $error = "You must be logged in to delete reminders.";
            }
            $action = 'reminders';
            break;

        case 'add_note':
            if (is_logged_in()) {
                $user_id = $_SESSION['user_id'];
                $quran_id = $_POST['quran_id'] ?? 0;
                $note_text = $_POST['note'] ?? '';
                if ($quran_id > 0 && !empty($note_text)) {
                    if (add_note($user_id, $quran_id, $note_text)) {
                        $message = "Note added.";
                    } else {
                        $error = "Failed to add note.";
                    }
                } else {
                    $error = "Note text is required.";
                }
                 // Redirect back to the ayah page
                $ayah_info = get_ayah_by_id($quran_id);
                if ($ayah_info) {
                     header('Location: ?action=read&surah=' . $ayah_info['surah_number'] . '&ayah=' . $ayah_info['ayah_number']);
                     exit;
                } else {
                     header('Location: ?action=dashboard');
                     exit;
                }
            } else {
                $error = "You must be logged in to add notes.";
                $action = 'login_form';
            }
            break;

        case 'delete_note':
            if (is_logged_in()) {
                $note_id = $_POST['note_id'] ?? 0;
                 $quran_id = $_POST['quran_id'] ?? 0; // Needed for redirect
                if ($note_id > 0) {
                    if (delete_note($note_id, $_SESSION['user_id'])) {
                        $message = "Note deleted.";
                    } else {
                        $error = "Failed to delete note.";
                    }
                } else {
                    $error = "Invalid note ID.";
                }
                 // Redirect back to the ayah page
                $ayah_info = get_ayah_by_id($quran_id);
                if ($ayah_info) {
                     header('Location: ?action=read&surah=' . $ayah_info['surah_number'] . '&ayah=' . $ayah_info['ayah_number']);
                     exit;
                } else {
                     header('Location: ?action=dashboard');
                     exit;
                }
            } else {
                $error = "You must be logged in to delete notes.";
                $action = 'login_form';
            }
            break;

        case 'admin_update_user_role':
            if (is_admin()) {
                $user_id = $_POST['user_id'] ?? 0;
                $role = $_POST['role'] ?? '';
                if ($user_id > 0 && ($role === 'user' || $role === 'admin')) {
                    if (update_user_role($user_id, $role)) {
                        $message = "User role updated.";
                    } else {
                        $error = "Failed to update user role.";
                    }
                } else {
                    $error = "Invalid user ID or role.";
                }
            } else {
                $error = "Unauthorized access.";
            }
            $action = 'admin_panel';
            break;

        case 'admin_delete_user':
            if (is_admin()) {
                $user_id = $_POST['user_id'] ?? 0;
                 // Prevent deleting the current admin user
                if ($user_id > 0 && $user_id != $_SESSION['user_id']) {
                    if (delete_user($user_id)) {
                        $message = "User deleted.";
                    } else {
                        $error = "Failed to delete user.";
                    }
                } else {
                    $error = "Invalid user ID or cannot delete yourself.";
                }
            } else {
                $error = "Unauthorized access.";
            }
            $action = 'admin_panel';
            break;

        case 'admin_backup_db':
            if (is_admin()) {
                $backup_file = backup_database();
                if ($backup_file) {
                    $message = "Database backed up successfully: " . htmlspecialchars($backup_file);
                } else {
                    $error = "Failed to create database backup.";
                }
            } else {
                $error = "Unauthorized access.";
            }
            $action = 'admin_panel';
            break;

        case 'admin_restore_db':
            if (is_admin()) {
                $backup_file = $_POST['backup_file'] ?? '';
                if (!empty($backup_file)) {
                    if (restore_database($backup_file)) {
                        $message = "Database restored successfully from " . htmlspecialchars($backup_file);
                    } else {
                        $error = "Failed to restore database from " . htmlspecialchars($backup_file);
                    }
                } else {
                    $error = "No backup file selected for restore.";
                }
            } else {
                $error = "Unauthorized access.";
            }
            $action = 'admin_panel';
            break;

        case 'admin_delete_backup':
             if (is_admin()) {
                $backup_file = $_POST['backup_file'] ?? '';
                if (!empty($backup_file)) {
                    if (delete_backup($backup_file)) {
                        $message = "Backup file deleted: " . htmlspecialchars($backup_file);
                    } else {
                        $error = "Failed to delete backup file: " . htmlspecialchars($backup_file);
                    }
                } else {
                    $error = "No backup file selected for deletion.";
                }
            } else {
                $error = "Unauthorized access.";
            }
            $action = 'admin_panel';
            break;

        default:
            $error = "Unknown action.";
            $action = 'home';
            break;
    }
}

// Handle GET requests and display pages
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?></title>
    <style>
        body { font-family: sans-serif; line-height: 1.6; margin: 0; padding: 0; background-color: #f4f4f4; color: #333; }
        .container { width: 90%; margin: 20px auto; background: #fff; padding: 20px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        header { background: #0056b3; color: #fff; padding: 10px 0; text-align: center; }
        header h1 { margin: 0; }
        nav { background: #004085; color: #fff; padding: 10px 0; text-align: center; }
        nav a { color: #fff; text-decoration: none; margin: 0 15px; }
        nav a:hover { text-decoration: underline; }
        .message { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 10px; margin-bottom: 15px; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 10px; margin-bottom: 15px; }
        .quran-text { font-size: 1.5em; text-align: right; direction: rtl; margin-bottom: 10px; }
        .translation-text { font-size: 1.1em; text-align: right; direction: rtl; color: #555; margin-bottom: 10px; }
        .ayah-meta { font-size: 0.9em; color: #777; text-align: left; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .tafsir, .word-meaning, .user-note { border: 1px solid #ddd; padding: 10px; margin-bottom: 10px; background: #f9f9f9; }
        .tafsir h4, .word-meaning h4, .user-note h4 { margin-top: 0; }
        form label { display: block; margin-bottom: 5px; font-weight: bold; }
        form input[type="text"], form input[type="email"], form input[type="password"], form textarea, form select { width: 100%; padding: 8px; margin-bottom: 10px; border: 1px solid #ddd; box-sizing: border-box; }
        form button { background: #0056b3; color: #fff; padding: 10px 15px; border: none; cursor: pointer; }
        form button:hover { background: #004085; }
        .admin-section { margin-top: 30px; border-top: 1px solid #eee; padding-top: 20px; }
        .admin-section h3 { margin-top: 0; }
        .admin-table th, .admin-table td { padding: 8px; border: 1px solid #ddd; }
        .admin-table th { background-color: #f2f2f2; }
        .tilawat-mode-controls { margin-bottom: 20px; padding: 10px; background: #eee; }
        .tilawat-mode-controls label { margin-right: 15px; }
        .tilawat-mode-ayah { margin-bottom: 20px; padding: 15px; border: 1px solid #ccc; }
        .tilawat-mode-arabic { font-size: 2em; text-align: center; direction: rtl; margin-bottom: 10px; }
        .tilawat-mode-urdu { font-size: 1.2em; text-align: center; direction: rtl; color: #555; }
        .tilawat-mode-meta { font-size: 0.9em; color: #777; text-align: center; margin-top: 10px; }
        .tilawat-mode-fullscreen { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: #000; color: #fff; overflow-y: auto; z-index: 1000; padding: 20px; box-sizing: border-box; }
        .tilawat-mode-fullscreen .tilawat-mode-ayah { border-color: #555; background: #222; }
        .tilawat-mode-fullscreen .tilawat-mode-urdu { color: #ccc; }
        .tilawat-mode-fullscreen .tilawat-mode-meta { color: #aaa; }
        .tilawat-mode-fullscreen .tilawat-mode-controls { background: #333; color: #fff; }
        .tilawat-mode-fullscreen .tilawat-mode-controls label { color: #fff; }
        .tilawat-mode-fullscreen .tilawat-mode-controls select, .tilawat-mode-fullscreen .tilawat-mode-controls input[type="number"] { background: #555; color: #fff; border-color: #777; }
        .tilawat-mode-fullscreen .tilawat-mode-controls button { background: #007bff; color: #fff; }
        .tilawat-mode-fullscreen .tilawat-mode-controls button:hover { background: #0056b3; }
        .tilawat-mode-fullscreen .tilawat-mode-close { position: absolute; top: 10px; right: 10px; font-size: 2em; color: #fff; cursor: pointer; }
        .tilawat-mode-fullscreen .tilawat-mode-pagination-controls { text-align: center; margin-top: 20px; }
        .tilawat-mode-fullscreen .tilawat-mode-pagination-controls button { margin: 0 10px; }
        .tilawat-mode-fullscreen .tilawat-mode-page-number { color: #fff; margin: 0 10px; }
        .search-results .result-item { border: 1px solid #ddd; padding: 10px; margin-bottom: 10px; }
        .search-results .result-item h4 { margin-top: 0; }
        .search-results .result-item .highlight { background-color: yellow; }
        .report-chart { width: 100%; height: 300px; } /* Placeholder for chart area */
        .reminders-list li { margin-bottom: 10px; padding: 10px; border: 1px solid #eee; background: #f9f9f9; }
        .reminders-list li form { display: inline; margin-left: 10px; }
        .reminders-list li button { background: #dc3545; color: #fff; padding: 5px 10px; border: none; cursor: pointer; font-size: 0.9em; }
        .reminders-list li button:hover { background: #c82333; }
        .backup-list li { margin-bottom: 5px; }
        .backup-list li form { display: inline; margin-left: 10px; }
        .backup-list li button { background: #ffc107; color: #212529; padding: 5px 10px; border: none; cursor: pointer; font-size: 0.9em; }
        .backup-list li button.delete { background: #dc3545; color: #fff; }
        .backup-list li button:hover { opacity: 0.8; }

    </style>
</head>
<body>
    <header>
        <h1><?php echo APP_NAME; ?></h1>
    </header>
    <nav>
        <a href="?action=home">Home</a>
        <a href="?action=read">Read Quran</a>
        <?php if (is_logged_in()): ?>
            <a href="?action=dashboard">Dashboard</a>
            <a href="?action=reports">Reports</a>
            <a href="?action=reminders">Reminders</a>
            <a href="?action=search">Search</a>
            <?php if (is_admin()): ?>
                <a href="?action=admin_panel">Admin Panel</a>
            <?php endif; ?>
            <a href="?action=logout">Logout (<?php echo htmlspecialchars($_SESSION['user_email']); ?>)</a>
        <?php else: ?>
            <a href="?action=login_form">Login</a>
            <a href="?action=register_form">Register</a>
        <?php endif; ?>
    </nav>

    <div class="container">
        <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php
        // Page Content based on action
        switch ($action) {
            case 'home':
                ?>
                <h2>Welcome to Quran Study Hub</h2>
                <p>Your personal platform for studying the Holy Quran.</p>
                <?php if (!is_logged_in()): ?>
                    <p><a href="?action=login_form">Login</a> or <a href="?action=register_form">Register</a> to personalize your experience.</p>
                <?php endif; ?>
                <p>You can start reading the Quran <a href="?action=read">here</a>.</p>
                <?php
                break;

            case 'register_form':
                if (is_logged_in()) {
                    header('Location: ?action=dashboard');
                    exit;
                }
                ?>
                <h2>Register</h2>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="register">
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email" required>

                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>

                    <button type="submit">Register</button>
                </form>
                <p>Already have an account? <a href="?action=login_form">Login here</a>.</p>
                <?php
                break;

            case 'login_form':
                 if (is_logged_in()) {
                    header('Location: ?action=dashboard');
                    exit;
                }
                ?>
                <h2>Login</h2>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="login">
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email" required>

                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>

                    <button type="submit">Login</button>
                </form>
                 <p>Don't have an account? <a href="?action=register_form">Register here</a>.</p>
                <?php
                break;

            case 'dashboard':
                if (!is_logged_in()) {
                    header('Location: ?action=login_form');
                    exit;
                }
                $user_id = $_SESSION['user_id'];
                $last_read_quran_id = get_last_read_quran_id($user_id);
                $last_read_ayah = $last_read_quran_id ? get_ayah_by_id($last_read_quran_id) : null;
                ?>
                <h2>Dashboard</h2>
                <p>Welcome, <?php echo htmlspecialchars($_SESSION['user_email']); ?>!</p>

                <?php if ($last_read_ayah): ?>
                    <p>Your Last Read: Surah <?php echo htmlspecialchars($last_read_ayah['surah_number']); ?>, Ayah <?php echo htmlspecialchars($last_read_ayah['ayah_number']); ?></p>
                    <p><a href="?action=read&surah=<?php echo $last_read_ayah['surah_number']; ?>&ayah=<?php echo $last_read_ayah['ayah_number']; ?>">Continue Reading</a></p>
                <?php else: ?>
                     <p>You haven't started reading yet. <a href="?action=read">Start Reading</a></p>
                <?php endif; ?>

                <h3>Quick Links</h3>
                <ul>
                    <li><a href="?action=read">Read Quran</a></li>
                    <li><a href="?action=reports">View Reading Reports</a></li>
                    <li><a href="?action=reminders">Manage Reminders</a></li>
                    <li><a href="?action=search">Search Quran and Content</a></li>
                </ul>
                <?php
                break;

            case 'read':
                $surah_number = $_GET['surah'] ?? 1;
                $ayah_number = $_GET['ayah'] ?? 1;

                $ayah = get_ayah_by_surah_ayah($surah_number, $ayah_number);

                if (!$ayah) {
                    $error = "Ayah not found.";
                    $surah_number = 1;
                    $ayah_number = 1;
                    $ayah = get_ayah_by_surah_ayah($surah_number, $ayah_number);
                }

                $surahs = get_surahs();
                $ayahs_in_surah = get_ayahs_by_surah($surah_number);
                $total_ayahs_in_surah = count($ayahs_in_surah);

                // Find previous and next ayah
                $prev_ayah = null;
                $next_ayah = null;
                $current_index = -1;
                foreach ($ayahs_in_surah as $index => $a) {
                    if ($a['ayah_number'] == $ayah_number) {
                        $current_index = $index;
                        break;
                    }
                }

                if ($current_index > 0) {
                    $prev_ayah = $ayahs_in_surah[$current_index - 1];
                } else {
                    // Check previous surah
                    $prev_surah_number = $surah_number - 1;
                    if (in_array($prev_surah_number, $surahs)) {
                        $prev_surah_ayahs = get_ayahs_by_surah($prev_surah_number);
                        if (!empty($prev_surah_ayahs)) {
                            $prev_ayah = end($prev_surah_ayahs);
                        }
                    }
                }

                 if ($current_index < $total_ayahs_in_surah - 1) {
                    $next_ayah = $ayahs_in_surah[$current_index + 1];
                } else {
                    // Check next surah
                    $next_surah_number = $surah_number + 1;
                     if (in_array($next_surah_number, $surahs)) {
                        $next_surah_ayahs = get_ayahs_by_surah($next_surah_number);
                        if (!empty($next_surah_ayahs)) {
                            $next_ayah = $next_surah_ayahs[0];
                        }
                    }
                }


                // Track reading if logged in
                if (is_logged_in() && $ayah) {
                    track_reading($ayah['id']);
                    set_last_read_quran_id($_SESSION['user_id'], $ayah['id']);
                }

                $tafsir_entries = $ayah ? get_tafsir_by_quran_id($ayah['id']) : [];
                $word_meanings = $ayah ? get_word_meanings_by_quran_id($ayah['id']) : [];
                $user_notes = (is_logged_in() && $ayah) ? get_notes_by_quran_id($_SESSION['user_id'], $ayah['id']) : [];

                ?>
                <h2>Read Quran</h2>

                <div class="navigation-controls">
                    <form method="GET" action="" style="display: inline;">
                        <input type="hidden" name="action" value="read">
                        <label for="surah_select">Surah:</label>
                        <select id="surah_select" name="surah" onchange="this.form.submit()">
                            <?php foreach ($surahs as $s): ?>
                                <option value="<?php echo $s; ?>" <?php echo $s == $surah_number ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(get_surah_name($s)); ?> (<?php echo $s; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                     <form method="GET" action="" style="display: inline;">
                        <input type="hidden" name="action" value="read">
                        <input type="hidden" name="surah" value="<?php echo htmlspecialchars($surah_number); ?>">
                        <label for="ayah_select">Ayah:</label>
                        <select id="ayah_select" name="ayah" onchange="this.form.submit()">
                            <?php foreach ($ayahs_in_surah as $a): ?>
                                <option value="<?php echo $a['ayah_number']; ?>" <?php echo $a['ayah_number'] == $ayah_number ? 'selected' : ''; ?>>
                                    <?php echo $a['ayah_number']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <?php if ($ayah): ?>
                    <div class="ayah-display">
                        <div class="quran-text"><?php echo htmlspecialchars($ayah['arabic_text']); ?></div>
                        <div class="translation-text">ترجمہ: <?php echo htmlspecialchars($ayah['urdu_translation']); ?></div>
                        <div class="ayah-meta">س <?php echo htmlspecialchars(sprintf('%03d', $ayah['surah_number'])); ?> آ <?php echo htmlspecialchars(sprintf('%03d', $ayah['ayah_number'])); ?></div>

                        <?php if (!empty($word_meanings)): ?>
                            <h3>Word Meanings</h3>
                            <?php foreach ($word_meanings as $wm): ?>
                                <div class="word-meaning">
                                    <h4>Word: <?php echo htmlspecialchars($wm['word']); ?></h4>
                                    <p><?php echo nl2br(htmlspecialchars($wm['meaning'])); ?></p>
                                    <small>Added by: <?php echo htmlspecialchars($wm['created_by_email'] ?? 'Admin'); ?> (v<?php echo $wm['version']; ?>)</small>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($tafsir_entries)): ?>
                            <h3>Tafsir</h3>
                            <?php foreach ($tafsir_entries as $t): ?>
                                <div class="tafsir">
                                    <h4>Source: <?php echo htmlspecialchars($t['source'] ?: 'Unknown'); ?></h4>
                                    <p><?php echo nl2br(htmlspecialchars($t['text'])); ?></p>
                                     <small>Added by: <?php echo htmlspecialchars($t['created_by_email'] ?? 'Admin'); ?> (v<?php echo $t['version']; ?>)</small>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                         <?php if (is_logged_in()): ?>
                            <h3>Your Notes</h3>
                            <?php if (!empty($user_notes)): ?>
                                <?php foreach ($user_notes as $note): ?>
                                    <div class="user-note">
                                        <p><?php echo nl2br(htmlspecialchars($note['note'])); ?></p>
                                        <small>Added on: <?php echo htmlspecialchars($note['created_at']); ?></small>
                                        <form method="POST" action="" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_note">
                                            <input type="hidden" name="note_id" value="<?php echo $note['id']; ?>">
                                            <input type="hidden" name="quran_id" value="<?php echo $ayah['id']; ?>">
                                            <button type="submit" onclick="return confirm('Are you sure you want to delete this note?')">Delete Note</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>No notes for this Ayah yet.</p>
                            <?php endif; ?>

                            <h4>Add a Note</h4>
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="add_note">
                                <input type="hidden" name="quran_id" value="<?php echo $ayah['id']; ?>">
                                <textarea name="note" rows="4" required></textarea>
                                <button type="submit">Add Note</button>
                            </form>

                            <h4>Suggest Tafsir or Word Meaning</h4>
                             <p>Your suggestions will be reviewed by an admin before being published.</p>
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="add_tafsir">
                                <input type="hidden" name="quran_id" value="<?php echo $ayah['id']; ?>">
                                <label for="tafsir_source">Source (Optional):</label>
                                <input type="text" id="tafsir_source" name="source">
                                <label for="tafsir_text">Tafsir Text:</label>
                                <textarea id="tafsir_text" name="text" rows="6" required></textarea>
                                <button type="submit">Suggest Tafsir</button>
                            </form>
                             <form method="POST" action="">
                                <input type="hidden" name="action" value="add_word_meaning">
                                <input type="hidden" name="quran_id" value="<?php echo $ayah['id']; ?>">
                                <label for="word_meaning_word">Word:</label>
                                <input type="text" id="word_meaning_word" name="word" required>
                                <label for="word_meaning_meaning">Meaning:</label>
                                <textarea id="word_meaning_meaning" name="meaning" rows="3" required></textarea>
                                <button type="submit">Suggest Word Meaning</button>
                            </form>

                        <?php endif; ?>

                    </div>

                    <div class="pagination-controls" style="text-align: center; margin-top: 20px;">
                        <?php if ($prev_ayah): ?>
                            <a href="?action=read&surah=<?php echo $prev_ayah['surah_number']; ?>&ayah=<?php echo $prev_ayah['ayah_number']; ?>">« Previous Ayah</a>
                        <?php endif; ?>
                        <?php if ($prev_ayah && $next_ayah): ?> | <?php endif; ?>
                        <?php if ($next_ayah): ?>
                            <a href="?action=read&surah=<?php echo $next_ayah['surah_number']; ?>&ayah=<?php echo $next_ayah['ayah_number']; ?>">Next Ayah »</a>
                        <?php endif; ?>
                    </div>

                    <h3>Tilawat Mode</h3>
                    <p>Enter fullscreen Tilawat mode for focused reading.</p>
                    <button id="enter-tilawat-mode">Enter Tilawat Mode</button>

                <?php endif; // End if $ayah ?>
                <?php
                break;

            case 'reports':
                if (!is_logged_in()) {
                    header('Location: ?action=login_form');
                    exit;
                }
                $user_id = $_SESSION['user_id'];
                $period = $_GET['period'] ?? 'daily';
                $reports = get_reading_report($user_id, $period);
                ?>
                <h2>Reading Reports</h2>
                <p>View your reading activity.</p>

                <form method="GET" action="">
                    <input type="hidden" name="action" value="reports">
                    <label for="report_period">Select Period:</label>
                    <select id="report_period" name="period" onchange="this.form.submit()">
                        <option value="daily" <?php echo $period === 'daily' ? 'selected' : ''; ?>>Daily</option>
                        <option value="monthly" <?php echo $period === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                        <option value="yearly" <?php echo $period === 'yearly' ? 'selected' : ''; ?>>Yearly</option>
                    </select>
                </form>

                <?php if (!empty($reports)): ?>
                    <h3><?php echo ucfirst($period); ?> Reading Activity</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th>Ayahs Read</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reports as $report): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($report['period']); ?></td>
                                    <td><?php echo htmlspecialchars($report['ayahs_read']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <!-- Placeholder for Chart -->
                    <div class="report-chart">
                        <p>Chart visualization coming soon.</p>
                        <!-- You would integrate a charting library here (e.g., Chart.js) -->
                    </div>
                <?php else: ?>
                    <p>No reading activity recorded for this period.</p>
                <?php endif; ?>
                <?php
                break;

            case 'reminders':
                if (!is_logged_in()) {
                    header('Location: ?action=login_form');
                    exit;
                }
                $user_id = $_SESSION['user_id'];
                $reminders = get_reminders($user_id);
                ?>
                <h2>Manage Reminders</h2>
                <p>Set browser notifications to remind you to read or memorize the Quran.</p>

                <h3>Add New Reminder</h3>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_reminder">
                    <label for="reminder_type">Type:</label>
                    <select id="reminder_type" name="type" required>
                        <option value="reading">Reading</option>
                        <option value="hifz">Hifz (Memorization)</option>
                    </select>

                    <label for="reminder_time">Time:</label>
                    <input type="time" id="reminder_time" name="time" required>

                    <label>Days (Select all that apply):</label><br>
                    <input type="checkbox" id="day_mon" name="days[]" value="Mon"> <label for="day_mon" style="display: inline;">Mon</label>
                    <input type="checkbox" id="day_tue" name="days[]" value="Tue"> <label for="day_tue" style="display: inline;">Tue</label>
                    <input type="checkbox" id="day_wed" name="days[]" value="Wed"> <label for="day_wed" style="display: inline;">Wed</label>
                    <input type="checkbox" id="day_thu" name="days[]" value="Thu"> <label for="day_thu" style="display: inline;">Thu</label>
                    <input type="checkbox" id="day_fri" name="days[]" value="Fri"> <label for="day_fri" style="display: inline;">Fri</label>
                    <input type="checkbox" id="day_sat" name="days[]" value="Sat"> <label for="day_sat" style="display: inline;">Sat</label>
                    <input type="checkbox" id="day_sun" name="days[]" value="Sun"> <label for="day_sun" style="display: inline;">Sun</label>
                    <br>

                    <label for="reminder_message">Message (Optional):</label>
                    <input type="text" id="reminder_message" name="message">

                    <button type="submit">Add Reminder</button>
                </form>

                <h3>Your Reminders</h3>
                <?php if (!empty($reminders)): ?>
                    <ul class="reminders-list">
                        <?php foreach ($reminders as $reminder): ?>
                            <li>
                                <strong><?php echo htmlspecialchars(ucfirst($reminder['type'])); ?> Reminder:</strong>
                                at <?php echo htmlspecialchars($reminder['time']); ?>
                                <?php if ($reminder['days']): ?>
                                    on <?php echo htmlspecialchars($reminder['days']); ?>
                                <?php endif; ?>
                                <?php if ($reminder['message']): ?>
                                    - "<?php echo htmlspecialchars($reminder['message']); ?>"
                                <?php endif; ?>
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="delete_reminder">
                                    <input type="hidden" name="reminder_id" value="<?php echo $reminder['id']; ?>">
                                    <button type="submit" onclick="return confirm('Are you sure you want to delete this reminder?')">Delete</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>You have no reminders set yet.</p>
                <?php endif; ?>

                <script>
                    // Basic client-side reminder logic (requires Service Worker for persistent notifications)
                    // This is a placeholder. Full Service Worker implementation is complex for a single file.
                    // You would need a separate service-worker.js file and proper registration.
                    // This script only handles requesting permission.

                    document.addEventListener('DOMContentLoaded', function() {
                        const requestNotificationPermissionButton = document.createElement('button');
                        requestNotificationPermissionButton.textContent = 'Enable Browser Notifications';
                        requestNotificationPermissionButton.onclick = function() {
                            if (!('Notification' in window)) {
                                alert('This browser does not support desktop notification');
                            } else if (Notification.permission === 'granted') {
                                alert('Notification permission already granted.');
                            } else if (Notification.permission !== 'denied') {
                                Notification.requestPermission().then(function (permission) {
                                    if (permission === 'granted') {
                                        alert('Notification permission granted.');
                                        // Here you would typically register a Service Worker
                                        // navigator.serviceWorker.register('service-worker.js');
                                    }
                                });
                            } else {
                                alert('Notification permission denied. Please change browser settings.');
                            }
                        };

                        const reminderSection = document.querySelector('h3:contains("Add New Reminder")').parentElement; // Find the form parent
                         if (reminderSection) {
                             reminderSection.insertBefore(requestNotificationPermissionButton, reminderSection.querySelector('form'));
                         }
                    });
                </script>
                <?php
                break;

            case 'search':
                $query = $_GET['query'] ?? '';
                $search_results_quran = [];
                $search_results_tafsir = [];
                $search_results_notes = [];

                if (!empty($query)) {
                    $search_results_quran = search_quran($query);
                    $search_results_tafsir = search_tafsir($query);
                    if (is_logged_in()) {
                        $search_results_notes = search_notes($_SESSION['user_id'], $query);
                    }
                }
                ?>
                <h2>Search</h2>
                <p>Search the Quran, Tafsir, and your notes.</p>

                <form method="GET" action="">
                    <input type="hidden" name="action" value="search">
                    <label for="search_query">Search Query:</label>
                    <input type="text" id="search_query" name="query" value="<?php echo htmlspecialchars($query); ?>" required>
                    <button type="submit">Search</button>
                </form>

                <?php if (!empty($query)): ?>
                    <h3>Search Results for "<?php echo htmlspecialchars($query); ?>"</h3>

                    <h4>Quran (Arabic & Urdu) (<?php echo count($search_results_quran); ?> results)</h4>
                    <div class="search-results">
                        <?php if (!empty($search_results_quran)): ?>
                            <?php foreach ($search_results_quran as $ayah): ?>
                                <div class="result-item">
                                    <h4>Surah <?php echo htmlspecialchars($ayah['surah_number']); ?>, Ayah <?php echo htmlspecialchars($ayah['ayah_number']); ?></h4>
                                    <p class="quran-text"><?php echo highlight_search_term(htmlspecialchars($ayah['arabic_text']), htmlspecialchars($query)); ?></p>
                                    <p class="translation-text"><?php echo highlight_search_term(htmlspecialchars($ayah['urdu_translation']), htmlspecialchars($query)); ?></p>
                                    <p><a href="?action=read&surah=<?php echo $ayah['surah_number']; ?>&ayah=<?php echo $ayah['ayah_number']; ?>">Read this Ayah</a></p>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>No results found in Quran text.</p>
                        <?php endif; ?>
                    </div>

                    <h4>Tafsir (<?php echo count($search_results_tafsir); ?> results)</h4>
                     <div class="search-results">
                        <?php if (!empty($search_results_tafsir)): ?>
                            <?php foreach ($search_results_tafsir as $tafsir): ?>
                                <div class="result-item">
                                    <h4>Tafsir for Surah <?php echo htmlspecialchars($tafsir['surah_number']); ?>, Ayah <?php echo htmlspecialchars($tafsir['ayah_number']); ?> (Source: <?php echo htmlspecialchars($tafsir['source'] ?: 'Unknown'); ?>)</h4>
                                    <p><?php echo nl2br(highlight_search_term(htmlspecialchars($tafsir['text']), htmlspecialchars($query))); ?></p>
                                     <p><a href="?action=read&surah=<?php echo $tafsir['surah_number']; ?>&ayah=<?php echo $tafsir['ayah_number']; ?>">Read this Ayah</a></p>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>No results found in Tafsir.</p>
                        <?php endif; ?>
                    </div>

                    <?php if (is_logged_in()): ?>
                        <h4>Your Notes (<?php echo count($search_results_notes); ?> results)</h4>
                         <div class="search-results">
                            <?php if (!empty($search_results_notes)): ?>
                                <?php foreach ($search_results_notes as $note): ?>
                                    <div class="result-item">
                                        <h4>Note for Surah <?php echo htmlspecialchars($note['surah_number']); ?>, Ayah <?php echo htmlspecialchars($note['ayah_number']); ?></h4>
                                        <p><?php echo nl2br(highlight_search_term(htmlspecialchars($note['note']), htmlspecialchars($query))); ?></p>
                                         <p><a href="?action=read&surah=<?php echo $note['surah_number']; ?>&ayah=<?php echo $note['ayah_number']; ?>">Read this Ayah</a></p>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>No results found in your notes.</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php endif; // End if !empty($query) ?>
                <?php
                break;

            case 'admin_panel':
                if (!is_admin()) {
                    $error = "Unauthorized access.";
                    header('Location: ?action=home');
                    exit;
                }
                $users = get_all_users();
                $pending_contributions = get_pending_contributions();
                $backups = get_backups();
                ?>
                <h2>Admin Panel</h2>
                <p>Manage users, content, and backups.</p>

                <div class="admin-section">
                    <h3>User Management</h3>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Created At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['id']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <form method="POST" action="" style="display: inline;">
                                            <input type="hidden" name="action" value="admin_update_user_role">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <select name="role" onchange="this.form.submit()" <?php echo $user['id'] == $_SESSION['user_id'] ? 'disabled' : ''; ?>>
                                                <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>User</option>
                                                <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                            </select>
                                        </form>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['created_at']); ?></td>
                                    <td>
                                        <?php if ($user['id'] != $_SESSION['user_id']): // Prevent deleting the current admin ?>
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="action" value="admin_delete_user">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" onclick="return confirm('Are you sure you want to delete user <?php echo htmlspecialchars($user['email']); ?>? This will delete all their data.')">Delete</button>
                                            </form>
                                        <?php else: ?>
                                            (Current User)
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="admin-section">
                    <h3>Pending Contributions (Suggestions)</h3>
                    <h4>Tafsir (<?php echo count($pending_contributions['tafsir']); ?>)</h4>
                    <?php if (!empty($pending_contributions['tafsir'])): ?>
                        <?php foreach ($pending_contributions['tafsir'] as $tafsir): ?>
                            <div class="tafsir">
                                <h4>Suggestion for Surah <?php echo htmlspecialchars($tafsir['surah_number']); ?>, Ayah <?php echo htmlspecialchars($tafsir['ayah_number']); ?></h4>
                                <p><strong>Source:</strong> <?php echo htmlspecialchars($tafsir['source'] ?: 'Unknown'); ?></p>
                                <p><strong>Text:</strong> <?php echo nl2br(htmlspecialchars($tafsir['text'])); ?></p>
                                <p><strong>Suggested by:</strong> <?php echo htmlspecialchars($tafsir['created_by_email'] ?? 'Unknown'); ?> on <?php echo htmlspecialchars($tafsir['created_at']); ?></p>
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="action" value="approve_contribution">
                                    <input type="hidden" name="type" value="tafsir">
                                    <input type="hidden" name="id" value="<?php echo $tafsir['id']; ?>">
                                    <button type="submit">Approve</button>
                                </form>
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="action" value="reject_contribution">
                                    <input type="hidden" name="type" value="tafsir">
                                    <input type="hidden" name="id" value="<?php echo $tafsir['id']; ?>">
                                    <button type="submit">Reject</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>No pending Tafsir suggestions.</p>
                    <?php endif; ?>

                    <h4>Word Meanings (<?php echo count($pending_contributions['word_meanings']); ?>)</h4>
                     <?php if (!empty($pending_contributions['word_meanings'])): ?>
                        <?php foreach ($pending_contributions['word_meanings'] as $wm): ?>
                            <div class="word-meaning">
                                <h4>Suggestion for Surah <?php echo htmlspecialchars($wm['surah_number']); ?>, Ayah <?php echo htmlspecialchars($wm['ayah_number']); ?></h4>
                                <p><strong>Word:</strong> <?php echo htmlspecialchars($wm['word']); ?></p>
                                <p><strong>Meaning:</strong> <?php echo nl2br(htmlspecialchars($wm['meaning'])); ?></p>
                                <p><strong>Suggested by:</strong> <?php echo htmlspecialchars($wm['created_by_email'] ?? 'Unknown'); ?> on <?php echo htmlspecialchars($wm['created_at']); ?></p>
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="action" value="approve_contribution">
                                    <input type="hidden" name="type" value="word_meaning">
                                    <input type="hidden" name="id" value="<?php echo $wm['id']; ?>">
                                    <button type="submit">Approve</button>
                                </form>
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="action" value="reject_contribution">
                                    <input type="hidden" name="type" value="word_meaning">
                                    <input type="hidden" name="id" value="<?php echo $wm['id']; ?>">
                                    <button type="submit">Reject</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>No pending Word Meaning suggestions.</p>
                    <?php endif; ?>
                </div>

                <div class="admin-section">
                    <h3>Database Backup and Restore</h3>
                    <p>Create and manage database backups.</p>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="admin_backup_db">
                        <button type="submit">Create Backup</button>
                    </form>

                    <h4>Existing Backups</h4>
                    <?php if (!empty($backups)): ?>
                        <ul class="backup-list">
                            <?php foreach ($backups as $backup): ?>
                                <li>
                                    <?php echo htmlspecialchars($backup); ?> (<?php echo round(filesize(__DIR__ . '/' . $backup) / 1024, 2); ?> KB)
                                    <form method="POST" action="" style="display: inline;">
                                        <input type="hidden" name="action" value="admin_restore_db">
                                        <input type="hidden" name="backup_file" value="<?php echo htmlspecialchars($backup); ?>">
                                        <button type="submit" onclick="return confirm('WARNING: Restoring will overwrite the current database. Are you sure?')">Restore</button>
                                    </form>
                                     <form method="POST" action="" style="display: inline;">
                                        <input type="hidden" name="action" value="admin_delete_backup">
                                        <input type="hidden" name="backup_file" value="<?php echo htmlspecialchars($backup); ?>">
                                        <button type="submit" class="delete" onclick="return confirm('Are you sure you want to delete this backup file?')">Delete</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p>No backups found.</p>
                    <?php endif; ?>
                </div>

                <?php
                break;

            default:
                // Fallback to home if action is unknown or not handled
                header('Location: ?action=home');
                exit;
        }
        ?>
    </div>

    <footer>
        <p style="text-align: center; margin-top: 20px; color: #777;">© <?php echo date('Y'); ?> <?php echo APP_NAME; ?> by Yasin Ullah</p>
    </footer>

    <script>
        // Basic JS for Tilawat Mode UI (prefixed with qsh_tm_)
        const qsh_tm_enterButton = document.getElementById('enter-tilawat-mode');
        let qsh_tm_fullscreenDiv = null;
        let qsh_tm_currentAyahIndex = 0;
        let qsh_tm_ayahsData = []; // Array to hold ayahs for the current surah
        let qsh_tm_settings = {
            fontSize: 2, // em
            linesPerPage: 10,
            viewMode: 'paginated', // 'paginated' or 'continuous'
            theme: 'black', // 'black' or 'white'
            showTranslation: true,
            showMeta: true
        };

        // Load settings from PHP (if logged in)
        <?php if (is_logged_in()): ?>
            const qsh_tm_phpSettings = <?php echo json_encode(get_user_settings($_SESSION['user_id'])['tilawat_mode_settings'] ?? []); ?>;
            qsh_tm_settings = { ...qsh_tm_settings, ...qsh_tm_phpSettings };
        <?php endif; ?>


        if (qsh_tm_enterButton) {
            qsh_tm_enterButton.addEventListener('click', qsh_tm_enterTilawatMode);
        }

        function qsh_tm_enterTilawatMode() {
            // Fetch all ayahs for the current surah
            const currentSurah = <?php echo $surah_number; ?>;
            const currentAyah = <?php echo $ayah_number; ?>;

            // This is a simplified approach. In a real app, you might fetch this via an API endpoint
            // or pre-load it more efficiently. For a single file, we'll embed the data.
            qsh_tm_ayahsData = <?php echo json_encode($ayahs_in_surah); ?>;

            // Find the index of the current ayah
            qsh_tm_currentAyahIndex = qsh_tm_ayahsData.findIndex(ayah => ayah.ayah_number == currentAyah);
            if (qsh_tm_currentAyahIndex === -1) {
                 qsh_tm_currentAyahIndex = 0; // Default to first ayah if not found
            }


            qsh_tm_fullscreenDiv = document.createElement('div');
            qsh_tm_fullscreenDiv.classList.add('tilawat-mode-fullscreen');
            qsh_tm_fullscreenDiv.classList.add('qsh-tm-' + qsh_tm_settings.theme); // Apply theme class

            qsh_tm_fullscreenDiv.innerHTML = `
                <div class="tilawat-mode-close">×</div>
                <div class="tilawat-mode-controls">
                    <label>Font Size: <input type="number" id="qsh-tm-font-size" value="${qsh_tm_settings.fontSize}" step="0.1" min="0.5"></label>
                    <label>Lines/Page: <input type="number" id="qsh-tm-lines-per-page" value="${qsh_tm_settings.linesPerPage}" step="1" min="1"></label>
                    <label>View Mode:
                        <select id="qsh-tm-view-mode">
                            <option value="paginated" ${qsh_tm_settings.viewMode === 'paginated' ? 'selected' : ''}>Paginated</option>
                            <option value="continuous" ${qsh_tm_settings.viewMode === 'continuous' ? 'selected' : ''}>Continuous Scroll</option>
                        </select>
                    </label>
                     <label>Theme:
                        <select id="qsh-tm-theme">
                            <option value="black" ${qsh_tm_settings.theme === 'black' ? 'selected' : ''}>Black</option>
                            <option value="white" ${qsh_tm_settings.theme === 'white' ? 'selected' : ''}>White</option>
                        </select>
                    </label>
                     <label>Show Translation: <input type="checkbox" id="qsh-tm-show-translation" ${qsh_tm_settings.showTranslation ? 'checked' : ''}></label>
                     <label>Show Meta: <input type="checkbox" id="qsh-tm-show-meta" ${qsh_tm_settings.showMeta ? 'checked' : ''}></label>
                    <button id="qsh-tm-save-settings">Save Settings</button>
                </div>
                <div id="qsh-tm-content"></div>
                 <div class="tilawat-mode-pagination-controls" id="qsh-tm-pagination-controls" style="display: ${qsh_tm_settings.viewMode === 'paginated' ? 'block' : 'none'};">
                    <button id="qsh-tm-prev-page">« Previous Page</button>
                    <span id="qsh-tm-page-number"></span>
                    <button id="qsh-tm-next-page">Next Page »</button>
                </div>
            `;

            document.body.appendChild(qsh_tm_fullscreenDiv);
            document.documentElement.requestFullscreen();

            qsh_tm_renderContent();
            qsh_tm_addTilawatModeListeners();
        }

        function qsh_tm_renderContent() {
            const contentDiv = document.getElementById('qsh-tm-content');
            if (!contentDiv) return;

            contentDiv.innerHTML = ''; // Clear previous content

            const startAyahIndex = qsh_tm_settings.viewMode === 'paginated' ? qsh_tm_currentAyahIndex : 0;
            const endAyahIndex = qsh_tm_settings.viewMode === 'paginated' ? Math.min(startAyahIndex + qsh_tm_settings.linesPerPage, qsh_tm_ayahsData.length) : qsh_tm_ayahsData.length;

            for (let i = startAyahIndex; i < endAyahIndex; i++) {
                const ayah = qsh_tm_ayahsData[i];
                const ayahDiv = document.createElement('div');
                ayahDiv.classList.add('tilawat-mode-ayah');
                ayahDiv.setAttribute('data-quran-id', ayah.id); // Add data attribute for tracking

                ayahDiv.innerHTML = `
                    <div class="tilawat-mode-arabic" style="font-size: ${qsh_tm_settings.fontSize}em;">${ayah.arabic_text}</div>
                    ${qsh_tm_settings.showTranslation ? `<div class="tilawat-mode-urdu" style="font-size: ${qsh_tm_settings.fontSize * 0.7}em;">ترجمہ: ${ayah.urdu_translation}</div>` : ''}
                    ${qsh_tm_settings.showMeta ? `<div class="tilawat-mode-meta">س ${String(ayah.surah_number).padStart(3, '0')} آ ${String(ayah.ayah_number).padStart(3, '0')}</div>` : ''}
                `;
                contentDiv.appendChild(ayahDiv);

                 // Track reading for each ayah displayed (basic tracking)
                 // A more robust tracking would involve scrolling/visibility detection
                 qsh_tm_trackReading(ayah.id);
            }

            // Update pagination controls
            const paginationControls = document.getElementById('qsh-tm-pagination-controls');
            const prevButton = document.getElementById('qsh-tm-prev-page');
            const nextButton = document.getElementById('qsh-tm-next-page');
            const pageNumberSpan = document.getElementById('qsh-tm-page-number');

            if (qsh_tm_settings.viewMode === 'paginated') {
                paginationControls.style.display = 'block';
                prevButton.disabled = qsh_tm_currentAyahIndex === 0;
                nextButton.disabled = endAyahIndex >= qsh_tm_ayahsData.length;
                pageNumberSpan.textContent = `Ayah ${qsh_tm_currentAyahIndex + 1} - ${endAyahIndex} of ${qsh_tm_ayahsData.length}`;
            } else {
                paginationControls.style.display = 'none';
            }

             // Scroll to the first ayah on the page in paginated mode
             if (qsh_tm_settings.viewMode === 'paginated' && contentDiv.firstElementChild) {
                 contentDiv.firstElementChild.scrollIntoView();
             }
        }

        function qsh_tm_addTilawatModeListeners() {
            const closeButton = qsh_tm_fullscreenDiv.querySelector('.tilawat-mode-close');
            closeButton.addEventListener('click', qsh_tm_exitTilawatMode);

            const fontSizeInput = qsh_tm_fullscreenDiv.querySelector('#qsh-tm-font-size');
            fontSizeInput.addEventListener('input', (e) => {
                qsh_tm_settings.fontSize = parseFloat(e.target.value);
                qsh_tm_renderContent();
            });

            const linesPerPageInput = qsh_tm_fullscreenDiv.querySelector('#qsh-tm-lines-per-page');
            linesPerPageInput.addEventListener('input', (e) => {
                qsh_tm_settings.linesPerPage = parseInt(e.target.value, 10);
                qsh_tm_renderContent();
            });

            const viewModeSelect = qsh_tm_fullscreenDiv.querySelector('#qsh-tm-view-mode');
            viewModeSelect.addEventListener('change', (e) => {
                qsh_tm_settings.viewMode = e.target.value;
                qsh_tm_currentAyahIndex = 0; // Reset to start of surah/page on mode change
                qsh_tm_renderContent();
            });

             const themeSelect = qsh_tm_fullscreenDiv.querySelector('#qsh-tm-theme');
            themeSelect.addEventListener('change', (e) => {
                qsh_tm_fullscreenDiv.classList.remove('qsh-tm-' + qsh_tm_settings.theme);
                qsh_tm_settings.theme = e.target.value;
                qsh_tm_fullscreenDiv.classList.add('qsh-tm-' + qsh_tm_settings.theme);
            });

             const showTranslationCheckbox = qsh_tm_fullscreenDiv.querySelector('#qsh-tm-show-translation');
            showTranslationCheckbox.addEventListener('change', (e) => {
                qsh_tm_settings.showTranslation = e.target.checked;
                qsh_tm_renderContent();
            });

             const showMetaCheckbox = qsh_tm_fullscreenDiv.querySelector('#qsh-tm-show-meta');
            showMetaCheckbox.addEventListener('change', (e) => {
                qsh_tm_settings.showMeta = e.target.checked;
                qsh_tm_renderContent();
            });


            const prevButton = qsh_tm_fullscreenDiv.querySelector('#qsh-tm-prev-page');
            if (prevButton) {
                 prevButton.addEventListener('click', qsh_tm_prevPage);
            }

            const nextButton = qsh_tm_fullscreenDiv.querySelector('#qsh-tm-next-page');
             if (nextButton) {
                nextButton.addEventListener('click', qsh_tm_nextPage);
             }

             const saveSettingsButton = qsh_tm_fullscreenDiv.querySelector('#qsh-tm-save-settings');
             if (saveSettingsButton) {
                 saveSettingsButton.addEventListener('click', qsh_tm_saveSettings);
             }


            // Keyboard navigation
            document.addEventListener('keydown', qsh_tm_handleKeyDown);

            // Swipe navigation (basic)
            let startX = 0;
            qsh_tm_fullscreenDiv.addEventListener('touchstart', (e) => {
                startX = e.touches[0].clientX;
            });
            qsh_tm_fullscreenDiv.addEventListener('touchend', (e) => {
                const endX = e.changedTouches[0].clientX;
                const diff = endX - startX;
                if (Math.abs(diff) > 50) { // Threshold for swipe
                    if (diff > 0) { // Swiped right
                        qsh_tm_prevPage();
                    } else { // Swiped left
                        qsh_tm_nextPage();
                    }
                }
            });
        }

        function qsh_tm_exitTilawatMode() {
            if (document.fullscreenElement) {
                document.exitFullscreen();
            }
            if (qsh_tm_fullscreenDiv) {
                qsh_tm_fullscreenDiv.remove();
                qsh_tm_fullscreenDiv = null;
            }
             document.removeEventListener('keydown', qsh_tm_handleKeyDown);
        }

        function qsh_tm_handleKeyDown(e) {
            if (qsh_tm_fullscreenDiv) { // Only handle keys when in tilawat mode
                if (e.key === 'Escape') {
                    qsh_tm_exitTilawatMode();
                } else if (e.key === 'ArrowRight') {
                    qsh_tm_nextPage();
                } else if (e.key === 'ArrowLeft') {
                    qsh_tm_prevPage();
                }
            }
        }

        function qsh_tm_nextPage() {
            if (qsh_tm_settings.viewMode === 'paginated') {
                const nextIndex = qsh_tm_currentAyahIndex + qsh_tm_settings.linesPerPage;
                if (nextIndex < qsh_tm_ayahsData.length) {
                    qsh_tm_currentAyahIndex = nextIndex;
                    qsh_tm_renderContent();
                }
            }
        }

        function qsh_tm_prevPage() {
             if (qsh_tm_settings.viewMode === 'paginated') {
                const prevIndex = qsh_tm_currentAyahIndex - qsh_tm_settings.linesPerPage;
                if (prevIndex >= 0) {
                    qsh_tm_currentAyahIndex = prevIndex;
                    qsh_tm_renderContent();
                } else if (qsh_tm_currentAyahIndex > 0) {
                    qsh_tm_currentAyahIndex = 0; // Go to the very beginning of the surah
                    qsh_tm_renderContent();
                }
            }
        }

        function qsh_tm_saveSettings() {
             <?php if (is_logged_in()): ?>
                const settingsData = {
                    tilawat_mode_settings: JSON.stringify(qsh_tm_settings),
                    // last_read_quran_id is updated automatically by trackReading
                };

                const formData = new FormData();
                formData.append('action', 'save_settings');
                formData.append('tilawat_mode_settings', settingsData.tilawat_mode_settings);
                 // No need to append last_read_quran_id here, trackReading handles it

                // Use fetch API to send data (no AJAX library)
                fetch('', { // Send to the same PHP file
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text()) // Or response.json() if PHP returns JSON
                .then(data => {
                    console.log('Settings saved:', data);
                    // Optionally display a confirmation message in the UI
                })
                .catch(error => {
                    console.error('Error saving settings:', error);
                    // Optionally display an error message
                });
             <?php else: ?>
                console.log('User not logged in. Settings not saved.');
             <?php endif; ?>
        }

        function qsh_tm_trackReading(quranId) {
             <?php if (is_logged_in()): ?>
                const formData = new FormData();
                formData.append('action', 'track_reading');
                formData.append('quran_id', quranId);

                // Use fetch API to send data (no AJAX library)
                fetch('', { // Send to the same PHP file
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text()) // Or response.json()
                .then(data => {
                    // console.log('Reading tracked:', data); // Optional logging
                })
                .catch(error => {
                    console.error('Error tracking reading:', error);
                });
             <?php endif; ?>
        }


        // Helper function to highlight search terms (basic)
        function highlight_search_term(text, query) {
            if (!query) return text;
            const regex = new RegExp('(' + query.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&') + ')', 'gi');
            return text.replace(regex, '<span class="highlight">$1</span>');
        }

        // Service Worker Registration (Placeholder)
        // This requires a separate service-worker.js file.
        // For a single-file app, this is complex and often not feasible without
        // restructuring. This is left as a comment to indicate where it would go.
        /*
        if ('serviceWorker' in navigator) {
          window.addEventListener('load', function() {
            navigator.serviceWorker.register('/service-worker.js').then(function(registration) {
              // Registration was successful
              console.log('ServiceWorker registration successful with scope: ', registration.scope);
            }, function(err) {
              // registration failed :(
              console.log('ServiceWorker registration failed: ', err);
            });
          });
        }
        */

    </script>
</body>
</html>
<?php
// ============================================================
// api/config.php — النسخة المُصلحة
// ============================================================
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

// ─── Headers ───────────────────────────────────────────────
// Same-origin: لا نحتاج CORS للطلبات من نفس الدومين
// نسمح بـ * لتجنب أي مشاكل في الاستضافة
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");
header("X-Content-Type-Options: nosniff");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}

// ─── قاعدة البيانات ─────────────────────────────────────────
// الأولوية: ملف خارجي → ثم credentials مباشرة هنا كـ fallback
$configPath = dirname(__DIR__, 2) . '/private/db.config.php';

if (file_exists($configPath)) {
    require_once $configPath;
} else {
    // ─── ضع بيانات قاعدة البيانات هنا مباشرة لو Private folder مش موجود ───
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'u629093312_saed_sys');
    define('DB_USER', 'u629093312_saed');
    define('DB_PASS', '6*wUR7tv');
}

// ─── اتصال PDO ──────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "فشل الاتصال بقاعدة البيانات: " . $e->getMessage()]);
    exit;
}

// ─── دوال مساعدة ────────────────────────────────────────────
function cleanInput($d) {
    return htmlspecialchars(strip_tags(trim($d)), ENT_QUOTES, 'UTF-8');
}

function sendJson($data, $code = 200) {
    http_response_code($code);
    ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Session ────────────────────────────────────────────────
// إعدادات Session آمنة
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
// cookie_lifetime = 0 → يُمسح عند إغلاق المتصفح (لا persistent cookie)
ini_set('session.cookie_lifetime', 0);
// gc_maxlifetime = 7200 → ينتهي الـ session بعد ساعتين من عدم النشاط
ini_set('session.gc_maxlifetime', 7200);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ─── صلاحيات ────────────────────────────────────────────────
function requireSuperAdmin() {
    if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'super_admin') {
        sendJson(["success" => false, "message" => "غير مصرح — يجب أن تكون Super Admin"], 403);
    }
}

function requireAdmin() {
    if (empty($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['super_admin', 'admin', 'supervisor'])) {
        sendJson(["success" => false, "message" => "غير مصرح — يجب تسجيل الدخول كمدير أو مشرف"], 403);
    }
}

function requireAuth() {
    if (empty($_SESSION['user_id'])) {
        sendJson(["success" => false, "message" => "يجب تسجيل الدخول"], 401);
    }
}

function isSuperAdmin() {
    return !empty($_SESSION['user_role']) && $_SESSION['user_role'] === 'super_admin';
}

function isAdmin() {
    return !empty($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['super_admin', 'admin']);
}

function isSupervisor() {
    return !empty($_SESSION['user_role']) && $_SESSION['user_role'] === 'supervisor';
}
?>

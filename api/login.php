<?php
// ============================================================
// api/login.php — تسجيل الدخول الآمن
// ============================================================
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(["success" => false, "message" => "Method Not Allowed"], 405);
}

$data     = json_decode(file_get_contents("php://input"), true) ?? [];
$email    = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
$password = $data['password'] ?? '';

if (!$email || !$password) {
    sendJson(["success" => false, "message" => "بيانات ناقصة"]);
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password'])) {
    // تجديد session ID لمنع session fixation
    session_regenerate_id(true);

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['name'];

    sendJson([
        "success" => true,
        "user"    => [
            "id"   => $user['id'],
            "name" => $user['name'],
            "role" => $user['role'],
        ]
    ]);
} else {
    // رسالة عامة لمنع كشف وجود الإيميل
    sendJson(["success" => false, "message" => "بيانات الدخول غير صحيحة"], 401);
}
?>

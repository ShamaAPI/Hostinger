<?php
// ============================================================
// api/track.php — بحث برقم الشكوى/الطلب فقط (للعملاء)
// ============================================================
require 'config.php';

$query = trim($_GET['query'] ?? '');
if (!$query) {
    sendJson(["success" => false, "message" => "أدخل رقم الشكوى أو الطلب"]);
}

// العميل يبحث برقم الكود فقط (مثل C-ELC-123456 أو R-HLT-456789)
// لا يُسمح بالبحث برقم الهاتف من الخارج
try {
    $stmt = $pdo->prepare("
        SELECT id, complaint_code, submission_type, title, description,
               category, name, is_anonymous, address, district,
               status, created_at, updated_at
        FROM complaints
        WHERE complaint_code = ?
        LIMIT 1
    ");
    $stmt->execute([$query]);
    $complaint = $stmt->fetch();

    if (!$complaint) {
        sendJson(["success" => false, "message" => "لم يتم العثور على نتائج لهذا الرقم"]);
    }

    $cid = $complaint['id'];

    $histStmt = $pdo->prepare("SELECT status, timestamp FROM status_history WHERE complaint_id = ? ORDER BY timestamp ASC");
    $histStmt->execute([$cid]);
    $history = $histStmt->fetchAll();

    // التعليقات: نخفي الملاحظات الداخلية (is_internal=1) عن العميل
    $comStmt = $pdo->prepare("
        SELECT user_type, user_name, message, file_path, created_at
        FROM comments
        WHERE complaint_id = ? AND is_internal = 0
        ORDER BY created_at ASC
    ");
    $comStmt->execute([$cid]);
    $comments = $comStmt->fetchAll();

    sendJson([
        "success"   => true,
        "complaint" => $complaint,
        "history"   => $history,
        "comments"  => $comments,
    ]);

} catch (PDOException $e) {
    sendJson(["success" => false, "message" => "خطأ في الخادم"], 500);
}
?>

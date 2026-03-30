<?php
// ============================================================
// api/submit.php — v10
// فئات جديدة + نوعين (شكوى/طلب) + رقم بطاقة + مناطق + بدون أولوية
// ============================================================
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(["success" => false, "message" => "Method Not Allowed"], 405);
}

$data = json_decode(file_get_contents("php://input"), true) ?? [];

// --- نوع التقديم ---
$submissionType = in_array($data['submission_type'] ?? '', ['شكوى','طلب']) ? $data['submission_type'] : 'شكوى';

// --- الحقول المطلوبة دائماً ---
foreach (['category','title','description','name','phone'] as $field) {
    if (empty(trim($data[$field] ?? ''))) {
        sendJson(["success" => false, "message" => "الحقل '$field' مطلوب"], 400);
    }
}

// --- اسم + هاتف إجباريان ---
if (mb_strlen(trim($data['name'])) < 2) sendJson(["success"=>false,"message"=>"أدخل اسمك كاملاً"], 400);

$phone = preg_replace('/\s+/', '', $data['phone']);
if (!preg_match('/^01[0125][0-9]{8}$/', $phone)) {
    sendJson(["success" => false, "message" => "رقم الهاتف غير صحيح. مثال: 01012345678"], 400);
}

// --- رقم البطاقة إجباري في الطلبات ---
$nationalId = preg_replace('/\s+/', '', $data['national_id'] ?? '');
if ($submissionType === 'طلب') {
    if (!preg_match('/^\d{14}$/', $nationalId)) {
        sendJson(["success" => false, "message" => "رقم البطاقة الوطنية مطلوب ويجب أن يكون 14 رقماً في الطلبات"], 400);
    }
}

// --- المنطقة إجبارية ---
$allowedDistricts = ['ابوحشيش','دير الملاك','مكاوى','المليحة','منشية الصدر','مصر والسودان','سكة الوايلى','الشيخ غراب','كوبرى القبة','الوايلى'];
$district = trim($data['district'] ?? '');
if (!in_array($district, $allowedDistricts)) {
    sendJson(["success" => false, "message" => "يجب اختيار المنطقة من القائمة"], 400);
}

// --- الفئات ---
$allowedCats = ['نظافة','كهرباء','مياه','طرق','فساد','صحة','تعليم','معاشات','تموين','أخرى'];
if (!in_array($data['category'], $allowedCats)) {
    sendJson(["success" => false, "message" => "فئة غير صحيحة"], 400);
}

// --- التحقق من العنوان والوصف ---
if (mb_strlen(trim($data['title'])) < 5) sendJson(["success"=>false,"message"=>"العنوان قصير جداً (5 أحرف)"], 400);
if (mb_strlen(trim($data['description'])) < 20) sendJson(["success"=>false,"message"=>"الوصف قصير جداً (20 حرف)"], 400);

$email = null;
if (!empty($data['email'])) {
    $email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);
    if (!$email) sendJson(["success" => false, "message" => "البريد الإلكتروني غير صحيح"], 400);
}

// --- بادئات الفئات ---
$prefixMap = [
    'نظافة'   => 'CLN', 'كهرباء'  => 'ELC', 'مياه'    => 'WTR',
    'طرق'     => 'RDW', 'فساد'    => 'CRP', 'صحة'     => 'HLT',
    'تعليم'   => 'EDU', 'معاشات'  => 'PNS', 'تموين'   => 'SUP',
    'أخرى'    => 'GEN',
];
// نوع التقديم يؤثر على البادئة (R = Request, C = Complaint)
$typePrefix = $submissionType === 'طلب' ? 'R' : 'C';
$catPrefix  = $prefixMap[$data['category']] ?? 'GEN';
$prefix     = $typePrefix . '-' . $catPrefix;

// رقم عشوائي غير متسلسل (6 أرقام)، يتحقق من عدم التكرار
do {
    $rand          = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $complaintCode = $prefix . '-' . $rand;
    $chk = $pdo->prepare("SELECT id FROM complaints WHERE complaint_code = ?");
    $chk->execute([$complaintCode]);
} while ($chk->fetch());

try {
    $stmt = $pdo->prepare("
        INSERT INTO complaints
          (complaint_code, submission_type, title, description, category,
           name, phone, national_id, email, is_anonymous,
           address, district, latitude, longitude, status, created_at)
        VALUES (?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,'تم الاستلام',NOW())
    ");
    $stmt->execute([
        $complaintCode,
        $submissionType,
        cleanInput($data['title']),
        cleanInput($data['description']),
        cleanInput($data['category']),
        cleanInput($data['name']),
        $phone,
        $nationalId ?: null,
        $email,
        !empty($data['isAnonymous']) ? 1 : 0,
        cleanInput($data['address'] ?? ''),
        $district,
        is_numeric($data['latitude']  ?? null) ? $data['latitude']  : null,
        is_numeric($data['longitude'] ?? null) ? $data['longitude'] : null,
    ]);

    $dbId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO status_history (complaint_id,status,timestamp) VALUES(?,'تم الاستلام',NOW())")
        ->execute([$dbId]);

    sendJson([
        "success"         => true,
        "message"         => "تم تقديم " . $submissionType . " بنجاح",
        "complaint_id"    => $dbId,
        "complaint_code"  => $complaintCode,
        "submission_type" => $submissionType,
    ]);

} catch (PDOException $e) {
    sendJson(["success" => false, "message" => "حدث خطأ أثناء الحفظ: " . $e->getMessage()], 500);
}
?>

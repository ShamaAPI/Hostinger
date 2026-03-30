<?php
// ============================================================
// api/admin.php — v10
// ============================================================
require 'config.php';

function getJsonInput() {
    return json_decode(file_get_contents("php://input"), true) ?? [];
}

define('ALLOWED_MIME', ['image/jpeg','image/png','image/gif','image/webp','application/pdf']);
define('MAX_FILE_SIZE', 5 * 1024 * 1024);

function handleFileUpload($fileKey = 'file') {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) return null;
    $file = $_FILES[$fileKey];
    if ($file['size'] > MAX_FILE_SIZE) sendJson(["success"=>false,"message"=>"حجم الملف يتجاوز 5MB"], 400);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ALLOWED_MIME)) sendJson(["success"=>false,"message"=>"نوع الملف غير مسموح"], 400);
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safeName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $uploadDir = '../uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    if (move_uploaded_file($file['tmp_name'], $uploadDir . $safeName)) return 'uploads/' . $safeName;
    return null;
}

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // whoami لا يحتاج requireAdmin
    if ($action === 'whoami') { whoami(); exit; }
    
    // الموظفين يقدروا يشوفوا تفاصيل الشكاوى وقائمة الشكاوى المكلفين بها فقط
    if ($action === 'get_complaint' || $action === 'list') {
        requireAuth(); // موظف أو أدمن أو مشرف
        if ($action === 'get_complaint') {
            getComplaintDetail($pdo);
        } else {
            listComplaints($pdo);
        }
        exit;
    }
    
    // طلبات تغيير الحالة - للأدمن والمشرف فقط
    if ($action === 'status_requests') {
        requireAdmin(); // admin أو supervisor أو super_admin
        getStatusRequests($pdo);
        exit;
    }
    
    // باقي الـ actions للأدمن والمشرف فقط
    requireAdmin();
    switch ($action) {
        case 'stats':          getStats($pdo);           break;
        case 'list_users':     listUsers($pdo);          break;
        case 'monthly_report': monthlyReport($pdo);      break;
        case 'notifications':  getNotifications($pdo);   break;
        default: sendJson(["success"=>false,"message"=>"Invalid action"]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($action) {
        case 'update_status':
            // الأدمن والمشرف يقدروا يغيروا الحالة مباشرة
            // الموظف يطلب تغيير ويحتاج موافقة
            requireAuth();
            updateStatus($pdo);
            break;
        case 'request_status_change':
            requireAuth(); // الموظف يطلب تغيير الحالة
            requestStatusChange($pdo);
            break;
        case 'approve_status_change':
            requireAdmin(); // الأدمن أو المشرف يوافق
            approveStatusChange($pdo);
            break;
        case 'add_user':
        case 'delete_user':
            requireSuperAdmin(); // Super Admin فقط
            match($action) {
                'add_user'    => addUser($pdo),
                'delete_user' => deleteUser($pdo),
            };
            break;
        case 'assign':
        case 'mark_read':
            requireAdmin();
            match($action) {
                'assign'    => assignComplaint($pdo),
                'mark_read' => markRead($pdo),
            };
            break;
        case 'add_comment':
            requireAuth();
            addComment($pdo);
            break;
        case 'add_customer_reply':
            addCustomerReply($pdo);
            break;
        default:
            sendJson(["success"=>false,"message"=>"Invalid action"]);
    }
} else {
    sendJson(["success"=>false,"message"=>"Method Not Allowed"], 405);
}

// ============================================================
// FUNCTIONS
// ============================================================

function whoami() {
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['user_role'])) {
        sendJson(["success"=>true,"user"=>[
            "id"   => $_SESSION['user_id'],
            "name" => $_SESSION['user_name'] ?? 'مستخدم',
            "role" => $_SESSION['user_role'],
        ]]);
    }
    sendJson(["success"=>false], 401);
}

function getComplaintDetail($pdo) {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) sendJson(["success"=>false,"message"=>"ID مطلوب"]);

    try {
        // جلب الشكوى — query بسيط بدون JOIN لتجنب مشاكل الأعمدة غير الموجودة
        $stmt = $pdo->prepare("SELECT * FROM complaints WHERE id=?");
        $stmt->execute([$id]);
        $complaint = $stmt->fetch();
        if (!$complaint) { sendJson(["success"=>false,"message"=>"الشكوى غير موجودة - ID: $id"]); exit; }

        // إضافة أعمدة افتراضية لو مش موجودة في الجدول القديم
        $complaint['submission_type']  = $complaint['submission_type']  ?? 'شكوى';
        $complaint['complaint_code']   = $complaint['complaint_code']   ?? '#'.$id;
        $complaint['district']         = $complaint['district']         ?? '';
        $complaint['national_id']      = $complaint['national_id']      ?? '';
        $complaint['assigned_to']      = $complaint['assigned_to']      ?? null;
        $complaint['has_unread_reply'] = $complaint['has_unread_reply'] ?? 0;
        $complaint['assigned_name']    = null;

        // لو في assigned_to، جيب اسم المستخدم
        if (!empty($complaint['assigned_to'])) {
            $us = $pdo->prepare("SELECT name FROM users WHERE id=?");
            $us->execute([$complaint['assigned_to']]);
            $usr = $us->fetch();
            $complaint['assigned_name'] = $usr['name'] ?? null;
        }

        // إخفاء جزء من الهاتف
        if (!empty($complaint['phone'])) {
            $complaint['phone_masked'] = substr($complaint['phone'],0,4).'****'.substr($complaint['phone'],-3);
        }

        // سجل الحالات
        $hist = $pdo->prepare("SELECT * FROM status_history WHERE complaint_id=? ORDER BY timestamp ASC");
        $hist->execute([$id]);
        $history = $hist->fetchAll();

        // التعليقات — نتعامل مع غياب بعض الأعمدة
        $com = $pdo->prepare("SELECT * FROM comments WHERE complaint_id=? ORDER BY created_at ASC");
        $com->execute([$id]);
        $rawComments = $com->fetchAll();

        // إضافة أعمدة افتراضية للتعليقات
        $comments = array_map(function($c) {
            $c['is_internal'] = $c['is_internal'] ?? 0;
            $c['user_name']   = $c['user_name']   ?? ($c['user_type'] === 'admin' ? 'الإدارة' : 'المواطن');
            return $c;
        }, $rawComments);

        sendJson([
            "success"   => true,
            "complaint" => $complaint,
            "history"   => $history,
            "comments"  => $comments,
        ]);
    } catch (PDOException $e) {
        sendJson(["success"=>false,"message"=>"DB Error: ".$e->getMessage()]);
    }
}

function getStats($pdo) {
    try {
        $total      = (int)$pdo->query("SELECT COUNT(*) FROM complaints")->fetchColumn();
        $complaints = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE submission_type='شكوى'")->fetchColumn();
        $requests   = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE submission_type='طلب'")->fetchColumn();
        $open       = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE status NOT IN ('تم الحل','مغلقة','مرفوضة')")->fetchColumn();
        $closed     = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE status IN ('تم الحل','مغلقة')")->fetchColumn();
        $unread     = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE has_unread_reply=1")->fetchColumn();
        $avgRow     = $pdo->query("SELECT ROUND(AVG(TIMESTAMPDIFF(HOUR,created_at,updated_at)/24),1) as d FROM complaints WHERE status IN ('تم الحل','مغلقة') AND updated_at IS NOT NULL")->fetch();
        sendJson(["success"=>true,"stats"=>compact('total','complaints','requests','open','closed','unread','avgRow')]);
    } catch (PDOException $e) { sendJson(["success"=>false,"message"=>"DB Error"]); }
}

function listComplaints($pdo) {
    try {
        $status   = $_GET['status']          ?? '';
        $category = $_GET['category']        ?? '';
        $type     = $_GET['submission_type'] ?? '';
        $search   = $_GET['search']          ?? '';
        $district = $_GET['district']        ?? '';

        $sql    = "SELECT c.*, u.name as assigned_name FROM complaints c LEFT JOIN users u ON u.id=c.assigned_to WHERE 1=1";
        $params = [];

        // فلترة حسب الصلاحية
        // الموظف يشوف بس الشكاوى المكلف بها
        if ($_SESSION['user_role'] === 'agent') {
            $sql .= " AND c.assigned_to = ?";
            $params[] = $_SESSION['user_id'];
        }
        // الأدمن والمشرف يشوفوا كل حاجة

        if ($status)   { $sql .= " AND c.status=?";          $params[] = $status; }
        if ($category) { $sql .= " AND c.category=?";         $params[] = $category; }
        if ($type)     { $sql .= " AND c.submission_type=?";  $params[] = $type; }
        if ($district) { $sql .= " AND c.district=?";         $params[] = $district; }
        if ($search)   {
            $sql .= " AND (c.complaint_code LIKE ? OR c.phone LIKE ? OR c.title LIKE ? OR c.name LIKE ?)";
            $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
        }
        $sql .= " ORDER BY c.created_at DESC LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        sendJson(["success"=>true,"complaints"=>$stmt->fetchAll()]);
    } catch (PDOException $e) { sendJson(["success"=>false,"message"=>$e->getMessage()]); }
}

function listUsers($pdo) {
    try {
        $users = $pdo->query("SELECT id,name,email,phone,role,created_at FROM users ORDER BY id DESC")->fetchAll();
        sendJson(["success"=>true,"users"=>$users]);
    } catch (PDOException $e) { sendJson(["success"=>false]); }
}

function getNotifications($pdo) {
    try {
        // شكاوى فيها رد من العميل لم يُقرأ بعد
        $stmt = $pdo->query("
            SELECT c.id, c.complaint_code, c.submission_type, c.title, c.has_unread_reply, c.updated_at
            FROM complaints c
            WHERE c.has_unread_reply = 1
            ORDER BY c.updated_at DESC
            LIMIT 50
        ");
        sendJson(["success"=>true,"notifications"=>$stmt->fetchAll()]);
    } catch (PDOException $e) { sendJson(["success"=>false]); }
}

function monthlyReport($pdo) {
    try {
        $month = $_GET['month'] ?? date('Y-m');

        $stmt = $pdo->prepare("SELECT * FROM complaints WHERE DATE_FORMAT(created_at,'%Y-%m')=? ORDER BY created_at DESC");
        $stmt->execute([$month]);
        $all = $stmt->fetchAll();

        $byStatus=[]; $byCat=[]; $byType=['شكوى'=>0,'طلب'=>0];
        foreach ($all as $c) {
            $byStatus[$c['status']]   = ($byStatus[$c['status']]   ?? 0) + 1;
            $byCat[$c['category']]    = ($byCat[$c['category']]    ?? 0) + 1;
            $byType[$c['submission_type']] = ($byType[$c['submission_type']] ?? 0) + 1;
        }

        $solvedStmt = $pdo->prepare("SELECT COUNT(*) FROM complaints WHERE DATE_FORMAT(created_at,'%Y-%m')=? AND status IN ('تم الحل','مغلقة')");
        $solvedStmt->execute([$month]);
        $solved = (int)$solvedStmt->fetchColumn();

        sendJson([
            "success"     => true,
            "month"       => $month,
            "complaints"  => $all,
            "by_status"   => $byStatus,
            "by_category" => $byCat,
            "by_type"     => $byType,
            "total"       => count($all),
            "solved"      => $solved,
        ]);
    } catch (PDOException $e) { sendJson(["success"=>false,"message"=>"DB Error"]); }
}

function updateStatus($pdo) {
    $data    = getJsonInput();
    $id      = (int)($data['id'] ?? 0);
    $status  = cleanInput($data['status'] ?? '');
    $allowed = ['تم الاستلام','قيد المراجعة','جاري التنفيذ','تم الحل','مغلقة','مرفوضة'];
    if (!$id || !in_array($status, $allowed)) sendJson(["success"=>false,"message"=>"بيانات غير صحيحة"]);
    
    try {
        // لو موظف، يطلب الموافقة بدلاً من التغيير المباشر
        if ($_SESSION['user_role'] === 'agent') {
            sendJson(["success"=>false,"message"=>"الموظف يحتاج موافقة الأدمن لتغيير الحالة. استخدم زر 'طلب تغيير الحالة'"]);
        }
        
        // الأدمن والمشرف يغيروا مباشرة
        $pdo->prepare("UPDATE complaints SET status=?, updated_at=NOW(), last_action_by=?, last_action_type=? WHERE id=?")
            ->execute([$status, $_SESSION['user_id'], 'تغيير الحالة', $id]);
        
        $pdo->prepare("INSERT INTO status_history (complaint_id,status,changed_by,timestamp) VALUES(?,?,?,NOW())")
            ->execute([$id,$status,$_SESSION['user_id']]);
        
        sendJson(["success"=>true]);
    } catch (PDOException $e) { sendJson(["success"=>false]); }
}

function addComment($pdo) {
    // يقبل FormData أو JSON
    if (!empty($_POST['complaint_id'])) {
        $complaint_id = (int)$_POST['complaint_id'];
        $message      = cleanInput($_POST['message'] ?? '');
        $is_internal  = !empty($_POST['is_internal']) ? 1 : 0;
    } else {
        $data         = getJsonInput();
        $complaint_id = (int)($data['complaint_id'] ?? 0);
        $message      = cleanInput($data['message']    ?? '');
        $is_internal  = !empty($data['is_internal'])  ? 1 : 0;
    }
    if (!$complaint_id || !$message) sendJson(["success"=>false,"message"=>"بيانات ناقصة"]);

    $file_path  = handleFileUpload('file');
    $user_name  = $_SESSION['user_name'] ?? 'مدير';

    try {
        $pdo->prepare("INSERT INTO comments (complaint_id,user_type,user_name,message,file_path,is_internal,created_at)
                       VALUES(?,'admin',?,?,?,?,NOW())")
            ->execute([$complaint_id,$user_name,$message,$file_path,$is_internal]);

        // تسجيل آخر معاملة
        $action_type = $is_internal ? 'إضافة ملاحظة داخلية' : 'إضافة رد';
        $pdo->prepare("UPDATE complaints SET updated_at=NOW(), last_action_by=?, last_action_type=? WHERE id=?")
            ->execute([$_SESSION['user_id'], $action_type, $complaint_id]);

        // لو مش internal → mark الشكوى إن فيها رد من الأدمن (يمسح الـ unread flag)
        if (!$is_internal) {
            $pdo->prepare("UPDATE complaints SET has_unread_reply=0 WHERE id=?")->execute([$complaint_id]);
        }
        sendJson(["success"=>true]);
    } catch (PDOException $e) { sendJson(["success"=>false,"message"=>"DB Error"]); }
}

function addCustomerReply($pdo) {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $message      = cleanInput($_POST['message'] ?? '');
    if (!$complaint_id) sendJson(["success"=>false,"message"=>"رقم الشكوى مطلوب"]);

    $chk = $pdo->prepare("SELECT id FROM complaints WHERE id=?");
    $chk->execute([$complaint_id]);
    if (!$chk->fetch()) sendJson(["success"=>false,"message"=>"الشكوى غير موجودة"]);

    $file_path = handleFileUpload('file');
    try {
        $pdo->prepare("INSERT INTO comments (complaint_id,user_type,user_name,message,file_path,is_internal,created_at)
                       VALUES(?,'customer','المواطن',?,?,0,NOW())")
            ->execute([$complaint_id,$message,$file_path]);

        // وضع علامة "يوجد رد غير مقروء" على الشكوى
        $pdo->prepare("UPDATE complaints SET has_unread_reply=1,updated_at=NOW() WHERE id=?")->execute([$complaint_id]);

        sendJson(["success"=>true]);
    } catch (PDOException $e) { sendJson(["success"=>false,"message"=>"DB Error"]); }
}

function assignComplaint($pdo) {
    $data    = getJsonInput();
    $id      = (int)($data['complaint_id'] ?? 0);
    $userId  = (int)($data['user_id']      ?? 0);
    if (!$id) sendJson(["success"=>false,"message"=>"ID مطلوب"]);
    try {
        $pdo->prepare("UPDATE complaints SET assigned_to=?,updated_at=NOW() WHERE id=?")->execute([$userId?:null,$id]);
        sendJson(["success"=>true]);
    } catch (PDOException $e) { sendJson(["success"=>false]); }
}

function markRead($pdo) {
    $data = getJsonInput();
    $id   = (int)($data['complaint_id'] ?? 0);
    if (!$id) sendJson(["success"=>false]);
    try {
        $pdo->prepare("UPDATE complaints SET has_unread_reply=0 WHERE id=?")->execute([$id]);
        sendJson(["success"=>true]);
    } catch (PDOException $e) { sendJson(["success"=>false]); }
}

function addUser($pdo) {
    $data     = getJsonInput();
    $name     = cleanInput($data['name']  ?? '');
    $email    = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $password = $data['password'] ?? '';
    $phone    = cleanInput($data['phone'] ?? '');
    $role     = in_array($data['role']??'',['admin','agent']) ? $data['role'] : 'agent';
    if (!$name||!$email||strlen($password)<8) sendJson(["success"=>false,"message"=>"بيانات ناقصة أو كلمة مرور أقل من 8 أحرف"]);
    $chk=$pdo->prepare("SELECT id FROM users WHERE email=?"); $chk->execute([$email]);
    if ($chk->fetch()) sendJson(["success"=>false,"message"=>"البريد مسجل مسبقاً"]);
    try {
        $pdo->prepare("INSERT INTO users (name,email,password,phone,role) VALUES(?,?,?,?,?)")
            ->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$phone,$role]);
        sendJson(["success"=>true]);
    } catch (PDOException $e) { sendJson(["success"=>false,"message"=>"DB Error"]); }
}

function deleteUser($pdo) {
    $data = getJsonInput();
    $id   = (int)($data['id'] ?? 0);
    if (!$id) sendJson(["success"=>false,"message"=>"ID مطلوب"]);
    if ($id===(int)$_SESSION['user_id']) sendJson(["success"=>false,"message"=>"لا يمكنك حذف حسابك"]);
    try {
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
        sendJson(["success"=>true]);
    } catch (PDOException $e) { sendJson(["success"=>false,"message"=>"DB Error"]); }
}

// ==========================================
// دوال طلبات تغيير الحالة
// ==========================================
function requestStatusChange($pdo) {
    $data    = getJsonInput();
    $id      = (int)($data['complaint_id'] ?? 0);
    $status  = cleanInput($data['status'] ?? '');
    $reason  = cleanInput($data['reason'] ?? '');
    $allowed = ['تم الاستلام','قيد المراجعة','جاري التنفيذ','تم الحل','مغلقة','مرفوضة'];
    
    if (!$id || !in_array($status, $allowed)) sendJson(["success"=>false,"message"=>"بيانات غير صحيحة"]);
    
    try {
        // التحقق من أن الشكوى مكلف بها للموظف
        if ($_SESSION['user_role'] === 'agent') {
            $check = $pdo->prepare("SELECT id FROM complaints WHERE id=? AND assigned_to=?");
            $check->execute([$id, $_SESSION['user_id']]);
            if (!$check->fetch()) {
                sendJson(["success"=>false,"message"=>"لست مكلفاً بهذه الشكوى"]);
            }
        }
        
        // إنشاء طلب تغيير الحالة
        $pdo->prepare("INSERT INTO status_change_requests (complaint_id, requested_status, requested_by, reason, created_at) 
                       VALUES (?, ?, ?, ?, NOW())")
            ->execute([$id, $status, $_SESSION['user_id'], $reason]);
        
        sendJson(["success"=>true, "message"=>"تم إرسال طلب التغيير للمراجعة"]);
    } catch (PDOException $e) { 
        sendJson(["success"=>false,"message"=>"DB Error: ".$e->getMessage()]); 
    }
}

function approveStatusChange($pdo) {
    $data       = getJsonInput();
    $requestId  = (int)($data['request_id'] ?? 0);
    $approve    = (bool)($data['approve'] ?? false);
    
    if (!$requestId) sendJson(["success"=>false,"message"=>"Request ID مطلوب"]);
    
    try {
        // جلب الطلب
        $stmt = $pdo->prepare("SELECT * FROM status_change_requests WHERE id=? AND status='pending'");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        
        if (!$request) sendJson(["success"=>false,"message"=>"الطلب غير موجود أو تمت معالجته"]);
        
        if ($approve) {
            // الموافقة على التغيير
            $pdo->prepare("UPDATE complaints SET status=?, updated_at=NOW(), last_action_by=?, last_action_type=? WHERE id=?")
                ->execute([$request['requested_status'], $_SESSION['user_id'], 'موافقة على تغيير الحالة', $request['complaint_id']]);
            
            $pdo->prepare("INSERT INTO status_history (complaint_id, status, changed_by, timestamp) VALUES(?, ?, ?, NOW())")
                ->execute([$request['complaint_id'], $request['requested_status'], $_SESSION['user_id']]);
            
            $pdo->prepare("UPDATE status_change_requests SET status='approved', approved_by=?, updated_at=NOW() WHERE id=?")
                ->execute([$_SESSION['user_id'], $requestId]);
            
            sendJson(["success"=>true, "message"=>"تمت الموافقة على التغيير"]);
        } else {
            // رفض الطلب
            $pdo->prepare("UPDATE status_change_requests SET status='rejected', approved_by=?, updated_at=NOW() WHERE id=?")
                ->execute([$_SESSION['user_id'], $requestId]);
            
            sendJson(["success"=>true, "message"=>"تم رفض الطلب"]);
        }
    } catch (PDOException $e) { 
        sendJson(["success"=>false,"message"=>"DB Error: ".$e->getMessage()]); 
    }
}

function getStatusRequests($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT sr.*, 
                   c.complaint_code, c.title, c.status as current_status,
                   u1.name as requester_name,
                   u2.name as approver_name
            FROM status_change_requests sr
            LEFT JOIN complaints c ON c.id = sr.complaint_id
            LEFT JOIN users u1 ON u1.id = sr.requested_by
            LEFT JOIN users u2 ON u2.id = sr.approved_by
            WHERE sr.status = 'pending'
            ORDER BY sr.created_at DESC
        ");
        sendJson(["success"=>true, "requests"=>$stmt->fetchAll()]);
    } catch (PDOException $e) { 
        sendJson(["success"=>false,"message"=>"DB Error"]); 
    }
}
?>

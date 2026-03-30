<?php
// ============================================================
// dashboard.php — بوابة لوحة التحكم
// ============================================================
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_start();
// ملاحظة: لا نعمل auto-redirect هنا لمنع الـ infinite loop
// المستخدم يسجل دخول في كل مرة (by design)
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>لوحة التحكم — حدائق القبة</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Tajawal',sans-serif;background:linear-gradient(135deg,#0f766e,#1e293b);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px}
.card{background:#fff;border-radius:20px;box-shadow:0 25px 60px rgba(0,0,0,.35);padding:40px;width:100%;max-width:420px}
.logo{width:64px;height:64px;background:#0d9488;border-radius:16px;display:flex;align-items:center;justify-content:center;margin:0 auto 20px}
h1{font-size:22px;font-weight:800;color:#1e293b;text-align:center;margin-bottom:4px}
.sub{font-size:13px;color:#64748b;text-align:center;margin-bottom:28px}
.field{margin-bottom:16px}
label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px}
input{width:100%;padding:13px 16px;border:2px solid #e2e8f0;border-radius:12px;font-size:15px;font-family:'Tajawal',sans-serif;outline:none;transition:border-color .2s;color:#1e293b}
input:focus{border-color:#0d9488;box-shadow:0 0 0 3px rgba(13,148,136,.1)}
.btn{width:100%;padding:14px;background:#0d9488;color:#fff;border:none;border-radius:12px;font-size:16px;font-weight:700;font-family:'Tajawal',sans-serif;cursor:pointer;transition:background .2s;margin-top:8px}
.btn:hover:not(:disabled){background:#0f766e}
.btn:disabled{background:#94a3b8;cursor:not-allowed}
.err{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;border-radius:10px;padding:12px 16px;font-size:13px;margin-bottom:16px;display:none;line-height:1.5}
.ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;border-radius:10px;padding:12px 16px;font-size:13px;margin-bottom:16px;display:none}
.back{display:block;text-align:center;color:#94a3b8;font-size:12px;margin-top:20px;text-decoration:none}
.back:hover{color:#64748b}
@keyframes spin{to{transform:rotate(360deg)}}
.spin{display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;margin-right:6px}
.debug{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;font-size:11px;color:#64748b;margin-top:12px;display:none;word-break:break-all;direction:ltr;text-align:left}
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <svg width="32" height="32" fill="none" stroke="white" stroke-width="2" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
    </svg>
  </div>
  <h1>لوحة التحكم</h1>
  <p class="sub">نظام إدارة شكاوى حدائق القبة</p>

  <div class="err" id="err"></div>
  <div class="ok"  id="ok">✅ تم تسجيل الدخول، جاري التحويل...</div>

  <div class="field">
    <label for="email">البريد الإلكتروني</label>
    <input type="email" id="email" placeholder="admin@example.com" autocomplete="email"
           onkeydown="if(event.key==='Enter')document.getElementById('password').focus()">
  </div>
  <div class="field">
    <label for="password">كلمة المرور</label>
    <input type="password" id="password" placeholder="••••••••" autocomplete="current-password"
           onkeydown="if(event.key==='Enter')doLogin()">
  </div>

  <button class="btn" id="btn" onclick="doLogin()">دخول لوحة التحكم</button>

  <div class="debug" id="debug"></div>
  <a href="/" class="back">← العودة للموقع الرئيسي</a>
</div>

<script>
// ─── كشف مسار الـ API تلقائياً ───────────────────────────────
function apiUrl() {
  // نكتشف المسار الصح بناءً على مكان dashboard.php
  const base = window.location.pathname.replace(/\/[^\/]*$/, '') || '';
  return base + '/api/login.php';
}

async function doLogin() {
  const email    = document.getElementById('email').value.trim();
  const password = document.getElementById('password').value;
  const btn      = document.getElementById('btn');
  const err      = document.getElementById('err');
  const ok       = document.getElementById('ok');
  const dbg      = document.getElementById('debug');

  // إخفاء الرسائل
  err.style.display = 'none';
  ok.style.display  = 'none';
  dbg.style.display = 'none';

  // Validation أولي
  if (!email) { showErr('أدخل البريد الإلكتروني'); return; }
  if (!password) { showErr('أدخل كلمة المرور'); return; }
  if (!email.includes('@')) { showErr('البريد الإلكتروني غير صحيح'); return; }

  // تعطيل الزرار
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span>جاري التحقق...';

  try {
    const url = apiUrl();

    const res = await fetch(url, {
      method:      'POST',
      headers:     { 'Content-Type': 'application/json' },
      credentials: 'include',
      body:        JSON.stringify({ email, password })
    });

    // قراءة الـ response كـ text أولاً
    const rawText = await res.text();

    // محاولة Parse
    let data;
    try {
      const clean = rawText.trim().replace(/^\uFEFF/, '').replace(/^[^{[]*/, '');
      data = JSON.parse(clean);
    } catch(parseErr) {
      // عرض الـ raw response للـ debug
      dbg.textContent = 'Server Response: ' + rawText.substring(0, 500);
      dbg.style.display = 'block';
      showErr('الخادم أرجع استجابة غير متوقعة. تحقق من إعدادات قاعدة البيانات.');
      reset(); return;
    }

    if (data.success) {
      // لا نحفظ البيانات — التحقق يتم من PHP session فقط
      ok.style.display = 'block';

      // التوجيه للـ app بعد 500ms
      setTimeout(() => {
        // نستخدم query param بدلاً من hash — أكثر موثوقية
        const base = window.location.pathname.replace(/\/dashboard\.php$/, '') || '';
        window.location.replace(base + '/index.html?goto=dashboard');
      }, 500);

    } else {
      showErr(data.message || 'بيانات الدخول غير صحيحة');
      reset();
    }

  } catch(networkErr) {
    dbg.textContent = 'Network Error: ' + networkErr.message;
    dbg.style.display = 'block';
    showErr('خطأ في الاتصال — تأكد من رفع ملفات الـ API على الخادم');
    reset();
  }
}

function showErr(msg) {
  const e = document.getElementById('err');
  e.textContent = msg;
  e.style.display = 'block';
}

function reset() {
  const btn = document.getElementById('btn');
  btn.disabled = false;
  btn.textContent = 'دخول لوحة التحكم';
}
</script>
</body>
</html>

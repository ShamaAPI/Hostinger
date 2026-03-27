<?php
// لوحة التحكم - رابط خاص منفصل عن الصفحة الرئيسية
// URL: /dashboard.php
session_start();
// لو مسجل دخول ادمن → روحه للـ app مع hash
if (!empty($_SESSION['user_id']) && !empty($_SESSION['user_role'])) {
    header("Location: /#admin-panel");
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>لوحة التحكم — حدائق القبة</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
  body { font-family: 'Tajawal', sans-serif; }
  .gradient-bg { background: linear-gradient(135deg, #0f766e 0%, #1e293b 100%); }
</style>
</head>
<body class="gradient-bg min-h-screen flex items-center justify-center p-4">
  <div class="w-full max-w-md">
    <div class="bg-white rounded-2xl shadow-2xl p-8">
      <div class="text-center mb-8">
        <div class="w-16 h-16 rounded-2xl bg-teal-600 flex items-center justify-center mx-auto mb-4">
          <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
          </svg>
        </div>
        <h1 class="text-2xl font-bold text-gray-800">لوحة التحكم</h1>
        <p class="text-gray-500 text-sm mt-1">نظام إدارة شكاوى حدائق القبة</p>
      </div>

      <div id="error-msg" class="hidden bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3 mb-4"></div>

      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">البريد الإلكتروني</label>
          <input type="email" id="email" placeholder="admin@example.com"
            class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:outline-none focus:border-teal-500 transition-colors"
            onkeydown="if(event.key==='Enter') doLogin()">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">كلمة المرور</label>
          <input type="password" id="password" placeholder="••••••••"
            class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:outline-none focus:border-teal-500 transition-colors"
            onkeydown="if(event.key==='Enter') doLogin()">
        </div>
        <button onclick="doLogin()" id="login-btn"
          class="w-full bg-teal-600 hover:bg-teal-700 text-white py-3 rounded-xl font-semibold transition-colors mt-2">
          دخول لوحة التحكم
        </button>
      </div>

      <p class="text-center text-xs text-gray-400 mt-6">
        للعودة للموقع الرئيسي
        <a href="/" class="text-teal-600 hover:underline">اضغط هنا</a>
      </p>
    </div>
  </div>

<script>
async function doLogin() {
  const email = document.getElementById('email').value.trim();
  const pass  = document.getElementById('password').value;
  const btn   = document.getElementById('login-btn');
  const err   = document.getElementById('error-msg');
  
  if (!email || !pass) { showErr('أدخل البريد وكلمة المرور'); return; }
  
  btn.disabled = true; btn.textContent = 'جاري الدخول...';
  err.classList.add('hidden');

  try {
    const res  = await fetch('api/login.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ email, password: pass })
    });
    const text = await res.text();
    const data = JSON.parse(text.trim());

    if (data.success) {
      // حفظ بيانات المستخدم في sessionStorage للـ SPA
      sessionStorage.setItem('dashboard_user', JSON.stringify(data.user));
      window.location.href = '/index.html#dashboard';
    } else {
      showErr(data.message || 'بيانات غير صحيحة');
    }
  } catch(e) {
    showErr('خطأ في الاتصال بالخادم');
  }
  
  btn.disabled = false; btn.textContent = 'دخول لوحة التحكم';
}

function showErr(msg) {
  const e = document.getElementById('error-msg');
  e.textContent = msg;
  e.classList.remove('hidden');
}
</script>
</body>
</html>

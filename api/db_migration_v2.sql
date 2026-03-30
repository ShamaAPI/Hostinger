-- ============================================================
-- Migration v2 — تعديلات النسخة الجديدة
-- نفّذ هذا الملف مرة واحدة في phpMyAdmin
-- ============================================================

-- 1. إضافة أعمدة جديدة لجدول complaints
ALTER TABLE complaints
  ADD COLUMN IF NOT EXISTS submission_type ENUM('شكوى','طلب') NOT NULL DEFAULT 'شكوى' AFTER complaint_code,
  ADD COLUMN IF NOT EXISTS national_id VARCHAR(14) NULL AFTER phone,
  ADD COLUMN IF NOT EXISTS district VARCHAR(100) NULL AFTER address,
  ADD COLUMN IF NOT EXISTS assigned_to INT NULL AFTER is_anonymous,
  ADD COLUMN IF NOT EXISTS has_unread_reply TINYINT(1) DEFAULT 0 AFTER updated_at;

-- 2. إضافة عمود is_internal للتعليقات (ملاحظات داخلية للأدمن فقط)
ALTER TABLE comments
  ADD COLUMN IF NOT EXISTS is_internal TINYINT(1) DEFAULT 0 AFTER file_path,
  ADD COLUMN IF NOT EXISTS user_name VARCHAR(100) NULL AFTER user_type;

-- 3. Index للأداء مع مليون+ سجل
CREATE INDEX IF NOT EXISTS idx_created_at      ON complaints(created_at);
CREATE INDEX IF NOT EXISTS idx_submission_type ON complaints(submission_type);
CREATE INDEX IF NOT EXISTS idx_assigned_to     ON complaints(assigned_to);
CREATE INDEX IF NOT EXISTS idx_unread          ON complaints(has_unread_reply);
CREATE INDEX IF NOT EXISTS idx_district        ON complaints(district);
CREATE INDEX IF NOT EXISTS idx_phone           ON complaints(phone);
CREATE INDEX IF NOT EXISTS idx_complaint_code  ON complaints(complaint_code);
CREATE INDEX IF NOT EXISTS idx_status_cat      ON complaints(status, category);
CREATE INDEX IF NOT EXISTS idx_comments_cid    ON comments(complaint_id);

-- 4. تحسين الأداء — InnoDB row format للجداول الكبيرة
ALTER TABLE complaints ROW_FORMAT=COMPRESSED;


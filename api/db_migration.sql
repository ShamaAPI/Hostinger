-- Migration: إضافة عمود complaint_code لجدول complaints
-- نفّذ هذا الـ SQL مرة واحدة على قاعدة البيانات

ALTER TABLE complaints 
ADD COLUMN IF NOT EXISTS complaint_code VARCHAR(20) NULL UNIQUE AFTER id,
ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL AFTER created_at;

-- إضافة index للبحث السريع
CREATE INDEX IF NOT EXISTS idx_complaint_code ON complaints(complaint_code);
CREATE INDEX IF NOT EXISTS idx_status ON complaints(status);
CREATE INDEX IF NOT EXISTS idx_category ON complaints(category);

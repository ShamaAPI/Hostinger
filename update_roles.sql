-- إضافة عمود لتسجيل آخر شخص عدل الشكوى
ALTER TABLE complaints 
ADD COLUMN last_action_by INT NULL AFTER updated_at,
ADD COLUMN last_action_type VARCHAR(50) NULL AFTER last_action_by;

-- إضافة جدول لطلبات تغيير الحالة
CREATE TABLE IF NOT EXISTS status_change_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT NOT NULL,
    requested_status VARCHAR(50) NOT NULL,
    requested_by INT NOT NULL,
    reason TEXT,
    approved_by INT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تعديل جدول المستخدمين لإضافة صلاحيات جديدة
ALTER TABLE users 
MODIFY COLUMN role ENUM('super_admin', 'admin', 'supervisor', 'agent') DEFAULT 'agent';

-- تحديث المستخدم الأول ليكون super_admin
UPDATE users SET role = 'super_admin' WHERE id = 1 LIMIT 1;

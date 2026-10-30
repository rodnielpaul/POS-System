-- Database Migration for POS System
-- Supports Account Management, Inventory, Suppliers, and POS Modules

USE pos_system;

-- 1. Suppliers Table
CREATE TABLE IF NOT EXISTS suppliers (
    supplier_id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(100) NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(100) NULL,
    address TEXT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Modify menu_items to support inventory, barcodes, and suppliers
-- (Safe column additions handled in migrate.php via conditional checks)

-- 3. Inventory Logs Table (Restock, Sales, Adjustments)
CREATE TABLE IF NOT EXISTS inventory_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    supplier_id INT NULL,
    action_type ENUM('restock', 'sale', 'adjustment', 'loss/damage') NOT NULL,
    quantity_change INT NOT NULL,
    cost_per_unit DECIMAL(10,2) DEFAULT 0.00,
    reference_no VARCHAR(100) NULL,
    notes TEXT NULL,
    user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES menu_items(item_id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. System Logs Table (Audit Trail)
CREATE TABLE IF NOT EXISTS system_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(50) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. System Settings Table
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default settings if not exists
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('store_name', 'Capuzan Kusina'),
('store_address', '123 Main Street, Metro Manila'),
('store_contact', '+63 912 345 6789'),
('tax_rate', '12'),
('receipt_footer', 'Thank you for your purchase! Please come again.');

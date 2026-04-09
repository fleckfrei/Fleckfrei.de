<?php
// Auto-create local-only tables if missing
$dbLocal->exec("CREATE TABLE IF NOT EXISTS invoice_payments (
    ip_id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id_fk INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_date DATE DEFAULT NULL,
    payment_method VARCHAR(50) DEFAULT '',
    note TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invoice (invoice_id_fk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$dbLocal->exec("CREATE TABLE IF NOT EXISTS customer_address (
    ca_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id_fk INT NOT NULL,
    street VARCHAR(255) DEFAULT '',
    number VARCHAR(20) DEFAULT '',
    postal_code VARCHAR(10) DEFAULT '',
    city VARCHAR(100) DEFAULT '',
    country VARCHAR(100) DEFAULT 'Deutschland',
    address_for VARCHAR(50) DEFAULT 'location',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id_fk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$dbLocal->exec("CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) DEFAULT '',
    last_name VARCHAR(100) DEFAULT '',
    company VARCHAR(255) DEFAULT '',
    phone VARCHAR(50) DEFAULT '',
    email VARCHAR(255) DEFAULT '',
    website VARCHAR(255) DEFAULT '',
    invoice_prefix VARCHAR(20) DEFAULT 'INV-',
    invoice_number INT DEFAULT 1,
    street VARCHAR(255) DEFAULT '',
    number VARCHAR(20) DEFAULT '',
    postal_code VARCHAR(10) DEFAULT '',
    city VARCHAR(100) DEFAULT '',
    country VARCHAR(100) DEFAULT 'Deutschland',
    bank VARCHAR(255) DEFAULT '',
    iban VARCHAR(50) DEFAULT '',
    bic VARCHAR(20) DEFAULT '',
    USt_IdNr VARCHAR(50) DEFAULT '',
    business_number VARCHAR(50) DEFAULT '',
    fiscal_number VARCHAR(50) DEFAULT '',
    invoice_text TEXT DEFAULT NULL,
    note_for_email TEXT DEFAULT NULL,
    email_booking TINYINT DEFAULT 1,
    email_job_start TINYINT DEFAULT 1,
    email_job_complete TINYINT DEFAULT 1,
    email_invoice TINYINT DEFAULT 1,
    email_reminder TINYINT DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

try {
    $hasSettings = $dbLocal->query("SELECT COUNT(*) FROM settings")->fetchColumn();
    if (!$hasSettings) {
        $dbLocal->exec("INSERT INTO settings (company) VALUES ('')");
    }
} catch (Exception $e) {}

// Channel bookings cache (Smoobu sync)
$dbLocal->exec("CREATE TABLE IF NOT EXISTS channel_bookings (
    cb_id INT AUTO_INCREMENT PRIMARY KEY,
    smoobu_id INT DEFAULT NULL,
    guest_name VARCHAR(255) DEFAULT '',
    guest_email VARCHAR(255) DEFAULT '',
    guest_phone VARCHAR(100) DEFAULT '',
    property_name VARCHAR(255) DEFAULT '',
    property_id INT DEFAULT NULL,
    channel VARCHAR(50) DEFAULT '',
    check_in DATE DEFAULT NULL,
    check_out DATE DEFAULT NULL,
    adults INT DEFAULT 1,
    children INT DEFAULT 0,
    price DECIMAL(10,2) DEFAULT 0,
    currency VARCHAR(10) DEFAULT 'EUR',
    status VARCHAR(50) DEFAULT 'confirmed',
    notes TEXT DEFAULT NULL,
    job_id INT DEFAULT NULL,
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_smoobu (smoobu_id),
    INDEX idx_checkin (check_in),
    INDEX idx_property (property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// iCal feeds (if not on master DB)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS ical_feeds (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id_fk INT DEFAULT NULL,
        label VARCHAR(255) DEFAULT '',
        url TEXT NOT NULL,
        platform VARCHAR(50) DEFAULT 'ical',
        active TINYINT DEFAULT 1,
        last_sync DATETIME DEFAULT NULL,
        jobs_created INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_customer (customer_id_fk)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Add ical_uid column to jobs if missing
try {
    $cols = $db->query("SHOW COLUMNS FROM jobs LIKE 'ical_uid'")->fetchAll();
    if (empty($cols)) { $db->exec("ALTER TABLE jobs ADD COLUMN ical_uid VARCHAR(255) DEFAULT NULL, ADD INDEX idx_ical_uid (ical_uid)"); }
} catch (Exception $e) {}

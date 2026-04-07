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

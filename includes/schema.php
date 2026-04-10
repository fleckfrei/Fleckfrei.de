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

// Dynamic Pricing — Self-Learning Algorithm
$dbLocal->exec("CREATE TABLE IF NOT EXISTS pricing_rules (
    pr_id INT AUTO_INCREMENT PRIMARY KEY,
    rule_type ENUM('base','season','demand','weekend','special') DEFAULT 'base',
    name VARCHAR(255) DEFAULT '',
    multiplier DECIMAL(5,3) DEFAULT 1.000,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    day_of_week VARCHAR(20) DEFAULT NULL,
    min_occupancy INT DEFAULT NULL,
    max_occupancy INT DEFAULT NULL,
    active TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$dbLocal->exec("CREATE TABLE IF NOT EXISTS pricing_history (
    ph_id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT DEFAULT NULL,
    customer_id INT DEFAULT NULL,
    base_price DECIMAL(10,2) DEFAULT 0,
    final_price DECIMAL(10,2) DEFAULT 0,
    multiplier DECIMAL(5,3) DEFAULT 1.000,
    rules_applied TEXT DEFAULT NULL,
    occupancy_pct INT DEFAULT 0,
    job_date DATE DEFAULT NULL,
    accepted TINYINT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date (job_date),
    INDEX idx_service (service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ============================================================
// Palantir-Lite Ontology (Gotham) — Session 12
// 3 tables: typed objects, typed links, temporal events
// Backs /admin/gotham.php unified investigation UI
// ============================================================
$dbLocal->exec("CREATE TABLE IF NOT EXISTS ontology_objects (
    obj_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    obj_type VARCHAR(24) NOT NULL,
    obj_key VARCHAR(255) NOT NULL,
    display_name VARCHAR(500) NOT NULL,
    properties JSON DEFAULT NULL,
    confidence FLOAT DEFAULT 0.0,
    verified TINYINT(1) DEFAULT 0,
    source_count INT DEFAULT 1,
    source_scans JSON DEFAULT NULL,
    first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT DEFAULT NULL,
    UNIQUE KEY uk_type_key (obj_type, obj_key(191)),
    INDEX idx_display (display_name(100)),
    INDEX idx_type (obj_type),
    INDEX idx_verified (verified),
    INDEX idx_updated (last_updated)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$dbLocal->exec("CREATE TABLE IF NOT EXISTS ontology_links (
    link_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    from_obj BIGINT NOT NULL,
    to_obj BIGINT NOT NULL,
    relation VARCHAR(64) NOT NULL,
    source VARCHAR(128) DEFAULT NULL,
    confidence FLOAT DEFAULT 0.5,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_link (from_obj, to_obj, relation),
    INDEX idx_from (from_obj),
    INDEX idx_to (to_obj),
    INDEX idx_relation (relation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$dbLocal->exec("CREATE TABLE IF NOT EXISTS ontology_events (
    event_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    obj_id BIGINT NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    event_date DATE DEFAULT NULL,
    title VARCHAR(500) NOT NULL,
    data JSON DEFAULT NULL,
    source VARCHAR(128) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_obj (obj_id),
    INDEX idx_date (event_date),
    INDEX idx_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Insert default pricing rules if empty
try {
    $prCount = $dbLocal->query("SELECT COUNT(*) FROM pricing_rules")->fetchColumn();
    if (!$prCount) {
        $dbLocal->exec("INSERT INTO pricing_rules (rule_type, name, multiplier) VALUES
            ('base', 'Standard', 1.000),
            ('weekend', 'Wochenende +15%', 1.150),
            ('season', 'Sommer (Jun-Aug) +10%', 1.100),
            ('season', 'Weihnachten (Dez) +25%', 1.250),
            ('demand', 'Hohe Auslastung >80% +20%', 1.200),
            ('demand', 'Niedrige Auslastung <30% -10%', 0.900),
            ('special', 'Neukunde -5%', 0.950),
            ('special', 'Stammkunde >10 Jobs -3%', 0.970)
        ");
    }
} catch (Exception $e) {}

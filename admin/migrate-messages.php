<?php
/**
 * Migration: Create messages table + add email features
 * Run once, then delete.
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$results = [];

// Create messages table
try {
    q("CREATE TABLE IF NOT EXISTS messages (
        msg_id INT AUTO_INCREMENT PRIMARY KEY,
        job_id INT DEFAULT NULL,
        sender_type ENUM('admin','customer','employee','system','ai') NOT NULL,
        sender_id INT DEFAULT NULL,
        sender_name VARCHAR(100) DEFAULT NULL,
        recipient_type ENUM('admin','customer','employee') NOT NULL,
        recipient_id INT DEFAULT NULL,
        message TEXT NOT NULL,
        translated_message TEXT DEFAULT NULL,
        channel ENUM('portal','whatsapp','email','system') DEFAULT 'portal',
        read_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_recipient (recipient_type, recipient_id),
        INDEX idx_sender (sender_type, sender_id),
        INDEX idx_job (job_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = 'messages table: OK';
} catch (Exception $e) {
    $results[] = 'messages table: ' . $e->getMessage();
}

// Add notification preferences to settings
try {
    $cols = all("SHOW COLUMNS FROM settings LIKE 'email_notifications'");
    if (empty($cols)) {
        q("ALTER TABLE settings ADD COLUMN email_notifications TINYINT(1) DEFAULT 1");
        $results[] = 'settings.email_notifications: ADDED';
    } else {
        $results[] = 'settings.email_notifications: already exists';
    }
} catch (Exception $e) {
    $results[] = 'settings column: ' . $e->getMessage();
}

// Add email_preferences to customer table
try {
    $cols = all("SHOW COLUMNS FROM customer LIKE 'email_notifications'");
    if (empty($cols)) {
        q("ALTER TABLE customer ADD COLUMN email_notifications TINYINT(1) DEFAULT 1");
        $results[] = 'customer.email_notifications: ADDED';
    } else {
        $results[] = 'customer.email_notifications: already exists';
    }
} catch (Exception $e) {
    $results[] = 'customer column: ' . $e->getMessage();
}

// Verify
$count = val("SELECT COUNT(*) FROM messages");
$results[] = "messages count: $count";

header('Content-Type: text/plain');
echo "Migration Results:\n" . implode("\n", $results) . "\n\nDone at " . date('Y-m-d H:i:s');

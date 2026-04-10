<?php
// Gotham moved — redirect to integrated OSI Scanner (has Ontology Graph built in)
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
header('Location: /admin/scanner.php');
exit;

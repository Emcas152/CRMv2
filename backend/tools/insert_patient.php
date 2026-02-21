<?php
// Usage: php insert_patient.php parsed_patient.json
require_once __DIR__ . '/../imported-src/core/helpers.php';
require_once __DIR__ . '/../imported-src/core/Database.php';

if ($argc < 2) {
    echo "Usage: php insert_patient.php <parsed_json>\n";
    exit(2);
}

$path = $argv[1];
if (!file_exists($path)) {
    echo "File not found: $path\n";
    exit(1);
}

$json = json_decode(file_get_contents($path), true);
if (!$json) {
    echo "Invalid JSON in $path\n";
    exit(1);
}

$dbData = $json['db'] ?? null;
if (!$dbData) {
    echo "No 'db' object found in JSON\n";
    exit(1);
}

// Basic sanitization
$name = trim($dbData['name'] ?? '');
$email = trim($dbData['email'] ?? '');
$phone = trim($dbData['phone'] ?? '');
$birthday = isset($dbData['birthday']) ? trim($dbData['birthday']) : null;
$address = trim($dbData['address'] ?? '');
$nit = trim($dbData['nit'] ?? '');

if (empty($name) || empty($email)) {
    echo "Name and email are required for insertion. Found name='" . $name . "', email='" . $email . "'\n";
    // proceed optionally? stop
    exit(3);
}

try {
    $db = Database::getInstance();
    // Ensure nit column exists
    try { $db->execute("ALTER TABLE patients ADD COLUMN nit VARCHAR(100) NULL", []); } catch (Exception $e) {}

    // Check duplicate email
    $existing = $db->fetchOne('SELECT id FROM patients WHERE email = ?', [$email]);
    if ($existing) {
        echo "A patient with email $email already exists (id: " . $existing['id'] . ").\n";
        exit(0);
    }

    $db->execute(
        'INSERT INTO patients (name, email, phone, birthday, address, nit, loyalty_points, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW(), NOW())',
        [$name, $email, $phone ?: null, $birthday ?: null, $address ?: null, $nit ?: null]
    );

    $id = $db->lastInsertId();
    echo "Inserted patient id: $id\n";
} catch (Exception $e) {
    echo "ERROR inserting patient: " . $e->getMessage() . "\n";
    exit(1);
}

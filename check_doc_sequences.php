<?php
require_once __DIR__ . '/includes/bootstrap.php';
$db = $GLOBALS['db'] ?? null;

if (!$db instanceof mysqli) {
    die("DB not available");
}

echo "Checking doc_sequences table structure...\n";

// Check if table exists
$result = $db->query("DESCRIBE doc_sequences");
if ($result) {
    echo "doc_sequences table exists. Columns:\n";
    while ($row = $result->fetch_assoc()) {
        echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
    $result->free();
} else {
    echo "doc_sequences table does not exist or error: " . $db->error . "\n";
}

echo "\nChecking all tables:\n";
$result = $db->query("SHOW TABLES LIKE '%doc%'");
if ($result) {
    while ($row = $result->fetch_row()) {
        echo "- " . $row[0] . "\n";
    }
    $result->free();
}
?>

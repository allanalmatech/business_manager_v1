<?php
// Simple migration runner
try {
    $db = new mysqli('localhost', 'root', '', 'business_manager_v1');
    
    if ($db->connect_error) {
        die("Connection failed: " . $db->connect_error);
    }
    
    echo "Connected to database\n";
    
    // Read and execute the migration
    $sql = file_get_contents(__DIR__ . '/migrations/create_doc_sequences_table.sql');
    
    if ($db->multi_query($sql)) {
        echo "Table created successfully\n";
        do {
            // consume all results
        } while ($db->next_result());
    } else {
        echo "Error creating table: " . $db->error . "\n";
    }
    
    $db->close();
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>

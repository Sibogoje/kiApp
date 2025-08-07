<?php
require_once 'config.php';

try {
    $pdo = getDbConnection();
    echo "Database connection successful\n";
    
    // Check current foreign key constraints on documents table
    $stmt = $pdo->prepare("
        SELECT 
            CONSTRAINT_NAME,
            COLUMN_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'documents' 
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    $stmt->execute();
    $constraints = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Current foreign key constraints:\n";
    foreach ($constraints as $constraint) {
        echo "- {$constraint['CONSTRAINT_NAME']}: {$constraint['COLUMN_NAME']} -> {$constraint['REFERENCED_TABLE_NAME']}.{$constraint['REFERENCED_COLUMN_NAME']}\n";
    }
    
    // Make uploaded_by nullable to allow clients to upload their own documents
    echo "\nModifying uploaded_by column to allow NULL values...\n";
    $pdo->exec("ALTER TABLE documents MODIFY uploaded_by INT NULL");
    echo "Column modified successfully\n";
    
    // Let's also check if we need to create a proper foreign key for client_id
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'clients'");
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        echo "Clients table exists\n";
        
        // Check if client_id has a proper foreign key constraint
        $hasClientFK = false;
        foreach ($constraints as $constraint) {
            if ($constraint['COLUMN_NAME'] === 'client_id' && $constraint['REFERENCED_TABLE_NAME'] === 'clients') {
                $hasClientFK = true;
                break;
            }
        }
        
        if (!$hasClientFK) {
            echo "Adding foreign key constraint for client_id...\n";
            try {
                $pdo->exec("ALTER TABLE documents ADD CONSTRAINT fk_documents_client_id FOREIGN KEY (client_id) REFERENCES clients(id)");
                echo "Foreign key constraint added successfully\n";
            } catch (Exception $e) {
                echo "Note: Could not add foreign key constraint for client_id: " . $e->getMessage() . "\n";
            }
        } else {
            echo "Foreign key constraint for client_id already exists\n";
        }
    } else {
        echo "Clients table does not exist\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>

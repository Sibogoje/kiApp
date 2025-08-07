<?php
require_once 'config.php';

try {
    $pdo = getDbConnection();
    echo "Database connection successful\n";
    
    // Check if documents table exists
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'documents'");
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        echo "Documents table exists\n";
        
        // Get table structure
        $stmt = $pdo->prepare("DESCRIBE documents");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Table structure:\n";
        foreach ($columns as $column) {
            echo "- {$column['Field']}: {$column['Type']} " . 
                 ($column['Null'] == 'YES' ? 'NULL' : 'NOT NULL') . 
                 ($column['Default'] ? " DEFAULT {$column['Default']}" : '') . "\n";
        }
        
        // Check if we have any documents
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM documents");
        $stmt->execute();
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "Total documents in table: {$count['count']}\n";
        
    } else {
        echo "Documents table does NOT exist\n";
        echo "Creating documents table...\n";
        
        $createTableSQL = "
        CREATE TABLE documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            document_name VARCHAR(255) NOT NULL,
            document_type VARCHAR(50) NOT NULL DEFAULT 'other',
            file_path VARCHAR(500) NOT NULL,
            file_size INT NOT NULL DEFAULT 0,
            description TEXT NULL,
            uploaded_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_client_id (client_id),
            INDEX idx_document_type (document_type),
            INDEX idx_created_at (created_at)
        )";
        
        $pdo->exec($createTableSQL);
        echo "Documents table created successfully\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
?>

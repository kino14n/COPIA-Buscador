<?php
return function (PDO $db): void {
    $stmt = $db->query("SHOW INDEX FROM codes WHERE Key_name = 'idx_document_code'");
    if ($stmt->fetch()) {
        return;
    }
    $db->exec('ALTER TABLE codes ADD INDEX idx_document_code (document_id, code)');
};

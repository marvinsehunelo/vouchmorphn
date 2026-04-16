<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

declare(strict_types=1);

namespace BUSINESS_LOGIC_LAYER\services;

use PDO;

class UserService {
    private PDO $db;
    
    public function __construct(PDO $db) { 
        $this->db = $db; 
    }

    public function getUserById(int $id) { 
        $stmt = $this->db->prepare("SELECT * FROM users WHERE user_id=? LIMIT 1");
        $stmt->execute([$id]); 
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateUser(int $id, array $data) {
        // minimal updater - expand validations
        $fields = []; 
        $params = [];
        foreach($data as $k => $v) { 
            $fields[] = "$k=?"; 
            $params[] = $v; 
        }
        $params[] = $id;
        $sql = "UPDATE users SET " . implode(',', $fields) . " WHERE user_id=?";
        $stmt = $this->db->prepare($sql); 
        $stmt->execute($params);
        return ['success' => true];
    }
}

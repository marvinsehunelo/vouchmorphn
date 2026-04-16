<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// 4. DATA_PERSISTENCE_LAYER/models/User.php

class User
{
    public int $id;
    public string $phone;
    public string $name;
    public string $email;
    public string $password_hash;
    public int $role_id;
    public string $created_at;
    public string $updated_at;

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function findByPhone(string $phone): ?array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE phone=?");
        $stmt->execute([$phone]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare(
            "INSERT INTO users (phone, name, email, password_hash, role_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->execute([
            $data['phone'],
            $data['name'],
            $data['email'],
            $data['password_hash'],
            $data['role_id']
        ]);
        return (int)$this->db->lastInsertId();
    }
}

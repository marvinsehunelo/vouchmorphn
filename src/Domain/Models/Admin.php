<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

class Admin
{
    public int $id;
    public string $name;
    public string $email;
    public string $password_hash;
    public string $created_at;

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function findByEmail(string $email): ?array {
        $stmt = $this->db->prepare("SELECT * FROM admins WHERE email=?");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

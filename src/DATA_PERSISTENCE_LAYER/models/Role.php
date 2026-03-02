<?php
class Role
{
    public int $id;
    public string $name;

    private PDO $db;

    public function __construct(PDO $db) { $this->db = $db; }
    public function getAll(): array {
        $stmt = $this->db->query("SELECT * FROM roles");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

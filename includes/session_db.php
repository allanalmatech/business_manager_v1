<?php
// includes/session_db.php
declare(strict_types=1);

final class DbSessionHandler implements SessionHandlerInterface
{
    private mysqli $db;
    private int $ttl;

    public function __construct(mysqli $db, int $ttlSeconds = 7200)
    {
        $this->db  = $db;
        $this->ttl = $ttlSeconds;
    }

    public function open(string $savePath, string $sessionName): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string|false
    {
        $now = time();
        $min = $now - $this->ttl;

        $stmt = $this->db->prepare("SELECT data FROM sessions WHERE id=? AND last_activity >= ? LIMIT 1");
        $stmt->bind_param("si", $id, $min);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$res) return '';
        return (string)$res['data'];
    }

    public function write(string $id, string $data): bool
    {
        $now = time();
        $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        $userId = $_SESSION['user']['id'] ?? null;

        $stmt = $this->db->prepare("
            INSERT INTO sessions (id, user_id, data, ip_address, user_agent, last_activity)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              user_id=VALUES(user_id),
              data=VALUES(data),
              ip_address=VALUES(ip_address),
              user_agent=VALUES(user_agent),
              last_activity=VALUES(last_activity)
        ");
        // bind_param needs explicit types. user_id can be null => use i but pass null ok with mysqlnd.
        $stmt->bind_param("sisssi", $id, $userId, $data, $ip, $ua, $now);
        $ok = $stmt->execute();
        $stmt->close();

        return (bool)$ok;
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM sessions WHERE id=?");
        $stmt->bind_param("s", $id);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }

    public function gc(int $max_lifetime): int|false
    {
        $now = time();
        $min = $now - $this->ttl;

        $stmt = $this->db->prepare("DELETE FROM sessions WHERE last_activity < ?");
        $stmt->bind_param("i", $min);
        $stmt->execute();
        $count = $stmt->affected_rows;
        $stmt->close();
        return $count;
    }
}

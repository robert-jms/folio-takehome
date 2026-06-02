<?php

date_default_timezone_set('America/Chicago');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $path = __DIR__ . '/../db.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function current_staff(): array {
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = 1');
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('No staff row #1 found. Did you run `php seed.php`?');
    }
    return $row;
}

function audit_log(string $action, string $entity_type, int $entity_id, array $details = []): void {
    $staff = current_staff();
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $staff['id'],
        $action,
        $entity_type,
        $entity_id,
        json_encode($details),
    ]);
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function format_publish_at(string $val): string {
    // Render publish timestamps as a human-friendly month/day/year time.
    return date('M j, Y g:i A', strtotime($val));
}

/**
 * Generate a unique, human-readable document identifier.
 *
 * Format: <title-slug>-<5-char-base-36-suffix>
 * Slug rules: lowercase; non-alnum → '-'; collapse consecutive '-';
 *             trim leading/trailing '-'; truncate to 60 chars; trim trailing '-'.
 * Suffix:    5 chars from '0-9a-z' via random_bytes entropy.
 * Uniqueness: SELECT-then-compare loop; up to 10 attempts.
 *
 * @throws RuntimeException if slug is empty (no alphanumeric characters in title)
 * @throws RuntimeException if 10 attempts all collide
 */
function generate_human_id(string $title): string {
    $slug = strtolower($title);
    // Replace any run of non-alphanumeric characters with a single hyphen.
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug = substr($slug, 0, 60);
    $slug = rtrim($slug, '-');
    if ($slug === '') {
        throw new RuntimeException(
            'Document title must contain at least one alphanumeric character.'
        );
    }

    $alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $bytes = random_bytes(5);
        // Base-36 characters used to turn random bytes into a short suffix.
        $suffix = '';
        for ($i = 0; $i < 5; $i++) {
            // Map each random byte to one of the 36 allowed suffix characters
            // and append to the suffix string.
            $suffix .= $alphabet[ord($bytes[$i]) % 36];
        }
        $candidate = "{$slug}-{$suffix}";

        // check candidate uniqueness against the db
        $stmt = db()->prepare('SELECT COUNT(*) FROM documents WHERE human_id = ?');
        $stmt->execute([$candidate]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $candidate;
        }
    }

    throw new RuntimeException(
        'Failed to generate a unique human_id after 10 attempts.'
    );
}

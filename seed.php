<?php

require __DIR__ . '/lib/bootstrap.php';

$dbPath = __DIR__ . '/db.sqlite';
if (file_exists($dbPath)) {
    unlink($dbPath);
}

$pdo = db();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));

// Apply migrations in filename order so the seeded database includes the publish_at column.
$migrationDir = __DIR__ . '/db/migrations';
if (is_dir($migrationDir)) {
    $migrationFiles = glob($migrationDir . '/*.sql');
    sort($migrationFiles);
    foreach ($migrationFiles as $migFile) {
        $pdo->exec(file_get_contents($migFile));
    }
}

$pdo->exec("
    INSERT INTO staff (email, name) VALUES
        ('freddy@folio.example', 'Freddy Folio')
");

$title = 'Welcome Packet';
$humanId = generate_human_id($title);
$stmt = $pdo->prepare('
    INSERT INTO documents (title, body, created_by, human_id)
    VALUES (?, ?, 1, ?)
');
$stmt->execute([
    $title,
    "Welcome to Folio!\n\nThis is the body of your welcome packet.",
    $humanId,
]);
$docId = (int) $pdo->lastInsertId();

$token = random_token();
$stmt = $pdo->prepare('
    INSERT INTO shares (document_id, token, recipient_email)
    VALUES (?, ?, ?)
');
$stmt->execute([$docId, $token, 'recipient@example.com']);

echo "Seeded db.sqlite.\n";
echo "Admin:        http://localhost:8000/admin.php\n";
echo "Sample share: http://localhost:8000/view.php?token={$token}\n";

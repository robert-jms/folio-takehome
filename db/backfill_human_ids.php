<?php
require __DIR__ . '/../lib/bootstrap.php';

$rows = db()->query('SELECT id, title FROM documents WHERE human_id IS NULL')->fetchAll();
$updated = 0;
$skipped = 0;
foreach ($rows as $row) {
    try {
        $humanId = generate_human_id($row['title']);
        db()->prepare('UPDATE documents SET human_id = ? WHERE id = ?')
            ->execute([$humanId, $row['id']]);
        $updated++;
    } catch (RuntimeException $e) {
        fwrite(STDERR, "Skipped document #{$row['id']} ({$row['title']}): {$e->getMessage()}\n");
        $skipped++;
    }
}
echo "Backfill complete. Updated: {$updated}, Skipped: {$skipped}\n";

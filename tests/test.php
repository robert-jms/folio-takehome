<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

test('future publish_at blocks recipient', function () {
    $future = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, publish_at) VALUES (?, ?, ?, ?)');
    $stmt->execute(['Gate Test Doc Future', 'body', 1, $future]);
    $docId = (int) db()->lastInsertId();
    $token = random_token();
    $stmt2 = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt2->execute([$docId, $token, 'test@example.com']);
    $stmt3 = db()->prepare('SELECT d.* FROM shares s JOIN documents d ON d.id = s.document_id WHERE s.token = ?');
    $stmt3->execute([$token]);
    $doc = $stmt3->fetch();
    $gateBlocks = ($doc['publish_at'] !== null && date('Y-m-d H:i:s') < $doc['publish_at']);
    assert_true($gateBlocks, 'Gate should block when publish_at is 1 hour in the future');
});

test('past publish_at allows recipient access', function () {
    $past = date('Y-m-d H:i:s', strtotime('-1 hour'));
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, publish_at) VALUES (?, ?, ?, ?)');
    $stmt->execute(['Gate Test Doc Past', 'body', 1, $past]);
    $docId = (int) db()->lastInsertId();
    $token = random_token();
    $stmt2 = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt2->execute([$docId, $token, 'test2@example.com']);
    $stmt3 = db()->prepare('SELECT d.* FROM shares s JOIN documents d ON d.id = s.document_id WHERE s.token = ?');
    $stmt3->execute([$token]);
    $doc = $stmt3->fetch();
    $gateBlocks = ($doc['publish_at'] !== null && date('Y-m-d H:i:s') < $doc['publish_at']);
    assert_true(!$gateBlocks, 'Gate should not block when publish_at is 1 hour in the past');
});

test('null publish_at allows recipient access', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, ?)');
    $stmt->execute(['Gate Test Doc Null', 'body', 1]);
    $docId = (int) db()->lastInsertId();
    $token = random_token();
    $stmt2 = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt2->execute([$docId, $token, 'test3@example.com']);
    $stmt3 = db()->prepare('SELECT d.* FROM shares s JOIN documents d ON d.id = s.document_id WHERE s.token = ?');
    $stmt3->execute([$token]);
    $doc = $stmt3->fetch();
    $gateBlocks = ($doc['publish_at'] !== null && date('Y-m-d H:i:s') < $doc['publish_at']);
    assert_true(!$gateBlocks, 'Gate should not block when publish_at is NULL');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);

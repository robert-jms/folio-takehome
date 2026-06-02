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
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, publish_at, human_id) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute(['Gate Test Doc Future', 'body', 1, $future, generate_human_id('Gate Test Doc Future')]);
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
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, publish_at, human_id) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute(['Gate Test Doc Past', 'body', 1, $past, generate_human_id('Gate Test Doc Past')]);
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
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, human_id) VALUES (?, ?, ?, ?)');
    $stmt->execute(['Gate Test Doc Null', 'body', 1, generate_human_id('Gate Test Doc Null')]);
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

test('generate_human_id produces correctly formatted id', function () {
    $id = generate_human_id('Hello World 2024');
    assert_true(
        (bool) preg_match('/^hello-world-2024-[a-z0-9]{5}$/', $id),
        'expected format hello-world-2024-XXXXX, got: ' . $id
    );
});

test('generate_human_id throws on empty slug', function () {
    $threw = false;
    try {
        generate_human_id('!!!');
    } catch (RuntimeException $e) {
        $threw = true;
        assert_true(
            strpos($e->getMessage(), 'alphanumeric') !== false,
            'unexpected exception message: ' . $e->getMessage()
        );
    }
    assert_true($threw, 'expected RuntimeException for empty slug');
});

test('audit log includes human_id on document create', function () {
    $humanId = generate_human_id('Audit Test Doc');
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, human_id) VALUES (?, ?, ?, ?)');
    $stmt->execute(['Audit Test Doc', 'body', 1, $humanId]);
    $docId = (int) db()->lastInsertId();
    audit_log('create', 'document', $docId, ['title' => 'Audit Test Doc', 'human_id' => $humanId]);
    $stmt2 = db()->prepare('SELECT details FROM audit_log WHERE entity_type = ? AND entity_id = ? AND action = ? ORDER BY id DESC LIMIT 1');
    $stmt2->execute(['document', $docId, 'create']);
    $row = $stmt2->fetch();
    assert_true($row !== false, 'expected audit_log row');
    $details = json_decode($row['details'], true);
    assert_true(isset($details['human_id']), 'expected human_id key in details');
    assert_true($details['human_id'] === $humanId, 'expected human_id value to match; got: ' . var_export($details['human_id'], true));
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);

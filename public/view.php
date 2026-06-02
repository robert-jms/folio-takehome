<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$hid   = $_GET['hid']   ?? '';
$token = $_GET['token'] ?? '';

// hid path 
if ($hid !== '') {
    $stmt = db()->prepare('SELECT * FROM documents WHERE human_id = ?');
    $stmt->execute([$hid]);
    $doc = $stmt->fetch();

    if (!$doc) {
        http_response_code(404);
        render_header('Not found');
        ?>
        <div class="centered-message">
            <h1>Share link not found</h1>
            <p>The link you used is invalid or has been removed.</p>
        </div>
        <?php
        render_footer();
        exit;
    }

    if ($doc['publish_at'] !== null && gmdate('Y-m-d H:i:s') < $doc['publish_at']) {
        render_not_yet_available($doc);
        exit;
    }

    render_header($doc['title']);
    ?>
    <h1 class="page-title"><?= h($doc['title']) ?></h1>
    <pre class="doc-body"><?= h($doc['body']) ?></pre>
    <?php
    render_footer();
    exit;

    // token path 
} elseif ($token !== '') {
    $stmt = db()->prepare('
        SELECT d.*, s.recipient_email
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        WHERE s.token = ?
    ');
    $stmt->execute([$token]);
    $doc = $stmt->fetch();

    if (!$doc) {
        http_response_code(404);
        render_header('Not found');
        ?>
        <div class="centered-message">
            <h1>Share link not found</h1>
            <p>The link you used is invalid or has been removed.</p>
        </div>
        <?php
        render_footer();
        exit;
    }

    if ($doc['publish_at'] !== null && gmdate('Y-m-d H:i:s') < $doc['publish_at']) {
        render_not_yet_available($doc);
        exit;
    }

    render_header($doc['title']);
    ?>

    <h1 class="page-title"><?= h($doc['title']) ?></h1>
    <p class="meta">Shared with <?= h($doc['recipient_email']) ?></p>

    <pre class="doc-body"><?= h($doc['body']) ?></pre>

    <?php
    render_footer();
    exit;

} else {
    http_response_code(404);
    render_header('Not found');
    ?>
    <div class="centered-message">
        <h1>Share link not found</h1>
        <p>The link you used is invalid or has been removed.</p>
    </div>
    <?php
    render_footer();
    exit;
}


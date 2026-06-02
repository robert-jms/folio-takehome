<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$token = $_GET['token'] ?? '';

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

if ($doc['publish_at'] !== null && date('Y-m-d H:i:s') < $doc['publish_at']) {
    render_header('This document is not yet available.');
    echo '<h1>' . h('This document is not yet available.') . '</h1>';
    echo '<p>Available on <span data-utc="' . h($doc['publish_at']) . '">' . h(format_publish_at($doc['publish_at'])) . '</span></p>';
    echo '<script>
(function() {
    var el = document.querySelector("[data-utc]");
    if (!el) return;
    var d = new Date(el.dataset.utc.replace(" ", "T") + "Z");
    // getTimezoneOffset() returns minutes *west* of UTC (e.g. UTC-5 → 300).
    // Negate it to get the conventional signed offset (e.g. -300 → display as UTC-5).
    var offsetTotalMin = -d.getTimezoneOffset();
    var sign = offsetTotalMin >= 0 ? "+" : "-";
    var absTotal = Math.abs(offsetTotalMin);
    var h = Math.floor(absTotal / 60);
    var m = absTotal % 60;
    var utcLabel = "UTC" + sign + h + (m ? ":" + String(m).padStart(2, "0") : "");
    var formatted = d.toLocaleString("en-US", {
        month: "short", day: "numeric", year: "numeric",
        hour: "numeric", minute: "2-digit", hour12: true
    });
    el.textContent = formatted + " (local time [" + utcLabel + "])";
})();
</script>';
    render_footer();
    exit;
}

render_header($doc['title']);
?>

<h1 class="page-title"><?= h($doc['title']) ?></h1>
<p class="meta">Shared with <?= h($doc['recipient_email']) ?></p>

<pre class="doc-body"><?= h($doc['body']) ?></pre>

<?php render_footer(); ?>

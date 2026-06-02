<?php

// Renders the HTML header, nav bar, and opening <main> tag.
// $containerClass controls the CSS class on <main>, allowing different pages to use different layout widths.
function render_header(string $title, ?array $staff = null, string $containerClass = 'container'): void {
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> · Folio</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav class="nav">
    <div class="nav-inner">
        <a href="/admin.php" class="brand">
            <span class="brand-mark">F</span>
            Folio
        </a>
        <?php if ($staff): ?>
            <span class="nav-user"><strong><?= h($staff['name']) ?></strong> · <?= h($staff['email']) ?></span>
        <?php endif ?>
    </div>
</nav>
<main class="<?= h($containerClass) ?>">
    <?php
}

function render_not_yet_available(array $doc): void {
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
}

function render_footer(): void {
    ?>
</main>
</body>
</html>
    <?php
}

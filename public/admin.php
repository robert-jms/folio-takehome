<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

/**
 * The publish_at column stores an optional UTC timestamp (YYYY-MM-DD HH:MM:SS)
 * indicating when a document should become visible to readers. When null the
 * document is available immediately. The admin UI collects local datetimes
 * (datetime-local) and sends the browser's tz_offset so the server converts
 * the supplied local time into UTC before saving. Validation ensures scheduled
 * publish times are in the future.
 */
function parse_and_normalize_publish_at(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    // Convert the datetime-local value into a SQL timestamp string.
    return str_replace('T', ' ', $raw) . ':00';
}

function validate_publish_at_input(?string $normalized): ?string {
    if ($normalized === null) {
        return null;
    }
    if ($normalized < date('Y-m-d H:i:s')) {
        
        return 'Publish time must be in the future.';
    }
    return null;
}

$staff = current_staff();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Browser supplies its UTC offset (minutes west) so we can convert local time to UTC.
    $tzOffset = (int) ($_POST['tz_offset'] ?? 0);
    // doc_id is present when updating an existing document's publish time (inline row form),
    // as opposed to the new-document form which has no doc_id.
    if (isset($_POST['doc_id'])) {
        $docId = (int) $_POST['doc_id'];

        $stmt = db()->prepare('SELECT publish_at FROM documents WHERE id = ?');
        $stmt->execute([$docId]);
        $existingDoc = $stmt->fetch();

        if (!$existingDoc) {
            $error = 'Document not found.';
        } else {
            $old = $existingDoc['publish_at'];

            $publishAtRaw   = $_POST['publish_at'] ?? '';
            $publishAtNorm  = parse_and_normalize_publish_at($publishAtRaw);
            // Shift local time to UTC using the browser-supplied offset.
            if ($publishAtNorm !== null && $tzOffset !== 0) {
                $dt = new DateTime($publishAtNorm);
                $dt->modify("+{$tzOffset} minutes");
                $publishAtNorm = $dt->format('Y-m-d H:i:s');
            }
            $publishAtError = validate_publish_at_input($publishAtNorm);

            if ($publishAtError !== null) {
                $error = $publishAtError;
            } else {
                $new = $publishAtNorm;

                db()->prepare('UPDATE documents SET publish_at = ? WHERE id = ?')
                    ->execute([$new, $docId]);

                audit_log('schedule_document', 'document', $docId, [
                    'publish_at_old' => $old,
                    'publish_at_new' => $new,
                ]);

                // Post/Redirect/Get pattern: 
                // redirect after POST to prevent form re-submission on refresh.
                // ?publish_updated=1 triggers the success banner on the next GET.
                header('Location: /admin.php?publish_updated=1');
                exit;
            }
        }
    } else {
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');

        $publishAtRaw   = $_POST['publish_at'] ?? '';
        $publishAtNorm  = parse_and_normalize_publish_at($publishAtRaw);
        // Shift local time to UTC using the browser-supplied offset.
        if ($publishAtNorm !== null && $tzOffset !== 0) {
            $dt = new DateTime($publishAtNorm);
            $dt->modify("+{$tzOffset} minutes");
            $publishAtNorm = $dt->format('Y-m-d H:i:s');
        }
        $publishAtError = validate_publish_at_input($publishAtNorm);

        if ($title === '' || $body === '') {
            $error = 'Title and body are required.';
        }

        if ($publishAtError !== null) {
            $error = $publishAtError;
        }

        if ($error === null) {
            try {
                $humanId = generate_human_id($title);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        if ($error === null) {
            $stmt = db()->prepare('
                INSERT INTO documents (title, body, created_by, publish_at, human_id)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([$title, $body, $staff['id'], $publishAtNorm, $humanId]);
            $docId = (int) db()->lastInsertId();

            // create key/value audit log details for the created document.
            $details = ['title' => $title, 'human_id' => $humanId];
            if ($publishAtNorm !== null) {
                $details['publish_at'] = $publishAtNorm;
            }
            audit_log('create', 'document', $docId, $details);

            header('Location: /admin.php?created=' . $docId);
            exit;
        }
    }
}

$docs = db()->query('
    SELECT d.*, s.name AS creator_name
    FROM documents d
    JOIN staff s ON s.id = d.created_by
    ORDER BY d.created_at DESC
')->fetchAll();

render_header('Admin', $staff, 'container container-wide');
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document #<?= (int) $_GET['created'] ?> created.</div>
<?php endif ?>

<?php if (!empty($_GET['publish_updated'])): ?>
    <div class="banner banner-success">Publish time updated.</div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required></textarea>
        </div>
        <div class="form-field">
            <label for="publish_at">Publish at (optional)</label>
            <!-- Keep the submitted publish time in the field after validation errors. -->
            <input type="datetime-local" id="publish_at" name="publish_at"
                   value="<?= h(isset($_POST['publish_at']) ? $_POST['publish_at'] : '') ?>">
        </div>
        <!-- Browser tz offset (minutes). Date.getTimezoneOffset() returns the minutes to add to local time to get UTC.
             The server adds this value to the submitted local datetime to store a UTC timestamp. -->
        <input type="hidden" name="tz_offset" value="0">
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Documents</h2>
    <?php if (empty($docs)): ?>
        <p class="empty">No documents yet.</p>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Human ID</th>
                    <th>Creator</th>
                    <th data-utc-header>Created</th>
                    <th></th>
                    <th data-utc-header>Publish at</th>
                    <th>Schedule Release at</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td class="id">#<?= (int) $d['id'] ?></td>
                        <td><?= h($d['title']) ?></td>
                        <td>
                            <span><?= h($d['human_id']) ?></span>
                            <button type="button" class="btn-copy"
                                    data-copy-url="http://<?= h($_SERVER['HTTP_HOST']) ?>/view.php?hid=<?= h($d['human_id']) ?>">
                                Copy link
                            </button>
                        </td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><span data-utc="<?= h($d['created_at']) ?>"><?= h($d['created_at']) ?></span></td>
                        <td><a href="/share.php?doc=<?= (int) $d['id'] ?>" class="btn-link">Create share →</a></td>
                        <td>
                            <?php if ($d['publish_at'] === null): ?>
                            <?php elseif (date('Y-m-d H:i:s') < $d['publish_at']): ?>
                                <span data-utc="<?= h($d['publish_at']) ?>"><?= h(format_publish_at($d['publish_at'])) ?></span> <span class="badge">Scheduled</span>
                            <?php else: ?>
                                <span data-utc="<?= h($d['publish_at']) ?>"><?= h(format_publish_at($d['publish_at'])) ?></span>
                            <?php endif ?>
                        </td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="doc_id" value="<?= h($d['id']) ?>">
                                <!-- Browser tz offset (minutes). Date.getTimezoneOffset() returns the minutes to add to local time to get UTC.
                                     The server adds this value to the submitted local datetime to store a UTC timestamp. -->
                                <input type="hidden" name="tz_offset" value="0">
                                <input type="datetime-local" name="publish_at"
                                       data-utc-val="<?= h($d['publish_at'] ?? '') ?>"
                                       value="<?= h($d['publish_at'] !== null
                                                       // substr(..., 0, 16) drops the trailing ':00' seconds,
                                                       // since datetime-local requires YYYY-MM-DDTHH:MM only.
                                                       ? substr(str_replace(' ', 'T', $d['publish_at']), 0, 16)
                                                       : '') ?>">
                                <button type="submit">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<script>
// Populate all tz_offset (hidden) fields with the browser's UTC offset (minutes west of UTC).
document.querySelectorAll('input[name="tz_offset"]').forEach(function(el) {
    el.value = new Date().getTimezoneOffset();
});

// Compute the UTC offset label once (e.g. "UTC-5") from the browser's current timezone.
(function() {
    var offsetTotalMin = -new Date().getTimezoneOffset();
    var sign = offsetTotalMin >= 0 ? '+' : '-';
    var absTotal = Math.abs(offsetTotalMin);
    var hh = Math.floor(absTotal / 60);
    var mm = absTotal % 60;
    var utcLabel = 'UTC' + sign + hh + (mm ? ':' + String(mm).padStart(2, '0') : '');

    // Append "(local time [UTC-X])" to column headers.
    document.querySelectorAll('[data-utc-header]').forEach(function(el) {
        el.textContent += ' (local time [' + utcLabel + '])';
    });

    // Format helper: outputs "YYYY-MM-DD HH:MM:SS" in local time.
    function fmtLocal(utcStr) {
        var d = new Date(utcStr.replace(' ', 'T') + 'Z');
        var pad = function(n) { return String(n).padStart(2, '0'); };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
             + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    }

    // Convert all [data-utc] display spans from UTC to local time.
    document.querySelectorAll('[data-utc]').forEach(function(el) {
        el.textContent = fmtLocal(el.dataset.utc);
    });

    // Convert datetime-local inputs: set value to local YYYY-MM-DDTHH:MM.
    document.querySelectorAll('input[type="datetime-local"][data-utc-val]').forEach(function(el) {
        var raw = el.dataset.utcVal;
        if (!raw) return;
        var d = new Date(raw.replace(' ', 'T') + 'Z');
        // pad ensures single-digit months/days/hours/minutes get a leading zero
        // so the value matches the required YYYY-MM-DDTHH:MM format (e.g. "06" not "6").
        var pad = function(n) { return String(n).padStart(2, '0'); };
        el.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                 + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    });
})();

document.querySelectorAll('.btn-copy').forEach(function(btn) {
    btn.addEventListener('click', function() {
        navigator.clipboard.writeText(btn.dataset.copyUrl);
    });
});
</script>
<?php render_footer(); ?>

<?php
/** @var \Stanford\RedcapRAG\RedcapRAG $module */

// Basic access control
if (!SUPER_USER && !USERID) {
    exit("Access denied");
}

// Selected namespace / project identifier
$projectIdentifier = trim($_POST['project_identifier'] ?? '');
$namespaces = $module->getPineconeNamespaces();
$action  = $_POST['action'] ?? null;
$results = null;
$rows    = [];
$message = null;

// Only run actions when a namespace is set
if ($projectIdentifier !== '') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Delete a single document
        if ($action === 'delete' && !empty($_POST['doc_id'])) {
            $module->deleteContextDocument($projectIdentifier, $_POST['doc_id']);
            $message = "Document deleted.";
        }

        // Purge entire namespace
        if ($action === 'purge' &&
            !empty($_POST['confirm']) &&
            $_POST['confirm'] === $projectIdentifier) {

            $module->purgeContextNamespace($projectIdentifier);

            // Redirect to preserve namespace in query string and clear POST
            $url = $_SERVER['PHP_SELF'] . '?project_identifier=' . urlencode($projectIdentifier);
            header("Location: {$url}");
            exit;
        }

        // Search
        if ($action === 'search' && !empty($_POST['query'])) {
            $topK = isset($_POST['top_k']) ? max(1, (int)$_POST['top_k']) : 20;
            $results = $module->debugSearchContext($projectIdentifier, $_POST['query'], $topK);
        }
    }

    // Refresh list of stored docs for current namespace
    $rows = $module->listContextDocuments($projectIdentifier);
}

/**
 * Simple blue-intensity heatmap style for numeric scores in [0, 1].
 *
 * @param mixed $score
 * @return string
 */
function ragHeatStyle($score): string
{
    if (!is_numeric($score)) {
        return '';
    }
    $v = max(0.0, min(1.0, (float)$score)); // clamp 0–1
    $alpha = 0.15 + 0.7 * $v;               // avoid fully transparent or fully opaque
    $bg = "rgba(13,110,253,{$alpha})";      // Bootstrap primary-ish blue
    $color = ($v >= 0.5) ? '#ffffff' : '#000000';

    return "background-color: {$bg}; color: {$color};";
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>REDCap RAG Debug Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 5 (CSS) -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous"
    >

    <style>
        body {
            padding: 20px;
        }
        .score-cell {
            text-align: right;
            white-space: nowrap;
        }
        .snippet-cell {
            max-width: 420px;
            white-space: nowrap;
            text-overflow: ellipsis;
            overflow: hidden;
        }
        .table thead th {
            cursor: pointer;
            user-select: none;
        }
        .table thead th.sortable::after {
            content: " ⇅";
            font-size: 0.75rem;
            opacity: 0.4;
        }
        .table thead th.sort-asc::after {
            content: " ↑";
            opacity: 0.8;
        }
        .table thead th.sort-desc::after {
            content: " ↓";
            opacity: 0.8;
        }
        .badge-namespace {
            font-size: 0.9rem;
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <h2 class="mb-3">REDCap RAG Debug Panel</h2>

    <!-- Namespace selector -->
    <div class="row mb-4">
        <!-- LEFT: Namespace selector -->
        <div class="col-md-6">
            <div class="card mb-4 h-100">
                <div class="card-body">
                    <form class="row g-3" method="post">
                        <input type="hidden" name="redcap_csrf_token" value="<?= $module->getCSRFToken() ?>">

                        <div class="col-md-6 col-lg-8">
                            <label for="project_identifier" class="form-label">
                                Namespace / Project Identifier
                            </label>

                            <select
                                class="form-select"
                                id="project_identifier"
                                name="project_identifier"
                            >
                                <option value="">Select a namespace…</option>

                                <?php foreach ($namespaces as $namespace): ?>
                                    <option
                                        value="<?= htmlspecialchars($namespace['name']) ?>"
                                        <?= $namespace['name'] === $projectIdentifier ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($namespace['name']) ?>
                                        (<?= (int) $namespace['record_count'] ?> records)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4 align-self-end">
                            <button type="submit" class="btn btn-primary">
                                Load Namespace
                            </button>
                        </div>
                    </form>

                    <?php if ($projectIdentifier !== ''): ?>
                        <div class="mt-3">
                        <span class="badge bg-secondary badge-namespace">
                            Current namespace: <?= htmlspecialchars($projectIdentifier) ?>
                        </span>
                        </div>
                    <?php else: ?>
                        <div class="mt-3 text-muted">
                            Enter a namespace to view stored documents and run test searches.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- RIGHT: Purge namespace -->
        <div class="col-md-6">
            <div class="card mb-4 border-danger h-100">
                <div class="card-header bg-danger text-white">
                    Purge Namespace
                </div>
                <div class="card-body">
                    <p class="mb-2">
                        This will permanently delete all context vectors for
                        <strong><?= htmlspecialchars($projectIdentifier) ?></strong>.
                    </p>
                    <p class="small text-muted">
                        Type the namespace exactly to confirm.
                    </p>

                    <form class="row g-3" method="post">
                        <input type="hidden" name="redcap_csrf_token" value="<?= $module->getCSRFToken() ?>">
                        <input type="hidden" name="action" value="purge">
                        <input type="hidden" name="project_identifier" value="<?= htmlspecialchars($projectIdentifier) ?>">

                        <div class="col-md-7">
                            <input
                                type="text"
                                class="form-control"
                                name="confirm"
                                placeholder="<?= htmlspecialchars($projectIdentifier) ?>"
                            >
                        </div>

                        <div class="col-md-5">
                            <button
                                type="submit"
                                class="btn btn-outline-danger w-100"
                                onclick="return confirm('Delete all vectors for this namespace?');"
                            >
                                Purge All
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($projectIdentifier !== ''): ?>

        <!-- Search card -->
        <div  class="card mb-4">
            <div class="card-header">
                Vector Search Test
            </div>
            <div class="card-body">
                <form id="searchForm" class="row g-3" method="post">
                    <input type="hidden" name="redcap_csrf_token" value="<?= $module->getCSRFToken() ?>">
                    <input type="hidden" name="action" value="search">
                    <input type="hidden" name="project_identifier" value="<?= htmlspecialchars($projectIdentifier) ?>">

                    <div class="col-md-7 col-lg-8">
                        <label class="form-label" for="query">Query text</label>
                        <input
                            type="text"
                            class="form-control"
                            id="query"
                            name="query"
                            value="<?= isset($_POST['query']) ? htmlspecialchars($_POST['query']) : '' ?>"
                            placeholder="Enter a test question or phrase"
                        >
                    </div>

                    <div class="col-md-2 col-lg-1">
                        <label class="form-label" for="top_k"
                               data-bs-toggle="tooltip"
                               data-bs-html="true"
                               title="Top K: The number of highest scoring results to return from the hybrid search.">
                            Top K
                        </label>
                        <input
                            type="number"
                            class="form-control"
                            id="top_k"
                            name="top_k"
                            min="1"
                            max="20"
                            value="<?= isset($_POST['top_k']) ? (int)$_POST['top_k'] : 5 ?>"
                        >
                    </div>

                    <div class="col-md-3 col-lg-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-success" id="runSearchBtn">
                            <span id="btnText">Run Search</span>
                            <span id="btnSpinner" class="spinner-border spinner-border-sm ms-2 d-none" role="status" aria-hidden="true"></span>
                        </button>
                    </div>
                </form>

                <?php if (is_array($results)): ?>
                    <hr>
                    <h5 class="mb-3" >
                        Search Results
                        <?php if (!empty($_POST['query'])): ?>
                            <small class="text-muted">
                                for "<?= htmlspecialchars($_POST['query']) ?>"
                            </small>
                        <?php endif; ?>
                    </h5>

                    <?php if (count($results)): ?>
                        <div class="table-responsive" style="max-height: calc(100vh - 600px); overflow-y: auto;">
                            <table
                                id="searchResultsTable"
                                class="table table-sm table-hover align-middle"
                                data-sortable-table="1"
                            >
                                <thead class="table-light">
                                <tr>
                                    <th scope="col" class="sortable" data-sort-key="id">ID</th>
                                    <th
                                        scope="col"
                                        class="sortable text-end"
                                        data-sort-key="dense"
                                        data-bs-toggle="tooltip"
                                        data-bs-placement="top"
                                        data-bs-html="true"
                                        title="
        <strong>Dense Score</strong><br>
        Measures semantic similarity using vector embeddings.<br>
        <em>Scale:</em> 0.0 – 1.0 (higher = more similar)
    "
                                    >
                                        Dense
                                    </th>

                                    <th
                                        scope="col"
                                        class="sortable text-end"
                                        data-sort-key="sparse"
                                        data-bs-toggle="tooltip"
                                        data-bs-placement="top"
                                        data-bs-html="true"
                                        title="
        <strong>Sparse Score</strong><br>
        Measures keyword overlap using term-based search (e.g. BM25).<br>
        <em>Scale:</em> ≥ 0 (higher = more keyword matches)
    "
                                    >
                                        Sparse
                                    </th>

                                    <th
                                        scope="col"
                                        class="sortable text-end"
                                        data-sort-key="similarity"
                                        data-bs-toggle="tooltip"
                                        data-bs-placement="top"
                                        data-bs-html="true"
                                        title="
        <strong>Hybrid Score</strong><br>
        Combined relevance score from dense + sparse search.<br>
        <em>Scale:</em> normalized (higher = more relevant), Current weights are 0.6 dense + 0.4 sparse
    "
                                    >
                                        Hybrid
                                    </th>
                                    <th scope="col">Content snippet</th>
                                    <th scope="col" style="width: 1%;"></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($results as $idx => $r): ?>
                                    <?php
                                    $dense = isset($r['dense']) ? (float)$r['dense'] : null;
                                    $sparse = isset($r['sparse']) ? (float)$r['sparse'] : null;
                                    $hybrid = isset($r['similarity']) ? (float)$r['similarity'] : null;
                                    $snippet = mb_substr($r['content'] ?? '', 0, 200);
                                    $detailId = 'search-detail-' . $idx;
                                    ?>
                                    <tr data-row-type="summary">
                                        <td
                                            data-sort-field="id"
                                            data-sort-value="<?= htmlspecialchars($r['id'] ?? '') ?>"
                                            class="text-monospace small"
                                        >
                                            <?= htmlspecialchars($r['id'] ?? '') ?>
                                        </td>
                                        <td
                                            class="score-cell"
                                            data-sort-field="dense"
                                            data-sort-value="<?= $dense !== null ? $dense : '' ?>"
                                            style="<?= ragHeatStyle($dense) ?>"
                                        >
                                            <?= $dense !== null ? number_format($dense, 4) : '-' ?>
                                        </td>
                                        <td
                                            class="score-cell"
                                            data-sort-field="sparse"
                                            data-sort-value="<?= $sparse !== null ? $sparse : '' ?>"
                                            style="<?= ragHeatStyle($sparse) ?>"
                                        >
                                            <?= $sparse !== null ? number_format($sparse, 4) : '-' ?>
                                        </td>
                                        <td
                                            class="score-cell fw-semibold"
                                            data-sort-field="similarity"
                                            data-sort-value="<?= $hybrid !== null ? $hybrid : '' ?>"
                                            style="<?= ragHeatStyle($hybrid) ?>"
                                        >
                                            <?= $hybrid !== null ? number_format($hybrid, 4) : '-' ?>
                                        </td>
                                        <td class="snippet-cell">
                                            <?= htmlspecialchars($snippet) ?>
                                        </td>
                                        <td class="text-end">
                                            <button
                                                class="btn btn-outline-secondary btn-sm"
                                                type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#<?= $detailId ?>"
                                                aria-expanded="false"
                                                aria-controls="<?= $detailId ?>"
                                            >
                                                Details
                                            </button>
                                        </td>
                                    </tr>
                                    <tr data-row-type="detail">
                                        <td colspan="6">
                                            <div class="collapse" id="<?= $detailId ?>">
                                                <div class="border rounded p-3 bg-light">
                                                    <div class="mb-2">
                                                        <strong>Source:</strong>
                                                        <?= htmlspecialchars($r['source'] ?? '') ?>
                                                    </div>
                                                    <?php if (!empty($r['meta_summary'])): ?>
                                                        <div class="mb-2">
                                                            <strong>Meta summary:</strong><br>
                                                            <span class="small">
                                                                <?= nl2br(htmlspecialchars($r['meta_summary'])) ?>
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($r['meta_tags'])): ?>
                                                        <div class="mb-2">
                                                            <strong>Meta tags:</strong>
                                                            <span class="badge bg-secondary">
                                                                <?= htmlspecialchars($r['meta_tags']) ?>
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($r['meta_timestamp'])): ?>
                                                        <div class="mb-2">
                                                            <strong>Timestamp:</strong>
                                                            <?= htmlspecialchars($r['meta_timestamp']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="mb-0">
                                                        <strong>Full content:</strong><br>
                                                        <pre class="small mb-0" style="white-space: pre-wrap;"><?= htmlspecialchars($r['content'] ?? '') ?></pre>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-secondary mt-3 mb-0">
                            No matches returned for this query.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Purge namespace card -->
<!--        <div class="card mb-4 border-danger">-->
<!--            <div class="card-header bg-danger text-white">-->
<!--                Purge Namespace-->
<!--            </div>-->
<!--            <div class="card-body">-->
<!--                <p class="mb-2">-->
<!--                    This will permanently delete all context vectors for-->
<!--                    <strong>--><?php //= htmlspecialchars($projectIdentifier) ?><!--</strong>.-->
<!--                </p>-->
<!--                <p class="small text-muted">-->
<!--                    Type the namespace exactly to confirm.-->
<!--                </p>-->
<!--                <form class="row g-3" method="post">-->
<!--                    <input type="hidden" name="redcap_csrf_token" value="--><?php //= $module->getCSRFToken() ?><!--">-->
<!--                    <input type="hidden" name="action" value="purge">-->
<!--                    <input type="hidden" name="project_identifier" value="--><?php //= htmlspecialchars($projectIdentifier) ?><!--">-->
<!---->
<!--                    <div class="col-md-4 col-lg-3">-->
<!--                        <input-->
<!--                            type="text"-->
<!--                            class="form-control"-->
<!--                            name="confirm"-->
<!--                            placeholder="--><?php //= htmlspecialchars($projectIdentifier) ?><!--"-->
<!--                        >-->
<!--                    </div>-->
<!--                    <div class="col-md-3">-->
<!--                        <button-->
<!--                            type="submit"-->
<!--                            class="btn btn-outline-danger"-->
<!--                            onclick="return confirm('Delete all vectors for this namespace?');"-->
<!--                        >-->
<!--                            Purge All-->
<!--                        </button>-->
<!--                    </div>-->
<!--                </form>-->
<!--            </div>-->
<!--        </div>-->

        <!-- Stored documents list -->
        <div class="card h-100 d-flex flex-column" style="height: calc(100vh - 600px) !important;">
            <div class="card-header">
                Stored Documents (<?= count($rows) ?>)
            </div>
            <div class="card-body overflow-auto">
                <?php if (count($rows)): ?>
                    <div class="table-responsive">
                        <table
                            id="docsTable"
                            class="table table-sm table-hover align-middle"
                            data-sortable-table="1"
                        >
                            <thead class="table-light">
                            <tr>
                                <th scope="col" class="sortable" data-sort-key="id">ID</th>
                                <th scope="col" class="sortable" data-sort-key="source">Source</th>
                                <th scope="col">Content snippet</th>
                                <th scope="col" style="width: 1%;"></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $idx => $r): ?>
                                <?php
                                $docId    = $r['id'] ?? '';
                                $snippet  = mb_substr($r['content'] ?? '', 0, 180);
                                $detailId = 'doc-detail-' . $idx;
                                ?>
                                <tr data-row-type="summary">
                                    <td
                                        class="text-monospace small"
                                        data-sort-field="id"
                                        data-sort-value="<?= htmlspecialchars($docId) ?>"
                                    >
                                        <?= htmlspecialchars($docId) ?>
                                    </td>
                                    <td
                                        data-sort-field="source"
                                        data-sort-value="<?= htmlspecialchars($r['source'] ?? '') ?>"
                                    >
                                        <?= htmlspecialchars($r['source'] ?? '') ?>
                                    </td>
                                    <td class="snippet-cell">
                                        <?= htmlspecialchars($snippet) ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button
                                                class="btn btn-outline-secondary"
                                                type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#<?= $detailId ?>"
                                                aria-expanded="false"
                                                aria-controls="<?= $detailId ?>"
                                            >
                                                Details
                                            </button>
                                            <form method="post" onsubmit="return confirm('Delete this document?');">
                                                <input type="hidden" name="redcap_csrf_token" value="<?= $module->getCSRFToken() ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="project_identifier" value="<?= htmlspecialchars($projectIdentifier) ?>">
                                                <input type="hidden" name="doc_id" value="<?= htmlspecialchars($docId) ?>">
                                                <button type="submit" class="btn btn-outline-danger">
                                                    Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <tr data-row-type="detail">
                                    <td colspan="4">
                                        <div class="collapse" id="<?= $detailId ?>">
                                            <div class="border rounded p-3 bg-light">
                                                <div class="mb-2">
                                                    <strong>Source:</strong>
                                                    <?= htmlspecialchars($r['source'] ?? '') ?>
                                                </div>
                                                <?php if (!empty($r['meta_summary'])): ?>
                                                    <div class="mb-2">
                                                        <strong>Meta summary:</strong><br>
                                                        <span class="small">
                                                            <?= nl2br(htmlspecialchars($r['meta_summary'])) ?>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($r['meta_tags'])): ?>
                                                    <div class="mb-2">
                                                        <strong>Meta tags:</strong>
                                                        <span class="badge bg-secondary">
                                                            <?= htmlspecialchars($r['meta_tags']) ?>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($r['meta_timestamp'])): ?>
                                                    <div class="mb-2">
                                                        <strong>Timestamp:</strong>
                                                        <?= htmlspecialchars($r['meta_timestamp']) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="mb-0">
                                                    <strong>Full content:</strong><br>
                                                    <pre class="small mb-0" style="white-space: pre-wrap;"><?= htmlspecialchars($r['content'] ?? '') ?></pre>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">
                        No stored context documents found for this namespace.
                    </p>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- Bootstrap 5 (JS bundle) -->
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous"
></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const tooltipTriggerList = [].slice.call(
            document.querySelectorAll('[data-bs-toggle="tooltip"]')
        );

        tooltipTriggerList.forEach(el => {
            new bootstrap.Tooltip(el);
        });

        const form = document.getElementById('searchForm');
        const runBtn = document.getElementById('runSearchBtn');
        const btnSpinner = document.getElementById('btnSpinner');
        const btnText = document.getElementById('btnText');

        if (!form || !runBtn || !btnSpinner || !btnText) {
            console.warn('Search spinner elements not found');
            return;
        }

        form.addEventListener('submit', function () {
            runBtn.disabled = true;
            btnSpinner.classList.remove('d-none');
            btnText.textContent = 'Running…';
        });
    });
</script>

<script>
// Simple client-side table sorting with support for summary/detail row pairs
(function () {
    function getCellValue(row, key) {
        var cell = row.querySelector('[data-sort-field="' + key + '"]');
        if (!cell) return '';
        var val = cell.getAttribute('data-sort-value');
        if (val === null) {
            val = cell.textContent || '';
        }
        return val.trim();
    }

    function isNumeric(val) {
        return val !== '' && !isNaN(val) && isFinite(val);
    }

    function attachSorting(table) {
        var headers = table.querySelectorAll('thead th[data-sort-key]');
        if (!headers.length) return;

        headers.forEach(function (th) {
            th.classList.add('sortable');
            th.addEventListener('click', function () {
                var key = th.getAttribute('data-sort-key');
                var tbody = table.querySelector('tbody');
                if (!tbody) return;

                // Determine new sort direction
                var currentDir = th.getAttribute('data-sort-dir') || 'none';
                var newDir = currentDir === 'asc' ? 'desc' : 'asc';
                th.setAttribute('data-sort-dir', newDir);

                // Reset indicators on siblings
                headers.forEach(function (h) {
                    if (h !== th) {
                        h.removeAttribute('data-sort-dir');
                        h.classList.remove('sort-asc', 'sort-desc');
                    }
                });
                th.classList.toggle('sort-asc', newDir === 'asc');
                th.classList.toggle('sort-desc', newDir === 'desc');

                // Collect summary rows and their detail companions (if any)
                var summaries = Array.prototype.slice.call(
                    tbody.querySelectorAll('tr[data-row-type="summary"]')
                );

                var pairs = summaries.map(function (summary) {
                    var detail = summary.nextElementSibling;
                    if (detail && detail.getAttribute('data-row-type') === 'detail') {
                        return { summary: summary, detail: detail };
                    }
                    return { summary: summary, detail: null };
                });

                pairs.sort(function (a, b) {
                    var av = getCellValue(a.summary, key);
                    var bv = getCellValue(b.summary, key);

                    var aNum = isNumeric(av) ? parseFloat(av) : null;
                    var bNum = isNumeric(bv) ? parseFloat(bv) : null;

                    var cmp;
                    if (aNum !== null && bNum !== null) {
                        cmp = aNum - bNum;
                    } else {
                        cmp = av.localeCompare(bv);
                    }

                    return newDir === 'asc' ? cmp : -cmp;
                });

                // Re-append in new order
                pairs.forEach(function (pair) {
                    tbody.appendChild(pair.summary);
                    if (pair.detail) {
                        tbody.appendChild(pair.detail);
                    }
                });
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var tables = document.querySelectorAll('table[data-sortable-table="1"]');
        tables.forEach(attachSorting);
    });
})();
</script>

</body>
</html>

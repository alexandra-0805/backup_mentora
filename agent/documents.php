<?php

/**
 * Documents (agents and HR): upload team resources (published "Training resources" appear on interns'
 * and employees' Training pages), open any document, verify applicants' application documents,
 * change status, or delete. Files are served by account/document.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/workflow.php';

if (mentora_is_post()) {
    $me = mentora_post_user(['agent', 'admin'], 'agent/documents.php');
    $action = (string) ($_POST['action'] ?? '');
    $statuses = ['published', 'draft', 'in_review'];
    if ($action === 'upload') {
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 190);
        $category = (string) ($_POST['category'] ?? '');
        $status = (string) ($_POST['status'] ?? '');
        if (!in_array($category, MENTORA_DOCUMENT_CATEGORIES, true) || $category === 'Application documents' || !in_array($status, $statuses, true)) {
            mentora_flash('Please choose a category and status.', 'error');
        } else {
            $stored = mentora_store_document('file');
            if (!$stored['ok']) {
                mentora_flash($stored['error'], 'error');
            } else {
                $pdo->prepare('INSERT INTO documents (name, owner_id, category, status, stored_name, original_name, mime, size) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$name !== '' ? $name : $stored['original_name'], $me['id'], $category, $status, $stored['stored_name'], $stored['original_name'], $stored['mime'], $stored['size']]);
                if ($status === 'published' && $category === 'Training resources') {
                    foreach (mentora_workspace_people($pdo, 'all') as $person) {
                        mentora_notify($pdo, (int) $person['id'], 'New training resource', ($name !== '' ? $name : $stored['original_name']) . ' is available on your Training page.', 'applicant/' . mentora_pathway_folder($person['pathway']) . '/training.php');
                    }
                }
                mentora_flash('“' . ($name !== '' ? $name : $stored['original_name']) . '” uploaded.');
            }
        }
    } elseif ($action === 'status') {
        $status = (string) ($_POST['status'] ?? '');
        $query = $pdo->prepare('SELECT id, name, owner_id, category FROM documents WHERE id = ?');
        $query->execute([(int) ($_POST['id'] ?? 0)]);
        $document = $query->fetch();
        if ($document && in_array($status, $statuses, true)) {
            $pdo->prepare('UPDATE documents SET status = ? WHERE id = ?')->execute([$status, $document['id']]);
            if ($document['category'] === 'Application documents' && $document['owner_id']) {
                $owner = mentora_person($pdo, (int) $document['owner_id']);
                if ($owner && $status !== 'in_review') {
                    mentora_notify($pdo, (int) $owner['id'], $status === 'published' ? 'Document verified: ' . $document['name'] : 'Document needs an update: ' . $document['name'], $status === 'published' ? 'Your document was checked by the Mentora team.' : 'Please upload a new version in your Profile.', 'applicant/' . mentora_pathway_folder($owner['pathway']) . '/profile.php#documents');
                }
            }
            mentora_flash('Status updated.');
        }
    } elseif ($action === 'delete') {
        $query = $pdo->prepare('SELECT id, stored_name FROM documents WHERE id = ?');
        $query->execute([(int) ($_POST['id'] ?? 0)]);
        $document = $query->fetch();
        if ($document) {
            $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$document['id']]);
            mentora_delete_document_file($document['stored_name']);
            mentora_flash('Document deleted.');
        }
    }
    mentora_redirect('agent/documents.php');
}

$pageTitle = 'Documents';
$activePage = 'documents.php';
require __DIR__ . '/../includes/agent_layout.php';

$documents = $pdo->query("SELECT d.*, CONCAT(u.first_name, ' ', u.last_name) AS owner_name, u.role AS owner_role FROM documents d LEFT JOIN users u ON u.id = d.owner_id ORDER BY d.updated_at DESC, d.id DESC")->fetchAll();
$countStatus = static fn(string $status): int => count(array_filter($documents, static fn($d) => $d['status'] === $status));
$statusLabel = ['published' => ['', 'Published'], 'draft' => ['warn', 'Draft'], 'in_review' => ['info', 'In review']];
?>
<div class="admin-heading"><div><h1>Documents</h1><p>Find, organize, and share the resources your team needs, and verify applicants’ documents.</p></div><div class="admin-actions"><a class="admin-btn primary" href="#upload">Upload document</a></div></div>
<?= mentora_flash_html() ?>
<div class="metric-grid"><div class="metric-card"><small>All documents</small><strong><?= count($documents) ?></strong><span>Across team folders</span></div><div class="metric-card"><small>Published</small><strong><?= $countStatus('published') ?></strong><span>Available to your team</span></div><div class="metric-card"><small>Drafts</small><strong><?= $countStatus('draft') ?></strong><span>Only visible to staff</span></div><div class="metric-card"><small>In review</small><strong><?= $countStatus('in_review') ?></strong><span>Awaiting approval</span></div></div>
<section class="admin-panel data-panel">
    <div class="table-toolbar" data-table-filter="document-table">
        <input type="search" data-filter="search" placeholder="Search by document name or owner" aria-label="Search documents">
        <select data-filter="category" aria-label="Category"><option value="all">All types</option><?php foreach (MENTORA_DOCUMENT_CATEGORIES as $category): ?><option value="<?= mentora_h(mb_strtolower($category)) ?>"><?= mentora_h($category) ?></option><?php endforeach; ?></select>
        <select data-filter="status" aria-label="Status"><option value="all">All statuses</option><option value="published">Published</option><option value="draft">Draft</option><option value="in_review">In review</option></select>
    </div>
    <table class="admin-table" id="document-table"><thead><tr><th>Document name</th><th>Category</th><th>Owner</th><th>Updated</th><th>Status</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($documents as $document): [$badgeClass, $badgeText] = $statusLabel[$document['status']] ?? ['', $document['status']]; ?>
            <tr data-category="<?= mentora_h(mb_strtolower($document['category'])) ?>" data-status="<?= $document['status'] ?>">
                <td><?= mentora_h($document['name']) ?><?= $document['stored_name'] ? '<br><small>' . mentora_h($document['original_name']) . ' · ' . mentora_format_bytes((int) $document['size']) . '</small>' : '<br><small>No file attached</small>' ?></td>
                <td><?= mentora_h($document['category']) ?></td>
                <td><?= mentora_h($document['owner_name'] ?? '—') ?></td>
                <td><?= date('d M Y', strtotime($document['updated_at'] ?? $document['created_at'])) ?></td>
                <td><span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span></td>
                <td class="row-actions">
                    <?php if ($document['stored_name']): ?><a class="admin-btn" href="../account/document.php?id=<?= (int) $document['id'] ?>" target="_blank" rel="noopener">Open</a><?php endif; ?>
                    <form method="post"><?= mentora_csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int) $document['id'] ?>">
                        <?php if ($document['status'] !== 'published'): ?><button class="admin-btn primary" type="submit" name="status" value="published" data-confirm-title="<?= $document['category'] === 'Application documents' ? 'Mark as verified?' : 'Publish this document?' ?>" data-confirm="<?= $document['category'] === 'Application documents' ? 'The applicant will be told their document was checked.' : 'Everyone in the workspace will be able to open it.' ?>"><?= $document['category'] === 'Application documents' ? 'Verify' : 'Publish' ?></button>
                        <?php else: ?><button class="admin-btn" type="submit" name="status" value="draft" data-confirm-title="<?= $document['category'] === 'Application documents' ? 'Ask for an update?' : 'Unpublish this document?' ?>" data-confirm="<?= $document['category'] === 'Application documents' ? 'The applicant will be asked to upload a new version.' : 'Only staff will be able to open it.' ?>"><?= $document['category'] === 'Application documents' ? 'Needs update' : 'Unpublish' ?></button><?php endif; ?>
                    </form>
                    <form method="post"><?= mentora_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $document['id'] ?>"><button class="admin-btn" type="submit" data-confirm-title="Delete this document?" data-confirm="“<?= mentora_h($document['name']) ?>” and its file will be removed for everyone." data-confirm-action="Delete">Delete</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody></table>
    <div class="table-note">Showing <span data-filter-count="document-table"><?= count($documents) ?></span> document(s).</div>
</section>
<section class="admin-panel" id="upload" style="margin-top:16px">
    <h2>Upload document</h2>
    <p class="panel-subtitle">PDF, Word, Excel, PowerPoint, text, CSV, JPG or PNG · up to 10 MB. Published training resources appear on interns’ and employees’ Training pages.</p>
    <form method="post" enctype="multipart/form-data" class="form-grid">
        <?= mentora_csrf_field() ?><input type="hidden" name="action" value="upload">
        <div class="field"><label for="doc-name">Document name</label><input id="doc-name" name="name" maxlength="190" placeholder="Uses the file name if empty"></div>
        <div class="field"><label for="doc-category">Category *</label><select id="doc-category" name="category" required><?php foreach (MENTORA_DOCUMENT_CATEGORIES as $category): if ($category === 'Application documents') { continue; } ?><option><?= mentora_h($category) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="doc-status">Status *</label><select id="doc-status" name="status" required><option value="published">Published (everyone)</option><option value="draft">Draft (staff only)</option><option value="in_review">In review (staff only)</option></select></div>
        <div class="field"><label for="doc-file">File *</label><input id="doc-file" type="file" name="file" required accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.jpg,.jpeg,.png"></div>
        <div class="field full"><button class="admin-btn primary" type="submit">Upload</button></div>
    </form>
</section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>

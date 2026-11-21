<?php

/**
 * Self-study training order and the certificate of completion.
 *
 *   - Modules are done in order: a module opens only when every module before it is done.
 *   - The certificate is a file the supervising agent (or HR) uploads for the person
 *     (documents, category "Certificates", owner = the person). The person can download it only
 *     after finishing every module (enforced in account/document.php too).
 *   - When the last module is finished, the agent is asked to upload the certificate (or, if it's
 *     already uploaded, the person is told it's ready), and an intern gets their AI assessment.
 */

declare(strict_types=1);

require_once __DIR__ . '/workflow.php';

const MENTORA_CERTIFICATE_CATEGORY = 'Certificates';

/** Module keys this person has finished. */
function mentora_done_modules(PDO $pdo, int $userId): array
{
    $query = $pdo->prepare('SELECT module_key FROM training_progress WHERE user_id = ?');
    $query->execute([$userId]);

    return $query->fetchAll(PDO::FETCH_COLUMN);
}

/** A module is open when every module before it is done (the first is always open). */
function mentora_module_unlocked(array $modules, array $doneKeys, string $key): bool
{
    foreach (array_keys($modules) as $moduleKey) {
        if ($moduleKey === $key) {
            return true;
        }
        if (!in_array($moduleKey, $doneKeys, true)) {
            return false;
        }
    }

    return false;
}

/** The module that has to be finished before $key can open, or null. */
function mentora_module_blocker(array $modules, array $doneKeys, string $key): ?string
{
    foreach (array_keys($modules) as $moduleKey) {
        if ($moduleKey === $key) {
            return null;
        }
        if (!in_array($moduleKey, $doneKeys, true)) {
            return $moduleKey;
        }
    }

    return null;
}

function mentora_training_complete(PDO $pdo, int $userId, string $pathway): bool
{
    $modules = array_keys(mentora_training_modules($pathway));

    return !array_diff($modules, mentora_done_modules($pdo, $userId));
}

/** The certificate file uploaded for this person (newest), or null. */
function mentora_training_certificate(PDO $pdo, int $userId): ?array
{
    $query = $pdo->prepare(
        "SELECT d.id, d.name, d.original_name, d.size, d.created_at, CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name
         FROM documents d LEFT JOIN users u ON u.id = d.uploaded_by
         WHERE d.owner_id = ? AND d.category = ? AND d.stored_name IS NOT NULL
         ORDER BY d.created_at DESC, d.id DESC LIMIT 1"
    );
    $query->execute([$userId, MENTORA_CERTIFICATE_CATEGORY]);

    return $query->fetch() ?: null;
}

/**
 * Upload (replacing any earlier one) or remove a person's certificate. For agent/edit_intern.php and
 * admin/user_edit.php. Returns [ok, message].
 */
function mentora_handle_certificate_post(PDO $pdo, array $staff, array $person, string $action): array
{
    $userId = (int) $person['id'];
    $old = $pdo->prepare('SELECT id, stored_name FROM documents WHERE owner_id = ? AND category = ?');
    $old->execute([$userId, MENTORA_CERTIFICATE_CATEGORY]);
    $previous = $old->fetchAll();

    if ($action === 'remove_certificate') {
        foreach ($previous as $document) {
            $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$document['id']]);
            mentora_delete_document_file($document['stored_name']);
        }
        return [true, 'Certificate removed.'];
    }

    $file = $_FILES['certificate'] ?? null;
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && !in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
        return [false, 'Upload the certificate as a PDF, JPG or PNG file.'];
    }
    $stored = mentora_store_document('certificate');
    if (!$stored['ok']) {
        return [false, $stored['error']];
    }
    $fullName = trim($person['first_name'] . ' ' . $person['last_name']);
    $pdo->prepare("INSERT INTO documents (name, owner_id, uploaded_by, category, status, stored_name, original_name, mime, size)
        VALUES (?, ?, ?, ?, 'published', ?, ?, ?, ?)")
        ->execute([
            'Certificate of completion · ' . $fullName,
            $userId,
            $staff['id'],
            MENTORA_CERTIFICATE_CATEGORY,
            $stored['stored_name'],
            $stored['original_name'],
            $stored['mime'],
            $stored['size']
        ]);
    foreach ($previous as $document) { // the new file replaces the old one
        $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$document['id']]);
        mentora_delete_document_file($document['stored_name']);
    }

    $complete = mentora_training_complete($pdo, $userId, $person['pathway']);
    if ($complete) {
        mentora_notify(
            $pdo,
            $userId,
            'Your certificate is ready',
            'Download your certificate of completion from your Training page.',
            'applicant/' . mentora_pathway_folder($person['pathway']) . '/training.php#certificate'
        );
    }

    return [
        true,
        'Certificate uploaded. ' . ($complete ? $person['first_name'] . ' can download it now.' : $person['first_name'] . ' can download it after finishing all modules.')
    ];
}

/**
 * Called when someone finishes a module: on the last one, ask for / announce the certificate, and for
 * an intern run the AI assessment and ranking (ai/intern_assessment.php), which hands them to their agent.
 */
function mentora_after_module_finished(PDO $pdo, array $person): void
{
    if (!mentora_training_complete($pdo, (int) $person['id'], $person['pathway'])) {
        return;
    }
    if (mentora_pathway_folder($person['pathway']) === 'intern') {
        require_once __DIR__ . '/../ai/intern_assessment.php';
        try {
            mentora_ai_assess_intern($pdo, (int) $person['id']);
        } catch (Throwable $exception) {
            error_log('Mentora AI assessment failed: ' . $exception->getMessage()); // never blocks the training step
        }
    }
    $name = trim($person['first_name'] . ' ' . $person['last_name']);
    if (mentora_training_certificate($pdo, (int) $person['id'])) {
        mentora_notify(
            $pdo,
            (int) $person['id'],
            'Your certificate is ready',
            'You finished all modules. Download your certificate from your Training page.',
            'applicant/' . mentora_pathway_folder($person['pathway']) . '/training.php#certificate'
        );
        return;
    }
    $isIntern = mentora_pathway_folder($person['pathway']) === 'intern';
    $title = 'Upload a certificate: ' . $name;
    $body = $name . ' finished all training modules and is waiting for their certificate of completion.';
    if ($isIntern && !empty($person['agent_id'])) {
        mentora_notify($pdo, (int) $person['agent_id'], $title, $body, 'agent/edit_intern.php?id=' . (int) $person['id'] . '#certificate');
    } elseif ($isIntern) {
        mentora_notify_roles($pdo, ['agent'], $title, $body, 'agent/edit_intern.php?id=' . (int) $person['id'] . '#certificate');
    } else {
        mentora_notify_roles($pdo, ['admin'], $title, $body, 'admin/user_edit.php?id=' . (int) $person['id'] . '#certificate');
    }
}

/** The upload / replace / remove panel for staff ($action = the page the form posts to). */
function mentora_render_certificate_panel(PDO $pdo, array $person, string $action, string $rootPrefix): void
{
    $modules = mentora_training_modules($person['pathway']);
    $doneCount = count(array_intersect(array_keys($modules), mentora_done_modules($pdo, (int) $person['id'])));
    $certificate = mentora_training_certificate($pdo, (int) $person['id']);
    $complete = $doneCount >= count($modules);
    ?>
    <section class="admin-panel" id="certificate" style="margin-top:16px">
        <h2>Certificate of completion <span class="badge <?= $certificate ? '' : ($complete ? 'warn' : 'info') ?>"><?= $certificate ? 'Uploaded' : ($complete ? 'Waiting for upload' : 'Not yet') ?></span>
        </h2>
        <p class="panel-subtitle"><?= mentora_h($person['first_name']) ?> has finished <?= $doneCount ?> of <?= count($modules) ?> self-study modules. <?= $complete
            ? 'They can download the certificate as soon as it’s uploaded.'
            : 'They can download the certificate after finishing all modules.' ?></p>
        <?php if ($certificate): ?>
            <div class="list-row">
                <span><?= mentora_h($certificate['original_name']) ?><br><small><?= mentora_format_bytes((int) $certificate['size']) ?> · uploaded <?= date('d M Y', strtotime($certificate['created_at'])) ?><?= $certificate['uploaded_by_name']
                    ? ' by ' . mentora_h($certificate['uploaded_by_name'])
                    : '' ?></small></span>
                <span class="row-actions"><a class="admin-btn"
                    href="<?= $rootPrefix ?>account/document.php?id=<?= (int) $certificate['id'] ?>"
                    target="_blank"
                    rel="noopener"><?= mentora_icon('view') ?>Open</a>
                    <form method="post" action="<?= mentora_h($action) ?>"><?= mentora_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $person['id'] ?>"><button class="admin-btn" type="submit" name="action" value="remove_certificate" data-confirm-title="Remove this certificate?" data-confirm="<?= mentora_h($person['first_name']) ?> won’t be able to download it until you upload a new one." data-confirm-tone="danger" data-confirm-action="Remove">Remove</button>
                    </form>
                    </span>
                    </div>
        <?php endif; ?>
        <form method="post" action="<?= mentora_h($action) ?>" enctype="multipart/form-data" class="table-toolbar">
            <?= mentora_csrf_field() ?><input type="hidden"
                name="id"
                value="<?= (int) $person['id'] ?>"><input type="hidden" name="action" value="upload_certificate">
            <input type="file" name="certificate" required accept=".pdf,.jpg,.jpeg,.png" aria-label="Certificate file">
            <button class="admin-btn primary"
                type="submit"
                data-confirm-title="<?= $certificate ? 'Replace the certificate?' : 'Upload this certificate?' ?>"
                data-confirm="<?= $complete
                    ? mentora_h($person['first_name']) . ' will be notified and can download it right away.'
                    : mentora_h($person['first_name']) . ' can download it after finishing all modules.' ?>"><?= mentora_icon('upload') ?><?= $certificate ? 'Replace certificate' : 'Upload certificate' ?></button>
        </form>
        <p class="panel-subtitle">PDF, JPG or PNG · up to 10 MB.</p>
    </section>
    <?php
}

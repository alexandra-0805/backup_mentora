<?php

/**
 * Applicant Profile (applicant/intern/profile.php, applicant/employee/profile.php; $pathway set there).
 *   - Contact and background details -> the person's applications row (also shown to agents / HR)
 *   - Application documents (résumé, endorsement...) -> documents (category "Application documents",
 *     "in_review"), visible to the owner, agents and HR (agent Documents)
 * Name, email and role are managed by HR (admin Users & access).
 */

declare(strict_types=1);

require_once __DIR__ . '/../workflow.php';

$folder = mentora_pathway_folder($pathway);
$self = 'applicant/' . $folder . '/profile.php';

if (mentora_is_post()) {
    $me = mentora_post_user(['applicant'], $self);
    $userId = (int) $me['id'];
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_details') {
        $field = static fn(string $name, int $max): string => mb_substr(trim((string) ($_POST[$name] ?? '')), 0, $max);
        $values = [
            'mobile' => $field('mobile', 40),
            'location' => $field('location', 190),
            'school' => $field('school', 190),
            'course' => $field('course', 190),
            'desired_role' => $field('desired_role', 190),
            'skills' => $field('skills', 2000),
        ];
        $hours = (int) ($_POST['required_hours'] ?? 0);
        $error = match (true) {
            $values['mobile'] !== '' && !preg_match('/^[0-9+()\s-]{7,20}$/', $values['mobile']) => 'Please enter a valid mobile number.',
            $folder === 'intern' && ($hours < 1 || $hours > 5000) => 'Required internship hours must be between 1 and 5000.',
            default => null,
        };
        if ($error !== null) {
            mentora_flash($error, 'error');
        } else {
            $nullable = array_map(static fn(string $v): ?string => $v !== '' ? $v : null, $values);
            $requiredHours = $folder === 'intern' ? $hours : null;
            $latest = $pdo->prepare('SELECT MAX(id) FROM applications WHERE user_id = ?');
            $latest->execute([$userId]);
            $applicationId = (int) $latest->fetchColumn();
            if ($applicationId > 0) {
                $pdo->prepare('UPDATE applications SET mobile = ?, location = ?, school = ?, course = ?, desired_role = ?, skills = ?, required_hours = COALESCE(?, required_hours) WHERE id = ?')
                    ->execute([...array_values($nullable), $requiredHours, $applicationId]);
            } else {
                $pdo->prepare('INSERT INTO applications (user_id, mobile, location, school, course, desired_role, skills, required_hours) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$userId, ...array_values($nullable), $requiredHours]);
            }
            mentora_flash('Your profile was updated.');
        }
    } elseif ($action === 'upload_document') {
        $name = mb_substr(trim((string) ($_POST['document_name'] ?? '')), 0, 190);
        $stored = mentora_store_document('document');
        if (!$stored['ok']) {
            mentora_flash($stored['error'], 'error');
        } else {
            $pdo->prepare("INSERT INTO documents (name, owner_id, category, status, stored_name, original_name, mime, size) VALUES (?, ?, 'Application documents', 'in_review', ?, ?, ?, ?)")
                ->execute([$name !== '' ? $name : $stored['original_name'], $userId, $stored['stored_name'], $stored['original_name'], $stored['mime'], $stored['size']]);
            mentora_notify_reviewers($pdo, mentora_person($pdo, $userId), 'New document: ' . $me['name'], ($name !== '' ? $name : $stored['original_name']) . ' was uploaded for review.');
            mentora_flash('Document uploaded. The Mentora team will review it.');
        }
    } elseif ($action === 'delete_document') {
        $query = $pdo->prepare("SELECT id, stored_name FROM documents WHERE id = ? AND owner_id = ? AND category = 'Application documents'");
        $query->execute([(int) ($_POST['document_id'] ?? 0), $userId]);
        $document = $query->fetch();
        if ($document) {
            $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$document['id']]);
            mentora_delete_document_file($document['stored_name']);
            mentora_flash('Document removed.');
        }
    }
    mentora_redirect($self);
}

$pageTitle = $folder === 'intern' ? 'Your intern profile' : 'Employee profile';
$activePage = 'profile.php';
require __DIR__ . '/../applicant_layout.php';

$userId = (int) $currentUser['id'];
$signedIn = $userId > 0;
$isIntern = $folder === 'intern';
$person = $signedIn ? mentora_person($pdo, $userId) : null;
$documents = [];
if ($signedIn) {
    $query = $pdo->prepare("SELECT id, name, original_name, size, status, created_at FROM documents WHERE owner_id = ? AND category = 'Application documents' ORDER BY created_at DESC");
    $query->execute([$userId]);
    $documents = $query->fetchAll();
}
$skills = array_values(array_filter(array_map('trim', preg_split('/[,\n;]+/', (string) ($person['skills'] ?? '')) ?: [])));
$value = static fn(string $key): string => mentora_h($person[$key] ?? '');
$docStatus = ['in_review' => ['info', 'In review'], 'published' => ['', 'Verified'], 'draft' => ['warn', 'Needs update']];
?>
<div class="admin-heading"><div><h1><?= $isIntern ? 'Your intern profile' : 'Employee profile' ?></h1><p><?= $isIntern ? 'Your personal information, application record and school requirements in one place.' : 'Manage your personal information, professional background, and employee documents.' ?></p></div><a class="admin-btn" href="#profile-details">Edit profile</a></div>
<?= mentora_flash_html() ?>
<?php if (!$signedIn): ?><div class="notice">You’re viewing a preview. <a href="../../auth/signin.php">Sign in</a> to edit your profile.</div><?php endif; ?>
<section class="pathway-card profile-hero"><?= mentora_avatar($avatarUrl, $initials, $fullName, 'avatar-xl') ?><div class="profile-hero-copy"><h2><?= mentora_h($fullName) ?></h2><p><?= mentora_h(($person['desired_role'] ?? '') !== '' ? $person['desired_role'] : ($isIntern ? 'Virtual Assistant Intern' : 'Employee VA')) ?> · <?= mentora_h($currentUser['email']) ?></p><span class="badge"><?= mentora_h($currentUser['user_code'] ?? '') ?: 'Profile' ?></span></div><a class="admin-btn" href="settings.php#profile"><?= $avatarUrl ? 'Change photo' : 'Add photo' ?></a></section>
<div class="admin-grid">
    <section class="pathway-card applicant-form" id="profile-details">
        <h3>Personal & contact details</h3>
        <form method="post">
            <?= mentora_csrf_field() ?><input type="hidden" name="action" value="save_details">
            <div class="form-grid">
                <div class="field"><label>Full name</label><input value="<?= mentora_h($fullName) ?>" disabled></div>
                <div class="field"><label>Email address</label><input value="<?= mentora_h($currentUser['email']) ?>" disabled></div>
                <div class="field"><label for="mobile">Mobile number</label><input id="mobile" name="mobile" inputmode="tel" maxlength="40" value="<?= $value('mobile') ?>" placeholder="e.g. 0917 123 4567"></div>
                <div class="field"><label for="location">Current city / province</label><input id="location" name="location" maxlength="190" value="<?= $value('location') ?>" placeholder="e.g. Quezon City, Metro Manila"></div>
                <?php if ($isIntern): ?>
                    <div class="field"><label for="school">School / university</label><input id="school" name="school" maxlength="190" value="<?= $value('school') ?>"></div>
                    <div class="field"><label for="course">Degree / program</label><input id="course" name="course" maxlength="190" value="<?= $value('course') ?>"></div>
                    <div class="field"><label for="required_hours">Required internship hours *</label><input id="required_hours" name="required_hours" type="number" min="1" max="5000" required value="<?= mentora_required_hours($person) ?>"></div>
                <?php endif; ?>
                <div class="field"><label for="desired_role"><?= $isIntern ? 'Preferred role' : 'Role / title' ?></label><input id="desired_role" name="desired_role" maxlength="190" value="<?= $value('desired_role') ?>"></div>
                <div class="field full"><label for="skills">Skills & tools</label><textarea id="skills" name="skills" rows="3" maxlength="2000" placeholder="Separate with commas, e.g. Google Workspace, Data entry, Canva"><?= $value('skills') ?></textarea></div>
            </div>
            <p class="panel-subtitle">Name and email changes are handled by HR.</p>
            <button class="admin-btn primary" type="submit"<?= $signedIn ? '' : ' disabled' ?> data-confirm-title="Save your profile?" data-confirm="Your details will be updated and visible to your agent and HR.">Save changes</button>
        </form>
    </section>
    <section class="pathway-card">
        <h3><?= $isIntern ? 'School & placement' : 'Availability & placement' ?></h3>
        <?php if ($isIntern): ?>
            <div class="notice-row"><span>School</span><strong><?= ($person['school'] ?? '') !== '' ? $value('school') : 'To be provided' ?></strong></div>
            <div class="notice-row"><span>Degree program</span><strong><?= ($person['course'] ?? '') !== '' ? $value('course') : 'To be provided' ?></strong></div>
            <div class="notice-row"><span>Required hours</span><strong><?= mentora_required_hours($person) ?> hours</strong></div>
        <?php else: ?>
            <div class="notice-row"><span>Work schedule</span><strong>Mon–Fri · 8:00 AM–5:00 PM PHT</strong></div>
        <?php endif; ?>
        <div class="notice-row"><span><?= $isIntern ? 'Team' : 'Department' ?></span><strong><?= mentora_h($person['department_name'] ?? '') ?: 'To be assigned by HR' ?></strong></div>
        <?php if ($isIntern): ?><div class="notice-row"><span>Supervising agent</span><strong><?= mentora_h($person['agent_name'] ?? '') ?: 'To be assigned' ?></strong></div><?php endif; ?>
        <div class="notice-row"><span>Application received</span><strong><?= !empty($person['applied_at']) ? date('d M Y', strtotime($person['applied_at'])) : '—' ?></strong></div>
        <h3>Skills & tools</h3>
        <?php if (!$skills): ?><p>Add your skills in the form to show them here.</p><?php endif; ?>
        <?php foreach ($skills as $skill): ?><span class="badge info"><?= mentora_h($skill) ?></span> <?php endforeach; ?>
    </section>
</div>
<section class="pathway-card data-panel" id="documents">
    <h3>Application documents</h3>
    <p>Upload your résumé, <?= $isIntern ? 'school endorsement, internship agreement' : 'IDs, signed offer' ?> and other requirements (PDF, Word, JPG or PNG · up to 10 MB).</p>
    <form method="post" enctype="multipart/form-data" class="table-toolbar">
        <?= mentora_csrf_field() ?><input type="hidden" name="action" value="upload_document">
        <input name="document_name" maxlength="190" placeholder="Document name, e.g. Résumé" aria-label="Document name">
        <input type="file" name="document" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt" aria-label="File">
        <button class="admin-btn primary" type="submit"<?= $signedIn ? '' : ' disabled' ?>>Upload</button>
    </form>
    <?php if ($documents): ?>
        <table class="admin-table"><thead><tr><th>Document</th><th>Size</th><th>Uploaded</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            <?php foreach ($documents as $document): [$badgeClass, $badgeText] = $docStatus[$document['status']] ?? ['', $document['status']]; ?>
                <tr><td><?= mentora_h($document['name']) ?><br><small><?= mentora_h($document['original_name']) ?></small></td><td><?= mentora_format_bytes((int) $document['size']) ?></td><td><?= date('d M Y', strtotime($document['created_at'])) ?></td><td><span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span></td>
                    <td class="row-actions"><a class="admin-btn" href="../../account/document.php?id=<?= (int) $document['id'] ?>" target="_blank" rel="noopener">Open</a>
                        <form method="post"><?= mentora_csrf_field() ?><input type="hidden" name="action" value="delete_document"><input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>"><button class="admin-btn" type="submit" data-confirm-title="Remove this document?" data-confirm="“<?= mentora_h($document['name']) ?>” will be deleted." data-confirm-action="Remove">Remove</button></form></td></tr>
            <?php endforeach; ?>
        </tbody></table>
    <?php else: ?>
        <p class="table-note">No documents uploaded yet.</p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../applicant_footer.php'; ?>

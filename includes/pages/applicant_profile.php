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
require_once __DIR__ . '/../social_profiles.php';

$folder = mentora_pathway_folder($pathway ?? 'intern'); // $pathway is set by applicant/{intern,employee}/profile.php
$self = 'applicant/' . $folder . '/profile.php';

if (mentora_is_post()) {
    $me = mentora_post_user(['applicant'], $self);
    $userId = (int) $me['id'];
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'set_presence') {
        $presence = (string) ($_POST['presence'] ?? '');
        if (in_array($presence, ['active', 'busy', 'offline'], true)) {
            $pdo->prepare('UPDATE users SET presence = ? WHERE id = ?')->execute([$presence, $userId]);
            mentora_flash('Your status is now ' . ucfirst($presence) . '.');
        }
        mentora_redirect($self);
    }

    if ($action === 'save_social') {
        // Social profile monitoring: Upwork, LinkedIn, Quora, Reddit, Instagram, Threads, Substack.
        $clean = [];
        $errors = [];
        foreach (array_keys(MENTORA_SOCIAL_PLATFORMS) as $column) {
            [$url, $error] = mentora_social_clean_url($column, (string) ($_POST[$column] ?? ''));
            $clean[$column] = $url;
            if ($error !== null) {
                $errors[] = $error;
            }
        }
        if ($errors) {
            mentora_flash(implode(' ', $errors), 'error');
            $_SESSION['social_draft'] = array_map(
                static fn($c) => mb_substr(trim((string) ($_POST[$c] ?? '')), 0, 255),
                array_combine(array_keys(MENTORA_SOCIAL_PLATFORMS), array_keys(MENTORA_SOCIAL_PLATFORMS))
            );
        } else {
            $columns = array_keys($clean);
            $pdo->prepare('INSERT INTO social_profiles (user_id, ' . implode(', ', $columns) . ') VALUES (?' . str_repeat(', ?', count($columns)) . ')
                ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn($c) => "$c = VALUES($c)", $columns)))
                ->execute([$userId, ...array_values($clean)]);
            [$added, $percent] = mentora_social_completion($clean);
            mentora_flash('Social profiles saved: ' . $added . ' of ' . count(MENTORA_SOCIAL_PLATFORMS) . ' added (' . $percent . '% complete).');
        }
        mentora_redirect($self . '#social-profiles');
    }

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
                $pdo->prepare('UPDATE applications
                    SET mobile = ?, location = ?, school = ?, course = ?, desired_role = ?, skills = ?, required_hours = COALESCE(?, required_hours)
                    WHERE id = ?')
                    ->execute([...array_values($nullable), $requiredHours, $applicationId]);
            } else {
                $pdo->prepare('INSERT INTO applications (user_id, mobile, location, school, course, desired_role, skills, required_hours)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$userId, ...array_values($nullable), $requiredHours]);
            }
            mentora_flash('Your profile was updated.');
        }
    } elseif ($action === 'add_link') {
        // A link instead of a file, e.g. a Google Drive portfolio. Agents and HR copy or open it.
        $name = mb_substr(trim((string) ($_POST['link_name'] ?? '')), 0, 190);
        [$url, $error] = mentora_clean_document_link((string) ($_POST['link_url'] ?? ''));
        if ($error !== null) {
            mentora_flash($error, 'error');
        } else {
            $name = $name !== '' ? $name : (mentora_is_drive_link($url) ? 'Portfolio (Google Drive)' : 'Portfolio link');
            $pdo->prepare("INSERT INTO documents (name, owner_id, category, status, original_name, link_url)
                VALUES (?, ?, 'Application documents', 'in_review', '', ?)")
                ->execute([$name, $userId, $url]);
            mentora_notify_reviewers($pdo, mentora_person($pdo, $userId), 'New portfolio link: ' . $me['name'], $name . ' was added for review.');
            mentora_flash('Link added. ' . (mentora_is_drive_link($url) ? 'Make sure it’s shared as “Anyone with the link can view” so your agent can open it.' : 'The Mentora team will review it.'));
        }
    } elseif ($action === 'upload_document') {
        $name = mb_substr(trim((string) ($_POST['document_name'] ?? '')), 0, 190);
        $stored = mentora_store_document('document');
        if (!$stored['ok']) {
            mentora_flash($stored['error'], 'error');
        } else {
            $pdo->prepare("INSERT INTO documents (name, owner_id, category, status, stored_name, original_name, mime, size)
                VALUES (?, ?, 'Application documents', 'in_review', ?, ?, ?, ?)")
                ->execute([
                    $name !== '' ? $name : $stored['original_name'],
                    $userId,
                    $stored['stored_name'],
                    $stored['original_name'],
                    $stored['mime'],
                    $stored['size']
                ]);
            mentora_notify_reviewers(
                $pdo,
                mentora_person($pdo, $userId),
                'New document: ' . $me['name'],
                ($name !== '' ? $name : $stored['original_name']) . ' was uploaded for review.'
            );
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
    $query = $pdo->prepare("SELECT id, name, original_name, size, link_url, status, created_at
        FROM documents
        WHERE owner_id = ? AND category = 'Application documents'
        ORDER BY created_at DESC");
    $query->execute([$userId]);
    $documents = $query->fetchAll();
}
$skills = array_values(array_filter(array_map('trim', preg_split('/[,\n;]+/', (string) ($person['skills'] ?? '')) ?: [])));
$value = static fn(string $key): string => mentora_h($person[$key] ?? '');
$docStatus = ['in_review' => ['info', 'In review'], 'published' => ['', 'Verified'], 'draft' => ['warn', 'Needs update']];
?>
<div class="admin-heading">
    <div>
        <h1><?= $isIntern ? 'Your intern profile' : 'Employee profile' ?></h1>
        <p><?= $isIntern
            ? 'Your personal information, application record and school requirements in one place.'
            : 'Manage your personal information, professional background, and employee documents.' ?></p>
    </div>
</div>
<?= mentora_flash_html() ?>
<?php if (!$signedIn): ?><div class="notice">You’re viewing a preview. <a href="../../auth/signin.php">Sign in</a> to edit your profile.</div><?php endif; ?>
<section class="pathway-card profile-hero">
    <span class="presence-wrap presence-wrap-xl"><?= mentora_avatar($avatarUrl, $initials, $fullName, 'avatar-xl') ?><i class="presence-dot is-<?= mentora_h($presence) ?>" title="<?= mentora_h($presenceLabels[$presence]) ?>"></i></span>
    <div class="profile-hero-copy">
        <h2><?= mentora_h($fullName) ?></h2>
        <p><?= mentora_h(($person['desired_role'] ?? '') !== '' ? $person['desired_role'] : ($isIntern ? 'Virtual Assistant Intern' : 'Employee VA')) ?> · <?= mentora_h($currentUser['email']) ?></p>
        <span class="badge"><?= mentora_h($currentUser['user_code'] ?? '') ?: 'Profile' ?></span>
        <form method="post" class="presence-picker" aria-label="Your status">
            <?= mentora_csrf_field() ?><input type="hidden" name="action" value="set_presence">
            <?php foreach ($presenceLabels as $option => $label): ?>
                <button type="submit"
                    name="presence"
                    value="<?= $option ?>"
                    class="presence-option is-<?= $option ?>"
                    aria-pressed="<?= $chosenPresence === $option ? 'true' : 'false' ?>"<?= $signedIn ? '' : ' disabled' ?>><i class="presence-dot is-<?= $option ?>" aria-hidden="true"></i><?= $label ?></button>
            <?php endforeach; ?>
        </form>
        <small class="presence-hint">You show as Offline automatically when you sign out or are away for <?= MENTORA_ONLINE_MINUTES ?> minutes, and as <?= mentora_h($presenceLabels[$chosenPresence]) ?> again when you’re back. While your status is Offline, your <?= $isIntern ? 'internship' : 'work' ?> hours are paused.</small>
    </div>
    <div class="profile-hero-actions">
        <a class="admin-btn" href="settings.php#profile"><?= $avatarUrl ? 'Change photo' : 'Add photo' ?></a>
        <a class="admin-btn" href="#profile-details">Edit profile</a>
    </div>
</section>
<div class="admin-grid">
    <section class="pathway-card applicant-form" id="profile-details">
        <h3>Personal & contact details</h3>
        <form method="post">
            <?= mentora_csrf_field() ?><input type="hidden" name="action" value="save_details">
            <div class="form-grid">
                <div class="field"><label>Full name</label><input value="<?= mentora_h($fullName) ?>" disabled></div>
                <div class="field"><label>Email address</label><input value="<?= mentora_h($currentUser['email']) ?>" disabled></div>
                <div class="field">
                    <label for="mobile">Mobile number</label><input id="mobile" name="mobile" inputmode="tel" maxlength="40" value="<?= $value('mobile') ?>"></div>
                <div class="field">
                    <label for="location">Current city / province</label><input id="location" name="location" maxlength="190" value="<?= $value('location') ?>"></div>
                <?php if ($isIntern): ?>
                    <div class="field">
                        <label for="school">School / university</label><input id="school" name="school" maxlength="190" value="<?= $value('school') ?>"></div>
                    <div class="field">
                        <label for="course">Degree / program</label><input id="course" name="course" maxlength="190" value="<?= $value('course') ?>"></div>
                    <div class="field">
                        <label for="required_hours">Required internship hours *</label><input id="required_hours" name="required_hours" type="number" min="1" max="5000" required value="<?= mentora_required_hours($person) ?>"></div>
                <?php endif; ?>
                <div class="field">
                    <label for="desired_role"><?= $isIntern ? 'Preferred role' : 'Role / title' ?></label><input id="desired_role" name="desired_role" maxlength="190" value="<?= $value('desired_role') ?>"></div>
                <div class="field full">
                    <label for="skills">Skills & tools</label><textarea id="skills" name="skills" rows="3" maxlength="2000" placeholder="Separate with commas"><?= $value('skills') ?></textarea>
                </div>
            </div>
            <p class="panel-subtitle">Name and email changes are handled by HR.</p>
            <button class="admin-btn primary"
                type="submit"<?= $signedIn ? '' : ' disabled' ?>
                data-confirm-title="Save your profile?"
                data-confirm="Your details will be updated and visible to your agent and HR.">Save changes</button>
        </form>
    </section>
    <section class="pathway-card">
        <h3><?= $isIntern ? 'School & placement' : 'Availability & placement' ?></h3>
        <?php if ($isIntern): ?>
            <div class="notice-row"><span>School</span><strong><?= ($person['school'] ?? '') !== '' ? $value('school') : 'To be provided' ?></strong></div>
            <div class="notice-row">
                <span>Degree program</span><strong><?= ($person['course'] ?? '') !== '' ? $value('course') : 'To be provided' ?></strong>
            </div>
            <div class="notice-row"><span>Required hours</span><strong><?= mentora_required_hours($person) ?> hours</strong></div>
        <?php else: ?>
            <div class="notice-row"><span>Work schedule</span><strong><?= MENTORA_SHIFT_LABEL ?></strong></div>
        <?php endif; ?>
        <div class="notice-row">
            <span><?= $isIntern ? 'Team' : 'Department' ?></span><strong><?= mentora_h($person['department_name'] ?? '') ?: 'To be assigned by HR' ?></strong>
        </div>
        <?php if ($isIntern): ?>
        <div class="notice-row">
            <span>Supervising agent</span><strong><?= mentora_h($person['agent_name'] ?? '') ?: 'To be assigned' ?></strong>
        </div>
        <?php endif; ?>
        <div class="notice-row">
            <span>Application received</span><strong><?= !empty($person['applied_at']) ? date('d M Y', strtotime($person['applied_at'])) : '—' ?></strong>
        </div>
        <h3>Skills & tools</h3>
        <?php if (!$skills): ?><p>Add your skills in the form to show them here.</p><?php endif; ?>
        <?php foreach ($skills as $skill): ?><span class="badge info"><?= mentora_h($skill) ?></span> <?php endforeach; ?>
    </section>
</div>
<?php
$social = $signedIn ? mentora_social_profile($pdo, $userId) : [];
$socialDraft = $_SESSION['social_draft'] ?? null;
unset($_SESSION['social_draft']);
[$socialAdded, $socialPercent] = mentora_social_completion($social);
$vaType = mentora_va_type($folder);
?>
<section class="pathway-card social-profiles" id="social-profiles">
    <div class="social-head">
        <div>
            <span class="module-eyebrow"><?= $vaType ?></span>
            <h3>Social profile monitoring</h3>
            <p>Build your online presence as a <?= $vaType ?>. Add the link to each profile you’ve created; your agent and HR track your progress.</p>
        </div>
        <div class="social-score" aria-label="<?= $socialAdded ?> of <?= count(MENTORA_SOCIAL_PLATFORMS) ?> profiles added">
            <strong><?= $socialPercent ?>%</strong>
            <span><?= $socialAdded ?> of <?= count(MENTORA_SOCIAL_PLATFORMS) ?> profiles added</span>
            <div class="bar"><i style="width:<?= $socialPercent ?>%"></i></div>
        </div>
    </div>
    <form method="post" class="applicant-form">
        <?= mentora_csrf_field() ?><input type="hidden" name="action" value="save_social">
        <div class="form-grid">
            <?php foreach (MENTORA_SOCIAL_PLATFORMS as $column => [$label, $hosts, $example]):
                $value = $socialDraft[$column] ?? ($social[$column] ?? '');
                $saved = trim((string) ($social[$column] ?? '')) !== ''; ?>
                <div class="field">
                    <label for="<?= $column ?>"><?= $label ?> URL <?= $saved ? '<span class="badge">Added</span>' : '' ?></label>
                    <div class="social-input">
                        <input id="<?= $column ?>"
                            name="<?= $column ?>"
                            type="url"
                            inputmode="url"
                            maxlength="255"
                            placeholder="<?= mentora_h($example) ?>"
                            value="<?= mentora_h($value) ?>">
                        <?php if ($saved): ?><a class="admin-btn"
                            href="<?= mentora_h($social[$column]) ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="Open your <?= $label ?> profile"><?= mentora_icon('view') ?>Open</a><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="field">
                <label>Number of profiles added</label><input value="<?= $socialAdded ?> of <?= count(MENTORA_SOCIAL_PLATFORMS) ?>" disabled></div>
            <div class="field"><label>Percentage of completion</label><input value="<?= $socialPercent ?>%" disabled></div>
        </div>
        <p class="panel-subtitle"><?= $social['updated_at'] ?? null
            ? 'Last updated ' . date('d M Y, g:i A', strtotime($social['updated_at'])) . '. '
            : '' ?>Leave a field empty if you don’t have that profile yet. Links must be on the platform’s own website.</p>
        <button class="admin-btn primary"
            type="submit"<?= $signedIn ? '' : ' disabled' ?>
            data-confirm-title="Save your social profiles?"
            data-confirm="Your links will be visible to your agent and HR for social profile monitoring.">Save social profiles</button>
    </form>
</section>
<section class="pathway-card data-panel" id="documents">
    <h3>Portfolio & application documents</h3>
    <p>Share your portfolio as a Google Drive link, or upload your <?= $isIntern ? 'school endorsement, internship agreement' : 'IDs, signed offer' ?> and other requirements.</p>
    <div class="document-add">
        <form method="post" class="document-add-form">
            <?= mentora_csrf_field() ?><input type="hidden" name="action" value="add_link">
            <strong>Add a link <span class="badge info">Google Drive</span></strong>
            <small>In Google Drive: Share → General access → “Anyone with the link” (Viewer) → Copy link, then paste it here.</small>
            <div class="table-toolbar">
                <input name="link_name" maxlength="190" placeholder="Link name" aria-label="Link name">
                <input name="link_url"
                    type="url"
                    inputmode="url"
                    maxlength="500"
                    required
                    placeholder="https://drive.google.com/drive/folders/…"
                    aria-label="Link">
                <button class="admin-btn primary" type="submit"<?= $signedIn ? '' : ' disabled' ?>>Add link</button>
            </div>
        </form>
        <form method="post" enctype="multipart/form-data" class="document-add-form">
            <?= mentora_csrf_field() ?><input type="hidden" name="action" value="upload_document">
            <strong>Or upload a file</strong>
            <small>PDF, Word, JPG or PNG · up to 10 MB.</small>
            <div class="table-toolbar">
                <input name="document_name" maxlength="190" placeholder="Document name" aria-label="Document name">
                <input type="file" name="document" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt" aria-label="File">
                <button class="admin-btn" type="submit"<?= $signedIn ? '' : ' disabled' ?>><?= mentora_icon('upload') ?>Upload</button>
            </div>
        </form>
    </div>
    <?php if ($documents): ?>
        <table class="admin-table"><thead><tr><th>Document</th><th>Type</th><th>Added</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            <?php foreach ($documents as $document): [$badgeClass, $badgeText] = $docStatus[$document['status']] ?? ['', $document['status']]; $link = $document['link_url']; ?>
                <tr><td><?= mentora_h($document['name']) ?><br><small class="document-url"><?= mentora_h($link ?: $document['original_name']) ?></small></td>
                    <td><?= $link ? (mentora_is_drive_link($link) ? 'Google Drive link' : 'Link') : mentora_format_bytes((int) $document['size']) ?></td>
                    <td><?= date('d M Y', strtotime($document['created_at'])) ?></td><td><span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span></td>
                    <td class="row-actions">
                        <?php if ($link): ?><a class="admin-btn"
                            href="<?= mentora_h($link) ?>"
                            target="_blank"
                            rel="noopener noreferrer"><?= mentora_icon('view') ?>Open</a><button class="admin-btn" type="button" data-copy-text="<?= mentora_h($link) ?>">Copy link</button><?php else: ?><a class="admin-btn" href="../../account/document.php?id=<?= (int) $document['id'] ?>" target="_blank" rel="noopener"><?= mentora_icon('view') ?>Open</a><?php endif; ?>
                        <form method="post"><?= mentora_csrf_field() ?><input type="hidden" name="action" value="delete_document"><input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>"><button class="admin-btn" type="submit" data-confirm-title="Remove this document?" data-confirm="“<?= mentora_h($document['name']) ?>” will be deleted." data-confirm-action="Remove">Remove</button>
                        </form>
                        </td>
                        </tr>
            <?php endforeach; ?>
        </tbody></table>
    <?php else: ?>
        <p class="table-note">No portfolio links or documents yet.</p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../applicant_footer.php'; ?>

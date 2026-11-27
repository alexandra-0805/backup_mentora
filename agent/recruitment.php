<?php

/**
 * Recruitment links (agents; HR sees everyone's): create a link and send it to applicants, like a form link.
 * Opening it leads to sign in / create an account, then the Intern or Employee VA choice (auth/join.php).
 * Below: everyone who joined with your links and where they are now.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/recruitment.php';

if (mentora_is_post()) {
    $me = mentora_post_user(['agent', 'admin'], 'agent/recruitment.php');
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $days = in_array($_POST['expires'] ?? '', ['7', '30', '90'], true) ? (int) $_POST['expires'] : null;
        $label = trim((string) ($_POST['label'] ?? ''));
        mentora_create_recruitment_link($pdo, (int) $me['id'], $label !== '' ? $label : 'Recruitment link ' . date('d M Y'), $days);
        mentora_flash('Your link is ready. Copy it and send it to applicants.');
    } elseif (in_array($action, ['pause', 'resume'], true)) {
        $update = $pdo->prepare('UPDATE recruitment_links SET active = ? WHERE id = ? AND (agent_id = ? OR ? = 1)');
        $update->execute([$action === 'resume' ? 1 : 0, (int) ($_POST['id'] ?? 0), $me['id'], $me['role'] === 'admin' ? 1 : 0]);
        mentora_flash($update->rowCount() ? ($action === 'resume' ? 'The link works again.' : 'The link is paused; people who open it are told it isn’t active.') : 'That link couldn’t be changed.', $update->rowCount() ? 'success' : 'error');
    }
    mentora_redirect('agent/recruitment.php');
}

$pageTitle = 'Recruitment links';
$activePage = 'dashboard.php'; // not in the sidebar; highlight the Dashboard it's opened from
require __DIR__ . '/../includes/agent_layout.php';

$me = $_SESSION['user'];
$scope = $me['role'] === 'admin' ? null : (int) $me['id'];
$links = mentora_recruitment_links($pdo, $scope);
$people = mentora_referred_people($pdo, $scope);
$isLive = static fn(array $link): bool => (bool) $link['active'] && ($link['expires_at'] === null || strtotime($link['expires_at']) > time());
$interns = count(array_filter($people, static fn($p) => $p['pathway_chosen_at'] !== null && $p['pathway'] === 'internship'));
$employees = count(array_filter($people, static fn($p) => $p['pathway_chosen_at'] !== null && $p['pathway'] === 'employee_va'));
?>
<div class="admin-heading">
    <div>
        <h1>Recruitment links</h1>
        <p>Send a link to applicants. They sign in or create an account, choose Intern or Employee VA, and fill the matching form.</p>
    </div>
</div>
<?= mentora_flash_html() ?>
<div class="metric-grid">
    <div class="metric-card"><small>Active links</small><strong><?= count(array_filter($links, $isLive)) ?></strong><span>Of <?= count($links) ?> created</span></div>
    <div class="metric-card"><small>People joined</small><strong><?= count($people) ?></strong><span>With <?= $scope ? 'your' : 'agents’' ?> links</span></div>
    <div class="metric-card"><small>Interns</small><strong><?= $interns ?></strong><span>Supervised by the link’s agent</span></div>
    <div class="metric-card"><small>Employee VAs</small><strong><?= $employees ?></strong><span>Screened by AI review</span></div>
</div>
<div class="admin-grid">
    <section class="admin-panel">
        <h2>Create a link</h2>
        <p class="panel-subtitle">Give it a name so you know where applicants came from, e.g. “KLD job fair” or “Facebook post”.</p>
        <form method="post" class="form-grid">
            <?= mentora_csrf_field() ?><input type="hidden" name="action" value="create">
            <div class="field full"><label for="label">Link name</label><input id="label" name="label" maxlength="120"></div>
            <div class="field">
                <label for="expires">Works for</label>
                <select id="expires" name="expires">
                    <option value="">No end date</option><option value="7">7 days</option><option value="30" selected>30 days</option><option value="90">90 days</option>
                </select>
            </div>
            <div class="field"><label>&nbsp;</label><button class="admin-btn primary" type="submit">Create link</button></div>
        </form>
    </section>
    <section class="admin-panel">
        <h2>How it works</h2>
        <ol class="screening-list">
            <li>The applicant opens your link and signs in or creates an account.</li>
            <li>They choose <strong>Intern</strong> or <strong>Employee VA</strong>.</li>
            <li>Interns fill the internship form and go straight to training; you’re their supervising agent.</li>
            <li>Employee VAs fill the form or upload a resume; the AI review sorts them as suitable or for review, and notifies HR and the matching department.</li>
        </ol>
    </section>
</div>
<section class="admin-panel data-panel" style="margin-top:16px">
    <table class="admin-table">
        <thead><tr><th>Link</th><?= $scope ? '' : '<th>Agent</th>' ?><th>Address</th><th>Joined</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$links): ?><tr><td colspan="6" class="table-note">No links yet. Create your first one above.</td></tr><?php endif; ?>
        <?php foreach ($links as $link): $url = mentora_recruitment_url($link['code']); $live = $isLive($link); ?>
            <tr>
                <td><?= mentora_h($link['label']) ?><br><small>Created <?= date('d M Y', strtotime($link['created_at'])) ?></small></td>
                <?= $scope ? '' : '<td>' . mentora_h($link['agent_name']) . '</td>' ?>
                <td><input class="link-field" value="<?= mentora_h($url) ?>" readonly aria-label="Link address" onfocus="this.select()"></td>
                <td><?= (int) $link['uses'] ?></td>
                <td><span class="badge <?= $live ? '' : 'warn' ?>"><?= $live ? 'Active' : (!$link['active'] ? 'Paused' : 'Expired') ?></span><?= $link['expires_at'] ? '<br><small>Until ' . date('d M Y', strtotime($link['expires_at'])) . '</small>' : '' ?></td>
                <td class="row-actions">
                    <button class="admin-btn primary" type="button" data-copy-text="<?= mentora_h($url) ?>">Copy link</button>
                    <form method="post"><?= mentora_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $link['id'] ?>"><input type="hidden" name="action" value="<?= $link['active'] ? 'pause' : 'resume' ?>"><button class="admin-btn" type="submit"><?= $link['active'] ? 'Pause' : 'Turn on' ?></button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<section class="admin-panel data-panel" id="joined" style="margin-top:16px">
    <h2>People who joined with <?= $scope ? 'your' : 'these' ?> links</h2>
    <table class="admin-table">
        <thead><tr><th>Applicant</th><th>Pathway</th><?= $scope ? '' : '<th>Agent</th>' ?><th>Joined</th><th>Where they are</th></tr></thead>
        <tbody>
        <?php if (!$people): ?><tr><td colspan="5" class="table-note">Nobody has joined yet.</td></tr><?php endif; ?>
        <?php foreach ($people as $person): [$stage, $stageClass] = mentora_referral_stage($person); ?>
            <tr>
                <td><?= mentora_h($person['first_name'] . ' ' . $person['last_name']) ?><br><small><?= mentora_h($person['email']) ?></small></td>
                <td><?= $person['pathway_chosen_at'] ? mentora_pathway_label($person['pathway']) : '—' ?></td>
                <?= $scope ? '' : '<td>' . mentora_h($person['agent_name']) . '</td>' ?>
                <td><?= date('d M Y', strtotime($person['created_at'])) ?></td>
                <td><span class="badge <?= $stageClass ?>"><?= mentora_h($stage) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>

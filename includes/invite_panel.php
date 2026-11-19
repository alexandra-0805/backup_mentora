<?php

/**
 * The "send this confirmation link" panel shown once after approving an applicant (admin/applications.php)
 * or inviting a user (admin/user_edit.php). $invite: name, full_name, email, pathway, link, resent,
 * emailed, mail_error, mail_not_configured, and optionally role ('agent' / 'admin' for staff invites).
 */

declare(strict_types=1);

require_once __DIR__ . '/account_invites.php';

function mentora_render_invite_panel(array $invite, string $adminName): void
{
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    [$subject, $body] = mentora_invite_email($invite['name'], $invite['pathway'], $invite['link'], $adminName, $invite['role'] ?? 'applicant');
    $gmailUrl = 'https://mail.google.com/mail/?' . http_build_query(['view' => 'cm', 'fs' => 1, 'to' => $invite['email'], 'su' => $subject, 'body' => $body]);
    $mailtoUrl = 'mailto:' . rawurlencode($invite['email']) . '?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body);
    $emailed = !empty($invite['emailed']);
    $staff = in_array($invite['role'] ?? 'applicant', ['agent', 'admin'], true);
?>
    <section class="admin-panel invite-ready<?= $emailed ? ' is-sent' : '' ?>" aria-labelledby="invite-ready-title">
        <?php if ($emailed): ?>
            <h2 id="invite-ready-title">✓ <?= $invite['resent'] ? 'New link emailed' : ($staff ? 'Invitation emailed' : 'Approved and emailed') ?> to <?= $h($invite['full_name']) ?></h2>
            <p class="panel-subtitle">
                The confirmation link was sent automatically to <strong><?= $h($invite['email']) ?></strong>.
                It works once and expires in <?= MENTORA_INVITE_DAYS ?> days.<?= $invite['resent'] ? ' Any link sent earlier no longer works.' : '' ?>
            </p>
            <details class="invite-backup">
                <summary>Didn’t arrive? Send it another way</summary>
        <?php else: ?>
            <h2 id="invite-ready-title"><?= $invite['resent'] ? 'New link ready' : ($staff ? 'Invited' : 'Approved') ?>: send <?= $h($invite['full_name']) ?> their confirmation link</h2>
            <p class="invite-mail-status" role="status">
                <?php if (!empty($invite['mail_not_configured'])): ?>
                    Automatic email isn’t set up yet (config/mail.local.php), so please send this link yourself.
                <?php else: ?>
                    <strong>The email couldn’t be sent automatically:</strong> <?= $h($invite['mail_error']) ?> Please send this link yourself.
                <?php endif; ?>
            </p>
            <p class="panel-subtitle">
                Send it to <strong><?= $h($invite['email']) ?></strong>. It works once and expires in <?= MENTORA_INVITE_DAYS ?> days.
                <?= $invite['resent'] ? 'Any link sent earlier no longer works.' : '' ?>
                This link is shown only now, so send it before leaving the page.
            </p>
        <?php endif; ?>
        <div class="invite-link-row">
            <input id="invite-link" type="text" value="<?= $h($invite['link']) ?>" readonly aria-label="Confirmation link">
        </div>
        <div class="invite-actions">
            <a class="admin-btn primary" href="<?= $h($gmailUrl) ?>" target="_blank" rel="noopener">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                Open in Gmail
            </a>
            <a class="admin-btn" href="<?= $h($mailtoUrl) ?>">Other email app</a>
            <button class="admin-btn" type="button" data-copy-link="#invite-link">Copy link</button>
        </div>
        <?php if ($emailed): ?>
            </details>
        <?php endif; ?>
    </section>
    <script>
        document.querySelectorAll('[data-copy-link]').forEach((button) => {
            button.addEventListener('click', async () => {
                const field = document.querySelector(button.dataset.copyLink);
                try {
                    await navigator.clipboard.writeText(field.value);
                } catch (error) {
                    field.select();
                    document.execCommand('copy');
                }
                button.textContent = 'Copied ✓';
                setTimeout(() => { button.textContent = 'Copy link'; }, 2000);
            });
        });
    </script>
<?php
}

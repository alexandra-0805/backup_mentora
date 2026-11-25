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
    [$subject, $body, $emailHtml] = mentora_invite_email($invite['name'], $invite['pathway'], $invite['link'], $adminName, $invite['role'] ?? 'applicant');
    // Gmail's compose link only takes plain text. So "Open in Gmail" copies the branded (HTML) email and opens
    // a draft with To + Subject filled in for the admin to paste into; the plain-text body is the fallback.
    $gmailUrl = 'https://mail.google.com/mail/?' . http_build_query(['view' => 'cm', 'fs' => 1, 'to' => $invite['email'], 'su' => $subject, 'body' => $body]);
    $gmailPasteUrl = 'https://mail.google.com/mail/?' . http_build_query(['view' => 'cm', 'fs' => 1, 'to' => $invite['email'], 'su' => $subject]);
    $mailtoUrl = 'mailto:' . rawurlencode($invite['email']) . '?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body);
    $emailed = !empty($invite['emailed']);
    $staff = in_array($invite['role'] ?? 'applicant', ['agent', 'admin'], true);
?>
    <section class="admin-panel invite-ready<?= $emailed ? ' is-sent' : '' ?>" aria-labelledby="invite-ready-title">
        <?php if ($emailed): ?>
            <h2 id="invite-ready-title">✓ <?= $invite['resent']
                ? 'New link emailed'
                : ($staff ? 'Invitation emailed' : 'Approved and emailed') ?> to <?= $h($invite['full_name']) ?></h2>
            <p class="panel-subtitle">
                The confirmation link was sent automatically to <strong><?= $h($invite['email']) ?></strong>.
                It works once and expires in <?= MENTORA_INVITE_DAYS ?> days. If it won’t open, they can get an activation code by email
                from “Activate your account” on Track your application.<?= $invite['resent'] ? ' Any link or code sent earlier no longer works.' : '' ?>
            </p>
            <details class="invite-backup">
                <summary>Didn’t arrive? Send it another way</summary>
        <?php else: ?>
            <h2 id="invite-ready-title"><?= $invite['resent'] ? 'New link ready' : ($staff ? 'Invited' : 'Approved') ?>: send <?= $h($invite['full_name']) ?> their confirmation link</h2>
            <p class="invite-mail-status" role="status">
                <?php if (!empty($invite['mail_not_configured'])): ?>
                    Automatic email isn’t set up yet (config/mail.local.php), so please send this link yourself.
                <?php else: ?>
                    <strong>The email couldn’t be sent automatically:</strong> <?= $h($invite['mail_error']) ?>
                    Try again, or send this link yourself.
                <?php endif; ?>
            </p>
            <?php if (empty($invite['mail_not_configured']) && !empty($invite['user_id'])): ?>
                <?php // Same "resend" action as the page's own button (admin/applications.php reads user_id + view, admin/user_edit.php reads id). ?>
                <form method="post" class="invite-retry" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:0 0 14px;">
                    <?= mentora_csrf_field() ?>
                    <input type="hidden" name="action" value="resend">
                    <input type="hidden" name="user_id" value="<?= (int) $invite['user_id'] ?>">
                    <input type="hidden" name="id" value="<?= (int) $invite['user_id'] ?>">
                    <input type="hidden" name="view" value="approved">
                    <button class="admin-btn primary" type="submit" data-sending-label="Sending…">Try sending again</button>
                    <small>Sends a new link; the one below stops working.</small>
                </form>
            <?php endif; ?>
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
            <a class="admin-btn primary" href="<?= $h($gmailUrl) ?>" target="_blank" rel="noopener"
               data-gmail-styled="<?= $h($gmailPasteUrl) ?>">
                <svg viewBox="0 0 24 24"
                    width="14"
                    height="14"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true">
                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                    <path d="m3 7 9 6 9-6"/>
                </svg>
                Open in Gmail
            </a>
            <button class="admin-btn" type="button" data-copy-styled>Copy styled email</button>
            <a class="admin-btn" href="<?= $h($mailtoUrl) ?>">Other email app</a>
            <button class="admin-btn" type="button" data-copy-link="#invite-link">Copy link</button>
        </div>
        <p class="panel-subtitle invite-paste-hint" role="status" hidden></p>
        <textarea id="invite-email-html" hidden aria-hidden="true"><?= $h($emailHtml) ?></textarea>
        <textarea id="invite-email-text" hidden aria-hidden="true"><?= $h($body) ?></textarea>
        <?php if ($emailed): ?>
            </details>
        <?php endif; ?>
    </section>
    <script>
        (() => {
            const html = document.getElementById('invite-email-html').value;
            const text = document.getElementById('invite-email-text').value;
            const hint = document.querySelector('.invite-paste-hint');
            const showHint = (message) => { hint.textContent = message; hint.hidden = false; };

            // Copy the branded email as rich text, so pasting into Gmail keeps the design.
            const copyStyled = async () => {
                try {
                    if (window.ClipboardItem && navigator.clipboard?.write) {
                        await navigator.clipboard.write([new ClipboardItem({
                            'text/html': new Blob([html], { type: 'text/html' }),
                            'text/plain': new Blob([text], { type: 'text/plain' }),
                        })]);
                        return true;
                    }
                } catch (error) { /* try the older way below */ }
                const holder = document.createElement('div');
                holder.style.cssText = 'position:fixed;left:-10000px;top:0;width:600px;';
                holder.innerHTML = html;
                document.body.appendChild(holder);
                const range = document.createRange();
                range.selectNodeContents(holder);
                const selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
                let copied = false;
                try { copied = document.execCommand('copy'); } catch (error) { copied = false; }
                selection.removeAllRanges();
                holder.remove();
                return copied;
            };

            document.querySelector('[data-copy-styled]')?.addEventListener('click', async (event) => {
                const button = event.currentTarget;
                if (await copyStyled()) {
                    button.textContent = 'Copied ✓';
                    showHint('Styled email copied. Click inside the message body of your email and press Ctrl+V (⌘V on Mac) to paste it.');
                    setTimeout(() => { button.textContent = 'Copy styled email'; }, 2000);
                } else {
                    showHint('Your browser blocked copying. Use “Copy link” and paste the link into your email instead.');
                }
            });

            document.querySelector('[data-gmail-styled]')?.addEventListener('click', async (event) => {
                event.preventDefault();
                const link = event.currentTarget;
                if (await copyStyled()) {
                    window.open(link.dataset.gmailStyled, '_blank', 'noopener');
                    showHint('Gmail opened with the recipient and subject filled in. Click inside the message body and press Ctrl+V (⌘V on Mac) to paste the styled email, then Send.');
                } else {
                    window.open(link.href, '_blank', 'noopener');   // plain-text version as before
                }
            });
        })();

        // Sending can take a few seconds: show it and prevent double-clicks.
        document.querySelector('.invite-retry')?.addEventListener('submit', (event) => {
            const button = event.currentTarget.querySelector('button');
            button.disabled = true;
            button.textContent = button.dataset.sendingLabel;
        });

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

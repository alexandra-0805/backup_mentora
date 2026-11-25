<?php

/**
 * Recruitment link landing page (auth/join.php?ref=CODE), sent by an agent (agent/recruitment.php).
 * Like a form link that needs a login: the applicant signs in, or creates their account right here
 * (username + password, or Google), then chooses Intern or Employee VA (auth/pathway.php).
 * See includes/recruitment.php for the whole flow.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/recruitment.php';
require_once __DIR__ . '/../includes/google_auth.php';

mentora_session_start();
$code = is_string($_GET['ref'] ?? null) ? $_GET['ref'] : (string) ($_SESSION['recruitment_ref'] ?? '');
$link = mentora_find_recruitment_link($pdo, $code);
if ($link) {
    $_SESSION['recruitment_ref'] = $link['code'];
}

$signedIn = mentora_current_user();
if ($link && $signedIn && $signedIn['role'] === 'applicant') {
    // Already has an account: carry on (choose a pathway, the form, or the workspace).
    $chosen = $pdo->prepare('SELECT pathway_chosen_at IS NOT NULL FROM users WHERE id = ?');
    $chosen->execute([$signedIn['id']]);
    if ($chosen->fetchColumn()) {
        mentora_apply_referral($pdo, (int) $signedIn['id']);
    }
    mentora_redirect(mentora_after_sign_in_path($pdo, $signedIn));
}

$errors = [];
$fieldErrors = [];
$account = ['first_name' => '', 'last_name' => '', 'email' => '', 'username' => ''];
if (!empty($_SESSION['flash_error']) && is_string($_SESSION['flash_error'])) {
    $errors[] = $_SESSION['flash_error']; // e.g. from Continue with Google
}
unset($_SESSION['flash_error']);

if ($link && !$signedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['first_name', 'last_name', 'email'] as $key) {
        $account[$key] = preg_replace('/\s+/u', ' ', trim(mentora_post_string($key)));
    }
    $account['email'] = strtolower($account['email']);
    $account['username'] = strtolower(trim(mentora_post_string('username')));
    $password = mentora_post_string('password');

    if (!mentora_csrf_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }
    $fieldErrors = array_filter([
        'first_name' => mentora_name_error($account['first_name'], 'first name'),
        'last_name' => mentora_name_error($account['last_name'], 'last name'),
        'email' => mentora_email_error($account['email']),
        'username' => mentora_username_error($pdo, $account['username'], 0),
        'password' => mentora_password_error($password),
        'confirm_password' => $password !== mentora_post_string('confirm_password') ? 'Passwords do not match.' : null,
        'agree' => isset($_POST['agree']) ? null : 'Please accept the Data Privacy Notice to create your account.',
    ]);
    if ($fieldErrors) {
        $errors[] = 'Please fix the highlighted fields.';
    }
    if (!$errors) {
        try {
            $result = mentora_join_create_account($pdo, $link, $account['first_name'], $account['last_name'], $account['email'], $account['username'], $password);
        } catch (PDOException $exception) {
            error_log('Mentora join failed: ' . $exception->getMessage());
            $result = ['ok' => false, 'error' => 'We couldn’t create your account right now. Please try again.'];
        }
        if ($result['ok']) {
            $row = $pdo->prepare('SELECT id, user_code, username, first_name, last_name, email, pathway, password_hash, role, avatar_path FROM users WHERE id = ?');
            $row->execute([$result['id']]);
            mentora_sign_in($row->fetch());
            $_SESSION['flash_notice'] = 'Welcome to Mentora, ' . $account['first_name'] . '! Your account is ready.';
            mentora_redirect('auth/pathway.php');
        }
        $errors[] = $result['error'];
        $fieldErrors['email'] = $result['error'];
    }
}

$pageTitle = $link ? 'Join Mentora' : 'Link not active | Mentora';
$bodyClass = 'account-page';
$headerStyle = 'signin-page';
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$invalid = static fn(string $field): string => isset($fieldErrors[$field]) ? ' aria-invalid="true"' : '';
$fieldError = static fn(string $field): string => '<small class="field-error" id="' . $field . '-error" role="alert">'
    . (isset($fieldErrors[$field]) ? $h($fieldErrors[$field]) : '') . '</small>';
require __DIR__ . '/../includes/header.php';
?>
<main class="auth-shell account-shell">
    <?php if (!$link): ?>
        <section class="auth-card account-card application-status" aria-labelledby="join-title">
            <span class="application-status-icon is-warning" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 2.4 17.5A2 2 0 0 0 4.1 20.5h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
            </span>
            <h1 id="join-title">This link isn’t active.</h1>
            <p class="intro">The recruitment link may have expired or been turned off by the agent who sent it.</p>
            <div class="notice activate-hint">
                <span><strong>Still want to join?</strong><br>Ask your Mentora agent for a new link, or apply directly.</span>
                <a class="activate-hint-link" href="signup.php">Apply directly</a>
            </div>
            <div class="auth-links">
                Already have an account? <a href="signin.php">Sign in</a><br><br>
                <a href="../index.php">Back to home</a>
            </div>
        </section>
    <?php elseif ($signedIn): ?>
        <section class="auth-card account-card application-status" aria-labelledby="join-title">
            <h1 id="join-title">This is a recruitment link.</h1>
            <p class="intro"><?= $h($link['agent_name']) ?>’s link<?= $link['label'] !== '' ? ' “' . $h($link['label']) . '”' : '' ?>. Applicants who open it sign in or create an account, then choose Intern or Employee VA.</p>
            <div class="notice activate-hint">
                <span><strong>You’re signed in as staff.</strong><br>Share this link with applicants; it isn’t for your own account.</span>
                <a class="activate-hint-link" href="<?= $h('../' . mentora_home_path($signedIn)) ?>">Back to your workspace</a>
            </div>
        </section>
    <?php else: ?>
        <section class="auth-card wide account-card" aria-labelledby="join-title">
            <p class="eyebrow">Invited by <?= $h($link['agent_name']) ?><?= $link['agent_department'] ? ' · ' . $h($link['agent_department']) : '' ?></p>
            <h1 id="join-title">Join Mentora.</h1>
            <p class="intro">Create your account to start your application. Next, you’ll choose <strong>Intern</strong> or <strong>Employee VA</strong> and fill in the matching form.</p>

            <div class="notice activate-hint">
                <span><strong>Already have a Mentora account?</strong><br>Sign in and you’ll continue from this link.</span>
                <a class="activate-hint-link" href="signin.php">Sign in</a>
            </div>
            <?php if ($errors): ?><div class="alert" role="alert"><?= $h(implode(' ', array_unique($errors))) ?></div><?php endif; ?>

            <?php if (mentora_google_enabled()): ?>
                <?php // Its own form: inside the main one, the field checks (assets/js/forms.js) would block it until every field was filled. ?>
                <form method="post" action="google.php" class="google-auth">
                    <?= mentora_csrf_field() ?>
                    <?php mentora_google_button('join'); ?>
                    <small>Quickest: we fill in your name and email from Google, and you sign in with Google from then on.</small>
                </form>
                <div class="auth-divider"><span>or create an account with your email</span></div>
            <?php endif; ?>

            <form method="post" id="join-form" data-validate>
                <?= mentora_csrf_field() ?>
                <fieldset>
                    <legend>About you</legend>
                    <div class="form-grid">
                        <div class="field">
                            <label for="first_name">First name *</label>
                            <input id="first_name" name="first_name" required minlength="2" maxlength="80" autocomplete="given-name" placeholder="First name" data-check="name" data-label="first name" aria-describedby="first_name-error"<?= $invalid('first_name') ?> value="<?= $h($account['first_name']) ?>">
                            <?= $fieldError('first_name') ?>
                        </div>
                        <div class="field">
                            <label for="last_name">Last name *</label>
                            <input id="last_name" name="last_name" required minlength="2" maxlength="80" autocomplete="family-name" placeholder="Last name" data-check="name" data-label="last name" aria-describedby="last_name-error"<?= $invalid('last_name') ?> value="<?= $h($account['last_name']) ?>">
                            <?= $fieldError('last_name') ?>
                        </div>
                        <div class="field full">
                            <label for="email">Email address *</label>
                            <input id="email" type="email" name="email" required maxlength="190" autocomplete="email" placeholder="name@gmail.com" data-check="email" aria-describedby="email-hint email-error"<?= $invalid('email') ?> value="<?= $h($account['email']) ?>">
                            <small id="email-hint">We’ll use it for updates on your application. You can also sign in with it.</small>
                            <?= $fieldError('email') ?>
                        </div>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Sign-in details</legend>
                    <div class="form-grid">
                        <div class="field full">
                            <label for="username">Username *</label>
                            <input id="username" name="username" required minlength="3" maxlength="30" autocomplete="username" autocapitalize="none" spellcheck="false" pattern="[A-Za-z][A-Za-z0-9._\-]{2,29}" placeholder="e.g. juan.delacruz" aria-describedby="username-hint username-error"<?= $invalid('username') ?> value="<?= $h($account['username']) ?>">
                            <small id="username-hint">We suggest one from your name; change it if you like. 3–30 characters: start with a letter, then letters, numbers, dots, dashes or underscores.</small>
                            <?= $fieldError('username') ?>
                        </div>
                        <div class="field">
                            <label for="password">Password *</label>
                            <input id="password" type="password" name="password" required minlength="8" maxlength="64" autocomplete="new-password" data-check="password" aria-describedby="password-rules password-error"<?= $invalid('password') ?>>
                            <ul class="password-rules" id="password-rules" aria-label="Password requirements">
                                <?php foreach (array_keys(mentora_password_checks('')) as $n => $rule): ?><li data-rule="<?= $n ?>"><?= $h(ucfirst($rule)) ?></li><?php endforeach; ?>
                            </ul>
                            <?= $fieldError('password') ?>
                        </div>
                        <div class="field">
                            <label for="confirm_password">Confirm password *</label>
                            <input id="confirm_password" type="password" name="confirm_password" required maxlength="64" autocomplete="new-password" data-check="match" data-match="password" aria-describedby="confirm_password-error"<?= $invalid('confirm_password') ?>>
                            <?= $fieldError('confirm_password') ?>
                        </div>
                    </div>
                    <label class="check">
                        <input type="checkbox" id="show-passwords">
                        Show passwords
                    </label>
                </fieldset>

                <label class="check">
                    <input type="checkbox" name="agree" required data-check="agree" aria-describedby="agree-error"<?= $invalid('agree') ?>>
                    I agree to Mentora collecting and using my information to review my application, including an AI-supported
                    review of my form or resume, as described in the Data Privacy Notice.
                </label>
                <?= $fieldError('agree') ?>
                <div class="form-actions"><button class="button button-primary" type="submit" id="join-submit">Create account &amp; continue</button></div>
            </form>
            <div class="auth-links"><a href="../index.php">Back to home</a></div>
        </section>
        <script>
            (() => {
                const form = document.getElementById('join-form');
                const first = document.getElementById('first_name');
                const last = document.getElementById('last_name');
                const username = document.getElementById('username');

                // Suggest a username from the name ("José Dela Cruz" -> "jose.delacruz") until the person types their own.
                let ownUsername = username.value !== '';
                const plain = (text) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '');
                const suggest = () => {
                    if (ownUsername) return;
                    const name = [plain(first.value), plain(last.value)].filter(Boolean).join('.').slice(0, 30).replace(/\.+$/, '');
                    username.value = /^[a-z][a-z0-9._-]{2,29}$/.test(name) ? name : '';
                };
                first.addEventListener('input', suggest);
                last.addEventListener('input', suggest);
                username.addEventListener('input', () => {
                    const cleaned = username.value.toLowerCase().replace(/\s+/g, '');
                    if (cleaned !== username.value) username.value = cleaned;   // only when needed, so the cursor stays put
                    ownUsername = username.value !== '';
                });

                // Show / hide both password fields.
                const show = document.getElementById('show-passwords');
                show.addEventListener('change', () => {
                    form.querySelectorAll('#password, #confirm_password').forEach((input) => { input.type = show.checked ? 'text' : 'password'; });
                });

                // Once the field checks pass, prevent a second click from creating a duplicate request.
                const submit = document.getElementById('join-submit');
                const label = submit.textContent;
                form.addEventListener('submit', (event) => {
                    setTimeout(() => {   // after assets/js/forms.js has had its say
                        if (!event.defaultPrevented) {
                            submit.disabled = true;
                            submit.textContent = 'Creating your account…';
                        }
                    });
                });
                // Back button after submitting: make the button usable again.
                window.addEventListener('pageshow', () => {
                    submit.disabled = false;
                    submit.textContent = label;
                });
            })();
        </script>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>

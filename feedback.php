<?php
/**
 * Feedback: public page with the overall rating, the "Leave feedback" form, and every review.
 * Linked from the "What people are saying" section on the landing page and the top bar.
 * Helpers and rules: includes/feedback.php. Page-only styles: assets/css/feedback.css.
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/feedback.php';

$viewer = mentora_current_user();
$isAdmin = $viewer !== null && $viewer['role'] === 'admin';
// Keyed by field (rating, name, email, comment) so each message shows under its own field;
// 'form' holds problems that are not about one field (expired session, too many posts).
$errors = [];
$formData = ['name' => '', 'email' => '', 'comment' => '', 'rating' => 0];

// Signed-in people start with their own name and email filled in.
if ($viewer !== null) {
    $formData['name'] = (string) $viewer['name'];
    $formData['email'] = (string) $viewer['email'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = mentora_post_string('action');

    if (!mentora_csrf_valid($_POST['csrf_token'] ?? null)) {
        $errors['form'] = 'Your session expired. Please send the form again.';
    } elseif ($action === 'remove' && $isAdmin) {
        $pdo->prepare('DELETE FROM site_feedback WHERE id = ?')->execute([(int) mentora_post_string('feedback_id')]);
        header('Location: feedback.php?removed=1#reviews');
        exit;
    } elseif ($action === 'post') {
        $formData['name'] = trim(mentora_post_string('name'));
        $formData['email'] = trim(mentora_post_string('email'));
        $formData['comment'] = trim(str_replace("\r\n", "\n", mentora_post_string('comment')));
        $formData['rating'] = (int) mentora_post_string('rating');

        if (mentora_post_string('website') !== '') {
            // Hidden honeypot field: only bots fill it in. Pretend it worked and save nothing.
            header('Location: feedback.php?sent=1#leave-feedback');
            exit;
        }

        $recent = $pdo->prepare('SELECT COUNT(*) FROM site_feedback WHERE ip_hash = ? AND created_at > NOW() - INTERVAL 1 HOUR');
        $recent->execute([mentora_feedback_ip_hash()]);

        if ((int) $recent->fetchColumn() >= MENTORA_FEEDBACK_PER_HOUR) {
            $errors['form'] = 'You have shared feedback several times recently. Please try again later.';
        } else {
            if ($formData['rating'] < 1 || $formData['rating'] > 5) {
                $errors['rating'] = 'Please choose a rating from 1 to 5 stars.';
            }
            if ($formData['name'] === '' || mb_strlen($formData['name']) > MENTORA_FEEDBACK_LIMITS['name']) {
                $errors['name'] = 'Please enter your name (up to 100 characters).';
            }
            if ($formData['email'] !== '' && (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)
                || mb_strlen($formData['email']) > MENTORA_FEEDBACK_LIMITS['email'])) {
                $errors['email'] = 'Please enter a valid email address, or leave it empty.';
            }
            $commentLength = mb_strlen($formData['comment']);
            if ($commentLength < MENTORA_FEEDBACK_MIN_COMMENT || $commentLength > MENTORA_FEEDBACK_LIMITS['comment']) {
                $errors['comment'] = 'Please write a short comment (5 to 1,000 characters).';
            }
        }

        if (!$errors) {
            $pdo->prepare('INSERT INTO site_feedback (user_id, name, email, rating, comment, ip_hash) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([
                    $viewer['id'] ?? null,
                    $formData['name'],
                    $formData['email'] !== '' ? $formData['email'] : null,
                    $formData['rating'],
                    $formData['comment'],
                    mentora_feedback_ip_hash(),
                ]);
            mentora_feedback_notify_admins($pdo, $formData['name'], $formData['rating']);
            header('Location: feedback.php?sent=1#leave-feedback');
            exit;
        }
    }
}

$summary = mentora_feedback_summary($pdo);
$reviews = mentora_feedback_list($pdo, 60);
$h = 'mentora_feedback_h';

// Share of reviews with 4 or 5 stars, for the hero.
$happyPercent = $summary['total']
    ? (int) round(($summary['distribution'][5] + $summary['distribution'][4]) / $summary['total'] * 100)
    : 0;

// Star counts among the reviews listed on this page (the filter chips filter this list).
$listedCounts = array_fill_keys([5, 4, 3, 2, 1], 0);
foreach ($reviews as $review) {
    if (isset($listedCounts[(int) $review['rating']])) {
        $listedCounts[(int) $review['rating']]++;
    }
}

// Attributes that tie a field to its error message.
$fieldState = static function (string $field) use ($errors): string {
    return isset($errors[$field])
        ? ' aria-invalid="true" aria-describedby="' . $field . '-error"'
        : '';
};

$alertIcon = [
    'success' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.7 2.7L16 9.8"/></svg>',
    'error' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.4v.1"/></svg>',
];

$pageTitle = 'Feedback | Mentora';
$bodyClass = 'landing-page feedback-page';
$headerStyle = 'landing-page';
$pageStyles = ['assets/css/feedback.css?v=1'];

require __DIR__ . '/includes/header.php';
?>

<main>
    <section class="hero feedback-hero">
        <div class="hero-content">
            <p class="eyebrow">Feedback</p>
            <h1>Kind words &amp;<br><em>honest ratings.</em></h1>
            <p class="hero-copy">
                Applied, trained, or mentored with Mentora, or just looked around? We’d love to hear how it went.
            </p>

            <div class="feedback-hero-actions">
                <a class="button feedback-hero-primary" href="#leave-feedback">
                    Leave feedback
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                </a>
                <a class="button button-ghost" href="#reviews">Read reviews</a>
            </div>

            <?php if ($summary['total']): ?>
                <ul class="feedback-hero-stats" aria-label="Mentora at a glance">
                    <li>
                        <strong>
                            <?= $h(mentora_feedback_format($summary['average'])) ?>
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m12 2.8 2.8 5.8 6.3.9-4.6 4.4 1.1 6.3L12 17.2l-5.6 3 1.1-6.3-4.6-4.4 6.3-.9L12 2.8Z"/></svg>
                        </strong>
                        <span>average rating</span>
                    </li>
                    <li>
                        <strong><?= (int) $summary['total'] ?></strong>
                        <span><?= $summary['total'] === 1 ? 'review' : 'reviews' ?></span>
                    </li>
                    <li>
                        <strong><?= $happyPercent ?>%</strong>
                        <span>rate us 4★ or more</span>
                    </li>
                </ul>
            <?php endif; ?>
        </div>
    </section>

    <?php // The form comes first in the source so phones show it first; on wide screens CSS puts the rating card on its left. ?>
    <section class="section feedback-layout">
        <div class="feedback-form-card" id="leave-feedback">
            <p class="eyebrow">Leave feedback</p>
            <h2>How was your experience?</h2>
            <p class="feedback-form-intro">Your name, rating, and comment are shown publicly. Your email stays private.</p>

            <?php if (isset($_GET['sent'])): ?>
                <div class="feedback-alert is-success" role="status" data-flash="sent">
                    <?= $alertIcon['success'] ?>
                    <div>
                        <p><strong>Thank you for your feedback!</strong> Your review is now live.</p>
                        <a href="#reviews">See it in the reviews</a>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="feedback-alert is-error" role="alert">
                    <?= $alertIcon['error'] ?>
                    <div>
                        <?php if (isset($errors['form'])): ?>
                            <p><?= $h($errors['form']) ?></p>
                        <?php else: ?>
                            <p><strong>Please check the highlighted <?= count($errors) === 1 ? 'field' : 'fields' ?>.</strong></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" action="feedback.php#leave-feedback" class="feedback-form" novalidate data-feedback-form>
                <?= mentora_csrf_field() ?>
                <input type="hidden" name="action" value="post">
                <div class="sr-only" aria-hidden="true">
                    <label for="website">Leave this field empty</label>
                    <input id="website" name="website" tabindex="-1" autocomplete="off">
                </div>

                <fieldset class="star-input<?= isset($errors['rating']) ? ' has-error' : '' ?>" data-field="rating"
                    <?= isset($errors['rating']) ? 'aria-describedby="rating-error"' : '' ?>>
                    <legend>Your rating <span class="feedback-required" aria-hidden="true">*</span></legend>
                    <div class="star-input-row">
                        <div class="star-input-options">
                            <?php for ($stars = 5; $stars >= 1; $stars--): ?>
                                <input type="radio" id="rating-<?= $stars ?>" name="rating" value="<?= $stars ?>"
                                    data-label="<?= $h(MENTORA_FEEDBACK_LABELS[$stars]) ?>"
                                    <?= $formData['rating'] === $stars ? 'checked' : '' ?> required>
                                <label for="rating-<?= $stars ?>" title="<?= $h(MENTORA_FEEDBACK_LABELS[$stars]) ?>">
                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m12 2.8 2.8 5.8 6.3.9-4.6 4.4 1.1 6.3L12 17.2l-5.6 3 1.1-6.3-4.6-4.4 6.3-.9L12 2.8Z"/></svg>
                                    <span class="sr-only"><?= $stars ?> <?= $stars === 1 ? 'star' : 'stars' ?>, <?= $h(MENTORA_FEEDBACK_LABELS[$stars]) ?></span>
                                </label>
                            <?php endfor; ?>
                        </div>
                        <span class="star-input-text<?= $formData['rating'] ? ' is-chosen' : '' ?>" data-rating-text aria-live="polite">
                            <?= $formData['rating'] ? $h(MENTORA_FEEDBACK_LABELS[$formData['rating']]) : 'Tap a star to rate' ?>
                        </span>
                    </div>
                    <p class="feedback-error" id="rating-error" data-error-for="rating"><?= $h($errors['rating'] ?? '') ?></p>
                </fieldset>

                <div class="feedback-fields">
                    <div class="feedback-field<?= isset($errors['name']) ? ' has-error' : '' ?>" data-field="name">
                        <label for="feedback-name">Name <span class="feedback-required" aria-hidden="true">*</span></label>
                        <input id="feedback-name" name="name" value="<?= $h($formData['name']) ?>" autocomplete="name"
                            required maxlength="100" placeholder="e.g. Maria Santos"<?= $fieldState('name') ?>>
                        <p class="feedback-error" id="name-error" data-error-for="name"><?= $h($errors['name'] ?? '') ?></p>
                    </div>
                    <div class="feedback-field<?= isset($errors['email']) ? ' has-error' : '' ?>" data-field="email">
                        <label for="feedback-email">Email <em>(optional, private)</em></label>
                        <input id="feedback-email" type="email" name="email" value="<?= $h($formData['email']) ?>"
                            autocomplete="email" inputmode="email" maxlength="160" placeholder="you@example.com"<?= $fieldState('email') ?>>
                        <p class="feedback-error" id="email-error" data-error-for="email"><?= $h($errors['email'] ?? '') ?></p>
                    </div>
                </div>

                <div class="feedback-field<?= isset($errors['comment']) ? ' has-error' : '' ?>" data-field="comment">
                    <label for="feedback-comment">Your feedback <span class="feedback-required" aria-hidden="true">*</span></label>
                    <textarea id="feedback-comment" name="comment" rows="5" required minlength="5" maxlength="1000"
                        placeholder="What went well? What could be better?"<?= isset($errors['comment'])
                            ? ' aria-invalid="true" aria-describedby="comment-error comment-hint"'
                            : ' aria-describedby="comment-hint"' ?>><?= $h($formData['comment']) ?></textarea>
                    <div class="feedback-field-meta">
                        <p class="feedback-error" id="comment-error" data-error-for="comment"><?= $h($errors['comment'] ?? '') ?></p>
                        <span class="feedback-counter" id="comment-hint" data-counter>
                            <span data-count><?= mb_strlen($formData['comment']) ?></span> / 1,000
                        </span>
                    </div>
                </div>

                <div class="feedback-form-actions">
                    <button class="button feedback-submit" type="submit" data-submit>
                        <span data-submit-label>Submit feedback</span>
                        <svg class="feedback-submit-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 12 20 4l-4 16-4-7-8-1Z"/><path d="m12 13 8-9"/></svg>
                        <span class="feedback-spinner" aria-hidden="true"></span>
                    </button>
                    <p class="feedback-form-note">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                        Posted right away. We never show your email.
                    </p>
                </div>
            </form>
        </div>

        <?= mentora_feedback_summary_card($summary) ?>
    </section>

    <section class="section pale feedback-reviews" id="reviews" aria-labelledby="reviews-title">
        <div class="review-head">
            <div>
                <p class="eyebrow">Reviews</p>
                <h2 id="reviews-title">What people <em>are saying.</em></h2>
            </div>
            <?php if ($summary['total']): ?>
                <p class="review-head-meta">
                    <?= mentora_feedback_stars($summary['average']) ?>
                    <span><strong><?= $h(mentora_feedback_format($summary['average'])) ?></strong> from <?= (int) $summary['total'] ?> <?= $summary['total'] === 1 ? 'review' : 'reviews' ?></span>
                </p>
            <?php endif; ?>
        </div>

        <?php if (isset($_GET['removed'])): ?>
            <div class="feedback-alert is-success" role="status" data-flash="removed">
                <?= $alertIcon['success'] ?>
                <div><p>The review was removed.</p></div>
            </div>
        <?php endif; ?>

        <?php if ($reviews): ?>
            <?php if (count($reviews) > 1): ?>
                <div class="review-toolbar">
                    <div class="review-filters" role="group" aria-label="Filter reviews by rating">
                        <button type="button" class="review-chip is-active" data-filter="all" aria-pressed="true">
                            All <span class="review-chip-count"><?= count($reviews) ?></span>
                        </button>
                        <?php foreach ($listedCounts as $stars => $count): ?>
                            <button type="button" class="review-chip" data-filter="<?= $stars ?>" aria-pressed="false"
                                aria-label="<?= $stars ?> <?= $stars === 1 ? 'star' : 'stars' ?>, <?= $count ?> <?= $count === 1 ? 'review' : 'reviews' ?>"
                                <?= $count === 0 ? 'disabled' : '' ?>>
                                <?= $stars ?> <span class="review-chip-star" aria-hidden="true">★</span>
                                <span class="review-chip-count" aria-hidden="true"><?= $count ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <label class="review-sort">
                        <span>Sort by</span>
                        <select data-review-sort>
                            <option value="newest">Newest first</option>
                            <option value="oldest">Oldest first</option>
                            <option value="highest">Highest rated</option>
                            <option value="lowest">Lowest rated</option>
                        </select>
                    </label>
                </div>
            <?php endif; ?>

            <p class="review-status" data-review-status aria-live="polite">
                <?php if ($summary['total'] > count($reviews)): ?>
                    Showing the latest <?= count($reviews) ?> of <?= (int) $summary['total'] ?> reviews.
                <?php endif; ?>
            </p>

            <div class="review-grid" data-review-grid>
                <?php foreach ($reviews as $index => $review):
                    $removeForm = '';
                    if ($isAdmin) {
                        $removeForm = '<form class="review-remove" method="post" action="feedback.php#reviews">'
                            . mentora_csrf_field()
                            . '<input type="hidden" name="action" value="remove">'
                            . '<input type="hidden" name="feedback_id" value="' . (int) $review['id'] . '">'
                            . '<button type="submit" aria-label="Remove this review" title="Remove (HR only)"'
                            . ' data-confirm-title="Remove this review?" data-confirm="It will no longer be shown on Mentora."'
                            . ' data-confirm-action="Remove" data-confirm-tone="danger">×</button></form>';
                    } ?>
                    <div class="review-item" data-rating="<?= (int) $review['rating'] ?>" data-order="<?= (int) $index ?>">
                        <?= mentora_feedback_card($review, $removeForm) ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="review-empty is-boxed review-none" data-review-none hidden>
                No reviews with this rating yet.
                <button type="button" class="review-reset" data-review-reset>Show all reviews</button>
            </p>

            <div class="review-more-wrap">
                <button type="button" class="button review-more" data-review-more hidden>
                    Show more reviews
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>
                </button>
            </div>
        <?php else: ?>
            <div class="review-empty is-boxed review-first">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-4.9A8 8 0 1 1 21 12Z"/></svg>
                <p><strong>No reviews yet.</strong> Yours could be the first!</p>
                <a class="button feedback-hero-primary" href="#leave-feedback">Write the first review</a>
            </div>
        <?php endif; ?>
    </section>
</main>

<script>
    (() => {
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // Drop ?sent=1 / ?removed=1 from the address so a refresh doesn't show the message again.
        if (document.querySelector('[data-flash]') && window.history.replaceState) {
            window.history.replaceState(null, '', window.location.pathname + window.location.hash);
        }

        /* ---------- Star rating ---------- */
        const ratingText = document.querySelector('[data-rating-text]');
        const ratingInputs = [...document.querySelectorAll('.star-input-options input')];
        const showChosen = () => {
            const chosen = ratingInputs.find((input) => input.checked);
            ratingText.textContent = chosen ? chosen.dataset.label : 'Tap a star to rate';
            ratingText.classList.toggle('is-chosen', Boolean(chosen));
        };

        ratingInputs.forEach((input) => {
            const label = document.querySelector(`label[for="${input.id}"]`);
            input.addEventListener('change', () => {
                showChosen();
                clearError('rating');
            });
            // Preview the label while hovering a star.
            label.addEventListener('mouseenter', () => { ratingText.textContent = input.dataset.label; });
            label.addEventListener('mouseleave', showChosen);
            // The stars are listed 5 -> 1 and drawn in reverse, so make the arrow keys follow what you see.
            input.addEventListener('keydown', (event) => {
                const step = { ArrowRight: 1, ArrowUp: 1, ArrowLeft: -1, ArrowDown: -1 }[event.key];
                if (!step) return;
                event.preventDefault();
                const next = ratingInputs.find((other) => Number(other.value) === Number(input.value) + step);
                if (next) {
                    next.checked = true;
                    next.focus();
                    next.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        });

        /* ---------- Form checks (the server checks the same rules) ---------- */
        const form = document.querySelector('[data-feedback-form]');
        const fields = {
            rating: () => ratingInputs.some((input) => input.checked) ? '' : 'Please choose a rating from 1 to 5 stars.',
            name: () => {
                const value = form.elements.name.value.trim();
                return value === '' || value.length > 100 ? 'Please enter your name (up to 100 characters).' : '';
            },
            email: () => {
                const input = form.elements.email;
                return input.value.trim() !== '' && !input.checkValidity()
                    ? 'Please enter a valid email address, or leave it empty.' : '';
            },
            comment: () => {
                const length = [...form.elements.comment.value.trim()].length;
                return length < 5 || length > 1000 ? 'Please write a short comment (5 to 1,000 characters).' : '';
            },
        };

        function setError(name, message) {
            const wrap = form.querySelector(`[data-field="${name}"]`);
            const slot = form.querySelector(`[data-error-for="${name}"]`);
            const control = name === 'rating' ? wrap : form.elements[name];
            wrap.classList.toggle('has-error', message !== '');
            slot.textContent = message;
            if (name === 'rating') {
                message ? wrap.setAttribute('aria-describedby', 'rating-error') : wrap.removeAttribute('aria-describedby');
                return;
            }
            control.setAttribute('aria-invalid', message ? 'true' : 'false');
            const describedBy = [message ? `${name}-error` : '', name === 'comment' ? 'comment-hint' : ''].filter(Boolean).join(' ');
            describedBy ? control.setAttribute('aria-describedby', describedBy) : control.removeAttribute('aria-describedby');
        }

        function clearError(name) {
            if (form.querySelector(`[data-field="${name}"]`).classList.contains('has-error')) {
                setError(name, fields[name]());
            }
        }

        ['name', 'email', 'comment'].forEach((name) => {
            const input = form.elements[name];
            input.addEventListener('input', () => clearError(name));
            input.addEventListener('blur', () => {
                if (input.value.trim() !== '') setError(name, fields[name]());
            });
        });

        // Live character count for the comment.
        const comment = form.elements.comment;
        const counter = form.querySelector('[data-counter]');
        const count = counter.querySelector('[data-count]');
        const updateCount = () => {
            const length = [...comment.value].length;
            count.textContent = length.toLocaleString();
            counter.classList.toggle('is-near', length >= 900 && length < 1000);
            counter.classList.toggle('is-full', length >= 1000);
        };
        comment.addEventListener('input', updateCount);
        updateCount();

        const submit = form.querySelector('[data-submit]');
        const submitLabel = form.querySelector('[data-submit-label]');
        const resetSubmit = () => {
            submit.disabled = false;
            submit.classList.remove('is-loading');
            submit.removeAttribute('aria-busy');
            submitLabel.textContent = 'Submit feedback';
        };

        form.addEventListener('submit', (event) => {
            const problems = Object.keys(fields).filter((name) => {
                const message = fields[name]();
                setError(name, message);
                return message !== '';
            });
            if (problems.length) {
                event.preventDefault();
                const first = problems[0] === 'rating'
                    ? (ratingInputs.find((input) => input.checked) || ratingInputs[ratingInputs.length - 1])
                    : form.elements[problems[0]];
                first.focus({ preventScroll: true });
                form.querySelector(`[data-field="${problems[0]}"]`)
                    .scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'center' });
                return;
            }
            // Stop double posts while the page is sending.
            submit.disabled = true;
            submit.classList.add('is-loading');
            submit.setAttribute('aria-busy', 'true');
            submitLabel.textContent = 'Sending…';
        });
        // Coming back with the browser's Back button restores the page as it was: make the button usable again.
        window.addEventListener('pageshow', (event) => { if (event.persisted) resetSubmit(); });

        // After a server-side error, start at the first field that needs fixing.
        const firstServerError = form.querySelector('.has-error');
        if (firstServerError) {
            const target = firstServerError.matches('.star-input')
                ? ratingInputs[ratingInputs.length - 1]
                : firstServerError.querySelector('input, textarea');
            target?.focus({ preventScroll: true });
        }

        /* ---------- Reviews: filter, sort, show more, read more ---------- */
        const grid = document.querySelector('[data-review-grid]');
        if (!grid) return;

        const PAGE = 9;
        const items = [...grid.querySelectorAll('.review-item')];
        const chips = [...document.querySelectorAll('.review-chip')];
        const sort = document.querySelector('[data-review-sort]');
        const status = document.querySelector('[data-review-status]');
        const more = document.querySelector('[data-review-more]');
        const none = document.querySelector('[data-review-none]');
        const baseStatus = status.textContent.trim();
        let filter = 'all';
        let shown = PAGE;

        const sorters = {
            newest: (a, b) => a.dataset.order - b.dataset.order,
            oldest: (a, b) => b.dataset.order - a.dataset.order,
            highest: (a, b) => b.dataset.rating - a.dataset.rating || a.dataset.order - b.dataset.order,
            lowest: (a, b) => a.dataset.rating - b.dataset.rating || a.dataset.order - b.dataset.order,
        };

        // Long reviews are cut to a few lines with a "Read more" button.
        const fitQuote = (item) => {
            if (item.dataset.measured) return;
            const quote = item.querySelector('blockquote');
            if (!quote) return;
            item.dataset.measured = '1';
            quote.classList.add('is-clamped');
            if (quote.scrollHeight <= quote.clientHeight + 2) {
                quote.classList.remove('is-clamped');
                return;
            }
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'review-toggle';
            toggle.textContent = 'Read more';
            toggle.setAttribute('aria-expanded', 'false');
            toggle.addEventListener('click', () => {
                const open = quote.classList.toggle('is-clamped') === false;
                toggle.textContent = open ? 'Show less' : 'Read more';
                toggle.setAttribute('aria-expanded', String(open));
            });
            quote.after(toggle);
        };

        const render = (animateFrom = Infinity) => {
            const matching = items
                .filter((item) => filter === 'all' || item.dataset.rating === filter)
                .sort(sorters[sort ? sort.value : 'newest']);
            const matchSet = new Set(matching);

            items.forEach((item) => { item.hidden = true; });
            matching.forEach((item, index) => {
                grid.appendChild(item);
                item.hidden = index >= shown;
                item.classList.toggle('is-entering', !reducedMotion && index >= animateFrom && index < shown);
                if (!item.hidden) fitQuote(item);
            });
            items.forEach((item) => { if (!matchSet.has(item)) grid.appendChild(item); });

            const visible = Math.min(shown, matching.length);
            none.hidden = matching.length !== 0;
            more.hidden = visible >= matching.length;
            more.firstChild.textContent = `Show more reviews (${matching.length - visible} left) `;

            if (filter === 'all' && visible >= matching.length) {
                status.textContent = baseStatus;
            } else {
                const label = filter === 'all' ? '' : ` ${filter}-star`;
                status.textContent = `Showing ${visible} of ${matching.length}${label} ${matching.length === 1 ? 'review' : 'reviews'}.`;
            }
        };

        const setFilter = (value) => {
            filter = value;
            shown = PAGE;
            chips.forEach((chip) => {
                const active = chip.dataset.filter === value;
                chip.classList.toggle('is-active', active);
                chip.setAttribute('aria-pressed', String(active));
            });
            render();
        };

        chips.forEach((chip) => chip.addEventListener('click', () => setFilter(chip.dataset.filter)));
        sort?.addEventListener('change', () => { shown = PAGE; render(); });
        document.querySelector('[data-review-reset]')?.addEventListener('click', () => setFilter('all'));
        more.addEventListener('click', () => {
            const from = shown;
            shown += PAGE;
            render(from);
            // Move focus to the first newly shown review so keyboard users continue from there.
            const next = [...grid.querySelectorAll('.review-item:not([hidden])')][from];
            next?.setAttribute('tabindex', '-1');
            next?.focus({ preventScroll: true });
        });

        // The bars in the "Overall rating" card also filter the reviews.
        document.querySelectorAll('.rating-bars li').forEach((row) => {
            const stars = row.textContent.trim().charAt(0);
            const chip = chips.find((item) => item.dataset.filter === stars);
            if (!chip || chip.disabled) return;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'rating-bar-link';
            button.setAttribute('aria-label', `Show ${stars}-star reviews`);
            button.addEventListener('click', () => {
                setFilter(stars);
                document.getElementById('reviews').scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth' });
            });
            row.classList.add('is-linked');
            row.appendChild(button);
        });

        // Re-measure long reviews if the layout width changes.
        let resizeTimer;
        let lastWidth = window.innerWidth;
        window.addEventListener('resize', () => {
            if (window.innerWidth === lastWidth) return;
            lastWidth = window.innerWidth;
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                items.forEach((item) => {
                    item.querySelector('.review-toggle')?.remove();
                    item.querySelector('blockquote')?.classList.remove('is-clamped');
                    delete item.dataset.measured;
                });
                render();
            }, 200);
        });

        render();
    })();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

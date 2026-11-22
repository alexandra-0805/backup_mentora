<?php
/**
 * Feedback: public page with the overall rating, the "Leave feedback" form, and every review.
 * Linked from the "What people are saying" section on the landing page and the top bar.
 * Helpers and rules: includes/feedback.php.
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/feedback.php';

$viewer = mentora_current_user();
$isAdmin = $viewer !== null && $viewer['role'] === 'admin';
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
        $errors[] = 'Your session expired. Please send the form again.';
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
            $errors[] = 'You have shared feedback several times recently. Please try again later.';
        } else {
            if ($formData['rating'] < 1 || $formData['rating'] > 5) {
                $errors[] = 'Please choose a rating from 1 to 5 stars.';
            }
            if ($formData['name'] === '' || mb_strlen($formData['name']) > MENTORA_FEEDBACK_LIMITS['name']) {
                $errors[] = 'Please enter your name (up to 100 characters).';
            }
            if ($formData['email'] !== '' && (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)
                || mb_strlen($formData['email']) > MENTORA_FEEDBACK_LIMITS['email'])) {
                $errors[] = 'Please enter a valid email address, or leave it empty.';
            }
            $commentLength = mb_strlen($formData['comment']);
            if ($commentLength < MENTORA_FEEDBACK_MIN_COMMENT || $commentLength > MENTORA_FEEDBACK_LIMITS['comment']) {
                $errors[] = 'Please write a short comment (5 to 1,000 characters).';
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

$pageTitle = 'Feedback | Mentora';
$bodyClass = 'landing-page feedback-page';
$headerStyle = 'landing-page';

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
        </div>
    </section>

    <section class="section feedback-layout">
        <?= mentora_feedback_summary_card($summary) ?>

        <div class="feedback-form-card" id="leave-feedback">
            <p class="eyebrow">Leave feedback</p>
            <h2>How was your experience?</h2>
            <p class="feedback-form-intro">Your name, rating, and comment are shown publicly. Your email stays private.</p>

            <?php if (isset($_GET['sent'])): ?>
                <p class="feedback-alert is-success" role="status">Thank you for your feedback! Your review is now live.</p>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="feedback-alert is-error" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <p><?= $h($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="feedback.php#leave-feedback" class="feedback-form">
                <?= mentora_csrf_field() ?>
                <input type="hidden" name="action" value="post">
                <div class="sr-only" aria-hidden="true">
                    <label for="website">Leave this field empty</label>
                    <input id="website" name="website" tabindex="-1" autocomplete="off">
                </div>

                <fieldset class="star-input">
                    <legend>Your rating</legend>
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
                        <span class="star-input-text" data-rating-text aria-live="polite">
                            <?= $formData['rating'] ? $h(MENTORA_FEEDBACK_LABELS[$formData['rating']]) : 'Tap a star' ?>
                        </span>
                    </div>
                </fieldset>

                <div class="feedback-fields">
                    <label class="feedback-field">
                        <span>Name</span>
                        <input name="name" value="<?= $h($formData['name']) ?>" autocomplete="name" required maxlength="100">
                    </label>
                    <label class="feedback-field">
                        <span>Email <em>(optional, private)</em></span>
                        <input type="email" name="email" value="<?= $h($formData['email']) ?>" autocomplete="email" maxlength="160">
                    </label>
                </div>

                <label class="feedback-field">
                    <span>Your feedback</span>
                    <textarea name="comment" rows="5" required minlength="5" maxlength="1000"
                        placeholder="What went well? What could be better?"><?= $h($formData['comment']) ?></textarea>
                </label>

                <button class="button feedback-submit" type="submit">
                    Submit feedback
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 12 20 4l-4 16-4-7-8-1Z"/><path d="m12 13 8-9"/></svg>
                </button>
            </form>
        </div>
    </section>

    <section class="section pale feedback-reviews" id="reviews" aria-labelledby="reviews-title">
        <p class="eyebrow">Reviews</p>
        <h2 id="reviews-title">What people <em>are saying.</em></h2>

        <?php if (isset($_GET['removed'])): ?>
            <p class="feedback-alert is-success" role="status">The review was removed.</p>
        <?php endif; ?>

        <?php if ($reviews): ?>
            <div class="review-grid">
                <?php foreach ($reviews as $review):
                    $removeForm = '';
                    if ($isAdmin) {
                        $removeForm = '<form class="review-remove" method="post" action="feedback.php#reviews">'
                            . mentora_csrf_field()
                            . '<input type="hidden" name="action" value="remove">'
                            . '<input type="hidden" name="feedback_id" value="' . (int) $review['id'] . '">'
                            . '<button type="submit" aria-label="Remove this review" title="Remove (HR only)"'
                            . ' data-confirm-title="Remove this review?" data-confirm="It will no longer be shown on Mentora."'
                            . ' data-confirm-action="Remove" data-confirm-tone="danger">×</button></form>';
                    }
                    echo mentora_feedback_card($review, $removeForm);
                endforeach; ?>
            </div>
        <?php else: ?>
            <p class="review-empty">No reviews yet. Yours could be the first!</p>
        <?php endif; ?>
    </section>
</main>

<script>
    // Star rating: show the chosen star's label ("Excellent") next to the stars.
    (() => {
        const text = document.querySelector('[data-rating-text]');
        document.querySelectorAll('.star-input-options input').forEach((input) => {
            input.addEventListener('change', () => {
                text.textContent = input.dataset.label;
            });
        });
    })();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

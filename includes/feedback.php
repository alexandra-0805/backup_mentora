<?php

/**
 * Public ratings and feedback about Mentora.
 *
 *   feedback.php   full page: overall rating, the "Leave feedback" form, and every review
 *   index.php      "What people are saying": overall rating + the latest reviews
 *
 * Anyone can leave feedback (no account needed). Name, rating and comment are public; the email is
 * private (only stored, so HR can follow up). HR admins can remove a review. Table: site_feedback.
 */

declare(strict_types=1);

const MENTORA_FEEDBACK_LABELS = [1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very good', 5 => 'Excellent'];
const MENTORA_FEEDBACK_LIMITS = ['name' => 100, 'email' => 160, 'comment' => 1000];
const MENTORA_FEEDBACK_MIN_COMMENT = 5;
// At most this many reviews from the same network per hour (stops repeat or bot posting).
const MENTORA_FEEDBACK_PER_HOUR = 3;

function mentora_feedback_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Average, total and how many reviews gave each star count (5 down to 1). */
function mentora_feedback_summary(PDO $pdo): array
{
    $counts = $pdo->query('SELECT rating, COUNT(*) FROM site_feedback GROUP BY rating')->fetchAll(PDO::FETCH_KEY_PAIR);
    $distribution = [];
    $total = 0;
    $sum = 0;
    for ($stars = 5; $stars >= 1; $stars--) {
        $distribution[$stars] = (int) ($counts[$stars] ?? 0);
        $total += $distribution[$stars];
        $sum += $stars * $distribution[$stars];
    }

    return [
        'average' => $total ? round($sum / $total, 1) : 0.0,
        'total' => $total,
        'distribution' => $distribution,
    ];
}

/** Newest reviews first. */
function mentora_feedback_list(PDO $pdo, int $limit): array
{
    return $pdo->query('SELECT id, name, rating, comment, created_at FROM site_feedback
        ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit))->fetchAll();
}

/** "4.5" or "5" */
function mentora_feedback_format(float $rating): string
{
    return rtrim(rtrim(number_format($rating, 1), '0'), '.');
}

/**
 * Five stars: an outline row with a filled row on top, cut to the rating's width (so 4.3 fills 86%).
 */
function mentora_feedback_stars(float $rating, string $class = ''): string
{
    $star = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m12 2.8 2.8 5.8 6.3.9-4.6 4.4 1.1 6.3L12 17.2l-5.6 3 1.1-6.3-4.6-4.4 6.3-.9L12 2.8Z"/></svg>';
    $row = str_repeat($star, 5);
    $percent = max(0, min(100, $rating / 5 * 100));

    return '<span class="rating-stars ' . mentora_feedback_h($class) . '" role="img" aria-label="'
        . mentora_feedback_h(mentora_feedback_format($rating) . ' out of 5 stars') . '">'
        . '<span class="rating-stars-empty">' . $row . '</span>'
        . '<span class="rating-stars-fill" style="width: ' . round($percent, 1) . '%">' . $row . '</span>'
        . '</span>';
}

/** The "Overall rating" card. */
function mentora_feedback_summary_card(array $summary): string
{
    ob_start();
    ?>
    <div class="rating-summary">
        <p class="eyebrow">Overall rating</p>
        <div class="rating-summary-score">
            <span class="rating-average"><?= $summary['total'] ? mentora_feedback_h(mentora_feedback_format($summary['average'])) : '–' ?></span>
            <div>
                <?= mentora_feedback_stars($summary['average']) ?>
                <small>
                    <?php if ($summary['total']): ?>
                        Based on <?= (int) $summary['total'] ?> <?= $summary['total'] === 1 ? 'review' : 'reviews' ?>
                    <?php else: ?>
                        No ratings yet
                    <?php endif; ?>
                </small>
            </div>
        </div>
        <?php if ($summary['total']): ?>
            <ul class="rating-bars" aria-label="Rating breakdown">
                <?php foreach ($summary['distribution'] as $stars => $count):
                    $percent = (int) round($count / $summary['total'] * 100); ?>
                    <li>
                        <span><?= (int) $stars ?> <span aria-hidden="true">★</span></span>
                        <span class="rating-bar-track" aria-hidden="true"><span style="width: <?= $percent ?>%"></span></span>
                        <span><?= (int) $count ?><span class="sr-only"> <?= $count === 1 ? 'review' : 'reviews' ?> with <?= (int) $stars ?> <?= $stars === 1 ? 'star' : 'stars' ?></span></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p class="rating-summary-note">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 11a8 8 0 0 0-14.9-3M4 4v4h4M4 13a8 8 0 0 0 14.9 3M20 20v-4h-4"/></svg>
            Updates automatically with every new review.
        </p>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * One review card. $removeForm: HTML of the HR "remove" button (feedback.php only), or ''.
 */
function mentora_feedback_card(array $item, string $removeForm = ''): string
{
    $name = (string) $item['name'];
    $date = strtotime((string) $item['created_at']);

    return '<article class="review-card">'
        . '<div class="review-card-top">' . mentora_feedback_stars((float) $item['rating']) . $removeForm . '</div>'
        . '<blockquote>' . nl2br(mentora_feedback_h((string) $item['comment'])) . '</blockquote>'
        . '<footer>'
        . '<span class="review-avatar" aria-hidden="true">' . mentora_feedback_h(mb_strtoupper(mb_substr($name, 0, 1))) . '</span>'
        . '<span><strong>' . mentora_feedback_h($name) . '</strong>'
        . '<small><time datetime="' . date('c', $date) . '">' . date('M j, Y', $date) . '</time></small></span>'
        . '</footer>'
        . '</article>';
}

/** Hash of the visitor's IP address (the raw IP is never stored). */
function mentora_feedback_ip_hash(): string
{
    return hash('sha256', 'mentora-feedback|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
}

/** Tell every HR admin about a new review (workspace notification linking to feedback.php). */
function mentora_feedback_notify_admins(PDO $pdo, string $name, int $rating): void
{
    $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
    $insert = $pdo->prepare('INSERT INTO notifications (user_id, title, body, link) VALUES (?, ?, ?, ?)');
    foreach ($admins as $adminId) {
        $insert->execute([
            (int) $adminId,
            'New ' . $rating . '-star feedback from ' . $name,
            'Rated Mentora ' . $rating . '/5 (' . MENTORA_FEEDBACK_LABELS[$rating] . '). It is live on the Feedback page.',
            'feedback.php',
        ]);
    }
}

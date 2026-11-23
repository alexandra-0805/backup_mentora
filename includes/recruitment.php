<?php

/**
 * Recruitment links: how an agent brings in applicants.
 *
 *   1. The agent creates a link (agent/recruitment.php) and sends it, like a form link.
 *   2. The applicant opens it (auth/join.php): signs in, or creates an account there
 *      (username + password, or Google). The link itself is the invitation, so no HR approval wait.
 *   3. They choose Intern or Employee VA (auth/pathway.php). The link's agent is recorded as
 *      users.referred_by and becomes an intern's supervising agent.
 *   4. Interns fill the internship form and go straight to training; employees fill the form or upload
 *      a resume, which the AI reviews (ai/resume_review.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/account_invites.php';

function mentora_recruitment_url(string $code): string
{
    return mentora_app_url('auth/join.php?ref=' . rawurlencode($code));
}

/** Create a link for an agent; $days = null means it doesn't expire. Returns the code. */
function mentora_create_recruitment_link(PDO $pdo, int $agentId, string $label, ?int $days): string
{
    $code = bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO recruitment_links (code, agent_id, label, expires_at) VALUES (?, ?, ?, ' . ($days ? 'DATE_ADD(NOW(), INTERVAL ' . (int) $days . ' DAY)' : 'NULL') . ')')
        ->execute([$code, $agentId, mb_substr(trim($label), 0, 120)]);

    return $code;
}

/** An active, unexpired link whose agent is still active, with the agent's name; or null. */
function mentora_find_recruitment_link(PDO $pdo, mixed $code): ?array
{
    if (!is_string($code) || !preg_match('/^[a-f0-9]{16}$/', $code)) {
        return null;
    }
    $query = $pdo->prepare(
        "SELECT l.*, u.first_name AS agent_first_name, CONCAT(u.first_name, ' ', u.last_name) AS agent_name, d.name AS agent_department
         FROM recruitment_links l
         JOIN users u ON u.id = l.agent_id AND u.status = 'active' AND u.role IN ('agent', 'admin')
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE l.code = ? AND l.active = 1 AND (l.expires_at IS NULL OR l.expires_at > NOW())"
    );
    $query->execute([$code]);

    return $query->fetch() ?: null;
}

/** The link the applicant opened this session (auth/join.php keeps it until they choose a pathway). */
function mentora_session_recruitment_link(PDO $pdo): ?array
{
    mentora_session_start();

    return mentora_find_recruitment_link($pdo, $_SESSION['recruitment_ref'] ?? null);
}

/**
 * Create an applicant account from a recruitment link: active straight away, pathway still to choose.
 * $password null = Google account (a random password nobody knows). Returns ['ok', 'id'] or ['ok' => false, 'error'].
 */
function mentora_join_create_account(PDO $pdo, array $link, string $first, string $last, string $email, ?string $username, ?string $password): array
{
    // One account per email (Gmail lookalikes included): never a second account for the same inbox.
    $existing = mentora_find_account_by_email($pdo, $email);
    if ($existing) {
        return ['ok' => false, 'error' => $existing['status'] === 'active'
            ? 'An account with this email already exists. Each email can only have one Mentora account, so sign in instead, and you’ll continue from this link.'
            : mentora_email_taken_message($existing)];
    }
    $pdo->prepare(
        "INSERT INTO users (first_name, last_name, email, username, pathway, pathway_chosen_at, password_hash, role, status, referred_by)
         VALUES (?, ?, ?, ?, 'employee_va', NULL, ?, 'applicant', 'active', ?)"
    )->execute([$first, $last, $email, $username, password_hash($password ?? bin2hex(random_bytes(32)), PASSWORD_DEFAULT), (int) $link['agent_id']]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE recruitment_links SET uses = uses + 1 WHERE id = ?')->execute([(int) $link['id']]);
    mentora_notify(
        $pdo,
        (int) $link['agent_id'],
        'New applicant joined: ' . trim($first . ' ' . $last),
        'They created an account with your link' . ($link['label'] !== '' ? ' “' . $link['label'] . '”' : '') . ' and are choosing Intern or Employee VA.',
        'agent/recruitment.php#joined'
    );

    return ['ok' => true, 'id' => $id];
}

/**
 * After the pathway is chosen: record the link's agent (if the person has none yet). For interns the
 * agent also becomes the supervising agent who handles them after training.
 */
function mentora_apply_referral(PDO $pdo, int $userId): void
{
    $link = mentora_session_recruitment_link($pdo);
    unset($_SESSION['recruitment_ref']);
    $person = mentora_person($pdo, $userId);
    if (!$person || $person['role'] !== 'applicant') {
        return;
    }
    $agentId = (int) ($person['referred_by'] ?: ($link['agent_id'] ?? 0));
    if ($agentId === 0) {
        return;
    }
    if (empty($person['referred_by'])) {
        $pdo->prepare('UPDATE users SET referred_by = ? WHERE id = ?')->execute([$agentId, $userId]);
        $pdo->prepare('UPDATE recruitment_links SET uses = uses + 1 WHERE id = ?')->execute([(int) $link['id']]);
    }
    $isIntern = $person['pathway'] === 'internship';
    if ($isIntern && empty($person['agent_id'])) {
        $pdo->prepare("UPDATE users SET agent_id = ? WHERE id = ? AND EXISTS (SELECT 1 FROM (SELECT id FROM users WHERE id = ? AND role = 'agent') a)")
            ->execute([$agentId, $userId, $agentId]);
    }
    mentora_notify(
        $pdo,
        $agentId,
        trim($person['first_name'] . ' ' . $person['last_name']) . ' chose ' . ($isIntern ? 'the Internship' : 'Employee VA'),
        $isIntern ? 'You are their supervising agent. They fill the internship form, then start training.' : 'They’re filling the Employee VA form or uploading a resume for the AI review.',
        'agent/recruitment.php#joined'
    );
}

/** Links for the recruitment page: one agent's, or everyone's (HR). */
function mentora_recruitment_links(PDO $pdo, ?int $agentId): array
{
    $query = $pdo->prepare(
        "SELECT l.*, CONCAT(u.first_name, ' ', u.last_name) AS agent_name
         FROM recruitment_links l JOIN users u ON u.id = l.agent_id
         WHERE (? IS NULL OR l.agent_id = ?)
         ORDER BY l.active DESC, l.created_at DESC"
    );
    $query->execute([$agentId, $agentId]);

    return $query->fetchAll();
}

/** People who joined with an agent's links (or anyone's, for HR), with where they are now. */
function mentora_referred_people(PDO $pdo, ?int $agentId): array
{
    $query = $pdo->prepare(
        "SELECT u.id, u.first_name, u.last_name, u.email, u.pathway, u.pathway_chosen_at, u.created_at, u.status,
                CONCAT(ag.first_name, ' ', ag.last_name) AS agent_name,
                a.status AS application_status, a.created_at AS applied_at,
                (SELECT r.category FROM ai_reviews r WHERE r.user_id = u.id ORDER BY r.id DESC LIMIT 1) AS ai_category,
                (SELECT r.score FROM ai_reviews r WHERE r.user_id = u.id ORDER BY r.id DESC LIMIT 1) AS ai_score,
                COALESCE(t.modules_completed, 0) AS modules_done
         FROM users u
         JOIN users ag ON ag.id = u.referred_by
         LEFT JOIN applications a ON a.id = (SELECT MAX(id) FROM applications WHERE user_id = u.id)
         LEFT JOIN training_records t ON t.user_id = u.id
         WHERE u.role = 'applicant' AND (? IS NULL OR u.referred_by = ?)
         ORDER BY u.created_at DESC"
    );
    $query->execute([$agentId, $agentId]);

    return $query->fetchAll();
}

/** Where a referred applicant is: [label, badge class]. */
function mentora_referral_stage(array $person): array
{
    $intern = $person['pathway'] === 'internship';
    return match (true) {
        $person['pathway_chosen_at'] === null => ['Choosing pathway', 'warn'],
        $person['applied_at'] === null => ['Filling the form', 'warn'],
        $intern => ['In training · ' . (int) $person['modules_done'] . '/' . count(mentora_training_modules('internship')) . ' modules', 'info'],
        $person['application_status'] === 'approved' => ['Accepted by HR', ''],
        $person['application_status'] === 'rejected' => ['Not selected', 'warn'],
        $person['ai_category'] !== null => ['AI: ' . (['suitable' => 'Suitable', 'for_review' => 'For review', 'unsuitable' => 'Flagged unsuitable'][$person['ai_category']] ?? '') . ' · ' . (int) $person['ai_score'], $person['ai_category'] === 'suitable' ? '' : 'info'],
        default => ['Waiting for AI review', 'info'],
    };
}

<?php

declare(strict_types=1);

$dbHost = getenv('MENTORA_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('MENTORA_DB_NAME') ?: 'backup_mentora_db';
$dbUser = getenv('MENTORA_DB_USER') ?: 'root';
$dbPassword = getenv('MENTORA_DB_PASSWORD') ?: '';

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbName);

try {
    $pdo = new PDO($dsn, $dbUser, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    // Mentora runs on Philippine time: PHP dates and MySQL NOW() / CURDATE() / TIMESTAMP columns agree
    // (attendance, report dates) whatever time zone the server itself uses.
    date_default_timezone_set('Asia/Manila');
    $pdo->exec("SET time_zone = '+08:00'");

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            first_name VARCHAR(80) NOT NULL,
            last_name VARCHAR(80) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            pathway ENUM("employee_va", "internship") NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM("applicant", "agent", "admin") NOT NULL DEFAULT "applicant",
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS password_resets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_password_resets_user
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS departments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            description VARCHAR(255) NOT NULL DEFAULT "",
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $userColumns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('department_id', $userColumns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN department_id INT UNSIGNED NULL AFTER role');
    }
    if (!in_array('user_code', $userColumns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN user_code VARCHAR(20) NULL AFTER id, ADD UNIQUE KEY uq_users_user_code (user_code)');
    }
    // Profile photo, relative to the app root (uploads/avatars/...); NULL shows initials instead.
    if (!in_array('avatar_path', $userColumns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN avatar_path VARCHAR(190) NULL AFTER department_id');
    }
    // Sign-in name chosen when the applicant confirms their account (stored lowercase; email still works too).
    if (!in_array('username', $userColumns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN username VARCHAR(40) NULL AFTER email, ADD UNIQUE KEY uq_users_username (username)');
    }
    // Application approval: pending (waiting for HR) -> approved (confirmation link sent) -> active (username
    // and password created), or rejected. Only active accounts can sign in. Existing accounts are active.
    if (!in_array('status', $userColumns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('pending', 'approved', 'active', 'rejected') NOT NULL DEFAULT 'active' AFTER role");
    }
    // One-time confirmation links an HR admin sends to approved applicants (auth/activate.php).
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS account_invites (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            created_by INT UNSIGNED NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_account_invites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_account_invites_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    // Email confirmation right after applying (auth/verify_email.php): NULL = the applicant hasn't clicked
    // the link yet. Everyone who existed before this step counts as confirmed.
    if (!in_array('email_verified_at', $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN), true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER email');
        $pdo->exec('UPDATE users SET email_verified_at = created_at');
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS email_verifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_email_verifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    // Give every user without a code one: role prefix + their id, e.g. ADM-0001, AGT-0002, INT-0003, EMP-0004.
    // Covers app sign-ups and rows added by hand (e.g. in phpMyAdmin). The id part keeps codes unique.
    $pdo->exec(
        "UPDATE users
         SET user_code = CONCAT(
             CASE WHEN role = 'admin' THEN 'ADM' WHEN role = 'agent' THEN 'AGT' WHEN pathway = 'internship' THEN 'INT' ELSE 'EMP' END,
             '-', IF(id < 10000, LPAD(id, 4, '0'), id))
         WHERE user_code IS NULL"
    );
    // Mentora's starting departments, added only to an empty list; HR adds, edits and deletes them
    // in admin/department.php (so a deleted department doesn't come back).
    if ((int) $pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn() === 0) {
        $pdo->exec(
            'INSERT IGNORE INTO departments (name, description) VALUES
                ("Web Developer", "Websites, web apps, landing pages and site maintenance for clients"),
                ("Marketing Services", "Social media, content, email campaigns and online advertising"),
                ("Accounting", "Bookkeeping, invoicing, payroll support and financial reports"),
                ("Psychotherapist", "Client intake, scheduling and admin support for therapy practices")'
        );
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS training_records (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            modules_completed TINYINT UNSIGNED NOT NULL DEFAULT 0,
            modules_total TINYINT UNSIGNED NOT NULL DEFAULT 12,
            hours_logged DECIMAL(6,1) NOT NULL DEFAULT 0,
            status ENUM("in_training", "completed", "on_hold") NOT NULL DEFAULT "in_training",
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_training_user (user_id),
            CONSTRAINT fk_training_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS evaluations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            review_type VARCHAR(80) NOT NULL DEFAULT "Midpoint evaluation",
            score DECIMAL(3,1) NULL,
            status ENUM("draft", "pending", "published") NOT NULL DEFAULT "pending",
            due_date DATE NULL,
            feedback TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_evaluations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS documents (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            owner_id INT UNSIGNED NULL,
            category VARCHAR(80) NOT NULL DEFAULT "Training resources",
            status ENUM("published", "draft", "in_review") NOT NULL DEFAULT "draft",
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_documents_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS applications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            mobile VARCHAR(40) NULL,
            location VARCHAR(190) NULL,
            school VARCHAR(190) NULL,
            course VARCHAR(190) NULL,
            required_hours SMALLINT UNSIGNED NULL,
            desired_role VARCHAR(190) NULL,
            start_date DATE NULL,
            interest VARCHAR(120) NULL,
            skills TEXT NULL,
            status ENUM("pending", "approved", "rejected") NOT NULL DEFAULT "pending",
            reviewed_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_applications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            title VARCHAR(190) NOT NULL,
            body TEXT NOT NULL,
            read_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    // Where a notification leads when clicked (a page inside Mentora, relative to the app root,
    // e.g. "admin/applications.php?view=pending"); NULL = not clickable. Opened via account/notification.php.
    $notificationColumns = $pdo->query('SHOW COLUMNS FROM notifications')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('link', $notificationColumns, true)) {
        $pdo->exec('ALTER TABLE notifications ADD COLUMN link VARCHAR(255) NULL AFTER body');
        $pdo->exec("UPDATE notifications SET link = 'admin/applications.php?view=pending' WHERE title LIKE 'New application:%'");
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sender_id INT UNSIGNED NULL,
            recipient_id INT UNSIGNED NULL,
            subject VARCHAR(190) NOT NULL,
            body TEXT NOT NULL,
            read_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_messages_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    // Files, images and GIFs sent with a message (account/messages.php). Uploaded files live in
    // uploads/messages/ (not directly reachable) and are served by account/attachment.php after an
    // access check; a GIF from GIPHY is stored as its URL.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS message_attachments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            message_id BIGINT UNSIGNED NOT NULL,
            kind ENUM("image", "file", "gif") NOT NULL,
            stored_name VARCHAR(80) NULL,
            url VARCHAR(500) NULL,
            original_name VARCHAR(190) NOT NULL DEFAULT "",
            mime VARCHAR(120) NOT NULL DEFAULT "",
            size INT UNSIGNED NOT NULL DEFAULT 0,
            width SMALLINT UNSIGNED NULL,
            height SMALLINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_message_attachments_message (message_id),
            CONSTRAINT fk_message_attachments_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    // Who has read an announcement (a message with recipient_id NULL goes to everyone, so its
    // own read_at can't say which person read it). Direct messages keep using messages.read_at.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS message_reads (
            message_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (message_id, user_id),
            CONSTRAINT fk_message_reads_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
            CONSTRAINT fk_message_reads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // ---------- Workflow (includes/workflow.php) ----------
    $userColumns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    // Supervising agent of an intern (set by HR in admin/user_edit.php or by an agent in agent/edit_intern.php).
    if (!in_array('agent_id', $userColumns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN agent_id INT UNSIGNED NULL AFTER department_id');
    }
    // Availability the person sets on their profile (Active / Busy / Offline). Separate from the account status.
    if (!in_array('presence', $userColumns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN presence ENUM('active', 'busy', 'offline') NOT NULL DEFAULT 'active' AFTER status");
    }
    // Last time the person was seen in Mentora (any signed-in page, or the heartbeat while a page is open).
    // Someone not seen for MENTORA_ONLINE_MINUTES shows as Offline whatever status they chose; signing out clears it.
    if (!in_array('last_seen_at', $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN), true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN last_seen_at DATETIME NULL AFTER presence');
    }
    // "disabled": HR turned off an account's access (Users & access). Like pending/rejected, it can't sign in.
    $statusType = (string) ($pdo->query("SHOW COLUMNS FROM users LIKE 'status'")->fetch()['Type'] ?? '');
    if ($statusType !== '' && !str_contains($statusType, "'disabled'")) {
        $pdo->exec("ALTER TABLE users MODIFY status ENUM('pending', 'approved', 'active', 'rejected', 'disabled') NOT NULL DEFAULT 'active'");
    }
    // Agent notes on an intern / employee's training.
    $trainingColumns = $pdo->query('SHOW COLUMNS FROM training_records')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('notes', $trainingColumns, true)) {
        $pdo->exec('ALTER TABLE training_records ADD COLUMN notes TEXT NULL AFTER status');
    }
    // Evaluations: who wrote it, per-criterion ratings (JSON {criterion: 1-5}) and written feedback.
    $evaluationColumns = $pdo->query('SHOW COLUMNS FROM evaluations')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('evaluator_id', $evaluationColumns, true)) {
        $pdo->exec('ALTER TABLE evaluations
            ADD COLUMN evaluator_id INT UNSIGNED NULL AFTER user_id,
            ADD COLUMN ratings TEXT NULL AFTER score,
            ADD COLUMN strengths TEXT NULL AFTER feedback,
            ADD COLUMN improvements TEXT NULL AFTER strengths,
            ADD COLUMN next_steps VARCHAR(255) NULL AFTER improvements,
            ADD COLUMN published_at DATETIME NULL AFTER next_steps,
            ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER published_at');
    }
    // Uploaded files for documents (stored in uploads/documents/, served by account/document.php).
    $documentColumns = $pdo->query('SHOW COLUMNS FROM documents')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('stored_name', $documentColumns, true)) {
        $pdo->exec('ALTER TABLE documents
            ADD COLUMN stored_name VARCHAR(80) NULL AFTER status,
            ADD COLUMN original_name VARCHAR(190) NOT NULL DEFAULT "" AFTER stored_name,
            ADD COLUMN mime VARCHAR(120) NOT NULL DEFAULT "" AFTER original_name,
            ADD COLUMN size INT UNSIGNED NOT NULL DEFAULT 0 AFTER mime,
            ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
    }
    // A document can be a link instead of a file (e.g. a Google Drive portfolio); then stored_name is NULL.
    if (!in_array('link_url', $pdo->query('SHOW COLUMNS FROM documents')->fetchAll(PDO::FETCH_COLUMN), true)) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN link_url VARCHAR(500) NULL AFTER size');
    }
    // Who uploaded a document for someone else (e.g. the agent who uploaded an intern's certificate).
    if (!in_array('uploaded_by', $pdo->query('SHOW COLUMNS FROM documents')->fetchAll(PDO::FETCH_COLUMN), true)) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN uploaded_by INT UNSIGNED NULL AFTER owner_id');
    }
    // Which training modules each person has finished (module keys from includes/workflow.php).
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS training_progress (
            user_id INT UNSIGNED NOT NULL,
            module_key VARCHAR(60) NOT NULL,
            completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, module_key),
            CONSTRAINT fk_training_progress_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    // Social profile monitoring: a Student VA (intern) or Professional VA (employee) builds these public
    // profiles; entered on the applicant Profile page, exported by agents and HR (export.php?type=social).
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS social_profiles (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            upwork_url VARCHAR(255) NULL,
            linkedin_url VARCHAR(255) NULL,
            quora_url VARCHAR(255) NULL,
            reddit_url VARCHAR(255) NULL,
            instagram_url VARCHAR(255) NULL,
            threads_url VARCHAR(255) NULL,
            substack_url VARCHAR(255) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_social_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    // Best quiz score (%) for a module passed on its lesson page; NULL when an agent ticked it as done.
    $progressColumns = $pdo->query('SHOW COLUMNS FROM training_progress')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('quiz_score', $progressColumns, true)) {
        $pdo->exec('ALTER TABLE training_progress ADD COLUMN quiz_score TINYINT UNSIGNED NULL AFTER module_key');
    }
    // Daily work / activity reports: submitted by interns and employees, approved or returned by an
    // agent or HR. Approved hours make up training_records.hours_logged.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS work_reports (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            report_date DATE NOT NULL,
            hours DECIMAL(4,1) NOT NULL DEFAULT 0,
            summary TEXT NOT NULL,
            notes TEXT NULL,
            status ENUM("submitted", "approved", "returned") NOT NULL DEFAULT "submitted",
            reviewer_id INT UNSIGNED NULL,
            review_note VARCHAR(500) NULL,
            reviewed_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_work_reports_user (user_id, report_date),
            KEY idx_work_reports_status (status),
            CONSTRAINT fk_work_reports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_work_reports_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    // Time in / time out, one row per person per day (applicant Reporting page; admin Monitoring).
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS attendance (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            work_date DATE NOT NULL,
            time_in DATETIME NULL,
            time_out DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_attendance_day (user_id, work_date),
            CONSTRAINT fk_attendance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // ---------- Recruitment & AI onboarding (includes/recruitment.php, ai/) ----------
    $userColumns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    // The agent whose recruitment link the person joined with (agent/recruitment.php).
    if (!in_array('referred_by', $userColumns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN referred_by INT UNSIGNED NULL AFTER agent_id');
    }
    // When the applicant chose Intern or Employee VA. People who join with a recruitment link create their
    // account first and choose afterwards (auth/pathway.php), so it starts NULL for them only.
    if (!in_array('pathway_chosen_at', $userColumns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN pathway_chosen_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP AFTER pathway');
        $pdo->exec('UPDATE users SET pathway_chosen_at = created_at');
    }
    // Skills a department needs (comma-separated, set by HR in admin/department.php). Used to find
    // the skills a team is lacking and to match applicants to the team that needs them.
    $departmentColumns = $pdo->query('SHOW COLUMNS FROM departments')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('required_skills', $departmentColumns, true)) {
        $pdo->exec('ALTER TABLE departments ADD COLUMN required_skills TEXT NULL AFTER description');
        $starterSkills = [
            'Web Developer' => 'HTML, CSS, JavaScript, PHP, WordPress, Responsive design, Git, SEO',
            'Marketing Services' => 'Social media management, Content writing, Copywriting, Canva, Email marketing, SEO, Facebook ads',
            'Accounting' => 'Bookkeeping, QuickBooks, Xero, Microsoft Excel, Invoicing, Payroll, Financial reporting',
            'Psychotherapist' => 'Client intake, Calendar management, Email management, Customer service, Data privacy, Medical records',
        ];
        $setSkills = $pdo->prepare('UPDATE departments SET required_skills = ? WHERE name = ? AND required_skills IS NULL');
        foreach ($starterSkills as $departmentName => $skills) {
            $setSkills->execute([$skills, $departmentName]);
        }
    }
    // Employee VA form: fill in the form or upload a resume; education and experience feed the AI review.
    $applicationColumns = $pdo->query('SHOW COLUMNS FROM applications')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('submission_mode', $applicationColumns, true)) {
        $pdo->exec("ALTER TABLE applications
            ADD COLUMN submission_mode ENUM('form', 'resume') NOT NULL DEFAULT 'form' AFTER user_id,
            ADD COLUMN education VARCHAR(60) NULL AFTER interest,
            ADD COLUMN experience_years TINYINT UNSIGNED NULL AFTER education,
            ADD COLUMN resume_document_id INT UNSIGNED NULL AFTER skills");
    }
    // Shareable recruitment links an agent sends to applicants (like a form link): opening one leads to
    // sign in / create an account, then the Intern or Employee VA choice (auth/join.php).
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS recruitment_links (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code CHAR(16) NOT NULL UNIQUE,
            agent_id INT UNSIGNED NOT NULL,
            label VARCHAR(120) NOT NULL DEFAULT "",
            active TINYINT(1) NOT NULL DEFAULT 1,
            expires_at DATETIME NULL,
            uses INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_recruitment_links_agent FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    // AI-supported resume review of an Employee VA application (ai/resume_review.php). One row per run;
    // the newest is current. category: suitable (fills a department's lacking skills) / for_review /
    // unsuitable (shown under For review, flagged). The department's own assessment is stored with it.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ai_reviews (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            application_id BIGINT UNSIGNED NULL,
            category ENUM("suitable", "for_review", "unsuitable") NOT NULL,
            score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            department_id INT UNSIGNED NULL,
            skills TEXT NULL,
            matched_skills TEXT NULL,
            gap_skills TEXT NULL,
            summary TEXT NULL,
            strengths TEXT NULL,
            concerns TEXT NULL,
            engine VARCHAR(120) NOT NULL DEFAULT "",
            dept_verdict ENUM("endorsed", "not_endorsed") NULL,
            dept_note VARCHAR(1000) NULL,
            dept_reviewer_id INT UNSIGNED NULL,
            dept_reviewed_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ai_reviews_user (user_id, id),
            CONSTRAINT fk_ai_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_ai_reviews_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
            CONSTRAINT fk_ai_reviews_dept_reviewer FOREIGN KEY (dept_reviewer_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    // AI assessment and ranking of an intern after training (ai/intern_assessment.php). Newest is current.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ai_assessments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            tier VARCHAR(30) NOT NULL DEFAULT "",
            ready TINYINT(1) NOT NULL DEFAULT 0,
            summary TEXT NULL,
            metrics TEXT NULL,
            engine VARCHAR(120) NOT NULL DEFAULT "",
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ai_assessments_user (user_id, id),
            CONSTRAINT fk_ai_assessments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    // Employability reports agents send about the interns they handle; AI summarizes them for HR
    // (agent/agent_reports.php -> admin/intern_reports.php). recommended / ai_findings are JSON.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS agent_reports (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            agent_id INT UNSIGNED NULL,
            title VARCHAR(190) NOT NULL,
            body TEXT NOT NULL,
            recommended TEXT NULL,
            ai_summary TEXT NULL,
            ai_findings TEXT NULL,
            engine VARCHAR(120) NOT NULL DEFAULT "",
            status ENUM("submitted", "reviewed") NOT NULL DEFAULT "submitted",
            reviewed_by INT UNSIGNED NULL,
            review_note VARCHAR(500) NULL,
            reviewed_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_agent_reports_agent FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_agent_reports_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
} catch (PDOException $exception) {
    error_log('Mentora database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Database connection failed.');
}
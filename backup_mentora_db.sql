-- phpMyAdmin SQL Dump
-- version 4.9.0.1
-- https://www.phpmyadmin.net/
--
-- Host: sql206.infinityfree.com
-- Generation Time: Nov 24, 2026 at 02:38 AM
-- Server version: 11.4.14-MariaDB
-- PHP Version: 7.2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `if0_43504347_backup_mentora_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `account_invites`
--

CREATE TABLE `account_invites` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `code_hash` char(64) DEFAULT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `account_invites`
--

INSERT INTO `account_invites` (`id`, `user_id`, `token_hash`, `code_hash`, `attempts`, `created_by`, `expires_at`, `used_at`, `created_at`) VALUES
(34, 94, 'adc14f730198e201bc238f6ef388fa4a33e5cb78ec4f789b977fcfa6438a384b', '054a69969a16ff0b0dbc98937f8dd6ecc8e7b4b3bb614631989bdddbf6bfe0fb', 0, 1, '2026-11-30 21:59:10', '2026-11-23 22:00:23', '2026-11-23 13:59:10'),
(37, 95, '63ab8ff5d70ed5b5a68f4484bda9ec44f3e478d2e347712b4fa1ad30aac7ffe6', 'f51acf96093a4f0e81a2e328477d60e181f5f112535b6f1d4d12c67c7caa7baf', 1, 1, '2026-11-30 23:42:36', NULL, '2026-11-23 15:42:36'),
(38, 96, '098e93f469bb70e255c057dc753a19c65831f98778aba3c9618f2887730ec22d', '95a9c63c0c11fead796f01d6a0dff1a1376b9763842c503764dd331d28f2d9ac', 0, 1, '2026-11-30 23:48:33', '2026-11-23 23:49:12', '2026-11-23 15:48:33');

-- --------------------------------------------------------

--
-- Table structure for table `agent_reports`
--

CREATE TABLE `agent_reports` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` int(10) UNSIGNED DEFAULT NULL,
  `title` varchar(190) NOT NULL,
  `body` text NOT NULL,
  `recommended` text DEFAULT NULL,
  `ai_summary` text DEFAULT NULL,
  `ai_findings` text DEFAULT NULL,
  `engine` varchar(120) NOT NULL DEFAULT '',
  `status` enum('submitted','reviewed') NOT NULL DEFAULT 'submitted',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `review_note` varchar(500) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ai_assessments`
--

CREATE TABLE `ai_assessments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `score` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `tier` varchar(30) NOT NULL DEFAULT '',
  `ready` tinyint(1) NOT NULL DEFAULT 0,
  `summary` text DEFAULT NULL,
  `metrics` text DEFAULT NULL,
  `engine` varchar(120) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ai_reviews`
--

CREATE TABLE `ai_reviews` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED DEFAULT NULL,
  `category` enum('suitable','for_review','unsuitable') NOT NULL,
  `score` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `department_id` int(10) UNSIGNED DEFAULT NULL,
  `skills` text DEFAULT NULL,
  `matched_skills` text DEFAULT NULL,
  `gap_skills` text DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `strengths` text DEFAULT NULL,
  `concerns` text DEFAULT NULL,
  `engine` varchar(120) NOT NULL DEFAULT '',
  `dept_verdict` enum('endorsed','not_endorsed') DEFAULT NULL,
  `dept_note` varchar(1000) DEFAULT NULL,
  `dept_reviewer_id` int(10) UNSIGNED DEFAULT NULL,
  `dept_reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `applications`
--

CREATE TABLE `applications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `submission_mode` enum('form','resume') NOT NULL DEFAULT 'form',
  `mobile` varchar(40) DEFAULT NULL,
  `location` varchar(190) DEFAULT NULL,
  `school` varchar(190) DEFAULT NULL,
  `course` varchar(190) DEFAULT NULL,
  `required_hours` smallint(5) UNSIGNED DEFAULT NULL,
  `desired_role` varchar(190) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `interest` varchar(120) DEFAULT NULL,
  `education` varchar(60) DEFAULT NULL,
  `experience_years` tinyint(3) UNSIGNED DEFAULT NULL,
  `skills` text DEFAULT NULL,
  `resume_document_id` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `work_date` date NOT NULL,
  `time_in` datetime DEFAULT NULL,
  `time_out` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` varchar(255) NOT NULL DEFAULT '',
  `required_skills` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name`, `description`, `required_skills`, `created_at`) VALUES
(12682, 'Web Developer', 'Websites, web apps, landing pages and site maintenance for clients', 'HTML, CSS, JavaScript, PHP, WordPress, Responsive design, Git, SEO', '2026-11-20 14:02:27'),
(12683, 'Marketing Services', 'Social media, content, email campaigns and online advertising', 'Social media management, Content writing, Copywriting, Canva, Email marketing, SEO, Facebook ads', '2026-11-20 14:02:27'),
(12684, 'Accounting', 'Bookkeeping, invoicing, payroll support and financial reports', 'Bookkeeping, QuickBooks, Xero, Microsoft Excel, Invoicing, Payroll, Financial reporting', '2026-11-20 14:02:27'),
(12685, 'Psychotherapist', 'Client intake, scheduling and admin support for therapy practices', 'Client intake, Calendar management, Email management, Customer service, Data privacy, Medical records', '2026-11-20 14:02:27');

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(190) NOT NULL,
  `owner_id` int(10) UNSIGNED DEFAULT NULL,
  `uploaded_by` int(10) UNSIGNED DEFAULT NULL,
  `category` varchar(80) NOT NULL DEFAULT 'Training resources',
  `status` enum('published','draft','in_review') NOT NULL DEFAULT 'draft',
  `stored_name` varchar(80) DEFAULT NULL,
  `original_name` varchar(190) NOT NULL DEFAULT '',
  `mime` varchar(120) NOT NULL DEFAULT '',
  `size` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `link_url` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_verifications`
--

CREATE TABLE `email_verifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `code_hash` char(64) DEFAULT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `email_verifications`
--

INSERT INTO `email_verifications` (`id`, `user_id`, `token_hash`, `code_hash`, `attempts`, `expires_at`, `used_at`, `created_at`) VALUES
(7, 94, '4ce03f279d898b98ae73c702423b6cba8ad99004ac2427616fcd1077d18b7cbe', '4e11cd0a518c4dc73a3ca4805dc57b7870e6307abd213cf3faf705f3b75024a3', 0, '2026-11-25 21:57:52', '2026-11-23 21:58:49', '2026-11-23 13:57:52'),
(8, 95, 'b1f21b90195054918529f80e0ae2ef9a91319158763584ab17aa68095799d342', 'a5815591b7f8313f2ec9288133c0562d1ef135e591cef8faad89f28918119744', 0, '2026-11-25 23:14:20', '2026-11-23 23:14:43', '2026-11-23 15:14:20'),
(9, 96, '0b6a281421a5053e2720642f1e0dd3b92b59465554568b7cd035564a283ed844', 'ca88939fc0f943752c546af5158239cfb81b555f679abcc190920e429d588fe9', 0, '2026-11-25 23:47:23', '2026-11-23 23:48:08', '2026-11-23 15:47:23');

-- --------------------------------------------------------

--
-- Table structure for table `evaluations`
--

CREATE TABLE `evaluations` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `evaluator_id` int(10) UNSIGNED DEFAULT NULL,
  `review_type` varchar(80) NOT NULL DEFAULT 'Midpoint evaluation',
  `score` decimal(3,1) DEFAULT NULL,
  `ratings` text DEFAULT NULL,
  `status` enum('draft','pending','published') NOT NULL DEFAULT 'pending',
  `due_date` date DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `strengths` text DEFAULT NULL,
  `improvements` text DEFAULT NULL,
  `next_steps` varchar(255) DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sender_id` int(10) UNSIGNED DEFAULT NULL,
  `recipient_id` int(10) UNSIGNED DEFAULT NULL,
  `subject` varchar(190) NOT NULL,
  `body` text NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`id`, `sender_id`, `recipient_id`, `subject`, `body`, `read_at`, `created_at`) VALUES
(23, 2, 1, 'Message', 'test', '2026-11-21 12:06:18', '2026-11-21 03:43:49'),
(24, 1, 2, 'Re: Message', 'ok', '2026-11-21 16:26:50', '2026-11-21 04:06:23');

-- --------------------------------------------------------

--
-- Table structure for table `message_attachments`
--

CREATE TABLE `message_attachments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `message_id` bigint(20) UNSIGNED NOT NULL,
  `kind` enum('image','file','gif') NOT NULL,
  `stored_name` varchar(80) DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `original_name` varchar(190) NOT NULL DEFAULT '',
  `mime` varchar(120) NOT NULL DEFAULT '',
  `size` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `width` smallint(5) UNSIGNED DEFAULT NULL,
  `height` smallint(5) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `message_reads`
--

CREATE TABLE `message_reads` (
  `message_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `title` varchar(190) NOT NULL,
  `body` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `title`, `body`, `link`, `read_at`, `created_at`);

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recruitment_links`
--

CREATE TABLE `recruitment_links` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` char(16) NOT NULL,
  `agent_id` int(10) UNSIGNED NOT NULL,
  `label` varchar(120) NOT NULL DEFAULT '',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `expires_at` datetime DEFAULT NULL,
  `uses` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `site_feedback`
--

CREATE TABLE `site_feedback` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(160) DEFAULT NULL,
  `rating` tinyint(3) UNSIGNED NOT NULL,
  `comment` text NOT NULL,
  `ip_hash` char(64) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `site_feedback`
--

INSERT INTO `site_feedback` (`id`, `user_id`, `name`, `email`, `rating`, `comment`, `ip_hash`, `created_at`) VALUES
(3, NULL, 'Alex', NULL, 5, 'Nice and better agency', 'adb655da9e8b387398aca1854192a9a4b15c44f96c11e910073078d595161b68', '2026-11-22 03:46:39');

-- --------------------------------------------------------

--
-- Table structure for table `social_profiles`
--

CREATE TABLE `social_profiles` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `upwork_url` varchar(255) DEFAULT NULL,
  `linkedin_url` varchar(255) DEFAULT NULL,
  `quora_url` varchar(255) DEFAULT NULL,
  `reddit_url` varchar(255) DEFAULT NULL,
  `instagram_url` varchar(255) DEFAULT NULL,
  `threads_url` varchar(255) DEFAULT NULL,
  `substack_url` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `training_progress`
--

CREATE TABLE `training_progress` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `module_key` varchar(60) NOT NULL,
  `quiz_score` tinyint(3) UNSIGNED DEFAULT NULL,
  `completed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `training_records`
--

CREATE TABLE `training_records` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `modules_completed` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `modules_total` tinyint(3) UNSIGNED NOT NULL DEFAULT 12,
  `hours_logged` decimal(6,1) NOT NULL DEFAULT 0.0,
  `status` enum('in_training','completed','on_hold') NOT NULL DEFAULT 'in_training',
  `notes` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_code` varchar(20) DEFAULT NULL,
  `first_name` varchar(80) NOT NULL,
  `last_name` varchar(80) NOT NULL,
  `email` varchar(190) NOT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `username` varchar(40) DEFAULT NULL,
  `pathway` enum('employee_va','internship') NOT NULL,
  `pathway_chosen_at` datetime DEFAULT current_timestamp(),
  `password_hash` varchar(255) NOT NULL,
  `role` enum('applicant','agent','admin') NOT NULL DEFAULT 'applicant',
  `status` enum('pending','approved','active','rejected','disabled') NOT NULL DEFAULT 'active',
  `presence` enum('active','busy','offline') NOT NULL DEFAULT 'active',
  `last_seen_at` datetime DEFAULT NULL,
  `department_id` int(10) UNSIGNED DEFAULT NULL,
  `agent_id` int(10) UNSIGNED DEFAULT NULL,
  `referred_by` int(10) UNSIGNED DEFAULT NULL,
  `avatar_path` varchar(190) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `user_code`, `first_name`, `last_name`, `email`, `email_verified_at`, `username`, `pathway`, `pathway_chosen_at`, `password_hash`, `role`, `status`, `presence`, `last_seen_at`, `department_id`, `agent_id`, `referred_by`, `avatar_path`, `created_at`, `updated_at`) VALUES
(1, 'ADM-0001', 'Test', 'Admin', 'admin@mentora.test', '2026-11-18 16:16:22', NULL, 'employee_va', '2026-11-18 16:16:22', '$2y$12$n.TafUDRpmawQdrowK22Ae76XHqvPvsp1wekzH7sq7RQA0r.CELXW', 'admin', 'active', 'active', '2026-11-24 15:37:29', NULL, NULL, NULL, 'uploads/avatars/u1-5273335f70c9b712.jpg', '2026-11-18 08:16:22', '2026-11-24 07:37:29'),
(2, 'AGT-0002', 'Test', 'Agent', 'agent@mentora.test', '2026-11-18 16:16:22', NULL, 'employee_va', '2026-11-18 16:16:22', '$2y$10$a6ouZR/ov5EQEG7eJyKP0.wWgDmkkR7reNh6MaLqCpQ.r9/Eb9J6.', 'agent', 'active', 'active', NULL, NULL, NULL, NULL, 'uploads/avatars/u2-7a8580077e627358.jpg', '2026-11-18 08:16:22', '2026-11-21 09:25:05'),
(94, 'EMP-0094', 'Alexandra', 'Concepcion', 'lykemaeconcepcion@gmail.com', '2026-11-23 21:58:49', 'alexandra.concepcion', 'employee_va', '2026-11-23 21:57:52', '$2y$12$/Of8Oev833XEpLD04TWFzOx7kbBk53UJiME990xR/X9I/GdcCXISu', 'applicant', 'active', 'active', NULL, NULL, NULL, NULL, NULL, '2026-11-23 13:57:52', '2026-11-23 15:22:40'),
(95, 'EMP-0095', 'lans', 'oksilyo', 'wolfmazing205@gmail.com', '2026-11-23 23:14:43', NULL, 'employee_va', '2026-11-23 23:14:20', '$2y$12$tfW32EKY4UHQq1N9q9/yMe2HgvBj3b/nhRU/R1eymtgsEPLxYS7TW', 'applicant', 'approved', 'active', NULL, NULL, NULL, NULL, NULL, '2026-11-23 15:14:20', '2026-11-23 15:25:14'),
(96, 'INT-0096', 'Zamara', 'Mikayla', 'zamaramikayla@gmail.com', '2026-11-23 23:48:08', 'zamara.mikayla', 'internship', '2026-11-23 23:47:23', '$2y$12$cD8gOCXQ5k0tCJj7wvLlZeTwTh779.q9heRIJoAeOB0CK96ZsIqN6', 'applicant', 'active', 'active', '2026-11-23 23:53:15', NULL, NULL, NULL, NULL, '2026-11-23 15:47:23', '2026-11-23 15:53:15');

-- --------------------------------------------------------

--
-- Table structure for table `work_reports`
--

CREATE TABLE `work_reports` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `report_date` date NOT NULL,
  `hours` decimal(4,1) NOT NULL DEFAULT 0.0,
  `summary` text NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('submitted','approved','returned') NOT NULL DEFAULT 'submitted',
  `reviewer_id` int(10) UNSIGNED DEFAULT NULL,
  `review_note` varchar(500) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `account_invites`
--
ALTER TABLE `account_invites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `fk_account_invites_user` (`user_id`),
  ADD KEY `fk_account_invites_creator` (`created_by`);

--
-- Indexes for table `agent_reports`
--
ALTER TABLE `agent_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_agent_reports_agent` (`agent_id`),
  ADD KEY `fk_agent_reports_reviewer` (`reviewed_by`);

--
-- Indexes for table `ai_assessments`
--
ALTER TABLE `ai_assessments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ai_assessments_user` (`user_id`,`id`);

--
-- Indexes for table `ai_reviews`
--
ALTER TABLE `ai_reviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ai_reviews_user` (`user_id`,`id`),
  ADD KEY `fk_ai_reviews_department` (`department_id`),
  ADD KEY `fk_ai_reviews_dept_reviewer` (`dept_reviewer_id`);

--
-- Indexes for table `applications`
--
ALTER TABLE `applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_applications_user` (`user_id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_attendance_day` (`user_id`,`work_date`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_documents_owner` (`owner_id`);

--
-- Indexes for table `email_verifications`
--
ALTER TABLE `email_verifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `fk_email_verifications_user` (`user_id`);

--
-- Indexes for table `evaluations`
--
ALTER TABLE `evaluations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_evaluations_user` (`user_id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_messages_sender` (`sender_id`),
  ADD KEY `fk_messages_recipient` (`recipient_id`);

--
-- Indexes for table `message_attachments`
--
ALTER TABLE `message_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_message_attachments_message` (`message_id`);

--
-- Indexes for table `message_reads`
--
ALTER TABLE `message_reads`
  ADD PRIMARY KEY (`message_id`,`user_id`),
  ADD KEY `fk_message_reads_user` (`user_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_notifications_user` (`user_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `fk_password_resets_user` (`user_id`);

--
-- Indexes for table `recruitment_links`
--
ALTER TABLE `recruitment_links`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `fk_recruitment_links_agent` (`agent_id`);

--
-- Indexes for table `site_feedback`
--
ALTER TABLE `site_feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_site_feedback_created` (`created_at`),
  ADD KEY `idx_site_feedback_ip` (`ip_hash`,`created_at`),
  ADD KEY `fk_site_feedback_user` (`user_id`);

--
-- Indexes for table `social_profiles`
--
ALTER TABLE `social_profiles`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `training_progress`
--
ALTER TABLE `training_progress`
  ADD PRIMARY KEY (`user_id`,`module_key`);

--
-- Indexes for table `training_records`
--
ALTER TABLE `training_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_training_user` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `uq_users_user_code` (`user_code`),
  ADD UNIQUE KEY `uq_users_username` (`username`);

--
-- Indexes for table `work_reports`
--
ALTER TABLE `work_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_work_reports_user` (`user_id`,`report_date`),
  ADD KEY `idx_work_reports_status` (`status`),
  ADD KEY `fk_work_reports_reviewer` (`reviewer_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `account_invites`
--
ALTER TABLE `account_invites`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `agent_reports`
--
ALTER TABLE `agent_reports`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ai_assessments`
--
ALTER TABLE `ai_assessments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ai_reviews`
--
ALTER TABLE `ai_reviews`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `applications`
--
ALTER TABLE `applications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12687;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `email_verifications`
--
ALTER TABLE `email_verifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `evaluations`
--
ALTER TABLE `evaluations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `message_attachments`
--
ALTER TABLE `message_attachments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=68;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `recruitment_links`
--
ALTER TABLE `recruitment_links`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `site_feedback`
--
ALTER TABLE `site_feedback`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `training_records`
--
ALTER TABLE `training_records`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=97;

--
-- AUTO_INCREMENT for table `work_reports`
--
ALTER TABLE `work_reports`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `account_invites`
--
ALTER TABLE `account_invites`
  ADD CONSTRAINT `fk_account_invites_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_account_invites_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `agent_reports`
--
ALTER TABLE `agent_reports`
  ADD CONSTRAINT `fk_agent_reports_agent` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_agent_reports_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `ai_assessments`
--
ALTER TABLE `ai_assessments`
  ADD CONSTRAINT `fk_ai_assessments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ai_reviews`
--
ALTER TABLE `ai_reviews`
  ADD CONSTRAINT `fk_ai_reviews_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ai_reviews_dept_reviewer` FOREIGN KEY (`dept_reviewer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ai_reviews_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `applications`
--
ALTER TABLE `applications`
  ADD CONSTRAINT `fk_applications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `fk_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `fk_documents_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `email_verifications`
--
ALTER TABLE `email_verifications`
  ADD CONSTRAINT `fk_email_verifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `evaluations`
--
ALTER TABLE `evaluations`
  ADD CONSTRAINT `fk_evaluations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `fk_messages_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `message_attachments`
--
ALTER TABLE `message_attachments`
  ADD CONSTRAINT `fk_message_attachments_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `message_reads`
--
ALTER TABLE `message_reads`
  ADD CONSTRAINT `fk_message_reads_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_message_reads_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `recruitment_links`
--
ALTER TABLE `recruitment_links`
  ADD CONSTRAINT `fk_recruitment_links_agent` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `site_feedback`
--
ALTER TABLE `site_feedback`
  ADD CONSTRAINT `fk_site_feedback_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `social_profiles`
--
ALTER TABLE `social_profiles`
  ADD CONSTRAINT `fk_social_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `training_progress`
--
ALTER TABLE `training_progress`
  ADD CONSTRAINT `fk_training_progress_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `training_records`
--
ALTER TABLE `training_records`
  ADD CONSTRAINT `fk_training_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `work_reports`
--
ALTER TABLE `work_reports`
  ADD CONSTRAINT `fk_work_reports_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_work_reports_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

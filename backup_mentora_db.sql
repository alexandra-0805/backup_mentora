-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 20, 2026 at 04:58 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `backup_mentora_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `account_invites`
--

CREATE TABLE `account_invites` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `account_invites`
--

INSERT INTO `account_invites` (`id`, `user_id`, `token_hash`, `created_by`, `expires_at`, `used_at`, `created_at`) VALUES
(12, 41, '19354f22ec926104327cd23861b7b2c12b7818cf0871164edfc575a2d41865e2', 1, '2026-11-26 19:30:24', '2026-11-19 19:31:35', '2026-11-19 11:30:24');

-- --------------------------------------------------------

--
-- Table structure for table `applications`
--

CREATE TABLE `applications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `mobile` varchar(40) DEFAULT NULL,
  `location` varchar(190) DEFAULT NULL,
  `school` varchar(190) DEFAULT NULL,
  `course` varchar(190) DEFAULT NULL,
  `required_hours` smallint(5) UNSIGNED DEFAULT NULL,
  `desired_role` varchar(190) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `interest` varchar(120) DEFAULT NULL,
  `skills` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `applications`
--

INSERT INTO `applications` (`id`, `user_id`, `mobile`, `location`, `school`, `course`, `required_hours`, `desired_role`, `start_date`, `interest`, `skills`, `status`, `reviewed_at`, `created_at`, `updated_at`) VALUES
(1, 3, NULL, NULL, 'Test University', 'BS Information Systems', 480, NULL, NULL, 'Operations', NULL, 'pending', NULL, '2026-11-18 08:16:23', '2026-11-18 08:16:23'),
(2, 4, NULL, NULL, NULL, NULL, NULL, 'Customer support', NULL, NULL, NULL, 'pending', NULL, '2026-11-18 08:16:23', '2026-11-18 08:16:23'),
(17, 41, '09462274362', 'Cavite', NULL, NULL, NULL, 'developer', '2026-11-23', NULL, 'none', 'pending', NULL, '2026-11-19 11:38:50', '2026-11-19 11:38:50');

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name`, `description`, `created_at`) VALUES
(12682, 'Web Developer', 'Websites, web apps, landing pages and site maintenance for clients', '2026-11-20 14:02:27'),
(12683, 'Marketing Services', 'Social media, content, email campaigns and online advertising', '2026-11-20 14:02:27'),
(12684, 'Accounting', 'Bookkeeping, invoicing, payroll support and financial reports', '2026-11-20 14:02:27'),
(12685, 'Psychotherapist', 'Client intake, scheduling and admin support for therapy practices', '2026-11-20 14:02:27');

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
(19, 1, 41, 'Message', 'hi', '2026-11-20 23:53:06', '2026-11-20 15:37:09'),
(20, 1, 41, 'Message', 'test', '2026-11-20 23:53:06', '2026-11-20 15:37:12'),
(21, 1, 41, 'Message', '🧡🧡', '2026-11-20 23:53:06', '2026-11-20 15:38:10');

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

--
-- Dumping data for table `message_attachments`
--

INSERT INTO `message_attachments` (`id`, `message_id`, `kind`, `stored_name`, `url`, `original_name`, `mime`, `size`, `width`, `height`, `created_at`) VALUES
(5, 21, 'file', 'm21-6b7f5e629cbff809.pdf', NULL, 'KLD-REG-FR-0008-01-Pre-Enrollment-Advising-Form-1.pdf', 'application/pdf', 257096, NULL, NULL, '2026-11-20 15:38:10'),
(6, 21, 'image', 'm21-a04e49476fdf8f07.png', NULL, 'Screenshot 2026-10-23 121757.png', 'image/png', 395583, 911, 902, '2026-11-20 15:38:10');

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

INSERT INTO `notifications` (`id`, `user_id`, `title`, `body`, `link`, `read_at`, `created_at`) VALUES
(9, 1, 'New application: Lyke Concepcion', 'Employee VA · alexandralykemae@gmail.com is waiting for confirmation in Applications.', 'admin/applications.php?view=pending', '2026-11-19 19:03:46', '2026-11-19 10:58:55');

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
  `username` varchar(40) DEFAULT NULL,
  `pathway` enum('employee_va','internship') NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('applicant','agent','admin') NOT NULL DEFAULT 'applicant',
  `status` enum('pending','approved','active','rejected','disabled') NOT NULL DEFAULT 'active',
  `department_id` int(10) UNSIGNED DEFAULT NULL,
  `agent_id` int(10) UNSIGNED DEFAULT NULL,
  `avatar_path` varchar(190) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `user_code`, `first_name`, `last_name`, `email`, `username`, `pathway`, `password_hash`, `role`, `status`, `department_id`, `agent_id`, `avatar_path`, `created_at`, `updated_at`) VALUES
(1, 'ADM-0001', 'Test', 'Admin', 'admin@mentora.test', NULL, 'employee_va', '$2y$10$nte8t3aPrnFAP86SBU1lB.dTfpQo31SQXvItSbiA7lH//jyBJcTBi', 'admin', 'active', NULL, NULL, 'uploads/avatars/u1-5273335f70c9b712.jpg', '2026-11-18 08:16:22', '2026-11-20 15:40:52'),
(2, 'AGT-0002', 'Test', 'Agent', 'agent@mentora.test', NULL, 'employee_va', '$2y$10$a6ouZR/ov5EQEG7eJyKP0.wWgDmkkR7reNh6MaLqCpQ.r9/Eb9J6.', 'agent', 'active', NULL, NULL, 'uploads/avatars/u2-7a8580077e627358.jpg', '2026-11-18 08:16:22', '2026-11-20 15:41:48'),
(3, 'INT-0003', 'Test', 'Intern', 'intern@mentora.test', NULL, 'internship', '$2y$10$tskvC.Kh/xI4fTksAHOCreYUzFaBUxFSP9sbsQ4YkV.tDx2QVtHjC', 'applicant', 'active', NULL, NULL, NULL, '2026-11-18 08:16:23', '2026-11-18 08:32:34'),
(4, 'EMP-0004', 'Test', 'Employee', 'employee@mentora.test', NULL, 'employee_va', '$2y$10$g9ChTWnBYQgimH94sgiL1eSAlDmS1oIK6aDTtACaMXXMeI5Bnoq6i', 'applicant', 'active', NULL, NULL, NULL, '2026-11-18 08:16:23', '2026-11-18 08:32:34'),
(34, 'INT-0034', 'Alex', 'Concepcion', 'lykemaeconcepcion@gmail.com', NULL, 'internship', '$2y$10$srS1/2RENymJYN04rgjOp.hfR4B0oxX2mPt7hWmqC1w6bYeIus6BW', 'applicant', 'active', NULL, NULL, NULL, '2026-11-19 10:04:01', '2026-11-19 10:04:02'),
(41, 'EMP-0041', 'Lyke', 'Concepcion', 'alexandralykemae@gmail.com', 'lyke.concepcion', 'employee_va', '$2y$10$S77pObh2rCZAEqz3lRsvxOPytfCXauA7hUWaKAVPIhq09O3iXtXuK', 'applicant', 'active', NULL, NULL, NULL, '2026-11-19 10:58:55', '2026-11-19 11:31:35');

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
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `applications`
--
ALTER TABLE `applications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

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
-- AUTO_INCREMENT for table `evaluations`
--
ALTER TABLE `evaluations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `message_attachments`
--
ALTER TABLE `message_attachments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `training_records`
--
ALTER TABLE `training_records`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=85;

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

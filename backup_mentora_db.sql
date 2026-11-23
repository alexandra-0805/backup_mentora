-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 22, 2026 at 10:59 AM
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
(12, 41, '19354f22ec926104327cd23861b7b2c12b7818cf0871164edfc575a2d41865e2', 1, '2026-11-26 19:30:24', '2026-11-19 19:31:35', '2026-11-19 11:30:24'),
(16, 88, '53b05e5d9031a96079331c728018c99d50981773aa85a41fdf9935a7cdb0312a', 1, '2026-11-28 15:44:40', '2026-11-21 15:45:40', '2026-11-21 07:44:40'),
(18, 89, '087b10ac5517d68dcaa7c665c136df54de689992c1bd4507d1ec2960f8418055', 1, '2026-11-28 16:00:36', '2026-11-21 16:04:57', '2026-11-21 08:00:36');

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(80) NOT NULL,
  `email` varchar(160) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `username`, `email`, `password`, `created_at`) VALUES
(1, 'admin', 'lykemaeconcepcion@gmail.com', '$2y$10$I8n2sPyU2O/vKEaQeQ9ZQe71bekO3izgM854zde39vJy/fFIrHpsC', '2026-11-21 10:11:11');

-- --------------------------------------------------------

--
-- Table structure for table `admin_password_resets`
--

CREATE TABLE `admin_password_resets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `admin_id` int(10) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `otp_hash` char(64) DEFAULT NULL,
  `otp_attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
-- Error reading structure for table backup_mentora_db.ai_reviews: #1932 - Table &#039;backup_mentora_db.ai_reviews&#039; doesn&#039;t exist in engine
-- Error reading data for table backup_mentora_db.ai_reviews: #1064 - You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near &#039;FROM `backup_mentora_db`.`ai_reviews`&#039; at line 1

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

--
-- Dumping data for table `applications`
--

INSERT INTO `applications` (`id`, `user_id`, `submission_mode`, `mobile`, `location`, `school`, `course`, `required_hours`, `desired_role`, `start_date`, `interest`, `education`, `experience_years`, `skills`, `resume_document_id`, `status`, `reviewed_at`, `created_at`, `updated_at`) VALUES
(1, 3, 'form', NULL, NULL, 'Test University', 'BS Information Systems', 480, NULL, NULL, 'Operations', NULL, NULL, NULL, NULL, 'pending', NULL, '2026-11-18 08:16:23', '2026-11-18 08:16:23'),
(2, 4, 'form', NULL, NULL, NULL, NULL, NULL, 'Customer support', NULL, NULL, NULL, NULL, NULL, NULL, 'pending', NULL, '2026-11-18 08:16:23', '2026-11-18 08:16:23'),
(17, 41, 'form', '09462274362', 'Cavite', NULL, NULL, NULL, 'developer', '2026-11-23', NULL, NULL, NULL, 'none', NULL, 'pending', NULL, '2026-11-19 11:38:50', '2026-11-19 11:38:50'),
(24, 88, 'form', '09389344807', 'Dasmarinas, Cavite', NULL, NULL, NULL, 'Marketing', '2026-11-23', NULL, 'college_undergrad', 0, 'One of my skills is creating an excel, editing in canva and such that related to editing or using a laptop or computer. Since the role that i want is marketing, i consider that part of my skills is talking to a customer politely, and introduce to them in better way the product or services that we can offer.', NULL, 'pending', NULL, '2026-11-21 07:52:36', '2026-11-21 07:52:36'),
(25, 89, 'form', '09389344807', 'Dasmarinas, Cavite', 'Cavite State University - Imus Campus', 'BS Office Administration', 480, NULL, '2026-11-27', 'Marketing Services', NULL, NULL, 'I have no experience yet in workplace, but during my senior high school and college, I have learned a lot. I realized that I have skills in marketing, including data analysis, strategic communication and technical execution.', NULL, 'pending', NULL, '2026-11-21 08:10:55', '2026-11-21 08:10:55');

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
-- Table structure for table `certificates`
--

CREATE TABLE `certificates` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(200) NOT NULL,
  `issuer` varchar(150) DEFAULT NULL,
  `date` varchar(80) DEFAULT NULL,
  `image` varchar(500) DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `certificates`
--

INSERT INTO `certificates` (`id`, `title`, `issuer`, `date`, `image`, `url`, `description`, `created_at`) VALUES
(1, 'Entrepreneurship', 'MIBEX', 'January 2024', NULL, 'https://drive.google.com/drive/folders/1yp7-SgJTQgzfwyaOOZA_OB5dkwGu-0Ek?usp=sharing', 'Entrepreneurship certificate.', '2026-11-21 10:11:14'),
(2, 'Establishing & Operating Micro-Small Medium Enterprises', 'MIBEX', 'April 2024', NULL, 'https://drive.google.com/drive/folders/1yp7-SgJTQgzfwyaOOZA_OB5dkwGu-0Ek?usp=sharing', 'Business operations certificate.', '2026-11-21 10:11:14'),
(3, 'Most Sustainable Product in Mini Customized Perfume Bottle', 'MIBEX', 'April 2024', NULL, 'https://drive.google.com/drive/folders/1yp7-SgJTQgzfwyaOOZA_OB5dkwGu-0Ek?usp=sharing', 'Sustainability recognition.', '2026-11-21 10:11:14'),
(4, 'Best Non-food in Product', 'MIBEX', 'April 2024', NULL, 'https://drive.google.com/drive/folders/1yp7-SgJTQgzfwyaOOZA_OB5dkwGu-0Ek?usp=sharing', 'Product development recognition.', '2026-11-21 10:11:14'),
(5, 'Visual Graphic Design NC II', 'TESDA', 'February 2026', NULL, 'https://drive.google.com/drive/folders/1yp7-SgJTQgzfwyaOOZA_OB5dkwGu-0Ek?usp=sharing', 'Visual graphic design certification.', '2026-11-21 10:11:14'),
(6, 'Certificate of Content Creation', 'MIBEX', 'April 2026', NULL, 'https://drive.google.com/drive/folders/1yp7-SgJTQgzfwyaOOZA_OB5dkwGu-0Ek?usp=sharing', 'Certificate of content creation.', '2026-11-21 10:11:14'),
(7, 'Certificate of MSMEs', 'MIBEX', 'April 2026', NULL, 'https://drive.google.com/drive/folders/1yp7-SgJTQgzfwyaOOZA_OB5dkwGu-0Ek?usp=sharing', 'Certificate for micro, small, and medium enterprise learning.', '2026-11-21 10:11:14'),
(8, 'NC II Logo Design', 'TESDA', 'April 2026', NULL, 'https://drive.google.com/drive/folders/1yp7-SgJTQgzfwyaOOZA_OB5dkwGu-0Ek?usp=sharing', 'NC II specialization certificate in logo design.', '2026-11-21 10:11:14'),
(9, 'NC II Print Media', 'TESDA', 'April 2026', NULL, 'https://drive.google.com/drive/folders/1yp7-SgJTQgzfwyaOOZA_OB5dkwGu-0Ek?usp=sharing', 'NC II specialization certificate in print media.', '2026-11-21 10:11:14'),
(10, 'NC II Product Packaging', 'TESDA', 'April 2026', NULL, 'https://drive.google.com/drive/folders/1yp7-SgJTQgzfwyaOOZA_OB5dkwGu-0Ek?usp=sharing', 'NC II specialization certificate in product packaging.', '2026-11-21 10:11:14'),
(11, 'NC II User Experience', 'TESDA', 'April 2026', NULL, 'https://drive.google.com/drive/folders/1yp7-SgJTQgzfwyaOOZA_OB5dkwGu-0Ek?usp=sharing', 'NC II specialization certificate in user experience.', '2026-11-21 10:11:14'),
(12, 'NC II User Interface', 'TESDA', 'April 2026', NULL, 'https://drive.google.com/drive/folders/1yp7-SgJTQgzfwyaOOZA_OB5dkwGu-0Ek?usp=sharing', 'NC II specialization certificate in user interface.', '2026-11-21 10:11:14'),
(13, 'NC II Visual Graphic Design', 'TESDA', 'April 2026', NULL, 'https://drive.google.com/drive/folders/1yp7-SgJTQgzfwyaOOZA_OB5dkwGu-0Ek?usp=sharing', 'NC II specialization certificate in visual graphic design.', '2026-11-21 10:11:14'),
(14, 'NC III Product & Window Design', 'TESDA', 'April 2026', NULL, 'https://drive.google.com/drive/folders/1yp7-SgJTQgzfwyaOOZA_OB5dkwGu-0Ek?usp=sharing', 'NC III specialization certificate in product and window design.', '2026-11-21 10:11:14');

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
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `email_verifications`
--

INSERT INTO `email_verifications` (`id`, `user_id`, `token_hash`, `expires_at`, `used_at`, `created_at`) VALUES
(1, 89, '322f44fb9b67899e398cb51ff0285903da240f9c770309a0d5fd1ca9391ab1c0', '2026-11-23 15:57:27', NULL, '2026-11-21 07:57:27');

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
(21, 1, 41, 'Message', '🧡🧡', '2026-11-20 23:53:06', '2026-11-20 15:38:10'),
(22, 41, 2, 'Message', 'Hi test', '2026-11-21 11:43:35', '2026-11-21 03:37:49'),
(23, 2, 1, 'Message', 'test', '2026-11-21 12:06:18', '2026-11-21 03:43:49'),
(24, 1, 2, 'Re: Message', 'ok', '2026-11-21 16:26:50', '2026-11-21 04:06:23'),
(25, 89, 1, 'Message', 'hi', NULL, '2026-11-21 09:12:54');

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
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text NOT NULL,
  `technologies` varchar(255) NOT NULL,
  `image` varchar(500) DEFAULT NULL,
  `project_url` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`id`, `title`, `description`, `technologies`, `image`, `project_url`, `created_at`) VALUES
(1, 'Bakeshop Website', 'A friendly front-end website concept designed to improve customer engagement.', 'HTML, CSS, JavaScript', 'https://images.unsplash.com/photo-1555507036-ab1f4038808a?auto=format&fit=crop&w=900&q=80', 'https://drive.google.com/drive/folders/1x1cmwo_GXkbv3--IOjZ4E0WYpWzk7cJ7?usp=sharing', '2026-11-21 10:11:13'),
(2, 'Wooden Perfume Bottle', 'An innovative product concept developed and presented as a team.', 'Research, Presentation, Design', 'https://images.unsplash.com/photo-1594035910387-fea47794261f?auto=format&fit=crop&w=900&q=80', 'https://drive.google.com/drive/folders/1tiwzumF9iTp8SrpSbn3vqVrnbLfPrGKK?usp=sharing', '2026-11-21 10:11:13'),
(3, 'Digital Content Projects', 'Social media and promotional graphics created for small business ideas.', 'Canva, Content Planning, AI Tools', 'https://images.unsplash.com/photo-1558655146-9f40138edfeb?auto=format&fit=crop&w=900&q=80', 'https://drive.google.com/drive/folders/13AQiPXZFps0GQXkdQPmDbbFtpRQhavoY?usp=sharing', '2026-11-21 10:11:13'),
(4, 'Web Development Portfolio', 'A collection of responsive web development work built with HTML, CSS, and JavaScript.', 'HTML, CSS, JavaScript', 'https://images.unsplash.com/photo-1498050108023-c5249f4df085?auto=format&fit=crop&w=900&q=80', 'https://drive.google.com/drive/folders/11DS32zj3hPiS7ngc4R11gXnbi9aa09xv?usp=sharing', '2026-11-21 10:11:13');

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
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text NOT NULL,
  `icon` varchar(80) NOT NULL DEFAULT 'bi-stars',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `title`, `description`, `icon`, `created_at`) VALUES
(1, 'Administrative Support', 'Keep calendars, documents, and recurring tasks organized and moving.', 'bi-kanban', '2026-11-21 10:11:13'),
(2, 'Data Entry', 'Accurate data entry, cleanup, and spreadsheet support.', 'bi-table', '2026-11-21 10:11:13'),
(3, 'Email & Client Communication', 'Professional, thoughtful responses and inbox assistance.', 'bi-chat-square-text', '2026-11-21 10:11:13'),
(4, 'Internet Research', 'Clear, useful research summarized for action.', 'bi-search', '2026-11-21 10:11:13'),
(5, 'File Management', 'Simple, consistent digital organization and file naming.', 'bi-folder2-open', '2026-11-21 10:11:13'),
(6, 'Customer Support', 'Patient customer care and practical issue resolution.', 'bi-headset', '2026-11-21 10:11:13'),
(7, 'Social Media Assistance', 'Content scheduling, captions, and day-to-day social support.', 'bi-share', '2026-11-21 10:11:13'),
(8, 'Canva Design Support', 'Clean graphics for posts, presentations, and campaigns.', 'bi-palette', '2026-11-21 10:11:13'),
(9, 'AI Content Creation', 'AI-assisted drafts, ideas, and workflow improvements.', 'bi-stars', '2026-11-21 10:11:13'),
(10, 'Front-End Development', 'Responsive interfaces built with foundational web skills.', 'bi-code-slash', '2026-11-21 10:11:13'),
(11, 'Graphic Design', 'Visual layouts shaped around your message and audience.', 'bi-vector-pen', '2026-11-21 10:11:13');

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
-- Table structure for table `skills`
--

CREATE TABLE `skills` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` varchar(100) NOT NULL,
  `proficiency` tinyint(3) UNSIGNED NOT NULL DEFAULT 70,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `skills`
--

INSERT INTO `skills` (`id`, `name`, `category`, `proficiency`, `created_at`) VALUES
(1, 'Microsoft Office', 'Productivity', 82, '2026-11-21 10:11:13'),
(2, 'Google Workspace', 'Productivity', 84, '2026-11-21 10:11:13'),
(3, 'Canva', 'Design', 88, '2026-11-21 10:11:13'),
(4, 'AI Tools', 'Digital', 82, '2026-11-21 10:11:13'),
(5, 'Basic Web Development', 'Development', 72, '2026-11-21 10:11:13'),
(6, 'HTML', 'Development', 78, '2026-11-21 10:11:13'),
(7, 'CSS', 'Development', 76, '2026-11-21 10:11:13'),
(8, 'JavaScript', 'Development', 68, '2026-11-21 10:11:13'),
(9, 'PHP', 'Development', 65, '2026-11-21 10:11:13'),
(10, 'MySQL', 'Development', 64, '2026-11-21 10:11:13'),
(11, 'Data Entry', 'Administration', 90, '2026-11-21 10:11:13'),
(12, 'Internet Research', 'Administration', 88, '2026-11-21 10:11:13'),
(13, 'File Management', 'Administration', 86, '2026-11-21 10:11:13'),
(14, 'Customer Support', 'Communication', 85, '2026-11-21 10:11:13');

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

--
-- Dumping data for table `training_records`
--

INSERT INTO `training_records` (`id`, `user_id`, `modules_completed`, `modules_total`, `hours_logged`, `status`, `notes`, `updated_at`) VALUES
(21, 89, 0, 7, 0.0, 'in_training', NULL, '2026-11-21 08:10:55');

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
(1, 'ADM-0001', 'Test', 'Admin', 'admin@mentora.test', '2026-11-18 16:16:22', NULL, 'employee_va', '2026-11-18 16:16:22', '$2y$10$nte8t3aPrnFAP86SBU1lB.dTfpQo31SQXvItSbiA7lH//jyBJcTBi', 'admin', 'active', 'active', NULL, NULL, NULL, NULL, 'uploads/avatars/u1-5273335f70c9b712.jpg', '2026-11-18 08:16:22', '2026-11-21 09:24:23'),
(2, 'AGT-0002', 'Test', 'Agent', 'agent@mentora.test', '2026-11-18 16:16:22', NULL, 'employee_va', '2026-11-18 16:16:22', '$2y$10$a6ouZR/ov5EQEG7eJyKP0.wWgDmkkR7reNh6MaLqCpQ.r9/Eb9J6.', 'agent', 'active', 'active', NULL, NULL, NULL, NULL, 'uploads/avatars/u2-7a8580077e627358.jpg', '2026-11-18 08:16:22', '2026-11-21 09:25:05'),
(3, 'INT-0003', 'Test', 'Intern', 'intern@mentora.test', '2026-11-18 16:16:23', NULL, 'internship', '2026-11-18 16:16:23', '$2y$10$tskvC.Kh/xI4fTksAHOCreYUzFaBUxFSP9sbsQ4YkV.tDx2QVtHjC', 'applicant', 'active', 'active', '2026-11-22 11:10:08', NULL, NULL, NULL, NULL, '2026-11-18 08:16:23', '2026-11-22 03:10:08'),
(4, 'EMP-0004', 'Test', 'Employee', 'employee@mentora.test', '2026-11-18 16:16:23', NULL, 'employee_va', '2026-11-18 16:16:23', '$2y$10$g9ChTWnBYQgimH94sgiL1eSAlDmS1oIK6aDTtACaMXXMeI5Bnoq6i', 'applicant', 'active', 'active', NULL, NULL, NULL, NULL, NULL, '2026-11-18 08:16:23', '2026-11-21 07:34:46'),
(34, 'INT-0034', 'Alex', 'Concepcion', 'lykemaeconcepcion@gmail.com', '2026-11-19 18:04:01', NULL, 'internship', '2026-11-19 18:04:01', '$2y$10$srS1/2RENymJYN04rgjOp.hfR4B0oxX2mPt7hWmqC1w6bYeIus6BW', 'applicant', 'active', 'active', NULL, NULL, NULL, NULL, NULL, '2026-11-19 10:04:01', '2026-11-21 10:16:26'),
(41, 'EMP-0041', 'Lyke', 'Concepcion', 'alexandralykemae@gmail.com', '2026-11-19 18:58:55', 'lyke.concepcion', 'employee_va', '2026-11-19 18:58:55', '$2y$10$S77pObh2rCZAEqz3lRsvxOPytfCXauA7hUWaKAVPIhq09O3iXtXuK', 'applicant', 'active', 'active', NULL, NULL, NULL, NULL, NULL, '2026-11-19 10:58:55', '2026-11-21 09:26:26'),
(85, 'INT-0085', 'Angel Marie', 'Palce', 'palceangel5@gmail.com', '2026-11-21 14:18:12', NULL, 'internship', '2026-11-21 14:18:12', '$2y$10$Ry6Mr5015LV8XCC5tm/T0eWNSUxeeCg17PpGPUMo6PIFcwrWWeMoC', 'applicant', 'pending', 'active', NULL, NULL, NULL, NULL, NULL, '2026-11-21 06:18:12', '2026-11-21 08:22:58'),
(86, 'INT-0086', 'Angel Marie', 'Palce', 'palceangel1@gmail.com', '2026-11-21 14:45:03', NULL, 'internship', '2026-11-21 14:29:31', '$2y$10$yvBgdUHbwsU7m7MxFYA1q.Wc1dWqucwmU0dhsEnWW5UJuhcpgnoTG', 'applicant', 'pending', 'active', NULL, NULL, NULL, NULL, NULL, '2026-11-21 06:45:03', '2026-11-21 07:34:46'),
(87, 'INT-0087', 'Zamara', 'Jarmin', 'zamaramikayla@gmail.com', '2026-11-21 14:47:07', NULL, 'internship', '2026-11-21 14:47:07', '$2y$10$GoCG/4g3HUrblr9AAJ4BiORY2iMvx6kh3RoxqzhGf6NZYHRKkoZ0O', 'applicant', 'pending', 'active', NULL, NULL, NULL, NULL, NULL, '2026-11-21 06:47:07', '2026-11-21 07:34:46'),
(88, 'EMP-0088', 'Angel', 'Palce', 'angelmarieo652@gmail.com', '2026-11-21 15:30:15', 'angel.palce', 'employee_va', '2026-11-21 15:30:15', '$2y$10$AH/ZI5iLSV0fy7BvZdXBCeMsA0xKjMmOhjNtmJXNJtt4RCPLTL5.K', 'applicant', 'active', 'active', NULL, NULL, NULL, NULL, NULL, '2026-11-21 07:30:15', '2026-11-21 07:56:02'),
(89, 'INT-0089', 'Angel', 'Marie', 'angelpalce90@gmail.com', NULL, 'angel.marie', 'internship', '2026-11-21 15:57:27', '$2y$10$.GpLaV8N1o35KUAv3qxSYOPRKdjci09tb2/b7J/NNMbkmxKVo7A2.', 'applicant', 'active', 'active', NULL, NULL, NULL, NULL, NULL, '2026-11-21 07:57:27', '2026-11-21 09:13:24');

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
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `admin_password_resets`
--
ALTER TABLE `admin_password_resets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `idx_password_reset_lookup` (`admin_id`,`expires_at`,`used_at`);

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
-- Indexes for table `certificates`
--
ALTER TABLE `certificates`
  ADD PRIMARY KEY (`id`);

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
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `recruitment_links`
--
ALTER TABLE `recruitment_links`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `fk_recruitment_links_agent` (`agent_id`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `site_feedback`
--
ALTER TABLE `site_feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_site_feedback_created` (`created_at`),
  ADD KEY `idx_site_feedback_ip` (`ip_hash`,`created_at`),
  ADD KEY `fk_site_feedback_user` (`user_id`);

--
-- Indexes for table `skills`
--
ALTER TABLE `skills`
  ADD PRIMARY KEY (`id`);

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
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `admin_password_resets`
--
ALTER TABLE `admin_password_resets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

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
-- AUTO_INCREMENT for table `certificates`
--
ALTER TABLE `certificates`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

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
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

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
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `recruitment_links`
--
ALTER TABLE `recruitment_links`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `site_feedback`
--
ALTER TABLE `site_feedback`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `skills`
--
ALTER TABLE `skills`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `training_records`
--
ALTER TABLE `training_records`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=90;

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
-- Constraints for table `admin_password_resets`
--
ALTER TABLE `admin_password_resets`
  ADD CONSTRAINT `fk_password_reset_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE;

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

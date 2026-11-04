-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 20, 2026 at 07:33 AM
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
-- Database: `capstone_culture_arts`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_logs`
--

CREATE TABLE `admin_logs` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `admin_name` varchar(255) DEFAULT NULL,
  `admin_role` varchar(50) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `action_type` enum('create','update','delete','login','logout','approve','reject','export','import','configure') DEFAULT NULL,
  `module` varchar(100) DEFAULT NULL,
  `target_type` varchar(100) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `target_user_id` int(11) DEFAULT NULL,
  `target_description` varchar(255) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `changes_made` text DEFAULT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `session_id` varchar(100) DEFAULT NULL,
  `status` enum('success','failed','pending') DEFAULT 'success',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `summary` text DEFAULT NULL,
  `category` varchar(100) DEFAULT 'general',
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `target_audience` enum('all','students','head','central') DEFAULT 'all',
  `target_campus` varchar(100) DEFAULT 'all',
  `target_college` varchar(255) DEFAULT 'all',
  `target_program` varchar(255) DEFAULT 'all',
  `target_year_level` varchar(50) DEFAULT 'all',
  `target_cultural_group` varchar(255) DEFAULT 'all',
  `attachment_url` varchar(255) DEFAULT NULL,
  `attachment_type` varchar(50) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `is_pinned` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `is_published` tinyint(1) DEFAULT 0,
  `publish_date` datetime DEFAULT NULL,
  `expiry_date` datetime DEFAULT NULL,
  `view_count` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `published_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `applications`
--

CREATE TABLE `applications` (
  `id` int(11) NOT NULL,
  `application_type` enum('performer_profile','student_artist','renewal') DEFAULT 'performer_profile',
  `performance_type` text DEFAULT NULL,
  `consent` enum('yes','no') NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `suffix` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `present_address` text DEFAULT NULL,
  `permanent_address` text DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `sex` enum('Male','Female','Other') DEFAULT NULL,
  `civil_status` enum('Single','Married','Widowed','Separated','Divorced') DEFAULT 'Single',
  `nationality` varchar(100) DEFAULT 'Filipino',
  `religion` varchar(100) DEFAULT NULL,
  `place_of_birth` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `father_name` varchar(100) DEFAULT NULL,
  `father_occupation` varchar(255) DEFAULT NULL,
  `father_contact` varchar(20) DEFAULT NULL,
  `mother_name` varchar(100) DEFAULT NULL,
  `mother_occupation` varchar(255) DEFAULT NULL,
  `mother_contact` varchar(20) DEFAULT NULL,
  `guardian` varchar(100) DEFAULT NULL,
  `guardian_name` varchar(255) DEFAULT NULL,
  `guardian_relationship` varchar(100) DEFAULT NULL,
  `guardian_contact` varchar(20) DEFAULT NULL,
  `guardian_address` text DEFAULT NULL,
  `emergency_contact_name` varchar(255) DEFAULT NULL,
  `emergency_contact_number` varchar(20) DEFAULT NULL,
  `emergency_contact_relationship` varchar(100) DEFAULT NULL,
  `campus` varchar(100) DEFAULT NULL,
  `college` varchar(100) DEFAULT NULL,
  `sr_code` varchar(50) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `program` varchar(100) DEFAULT NULL,
  `major` varchar(255) DEFAULT NULL,
  `section` varchar(50) DEFAULT NULL,
  `student_type` enum('Regular','Irregular','Returnee','Transferee') DEFAULT 'Regular',
  `first_semester_units` int(11) DEFAULT NULL,
  `second_semester_units` int(11) DEFAULT NULL,
  `instructors` text DEFAULT NULL,
  `current_gwa` decimal(3,2) DEFAULT NULL,
  `previous_gwa` decimal(3,2) DEFAULT NULL,
  `is_scholar` tinyint(1) DEFAULT 0,
  `scholarship_type` varchar(255) DEFAULT NULL,
  `scholarship_provider` varchar(255) DEFAULT NULL,
  `cultural_group` varchar(255) DEFAULT NULL,
  `other_cultural_group` varchar(255) DEFAULT NULL,
  `talents` text DEFAULT NULL,
  `hobbies` text DEFAULT NULL,
  `skills` text DEFAULT NULL,
  `interests` text DEFAULT NULL,
  `awards_recognition` text DEFAULT NULL,
  `previous_performances` text DEFAULT NULL,
  `performance_links` text DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `id_card_front` varchar(255) DEFAULT NULL,
  `id_card_back` varchar(255) DEFAULT NULL,
  `certificate_of_registration` varchar(255) DEFAULT NULL,
  `birth_certificate` varchar(255) DEFAULT NULL,
  `grades_screenshot` varchar(255) DEFAULT NULL,
  `signature_image` varchar(255) DEFAULT NULL,
  `signature_date` date DEFAULT NULL,
  `additional_documents` text DEFAULT NULL,
  `certification` tinyint(1) DEFAULT 0,
  `application_status` enum('pending','under_review','approved','rejected','requires_documents','for_interview','on_hold') DEFAULT 'pending',
  `status` enum('pending','approved','rejected','for_interview','on_hold') DEFAULT 'pending',
  `application_period` varchar(100) DEFAULT NULL,
  `application_year` year(4) DEFAULT NULL,
  `application_letter` text DEFAULT NULL,
  `registration_password_hash` varchar(255) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `interview_date` datetime DEFAULT NULL,
  `interview_notes` text DEFAULT NULL,
  `interviewer_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `admin_remarks` text DEFAULT NULL,
  `submission_date` datetime DEFAULT NULL,
  `submitted_by` int(11) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_date` datetime DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `last_updated_by` int(11) DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `review_date` datetime DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `application_affiliations`
--

CREATE TABLE `application_affiliations` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `position` varchar(100) DEFAULT NULL,
  `organization` varchar(150) DEFAULT NULL,
  `years_active` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `application_competition`
--

CREATE TABLE `application_competition` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `competition_date` date DEFAULT NULL,
  `event_name` varchar(200) DEFAULT NULL,
  `competition_level` enum('School','Municipal','Provincial','Regional','National','International') DEFAULT NULL,
  `rank_award` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `application_participation`
--

CREATE TABLE `application_participation` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `participation_date` date DEFAULT NULL,
  `event_name` varchar(200) DEFAULT NULL,
  `participation_level` enum('local','regional','national','international') DEFAULT NULL,
  `rank_award` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `borrowing_requests`
--

CREATE TABLE `borrowing_requests` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `student_name` varchar(255) NOT NULL,
  `student_email` varchar(255) NOT NULL,
  `student_course` varchar(255) DEFAULT NULL,
  `student_year` varchar(50) DEFAULT NULL,
  `student_campus` varchar(100) DEFAULT NULL,
  `student_contact` varchar(20) DEFAULT NULL,
  `item_id` int(11) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_category` varchar(100) DEFAULT NULL,
  `quantity_requested` int(11) NOT NULL DEFAULT 1,
  `quantity_approved` int(11) DEFAULT NULL,
  `approved_items` text DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `event_details` text DEFAULT NULL,
  `venue` varchar(255) DEFAULT NULL,
  `dates_of_use` varchar(255) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `actual_return_date` date DEFAULT NULL,
  `expected_return_time` time DEFAULT NULL,
  `status` enum('pending','approved','rejected','borrowed','returned','overdue','cancelled') DEFAULT 'pending',
  `current_status` enum('active','pending_return','returned','overdue') DEFAULT 'active',
  `approval_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_date` datetime DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `approval_notes` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `borrowing_slip_number` varchar(50) DEFAULT NULL,
  `released_by` int(11) DEFAULT NULL,
  `released_date` datetime DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `received_date` datetime DEFAULT NULL,
  `return_condition` enum('excellent','good','fair','damaged') DEFAULT NULL,
  `return_notes` text DEFAULT NULL,
  `penalty_amount` decimal(10,2) DEFAULT 0.00,
  `penalty_reason` text DEFAULT NULL,
  `is_late_return` tinyint(1) DEFAULT 0,
  `late_days` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `deleted_students`
--

CREATE TABLE `deleted_students` (
  `id` int(11) NOT NULL,
  `original_id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `sr_code` varchar(20) NOT NULL,
  `email` varchar(100) NOT NULL,
  `course` varchar(100) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `campus` varchar(100) DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deletion_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `evaluation_summary`
--

CREATE TABLE `evaluation_summary` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `total_responses` int(11) DEFAULT 0,
  `average_rating` decimal(3,2) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `venue` varchar(255) DEFAULT NULL,
  `category` varchar(100) DEFAULT 'other',
  `event_type` varchar(100) DEFAULT NULL,
  `target_participants` text DEFAULT NULL,
  `cultural_groups` text DEFAULT NULL,
  `max_participants` int(11) DEFAULT NULL,
  `current_participants` int(11) DEFAULT 0,
  `registration_deadline` date DEFAULT NULL,
  `registration_fee` decimal(10,2) DEFAULT 0.00,
  `requirements` text DEFAULT NULL,
  `objectives` text DEFAULT NULL,
  `expected_outcomes` text DEFAULT NULL,
  `event_poster` varchar(255) DEFAULT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `contact_email` varchar(100) DEFAULT NULL,
  `status` enum('draft','published','ongoing','completed','cancelled') DEFAULT 'draft',
  `is_featured` tinyint(1) DEFAULT 0,
  `allow_registration` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `campus` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `event_evaluations`
--

CREATE TABLE `event_evaluations` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `q1_rating` int(11) NOT NULL COMMENT 'Overall rating of seminar/training',
  `q2_rating` int(11) NOT NULL COMMENT 'Appropriateness of time and resources',
  `q3_rating` int(11) NOT NULL COMMENT 'Objectives and expectations achieved',
  `q4_rating` int(11) NOT NULL COMMENT 'Session activities relevance',
  `q5_rating` int(11) NOT NULL COMMENT 'Sufficient time for discussion',
  `q6_rating` int(11) NOT NULL COMMENT 'Materials and tasks usefulness',
  `q7_rating` int(11) NOT NULL COMMENT 'Trainer knowledge and insights',
  `q8_rating` int(11) NOT NULL COMMENT 'Trainer explanation quality',
  `q9_rating` int(11) NOT NULL COMMENT 'Learning environment',
  `q10_rating` int(11) NOT NULL COMMENT 'Time management',
  `q11_rating` int(11) NOT NULL COMMENT 'Trainer responsiveness to needs',
  `q12_rating` int(11) NOT NULL COMMENT 'Venue/platform conduciveness',
  `q13_opinion` text NOT NULL COMMENT 'Was the training helpful? Why or why not?',
  `q14_suggestions` text NOT NULL COMMENT 'Helpful aspects and future topic suggestions',
  `q15_comments` text NOT NULL COMMENT 'Comments/Recommendations/Complaints',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `event_participants`
--

CREATE TABLE `event_participants` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `student_name` varchar(255) DEFAULT NULL,
  `student_sr_code` varchar(20) DEFAULT NULL,
  `student_email` varchar(255) DEFAULT NULL,
  `student_contact` varchar(20) DEFAULT NULL,
  `student_campus` varchar(100) DEFAULT NULL,
  `student_program` varchar(255) DEFAULT NULL,
  `student_year` varchar(50) DEFAULT NULL,
  `registration_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `registration_type` enum('online','walk_in','invited','admin_added') DEFAULT 'online',
  `payment_status` enum('unpaid','paid','waived','pending') DEFAULT 'unpaid',
  `payment_amount` decimal(10,2) DEFAULT 0.00,
  `payment_reference` varchar(100) DEFAULT NULL,
  `payment_date` datetime DEFAULT NULL,
  `attendance_status` enum('registered','attended','absent','cancelled','late') DEFAULT 'registered',
  `attendance_marked_at` timestamp NULL DEFAULT NULL,
  `attendance_marked_by` int(11) DEFAULT NULL,
  `check_in_time` datetime DEFAULT NULL,
  `check_out_time` datetime DEFAULT NULL,
  `participation_hours` decimal(4,2) DEFAULT 0.00,
  `certificate_issued` tinyint(1) DEFAULT 0,
  `certificate_issue_date` datetime DEFAULT NULL,
  `certificate_number` varchar(100) DEFAULT NULL,
  `feedback_submitted` tinyint(1) DEFAULT 0,
  `feedback_date` datetime DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `id` int(11) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_code` varchar(50) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `subcategory` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `size` varchar(50) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `material` varchar(100) DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `available_quantity` int(11) NOT NULL DEFAULT 0,
  `borrowed_quantity` int(11) DEFAULT 0,
  `damaged_quantity` int(11) DEFAULT 0,
  `status` enum('available','borrowed','maintenance','reserved','retired','archived') DEFAULT 'available',
  `condition_status` enum('excellent','good','fair','poor','damaged','under_repair') DEFAULT 'good',
  `location` varchar(255) DEFAULT NULL,
  `storage_location` varchar(255) DEFAULT NULL,
  `rack_number` varchar(50) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `purchase_price` decimal(10,2) DEFAULT NULL,
  `current_value` decimal(10,2) DEFAULT NULL,
  `supplier` varchar(255) DEFAULT NULL,
  `warranty_expiry` date DEFAULT NULL,
  `last_maintenance_date` date DEFAULT NULL,
  `next_maintenance_date` date DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `qr_code` varchar(255) DEFAULT NULL,
  `is_borrowable` tinyint(1) DEFAULT 1,
  `min_stock_level` int(11) DEFAULT 5,
  `max_borrowing_days` int(11) DEFAULT 7,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `campus` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `question_analysis`
--

CREATE TABLE `question_analysis` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `question_number` int(11) NOT NULL,
  `question_text` varchar(500) NOT NULL,
  `avg_score` decimal(3,2) DEFAULT NULL,
  `response_count` int(11) DEFAULT 0,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `repair_items`
--

CREATE TABLE `repair_items` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `category` enum('costume','equipment') NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `repair_status` enum('damaged','under_repair','repaired') NOT NULL DEFAULT 'damaged',
  `date_reported` datetime NOT NULL DEFAULT current_timestamp(),
  `reported_by_student_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `return_requests`
--

CREATE TABLE `return_requests` (
  `id` int(11) NOT NULL,
  `borrowing_request_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `student_name` varchar(255) DEFAULT NULL,
  `student_sr_code` varchar(20) DEFAULT NULL,
  `item_id` int(11) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_category` varchar(100) DEFAULT NULL,
  `quantity_returned` int(11) DEFAULT 1,
  `return_date` date DEFAULT NULL,
  `return_time` time DEFAULT NULL,
  `return_condition` enum('excellent','good','fair','damaged','lost') DEFAULT 'good',
  `condition_notes` text DEFAULT NULL,
  `damage_description` text DEFAULT NULL,
  `damage_assessment` text DEFAULT NULL,
  `missing_items` text DEFAULT NULL,
  `penalty_amount` decimal(10,2) DEFAULT 0.00,
  `penalty_reason` text DEFAULT NULL,
  `is_late_return` tinyint(1) DEFAULT 0,
  `late_days` int(11) DEFAULT 0,
  `replacement_required` tinyint(1) DEFAULT 0,
  `replacement_cost` decimal(10,2) DEFAULT 0.00,
  `status` enum('pending','completed','cancelled','needs_inspection') DEFAULT 'pending',
  `inspection_notes` text DEFAULT NULL,
  `inspection_by` int(11) DEFAULT NULL,
  `inspection_date` datetime DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `completed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_affiliation_records`
--

CREATE TABLE `student_affiliation_records` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `position` varchar(100) DEFAULT NULL,
  `organization` varchar(150) DEFAULT NULL,
  `years_active` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_artists`
--

CREATE TABLE `student_artists` (
  `id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `sr_code` varchar(20) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `course` varchar(100) DEFAULT NULL,
  `program` varchar(100) DEFAULT NULL,
  `year_level` varchar(20) DEFAULT NULL,
  `campus` varchar(100) DEFAULT NULL,
  `college` varchar(100) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `present_address` text DEFAULT NULL,
  `permanent_address` text DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `place_of_birth` varchar(100) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `civil_status` enum('single','married','widowed','separated') DEFAULT 'single',
  `nationality` varchar(50) DEFAULT 'Filipino',
  `religion` varchar(50) DEFAULT NULL,
  `father_name` varchar(100) DEFAULT NULL,
  `mother_name` varchar(100) DEFAULT NULL,
  `guardian` varchar(100) DEFAULT NULL,
  `guardian_name` varchar(100) DEFAULT NULL,
  `guardian_contact` varchar(20) DEFAULT NULL,
  `emergency_contact_name` varchar(100) DEFAULT NULL,
  `emergency_contact_number` varchar(20) DEFAULT NULL,
  `emergency_contact_relationship` varchar(50) DEFAULT NULL,
  `skills` text DEFAULT NULL,
  `talents` text DEFAULT NULL,
  `interests` text DEFAULT NULL,
  `hobbies` text DEFAULT NULL,
  `performance_experience` text DEFAULT NULL,
  `awards_recognition` text DEFAULT NULL,
  `cultural_group` varchar(100) DEFAULT NULL,
  `affiliation_position` varchar(100) DEFAULT NULL,
  `affiliation_organization` varchar(150) DEFAULT NULL,
  `affiliation_years` varchar(50) DEFAULT NULL,
  `performance_type` text DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `signature_image` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive','suspended','graduated') DEFAULT 'active',
  `is_archived` tinyint(1) DEFAULT 0,
  `enrollment_date` date DEFAULT NULL,
  `graduation_date` date DEFAULT NULL,
  `is_scholar` tinyint(1) DEFAULT 0,
  `scholarship_type` varchar(100) DEFAULT NULL,
  `first_semester_units` int(11) DEFAULT NULL,
  `second_semester_units` int(11) DEFAULT NULL,
  `instructors` text DEFAULT NULL,
  `gwa` decimal(3,2) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_certificates`
--

CREATE TABLE `student_certificates` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `student_name` varchar(255) DEFAULT NULL,
  `student_sr_code` varchar(20) DEFAULT NULL,
  `certificate_type` varchar(100) NOT NULL,
  `certificate_name` varchar(255) NOT NULL,
  `certificate_title` varchar(500) DEFAULT NULL,
  `event_id` int(11) DEFAULT NULL,
  `event_name` varchar(255) DEFAULT NULL,
  `event_date` date DEFAULT NULL,
  `issued_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `issued_by` int(11) DEFAULT NULL,
  `issued_by_name` varchar(255) DEFAULT NULL,
  `issuer_position` varchar(255) DEFAULT NULL,
  `certificate_file` varchar(255) DEFAULT NULL,
  `certificate_url` varchar(500) DEFAULT NULL,
  `certificate_number` varchar(100) DEFAULT NULL,
  `template_used` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `achievement_details` text DEFAULT NULL,
  `award_category` varchar(100) DEFAULT NULL,
  `performance_score` decimal(5,2) DEFAULT NULL,
  `hours_completed` decimal(5,2) DEFAULT NULL,
  `signature_image` varchar(255) DEFAULT NULL,
  `seal_image` varchar(255) DEFAULT NULL,
  `qr_code` varchar(255) DEFAULT NULL,
  `verification_code` varchar(50) DEFAULT NULL,
  `verification_url` varchar(500) DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `is_published` tinyint(1) DEFAULT 0,
  `is_digital` tinyint(1) DEFAULT 1,
  `download_count` int(11) DEFAULT 0,
  `last_downloaded_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `metadata` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_competition_records`
--

CREATE TABLE `student_competition_records` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `competition_date` date DEFAULT NULL,
  `event_name` varchar(200) DEFAULT NULL,
  `competition_level` enum('School','Municipal','Provincial','Regional','National','International') DEFAULT NULL,
  `rank_award` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_participation_records`
--

CREATE TABLE `student_participation_records` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `participation_date` date DEFAULT NULL,
  `event_name` varchar(200) DEFAULT NULL,
  `participation_level` enum('School','Municipal','Provincial','Regional','National','International') DEFAULT NULL,
  `rank_award` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(50) DEFAULT 'general',
  `is_editable` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('student','head','central','admin','director') NOT NULL,
  `campus` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_logs`
--
ALTER TABLE `admin_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `target_user_id` (`target_user_id`),
  ADD KEY `idx_admin_logs_created_at` (`created_at`),
  ADD KEY `idx_admin_logs_status` (`status`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `updated_by` (`updated_by`),
  ADD KEY `published_by` (`published_by`),
  ADD KEY `idx_announcements_active` (`is_active`),
  ADD KEY `idx_announcements_published` (`is_published`),
  ADD KEY `idx_announcements_priority` (`priority`),
  ADD KEY `idx_announcements_target` (`target_audience`),
  ADD KEY `idx_announcements_campus` (`target_campus`),
  ADD KEY `idx_announcements_pinned` (`is_pinned`),
  ADD KEY `idx_announcements_dates` (`publish_date`,`expiry_date`);

--
-- Indexes for table `applications`
--
ALTER TABLE `applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `submitted_by` (`submitted_by`),
  ADD KEY `reviewed_by` (`reviewed_by`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `interviewer_id` (`interviewer_id`),
  ADD KEY `last_updated_by` (`last_updated_by`),
  ADD KEY `idx_applications_type` (`application_type`),
  ADD KEY `idx_applications_status` (`application_status`),
  ADD KEY `idx_applications_sr_code` (`sr_code`),
  ADD KEY `idx_applications_campus` (`campus`),
  ADD KEY `idx_applications_cultural_group` (`cultural_group`),
  ADD KEY `idx_applications_year` (`application_year`),
  ADD KEY `idx_applications_submitted_at` (`submitted_at`);

--
-- Indexes for table `application_affiliations`
--
ALTER TABLE `application_affiliations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`);

--
-- Indexes for table `application_competition`
--
ALTER TABLE `application_competition`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`);

--
-- Indexes for table `application_participation`
--
ALTER TABLE `application_participation`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`);

--
-- Indexes for table `borrowing_requests`
--
ALTER TABLE `borrowing_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `borrowing_slip_number` (`borrowing_slip_number`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `idx_borrowing_requests_status` (`status`),
  ADD KEY `idx_borrowing_requests_current_status` (`current_status`),
  ADD KEY `idx_borrowing_requests_dates` (`start_date`,`end_date`),
  ADD KEY `idx_borrowing_requests_due_date` (`due_date`),
  ADD KEY `idx_borrowing_requests_created` (`created_at`);

--
-- Indexes for table `deleted_students`
--
ALTER TABLE `deleted_students`
  ADD PRIMARY KEY (`id`),
  ADD KEY `deleted_by` (`deleted_by`),
  ADD KEY `idx_deleted_students_sr_code` (`sr_code`);

--
-- Indexes for table `evaluation_summary`
--
ALTER TABLE `evaluation_summary`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `event_id` (`event_id`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_events_status` (`status`),
  ADD KEY `idx_events_category` (`category`),
  ADD KEY `idx_events_dates` (`start_date`,`end_date`),
  ADD KEY `idx_events_created_at` (`created_at`),
  ADD KEY `idx_events_campus` (`campus`);

--
-- Indexes for table `event_evaluations`
--
ALTER TABLE `event_evaluations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_student_event_evaluation` (`event_id`,`student_id`),
  ADD KEY `idx_event_evaluations_event` (`event_id`),
  ADD KEY `idx_event_evaluations_student` (`student_id`),
  ADD KEY `idx_event_evaluations_submitted` (`submitted_at`);

--
-- Indexes for table `event_participants`
--
ALTER TABLE `event_participants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_event_participant` (`event_id`,`student_id`),
  ADD UNIQUE KEY `certificate_number` (`certificate_number`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `attendance_marked_by` (`attendance_marked_by`),
  ADD KEY `cancelled_by` (`cancelled_by`),
  ADD KEY `idx_event_participants_student` (`student_id`),
  ADD KEY `idx_event_participants_status` (`attendance_status`),
  ADD KEY `idx_event_participants_payment` (`payment_status`),
  ADD KEY `idx_event_participants_registration` (`registration_date`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `item_code` (`item_code`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_inventory_category` (`category`),
  ADD KEY `idx_inventory_status` (`status`),
  ADD KEY `idx_inventory_condition` (`condition_status`),
  ADD KEY `idx_inventory_name` (`item_name`),
  ADD KEY `idx_inventory_barcode` (`barcode`),
  ADD KEY `idx_inventory_campus` (`campus`);

--
-- Indexes for table `question_analysis`
--
ALTER TABLE `question_analysis`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_event_question` (`event_id`,`question_number`);

--
-- Indexes for table `repair_items`
--
ALTER TABLE `repair_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `idx_repair_status` (`repair_status`);

--
-- Indexes for table `return_requests`
--
ALTER TABLE `return_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `borrowing_request_id` (`borrowing_request_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `completed_by` (`completed_by`),
  ADD KEY `inspection_by` (`inspection_by`),
  ADD KEY `idx_return_requests_status` (`status`),
  ADD KEY `idx_return_requests_requested_at` (`requested_at`),
  ADD KEY `idx_return_requests_return_date` (`return_date`),
  ADD KEY `idx_return_requests_condition` (`return_condition`);

--
-- Indexes for table `student_affiliation_records`
--
ALTER TABLE `student_affiliation_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `idx_affiliation_student` (`student_id`);

--
-- Indexes for table `student_artists`
--
ALTER TABLE `student_artists`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sr_code` (`sr_code`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_student_artists_email` (`email`),
  ADD KEY `idx_student_artists_sr_code` (`sr_code`),
  ADD KEY `idx_student_artists_status` (`status`),
  ADD KEY `idx_student_artists_campus` (`campus`),
  ADD KEY `idx_student_artists_course` (`course`),
  ADD KEY `idx_student_artists_cultural_group` (`cultural_group`);

--
-- Indexes for table `student_certificates`
--
ALTER TABLE `student_certificates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `certificate_number` (`certificate_number`),
  ADD UNIQUE KEY `verification_code` (`verification_code`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `event_id` (`event_id`),
  ADD KEY `issued_by` (`issued_by`),
  ADD KEY `idx_student_certificates_student` (`student_id`),
  ADD KEY `idx_student_certificates_type` (`certificate_type`),
  ADD KEY `idx_student_certificates_date` (`issued_date`),
  ADD KEY `idx_student_certificates_verification` (`verification_code`),
  ADD KEY `idx_student_certificates_event` (`event_id`);

--
-- Indexes for table `student_competition_records`
--
ALTER TABLE `student_competition_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `idx_competition_student` (`student_id`),
  ADD KEY `idx_competition_date` (`competition_date`);

--
-- Indexes for table `student_participation_records`
--
ALTER TABLE `student_participation_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `idx_participation_student` (`student_id`),
  ADD KEY `idx_participation_date` (`participation_date`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `idx_system_settings_category` (`category`),
  ADD KEY `idx_system_settings_key` (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_email` (`email`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_status` (`status`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_logs`
--
ALTER TABLE `admin_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `applications`
--
ALTER TABLE `applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `application_affiliations`
--
ALTER TABLE `application_affiliations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `application_competition`
--
ALTER TABLE `application_competition`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `application_participation`
--
ALTER TABLE `application_participation`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `borrowing_requests`
--
ALTER TABLE `borrowing_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `deleted_students`
--
ALTER TABLE `deleted_students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `evaluation_summary`
--
ALTER TABLE `evaluation_summary`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `event_evaluations`
--
ALTER TABLE `event_evaluations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `event_participants`
--
ALTER TABLE `event_participants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `question_analysis`
--
ALTER TABLE `question_analysis`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `repair_items`
--
ALTER TABLE `repair_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `return_requests`
--
ALTER TABLE `return_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_affiliation_records`
--
ALTER TABLE `student_affiliation_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_artists`
--
ALTER TABLE `student_artists`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_certificates`
--
ALTER TABLE `student_certificates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_competition_records`
--
ALTER TABLE `student_competition_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_participation_records`
--
ALTER TABLE `student_participation_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin_logs`
--
ALTER TABLE `admin_logs`
  ADD CONSTRAINT `admin_logs_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `admin_logs_ibfk_2` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `announcements`
--
ALTER TABLE `announcements`
  ADD CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `announcements_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `announcements_ibfk_3` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `applications`
--
ALTER TABLE `applications`
  ADD CONSTRAINT `applications_ibfk_1` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `applications_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `applications_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `applications_ibfk_4` FOREIGN KEY (`interviewer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `applications_ibfk_5` FOREIGN KEY (`last_updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `application_affiliations`
--
ALTER TABLE `application_affiliations`
  ADD CONSTRAINT `application_affiliations_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `application_competition`
--
ALTER TABLE `application_competition`
  ADD CONSTRAINT `application_competition_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `application_participation`
--
ALTER TABLE `application_participation`
  ADD CONSTRAINT `application_participation_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `borrowing_requests`
--
ALTER TABLE `borrowing_requests`
  ADD CONSTRAINT `borrowing_requests_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `student_artists` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `borrowing_requests_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `borrowing_requests_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `evaluation_summary`
--
ALTER TABLE `evaluation_summary`
  ADD CONSTRAINT `evaluation_summary_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `events`
--
ALTER TABLE `events`
  ADD CONSTRAINT `events_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `event_evaluations`
--
ALTER TABLE `event_evaluations`
  ADD CONSTRAINT `event_evaluations_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `event_evaluations_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `student_artists` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `event_participants`
--
ALTER TABLE `event_participants`
  ADD CONSTRAINT `event_participants_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `event_participants_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `student_artists` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `event_participants_ibfk_3` FOREIGN KEY (`attendance_marked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `event_participants_ibfk_4` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `question_analysis`
--
ALTER TABLE `question_analysis`
  ADD CONSTRAINT `question_analysis_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `return_requests`
--
ALTER TABLE `return_requests`
  ADD CONSTRAINT `return_requests_ibfk_1` FOREIGN KEY (`borrowing_request_id`) REFERENCES `borrowing_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `return_requests_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `student_artists` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `return_requests_ibfk_3` FOREIGN KEY (`item_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `return_requests_ibfk_4` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `return_requests_ibfk_5` FOREIGN KEY (`inspection_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `student_affiliation_records`
--
ALTER TABLE `student_affiliation_records`
  ADD CONSTRAINT `student_affiliation_records_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `student_artists` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_certificates`
--
ALTER TABLE `student_certificates`
  ADD CONSTRAINT `student_certificates_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `student_artists` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `student_certificates_ibfk_2` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `student_certificates_ibfk_3` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `student_competition_records`
--
ALTER TABLE `student_competition_records`
  ADD CONSTRAINT `student_competition_records_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `student_artists` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_participation_records`
--
ALTER TABLE `student_participation_records`
  ADD CONSTRAINT `student_participation_records_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `student_artists` (`id`) ON DELETE CASCADE;

--
-- Seed admin account
--
INSERT INTO `users` (`first_name`, `middle_name`, `last_name`, `email`, `password`, `role`, `campus`, `status`)
VALUES ('Admin', NULL, 'Role', 'admin@g.batstate-u.edu.ph', '$2y$10$KD4A.8Y7TyvKXQI52vqgAe/BX4m3T8mqcIp86JCECKCZQNyl2ubSu', 'admin', NULL, 'active');

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

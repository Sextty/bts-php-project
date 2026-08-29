/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `app_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `notifiable_type` varchar(255) NOT NULL,
  `notifiable_id` bigint(20) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `dedupe_key` varchar(255) DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `app_notifications_dedupe_unique` (`notifiable_type`,`notifiable_id`,`type`,`dedupe_key`),
  KEY `app_notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `application_number_counters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `application_number_counters` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `year` smallint(5) unsigned NOT NULL,
  `type` varchar(10) NOT NULL DEFAULT 'CR',
  `last_number` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `application_number_counters_year_type_unique` (`year`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appointments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `credit_application_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `attempt_number` tinyint(3) unsigned NOT NULL,
  `scheduled_date` date NOT NULL,
  `scheduled_time` time NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'proposed',
  `is_auto_scheduled_future` tinyint(1) NOT NULL DEFAULT 0,
  `decided_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `appointments_branch_id_scheduled_date_index` (`branch_id`,`scheduled_date`),
  KEY `appointments_credit_application_id_attempt_number_index` (`credit_application_id`,`attempt_number`),
  CONSTRAINT `appointments_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `appointments_credit_application_id_foreign` FOREIGN KEY (`credit_application_id`) REFERENCES `credit_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `staff_user_id` bigint(20) unsigned DEFAULT NULL,
  `credit_application_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `previous_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`previous_state`)),
  `new_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_state`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `audit_logs_user_id_action_index` (`user_id`,`action`),
  KEY `audit_logs_created_at_index` (`created_at`),
  KEY `audit_logs_credit_application_id_action_index` (`credit_application_id`,`action`),
  KEY `audit_logs_staff_user_id_action_index` (`staff_user_id`,`action`),
  KEY `audit_logs_credit_application_id_index` (`credit_application_id`),
  CONSTRAINT `audit_logs_credit_application_id_foreign` FOREIGN KEY (`credit_application_id`) REFERENCES `credit_applications` (`id`) ON DELETE SET NULL,
  CONSTRAINT `audit_logs_staff_user_id_foreign` FOREIGN KEY (`staff_user_id`) REFERENCES `staff_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `ville` varchar(255) NOT NULL,
  `delegation` varchar(255) DEFAULT NULL,
  `address` varchar(255) NOT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `fax` varchar(255) DEFAULT NULL,
  `opening_hours` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `daily_capacity` tinyint(3) unsigned NOT NULL DEFAULT 4,
  `slot_start_time` time NOT NULL DEFAULT '08:00:00',
  `slot_end_time` time NOT NULL DEFAULT '12:00:00',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `branches_ville_index` (`ville`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `credit_application_id` bigint(20) unsigned NOT NULL,
  `code_client` varchar(255) DEFAULT NULL,
  `civilite` varchar(255) DEFAULT NULL,
  `nom` varchar(255) DEFAULT NULL,
  `prenom` varchar(255) DEFAULT NULL,
  `nom_epoux` varchar(255) DEFAULT NULL,
  `deuxieme_prenom` varchar(255) DEFAULT NULL,
  `date_naissance` date DEFAULT NULL,
  `lieu_naissance` varchar(255) DEFAULT NULL,
  `pays_naissance` varchar(255) DEFAULT NULL,
  `nationalite` varchar(255) DEFAULT NULL,
  `pays_residence` varchar(255) DEFAULT NULL,
  `etat_civil` varchar(255) DEFAULT NULL,
  `nombre_enfants` smallint(5) unsigned DEFAULT NULL,
  `type_pid` varchar(255) DEFAULT NULL,
  `numero_pid` varchar(255) DEFAULT NULL,
  `date_delivrance_pid` date DEFAULT NULL,
  `lieu_delivrance_pid` varchar(255) DEFAULT NULL,
  `numero_carte_sejour` varchar(255) DEFAULT NULL,
  `profession` varchar(255) DEFAULT NULL,
  `date_entree_relation` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clients_credit_application_id_unique` (`credit_application_id`),
  CONSTRAINT `clients_credit_application_id_foreign` FOREIGN KEY (`credit_application_id`) REFERENCES `credit_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `credit_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `credit_applications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'DRAFT',
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `report_closed_at` timestamp NULL DEFAULT NULL,
  `report_closed_by_staff_id` bigint(20) unsigned DEFAULT NULL,
  `report_closed_reason` varchar(500) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `decided_by_staff_user_id` bigint(20) unsigned DEFAULT NULL,
  `decided_by_admin_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `credit_applications_user_id_status_index` (`user_id`,`status`),
  KEY `credit_applications_decided_by_staff_user_id_foreign` (`decided_by_staff_user_id`),
  KEY `credit_applications_decided_by_admin_user_id_foreign` (`decided_by_admin_user_id`),
  KEY `credit_applications_branch_id_foreign` (`branch_id`),
  KEY `credit_applications_status_index` (`status`),
  KEY `credit_applications_created_at_index` (`created_at`),
  KEY `credit_applications_report_closed_by_staff_id_foreign` (`report_closed_by_staff_id`),
  CONSTRAINT `credit_applications_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `credit_applications_decided_by_admin_user_id_foreign` FOREIGN KEY (`decided_by_admin_user_id`) REFERENCES `staff_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `credit_applications_decided_by_staff_user_id_foreign` FOREIGN KEY (`decided_by_staff_user_id`) REFERENCES `staff_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `credit_applications_report_closed_by_staff_id_foreign` FOREIGN KEY (`report_closed_by_staff_id`) REFERENCES `staff_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `credit_applications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `credit_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `credit_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `credit_application_id` bigint(20) unsigned NOT NULL,
  `n_demande` varchar(255) DEFAULT NULL,
  `identifiant_personne` varchar(255) DEFAULT NULL,
  `nom_ou_rs` varchar(255) DEFAULT NULL,
  `prenom_ou_dc` varchar(255) DEFAULT NULL,
  `type_pid` varchar(255) DEFAULT NULL,
  `numero_pid` varchar(255) DEFAULT NULL,
  `origine` varchar(255) DEFAULT NULL,
  `date_depot` date DEFAULT NULL,
  `date_reception` date DEFAULT NULL,
  `type_demande` varchar(255) DEFAULT NULL,
  `code_devise` varchar(255) DEFAULT NULL,
  `montant_global_sollicite` decimal(14,3) DEFAULT NULL,
  `nombre_credits_sollicites` smallint(5) unsigned DEFAULT NULL,
  `unite_depot` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `credit_requests_credit_application_id_unique` (`credit_application_id`),
  UNIQUE KEY `credit_requests_n_demande_unique` (`n_demande`),
  CONSTRAINT `credit_requests_credit_application_id_foreign` FOREIGN KEY (`credit_application_id`) REFERENCES `credit_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `credit_application_id` bigint(20) unsigned NOT NULL,
  `document_type` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `disk_path` varchar(255) NOT NULL,
  `mime_type` varchar(255) NOT NULL,
  `size_bytes` bigint(20) unsigned NOT NULL,
  `ai_verified_at` timestamp NULL DEFAULT NULL,
  `ai_is_valid` tinyint(1) DEFAULT NULL,
  `ai_confidence` varchar(10) DEFAULT NULL,
  `ai_comment` text DEFAULT NULL,
  `ai_extracted_fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ai_extracted_fields`)),
  `ai_mismatches` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ai_mismatches`)),
  `ai_processing_status` varchar(255) DEFAULT NULL,
  `ai_detected_issues` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ai_detected_issues`)),
  `ai_requires_human_review` tinyint(1) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `documents_disk_path_unique` (`disk_path`),
  KEY `documents_credit_application_id_document_type_index` (`credit_application_id`,`document_type`),
  CONSTRAINT `documents_credit_application_id_foreign` FOREIGN KEY (`credit_application_id`) REFERENCES `credit_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `otp_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `otp_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `purpose` enum('registration','login','password_reset') NOT NULL,
  `channel` enum('sms','email') NOT NULL DEFAULT 'sms',
  `expires_at` datetime NOT NULL,
  `consumed_at` timestamp NULL DEFAULT NULL,
  `attempt_count` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `otp_codes_user_id_purpose_consumed_at_index` (`user_id`,`purpose`,`consumed_at`),
  CONSTRAINT `otp_codes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `projects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `credit_application_id` bigint(20) unsigned NOT NULL,
  `code_projet` varchar(255) DEFAULT NULL,
  `identifiant_personne` varchar(255) DEFAULT NULL,
  `nom_ou_rs` varchar(255) DEFAULT NULL,
  `prenom_ou_dc` varchar(255) DEFAULT NULL,
  `type_projet` varchar(255) DEFAULT NULL,
  `objet` varchar(255) DEFAULT NULL,
  `adresse` varchar(255) DEFAULT NULL,
  `ville` varchar(255) DEFAULT NULL,
  `code_postal` varchar(255) DEFAULT NULL,
  `activite` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `delegation` varchar(255) DEFAULT NULL,
  `localisation` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `cout` decimal(14,3) DEFAULT NULL,
  `investissement_personnel` decimal(14,3) DEFAULT NULL,
  `financement` decimal(14,3) DEFAULT NULL,
  `revenus` decimal(14,3) DEFAULT NULL,
  `depenses` decimal(14,3) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `projects_credit_application_id_unique` (`credit_application_id`),
  CONSTRAINT `projects_credit_application_id_foreign` FOREIGN KEY (`credit_application_id`) REFERENCES `credit_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `report_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `report_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `credit_application_id` bigint(20) unsigned NOT NULL,
  `sender_type` varchar(10) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `staff_user_id` bigint(20) unsigned DEFAULT NULL,
  `body` text NOT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `attachment_type` varchar(100) DEFAULT NULL,
  `attachment_size` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `report_messages_user_id_foreign` (`user_id`),
  KEY `report_messages_staff_user_id_foreign` (`staff_user_id`),
  KEY `report_messages_credit_application_id_created_at_index` (`credit_application_id`,`created_at`),
  CONSTRAINT `report_messages_credit_application_id_foreign` FOREIGN KEY (`credit_application_id`) REFERENCES `credit_applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `report_messages_staff_user_id_foreign` FOREIGN KEY (`staff_user_id`) REFERENCES `staff_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `report_messages_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `staff_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff_users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('staff','admin') NOT NULL DEFAULT 'staff',
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `staff_users_email_unique` (`email`),
  KEY `staff_users_branch_id_foreign` (`branch_id`),
  CONSTRAINT `staff_users_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `phone_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `auth_provider` enum('password','google') NOT NULL DEFAULT 'password',
  `status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `banned_at` timestamp NULL DEFAULT NULL,
  `banned_reason` text DEFAULT NULL,
  `banned_by_staff_id` bigint(20) unsigned DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_phone_unique` (`phone`),
  UNIQUE KEY `users_google_id_unique` (`google_id`),
  KEY `users_banned_by_staff_id_foreign` (`banned_by_staff_id`),
  CONSTRAINT `users_banned_by_staff_id_foreign` FOREIGN KEY (`banned_by_staff_id`) REFERENCES `staff_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `validation_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `validation_steps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `credit_application_id` bigint(20) unsigned NOT NULL,
  `step` enum('validation_1','validation_2') NOT NULL,
  `status` enum('passed','failed') NOT NULL,
  `errors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`errors`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `validation_steps_credit_application_id_step_index` (`credit_application_id`,`step`),
  CONSTRAINT `validation_steps_credit_application_id_foreign` FOREIGN KEY (`credit_application_id`) REFERENCES `credit_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_08_11_142408_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_08_11_142456_create_audit_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_08_11_142456_create_otp_codes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_08_11_190001_create_credit_applications_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_08_11_190002_create_clients_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_08_11_190003_create_credit_requests_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_08_11_190004_create_projects_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_08_11_190005_create_application_number_counters_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_08_11_190006_create_documents_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_08_11_190007_create_validation_steps_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_08_11_190008_add_credit_application_id_to_audit_logs_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_08_11_230001_add_telegram_chat_id_to_users_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_08_12_000001_add_telegram_link_token_to_users_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_08_12_100001_create_staff_users_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_08_12_100002_add_staff_user_id_to_audit_logs_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_08_12_100003_add_review_fields_to_credit_applications_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_08_12_120001_create_branches_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_08_12_120002_create_appointments_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_08_12_130001_create_report_messages_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_08_12_140001_drop_telegram_columns_from_users_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_08_12_140001_add_ai_verification_columns_to_documents_table',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_08_12_150001_add_type_to_application_number_counters_table',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_08_14_000001_add_ai_extraction_columns_to_documents_table',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_08_14_100001_add_branch_id_to_staff_users_table',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_08_14_100002_add_branch_id_to_credit_applications_table',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_08_14_150001_add_ai_structured_result_columns_to_documents_table',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_08_14_200001_add_dashboard_indexes_to_credit_applications_and_audit_logs',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_08_14_200001_create_app_notifications_table',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_08_14_200002_add_unique_index_to_documents_disk_path',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_08_17_082752_add_is_auto_scheduled_future_to_appointments_table',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_08_17_100001_add_phone_opening_hours_to_branches_table',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_08_20_000001_add_coordinates_to_projects_table',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_08_20_224343_add_banning_closing_and_attachments',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_08_20_235500_add_fax_to_branches_table',16);

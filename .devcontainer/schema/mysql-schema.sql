/*M!999999\- enable the sandbox mode */
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `activity_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_attempts` (
  `id` varchar(26) NOT NULL,
  `workflow_run_id` varchar(26) NOT NULL,
  `activity_execution_id` varchar(26) NOT NULL,
  `workflow_task_id` varchar(26) DEFAULT NULL,
  `worker_attempt_id` varchar(255) DEFAULT NULL,
  `attempt_number` int(10) unsigned NOT NULL,
  `status` varchar(255) NOT NULL,
  `lease_owner` varchar(255) DEFAULT NULL,
  `started_at` timestamp(6) NOT NULL,
  `last_heartbeat_at` timestamp(6) NULL DEFAULT NULL,
  `lease_expires_at` timestamp(6) NULL DEFAULT NULL,
  `closed_at` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `activity_attempts_activity_execution_id_attempt_number_unique` (`activity_execution_id`,`attempt_number`),
  KEY `activity_attempts_workflow_run_id_index` (`workflow_run_id`),
  KEY `activity_attempts_activity_execution_id_index` (`activity_execution_id`),
  KEY `activity_attempts_workflow_task_id_index` (`workflow_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `activity_executions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_executions` (
  `id` varchar(26) NOT NULL,
  `workflow_run_id` varchar(26) NOT NULL,
  `sequence` int(10) unsigned NOT NULL,
  `activity_class` varchar(255) NOT NULL,
  `activity_type` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `payload_codec` varchar(255) DEFAULT NULL,
  `arguments` longtext DEFAULT NULL,
  `result` longtext DEFAULT NULL,
  `exception` longtext DEFAULT NULL,
  `connection` varchar(255) DEFAULT NULL,
  `queue` varchar(255) DEFAULT NULL,
  `attempt_count` int(10) unsigned NOT NULL DEFAULT 1,
  `current_attempt_id` varchar(26) DEFAULT NULL,
  `retry_policy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`retry_policy`)),
  `parallel_group_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`parallel_group_path`)),
  `activity_options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`activity_options`)),
  `schedule_deadline_at` timestamp(6) NULL DEFAULT NULL,
  `close_deadline_at` timestamp(6) NULL DEFAULT NULL,
  `schedule_to_close_deadline_at` timestamp(6) NULL DEFAULT NULL,
  `heartbeat_deadline_at` timestamp(6) NULL DEFAULT NULL,
  `started_at` timestamp(6) NULL DEFAULT NULL,
  `closed_at` timestamp(6) NULL DEFAULT NULL,
  `last_heartbeat_at` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `activity_executions_workflow_run_id_sequence_unique` (`workflow_run_id`,`sequence`),
  KEY `activity_executions_workflow_run_id_index` (`workflow_run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_conversation_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_conversation_messages` (
  `id` varchar(36) NOT NULL,
  `conversation_id` varchar(36) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `agent` varchar(255) NOT NULL,
  `role` varchar(25) NOT NULL,
  `content` text NOT NULL,
  `attachments` text NOT NULL,
  `tool_calls` text NOT NULL,
  `tool_results` text NOT NULL,
  `usage` text NOT NULL,
  `meta` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `conversation_index` (`conversation_id`,`user_id`,`updated_at`),
  KEY `agent_conversation_messages_user_id_index` (`user_id`),
  KEY `agent_conversation_messages_conversation_id_index` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_conversations` (
  `id` varchar(36) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agent_conversations_user_id_updated_at_index` (`user_id`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_workflow_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_workflow_messages` (
  `reference` varchar(255) NOT NULL,
  `workflow_id` varchar(255) NOT NULL,
  `run_id` varchar(255) NOT NULL,
  `role` varchar(32) NOT NULL,
  `content` longtext NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`reference`),
  KEY `ai_workflow_messages_workflow_id_index` (`workflow_id`),
  KEY `ai_workflow_messages_run_id_index` (`run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `waterline_saved_views`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `waterline_saved_views` (
  `id` varchar(26) NOT NULL,
  `name` varchar(120) NOT NULL,
  `scope` varchar(120) NOT NULL DEFAULT 'default',
  `bucket` varchar(32) NOT NULL,
  `filters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`filters`)),
  `filter_version` smallint(5) unsigned NOT NULL DEFAULT 6,
  `shared` tinyint(1) NOT NULL DEFAULT 0,
  `owner_type` varchar(255) DEFAULT NULL,
  `owner_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `waterline_saved_views_scope_bucket_name_unique` (`scope`,`bucket`,`name`),
  KEY `waterline_saved_views_scope_bucket_index` (`scope`,`bucket`),
  KEY `waterline_saved_views_scope_bucket_shared_index` (`scope`,`bucket`,`shared`),
  KEY `waterline_saved_views_scope_bucket_owner_index` (`scope`,`bucket`,`owner_type`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `waterline_user_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `waterline_user_preferences` (
  `id` varchar(26) NOT NULL,
  `scope` varchar(120) NOT NULL DEFAULT 'default',
  `subject_key` varchar(191) NOT NULL,
  `surface` varchar(80) NOT NULL,
  `preferences` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`preferences`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `waterline_preferences_scope_subject_surface_unique` (`scope`,`subject_key`,`surface`),
  KEY `waterline_preferences_scope_surface_index` (`scope`,`surface`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_child_calls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_child_calls` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_workflow_run_id` varchar(26) NOT NULL,
  `parent_workflow_instance_id` varchar(191) NOT NULL,
  `sequence` int(10) unsigned NOT NULL,
  `child_workflow_type` varchar(191) NOT NULL,
  `child_workflow_class` varchar(255) NOT NULL,
  `requested_child_id` varchar(191) DEFAULT NULL,
  `resolved_child_instance_id` varchar(191) DEFAULT NULL,
  `resolved_child_run_id` varchar(26) DEFAULT NULL,
  `parent_close_policy` varchar(32) NOT NULL DEFAULT 'abandon',
  `connection` varchar(191) DEFAULT NULL,
  `queue` varchar(191) DEFAULT NULL,
  `compatibility` varchar(191) DEFAULT NULL,
  `retry_policy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`retry_policy`)),
  `timeout_policy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`timeout_policy`)),
  `cancellation_propagation` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(32) NOT NULL DEFAULT 'scheduled',
  `result_payload_reference` varchar(191) DEFAULT NULL,
  `failure_reference` varchar(191) DEFAULT NULL,
  `closed_reason` varchar(64) DEFAULT NULL,
  `scheduled_at` timestamp(6) NOT NULL,
  `started_at` timestamp(6) NULL DEFAULT NULL,
  `closed_at` timestamp(6) NULL DEFAULT NULL,
  `arguments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`arguments`)),
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `child_calls_parent_seq` (`parent_workflow_run_id`,`sequence`),
  KEY `child_calls_parent_status` (`parent_workflow_run_id`,`status`),
  KEY `child_calls_child_parent` (`resolved_child_instance_id`,`parent_workflow_run_id`),
  KEY `workflow_child_calls_parent_workflow_run_id_index` (`parent_workflow_run_id`),
  KEY `workflow_child_calls_parent_workflow_instance_id_index` (`parent_workflow_instance_id`),
  KEY `workflow_child_calls_sequence_index` (`sequence`),
  KEY `workflow_child_calls_resolved_child_instance_id_index` (`resolved_child_instance_id`),
  KEY `workflow_child_calls_resolved_child_run_id_index` (`resolved_child_run_id`),
  KEY `workflow_child_calls_status_index` (`status`),
  CONSTRAINT `workflow_child_calls_parent_workflow_run_id_foreign` FOREIGN KEY (`parent_workflow_run_id`) REFERENCES `workflow_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_child_projection_repairs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_child_projection_repairs` (
  `workflow_history_event_id` varchar(26) NOT NULL,
  `workflow_run_id` varchar(26) NOT NULL,
  `workflow_task_id` varchar(26) NOT NULL,
  `history_sequence` int(10) unsigned NOT NULL,
  `failure_id` varchar(26) DEFAULT NULL,
  `failed_child_counted_at` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`workflow_history_event_id`),
  KEY `workflow_child_projection_repairs_drain_idx` (`workflow_run_id`,`workflow_task_id`,`history_sequence`),
  KEY `workflow_child_projection_repairs_failure_idx` (`workflow_run_id`,`failure_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_commands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_commands` (
  `id` varchar(26) NOT NULL,
  `workflow_instance_id` varchar(191) DEFAULT NULL,
  `workflow_run_id` varchar(26) DEFAULT NULL,
  `requested_workflow_run_id` varchar(26) DEFAULT NULL,
  `resolved_workflow_run_id` varchar(26) DEFAULT NULL,
  `command_type` varchar(255) NOT NULL,
  `target_scope` varchar(255) NOT NULL DEFAULT 'instance',
  `source` varchar(255) NOT NULL DEFAULT 'php',
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `request_id` varchar(191) DEFAULT NULL,
  `status` varchar(255) NOT NULL,
  `outcome` varchar(255) DEFAULT NULL,
  `workflow_class` varchar(255) DEFAULT NULL,
  `workflow_type` varchar(255) DEFAULT NULL,
  `payload_codec` varchar(255) DEFAULT NULL,
  `payload` longtext DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `command_sequence` int(10) unsigned DEFAULT NULL,
  `message_sequence` int(10) unsigned DEFAULT NULL,
  `accepted_at` timestamp(6) NULL DEFAULT NULL,
  `applied_at` timestamp(6) NULL DEFAULT NULL,
  `rejected_at` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workflow_commands_request_lookup` (`workflow_instance_id`,`command_type`,`request_id`),
  KEY `workflow_commands_workflow_instance_id_index` (`workflow_instance_id`),
  KEY `workflow_commands_workflow_run_id_index` (`workflow_run_id`),
  KEY `workflow_commands_requested_workflow_run_id_index` (`requested_workflow_run_id`),
  KEY `workflow_commands_resolved_workflow_run_id_index` (`resolved_workflow_run_id`),
  KEY `workflow_commands_command_type_index` (`command_type`),
  KEY `workflow_commands_source_index` (`source`),
  KEY `workflow_commands_status_index` (`status`),
  KEY `workflow_commands_command_sequence_index` (`command_sequence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_exceptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_exceptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stored_workflow_id` bigint(20) unsigned NOT NULL,
  `class` text NOT NULL,
  `exception` text NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `workflow_exceptions_stored_workflow_id_index` (`stored_workflow_id`),
  CONSTRAINT `workflow_exceptions_stored_workflow_id_foreign` FOREIGN KEY (`stored_workflow_id`) REFERENCES `workflows` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_failures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_failures` (
  `id` varchar(26) NOT NULL,
  `workflow_run_id` varchar(26) NOT NULL,
  `source_kind` varchar(255) NOT NULL,
  `source_id` varchar(255) NOT NULL,
  `propagation_kind` varchar(255) NOT NULL,
  `failure_category` varchar(255) DEFAULT NULL,
  `non_retryable` tinyint(1) NOT NULL DEFAULT 0,
  `handled` tinyint(1) NOT NULL DEFAULT 0,
  `exception_class` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `file` text NOT NULL,
  `line` int(10) unsigned DEFAULT NULL,
  `trace_preview` longtext DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `workflow_failures_source_kind_source_id_index` (`source_kind`,`source_id`),
  KEY `workflow_failures_workflow_run_id_index` (`workflow_run_id`),
  KEY `workflow_failures_failure_category_index` (`failure_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_history_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_history_events` (
  `id` varchar(26) NOT NULL,
  `workflow_run_id` varchar(26) NOT NULL,
  `sequence` int(10) unsigned NOT NULL,
  `event_type` varchar(255) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `workflow_task_id` varchar(26) DEFAULT NULL,
  `workflow_command_id` varchar(26) DEFAULT NULL,
  `recorded_at` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workflow_history_events_workflow_run_id_sequence_unique` (`workflow_run_id`,`sequence`),
  KEY `workflow_history_events_workflow_run_id_index` (`workflow_run_id`),
  KEY `workflow_history_events_workflow_task_id_index` (`workflow_task_id`),
  KEY `workflow_history_events_workflow_command_id_index` (`workflow_command_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_instances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_instances` (
  `id` varchar(191) NOT NULL,
  `workflow_class` varchar(255) NOT NULL,
  `workflow_type` varchar(255) NOT NULL,
  `namespace` varchar(255) DEFAULT NULL,
  `business_key` varchar(191) DEFAULT NULL,
  `visibility_labels` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`visibility_labels`)),
  `memo` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`memo`)),
  `execution_timeout_seconds` int(10) unsigned DEFAULT NULL,
  `current_run_id` varchar(26) DEFAULT NULL,
  `run_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_message_sequence` int(10) unsigned NOT NULL DEFAULT 0,
  `reserved_at` timestamp(6) NULL DEFAULT NULL,
  `started_at` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `workflow_instances_namespace_index` (`namespace`),
  KEY `workflow_instances_business_key_index` (`business_key`),
  KEY `workflow_instances_current_run_id_index` (`current_run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_links` (
  `id` varchar(26) NOT NULL,
  `link_type` varchar(255) NOT NULL,
  `sequence` int(10) unsigned DEFAULT NULL,
  `parallel_group_path` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`parallel_group_path`)),
  `parent_workflow_instance_id` varchar(191) NOT NULL,
  `parent_workflow_run_id` varchar(26) NOT NULL,
  `child_workflow_instance_id` varchar(191) NOT NULL,
  `child_workflow_run_id` varchar(26) NOT NULL,
  `is_primary_parent` tinyint(1) NOT NULL DEFAULT 0,
  `parent_close_policy` varchar(255) NOT NULL DEFAULT 'abandon',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workflow_links_parent_child_type_unique` (`parent_workflow_run_id`,`child_workflow_run_id`,`link_type`),
  KEY `workflow_links_parent_sequence_type_index` (`parent_workflow_run_id`,`sequence`,`link_type`),
  KEY `workflow_links_parent_workflow_instance_id_index` (`parent_workflow_instance_id`),
  KEY `workflow_links_parent_workflow_run_id_index` (`parent_workflow_run_id`),
  KEY `workflow_links_child_workflow_instance_id_index` (`child_workflow_instance_id`),
  KEY `workflow_links_child_workflow_run_id_index` (`child_workflow_run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stored_workflow_id` bigint(20) unsigned NOT NULL,
  `index` bigint(20) unsigned NOT NULL,
  `now` timestamp(6) NOT NULL,
  `class` text NOT NULL,
  `result` text DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workflow_logs_stored_workflow_id_index_unique` (`stored_workflow_id`,`index`),
  KEY `workflow_logs_stored_workflow_id_index` (`stored_workflow_id`),
  CONSTRAINT `workflow_logs_stored_workflow_id_foreign` FOREIGN KEY (`stored_workflow_id`) REFERENCES `workflows` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_memos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_memos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workflow_run_id` varchar(26) NOT NULL,
  `workflow_instance_id` varchar(191) NOT NULL,
  `key` varchar(191) NOT NULL,
  `value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`value`)),
  `upserted_at_sequence` int(10) unsigned NOT NULL,
  `inherited_from_parent` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workflow_memos_run_key_unique` (`workflow_run_id`,`key`),
  KEY `workflow_memos_instance_key` (`workflow_instance_id`,`key`),
  CONSTRAINT `workflow_memos_workflow_run_id_foreign` FOREIGN KEY (`workflow_run_id`) REFERENCES `workflow_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workflow_instance_id` varchar(191) NOT NULL,
  `workflow_run_id` varchar(26) DEFAULT NULL,
  `direction` varchar(16) NOT NULL,
  `channel` varchar(64) NOT NULL,
  `stream_key` varchar(191) NOT NULL,
  `sequence` bigint(20) unsigned NOT NULL,
  `source_workflow_instance_id` varchar(191) DEFAULT NULL,
  `source_workflow_run_id` varchar(26) DEFAULT NULL,
  `target_workflow_instance_id` varchar(191) DEFAULT NULL,
  `target_workflow_run_id` varchar(26) DEFAULT NULL,
  `correlation_id` varchar(191) DEFAULT NULL,
  `idempotency_key` varchar(191) DEFAULT NULL,
  `payload_reference` varchar(191) DEFAULT NULL,
  `consume_state` varchar(16) NOT NULL DEFAULT 'pending',
  `consumed_at` timestamp(6) NULL DEFAULT NULL,
  `consumed_by_sequence` int(10) unsigned DEFAULT NULL,
  `expires_at` timestamp(6) NULL DEFAULT NULL,
  `delivery_attempt_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_delivery_attempt_at` timestamp(6) NULL DEFAULT NULL,
  `last_delivery_error` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wf_msgs_stream_seq_unique` (`workflow_instance_id`,`stream_key`,`sequence`),
  KEY `wf_msgs_instance_stream_seq` (`workflow_instance_id`,`stream_key`,`sequence`),
  KEY `wf_msgs_run_dir_state` (`workflow_run_id`,`direction`,`consume_state`),
  KEY `wf_msgs_stream_seq_state` (`stream_key`,`sequence`,`consume_state`),
  KEY `wf_msgs_target_state` (`target_workflow_instance_id`,`consume_state`),
  KEY `workflow_messages_workflow_instance_id_index` (`workflow_instance_id`),
  KEY `workflow_messages_workflow_run_id_index` (`workflow_run_id`),
  KEY `workflow_messages_direction_index` (`direction`),
  KEY `workflow_messages_channel_index` (`channel`),
  KEY `workflow_messages_stream_key_index` (`stream_key`),
  KEY `workflow_messages_sequence_index` (`sequence`),
  KEY `workflow_messages_source_workflow_instance_id_index` (`source_workflow_instance_id`),
  KEY `workflow_messages_target_workflow_instance_id_index` (`target_workflow_instance_id`),
  KEY `workflow_messages_correlation_id_index` (`correlation_id`),
  KEY `workflow_messages_idempotency_key_index` (`idempotency_key`),
  KEY `workflow_messages_consume_state_index` (`consume_state`),
  CONSTRAINT `workflow_messages_workflow_run_id_foreign` FOREIGN KEY (`workflow_run_id`) REFERENCES `workflow_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_relationships`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_relationships` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_workflow_id` bigint(20) unsigned DEFAULT NULL,
  `parent_index` bigint(20) unsigned NOT NULL,
  `parent_now` timestamp NOT NULL,
  `child_workflow_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `workflow_relationships_parent_workflow_id_index` (`parent_workflow_id`),
  KEY `workflow_relationships_child_workflow_id_index` (`child_workflow_id`),
  CONSTRAINT `workflow_relationships_child_workflow_id_foreign` FOREIGN KEY (`child_workflow_id`) REFERENCES `workflows` (`id`),
  CONSTRAINT `workflow_relationships_parent_workflow_id_foreign` FOREIGN KEY (`parent_workflow_id`) REFERENCES `workflows` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_run_lineage_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_run_lineage_entries` (
  `id` varchar(64) NOT NULL,
  `workflow_run_id` varchar(26) NOT NULL,
  `workflow_instance_id` varchar(191) NOT NULL,
  `direction` varchar(255) NOT NULL,
  `lineage_id` varchar(191) NOT NULL,
  `position` int(10) unsigned NOT NULL,
  `link_type` varchar(255) NOT NULL,
  `child_call_id` varchar(26) DEFAULT NULL,
  `sequence` int(10) unsigned DEFAULT NULL,
  `is_primary_parent` tinyint(1) NOT NULL DEFAULT 0,
  `related_workflow_instance_id` varchar(191) DEFAULT NULL,
  `related_workflow_run_id` varchar(26) DEFAULT NULL,
  `related_run_number` int(10) unsigned DEFAULT NULL,
  `related_workflow_type` varchar(191) DEFAULT NULL,
  `related_workflow_class` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `status_bucket` varchar(255) DEFAULT NULL,
  `closed_reason` varchar(255) DEFAULT NULL,
  `linked_at` timestamp(6) NULL DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workflow_run_lineage_run_direction_lineage_unique` (`workflow_run_id`,`direction`,`lineage_id`),
  KEY `workflow_run_lineage_run_direction_position_index` (`workflow_run_id`,`direction`,`position`),
  KEY `workflow_run_lineage_instance_direction_type_index` (`workflow_instance_id`,`direction`,`link_type`),
  KEY `workflow_run_lineage_entries_workflow_run_id_index` (`workflow_run_id`),
  KEY `workflow_run_lineage_entries_workflow_instance_id_index` (`workflow_instance_id`),
  KEY `workflow_run_lineage_entries_direction_index` (`direction`),
  KEY `workflow_run_lineage_entries_link_type_index` (`link_type`),
  KEY `workflow_run_lineage_entries_child_call_id_index` (`child_call_id`),
  KEY `workflow_run_lineage_entries_sequence_index` (`sequence`),
  KEY `workflow_run_lineage_entries_is_primary_parent_index` (`is_primary_parent`),
  KEY `workflow_run_lineage_entries_related_workflow_instance_id_index` (`related_workflow_instance_id`),
  KEY `workflow_run_lineage_entries_related_workflow_run_id_index` (`related_workflow_run_id`),
  KEY `workflow_run_lineage_entries_related_workflow_type_index` (`related_workflow_type`),
  KEY `workflow_run_lineage_entries_status_index` (`status`),
  KEY `workflow_run_lineage_entries_status_bucket_index` (`status_bucket`),
  KEY `workflow_run_lineage_entries_closed_reason_index` (`closed_reason`),
  KEY `workflow_run_lineage_entries_linked_at_index` (`linked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_run_summaries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_run_summaries` (
  `id` varchar(26) NOT NULL,
  `workflow_instance_id` varchar(191) NOT NULL,
  `run_number` int(10) unsigned NOT NULL,
  `is_current_run` tinyint(1) NOT NULL DEFAULT 0,
  `engine_source` varchar(255) NOT NULL DEFAULT 'v2',
  `projection_schema_version` smallint(5) unsigned DEFAULT NULL,
  `class` varchar(255) NOT NULL,
  `workflow_type` varchar(255) NOT NULL,
  `namespace` varchar(255) DEFAULT NULL,
  `compatibility` varchar(255) DEFAULT NULL,
  `declared_entry_mode` varchar(255) DEFAULT NULL,
  `declared_contract_source` varchar(255) DEFAULT NULL,
  `business_key` varchar(191) DEFAULT NULL,
  `visibility_labels` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`visibility_labels`)),
  `status` varchar(255) NOT NULL,
  `status_bucket` varchar(255) NOT NULL,
  `closed_reason` varchar(255) DEFAULT NULL,
  `connection` varchar(255) DEFAULT NULL,
  `queue` varchar(255) DEFAULT NULL,
  `started_at` timestamp(6) NULL DEFAULT NULL,
  `sort_timestamp` timestamp(6) NULL DEFAULT NULL,
  `sort_key` varchar(64) DEFAULT NULL,
  `closed_at` timestamp(6) NULL DEFAULT NULL,
  `archived_at` timestamp(6) NULL DEFAULT NULL,
  `archive_command_id` varchar(26) DEFAULT NULL,
  `archive_reason` varchar(255) DEFAULT NULL,
  `duration_ms` bigint(20) DEFAULT NULL,
  `wait_kind` varchar(255) DEFAULT NULL,
  `wait_reason` text DEFAULT NULL,
  `wait_started_at` timestamp(6) NULL DEFAULT NULL,
  `wait_deadline_at` timestamp(6) NULL DEFAULT NULL,
  `open_wait_id` varchar(191) DEFAULT NULL,
  `resume_source_kind` varchar(255) DEFAULT NULL,
  `resume_source_id` varchar(191) DEFAULT NULL,
  `next_task_at` timestamp(6) NULL DEFAULT NULL,
  `liveness_state` varchar(255) DEFAULT NULL,
  `liveness_reason` text DEFAULT NULL,
  `repair_blocked_reason` varchar(255) DEFAULT NULL,
  `repair_attention` tinyint(1) NOT NULL DEFAULT 0,
  `task_problem` tinyint(1) NOT NULL DEFAULT 0,
  `next_task_id` varchar(26) DEFAULT NULL,
  `next_task_type` varchar(255) DEFAULT NULL,
  `next_task_status` varchar(255) DEFAULT NULL,
  `next_task_lease_expires_at` timestamp(6) NULL DEFAULT NULL,
  `exception_count` int(10) unsigned NOT NULL DEFAULT 0,
  `history_event_count` int(10) unsigned NOT NULL DEFAULT 0,
  `history_size_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `history_fan_out` int(10) unsigned NOT NULL DEFAULT 0,
  `continue_as_new_recommended` tinyint(1) NOT NULL DEFAULT 0,
  `history_budget_pressure` varchar(32) NOT NULL DEFAULT 'ok',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `workflow_run_summaries_status_bucket_started_at_index` (`status_bucket`,`started_at`),
  KEY `workflow_run_summaries_sort_order_index` (`sort_timestamp`,`id`),
  KEY `wfrs_namespace_sort_idx` (`namespace`,`sort_timestamp`,`id`),
  KEY `wfrs_namespace_type_sort_idx` (`namespace`,`workflow_type`,`sort_timestamp`,`id`),
  KEY `wfrs_namespace_status_sort_idx` (`namespace`,`status_bucket`,`sort_timestamp`,`id`),
  KEY `workflow_run_summaries_sort_key_index` (`sort_key`),
  KEY `workflow_run_summaries_workflow_instance_id_index` (`workflow_instance_id`),
  KEY `workflow_run_summaries_is_current_run_index` (`is_current_run`),
  KEY `workflow_run_summaries_projection_schema_version_index` (`projection_schema_version`),
  KEY `workflow_run_summaries_namespace_index` (`namespace`),
  KEY `wfrs_decl_entry_mode_idx` (`declared_entry_mode`),
  KEY `wfrs_decl_contract_source_idx` (`declared_contract_source`),
  KEY `workflow_run_summaries_business_key_index` (`business_key`),
  KEY `workflow_run_summaries_status_index` (`status`),
  KEY `workflow_run_summaries_status_bucket_index` (`status_bucket`),
  KEY `workflow_run_summaries_archived_at_index` (`archived_at`),
  KEY `workflow_run_summaries_archive_command_id_index` (`archive_command_id`),
  KEY `workflow_run_summaries_repair_blocked_reason_index` (`repair_blocked_reason`),
  KEY `workflow_run_summaries_repair_attention_index` (`repair_attention`),
  KEY `workflow_run_summaries_continue_as_new_recommended_index` (`continue_as_new_recommended`),
  KEY `workflow_run_summaries_history_budget_pressure_index` (`history_budget_pressure`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_run_timeline_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_run_timeline_entries` (
  `id` varchar(64) NOT NULL,
  `workflow_run_id` varchar(26) NOT NULL,
  `workflow_instance_id` varchar(191) NOT NULL,
  `history_event_id` varchar(26) NOT NULL,
  `sequence` int(10) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  `kind` varchar(255) NOT NULL,
  `entry_kind` varchar(255) NOT NULL DEFAULT 'point',
  `source_kind` varchar(255) DEFAULT NULL,
  `source_id` varchar(191) DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `recorded_at` timestamp(6) NULL DEFAULT NULL,
  `command_id` varchar(26) DEFAULT NULL,
  `command_sequence` int(10) unsigned DEFAULT NULL,
  `task_id` varchar(26) DEFAULT NULL,
  `activity_execution_id` varchar(26) DEFAULT NULL,
  `timer_id` varchar(191) DEFAULT NULL,
  `failure_id` varchar(26) DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workflow_run_timeline_run_event_unique` (`workflow_run_id`,`history_event_id`),
  KEY `workflow_run_timeline_run_sequence_index` (`workflow_run_id`,`sequence`),
  KEY `workflow_run_timeline_instance_kind_recorded_index` (`workflow_instance_id`,`kind`,`recorded_at`),
  KEY `workflow_run_timeline_entries_workflow_run_id_index` (`workflow_run_id`),
  KEY `workflow_run_timeline_entries_workflow_instance_id_index` (`workflow_instance_id`),
  KEY `workflow_run_timeline_entries_history_event_id_index` (`history_event_id`),
  KEY `workflow_run_timeline_entries_sequence_index` (`sequence`),
  KEY `workflow_run_timeline_entries_type_index` (`type`),
  KEY `workflow_run_timeline_entries_kind_index` (`kind`),
  KEY `workflow_run_timeline_entries_source_kind_index` (`source_kind`),
  KEY `workflow_run_timeline_entries_source_id_index` (`source_id`),
  KEY `workflow_run_timeline_entries_recorded_at_index` (`recorded_at`),
  KEY `workflow_run_timeline_entries_command_id_index` (`command_id`),
  KEY `workflow_run_timeline_entries_command_sequence_index` (`command_sequence`),
  KEY `workflow_run_timeline_entries_task_id_index` (`task_id`),
  KEY `workflow_run_timeline_entries_activity_execution_id_index` (`activity_execution_id`),
  KEY `workflow_run_timeline_entries_timer_id_index` (`timer_id`),
  KEY `workflow_run_timeline_entries_failure_id_index` (`failure_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_run_timer_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_run_timer_entries` (
  `id` varchar(64) NOT NULL,
  `workflow_run_id` varchar(26) NOT NULL,
  `workflow_instance_id` varchar(191) NOT NULL,
  `timer_id` varchar(191) NOT NULL,
  `schema_version` smallint(5) unsigned NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL,
  `sequence` int(10) unsigned DEFAULT NULL,
  `status` varchar(255) NOT NULL,
  `source_status` varchar(255) DEFAULT NULL,
  `delay_seconds` int(11) DEFAULT NULL,
  `fire_at` timestamp(6) NULL DEFAULT NULL,
  `fired_at` timestamp(6) NULL DEFAULT NULL,
  `cancelled_at` timestamp(6) NULL DEFAULT NULL,
  `timer_kind` varchar(191) DEFAULT NULL,
  `condition_wait_id` varchar(191) DEFAULT NULL,
  `condition_key` varchar(191) DEFAULT NULL,
  `condition_definition_fingerprint` varchar(191) DEFAULT NULL,
  `history_authority` varchar(255) DEFAULT NULL,
  `history_unsupported_reason` varchar(255) DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workflow_run_timer_entries_run_timer_unique` (`workflow_run_id`,`timer_id`),
  KEY `workflow_run_timer_entries_run_status_position_index` (`workflow_run_id`,`status`,`position`),
  KEY `workflow_run_timer_entries_instance_kind_status_index` (`workflow_instance_id`,`timer_kind`,`status`),
  KEY `workflow_run_timer_entries_workflow_run_id_index` (`workflow_run_id`),
  KEY `workflow_run_timer_entries_workflow_instance_id_index` (`workflow_instance_id`),
  KEY `workflow_run_timer_entries_sequence_index` (`sequence`),
  KEY `workflow_run_timer_entries_status_index` (`status`),
  KEY `workflow_run_timer_entries_source_status_index` (`source_status`),
  KEY `workflow_run_timer_entries_timer_kind_index` (`timer_kind`),
  KEY `workflow_run_timer_entries_condition_wait_id_index` (`condition_wait_id`),
  KEY `workflow_run_timer_entries_condition_key_index` (`condition_key`),
  KEY `workflow_run_timer_entries_history_authority_index` (`history_authority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_run_timers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_run_timers` (
  `id` varchar(26) NOT NULL,
  `workflow_run_id` varchar(26) NOT NULL,
  `sequence` int(10) unsigned NOT NULL,
  `status` varchar(255) NOT NULL,
  `delay_seconds` bigint(20) unsigned NOT NULL,
  `fire_at` timestamp(6) NOT NULL,
  `fired_at` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workflow_run_timers_workflow_run_id_sequence_unique` (`workflow_run_id`,`sequence`),
  KEY `workflow_run_timers_status_fire_at_index` (`status`,`fire_at`),
  KEY `workflow_run_timers_workflow_run_id_index` (`workflow_run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_run_waits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_run_waits` (
  `id` varchar(64) NOT NULL,
  `workflow_run_id` varchar(26) NOT NULL,
  `workflow_instance_id` varchar(191) NOT NULL,
  `wait_id` varchar(191) NOT NULL,
  `position` int(10) unsigned NOT NULL,
  `kind` varchar(255) NOT NULL,
  `sequence` int(10) unsigned DEFAULT NULL,
  `status` varchar(255) NOT NULL,
  `source_status` varchar(255) DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `opened_at` timestamp(6) NULL DEFAULT NULL,
  `deadline_at` timestamp(6) NULL DEFAULT NULL,
  `resolved_at` timestamp(6) NULL DEFAULT NULL,
  `target_name` varchar(191) DEFAULT NULL,
  `target_type` varchar(191) DEFAULT NULL,
  `task_backed` tinyint(1) NOT NULL DEFAULT 0,
  `external_only` tinyint(1) NOT NULL DEFAULT 0,
  `resume_source_kind` varchar(191) DEFAULT NULL,
  `resume_source_id` varchar(191) DEFAULT NULL,
  `task_id` varchar(26) DEFAULT NULL,
  `task_type` varchar(255) DEFAULT NULL,
  `task_status` varchar(255) DEFAULT NULL,
  `command_id` varchar(26) DEFAULT NULL,
  `command_sequence` int(10) unsigned DEFAULT NULL,
  `command_status` varchar(255) DEFAULT NULL,
  `command_outcome` varchar(255) DEFAULT NULL,
  `history_authority` varchar(255) DEFAULT NULL,
  `history_unsupported_reason` varchar(255) DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workflow_run_waits_run_wait_unique` (`workflow_run_id`,`wait_id`),
  KEY `workflow_run_waits_run_status_position_index` (`workflow_run_id`,`status`,`position`),
  KEY `workflow_run_waits_instance_kind_status_index` (`workflow_instance_id`,`kind`,`status`),
  KEY `workflow_run_waits_workflow_run_id_index` (`workflow_run_id`),
  KEY `workflow_run_waits_workflow_instance_id_index` (`workflow_instance_id`),
  KEY `workflow_run_waits_kind_index` (`kind`),
  KEY `workflow_run_waits_sequence_index` (`sequence`),
  KEY `workflow_run_waits_status_index` (`status`),
  KEY `workflow_run_waits_source_status_index` (`source_status`),
  KEY `workflow_run_waits_target_name_index` (`target_name`),
  KEY `workflow_run_waits_task_backed_index` (`task_backed`),
  KEY `workflow_run_waits_resume_source_kind_index` (`resume_source_kind`),
  KEY `workflow_run_waits_resume_source_id_index` (`resume_source_id`),
  KEY `workflow_run_waits_task_id_index` (`task_id`),
  KEY `workflow_run_waits_command_id_index` (`command_id`),
  KEY `workflow_run_waits_history_authority_index` (`history_authority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_runs` (
  `id` varchar(26) NOT NULL,
  `workflow_instance_id` varchar(191) NOT NULL,
  `run_number` int(10) unsigned NOT NULL,
  `workflow_class` varchar(255) NOT NULL,
  `workflow_type` varchar(255) NOT NULL,
  `namespace` varchar(255) DEFAULT NULL,
  `business_key` varchar(191) DEFAULT NULL,
  `visibility_labels` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`visibility_labels`)),
  `status` varchar(255) NOT NULL,
  `closed_reason` varchar(255) DEFAULT NULL,
  `compatibility` varchar(255) DEFAULT NULL,
  `payload_codec` varchar(255) DEFAULT NULL,
  `arguments` longtext DEFAULT NULL,
  `output` longtext DEFAULT NULL,
  `output_payload_codec` varchar(255) DEFAULT NULL,
  `connection` varchar(255) DEFAULT NULL,
  `queue` varchar(255) DEFAULT NULL,
  `priority` tinyint(3) unsigned NOT NULL DEFAULT 5,
  `fairness_key` varchar(64) DEFAULT NULL,
  `fairness_weight` smallint(5) unsigned NOT NULL DEFAULT 1,
  `sticky_worker_id` varchar(255) DEFAULT NULL,
  `sticky_until` timestamp(6) NULL DEFAULT NULL,
  `last_history_sequence` int(10) unsigned NOT NULL DEFAULT 0,
  `last_command_sequence` int(10) unsigned NOT NULL DEFAULT 0,
  `message_cursor_position` int(10) unsigned NOT NULL DEFAULT 0,
  `run_timeout_seconds` int(10) unsigned DEFAULT NULL,
  `execution_deadline_at` timestamp(6) NULL DEFAULT NULL,
  `run_deadline_at` timestamp(6) NULL DEFAULT NULL,
  `started_at` timestamp(6) NULL DEFAULT NULL,
  `closed_at` timestamp(6) NULL DEFAULT NULL,
  `archived_at` timestamp(6) NULL DEFAULT NULL,
  `archive_command_id` varchar(26) DEFAULT NULL,
  `archive_reason` varchar(255) DEFAULT NULL,
  `last_progress_at` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  `import_source` varchar(64) DEFAULT NULL,
  `import_id` varchar(64) DEFAULT NULL,
  `import_dedupe_key` varchar(191) DEFAULT NULL,
  `import_contract_version` smallint(5) unsigned DEFAULT NULL,
  `imported_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workflow_runs_workflow_instance_id_run_number_unique` (`workflow_instance_id`,`run_number`),
  KEY `workflow_runs_workflow_instance_id_index` (`workflow_instance_id`),
  KEY `workflow_runs_namespace_index` (`namespace`),
  KEY `workflow_runs_business_key_index` (`business_key`),
  KEY `workflow_runs_sticky_worker_id_index` (`sticky_worker_id`),
  KEY `workflow_runs_sticky_until_index` (`sticky_until`),
  KEY `workflow_runs_archived_at_index` (`archived_at`),
  KEY `workflow_runs_archive_command_id_index` (`archive_command_id`),
  KEY `workflow_runs_import_source_index` (`import_source`),
  KEY `workflow_runs_import_id_index` (`import_id`),
  KEY `workflow_runs_import_dedupe_key_index` (`import_dedupe_key`),
  KEY `workflow_runs_imported_at_index` (`imported_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_schedule_history_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_schedule_history_events` (
  `id` varchar(26) NOT NULL,
  `workflow_schedule_id` varchar(26) NOT NULL,
  `schedule_id` varchar(255) NOT NULL,
  `namespace` varchar(255) DEFAULT NULL,
  `sequence` int(10) unsigned NOT NULL,
  `event_type` varchar(255) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `workflow_instance_id` varchar(191) DEFAULT NULL,
  `workflow_run_id` varchar(26) DEFAULT NULL,
  `recorded_at` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wf_schedule_history_schedule_sequence_unique` (`workflow_schedule_id`,`sequence`),
  KEY `wf_schedule_history_namespace_schedule_idx` (`namespace`,`schedule_id`),
  KEY `wf_schedule_history_event_recorded_idx` (`event_type`,`recorded_at`),
  KEY `wf_schedule_history_workflow_schedule_idx` (`workflow_schedule_id`),
  KEY `wf_schedule_history_schedule_idx` (`schedule_id`),
  KEY `wf_schedule_history_namespace_idx` (`namespace`),
  KEY `wf_schedule_history_instance_idx` (`workflow_instance_id`),
  KEY `wf_schedule_history_run_idx` (`workflow_run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_schedules` (
  `id` varchar(26) NOT NULL,
  `schedule_id` varchar(255) NOT NULL,
  `namespace` varchar(255) DEFAULT NULL,
  `spec` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`spec`)),
  `action` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`action`)),
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `overlap_policy` varchar(32) NOT NULL DEFAULT 'skip',
  `note` text DEFAULT NULL,
  `memo` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`memo`)),
  `search_attributes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`search_attributes`)),
  `visibility_labels` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`visibility_labels`)),
  `jitter_seconds` int(10) unsigned NOT NULL DEFAULT 0,
  `max_runs` bigint(20) unsigned DEFAULT NULL,
  `remaining_actions` bigint(20) unsigned DEFAULT NULL,
  `fires_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `failures_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `recent_actions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`recent_actions`)),
  `buffered_actions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`buffered_actions`)),
  `last_fired_at` timestamp(6) NULL DEFAULT NULL,
  `next_fire_at` timestamp(6) NULL DEFAULT NULL,
  `latest_workflow_instance_id` varchar(191) DEFAULT NULL,
  `connection` varchar(255) DEFAULT NULL,
  `queue` varchar(255) DEFAULT NULL,
  `paused_at` timestamp(6) NULL DEFAULT NULL,
  `deleted_at` timestamp(6) NULL DEFAULT NULL,
  `last_skip_reason` varchar(64) DEFAULT NULL,
  `last_skipped_at` timestamp(6) NULL DEFAULT NULL,
  `skipped_trigger_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workflow_schedules_namespace_schedule_id_unique` (`namespace`,`schedule_id`),
  KEY `workflow_schedules_status_next_fire_at_index` (`status`,`next_fire_at`),
  KEY `workflow_schedules_namespace_index` (`namespace`),
  KEY `workflow_schedules_latest_workflow_instance_id_index` (`latest_workflow_instance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_search_attributes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_search_attributes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workflow_run_id` varchar(26) NOT NULL,
  `workflow_instance_id` varchar(191) NOT NULL,
  `key` varchar(191) NOT NULL COMMENT 'Attribute name (e.g., "customer_id", "priority", "region")',
  `type` varchar(16) NOT NULL COMMENT 'Type: string, keyword, int, float, bool, datetime',
  `value_string` text DEFAULT NULL COMMENT 'For type=string (max 2048 chars enforced at app level)',
  `value_keyword` varchar(255) DEFAULT NULL COMMENT 'For type=keyword (exact match, indexed)',
  `value_keyword_list` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'For type=keyword_list (ordered exact-match values)' CHECK (json_valid(`value_keyword_list`)),
  `value_int` bigint(20) DEFAULT NULL COMMENT 'For type=int',
  `value_float` double DEFAULT NULL COMMENT 'For type=float',
  `value_bool` tinyint(1) DEFAULT NULL COMMENT 'For type=bool',
  `value_datetime` timestamp(6) NULL DEFAULT NULL COMMENT 'For type=datetime (microsecond precision)',
  `upserted_at_sequence` int(10) unsigned NOT NULL COMMENT 'History sequence when this attribute was last upserted',
  `inherited_from_parent` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'True if inherited via continue-as-new',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workflow_search_attrs_run_key_unique` (`workflow_run_id`,`key`),
  KEY `workflow_search_attrs_instance_key_type` (`workflow_instance_id`,`key`,`type`),
  KEY `workflow_search_attrs_key_keyword` (`key`,`value_keyword`),
  KEY `workflow_search_attrs_key_int` (`key`,`value_int`),
  KEY `workflow_search_attrs_key_float` (`key`,`value_float`),
  KEY `workflow_search_attrs_key_bool` (`key`,`value_bool`),
  KEY `workflow_search_attrs_key_datetime` (`key`,`value_datetime`),
  KEY `workflow_search_attributes_workflow_run_id_index` (`workflow_run_id`),
  KEY `workflow_search_attributes_workflow_instance_id_index` (`workflow_instance_id`),
  KEY `workflow_search_attributes_value_keyword_index` (`value_keyword`),
  KEY `workflow_search_attributes_value_int_index` (`value_int`),
  KEY `workflow_search_attributes_value_float_index` (`value_float`),
  KEY `workflow_search_attributes_value_bool_index` (`value_bool`),
  KEY `workflow_search_attributes_value_datetime_index` (`value_datetime`),
  CONSTRAINT `workflow_search_attributes_workflow_run_id_foreign` FOREIGN KEY (`workflow_run_id`) REFERENCES `workflow_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_service_calls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_service_calls` (
  `id` varchar(26) NOT NULL,
  `workflow_service_endpoint_id` varchar(26) DEFAULT NULL,
  `workflow_service_id` varchar(26) DEFAULT NULL,
  `workflow_service_operation_id` varchar(26) DEFAULT NULL,
  `namespace` varchar(255) DEFAULT NULL,
  `endpoint_name` varchar(191) NOT NULL,
  `service_name` varchar(191) NOT NULL,
  `operation_name` varchar(191) NOT NULL,
  `caller_namespace` varchar(255) DEFAULT NULL,
  `caller_workflow_instance_id` varchar(191) DEFAULT NULL,
  `caller_workflow_run_id` varchar(26) DEFAULT NULL,
  `target_namespace` varchar(255) DEFAULT NULL,
  `linked_workflow_instance_id` varchar(191) DEFAULT NULL,
  `linked_workflow_run_id` varchar(26) DEFAULT NULL,
  `linked_workflow_update_id` varchar(26) DEFAULT NULL,
  `status` varchar(32) NOT NULL,
  `outcome` varchar(64) DEFAULT NULL,
  `operation_mode` varchar(32) NOT NULL,
  `resolved_binding_kind` varchar(64) DEFAULT NULL,
  `resolved_target_reference` varchar(191) DEFAULT NULL,
  `payload_codec` varchar(255) DEFAULT NULL,
  `input_payload_reference` varchar(191) DEFAULT NULL,
  `output_payload_reference` varchar(191) DEFAULT NULL,
  `failure_payload_reference` varchar(191) DEFAULT NULL,
  `failure_message` text DEFAULT NULL,
  `idempotency_key` varchar(191) DEFAULT NULL,
  `deadline_policy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`deadline_policy`)),
  `idempotency_policy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`idempotency_policy`)),
  `cancellation_policy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cancellation_policy`)),
  `retry_policy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`retry_policy`)),
  `boundary_policy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`boundary_policy`)),
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `outcome_category` varchar(32) DEFAULT NULL,
  `outcome_reason` varchar(191) DEFAULT NULL,
  `outcome_message` text DEFAULT NULL,
  `outcome_metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`outcome_metadata`)),
  `policy_name` varchar(191) DEFAULT NULL,
  `retry_after_seconds` int(10) unsigned DEFAULT NULL,
  `caller_principal_subject` varchar(191) DEFAULT NULL,
  `caller_principal_method` varchar(64) DEFAULT NULL,
  `caller_principal_roles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`caller_principal_roles`)),
  `caller_principal_tenant` varchar(191) DEFAULT NULL,
  `caller_principal_claims` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`caller_principal_claims`)),
  `accepted_at` timestamp(6) NULL DEFAULT NULL,
  `started_at` timestamp(6) NULL DEFAULT NULL,
  `completed_at` timestamp(6) NULL DEFAULT NULL,
  `failed_at` timestamp(6) NULL DEFAULT NULL,
  `cancelled_at` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `wf_service_calls_namespace_status_idx` (`namespace`,`status`),
  KEY `wf_service_calls_caller_status_idx` (`caller_namespace`,`status`),
  KEY `wf_service_calls_target_status_idx` (`target_namespace`,`status`),
  KEY `wf_service_calls_namespace_outcome_idx` (`namespace`,`outcome`),
  KEY `wf_service_calls_caller_outcome_idx` (`caller_namespace`,`outcome`),
  KEY `wf_service_calls_target_outcome_idx` (`target_namespace`,`outcome`),
  KEY `wf_service_calls_endpoint_idx` (`workflow_service_endpoint_id`),
  KEY `wf_service_calls_service_idx` (`workflow_service_id`),
  KEY `wf_service_calls_operation_idx` (`workflow_service_operation_id`),
  KEY `wf_service_calls_namespace_idx` (`namespace`),
  KEY `wf_service_calls_caller_namespace_idx` (`caller_namespace`),
  KEY `wf_service_calls_caller_instance_idx` (`caller_workflow_instance_id`),
  KEY `wf_service_calls_caller_run_idx` (`caller_workflow_run_id`),
  KEY `wf_service_calls_target_namespace_idx` (`target_namespace`),
  KEY `wf_service_calls_linked_instance_idx` (`linked_workflow_instance_id`),
  KEY `wf_service_calls_linked_run_idx` (`linked_workflow_run_id`),
  KEY `wf_service_calls_linked_update_idx` (`linked_workflow_update_id`),
  KEY `wf_service_calls_status_idx` (`status`),
  KEY `wf_service_calls_outcome_idx` (`outcome`),
  KEY `wf_service_calls_mode_idx` (`operation_mode`),
  KEY `wf_service_calls_binding_kind_idx` (`resolved_binding_kind`),
  KEY `wf_service_calls_idempotency_idx` (`idempotency_key`),
  KEY `wf_service_calls_outcome_category_idx` (`outcome_category`),
  KEY `wf_service_calls_policy_idx` (`policy_name`),
  KEY `wf_service_calls_principal_subject_idx` (`caller_principal_subject`),
  KEY `wf_service_calls_accepted_at_idx` (`accepted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_service_endpoints`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_service_endpoints` (
  `id` varchar(26) NOT NULL,
  `namespace` varchar(255) DEFAULT NULL,
  `endpoint_name` varchar(191) NOT NULL,
  `description` text DEFAULT NULL,
  `boundary_policy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`boundary_policy`)),
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wf_service_endpoints_namespace_name_unique` (`namespace`,`endpoint_name`),
  KEY `wf_service_endpoints_namespace_idx` (`namespace`),
  KEY `wf_service_endpoints_name_idx` (`endpoint_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_service_operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_service_operations` (
  `id` varchar(26) NOT NULL,
  `workflow_service_endpoint_id` varchar(26) NOT NULL,
  `workflow_service_id` varchar(26) NOT NULL,
  `namespace` varchar(255) DEFAULT NULL,
  `operation_name` varchar(191) NOT NULL,
  `description` text DEFAULT NULL,
  `operation_mode` varchar(32) NOT NULL,
  `handler_binding_kind` varchar(64) NOT NULL,
  `handler_target_reference` varchar(191) DEFAULT NULL,
  `handler_binding` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`handler_binding`)),
  `deadline_policy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`deadline_policy`)),
  `idempotency_policy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`idempotency_policy`)),
  `cancellation_policy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cancellation_policy`)),
  `retry_policy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`retry_policy`)),
  `boundary_policy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`boundary_policy`)),
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wf_service_ops_namespace_service_name_unique` (`namespace`,`workflow_service_id`,`operation_name`),
  KEY `wf_service_ops_namespace_name_idx` (`namespace`,`operation_name`),
  KEY `wf_service_ops_endpoint_idx` (`workflow_service_endpoint_id`),
  KEY `wf_service_ops_service_idx` (`workflow_service_id`),
  KEY `wf_service_ops_namespace_idx` (`namespace`),
  KEY `wf_service_ops_name_idx` (`operation_name`),
  KEY `wf_service_ops_mode_idx` (`operation_mode`),
  KEY `wf_service_ops_binding_kind_idx` (`handler_binding_kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_services` (
  `id` varchar(26) NOT NULL,
  `workflow_service_endpoint_id` varchar(26) NOT NULL,
  `namespace` varchar(255) DEFAULT NULL,
  `service_name` varchar(191) NOT NULL,
  `description` text DEFAULT NULL,
  `boundary_policy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`boundary_policy`)),
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wf_services_namespace_endpoint_name_unique` (`namespace`,`workflow_service_endpoint_id`,`service_name`),
  KEY `wf_services_namespace_name_idx` (`namespace`,`service_name`),
  KEY `wf_services_endpoint_idx` (`workflow_service_endpoint_id`),
  KEY `wf_services_namespace_idx` (`namespace`),
  KEY `wf_services_name_idx` (`service_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_signal_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_signal_records` (
  `id` varchar(26) NOT NULL,
  `workflow_command_id` varchar(26) NOT NULL,
  `workflow_instance_id` varchar(191) DEFAULT NULL,
  `workflow_run_id` varchar(26) DEFAULT NULL,
  `target_scope` varchar(255) NOT NULL DEFAULT 'instance',
  `requested_workflow_run_id` varchar(26) DEFAULT NULL,
  `resolved_workflow_run_id` varchar(26) DEFAULT NULL,
  `signal_name` varchar(255) NOT NULL,
  `signal_wait_id` varchar(255) DEFAULT NULL,
  `status` varchar(255) NOT NULL,
  `outcome` varchar(255) DEFAULT NULL,
  `command_sequence` int(10) unsigned DEFAULT NULL,
  `workflow_sequence` int(10) unsigned DEFAULT NULL,
  `payload_codec` varchar(255) DEFAULT NULL,
  `arguments` longtext DEFAULT NULL,
  `validation_errors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`validation_errors`)),
  `rejection_reason` varchar(255) DEFAULT NULL,
  `received_at` timestamp(6) NULL DEFAULT NULL,
  `applied_at` timestamp(6) NULL DEFAULT NULL,
  `rejected_at` timestamp(6) NULL DEFAULT NULL,
  `closed_at` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workflow_signal_records_workflow_command_id_unique` (`workflow_command_id`),
  KEY `workflow_signal_records_workflow_instance_id_index` (`workflow_instance_id`),
  KEY `workflow_signal_records_workflow_run_id_index` (`workflow_run_id`),
  KEY `workflow_signal_records_requested_workflow_run_id_index` (`requested_workflow_run_id`),
  KEY `workflow_signal_records_resolved_workflow_run_id_index` (`resolved_workflow_run_id`),
  KEY `workflow_signal_records_signal_name_index` (`signal_name`),
  KEY `workflow_signal_records_signal_wait_id_index` (`signal_wait_id`),
  KEY `workflow_signal_records_status_index` (`status`),
  KEY `workflow_signal_records_outcome_index` (`outcome`),
  KEY `workflow_signal_records_command_sequence_index` (`command_sequence`),
  KEY `workflow_signal_records_workflow_sequence_index` (`workflow_sequence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_signals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_signals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stored_workflow_id` bigint(20) unsigned NOT NULL,
  `method` text NOT NULL,
  `arguments` text DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `workflow_signals_stored_workflow_id_created_at_index` (`stored_workflow_id`,`created_at`),
  KEY `workflow_signals_stored_workflow_id_index` (`stored_workflow_id`),
  CONSTRAINT `workflow_signals_stored_workflow_id_foreign` FOREIGN KEY (`stored_workflow_id`) REFERENCES `workflows` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_tasks` (
  `id` varchar(26) NOT NULL,
  `workflow_run_id` varchar(26) NOT NULL,
  `namespace` varchar(255) DEFAULT NULL,
  `task_type` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `compatibility` varchar(255) DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `connection` varchar(255) DEFAULT NULL,
  `queue` varchar(255) DEFAULT NULL,
  `priority` tinyint(3) unsigned NOT NULL DEFAULT 5,
  `fairness_key` varchar(64) DEFAULT NULL,
  `fairness_weight` smallint(5) unsigned NOT NULL DEFAULT 1,
  `sticky_worker_id` varchar(255) DEFAULT NULL,
  `sticky_until` timestamp(6) NULL DEFAULT NULL,
  `sticky_replay_mode` varchar(255) DEFAULT NULL,
  `sticky_claimed_at` timestamp(6) NULL DEFAULT NULL,
  `available_at` timestamp(6) NULL DEFAULT NULL,
  `leased_at` timestamp(6) NULL DEFAULT NULL,
  `lease_owner` varchar(255) DEFAULT NULL,
  `lease_expires_at` timestamp(6) NULL DEFAULT NULL,
  `attempt_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_dispatch_attempt_at` timestamp(6) NULL DEFAULT NULL,
  `last_dispatched_at` timestamp(6) NULL DEFAULT NULL,
  `last_dispatch_error` text DEFAULT NULL,
  `last_claim_failed_at` timestamp(6) NULL DEFAULT NULL,
  `last_claim_error` text DEFAULT NULL,
  `repair_count` int(10) unsigned NOT NULL DEFAULT 0,
  `repair_available_at` timestamp(6) NULL DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `workflow_tasks_status_available_at_index` (`status`,`available_at`),
  KEY `workflow_tasks_workflow_run_id_index` (`workflow_run_id`),
  KEY `workflow_tasks_namespace_index` (`namespace`),
  KEY `workflow_tasks_sticky_worker_id_index` (`sticky_worker_id`),
  KEY `workflow_tasks_sticky_until_index` (`sticky_until`),
  KEY `workflow_tasks_sticky_replay_mode_index` (`sticky_replay_mode`),
  KEY `workflow_tasks_sticky_claimed_at_index` (`sticky_claimed_at`),
  KEY `workflow_tasks_dispatch_order_index` (`queue`,`status`,`priority`,`available_at`),
  KEY `workflow_tasks_namespace_queue_status_idx` (`namespace`,`queue`,`status`),
  KEY `workflow_tasks_fairness_class_index` (`queue`,`status`,`fairness_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_timers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_timers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stored_workflow_id` bigint(20) unsigned NOT NULL,
  `index` int(11) NOT NULL,
  `stop_at` timestamp(6) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `workflow_timers_stored_workflow_id_created_at_index` (`stored_workflow_id`,`created_at`),
  KEY `workflow_timers_stored_workflow_id_index` (`stored_workflow_id`),
  CONSTRAINT `workflow_timers_stored_workflow_id_foreign` FOREIGN KEY (`stored_workflow_id`) REFERENCES `workflows` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_updates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_updates` (
  `id` varchar(26) NOT NULL,
  `workflow_command_id` varchar(26) NOT NULL,
  `workflow_instance_id` varchar(191) DEFAULT NULL,
  `workflow_run_id` varchar(26) DEFAULT NULL,
  `target_scope` varchar(255) NOT NULL DEFAULT 'instance',
  `requested_workflow_run_id` varchar(26) DEFAULT NULL,
  `resolved_workflow_run_id` varchar(26) DEFAULT NULL,
  `update_name` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `outcome` varchar(255) DEFAULT NULL,
  `command_sequence` int(10) unsigned DEFAULT NULL,
  `workflow_sequence` int(10) unsigned DEFAULT NULL,
  `payload_codec` varchar(255) DEFAULT NULL,
  `arguments` longtext DEFAULT NULL,
  `result` longtext DEFAULT NULL,
  `validation_errors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`validation_errors`)),
  `rejection_reason` varchar(255) DEFAULT NULL,
  `failure_id` varchar(26) DEFAULT NULL,
  `failure_message` text DEFAULT NULL,
  `accepted_at` timestamp(6) NULL DEFAULT NULL,
  `applied_at` timestamp(6) NULL DEFAULT NULL,
  `rejected_at` timestamp(6) NULL DEFAULT NULL,
  `closed_at` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workflow_updates_workflow_command_id_unique` (`workflow_command_id`),
  KEY `workflow_updates_workflow_instance_id_index` (`workflow_instance_id`),
  KEY `workflow_updates_workflow_run_id_index` (`workflow_run_id`),
  KEY `workflow_updates_requested_workflow_run_id_index` (`requested_workflow_run_id`),
  KEY `workflow_updates_resolved_workflow_run_id_index` (`resolved_workflow_run_id`),
  KEY `workflow_updates_update_name_index` (`update_name`),
  KEY `workflow_updates_status_index` (`status`),
  KEY `workflow_updates_outcome_index` (`outcome`),
  KEY `workflow_updates_command_sequence_index` (`command_sequence`),
  KEY `workflow_updates_workflow_sequence_index` (`workflow_sequence`),
  KEY `workflow_updates_failure_id_index` (`failure_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_worker_compatibility_heartbeats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_worker_compatibility_heartbeats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `worker_id` varchar(255) NOT NULL,
  `scope_key` varchar(64) NOT NULL,
  `namespace` varchar(255) DEFAULT NULL,
  `host` varchar(255) DEFAULT NULL,
  `process_id` varchar(255) DEFAULT NULL,
  `connection` varchar(255) DEFAULT NULL,
  `queue` varchar(255) DEFAULT NULL,
  `supported` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`supported`)),
  `recorded_at` datetime(6) NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workflow_worker_compatibility_heartbeats_scope_unique` (`worker_id`,`scope_key`),
  KEY `workflow_worker_compatibility_heartbeats_namespace_index` (`namespace`),
  KEY `workflow_worker_compatibility_heartbeats_connection_index` (`connection`),
  KEY `workflow_worker_compatibility_heartbeats_queue_index` (`queue`),
  KEY `workflow_worker_compatibility_heartbeats_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflows` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `class` text NOT NULL,
  `arguments` text DEFAULT NULL,
  `output` text DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `workflows_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

/*M!999999\- enable the sandbox mode */
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2022_01_01_000000_create_workflows_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2022_01_01_000001_create_workflow_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2022_01_01_000002_create_workflow_signals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2022_01_01_000003_create_workflow_timers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2022_01_01_000004_create_workflow_exceptions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2022_01_01_000005_create_workflow_relationships_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_02_05_182721_create_agent_conversations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_04_05_000100_create_workflow_instances_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_04_05_000101_create_workflow_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_04_05_000102_create_workflow_history_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_04_05_000103_create_workflow_tasks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_04_05_000104_create_activity_executions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_04_05_000105_create_workflow_failures_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_04_05_000106_create_workflow_run_summaries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_04_05_000107_create_workflow_run_timers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_04_05_000108_create_workflow_commands_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_04_05_000112_create_workflow_links_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_04_08_000124_create_activity_attempts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_04_08_000126_create_worker_compatibility_heartbeats_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_04_09_000000_create_waterline_saved_views_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_04_09_000128_create_workflow_updates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_04_09_000130_create_workflow_signal_records_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_04_10_000136_create_workflow_run_waits_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_04_10_000137_create_workflow_run_timeline_entries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_04_10_000139_create_workflow_run_lineage_entries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_04_11_000140_create_workflow_run_timer_entries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_04_14_000157_create_workflow_schedules_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_04_15_000150_create_workflow_search_attributes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_04_16_000151_create_workflow_memos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_04_16_000160_create_workflow_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_04_16_000170_create_workflow_child_calls_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_04_16_000171_create_workflow_child_projection_repairs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_04_16_000180_create_workflow_schedule_history_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_04_21_000000_create_waterline_user_preferences_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_04_22_001700_create_ai_workflow_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_04_24_000190_create_workflow_service_endpoints_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_04_24_000191_create_workflow_services_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_04_24_000192_create_workflow_service_operations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_04_24_000193_create_workflow_service_calls_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_05_05_000200_add_embedded_import_markers_to_workflow_runs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_05_05_000590_add_sticky_execution_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_05_08_000200_add_history_budget_pressure_to_workflow_run_summaries',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_05_09_000300_add_priority_and_fairness_to_workflow_tasks',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_05_20_000100_add_keyword_list_to_workflow_search_attributes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_07_13_000100_add_output_payload_codec_to_workflow_runs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_07_14_000100_add_request_id_to_workflow_commands',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_08_27_000100_add_worker_attempt_id_to_activity_attempts',1);

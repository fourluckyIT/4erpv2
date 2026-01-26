mysqldump: [Warning] Using a password on the command line interface can be insecure.
-- MySQL dump 10.13  Distrib 8.0.44, for macos12.7 (arm64)
--
-- Host: 127.0.0.1    Database: erp_v2
-- ------------------------------------------------------
-- Server version	8.0.44

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `user_role` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Role at time of action',
  `request_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'UUID trace ID for request',
  `action_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'create/update/approve/void/login/etc',
  `entity_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'JOB/PO/USER/etc',
  `entity_id` bigint unsigned DEFAULT NULL COMMENT 'ID of affected entity',
  `old_value` json DEFAULT NULL COMMENT 'Previous values (diff)',
  `new_value` json DEFAULT NULL COMMENT 'New values (diff)',
  `reason` text COLLATE utf8mb4_unicode_ci COMMENT 'Required for cancel/void/override',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_entity` (`entity_type`,`entity_id`),
  KEY `idx_action` (`action_name`),
  KEY `idx_request` (`request_id`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=67 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (1,1,'ADM','edaa21d7-d6c8-40c2-80c0-5ba903f64f55','login','USER',1,NULL,'{\"ip\": \"::1\", \"username\": \"admin\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:16:55'),(2,1,'ADM','edaa21d7-d6c8-40c2-80c0-5ba903f64f55','logout','USER',1,NULL,'{\"ip\": \"::1\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:25:39'),(3,1,'ADM','6e8e5983-c072-4c9b-ab6a-19a88ca7568d','login','USER',1,NULL,'{\"ip\": \"::1\", \"username\": \"admin\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:26:00'),(4,1,'ADM','6e8e5983-c072-4c9b-ab6a-19a88ca7568d','logout','USER',1,NULL,'{\"ip\": \"::1\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:26:07'),(5,1,'ADM','f7ded360-b9b2-4749-b384-acb52b93ffed','login','USER',1,NULL,'{\"ip\": \"::1\", \"username\": \"admin\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:27:03'),(6,1,'ADM','f7ded360-b9b2-4749-b384-acb52b93ffed','logout','USER',1,NULL,'{\"ip\": \"::1\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:31:51'),(7,1,'ADM','b6314c2e-58eb-4e71-9f70-f2ada5d789e5','login','USER',1,NULL,'{\"ip\": \"::1\", \"username\": \"admin\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:32:07'),(8,1,'ADM','b6314c2e-58eb-4e71-9f70-f2ada5d789e5','logout','USER',1,NULL,'{\"ip\": \"::1\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:32:11'),(9,1,'ADM','f23ecee6-6fd4-4c05-9f19-4aa5507f67d8','login','USER',1,NULL,'{\"ip\": \"::1\", \"username\": \"admin\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:32:17'),(10,1,'ADM','f23ecee6-6fd4-4c05-9f19-4aa5507f67d8','create','USER',2,NULL,'{\"email\": \"wh@4erp.com\", \"roles\": [\"6\"], \"username\": \"wh\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:34:16'),(11,1,'ADM','f23ecee6-6fd4-4c05-9f19-4aa5507f67d8','create','USER',3,NULL,'{\"email\": \"sal@4erp.com\", \"roles\": [\"2\"], \"username\": \"sal\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:34:38'),(12,1,'ADM','f23ecee6-6fd4-4c05-9f19-4aa5507f67d8','logout','USER',1,NULL,'{\"ip\": \"::1\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:34:42'),(13,3,'SAL','d4c74ba0-6c8d-4464-8cc0-5ad881870a6c','login','USER',3,NULL,'{\"ip\": \"::1\", \"username\": \"sal\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:34:44'),(14,3,'SAL','d4c74ba0-6c8d-4464-8cc0-5ad881870a6c','logout','USER',3,NULL,'{\"ip\": \"::1\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:34:59'),(15,2,'WH','29012829-75c6-41e7-84d5-a349c4734c80','login','USER',2,NULL,'{\"ip\": \"::1\", \"username\": \"wh\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:35:02'),(16,2,'WH','29012829-75c6-41e7-84d5-a349c4734c80','logout','USER',2,NULL,'{\"ip\": \"::1\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:35:11'),(17,1,'ADM','7fc40467-48e8-477d-930b-52173bc3c54c','login','USER',1,NULL,'{\"ip\": \"::1\", \"username\": \"admin\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:35:14'),(18,1,'ADM','587ff6f8-0920-432d-a52a-445d24856d8f','login','USER',1,NULL,'{\"ip\": \"::1\", \"username\": \"admin\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:35:14'),(19,1,'ADM','587ff6f8-0920-432d-a52a-445d24856d8f','line_bind','LINE_BINDING',1,NULL,'{\"user_id\": 1, \"line_user_id\": \"xxx\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:39:13'),(20,1,'ADM','587ff6f8-0920-432d-a52a-445d24856d8f','unbind','LINE_BINDING',1,'{\"id\": 1, \"user_id\": 1, \"bound_by\": 1, \"is_active\": 1, \"created_at\": \"2026-01-15 21:39:13\", \"updated_at\": \"2026-01-15 21:39:13\", \"display_name\": \"JT\", \"line_user_id\": \"xxx\"}',NULL,'Unbound by admin','::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 14:39:16'),(21,1,'ADM','73d3b0d7-bdd3-48bb-b55f-72128499a0cf','create','JOB',1,NULL,'{\"job_number\": \"JOB-2026-00105\", \"customer_id\": 1}',NULL,'0.0.0.0','','2026-01-15 15:13:56'),(22,1,'ADM','d0363068-bda0-4408-9e56-bb98cea74b2c','submit','JOB',1,'{\"status\": \"Draft\"}','{\"status\": \"Submitted\"}',NULL,'0.0.0.0','','2026-01-15 15:13:56'),(23,1,'ADM','647ef75b-0f84-4d84-bb8a-a42f451bc52f','approve','JOB',1,'{\"status\": \"Submitted\"}','{\"status\": \"Approved\"}',NULL,'0.0.0.0','','2026-01-15 15:13:56'),(24,1,'ADM','03321b38-dfb3-4a40-8a1e-560407b34fc5','create','JOB',2,NULL,'{\"job_number\": \"JOB-2026-00106\", \"customer_id\": 1}',NULL,'0.0.0.0','','2026-01-15 15:32:04'),(25,1,'ADM','a1498d77-e642-4658-ab99-9072a8fff091','submit','JOB',2,'{\"status\": \"Draft\"}','{\"status\": \"Submitted\"}',NULL,'0.0.0.0','','2026-01-15 15:32:04'),(26,1,'ADM','fc5ca236-002a-43d7-98ad-a8e9c2c69b26','approve','JOB',2,'{\"status\": \"Submitted\"}','{\"status\": \"Approved\"}',NULL,'0.0.0.0','','2026-01-15 15:32:04'),(27,1,'SAL','38edb043-21c1-4c05-8160-ed4bb28b6501','create','JOB',3,NULL,'{\"job_number\": \"JOB-2026-00107\", \"customer_id\": 1}',NULL,'0.0.0.0','','2026-01-15 15:32:04'),(28,1,'SAL','c692d952-fbd2-427c-9fc7-8d3fcd7dfa63','submit','JOB',3,'{\"status\": \"Draft\"}','{\"status\": \"Submitted\"}',NULL,'0.0.0.0','','2026-01-15 15:32:04'),(29,1,'ADM','6384d48c-0490-4225-a142-57f74fcae3bb','create','JOB',4,NULL,'{\"job_number\": \"JOB-2026-00108\", \"customer_id\": 1}',NULL,'0.0.0.0','','2026-01-15 15:32:04'),(30,1,'ADM','ebb3adff-3cad-49c0-afc5-b00c548977c0','submit','JOB',4,'{\"status\": \"Draft\"}','{\"status\": \"Submitted\"}',NULL,'0.0.0.0','','2026-01-15 15:32:04'),(31,1,'ADM','6e207012-0cef-4a92-aff5-9749ac850679','create','JOB',5,NULL,'{\"job_number\": \"JOB-2026-00109\", \"customer_id\": 1}',NULL,'0.0.0.0','','2026-01-15 15:42:12'),(32,1,'ADM','b4973402-521d-4647-89a9-dc0ff12501ae','create','JOB',6,NULL,'{\"job_number\": \"JOB-2026-00110\", \"customer_id\": 1}',NULL,'0.0.0.0','','2026-01-15 15:42:12'),(33,1,'ADM','e89beeb1-4bfa-4d5f-b9a0-f9add7335a89','submit','JOB',6,'{\"status\": \"Draft\"}','{\"status\": \"Submitted\"}',NULL,'0.0.0.0','','2026-01-15 15:42:12'),(34,1,'ADM','128b7692-11fb-40e3-bf8f-091ef63363a3','create','JOB',7,NULL,'{\"job_number\": \"JOB-2026-00111\", \"customer_id\": 1}',NULL,'0.0.0.0','','2026-01-15 15:42:12'),(35,1,'ADM','4c90da92-701b-44c6-b874-a6c4480fb781','submit','JOB',7,'{\"status\": \"Draft\"}','{\"status\": \"Submitted\"}',NULL,'0.0.0.0','','2026-01-15 15:42:12'),(36,1,'ADM','4493a678-fbbf-4638-b814-995b8f59d831','approve','JOB',7,'{\"status\": \"Submitted\"}','{\"status\": \"Approved\"}',NULL,'0.0.0.0','','2026-01-15 15:42:12'),(37,1,'ADM','6e32ed7d-04c9-4365-b813-0cb80ba7ae13','void','JOB',7,'{\"status\": \"Approved\"}','{\"status\": \"Voided\"}','ผิดพลาดร้ายแรง','0.0.0.0','','2026-01-15 15:42:12'),(38,1,'ADM','454fb52a-7844-47e1-97a5-1e0dfaead1b1','create','JOB',8,NULL,'{\"job_number\": \"JOB-2026-00112\", \"customer_id\": 1}',NULL,'0.0.0.0','','2026-01-15 15:42:12'),(39,1,'ADM','e700f09c-3d59-47fd-b6d7-e6fa77466778','create','JOB',9,NULL,'{\"job_number\": \"JOB-2026-00113\", \"customer_id\": 1}',NULL,'0.0.0.0','','2026-01-15 15:42:52'),(40,1,'ADM','c266b614-e568-4ec8-b207-f33836280107','cancel','JOB',9,'{\"status\": \"Draft\"}','{\"status\": \"Cancelled\"}','ไม่ต้องการแล้ว','0.0.0.0','','2026-01-15 15:42:52'),(41,1,'ADM','c64f40f2-d428-4400-8c95-76c9fecd317f','create','JOB',10,NULL,'{\"job_number\": \"JOB-2026-00114\", \"customer_id\": 1}',NULL,'0.0.0.0','','2026-01-15 15:42:52'),(42,1,'ADM','2d7db9be-720f-438d-9c69-56ecf05da261','submit','JOB',10,'{\"status\": \"Draft\"}','{\"status\": \"Submitted\"}',NULL,'0.0.0.0','','2026-01-15 15:42:52'),(43,1,'ADM','0ba5fb81-e4fa-48fb-b5f5-b54e9f9329b5','reject','JOB',10,'{\"status\": \"Submitted\"}','{\"status\": \"Cancelled\"}','ข้อมูลไม่ครบ','0.0.0.0','','2026-01-15 15:42:52'),(44,1,'ADM','faec5c26-278a-450d-89fc-2cefaf532b52','create','JOB',11,NULL,'{\"job_number\": \"JOB-2026-00115\", \"customer_id\": 1}',NULL,'0.0.0.0','','2026-01-15 15:42:52'),(45,1,'ADM','641effa3-95f5-4598-ba6c-ef76a5205c39','submit','JOB',11,'{\"status\": \"Draft\"}','{\"status\": \"Submitted\"}',NULL,'0.0.0.0','','2026-01-15 15:42:52'),(46,1,'ADM','ef418f62-e34e-49a7-ac7f-1fbf00cf1adb','approve','JOB',11,'{\"status\": \"Submitted\"}','{\"status\": \"Approved\"}',NULL,'0.0.0.0','','2026-01-15 15:42:52'),(47,1,'ADM','0b8e8370-3b6c-40f6-996e-40e7947606ad','void','JOB',11,'{\"status\": \"Approved\"}','{\"status\": \"Voided\"}','ผิดพลาดร้ายแรง','0.0.0.0','','2026-01-15 15:42:52'),(48,1,'ADM','a738b418-0450-42d5-bdb1-ea20084568bb','create','JOB',12,NULL,'{\"job_number\": \"JOB-2026-00116\", \"customer_id\": 1}',NULL,'0.0.0.0','','2026-01-15 15:42:52'),(49,1,'ADM','8a893685-1587-404c-967b-67361acf2010','login','USER',1,NULL,'{\"ip\": \"::1\", \"username\": \"admin\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-15 16:17:13'),(50,1,'ADM','0f98ec2b-4034-4756-9037-db725fe6ab68','login','USER',1,NULL,'{\"ip\": \"::1\", \"username\": \"admin\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36','2026-01-15 16:20:48'),(51,1,'ADM','d69bf31d-0f12-44e5-842f-90c0dc5888ff','login','USER',1,NULL,'{\"ip\": \"::1\", \"username\": \"admin\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-16 00:26:38'),(52,1,'ADM','4855b3f3-d4e2-4544-82a4-e577aa62c3f0','login','USER',1,NULL,'{\"ip\": \"::1\", \"username\": \"admin\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36','2026-01-16 00:46:48'),(53,1,'ADM','84c3dadc-8850-40c8-8c86-b0c964b64205','login','USER',1,NULL,'{\"ip\": \"::1\", \"username\": \"admin\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36','2026-01-16 00:50:16'),(54,1,'ADM','d69ca7e1-7128-4635-a1c1-588342f79959','login','USER',1,NULL,'{\"ip\": \"::1\", \"username\": \"admin\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36','2026-01-16 07:46:26'),(55,1,'ADM','d69ca7e1-7128-4635-a1c1-588342f79959','logout','USER',1,NULL,'{\"ip\": \"::1\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36','2026-01-16 08:02:28'),(56,1,'ADM','80bfb98d-e46d-4147-b5ba-90897d0fb0db','login','USER',1,NULL,'{\"ip\": \"::1\", \"username\": \"admin\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36','2026-01-16 08:03:46'),(57,1,'ADM','80bfb98d-e46d-4147-b5ba-90897d0fb0db','update','USER',2,'{\"id\": 2, \"email\": \"wh@4erp.com\", \"phone\": null, \"username\": \"wh\", \"full_name\": \"WH A\", \"is_active\": 1, \"created_at\": \"2026-01-15 21:34:16\", \"updated_at\": \"2026-01-15 21:35:02\", \"last_login_at\": \"2026-01-15 21:35:02\", \"password_hash\": \"$2y$10$msjtDnlonZYVaJ1mHsHmruPGkSmK23pXe8CfhvJvXbtdAV.VbeozS\"}','{\"email\": \"wh@4erp.com\", \"roles\": [\"6\"], \"full_name\": \"WH A\", \"is_active\": 1}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36','2026-01-16 08:06:02'),(58,1,'ADM','80bfb98d-e46d-4147-b5ba-90897d0fb0db','logout','USER',1,NULL,'{\"ip\": \"::1\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36','2026-01-16 08:06:13'),(59,1,'ADM','23b448c9-cf39-4201-94bf-fd896d0be177','login','USER',1,NULL,'{\"ip\": \"::1\", \"username\": \"admin\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36','2026-01-16 08:11:42'),(60,1,'ADM','23b448c9-cf39-4201-94bf-fd896d0be177','submit','PR',1,NULL,NULL,NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36','2026-01-16 08:17:03'),(61,1,'ADM','23b448c9-cf39-4201-94bf-fd896d0be177','approve','PR',1,NULL,NULL,NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36','2026-01-16 08:22:45'),(62,1,'ADM','6c446a1c-9f9f-437b-99db-9325bcbbc274','login','USER',1,NULL,'{\"ip\": \"::1\", \"username\": \"admin\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-16 08:40:11'),(63,1,'ADM','6c446a1c-9f9f-437b-99db-9325bcbbc274','create','JOB',17,NULL,'{\"job_number\": \"JOB-2026-00117\", \"customer_id\": 1}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-16 09:49:37'),(64,1,'ADM','6c446a1c-9f9f-437b-99db-9325bcbbc274','submit','JOB',17,'{\"status\": \"Draft\"}','{\"status\": \"Submitted\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-16 09:49:53'),(65,1,'ADM','6c446a1c-9f9f-437b-99db-9325bcbbc274','approve','JOB',17,'{\"status\": \"Submitted\"}','{\"status\": \"Approved\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-16 09:50:02'),(66,1,'ADM','6c446a1c-9f9f-437b-99db-9325bcbbc274','plan','JOB',17,'{\"status\": \"Approved\"}','{\"status\": \"Planned\"}',NULL,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0','2026-01-16 09:50:10');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `custom_permissions`
--

DROP TABLE IF EXISTS `custom_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_permissions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `permission_id` int unsigned NOT NULL,
  `entity_status` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_granted` tinyint(1) DEFAULT '1' COMMENT '1=grant, 0=revoke',
  `reason` text COLLATE utf8mb4_unicode_ci,
  `assigned_by` int unsigned NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL COMMENT 'NULL means no expiry',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_perm_status` (`user_id`,`permission_id`,`entity_status`),
  KEY `fk_cp_perm` (`permission_id`),
  KEY `fk_cp_assigned` (`assigned_by`),
  CONSTRAINT `fk_cp_assigned` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `custom_permissions`
--

LOCK TABLES `custom_permissions` WRITE;
/*!40000 ALTER TABLE `custom_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `custom_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `tax_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `credit_limit` decimal(15,2) DEFAULT '0.00',
  `is_active` tinyint(1) DEFAULT '1',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_code` (`code`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
INSERT INTO `customers` VALUES (1,'CUST001','บริษัท ทดสอบ จำกัด','คุณทดสอบ','02-123-4567','test@example.com',NULL,NULL,0.00,1,1,'2026-01-15 15:08:14','2026-01-15 15:08:14'),(2,'CUST002','บริษัท ตัวอย่าง จำกัด','คุณตัวอย่าง','02-987-6543','example@example.com',NULL,NULL,0.00,1,1,'2026-01-15 15:08:14','2026-01-15 15:08:14');
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dispatch_items`
--

DROP TABLE IF EXISTS `dispatch_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dispatch_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `dispatch_id` int unsigned NOT NULL,
  `plan_item_id` int unsigned DEFAULT NULL,
  `item_id` int unsigned NOT NULL,
  `qty` decimal(10,2) NOT NULL DEFAULT '1.00',
  `serial_numbers` text COLLATE utf8mb4_unicode_ci COMMENT 'JSON array of serial numbers',
  `condition_note` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `dispatch_id` (`dispatch_id`),
  KEY `plan_item_id` (`plan_item_id`),
  KEY `item_id` (`item_id`),
  CONSTRAINT `dispatch_items_ibfk_1` FOREIGN KEY (`dispatch_id`) REFERENCES `dispatch_notes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dispatch_items_ibfk_2` FOREIGN KEY (`plan_item_id`) REFERENCES `plan_items` (`id`),
  CONSTRAINT `dispatch_items_ibfk_3` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dispatch_items`
--

LOCK TABLES `dispatch_items` WRITE;
/*!40000 ALTER TABLE `dispatch_items` DISABLE KEYS */;
INSERT INTO `dispatch_items` VALUES (1,1,NULL,2001,1.00,'[\"SN-TEST-3830\"]','OK'),(2,2,NULL,2001,1.00,'[\"SN-TEST-3830\"]','OK'),(3,3,NULL,2001,1.00,'[\"SN-TEST-3830\"]',NULL),(4,4,NULL,2002,1.00,'[\"SN-TEST-7288\"]','OK'),(5,5,NULL,2002,1.00,'[\"SN-TEST-7288\"]','OK'),(6,6,NULL,2002,1.00,'[\"SN-TEST-7288\"]',NULL);
/*!40000 ALTER TABLE `dispatch_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dispatch_notes`
--

DROP TABLE IF EXISTS `dispatch_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dispatch_notes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `do_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `plan_id` int unsigned NOT NULL,
  `vehicle_id` int unsigned DEFAULT NULL,
  `driver_id` int unsigned DEFAULT NULL,
  `dispatch_date` datetime NOT NULL,
  `status` enum('Draft','Prepared','Dispatched','Delivered','Cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'Draft',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int unsigned NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `do_number` (`do_number`),
  KEY `plan_id` (`plan_id`),
  KEY `vehicle_id` (`vehicle_id`),
  KEY `driver_id` (`driver_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `dispatch_notes_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`),
  CONSTRAINT `dispatch_notes_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `items` (`id`),
  CONSTRAINT `dispatch_notes_ibfk_3` FOREIGN KEY (`driver_id`) REFERENCES `people` (`id`),
  CONSTRAINT `dispatch_notes_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dispatch_notes`
--

LOCK TABLES `dispatch_notes` WRITE;
/*!40000 ALTER TABLE `dispatch_notes` DISABLE KEYS */;
INSERT INTO `dispatch_notes` VALUES (1,'DO-TEST-1',1,NULL,NULL,'2026-01-16 15:59:33','Draft',NULL,1,'2026-01-16 15:59:33','2026-01-16 15:59:33'),(2,'DO-TEST-2',1,NULL,NULL,'2026-01-16 15:59:33','Draft',NULL,1,'2026-01-16 15:59:33','2026-01-16 15:59:33'),(3,'DO-TEST-3',1,NULL,NULL,'2026-01-16 15:59:33','Draft',NULL,1,'2026-01-16 15:59:33','2026-01-16 15:59:33'),(4,'DO-TEST-1b',2,NULL,NULL,'2026-01-16 16:01:12','Draft',NULL,1,'2026-01-16 16:01:12','2026-01-16 16:01:12'),(5,'DO-TEST-2b',2,NULL,NULL,'2026-01-16 16:01:12','Draft',NULL,1,'2026-01-16 16:01:12','2026-01-16 16:01:12'),(6,'DO-TEST-3b',2,NULL,NULL,'2026-01-16 16:01:12','Draft',NULL,1,'2026-01-16 16:01:12','2026-01-16 16:01:12');
/*!40000 ALTER TABLE `dispatch_notes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `doc_number_log`
--

DROP TABLE IF EXISTS `doc_number_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `doc_number_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `doc_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `doc_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_id` bigint unsigned DEFAULT NULL COMMENT 'ID of document that received this number',
  `generated_by` int unsigned NOT NULL,
  `generated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_doc_number` (`doc_type`,`doc_number`),
  KEY `idx_entity` (`entity_id`),
  KEY `fk_dnl_user` (`generated_by`),
  CONSTRAINT `fk_dnl_user` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `doc_number_log`
--

LOCK TABLES `doc_number_log` WRITE;
/*!40000 ALTER TABLE `doc_number_log` DISABLE KEYS */;
INSERT INTO `doc_number_log` VALUES (1,'JOB','JOB-2026-00001',NULL,1,'2026-01-15 14:45:02'),(2,'JOB','JOB-2026-00002',NULL,1,'2026-01-15 14:45:02'),(3,'JOB','JOB-2026-00003',NULL,1,'2026-01-15 14:45:02'),(4,'JOB','JOB-2026-00004',NULL,1,'2026-01-15 14:45:02'),(5,'JOB','JOB-2026-00005',NULL,1,'2026-01-15 14:45:02'),(6,'JOB','JOB-2026-00006',NULL,1,'2026-01-15 14:45:02'),(7,'JOB','JOB-2026-00007',NULL,1,'2026-01-15 14:45:02'),(8,'JOB','JOB-2026-00008',NULL,1,'2026-01-15 14:45:02'),(9,'JOB','JOB-2026-00009',NULL,1,'2026-01-15 14:45:02'),(10,'JOB','JOB-2026-00010',NULL,1,'2026-01-15 14:45:02'),(11,'JOB','JOB-2026-00011',NULL,1,'2026-01-15 14:45:02'),(12,'JOB','JOB-2026-00012',NULL,1,'2026-01-15 14:45:02'),(13,'JOB','JOB-2026-00013',NULL,1,'2026-01-15 14:45:02'),(14,'JOB','JOB-2026-00014',NULL,1,'2026-01-15 14:45:02'),(15,'JOB','JOB-2026-00015',NULL,1,'2026-01-15 14:45:02'),(16,'JOB','JOB-2026-00016',NULL,1,'2026-01-15 14:45:02'),(17,'JOB','JOB-2026-00017',NULL,1,'2026-01-15 14:45:02'),(18,'JOB','JOB-2026-00100',NULL,1,'2026-01-15 14:45:39'),(19,'JOB','JOB-2026-00101',NULL,1,'2026-01-15 14:45:39'),(20,'JOB','JOB-2026-00102',NULL,1,'2026-01-15 14:45:39'),(21,'JOB','JOB-2026-00103',NULL,1,'2026-01-15 14:45:39'),(22,'JOB','JOB-2026-00104',NULL,1,'2026-01-15 14:45:39'),(23,'JOB','JOB-2026-00105',NULL,1,'2026-01-15 15:13:56'),(24,'JOB','JOB-2026-00106',NULL,1,'2026-01-15 15:32:04'),(25,'JOB','JOB-2026-00107',NULL,1,'2026-01-15 15:32:04'),(26,'JOB','JOB-2026-00108',NULL,1,'2026-01-15 15:32:04'),(27,'JOB','JOB-2026-00109',NULL,1,'2026-01-15 15:42:12'),(28,'JOB','JOB-2026-00110',NULL,1,'2026-01-15 15:42:12'),(29,'JOB','JOB-2026-00111',NULL,1,'2026-01-15 15:42:12'),(30,'JOB','JOB-2026-00112',NULL,1,'2026-01-15 15:42:12'),(31,'JOB','JOB-2026-00113',NULL,1,'2026-01-15 15:42:52'),(32,'JOB','JOB-2026-00114',NULL,1,'2026-01-15 15:42:52'),(33,'JOB','JOB-2026-00115',NULL,1,'2026-01-15 15:42:52'),(34,'JOB','JOB-2026-00116',NULL,1,'2026-01-15 15:42:52'),(35,'PR','PR-2026-00001',NULL,1,'2026-01-16 01:44:32'),(36,'PR','PR-2026-00002',NULL,1,'2026-01-16 01:44:58'),(37,'JOB','JOB-2026-00117',NULL,1,'2026-01-16 09:49:37');
/*!40000 ALTER TABLE `doc_number_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `doc_number_settings`
--

DROP TABLE IF EXISTS `doc_number_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `doc_number_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `doc_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'JOB, PO, PR, GR, INV, etc.',
  `prefix` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `current_year` year NOT NULL,
  `next_number` int unsigned NOT NULL DEFAULT '1',
  `padding` tinyint unsigned DEFAULT '5' COMMENT 'Digit padding (e.g. 5 = 00001)',
  `reset_yearly` tinyint(1) DEFAULT '1',
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `doc_type` (`doc_type`),
  KEY `fk_dns_updated` (`updated_by`),
  CONSTRAINT `fk_dns_updated` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `doc_number_settings`
--

LOCK TABLES `doc_number_settings` WRITE;
/*!40000 ALTER TABLE `doc_number_settings` DISABLE KEYS */;
INSERT INTO `doc_number_settings` VALUES (1,'JOB','JOB-',2026,118,5,1,NULL,'2026-01-15 14:11:47','2026-01-16 09:49:37'),(2,'PO','PO-',2026,1,5,1,NULL,'2026-01-15 14:11:47','2026-01-15 14:11:47'),(3,'PR','PR-',2026,3,5,1,NULL,'2026-01-15 14:11:47','2026-01-16 01:44:58'),(4,'GR','GR-',2026,1,5,1,NULL,'2026-01-15 14:11:47','2026-01-15 14:11:47'),(5,'DN','DN-',2026,1,5,1,NULL,'2026-01-15 14:11:47','2026-01-15 14:11:47'),(6,'RN','RN-',2026,1,5,1,NULL,'2026-01-15 14:11:47','2026-01-15 14:11:47'),(7,'INV','INV-',2026,1,5,1,NULL,'2026-01-15 14:11:47','2026-01-15 14:11:47'),(8,'PAY','PAY-',2026,1,5,1,NULL,'2026-01-15 14:11:47','2026-01-15 14:11:47'),(10,'PLN','PLN-',2026,1,5,1,NULL,'2026-01-16 08:45:39','2026-01-16 08:45:39'),(11,'DO','DO-',2026,1,5,1,NULL,'2026-01-16 08:45:39','2026-01-16 08:45:39');
/*!40000 ALTER TABLE `doc_number_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `goods_receipts`
--

DROP TABLE IF EXISTS `goods_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `goods_receipts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `gr_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `po_id` int unsigned NOT NULL,
  `received_date` date NOT NULL,
  `received_by` int unsigned NOT NULL,
  `status` enum('Draft','Confirmed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `confirmed_at` datetime DEFAULT NULL,
  `confirmed_by` int unsigned DEFAULT NULL,
  `created_by` int unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gr_number` (`gr_number`),
  KEY `idx_gr_number` (`gr_number`),
  KEY `idx_po` (`po_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_gr_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `goods_receipts`
--

LOCK TABLES `goods_receipts` WRITE;
/*!40000 ALTER TABLE `goods_receipts` DISABLE KEYS */;
/*!40000 ALTER TABLE `goods_receipts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gr_items`
--

DROP TABLE IF EXISTS `gr_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gr_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `gr_id` int unsigned NOT NULL,
  `po_item_id` int unsigned NOT NULL,
  `received_qty` decimal(10,2) NOT NULL,
  `serial_numbers` json DEFAULT NULL COMMENT 'For serialized items',
  `condition_note` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_gr` (`gr_id`),
  KEY `idx_po_item` (`po_item_id`),
  CONSTRAINT `fk_gri_gr` FOREIGN KEY (`gr_id`) REFERENCES `goods_receipts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gri_po_item` FOREIGN KEY (`po_item_id`) REFERENCES `po_items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gr_items`
--

LOCK TABLES `gr_items` WRITE;
/*!40000 ALTER TABLE `gr_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `gr_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `items`
--

DROP TABLE IF EXISTS `items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_type` enum('Device','Equipment','Vehicle','Consumable') COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Sub-category',
  `brand` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `unit` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'pcs' COMMENT 'Unit of measure',
  `is_serialized` tinyint(1) DEFAULT '0' COMMENT 'Track by serial number',
  `min_stock` int DEFAULT '0',
  `cost_price` decimal(15,2) DEFAULT '0.00',
  `rental_price_day` decimal(15,2) DEFAULT '0.00' COMMENT 'Daily rental rate',
  `sale_price` decimal(15,2) DEFAULT '0.00',
  `supplier_id` int unsigned DEFAULT NULL COMMENT 'Default supplier',
  `is_active` tinyint(1) DEFAULT '1',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_code` (`code`),
  KEY `idx_type` (`item_type`),
  KEY `idx_name` (`name`),
  KEY `idx_active` (`is_active`),
  KEY `fk_item_supplier` (`supplier_id`),
  CONSTRAINT `fk_item_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2003 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `items`
--

LOCK TABLES `items` WRITE;
/*!40000 ALTER TABLE `items` DISABLE KEYS */;
INSERT INTO `items` VALUES (1,'DEV001','iPad Pro 12.9\"','Device',NULL,'Apple',NULL,NULL,'pcs',1,0,0.00,500.00,0.00,NULL,1,1,'2026-01-15 16:04:45','2026-01-15 16:04:45'),(2,'DEV002','Samsung Galaxy Tab S9','Device',NULL,'Samsung',NULL,NULL,'pcs',1,0,0.00,400.00,0.00,NULL,1,1,'2026-01-15 16:04:45','2026-01-15 16:04:45'),(3,'EQP001','Projector Epson EB-X51','Equipment',NULL,'Epson',NULL,NULL,'pcs',1,0,0.00,800.00,0.00,NULL,1,1,'2026-01-15 16:04:45','2026-01-15 16:04:45'),(4,'VEH001','Toyota Hiace (รถตู้)','Vehicle',NULL,'Toyota',NULL,NULL,'คัน',1,0,0.00,2500.00,0.00,NULL,1,1,'2026-01-15 16:04:45','2026-01-15 16:04:45'),(5,'CON001','สาย HDMI 3m','Consumable',NULL,'Generic',NULL,NULL,'เส้น',0,0,0.00,0.00,0.00,NULL,1,1,'2026-01-15 16:04:45','2026-01-15 16:04:45'),(6,'CON002','ถ่าน AA Duracell','Consumable',NULL,'Duracell',NULL,NULL,'ก้อน',0,0,0.00,0.00,0.00,NULL,1,1,'2026-01-15 16:04:45','2026-01-15 16:04:45'),(2001,'ITM-TEST','Test Item','Equipment',NULL,NULL,NULL,NULL,'Units',1,0,0.00,0.00,0.00,NULL,1,1,'2026-01-16 08:59:33','2026-01-16 08:59:33'),(2002,'ITM-TEST-2','Test Item 2','Equipment',NULL,NULL,NULL,NULL,'Units',1,0,0.00,0.00,0.00,NULL,1,1,'2026-01-16 09:01:12','2026-01-16 09:01:12');
/*!40000 ALTER TABLE `items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_extensions`
--

DROP TABLE IF EXISTS `job_extensions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_extensions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `job_id` int unsigned NOT NULL,
  `extension_type` enum('extend_days','reduce_days','change_dates','add_equipment','remove_equipment','add_manpower','remove_manpower','other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_end_date` date DEFAULT NULL,
  `new_end_date` date DEFAULT NULL,
  `days_changed` int DEFAULT '0',
  `reason` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` text COLLATE utf8mb4_unicode_ci,
  `status` enum('Pending','Approved','Rejected') COLLATE utf8mb4_unicode_ci DEFAULT 'Pending',
  `requested_by` int unsigned NOT NULL,
  `requested_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_by` int unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_job` (`job_id`),
  KEY `idx_status` (`status`),
  KEY `fk_ext_requested` (`requested_by`),
  KEY `fk_ext_approved` (`approved_by`),
  CONSTRAINT `fk_ext_approved` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ext_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ext_requested` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_extensions`
--

LOCK TABLES `job_extensions` WRITE;
/*!40000 ALTER TABLE `job_extensions` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_extensions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_required_certs`
--

DROP TABLE IF EXISTS `job_required_certs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_required_certs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `job_id` int unsigned NOT NULL,
  `cert_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_mandatory` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_job` (`job_id`),
  CONSTRAINT `fk_jrc_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_required_certs`
--

LOCK TABLES `job_required_certs` WRITE;
/*!40000 ALTER TABLE `job_required_certs` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_required_certs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_status_history`
--

DROP TABLE IF EXISTS `job_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_status_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` int unsigned NOT NULL,
  `old_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `changed_by` int unsigned NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci COMMENT 'Required for void/cancel',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_job` (`job_id`),
  KEY `idx_created` (`created_at`),
  KEY `fk_jsh_user` (`changed_by`),
  CONSTRAINT `fk_jsh_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_jsh_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_status_history`
--

LOCK TABLES `job_status_history` WRITE;
/*!40000 ALTER TABLE `job_status_history` DISABLE KEYS */;
INSERT INTO `job_status_history` VALUES (1,1,NULL,'Draft',1,NULL,'2026-01-15 15:13:56'),(2,1,'Draft','Submitted',1,NULL,'2026-01-15 15:13:56'),(3,1,'Submitted','Approved',1,NULL,'2026-01-15 15:13:56'),(4,2,NULL,'Draft',1,NULL,'2026-01-15 15:32:04'),(5,2,'Draft','Submitted',1,NULL,'2026-01-15 15:32:04'),(6,2,'Submitted','Approved',1,NULL,'2026-01-15 15:32:04'),(7,3,NULL,'Draft',1,NULL,'2026-01-15 15:32:04'),(8,3,'Draft','Submitted',1,NULL,'2026-01-15 15:32:04'),(9,4,NULL,'Draft',1,NULL,'2026-01-15 15:32:04'),(10,4,'Draft','Submitted',1,NULL,'2026-01-15 15:32:04'),(11,5,NULL,'Draft',1,NULL,'2026-01-15 15:42:12'),(12,6,NULL,'Draft',1,NULL,'2026-01-15 15:42:12'),(13,6,'Draft','Submitted',1,NULL,'2026-01-15 15:42:12'),(14,7,NULL,'Draft',1,NULL,'2026-01-15 15:42:12'),(15,7,'Draft','Submitted',1,NULL,'2026-01-15 15:42:12'),(16,7,'Submitted','Approved',1,NULL,'2026-01-15 15:42:12'),(17,7,'Approved','Voided',1,'ผิดพลาดร้ายแรง','2026-01-15 15:42:12'),(18,8,NULL,'Draft',1,NULL,'2026-01-15 15:42:12'),(19,9,NULL,'Draft',1,NULL,'2026-01-15 15:42:52'),(20,9,'Draft','Cancelled',1,'ไม่ต้องการแล้ว','2026-01-15 15:42:52'),(21,10,NULL,'Draft',1,NULL,'2026-01-15 15:42:52'),(22,10,'Draft','Submitted',1,NULL,'2026-01-15 15:42:52'),(23,10,'Submitted','Cancelled',1,'ข้อมูลไม่ครบ','2026-01-15 15:42:52'),(24,11,NULL,'Draft',1,NULL,'2026-01-15 15:42:52'),(25,11,'Draft','Submitted',1,NULL,'2026-01-15 15:42:52'),(26,11,'Submitted','Approved',1,NULL,'2026-01-15 15:42:52'),(27,11,'Approved','Voided',1,'ผิดพลาดร้ายแรง','2026-01-15 15:42:52'),(28,12,NULL,'Draft',1,NULL,'2026-01-15 15:42:52'),(29,17,NULL,'Draft',1,NULL,'2026-01-16 09:49:37'),(30,17,'Draft','Submitted',1,NULL,'2026-01-16 09:49:53'),(31,17,'Submitted','Approved',1,NULL,'2026-01-16 09:50:02'),(32,17,'Approved','Planned',1,NULL,'2026-01-16 09:50:10');
/*!40000 ALTER TABLE `job_status_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `job_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` int unsigned NOT NULL,
  `site_id` int unsigned DEFAULT NULL,
  `job_type` enum('Lumpsum','Dayrent','Manpower') COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope_short` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Brief description',
  `scope_detail` text COLLATE utf8mb4_unicode_ci,
  `quotation_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Reference only, not linked',
  `plan_start_date` date NOT NULL,
  `plan_end_date` date NOT NULL,
  `actual_start_date` date DEFAULT NULL,
  `actual_end_date` date DEFAULT NULL,
  `owner_sale_id` int unsigned NOT NULL,
  `owner_planner_id` int unsigned DEFAULT NULL,
  `contract_value` decimal(15,2) DEFAULT '0.00',
  `budget` decimal(15,2) DEFAULT '0.00',
  `status` enum('Draft','Submitted','Approved','Planned','Dispatched','In Progress','Returned','WH Received','POS Checked','Accounting Ready','Invoiced','Paid','Partial Paid','Closed','Cancelled','Voided') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
  `submitted_at` timestamp NULL DEFAULT NULL,
  `submitted_by` int unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approved_by` int unsigned DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `closed_by` int unsigned DEFAULT NULL,
  `voided_at` timestamp NULL DEFAULT NULL,
  `voided_by` int unsigned DEFAULT NULL,
  `void_reason` text COLLATE utf8mb4_unicode_ci,
  `pdf_attachment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_number` (`job_number`),
  KEY `idx_job_number` (`job_number`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_status` (`status`),
  KEY `idx_dates` (`plan_start_date`,`plan_end_date`),
  KEY `idx_owner_sale` (`owner_sale_id`),
  KEY `idx_owner_planner` (`owner_planner_id`),
  KEY `fk_job_site` (`site_id`),
  KEY `fk_job_created` (`created_by`),
  CONSTRAINT `fk_job_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_job_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_job_planner` FOREIGN KEY (`owner_planner_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_job_sale` FOREIGN KEY (`owner_sale_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_job_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
INSERT INTO `jobs` VALUES (1,'JOB-2026-00105',1,NULL,'Lumpsum','Test Job - System Verification',NULL,NULL,'2026-01-15','2026-01-22',NULL,NULL,1,NULL,0.00,0.00,'Approved','2026-01-15 08:13:56',1,'2026-01-15 08:13:56',1,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-01-15 15:13:56','2026-01-15 15:13:56'),(2,'JOB-2026-00106',1,NULL,'Lumpsum','Test Wrong Path',NULL,NULL,'2026-01-15','2026-01-22',NULL,NULL,1,NULL,0.00,0.00,'Approved','2026-01-15 08:32:04',1,'2026-01-15 08:32:04',1,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-01-15 15:32:04','2026-01-15 15:32:04'),(3,'JOB-2026-00107',1,NULL,'Dayrent','Test Role Check',NULL,NULL,'2026-01-15','2026-01-20',NULL,NULL,1,NULL,0.00,0.00,'Submitted','2026-01-15 08:32:04',1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-01-15 15:32:04','2026-01-15 15:32:04'),(4,'JOB-2026-00108',1,NULL,'Manpower','Test Double Submit',NULL,NULL,'2026-01-15','2026-01-18',NULL,NULL,1,NULL,0.00,0.00,'Submitted','2026-01-15 08:32:04',1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-01-15 15:32:04','2026-01-15 15:32:04'),(5,'JOB-2026-00109',1,NULL,'Lumpsum','Test Cancel',NULL,NULL,'2026-01-15','2026-01-18',NULL,NULL,1,NULL,0.00,0.00,'Draft',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-01-15 15:42:12','2026-01-15 15:42:12'),(6,'JOB-2026-00110',1,NULL,'Dayrent','Test Reject',NULL,NULL,'2026-01-15','2026-01-18',NULL,NULL,1,NULL,0.00,0.00,'Submitted','2026-01-15 08:42:12',1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-01-15 15:42:12','2026-01-15 15:42:12'),(7,'JOB-2026-00111',1,NULL,'Manpower','Test Void',NULL,NULL,'2026-01-15','2026-01-18',NULL,NULL,1,NULL,0.00,0.00,'Voided','2026-01-15 08:42:12',1,'2026-01-15 08:42:12',1,NULL,NULL,'2026-01-15 08:42:12',1,'ผิดพลาดร้ายแรง',NULL,1,'2026-01-15 15:42:12','2026-01-15 15:42:12'),(8,'JOB-2026-00112',1,NULL,'Lumpsum','Test NoVoid',NULL,NULL,'2026-01-15','2026-01-18',NULL,NULL,1,NULL,0.00,0.00,'Draft',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-01-15 15:42:12','2026-01-15 15:42:12'),(9,'JOB-2026-00113',1,NULL,'Lumpsum','Test Cancel',NULL,NULL,'2026-01-15','2026-01-18',NULL,NULL,1,NULL,0.00,0.00,'Cancelled',NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-15 08:42:52',1,'ไม่ต้องการแล้ว',NULL,1,'2026-01-15 15:42:52','2026-01-15 15:42:52'),(10,'JOB-2026-00114',1,NULL,'Dayrent','Test Reject',NULL,NULL,'2026-01-15','2026-01-18',NULL,NULL,1,NULL,0.00,0.00,'Cancelled','2026-01-15 08:42:52',1,NULL,NULL,NULL,NULL,'2026-01-15 08:42:52',1,'ข้อมูลไม่ครบ',NULL,1,'2026-01-15 15:42:52','2026-01-15 15:42:52'),(11,'JOB-2026-00115',1,NULL,'Manpower','Test Void',NULL,NULL,'2026-01-15','2026-01-18',NULL,NULL,1,NULL,0.00,0.00,'Voided','2026-01-15 08:42:52',1,'2026-01-15 08:42:52',1,NULL,NULL,'2026-01-15 08:42:52',1,'ผิดพลาดร้ายแรง',NULL,1,'2026-01-15 15:42:52','2026-01-15 15:42:52'),(12,'JOB-2026-00116',1,NULL,'Lumpsum','Test NoVoid',NULL,NULL,'2026-01-15','2026-01-18',NULL,NULL,1,NULL,0.00,0.00,'Draft',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-01-15 15:42:52','2026-01-15 15:42:52'),(13,'J-TEST-DRAFT',1,NULL,'Lumpsum','Draft Job',NULL,NULL,'2026-01-16','2026-01-16',NULL,NULL,1,NULL,0.00,0.00,'Draft',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-01-16 08:59:32','2026-01-16 08:59:32'),(14,'J-TEST-DISP',1,NULL,'Lumpsum','Test Dispatch',NULL,NULL,'2026-01-16','2026-01-16',NULL,NULL,1,NULL,0.00,0.00,'Approved',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-01-16 08:59:32','2026-01-16 08:59:32'),(15,'J-TEST-DRAFT-2',1,NULL,'Lumpsum','Draft Job 2',NULL,NULL,'2026-01-16','2026-01-16',NULL,NULL,1,NULL,0.00,0.00,'Draft',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-01-16 09:01:12','2026-01-16 09:01:12'),(16,'J-TEST-DISP-2',1,NULL,'Lumpsum','Test Dispatch 2',NULL,NULL,'2026-01-16','2026-01-16',NULL,NULL,1,NULL,0.00,0.00,'Approved',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-01-16 09:01:12','2026-01-16 09:01:12'),(17,'JOB-2026-00117',1,2,'Lumpsum','ล้างเครื่องจักรใหญ่','สวัสดีครับ ทำความสะอาดครับ','','2026-01-16','2026-01-23',NULL,NULL,3,1,1000000.00,500000.00,'Planned','2026-01-16 09:49:53',1,'2026-01-16 09:50:02',1,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-01-16 09:49:37','2026-01-16 09:50:10');
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `line_bindings`
--

DROP TABLE IF EXISTS `line_bindings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `line_bindings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `line_user_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'LINE userId',
  `display_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `bound_by` int unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `line_user_id` (`line_user_id`),
  UNIQUE KEY `uk_user` (`user_id`),
  KEY `idx_line_user` (`line_user_id`),
  KEY `fk_lb_bound` (`bound_by`),
  CONSTRAINT `fk_lb_bound` FOREIGN KEY (`bound_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_lb_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `line_bindings`
--

LOCK TABLES `line_bindings` WRITE;
/*!40000 ALTER TABLE `line_bindings` DISABLE KEYS */;
/*!40000 ALTER TABLE `line_bindings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `people`
--

DROP TABLE IF EXISTS `people`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `people` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Employee ID or External ID',
  `full_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `people_type` enum('Employee','External') COLLATE utf8mb4_unicode_ci NOT NULL,
  `position` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_card` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'บัตรประชาชน',
  `address` text COLLATE utf8mb4_unicode_ci,
  `daily_rate` decimal(10,2) DEFAULT '0.00' COMMENT 'For manpower cost calculation',
  `emergency_contact` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_id` int unsigned DEFAULT NULL COMMENT 'For External: which supplier provides',
  `hire_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_code` (`code`),
  KEY `idx_type` (`people_type`),
  KEY `idx_name` (`full_name`),
  KEY `idx_active` (`is_active`),
  KEY `fk_people_supplier` (`supplier_id`),
  CONSTRAINT `fk_people_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `people`
--

LOCK TABLES `people` WRITE;
/*!40000 ALTER TABLE `people` DISABLE KEYS */;
INSERT INTO `people` VALUES (1,'EMP001','นายสมชาย ใจดี','Employee','Senior Technician','Operation','089-111-1111',NULL,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,1,1,'2026-01-15 16:04:45','2026-01-15 16:04:45'),(2,'EMP002','นางสาวสมหญิง รักงาน','Employee','Technician','Operation','089-222-2222',NULL,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,1,1,'2026-01-15 16:04:45','2026-01-15 16:04:45'),(3,'EXT001','นายแรงงาน หนึ่ง','External','Helper',NULL,'089-333-3333',NULL,NULL,NULL,500.00,NULL,NULL,NULL,NULL,NULL,1,1,'2026-01-15 16:04:45','2026-01-15 16:04:45'),(4,'EXT002','นายแรงงาน สอง','External','Helper',NULL,'089-444-4444',NULL,NULL,NULL,500.00,NULL,NULL,NULL,NULL,NULL,1,1,'2026-01-15 16:04:45','2026-01-15 16:04:45');
/*!40000 ALTER TABLE `people` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `people_certs`
--

DROP TABLE IF EXISTS `people_certs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `people_certs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `people_id` int unsigned NOT NULL,
  `cert_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cert_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issued_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issued_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `document_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Uploaded certificate file',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_people` (`people_id`),
  KEY `idx_expiry` (`expiry_date`),
  CONSTRAINT `fk_cert_people` FOREIGN KEY (`people_id`) REFERENCES `people` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `people_certs`
--

LOCK TABLES `people_certs` WRITE;
/*!40000 ALTER TABLE `people_certs` DISABLE KEYS */;
INSERT INTO `people_certs` VALUES (1,1,'ใบขับขี่ประเภท 2','DL-12345678',NULL,NULL,'2027-12-31',NULL,1,'2026-01-15 16:04:45'),(2,1,'ความปลอดภัยในการทำงาน','SAFE-001',NULL,NULL,'2025-06-30',NULL,1,'2026-01-15 16:04:45'),(3,2,'ใบขับขี่ประเภท 1','DL-87654321',NULL,NULL,'2026-08-15',NULL,1,'2026-01-15 16:04:45');
/*!40000 ALTER TABLE `people_certs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'JOB, PO, PR, GR, INVOICE, etc.',
  `action` enum('view','create','edit','approve','void','cancel','extend','dispatch','close','export','print') COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_entity_action` (`entity_type`,`action`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'JOB_VIEW','View Job','JOB','view','View job details','2026-01-15 14:11:47'),(2,'JOB_CREATE','Create Job','JOB','create','Create new job','2026-01-15 14:11:47'),(3,'JOB_EDIT','Edit Job','JOB','edit','Edit job details','2026-01-15 14:11:47'),(4,'JOB_APPROVE','Approve Job','JOB','approve','Approve job','2026-01-15 14:11:47'),(5,'JOB_VOID','Void Job','JOB','void','Void/cancel job','2026-01-15 14:11:47'),(6,'JOB_EXTEND','Extend Job','JOB','extend','Request job extension','2026-01-15 14:11:47'),(7,'JOB_DISPATCH','Dispatch Job','JOB','dispatch','Dispatch job resources','2026-01-15 14:11:47'),(8,'JOB_CLOSE','Close Job','JOB','close','Close job','2026-01-15 14:11:47'),(9,'PR_VIEW','View PR','PR','view','View purchase request','2026-01-15 14:11:47'),(10,'PR_CREATE','Create PR','PR','create','Create purchase request','2026-01-15 14:11:47'),(11,'PR_APPROVE','Approve PR','PR','approve','Approve purchase request','2026-01-15 14:11:47'),(12,'PO_VIEW','View PO','PO','view','View purchase order','2026-01-15 14:11:47'),(13,'PO_CREATE','Create PO','PO','create','Create purchase order','2026-01-15 14:11:47'),(14,'PO_APPROVE','Approve PO','PO','approve','Approve purchase order','2026-01-15 14:11:47'),(15,'PO_VOID','Void PO','PO','void','Void purchase order','2026-01-15 14:11:47'),(16,'USER_VIEW','View Users','USER','view','View user list','2026-01-15 14:11:47'),(17,'USER_CREATE','Create User','USER','create','Create new user','2026-01-15 14:11:47'),(18,'USER_EDIT','Edit User','USER','edit','Edit user details','2026-01-15 14:11:47'),(19,'PERM_MANAGE','Manage Permissions','PERMISSION','edit','Manage permissions and roles','2026-01-15 14:11:47');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plan_items`
--

DROP TABLE IF EXISTS `plan_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plan_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `plan_id` int unsigned NOT NULL,
  `item_id` int unsigned NOT NULL,
  `qty` decimal(10,2) NOT NULL DEFAULT '1.00',
  `notes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `plan_id` (`plan_id`),
  KEY `item_id` (`item_id`),
  CONSTRAINT `plan_items_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `plan_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plan_items`
--

LOCK TABLES `plan_items` WRITE;
/*!40000 ALTER TABLE `plan_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `plan_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plans`
--

DROP TABLE IF EXISTS `plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plans` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `plan_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `job_id` int unsigned NOT NULL,
  `plan_date` date NOT NULL,
  `status` enum('Draft','Confirmed','Cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'Draft',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int unsigned NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plan_number` (`plan_number`),
  KEY `job_id` (`job_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `plans_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`),
  CONSTRAINT `plans_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plans`
--

LOCK TABLES `plans` WRITE;
/*!40000 ALTER TABLE `plans` DISABLE KEYS */;
INSERT INTO `plans` VALUES (1,'P-TEST-DISP',14,'2026-01-16','Confirmed',NULL,1,'2026-01-16 15:59:33','2026-01-16 15:59:33'),(2,'P-TEST-DISP-2',16,'2026-01-16','Confirmed',NULL,1,'2026-01-16 16:01:12','2026-01-16 16:01:12');
/*!40000 ALTER TABLE `plans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `po_items`
--

DROP TABLE IF EXISTS `po_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `po_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `po_id` int unsigned NOT NULL,
  `pr_item_id` int unsigned DEFAULT NULL COMMENT 'Link to PR item if from PR',
  `item_id` int unsigned DEFAULT NULL COMMENT 'Nullable for non-catalog items',
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `qty` decimal(10,2) NOT NULL,
  `received_qty` decimal(10,2) DEFAULT '0.00',
  `unit` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'pcs',
  `unit_price` decimal(15,2) DEFAULT '0.00',
  `amount` decimal(15,2) DEFAULT '0.00',
  `notes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_po` (`po_id`),
  KEY `fk_poi_item` (`item_id`),
  KEY `fk_poi_pr_item` (`pr_item_id`),
  CONSTRAINT `fk_poi_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_poi_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_poi_pr_item` FOREIGN KEY (`pr_item_id`) REFERENCES `pr_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `po_items`
--

LOCK TABLES `po_items` WRITE;
/*!40000 ALTER TABLE `po_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `po_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `po_manpower`
--

DROP TABLE IF EXISTS `po_manpower`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `po_manpower` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `po_id` int unsigned NOT NULL,
  `people_id` int unsigned NOT NULL,
  `position` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `daily_rate` decimal(10,2) DEFAULT '0.00',
  `contract_start` date DEFAULT NULL,
  `contract_end` date DEFAULT NULL,
  `status` enum('Active','Ended','Cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_po` (`po_id`),
  KEY `idx_people` (`people_id`),
  CONSTRAINT `fk_pom_people` FOREIGN KEY (`people_id`) REFERENCES `people` (`id`),
  CONSTRAINT `fk_pom_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `po_manpower`
--

LOCK TABLES `po_manpower` WRITE;
/*!40000 ALTER TABLE `po_manpower` DISABLE KEYS */;
/*!40000 ALTER TABLE `po_manpower` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pr_items`
--

DROP TABLE IF EXISTS `pr_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pr_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pr_id` int unsigned NOT NULL,
  `item_id` int unsigned DEFAULT NULL COMMENT 'Nullable for non-catalog items',
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `qty` decimal(10,2) NOT NULL,
  `unit` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'pcs',
  `unit_price` decimal(15,2) DEFAULT '0.00',
  `amount` decimal(15,2) DEFAULT '0.00',
  `notes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_pr` (`pr_id`),
  KEY `fk_pri_item` (`item_id`),
  CONSTRAINT `fk_pri_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pri_pr` FOREIGN KEY (`pr_id`) REFERENCES `purchase_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pr_items`
--

LOCK TABLES `pr_items` WRITE;
/*!40000 ALTER TABLE `pr_items` DISABLE KEYS */;
INSERT INTO `pr_items` VALUES (1,1,NULL,'อุปกรณ์ทดสอบ',2.00,'pcs',500.00,1000.00,NULL);
/*!40000 ALTER TABLE `pr_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_orders`
--

DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `po_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pr_id` int unsigned DEFAULT NULL COMMENT 'Optional - from PR',
  `supplier_id` int unsigned NOT NULL,
  `po_type` enum('Goods','Service','Manpower') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Goods',
  `order_date` date NOT NULL,
  `delivery_date` date DEFAULT NULL,
  `status` enum('Draft','Submitted','Approved','Partially Received','Received','Cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
  `payment_terms` int DEFAULT '30' COMMENT 'Days',
  `subtotal` decimal(15,2) DEFAULT '0.00',
  `vat_rate` decimal(5,2) DEFAULT '7.00',
  `vat_amount` decimal(15,2) DEFAULT '0.00',
  `grand_total` decimal(15,2) DEFAULT '0.00',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `submitted_at` datetime DEFAULT NULL,
  `submitted_by` int unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` int unsigned DEFAULT NULL,
  `created_by` int unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `po_number` (`po_number`),
  KEY `idx_po_number` (`po_number`),
  KEY `idx_status` (`status`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_pr` (`pr_id`),
  CONSTRAINT `fk_po_pr` FOREIGN KEY (`pr_id`) REFERENCES `purchase_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_orders`
--

LOCK TABLES `purchase_orders` WRITE;
/*!40000 ALTER TABLE `purchase_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_requests`
--

DROP TABLE IF EXISTS `purchase_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pr_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `job_id` int unsigned DEFAULT NULL COMMENT 'Optional link to job',
  `requester_id` int unsigned NOT NULL,
  `purpose` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'วัตถุประสงค์',
  `required_date` date DEFAULT NULL COMMENT 'วันที่ต้องการ',
  `status` enum('Draft','Submitted','Approved','Rejected','Cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
  `total_amount` decimal(15,2) DEFAULT '0.00',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `submitted_at` datetime DEFAULT NULL,
  `submitted_by` int unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` int unsigned DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `rejected_by` int unsigned DEFAULT NULL,
  `reject_reason` text COLLATE utf8mb4_unicode_ci,
  `created_by` int unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pr_number` (`pr_number`),
  KEY `idx_pr_number` (`pr_number`),
  KEY `idx_status` (`status`),
  KEY `idx_requester` (`requester_id`),
  KEY `idx_job` (`job_id`),
  CONSTRAINT `fk_pr_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pr_requester` FOREIGN KEY (`requester_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_requests`
--

LOCK TABLES `purchase_requests` WRITE;
/*!40000 ALTER TABLE `purchase_requests` DISABLE KEYS */;
INSERT INTO `purchase_requests` VALUES (1,'PR-2026-00002',NULL,1,'ทดสอบสร้าง PR',NULL,'Approved',1000.00,NULL,'2026-01-16 15:17:03',1,'2026-01-16 15:22:45',1,NULL,NULL,NULL,1,'2026-01-16 01:44:58','2026-01-16 08:22:45');
/*!40000 ALTER TABLE `purchase_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permissions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int unsigned NOT NULL,
  `permission_id` int unsigned NOT NULL,
  `entity_status` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'NULL means all statuses',
  `is_granted` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_perm_status` (`role_id`,`permission_id`,`entity_status`),
  KEY `fk_rp_perm` (`permission_id`),
  CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT INTO `role_permissions` VALUES (1,1,1,NULL,1,'2026-01-15 14:11:47'),(2,1,2,NULL,1,'2026-01-15 14:11:47'),(3,1,3,NULL,1,'2026-01-15 14:11:47'),(4,1,4,NULL,1,'2026-01-15 14:11:47'),(5,1,5,NULL,1,'2026-01-15 14:11:47'),(6,1,6,NULL,1,'2026-01-15 14:11:47'),(7,1,7,NULL,1,'2026-01-15 14:11:47'),(8,1,8,NULL,1,'2026-01-15 14:11:47'),(9,1,19,NULL,1,'2026-01-15 14:11:47'),(10,1,12,NULL,1,'2026-01-15 14:11:47'),(11,1,13,NULL,1,'2026-01-15 14:11:47'),(12,1,14,NULL,1,'2026-01-15 14:11:47'),(13,1,15,NULL,1,'2026-01-15 14:11:47'),(14,1,9,NULL,1,'2026-01-15 14:11:47'),(15,1,10,NULL,1,'2026-01-15 14:11:47'),(16,1,11,NULL,1,'2026-01-15 14:11:47'),(17,1,16,NULL,1,'2026-01-15 14:11:47'),(18,1,17,NULL,1,'2026-01-15 14:11:47'),(19,1,18,NULL,1,'2026-01-15 14:11:47'),(32,8,1,NULL,1,'2026-01-15 14:11:47'),(33,8,2,NULL,1,'2026-01-15 14:11:47'),(34,8,3,NULL,1,'2026-01-15 14:11:47'),(35,8,4,NULL,1,'2026-01-15 14:11:47'),(36,8,5,NULL,1,'2026-01-15 14:11:47'),(37,8,6,NULL,1,'2026-01-15 14:11:47'),(38,8,7,NULL,1,'2026-01-15 14:11:47'),(39,8,8,NULL,1,'2026-01-15 14:11:47'),(40,8,19,NULL,1,'2026-01-15 14:11:47'),(41,8,12,NULL,1,'2026-01-15 14:11:47'),(42,8,13,NULL,1,'2026-01-15 14:11:47'),(43,8,14,NULL,1,'2026-01-15 14:11:47'),(44,8,15,NULL,1,'2026-01-15 14:11:47'),(45,8,9,NULL,1,'2026-01-15 14:11:47'),(46,8,10,NULL,1,'2026-01-15 14:11:47'),(47,8,11,NULL,1,'2026-01-15 14:11:47'),(48,8,16,NULL,1,'2026-01-15 14:11:47'),(49,8,17,NULL,1,'2026-01-15 14:11:47'),(50,8,18,NULL,1,'2026-01-15 14:11:47');
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ADM, SAL, PLN, PUR, HR, WH, ACC, MGR',
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'ADM','Admin','System administrator with full access','2026-01-15 14:11:47'),(2,'SAL','Sale','Sales team member','2026-01-15 14:11:47'),(3,'PLN','Planner','Job planning and resource allocation','2026-01-15 14:11:47'),(4,'PUR','Purchase','Procurement and purchasing','2026-01-15 14:11:47'),(5,'HR','HRM','Human resource management','2026-01-15 14:11:47'),(6,'WH','Warehouse','Warehouse and stock management','2026-01-15 14:11:47'),(7,'ACC','Accountant','Accounting and finance','2026-01-15 14:11:47'),(8,'MGR','Manager','Management with approval authority','2026-01-15 14:11:47');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `serials`
--

DROP TABLE IF EXISTS `serials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `serials` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `item_id` int unsigned NOT NULL,
  `serial_number` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('Available','Allocated','Dispatched','InUse','Returned','Damaged','Lost','Sold') COLLATE utf8mb4_unicode_ci DEFAULT 'Available',
  `condition_note` text COLLATE utf8mb4_unicode_ci,
  `purchase_date` date DEFAULT NULL,
  `purchase_price` decimal(15,2) DEFAULT NULL,
  `warranty_until` date DEFAULT NULL,
  `current_job_id` int unsigned DEFAULT NULL COMMENT 'If allocated/dispatched',
  `location` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Current location/warehouse',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_item_serial` (`item_id`,`serial_number`),
  KEY `idx_serial` (`serial_number`),
  KEY `idx_status` (`status`),
  KEY `idx_job` (`current_job_id`),
  CONSTRAINT `fk_serial_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_serial_job` FOREIGN KEY (`current_job_id`) REFERENCES `jobs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `serials`
--

LOCK TABLES `serials` WRITE;
/*!40000 ALTER TABLE `serials` DISABLE KEYS */;
INSERT INTO `serials` VALUES (1,1,'IPAD-2024-001','Available',NULL,NULL,NULL,NULL,NULL,'คลังหลัก',1,'2026-01-15 16:04:45','2026-01-15 16:04:45'),(2,1,'IPAD-2024-002','Available',NULL,NULL,NULL,NULL,NULL,'คลังหลัก',1,'2026-01-15 16:04:45','2026-01-15 16:04:45'),(3,1,'IPAD-2024-003','Available',NULL,NULL,NULL,NULL,NULL,'คลังหลัก',1,'2026-01-15 16:04:45','2026-01-15 16:04:45'),(4,2,'TAB-S9-001','Available',NULL,NULL,NULL,NULL,NULL,'คลังหลัก',1,'2026-01-15 16:04:45','2026-01-15 16:04:45'),(5,2,'TAB-S9-002','Available',NULL,NULL,NULL,NULL,NULL,'คลังหลัก',1,'2026-01-15 16:04:45','2026-01-15 16:04:45'),(6,3,'PROJ-EB-001','Available',NULL,NULL,NULL,NULL,NULL,'คลังหลัก',1,'2026-01-15 16:04:45','2026-01-15 16:04:45'),(7,4,'HIACE-001','Available',NULL,NULL,NULL,NULL,NULL,'ลานจอดรถ',1,'2026-01-15 16:04:45','2026-01-15 16:04:45'),(8,2001,'SN-TEST-3830','Dispatched',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-16 08:59:33','2026-01-16 08:59:33'),(9,2002,'SN-TEST-7288','Dispatched',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-16 09:01:12','2026-01-16 09:01:12');
/*!40000 ALTER TABLE `serials` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int unsigned NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload` text COLLATE utf8mb4_unicode_ci,
  `last_activity` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_last_activity` (`last_activity`),
  CONSTRAINT `fk_sess_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES ('03v3c0hhd8s1fa924kim46p7md',1,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0',NULL,'2026-01-15 14:35:14','2026-01-15 14:35:14'),('48imc5k3787a837oc314047kpi',1,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0',NULL,'2026-01-15 14:35:14','2026-01-15 14:35:14'),('5d583f83fda7bbdffcbe908bd0dfedee',1,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36',NULL,'2026-01-16 08:11:42','2026-01-16 08:11:42'),('ce10go70leklr6feeui3ge4knf',1,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0',NULL,'2026-01-16 00:26:38','2026-01-16 00:26:38'),('dou06e1ts4dt8f7dohag867amb',1,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0',NULL,'2026-01-16 08:40:11','2026-01-16 08:40:11'),('g2eupqf8442rf3ghuvo0v5n5bu',1,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36',NULL,'2026-01-16 00:46:48','2026-01-16 00:46:48'),('kcnojovm1acujnjbblvh8agmbj',1,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36',NULL,'2026-01-15 16:20:48','2026-01-15 16:20:48'),('uq0ejmf9pnh8kph8b5rea9dtg3',1,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36',NULL,'2026-01-16 00:50:16','2026-01-16 00:50:16'),('uts9qdiogvekqlo5vh22mv49fh',1,'::1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0',NULL,'2026-01-15 16:17:13','2026-01-15 16:17:13');
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sites`
--

DROP TABLE IF EXISTS `sites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sites` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int unsigned NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `contact_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customer` (`customer_id`),
  CONSTRAINT `fk_site_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sites`
--

LOCK TABLES `sites` WRITE;
/*!40000 ALTER TABLE `sites` DISABLE KEYS */;
INSERT INTO `sites` VALUES (1,1,'สำนักงานใหญ่','กรุงเทพฯ',NULL,NULL,NULL,NULL,1,'2026-01-15 15:08:14','2026-01-15 15:08:14'),(2,1,'สาขาเชียงใหม่','เชียงใหม่',NULL,NULL,NULL,NULL,1,'2026-01-15 15:08:14','2026-01-15 15:08:14'),(3,2,'โรงงาน','สมุทรปราการ',NULL,NULL,NULL,NULL,1,'2026-01-15 15:08:14','2026-01-15 15:08:14');
/*!40000 ALTER TABLE `sites` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suppliers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `tax_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_terms` int DEFAULT '30' COMMENT 'Days',
  `bank_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) DEFAULT '1',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_code` (`code`),
  KEY `idx_name` (`name`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suppliers`
--

LOCK TABLES `suppliers` WRITE;
/*!40000 ALTER TABLE `suppliers` DISABLE KEYS */;
INSERT INTO `suppliers` VALUES (1,'SUP001','บริษัท ABC ซัพพลาย จำกัด','คุณสมชาย','02-111-1111',NULL,NULL,NULL,30,NULL,NULL,NULL,1,1,'2026-01-15 16:04:45','2026-01-15 16:04:45'),(2,'SUP002','ห้างหุ้นส่วน XYZ เครื่องมือ','คุณสมหญิง','02-222-2222',NULL,NULL,NULL,45,NULL,NULL,NULL,1,1,'2026-01-15 16:04:45','2026-01-15 16:04:45');
/*!40000 ALTER TABLE `suppliers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_roles`
--

DROP TABLE IF EXISTS `user_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `role_id` int unsigned NOT NULL,
  `assigned_by` int unsigned DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_role` (`user_id`,`role_id`),
  KEY `fk_ur_role` (`role_id`),
  KEY `fk_ur_assigned` (`assigned_by`),
  CONSTRAINT `fk_ur_assigned` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_roles`
--

LOCK TABLES `user_roles` WRITE;
/*!40000 ALTER TABLE `user_roles` DISABLE KEYS */;
INSERT INTO `user_roles` VALUES (1,1,1,1,'2026-01-15 14:11:47'),(3,3,2,1,'2026-01-15 14:34:38'),(4,2,6,1,'2026-01-16 08:06:02');
/*!40000 ALTER TABLE `user_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_username` (`username`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin','admin@erp.local','$2y$12$sWX8RlylVoPZ.7egctNp5uuMf3jjMBMT3ekf5L.Ks9AzOH4CcDSZu','System Administrator',NULL,1,'2026-01-16 08:40:11','2026-01-15 14:11:47','2026-01-16 08:40:11'),(2,'wh','wh@4erp.com','$2y$10$msjtDnlonZYVaJ1mHsHmruPGkSmK23pXe8CfhvJvXbtdAV.VbeozS','WH A','wh123',1,'2026-01-15 14:35:02','2026-01-15 14:34:16','2026-01-16 08:06:02'),(3,'sal','sal@4erp.com','$2y$10$KLuLOyRZaiYdH3qCdTnqdOHWWjH516R75HzI6FjAJixg1p9Y0U44u','SALE',NULL,1,'2026-01-15 14:34:44','2026-01-15 14:34:38','2026-01-15 14:34:44');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-01-17  6:51:30

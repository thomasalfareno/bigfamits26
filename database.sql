-- database.sql — Big Family ITS 26 (Updated)

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+07:00";

CREATE TABLE IF NOT EXISTS `users` (
  `id_user` int(11) NOT NULL AUTO_INCREMENT,
  `nama` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `plain_password` varchar(255) DEFAULT NULL,
  `role` enum('user','operator','admin','superadmin') NOT NULL DEFAULT 'user',
  `foto_profil` varchar(255) DEFAULT NULL,
  `last_online` datetime DEFAULT NULL,
  `is_online` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `nama` (`nama`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `notes` (
  `id_note` int(11) NOT NULL AUTO_INCREMENT,
  `id_user` int(11) NOT NULL,
  `judul` varchar(255) DEFAULT 'Catatan Baru',
  `konten` longtext DEFAULT NULL,
  `tanggal_kegiatan` date DEFAULT NULL,
  `warna` varchar(20) DEFAULT '#58a6ff',
  `is_global` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_note`),
  KEY `idx_notes_user_updated` (`id_user`,`updated_at`),
  KEY `idx_notes_global_date_updated` (`is_global`,`tanggal_kegiatan`,`updated_at`),
  KEY `idx_notes_user_global_date` (`id_user`,`is_global`,`tanggal_kegiatan`),
  CONSTRAINT `fk_notes_user` FOREIGN KEY (`id_user`) REFERENCES `users` (`id_user`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `polls` (
  `id_poll` int(11) NOT NULL AUTO_INCREMENT,
  `pertanyaan` text NOT NULL,
  `tipe` enum('single','multiple') NOT NULL DEFAULT 'single',
  `id_user` int(11) NOT NULL,
  `media_path` varchar(255) DEFAULT NULL,
  `media_type` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_poll`),
  KEY `id_user` (`id_user`),
  CONSTRAINT `fk_poll_user` FOREIGN KEY (`id_user`) REFERENCES `users` (`id_user`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `msg` (
  `id_msg` int(11) NOT NULL AUTO_INCREMENT,
  `id_user` int(11) NOT NULL,
  `pesan` text DEFAULT NULL,
  `tipe` enum('text','image','file','poll') NOT NULL DEFAULT 'text',
  `file_path` varchar(255) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `pinned` tinyint(1) NOT NULL DEFAULT 0,
  `reply_to` int(11) DEFAULT NULL,
  `id_poll` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_msg`),
  KEY `id_user` (`id_user`),
  KEY `id_poll` (`id_poll`),
  KEY `idx_msg_pinned_id` (`pinned`,`id_msg`),
  CONSTRAINT `fk_msg_user` FOREIGN KEY (`id_user`) REFERENCES `users` (`id_user`) ON DELETE CASCADE,
  CONSTRAINT `fk_msg_poll` FOREIGN KEY (`id_poll`) REFERENCES `polls` (`id_poll`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `msg_deleted` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_msg` int(11) NOT NULL,
  `id_user` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_del` (`id_msg`,`id_user`),
  KEY `idx_msg_deleted_user_msg` (`id_user`,`id_msg`),
  CONSTRAINT `fk_del_msg` FOREIGN KEY (`id_msg`) REFERENCES `msg` (`id_msg`) ON DELETE CASCADE,
  CONSTRAINT `fk_del_user` FOREIGN KEY (`id_user`) REFERENCES `users` (`id_user`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `folders` (
  `id_folder` int(11) NOT NULL AUTO_INCREMENT,
  `nama` varchar(255) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `id_user` int(11) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_folder`),
  KEY `parent_id` (`parent_id`),
  KEY `id_user` (`id_user`),
  CONSTRAINT `fk_folder_user` FOREIGN KEY (`id_user`) REFERENCES `users` (`id_user`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `files` (
  `id_file` int(11) NOT NULL AUTO_INCREMENT,
  `nama` varchar(255) NOT NULL,
  `tipe` varchar(100) DEFAULT NULL,
  `ukuran` int(11) NOT NULL DEFAULT 0,
  `file_path` varchar(255) NOT NULL,
  `id_folder` int(11) DEFAULT NULL,
  `id_user` int(11) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_file`),
  KEY `id_folder` (`id_folder`),
  KEY `id_user` (`id_user`),
  CONSTRAINT `fk_file_folder` FOREIGN KEY (`id_folder`) REFERENCES `folders` (`id_folder`) ON DELETE SET NULL,
  CONSTRAINT `fk_file_user` FOREIGN KEY (`id_user`) REFERENCES `users` (`id_user`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `poll_options` (
  `id_option` int(11) NOT NULL AUTO_INCREMENT,
  `id_poll` int(11) NOT NULL,
  `teks` varchar(255) DEFAULT NULL,
  `gambar` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_option`),
  KEY `id_poll` (`id_poll`),
  CONSTRAINT `fk_option_poll` FOREIGN KEY (`id_poll`) REFERENCES `polls` (`id_poll`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `poll_votes` (
  `id_vote` int(11) NOT NULL AUTO_INCREMENT,
  `id_poll` int(11) NOT NULL,
  `id_option` int(11) NOT NULL,
  `id_user` int(11) NOT NULL,
  PRIMARY KEY (`id_vote`),
  UNIQUE KEY `unique_vote` (`id_poll`,`id_option`,`id_user`),
  KEY `idx_poll_votes_poll_user` (`id_poll`,`id_user`),
  CONSTRAINT `fk_vote_poll` FOREIGN KEY (`id_poll`) REFERENCES `polls` (`id_poll`) ON DELETE CASCADE,
  CONSTRAINT `fk_vote_option` FOREIGN KEY (`id_option`) REFERENCES `poll_options` (`id_option`) ON DELETE CASCADE,
  CONSTRAINT `fk_vote_user` FOREIGN KEY (`id_user`) REFERENCES `users` (`id_user`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `site_title` varchar(255) DEFAULT 'BIG FAMILY ITS 26',
  `site_description` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `settings` (`id`, `site_title`, `site_description`) VALUES (1, 'BIG FAMILY ITS 26', 'Platform Kolaborasi Mahasiswa ITS 26');

-- Initial Admin and Super Admin credentials are entered manually in installer/index.php.

COMMIT;

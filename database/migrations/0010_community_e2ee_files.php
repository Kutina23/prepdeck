<?php
// End-to-end encrypted file sharing in the community. The browser does all the cryptography:
//   community_keys   each member's ECDH P-256 public key, and the private key wrapped (AES-GCM) with a key derived from
//                    their password (PBKDF2). The server never sees a usable private key.
//   community_files  the encrypted file is stored on disk; this row holds only ciphertext metadata (encrypted name and
//                    type), the file key wrapped for the sender + recipient pair, and which public keys were used.
return function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS `community_keys` (
      `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` int(10) UNSIGNED NOT NULL,
      `public_key` varchar(255) NOT NULL,
      `wrapped_private` text NOT NULL,
      `salt` varchar(64) NOT NULL,
      `iterations` int(10) UNSIGNED NOT NULL,
      `active` tinyint(1) NOT NULL DEFAULT 1,
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`), KEY `user_active` (`user_id`,`active`),
      CONSTRAINT `community_keys_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS `community_files` (
      `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      `sender_id` int(10) UNSIGNED NOT NULL,
      `recipient_id` int(10) UNSIGNED NOT NULL,
      `storage_name` char(40) NOT NULL,
      `size` int(10) UNSIGNED NOT NULL,
      `meta` text NOT NULL,
      `file_iv` varchar(32) NOT NULL,
      `wrapped_key` varchar(255) NOT NULL,
      `sender_key_id` int(10) UNSIGNED NOT NULL,
      `recipient_key_id` int(10) UNSIGNED NOT NULL,
      `sha256` char(64) NOT NULL,
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`), UNIQUE KEY `storage_name` (`storage_name`), KEY `sender` (`sender_id`,`created_at`), KEY `recipient` (`recipient_id`),
      CONSTRAINT `community_files_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
      CONSTRAINT `community_files_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    mig_add_column('messages', 'kind', "enum('text','file') NOT NULL DEFAULT 'text'");
    mig_add_column('messages', 'file_id', 'bigint(20) UNSIGNED DEFAULT NULL');
};

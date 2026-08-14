-- Tabela para registrar o relacionamento many-to-many entre users e profiles
CREATE TABLE IF NOT EXISTS `users_profiles` (
    `idx` int NOT NULL AUTO_INCREMENT,
    `created_at` datetime NOT NULL,
    `created_by` int NOT NULL,
    `modified_at` datetime DEFAULT NULL,
    `modified_by` int DEFAULT NULL,
    `removed_at` datetime DEFAULT NULL,
    `removed_by` int DEFAULT NULL,
    `active` enum('yes', 'no') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'yes',
    `users_id` int NOT NULL,
    `profiles_id` int NOT NULL,
    PRIMARY KEY (`idx`),
    KEY `idx_users_id` (`users_id`),
    KEY `idx_profiles_id` (`profiles_id`),
    KEY `idx_active` (`active`),
    UNIQUE KEY `uq_users_profiles` (`users_id`, `profiles_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Relação many-to-many entre users e profiles';

-- INSERT IGNORE: com UNIQUE (users_id, profiles_id), re-execuções não duplicam
-- a atribuição de perfil do admin.
INSERT IGNORE INTO
    `users_profiles` (
        `created_at`,
        `created_by`,
        `active`,
        `users_id`,
        `profiles_id`
    )
VALUES (NOW(), '0', 'yes', 1, 1);

-- ROLLBACK MANUAL (o runner nao tem suporte a down; execute a mao se preciso):
--   DROP TABLE `users_profiles`;
-- DESTRUTIVO: apaga todo vinculo usuario-perfil; ninguem passa no gate adm
-- depois disso.

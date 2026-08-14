-- Tabelas de permissao por perfil — fatia 1 do desenho em plans/016-DESIGN.md.
-- Nada em runtime le estas tabelas ainda: os guards das rotas continuam sendo
-- auth_controller::check_login() puro ate o plano 021.
--
-- Idempotencia: CREATE TABLE IF NOT EXISTS + INSERT IGNORE apoiado nas chaves
-- UNIQUE. O runner NAO torna DDL atomica (comentario em MigrationRunner.php:240),
-- entao reexecucao precisa ser no-op por conta propria.
-- Nenhum ';' aparece dentro de literal, entao o splitter ingenuo do runner
-- (explode(';')) continua valido.

-- Vocabulario fechado de permissoes do manager. Slugs no formato
-- "<dominio>.<acao>" (ex.: usuarios.ler, perfis.escrever).
CREATE TABLE IF NOT EXISTS `capabilities` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME NOT NULL,
    `created_by` INT NOT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') NOT NULL DEFAULT 'yes',
    `slug` VARCHAR(255) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`idx`),
    UNIQUE KEY `uq_capabilities_slug` (`slug`),
    KEY `idx_capabilities_active` (`active`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Vocabulario fechado de permissoes do manager';

-- Vinculo M2M perfil <-> capacidade, mesmo formato de users_profiles (004).
CREATE TABLE IF NOT EXISTS `profiles_capabilities` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME NOT NULL,
    `created_by` INT NOT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') NOT NULL DEFAULT 'yes',
    `profiles_id` INT NOT NULL,
    `capabilities_id` INT NOT NULL,
    PRIMARY KEY (`idx`),
    KEY `idx_profiles_id` (`profiles_id`),
    KEY `idx_capabilities_id` (`capabilities_id`),
    KEY `idx_active` (`active`),
    UNIQUE KEY `uq_profiles_capabilities` (`profiles_id`, `capabilities_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Relacao many-to-many entre profiles e capabilities';

-- Seed: as 6 capacidades do inventario de rotas do manager.
INSERT IGNORE INTO `capabilities` (`created_at`, `created_by`, `active`, `slug`, `name`)
VALUES
    (NOW(), 0, 'yes', 'usuarios.ler', 'Ver usuarios'),
    (NOW(), 0, 'yes', 'usuarios.escrever', 'Criar/editar/remover usuarios'),
    (NOW(), 0, 'yes', 'usuarios.atribuir_perfil', 'Alterar o(s) perfil(is) de um usuario'),
    (NOW(), 0, 'yes', 'emails.ler', 'Ver emails'),
    (NOW(), 0, 'yes', 'perfis.ler', 'Ver perfis'),
    (NOW(), 0, 'yes', 'perfis.escrever', 'Criar/editar/remover perfis');

-- Seed: o perfil Administrador (slug 'admin', criado em 003) recebe TODAS as
-- capacidades. Sem isto, quando o gate passar a bloquear (plano 022) o operador
-- se tranca fora do painel.
INSERT IGNORE INTO `profiles_capabilities` (`created_at`, `created_by`, `active`, `profiles_id`, `capabilities_id`)
SELECT NOW(), 0, 'yes', p.idx, c.idx
FROM `profiles` p
CROSS JOIN `capabilities` c
WHERE p.slug = 'admin' AND p.active = 'yes' AND c.active = 'yes';

-- ROLLBACK MANUAL (o runner nao tem suporte a down; execute a mao se preciso):
--   DROP TABLE `profiles_capabilities`;
--   DROP TABLE `capabilities`;
-- DESTRUTIVO: apaga todo vinculo perfil-capacidade. Reverter e seguro enquanto
-- os guards ainda forem check_login() puro (ate o plano 021) ou can() em modo
-- log (plano 021); depois do plano 022 ninguem passa em guard nenhum.

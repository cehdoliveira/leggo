-- Tabela de rascunho de import de usuarios por CSV — fatia 1 do desenho em
-- plans/018-DESIGN.md (Step 6, versao minima: so name+mail, sem perfis).
--
-- `dados` guarda o array classificado (criar/atualizar/erro) do CSV parseado,
-- serializado como JSON — nao ha schema relacional para "linha do arquivo",
-- entao o rascunho inteiro fica num unico LONGTEXT ate a confirmacao.
-- `imported_at`/`imported_by` NULL = rascunho ainda nao aplicado; controla a
-- idempotencia da confirmacao (SELECT ... WHERE imported_at IS NULL FOR UPDATE).
--
-- Idempotencia: CREATE TABLE IF NOT EXISTS. Sem seed — tabela nasce vazia.
-- Nenhum ';' aparece dentro de literal, entao o splitter ingenuo do runner
-- (explode(';')) continua valido.
CREATE TABLE IF NOT EXISTS `users_imports` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME NOT NULL,
    `created_by` INT NOT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') NOT NULL DEFAULT 'yes',
    `name` VARCHAR(255) DEFAULT NULL,
    `dados` LONGTEXT NOT NULL,
    `imported_at` DATETIME DEFAULT NULL,
    `imported_by` INT DEFAULT NULL,
    PRIMARY KEY (`idx`),
    KEY `idx_users_imports_active_created_at` (`active`, `created_at`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Rascunhos de import de usuarios por CSV (fatia 1 do plans/018-DESIGN.md)';

-- ROLLBACK MANUAL (o runner nao tem suporte a down; execute a mao se preciso):
--   DROP TABLE `users_imports`;
-- DESTRUTIVO: apaga todo historico e rascunho de import, inclusive os ja
-- aplicados (imported_at preenchido). Os `users` ja criados por uma
-- confirmacao anterior NAO sao afetados — so o registro de auditoria do
-- import em si.

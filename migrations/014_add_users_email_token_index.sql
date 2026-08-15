-- Adiciona indice em users.email_token: coluna consultada por WHERE
-- email_token = ? em display_set_password()/set_password() (ja existente) e,
-- desde o plano 031, tambem em display_reset_password()/reset_password() —
-- sem indice, cada clique num link de definir-senha ou redefinir-senha faz
-- table scan completo em users.
--
-- Idempotencia: mesmo padrao de migrations/010_add_users_indexes.sql — o
-- ADD INDEX fica atras de uma checagem em information_schema.STATISTICS.
-- Nenhum ';' aparece dentro de literais, entao o splitter ingenuo do runner
-- (explode(';')) continua valido.

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'idx_users_email_token'
);
SET @ddl := IF(
    @idx_exists = 0,
    'ALTER TABLE `users` ADD INDEX `idx_users_email_token` (`email_token`)',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ROLLBACK MANUAL (o runner nao tem suporte a down; execute a mao se preciso):
--   ALTER TABLE `users` DROP INDEX `idx_users_email_token`;
-- Reverter e seguro: nenhum dado e afetado, so o plano de execucao das
-- consultas por email_token volta a fazer table scan.

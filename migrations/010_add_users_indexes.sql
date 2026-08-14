-- Adiciona indices em users para as colunas usadas no WHERE padrao
-- (active = 'yes', aplicado em toda chamada de users_controller::display())
-- e nas colunas ordenaveis pela listagem (users_controller::ORDER_ALLOWED).
-- Sem indice, tanto o filtro quanto qualquer clique de ordenacao fazem table
-- scan + filesort. `mail` ja tem indice via UNIQUE KEY mail_UNIQUE (migration
-- 002), entao nao repetimos aqui.
--
-- Idempotencia: cada ADD INDEX fica atras de uma checagem em
-- information_schema.STATISTICS, mesmo padrao de
-- migrations/006_add_unique_constraints.sql. Nenhum ';' aparece dentro de
-- literais, entao o splitter ingenuo do runner (explode(';')) continua
-- valido.

-- 1) users.active
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'idx_users_active'
);
SET @ddl := IF(
    @idx_exists = 0,
    'ALTER TABLE `users` ADD INDEX `idx_users_active` (`active`)',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) users.name
SET @idx_exists2 := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'idx_users_name'
);
SET @ddl2 := IF(
    @idx_exists2 = 0,
    'ALTER TABLE `users` ADD INDEX `idx_users_name` (`name`)',
    'DO 0'
);
PREPARE stmt2 FROM @ddl2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 3) users.login
SET @idx_exists3 := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'idx_users_login'
);
SET @ddl3 := IF(
    @idx_exists3 = 0,
    'ALTER TABLE `users` ADD INDEX `idx_users_login` (`login`)',
    'DO 0'
);
PREPARE stmt3 FROM @ddl3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

-- 4) users.created_at
SET @idx_exists4 := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'idx_users_created_at'
);
SET @ddl4 := IF(
    @idx_exists4 = 0,
    'ALTER TABLE `users` ADD INDEX `idx_users_created_at` (`created_at`)',
    'DO 0'
);
PREPARE stmt4 FROM @ddl4;
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;

-- 5) users.last_login
SET @idx_exists5 := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'idx_users_last_login'
);
SET @ddl5 := IF(
    @idx_exists5 = 0,
    'ALTER TABLE `users` ADD INDEX `idx_users_last_login` (`last_login`)',
    'DO 0'
);
PREPARE stmt5 FROM @ddl5;
EXECUTE stmt5;
DEALLOCATE PREPARE stmt5;

-- ROLLBACK MANUAL (o runner nao tem suporte a down; execute a mao se preciso):
--   ALTER TABLE `users`  DROP INDEX `idx_users_active`;
--   ALTER TABLE `users`  DROP INDEX `idx_users_name`;
--   ALTER TABLE `users`  DROP INDEX `idx_users_login`;
--   ALTER TABLE `users`  DROP INDEX `idx_users_created_at`;
--   ALTER TABLE `users`  DROP INDEX `idx_users_last_login`;
-- Reverter e seguro: nenhum dado e afetado, so o plano de execucao das
-- consultas.

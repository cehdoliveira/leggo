-- Adiciona identificador publico (slug) a users, para URLs de
-- usuarios/006-padrao-controller-usuarios usarem em vez do idx sequencial.
--
-- Idempotencia: cada DDL fica atras de uma checagem em information_schema,
-- montando o comando via SQL dinamico apenas quando ainda nao aplicado — a
-- mesma tecnica de 006_add_unique_constraints.sql. Nenhum ';' aparece dentro
-- de literais, entao o splitter ingenuo do runner (explode(';')) continua
-- valido.

-- 1) ADD COLUMN slug
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'slug'
);
SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `slug` VARCHAR(32) NULL DEFAULT NULL',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) Backfill das linhas existentes sem slug. Formato: 10 caracteres
-- aleatorios + data de criacao (aammdd) — mesmo formato que o controller gera
-- na criacao (generate_key(10) . date("ymd")).
UPDATE `users`
SET `slug` = CONCAT(SUBSTRING(MD5(CONCAT(idx, RAND())), 1, 10), DATE_FORMAT(COALESCE(created_at, NOW()), '%y%m%d'))
WHERE `slug` IS NULL OR `slug` = '';

-- 3) ADD UNIQUE em slug
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'slug_UNIQUE'
);
SET @ddl2 := IF(
    @idx_exists = 0,
    'ALTER TABLE `users` ADD UNIQUE `slug_UNIQUE` (`slug`)',
    'DO 0'
);
PREPARE stmt2 FROM @ddl2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

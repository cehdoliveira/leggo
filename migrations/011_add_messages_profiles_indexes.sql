-- Adiciona indices nas colunas que as listagens do manager filtram e ordenam.
--
-- 1) messages(active, sent_at): a consulta de /emails roda em toda visita como
--    WHERE active = 'yes' ORDER BY sent_at DESC LIMIT ?, ?. Sem indice e table
--    scan + filesort numa tabela que cresce a cada e-mail enviado. Composto e
--    nessa ordem para cobrir filtro e ordenacao no mesmo indice.
--    to_mail e subject NAO sao indexados de proposito: os filtros da tela usam
--    LIKE '%valor%' (indice nao ajuda) e a ordenacao por essas colunas e um
--    clique opcional, nao o caminho quente.
-- 2) profiles.adm e 3) profiles.parent: filtros de /perfis
--    (profiles_controller::filter()). Fecha a pendencia registrada no PR #86.
--    A tabela tem poucas linhas hoje; o custo de escrever agora e menor que o de
--    lembrar depois.
--
-- Idempotencia: cada ADD INDEX fica atras de uma checagem em
-- information_schema.STATISTICS, mesmo padrao de
-- migrations/010_add_users_indexes.sql. Nenhum ';' aparece dentro de literais,
-- entao o splitter ingenuo do runner (explode(';')) continua valido.

-- 1) messages(active, sent_at)
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'messages'
      AND INDEX_NAME = 'idx_messages_active_sent_at'
);
SET @ddl := IF(
    @idx_exists = 0,
    'ALTER TABLE `messages` ADD INDEX `idx_messages_active_sent_at` (`active`, `sent_at`)',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) profiles.adm
SET @idx_exists2 := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'profiles'
      AND INDEX_NAME = 'idx_profiles_adm'
);
SET @ddl2 := IF(
    @idx_exists2 = 0,
    'ALTER TABLE `profiles` ADD INDEX `idx_profiles_adm` (`adm`)',
    'DO 0'
);
PREPARE stmt2 FROM @ddl2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 3) profiles.parent
SET @idx_exists3 := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'profiles'
      AND INDEX_NAME = 'idx_profiles_parent'
);
SET @ddl3 := IF(
    @idx_exists3 = 0,
    'ALTER TABLE `profiles` ADD INDEX `idx_profiles_parent` (`parent`)',
    'DO 0'
);
PREPARE stmt3 FROM @ddl3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

-- ROLLBACK MANUAL (o runner nao tem suporte a down; execute a mao se preciso):
--   ALTER TABLE `messages`  DROP INDEX `idx_messages_active_sent_at`;
--   ALTER TABLE `profiles`  DROP INDEX `idx_profiles_adm`;
--   ALTER TABLE `profiles`  DROP INDEX `idx_profiles_parent`;
-- Reverter e seguro: nenhum dado e afetado, so o plano de execucao das consultas.

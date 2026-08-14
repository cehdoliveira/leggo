-- Rotaciona o admin seedado caso a senha default nunca tenha sido trocada.
-- O hash abaixo é o valor original commitado em migrations/002 (antes da
-- edição que o removeu) — usado apenas como guard de idempotência: só afeta
-- instalações que ainda não trocaram a senha default.
UPDATE users
   SET password = '!disabled!', enabled = 'no', modified_at = NOW()
 WHERE login = 'admin'
   AND password = '$2y$10$ie5ckp.oFWWVU5UP3.P7tOY/XIGxKvuU5sZK7rwl0.88KXsBWuuG2';

-- ROLLBACK MANUAL (o runner nao tem suporte a down; execute a mao se preciso):
--   IRREVERSIVEL por SQL: a senha rotacionada nao volta ao valor anterior,
--   o hash antigo e perdido no momento em que esta migration roda.
--   O fluxo normal de definir-senha exige email_token + email_token_expires_at
--   validos (auth_controller.php:214) e esse token so e gerado pela acao
--   reset-senha, que exige estar logado como admin no manager - ou seja, nao
--   resolve se este for o unico admin. Para reabilitar manualmente:
--     UPDATE users SET email_token = '<token-aleatorio-escolhido-por-voce>',
--            email_token_expires_at = DATE_ADD(NOW(), INTERVAL 72 HOUR),
--            enabled = 'no'
--      WHERE login = 'admin';
--   Depois acesse /definir-senha/<mesmo-token-aleatorio> e defina a senha
--   nova; o proprio fluxo marca enabled = 'yes' ao salvar.
-- Risco: nenhum comando SQL sozinho restaura o acesso; e preciso gerar um
-- token valido e passar pela tela de definir-senha.

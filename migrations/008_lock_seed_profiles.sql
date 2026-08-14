-- Perfis de sistema não podem ser editados nem soft-deletados pela UI de
-- /perfis (guard de `editabled` no profiles_controller). O seed da 003
-- criou ambos com 'yes' por engano: soft-deletar `admin` remove o gate adm
-- de quem depende dele; soft-deletar/editar `user` quebra o cadastro público
-- (DEFAULT_USER_PROFILE_ID). Idempotente: re-execução é no-op.
UPDATE profiles
   SET editabled = 'no', modified_at = NOW()
 WHERE slug IN ('admin', 'user')
   AND editabled <> 'no';

-- ROLLBACK MANUAL (o runner nao tem suporte a down; execute a mao se preciso):
--   UPDATE profiles SET editabled = 'yes' WHERE slug IN ('admin', 'user');
-- Risco: reabre a possibilidade de soft-deletar o perfil Administrador pela
-- tela /perfis.

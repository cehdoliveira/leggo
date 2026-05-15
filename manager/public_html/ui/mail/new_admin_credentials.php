<?php

/**
 * Template de e-mail: Boas-vindas e dados de acesso para novo administrador
 *
 * Variáveis esperadas (via extract($data) ou atribuição direta):
 *   @var string $name      Nome do novo administrador
 *   @var string $login     Login de acesso (e-mail ou nome de usuário)
 *   @var string $loginLink URL da página de login do painel administrativo
 */
$name      = isset($name)      ? (string) $name      : '';
$login     = isset($login)     ? (string) $login     : '';
$loginLink = isset($loginLink) ? (string) $loginLink : '#';
$baseUrl   = defined('cFrontend') ? rtrim(constant('cFrontend'), '/') . '/' : 'http://localhost/';
$logoUrl   = $baseUrl . 'assets/img/logo.png';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Seus dados de acesso — GarimpAções Manager</title>
</head>

<body style="margin:0;padding:0;background-color:#060b11;font-family:Arial,Helvetica,sans-serif;">

    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#060b11;">
        <tr>
            <td align="center" style="padding:48px 16px;">

                <!-- Container principal -->
                <table border="0" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;background-color:#0d1520;border-radius:10px;overflow:hidden;border:1px solid rgba(0,212,170,0.15);">

                    <!-- Cabeçalho -->
                    <tr>
                        <td align="center" style="background-color:#060b11;padding:36px 40px;">
                            <table border="0" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center">
                                        <img src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                            alt="GarimpAções" width="56" height="56"
                                            style="display:block;border:0;border-radius:8px;">
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding-top:14px;">
                                        <span style="font-size:26px;font-weight:700;color:#00d4aa;letter-spacing:2px;font-family:Arial,Helvetica,sans-serif;">
                                            GARIMPAÇÕES
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding-top:4px;">
                                        <span style="font-size:12px;color:#7a8ba0;letter-spacing:1px;font-family:Arial,Helvetica,sans-serif;text-transform:uppercase;">
                                            Painel Administrativo
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Faixa accent -->
                    <tr>
                        <td style="background-color:#00d4aa;height:2px;font-size:0;">&nbsp;</td>
                    </tr>

                    <!-- Corpo -->
                    <tr>
                        <td style="padding:44px 48px 36px;background-color:#0d1520;">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">

                                <!-- Título -->
                                <tr>
                                    <td style="font-size:22px;font-weight:700;color:#f1f5f9;padding-bottom:20px;font-family:Arial,Helvetica,sans-serif;">
                                        Bem-vindo ao painel administrativo!
                                    </td>
                                </tr>

                                <!-- Saudação -->
                                <tr>
                                    <td style="font-size:15px;color:#e2e8f0;line-height:1.75;padding-bottom:16px;font-family:Arial,Helvetica,sans-serif;">
                                        Olá, <strong style="color:#00d4aa;"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></strong>!
                                    </td>
                                </tr>

                                <!-- Texto principal -->
                                <tr>
                                    <td style="font-size:15px;color:#b0bec5;line-height:1.75;padding-bottom:32px;font-family:Arial,Helvetica,sans-serif;">
                                        Seu cadastro no <strong style="color:#e2e8f0;">GarimpAções Manager</strong> foi realizado com sucesso.
                                        Abaixo estão seus dados de acesso ao painel administrativo. Guarde-os em local seguro.
                                    </td>
                                </tr>

                                <!-- Card de credenciais -->
                                <tr>
                                    <td style="padding-bottom:32px;">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#142030;border-radius:8px;border:1px solid rgba(255,255,255,0.06);">
                                            <tr>
                                                <td style="padding:28px 32px;">
                                                    <table border="0" cellpadding="0" cellspacing="0" width="100%">

                                                        <!-- Label Login -->
                                                        <tr>
                                                            <td style="font-size:11px;font-weight:700;color:#7a8ba0;letter-spacing:1px;text-transform:uppercase;padding-bottom:4px;font-family:Arial,Helvetica,sans-serif;">
                                                                LOGIN / E-MAIL
                                                            </td>
                                                        </tr>
                                                        <!-- Valor Login -->
                                                        <tr>
                                                            <td style="font-size:16px;font-weight:700;color:#00d4aa;padding-bottom:20px;font-family:Arial,Helvetica,sans-serif;border-bottom:1px solid rgba(255,255,255,0.06);">
                                                                <?php echo htmlspecialchars($login, ENT_QUOTES, 'UTF-8'); ?>
                                                            </td>
                                                        </tr>

                                                        <!-- Label Senha -->
                                                        <tr>
                                                            <td style="font-size:11px;font-weight:700;color:#7a8ba0;letter-spacing:1px;text-transform:uppercase;padding-top:20px;padding-bottom:4px;font-family:Arial,Helvetica,sans-serif;">
                                                                SENHA TEMPORÁRIA
                                                            </td>
                                                        </tr>
                                                        <!-- Aviso senha -->
                                                        <tr>
                                                            <td style="font-size:14px;color:#b0bec5;font-family:Arial,Helvetica,sans-serif;">
                                                                Uma senha temporária foi gerada automaticamente. Por segurança, altere-a
                                                                imediatamente após o primeiro acesso nas configurações do seu perfil.
                                                            </td>
                                                        </tr>

                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <!-- Dica de segurança -->
                                <tr>
                                    <td style="background-color:#142030;border-left:3px solid #3b82f6;padding:14px 18px;border-radius:0 4px 4px 0;margin-bottom:28px;">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td style="font-size:13px;color:#7a8ba0;line-height:1.65;font-family:Arial,Helvetica,sans-serif;">
                                                    🔒 <strong style="color:#b0bec5;">Segurança:</strong> Nunca compartilhe suas credenciais de acesso.
                                                    Recomendamos alterar a senha imediatamente após o primeiro login.
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <!-- Botão CTA -->
                                <tr>
                                    <td align="center" style="padding-top:28px;padding-bottom:8px;">
                                        <table border="0" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td align="center" style="background-color:#00d4aa;border-radius:8px;">
                                                    <a href="<?php echo htmlspecialchars($loginLink, ENT_QUOTES, 'UTF-8'); ?>"
                                                        target="_blank"
                                                        style="display:inline-block;padding:15px 40px;font-size:15px;font-weight:700;color:#060b11;text-decoration:none;font-family:Arial,Helvetica,sans-serif;letter-spacing:0.5px;">
                                                        Acessar o painel administrativo
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                            </table>
                        </td>
                    </tr>

                    <!-- Rodapé -->
                    <tr>
                        <td style="background-color:#060b11;padding:24px 48px;border-top:1px solid rgba(255,255,255,0.06);">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td style="font-size:12px;color:#7a8ba0;line-height:1.65;font-family:Arial,Helvetica,sans-serif;text-align:center;">
                                        Este é um e-mail automático. Por favor, não responda a esta mensagem.<br>
                                        Se você não solicitou este acesso, entre em contato imediatamente com o administrador do sistema.<br><br>
                                        © <?php echo date('Y'); ?> GarimpAções — Todos os direitos reservados.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                </table>
                <!-- /Container principal -->

            </td>
        </tr>
    </table>

</body>

</html>

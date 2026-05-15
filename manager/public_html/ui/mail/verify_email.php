<?php

/**
 * Template de e-mail: Confirmação de cadastro / verificação de e-mail
 *
 * Variáveis esperadas (via extract($data) ou atribuição direta):
 *   @var string $name       Nome do destinatário
 *   @var string $verifyLink URL de verificação de e-mail
 */
$name       = isset($name)       ? (string) $name       : '';
$verifyLink = isset($verifyLink) ? (string) $verifyLink : '#';
$canonicalSite = defined('SITE_CANONICAL_URL') && constant('SITE_CANONICAL_URL') !== ''
    ? rtrim(constant('SITE_CANONICAL_URL'), '/')
    : 'https://leggo.com.br';
$logoUrl = $canonicalSite . '/assets/img/logo.png';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Confirme seu e-mail — GarimpAções</title>
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
                                            Curadoria Quantitativa de Ações
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
                                        Confirme seu endereço de e-mail
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
                                        Seu cadastro no <strong style="color:#e2e8f0;">GarimpAções</strong> foi recebido com sucesso.
                                        Para ativar sua conta e começar a explorar o ranking completo de ações,
                                        confirme seu endereço de e-mail clicando no botão abaixo.
                                    </td>
                                </tr>

                                <!-- Botão CTA -->
                                <tr>
                                    <td align="center" style="padding-bottom:36px;">
                                        <table border="0" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td align="center" style="background-color:#00d4aa;border-radius:8px;">
                                                    <a href="<?php echo htmlspecialchars($verifyLink, ENT_QUOTES, 'UTF-8'); ?>"
                                                        target="_blank"
                                                        style="display:inline-block;padding:15px 40px;font-size:15px;font-weight:700;color:#060b11;text-decoration:none;font-family:Arial,Helvetica,sans-serif;letter-spacing:0.5px;">
                                                        Confirmar meu e-mail
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <!-- Validade e disclaimer -->
                                <tr>
                                    <td style="background-color:#142030;border-left:3px solid #00d4aa;padding:14px 18px;border-radius:0 4px 4px 0;">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td style="font-size:13px;color:#7a8ba0;line-height:1.65;font-family:Arial,Helvetica,sans-serif;">
                                                    ⏳ Este link é válido por <strong style="color:#e2e8f0;">72 horas</strong> a partir do recebimento deste e-mail.<br>
                                                    Se você não realizou este cadastro, basta ignorar esta mensagem — sua conta não será ativada.
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <!-- Link em texto -->
                                <tr>
                                    <td style="padding-top:24px;font-size:12px;color:#7a8ba0;word-break:break-all;font-family:Arial,Helvetica,sans-serif;">
                                        Se o botão não funcionar, copie e cole o endereço abaixo no seu navegador:<br>
                                        <span style="color:#3b82f6;"><?php echo htmlspecialchars($verifyLink, ENT_QUOTES, 'UTF-8'); ?></span>
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

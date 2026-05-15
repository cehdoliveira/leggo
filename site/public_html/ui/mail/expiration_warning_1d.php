<?php

/**
 * Template de e-mail: Aviso de expiração de assinatura — último dia (amanhã)
 *
 * Variáveis esperadas (via extract($data) ou atribuição direta):
 *   @var string $name             Nome do destinatário
 *   @var string $planLabel        Nome legível do plano (ex.: "Mensal", "Anual")
 *   @var string $expiresFormatted Data de expiração formatada (ex.: "24/04/2026")
 *   @var string $renewLink        URL da página de renovação
 */
$name             = isset($name)             ? (string) $name             : '';
$planLabel        = isset($planLabel)        ? (string) $planLabel        : 'Assinatura';
$expiresFormatted = isset($expiresFormatted) ? (string) $expiresFormatted : '';
$renewLink        = isset($renewLink)        ? (string) $renewLink        : '#';
$baseUrl          = defined('cFrontend') ? rtrim(constant('cFrontend'), '/') . '/' : 'http://localhost/';
$logoUrl          = $baseUrl . 'assets/img/logo.png';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Último dia — Sua assinatura expira amanhã — GarimpAções</title>
</head>

<body style="margin:0;padding:0;background-color:#060b11;font-family:Arial,Helvetica,sans-serif;">

    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#060b11;">
        <tr>
            <td align="center" style="padding:48px 16px;">

                <!-- Container principal -->
                <table border="0" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;background-color:#0d1520;border-radius:10px;overflow:hidden;border:1px solid rgba(239,68,68,0.25);">

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

                    <!-- Faixa vermelha de urgência -->
                    <tr>
                        <td style="background-color:#ef4444;height:2px;font-size:0;">&nbsp;</td>
                    </tr>

                    <!-- Banner de urgência -->
                    <tr>
                        <td align="center" style="background-color:#142030;padding:18px 48px;border-bottom:1px solid rgba(239,68,68,0.15);">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td align="center" style="font-size:13px;font-weight:700;color:#ef4444;font-family:Arial,Helvetica,sans-serif;letter-spacing:0.5px;">
                                        🚨 &nbsp;URGENTE: SUA ASSINATURA EXPIRA AMANHÃ
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Corpo -->
                    <tr>
                        <td style="padding:40px 48px 36px;background-color:#0d1520;">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">

                                <!-- Saudação -->
                                <tr>
                                    <td style="font-size:15px;color:#e2e8f0;line-height:1.75;padding-bottom:16px;font-family:Arial,Helvetica,sans-serif;">
                                        Olá, <strong style="color:#00d4aa;"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></strong>!
                                    </td>
                                </tr>

                                <!-- Texto principal -->
                                <tr>
                                    <td style="font-size:15px;color:#b0bec5;line-height:1.75;padding-bottom:24px;font-family:Arial,Helvetica,sans-serif;">
                                        Este é o <strong style="color:#ef4444;">último aviso</strong> antes que sua assinatura
                                        <strong style="color:#e2e8f0;"><?php echo htmlspecialchars($planLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                                        expire. A partir de amanhã
                                        (<strong style="color:#e2e8f0;"><?php echo htmlspecialchars($expiresFormatted, ENT_QUOTES, 'UTF-8'); ?></strong>),
                                        seu acesso ao conteúdo exclusivo será encerrado.
                                    </td>
                                </tr>

                                <!-- Card de contagem regressiva -->
                                <tr>
                                    <td style="padding-bottom:28px;">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#142030;border-radius:8px;border:1px solid rgba(239,68,68,0.20);">
                                            <tr>
                                                <td align="center" style="padding:24px;">
                                                    <table border="0" cellpadding="0" cellspacing="0">
                                                        <tr>
                                                            <td align="center" style="font-size:48px;font-weight:700;color:#ef4444;line-height:1;font-family:Arial,Helvetica,sans-serif;">
                                                                1
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td align="center" style="font-size:13px;color:#7a8ba0;font-family:Arial,Helvetica,sans-serif;padding-top:4px;">
                                                                DIA RESTANTE
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <!-- O que você vai perder -->
                                <tr>
                                    <td style="font-size:15px;color:#b0bec5;line-height:1.75;padding-bottom:8px;font-family:Arial,Helvetica,sans-serif;">
                                        Sem renovação, você perde acesso imediato a:
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding-bottom:32px;">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td style="font-size:14px;color:#ef4444;line-height:1.9;font-family:Arial,Helvetica,sans-serif;padding-left:8px;">
                                                    &nbsp;✗&nbsp; Ranking completo de ações (Top 30)<br>
                                                    &nbsp;✗&nbsp; Painel de movimentação e persistência<br>
                                                    &nbsp;✗&nbsp; Histórico de sessões anteriores
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <!-- Botão CTA — urgência -->
                                <tr>
                                    <td align="center" style="padding-bottom:20px;">
                                        <table border="0" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td align="center" style="background-color:#00d4aa;border-radius:8px;">
                                                    <a href="<?php echo htmlspecialchars($renewLink, ENT_QUOTES, 'UTF-8'); ?>"
                                                        target="_blank"
                                                        style="display:inline-block;padding:15px 40px;font-size:15px;font-weight:700;color:#060b11;text-decoration:none;font-family:Arial,Helvetica,sans-serif;letter-spacing:0.5px;">
                                                        Renovar agora — antes que expire
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <!-- Nota de urgência -->
                                <tr>
                                    <td align="center" style="font-size:13px;color:#7a8ba0;line-height:1.65;font-family:Arial,Helvetica,sans-serif;">
                                        Este é o seu último aviso. Após a expiração, será necessário reativar o plano manualmente.
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
                                        © <?php echo date('Y'); ?> GarimpAções — Todos os direitos reservados.<br><br>
                                        <span style="font-size:11px;color:#4a5568;">As informações disponibilizadas pelo GarimpAções têm caráter exclusivamente informativo e educacional. Não constituem recomendação de investimento nem serviço regulado pela CVM. A decisão de investir é sempre do usuário.</span>
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

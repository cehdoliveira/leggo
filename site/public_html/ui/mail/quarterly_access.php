<?php

/**
 * Template de e-mail: Acesso ao Acompanhamento Trimestral liberado
 *
 * Variáveis esperadas (via extract($data) ou atribuição direta):
 *   @var string $name       Nome do destinatário (pode ser vazio)
 *   @var string $accessLink URL da página do Acompanhamento Trimestral
 */
$name       = isset($name)       ? (string) $name       : '';
$accessLink = isset($accessLink) ? (string) $accessLink : '#';
$baseUrl    = defined('cFrontend') ? rtrim(constant('cFrontend'), '/') . '/' : 'http://localhost/';
$logoUrl    = $baseUrl . 'assets/img/logo.png';
$salut      = $name !== '' ? ', ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Acesso Trimestral liberado — GarimpAções</title>
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

                    <!-- Banner de confirmação -->
                    <tr>
                        <td align="center" style="background-color:#142030;padding:18px 48px;border-bottom:1px solid rgba(0,212,170,0.15);">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td align="center" style="font-size:13px;font-weight:700;color:#00d4aa;font-family:Arial,Helvetica,sans-serif;letter-spacing:0.5px;">
                                        ✅ &nbsp;ACESSO LIBERADO COM SUCESSO
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Corpo -->
                    <tr>
                        <td style="padding:40px 48px 36px;background-color:#0d1520;">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">

                                <!-- Título -->
                                <tr>
                                    <td style="font-size:22px;font-weight:700;color:#f1f5f9;padding-bottom:20px;font-family:Arial,Helvetica,sans-serif;">
                                        Acompanhamento Trimestral desbloqueado!
                                    </td>
                                </tr>

                                <!-- Saudação e texto principal -->
                                <tr>
                                    <td style="font-size:15px;color:#b0bec5;line-height:1.75;padding-bottom:24px;font-family:Arial,Helvetica,sans-serif;">
                                        Olá<?php echo $salut; ?>! Seu acesso ao
                                        <strong style="color:#e2e8f0;">Acompanhamento Trimestral</strong>
                                        foi confirmado e já está disponível na sua conta.
                                    </td>
                                </tr>

                                <!-- Card de funcionalidades -->
                                <tr>
                                    <td style="padding-bottom:32px;">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#142030;border-radius:8px;border:1px solid rgba(255,255,255,0.06);">
                                            <tr>
                                                <td style="padding:24px 28px;">
                                                    <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                                        <tr>
                                                            <td style="font-size:14px;font-weight:700;color:#e2e8f0;padding-bottom:14px;font-family:Arial,Helvetica,sans-serif;">
                                                                O que você acessa agora:
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td style="font-size:14px;color:#b0bec5;line-height:2;font-family:Arial,Helvetica,sans-serif;">
                                                                &nbsp;<span style="color:#00d4aa;">✓</span>&nbsp; <strong style="color:#e2e8f0;">Registro de aportes mensais</strong> e dividendos recebidos<br>
                                                                &nbsp;<span style="color:#00d4aa;">✓</span>&nbsp; <strong style="color:#e2e8f0;">Evolução trimestral</strong> da carteira por período<br>
                                                                &nbsp;<span style="color:#00d4aa;">✓</span>&nbsp; <strong style="color:#e2e8f0;">Distribuição do ranking</strong> nas posições ao longo do trimestre<br>
                                                                &nbsp;<span style="color:#00d4aa;">✓</span>&nbsp; <strong style="color:#e2e8f0;">Persistência de ativos</strong> no ranking ao longo do tempo
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <!-- Aviso regulatório -->
                                <tr>
                                    <td style="background-color:#142030;border-left:3px solid #3b82f6;padding:14px 18px;border-radius:0 4px 4px 0;margin-bottom:32px;">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td style="font-size:12px;color:#7a8ba0;line-height:1.65;font-family:Arial,Helvetica,sans-serif;">
                                                    <strong style="color:#b0bec5;">Aviso importante:</strong> O Acompanhamento Trimestral é uma ferramenta de organização pessoal de dados fornecidos pelo próprio usuário, com caráter exclusivamente informativo e educacional. Nenhum conteúdo desta plataforma constitui recomendação de investimento, consultoria de valores mobiliários, análise de valores mobiliários ou qualquer serviço regulado pela CVM. A decisão de investir é sempre e exclusivamente do usuário.
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
                                                    <a href="<?php echo htmlspecialchars($accessLink, ENT_QUOTES, 'UTF-8'); ?>"
                                                        target="_blank"
                                                        style="display:inline-block;padding:15px 40px;font-size:15px;font-weight:700;color:#060b11;text-decoration:none;font-family:Arial,Helvetica,sans-serif;letter-spacing:0.5px;">
                                                        Acessar o Acompanhamento Trimestral
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

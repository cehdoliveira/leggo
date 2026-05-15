<?php

/**
 * Template de e-mail: Novo Ranking Publicado
 *
 * Variáveis esperadas:
 *   @var string $name           Nome do destinatário (fallback: 'Assinante')
 *   @var string $rankingName    Nome da sessão do ranking (ex.: "Ranking 05 — 27/04/2026")
 *   @var string $rankingUrl     URL canônica da página de ranking no site
 *   @var string $rankingDate    Data formatada (ex.: "27/04/2026")
 *   @var string $unsubscribeUrl URL de cancelamento com token HMAC
 */
$name           = isset($name)           ? (string) $name           : 'Assinante';
$rankingName    = isset($rankingName)    ? (string) $rankingName    : 'Novo Ranking';
$rankingUrl     = isset($rankingUrl)     ? (string) $rankingUrl     : '#';
$rankingDate    = isset($rankingDate)    ? (string) $rankingDate    : date('d/m/Y');
$unsubscribeUrl = isset($unsubscribeUrl) ? (string) $unsubscribeUrl : '#';

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
    <title>Novo Ranking Publicado — GarimpAções</title>
</head>

<body style="margin:0;padding:0;background-color:#060b11;font-family:Arial,Helvetica,sans-serif;">

    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#060b11;">
        <tr>
            <td align="center" style="padding:48px 16px;">

                <!-- Container principal -->
                <table border="0" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;background-color:#0d1520;border-radius:10px;overflow:hidden;border:1px solid rgba(0,212,170,0.25);">

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

                    <!-- Faixa verde -->
                    <tr>
                        <td style="background-color:#00d4aa;height:2px;font-size:0;">&nbsp;</td>
                    </tr>

                    <!-- Banner de destaque -->
                    <tr>
                        <td align="center" style="background-color:#0a1a14;padding:18px 48px;border-bottom:1px solid rgba(0,212,170,0.15);">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td align="center" style="font-size:13px;font-weight:700;color:#00d4aa;font-family:Arial,Helvetica,sans-serif;letter-spacing:0.5px;">
                                        &#128202;&nbsp; NOVO RANKING DISPONÍVEL — <?php echo htmlspecialchars($rankingDate, ENT_QUOTES, 'UTF-8'); ?>
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
                                        Um novo ranking foi publicado no GarimpAções.<br><br>
                                        A curadoria quantitativa foi atualizada com os dados mais recentes. Acesse o painel para conferir as ações selecionadas pelo nosso modelo, os filtros aplicados e os movimentos de entrada e saída em relação ao ranking anterior.
                                    </td>
                                </tr>

                                <!-- Card informativo -->
                                <tr>
                                    <td style="padding-bottom:32px;">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#142030;border-radius:8px;border:1px solid rgba(0,212,170,0.12);">
                                            <tr>
                                                <td style="padding:20px 24px;">
                                                    <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                                        <tr>
                                                            <td style="font-size:13px;color:#7a8ba0;font-family:Arial,Helvetica,sans-serif;padding-bottom:6px;">
                                                                Sessão publicada
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td style="font-size:16px;font-weight:700;color:#00d4aa;font-family:Arial,Helvetica,sans-serif;">
                                                                <?php echo htmlspecialchars($rankingName, ENT_QUOTES, 'UTF-8'); ?>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <!-- Botão CTA -->
                                <tr>
                                    <td align="center" style="padding-bottom:20px;">
                                        <table border="0" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td align="center" style="background-color:#00d4aa;border-radius:8px;">
                                                    <a href="<?php echo htmlspecialchars($rankingUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                                        target="_blank"
                                                        style="display:inline-block;padding:15px 40px;font-size:15px;font-weight:700;color:#060b11;text-decoration:none;font-family:Arial,Helvetica,sans-serif;letter-spacing:0.5px;">
                                                        Ver o Ranking Completo
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                            </table>
                        </td>
                    </tr>

                    <!-- Aviso CVM -->
                    <tr>
                        <td style="background-color:#0a1118;padding:20px 48px;border-top:1px solid rgba(255,255,255,0.06);">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td style="font-size:11px;color:#4a5568;line-height:1.65;font-family:Arial,Helvetica,sans-serif;">
                                        <strong style="color:#5a6880;">AVISO IMPORTANTE:</strong> As informações disponibilizadas pelo GarimpAções têm caráter exclusivamente informativo e educacional, baseado em critérios quantitativos públicos. Não constituem recomendação de compra ou venda de valores mobiliários, nem serviço de análise de valores mobiliários regulado pela CVM (Resolução CVM n.º 20/2021). Rentabilidade passada não é garantia de resultados futuros. Antes de investir, avalie seu perfil de risco e, se necessário, consulte um profissional habilitado. A decisão de investir é sempre de responsabilidade exclusiva do investidor.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Rodapé -->
                    <tr>
                        <td style="background-color:#060b11;padding:20px 48px;border-top:1px solid rgba(255,255,255,0.04);">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td style="font-size:12px;color:#7a8ba0;line-height:1.65;font-family:Arial,Helvetica,sans-serif;text-align:center;">
                                        Este é um e-mail automático. Por favor, não responda a esta mensagem.<br>
                                        © <?php echo date('Y'); ?> GarimpAções — Todos os direitos reservados.<br><br>
                                        <a href="<?php echo htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                            style="color:#4a5568;font-size:11px;text-decoration:underline;">
                                            Cancelar notificações de novo ranking
                                        </a>
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

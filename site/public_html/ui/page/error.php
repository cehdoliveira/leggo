<?php
/**
 * Tela de erro. Recebe $errorCode, $errorTitle e $errorMessage de
 * render_error_page() (CommonFunctions.php).
 */
$errorCode    = (int)($errorCode ?? 500);
$errorTitle   = $errorTitle ?? 'Algo deu errado';
$errorMessage = $errorMessage ?? 'Tente novamente.';
?>

<div class="container error-page">
    <div class="error-code"><?php echo $errorCode; ?></div>
    <h1 class="error-title"><?php echo htmlspecialchars($errorTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="error-message"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></p>
    <div class="error-actions">
        <a class="btn btn-accent" href="<?php echo htmlspecialchars($GLOBALS['home_url'], ENT_QUOTES, 'UTF-8'); ?>">
            <i class="bi bi-house me-1" aria-hidden="true"></i> Ir para o início
        </a>
    </div>
</div>

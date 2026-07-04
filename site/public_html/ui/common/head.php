<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#060b11">
    <title><?php echo htmlspecialchars(constant("cTitle")); ?></title>

    <link rel="icon" type="image/svg+xml" href="<?php printf("%s%s", constant("cFrontend"), "assets/img/favicon.svg"); ?>">
    <link rel="shortcut icon" href="<?php printf("%s%s", constant("cFrontend"), "assets/img/favicon.svg"); ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.5/dist/sweetalert2.min.css">

    <link rel="stylesheet" href="<?php printf("%s%s", constant("cFrontend"), "assets/css/main.css"); ?>">

    <!-- Apply theme before render to prevent flash -->
    <script nonce="<?php echo htmlspecialchars($GLOBALS['cspNonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    (function () {
        var saved = localStorage.getItem('theme');
        var theme = saved
            ? saved
            : (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
        document.documentElement.setAttribute('data-theme', theme);
    })();
    </script>

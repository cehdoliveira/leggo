<?php
class site_controller
{
    private const CACHE_TTL_RANKING    = 300;
    private const CACHE_TTL_PREVIEW    = 300;
    private const CACHE_TTL_PERSISTENCE = 600;
    private const CACHE_TTL_SITUACAO   = 300;
    public function home($info)
    {
        $isLoggedIn  = auth_controller::check_login();
        $session     = null;
        $results     = [];
        $sessions    = [];
        $prevSession = null;
        $persistence = [];
        $comparativo = ['saidas' => [], 'entradas' => []];
        $isAdmin     = false;
        $dbError     = false;
        $showWelcome = false;
        $subscriptionExpired = false;
        $subscriptionInfo    = null;

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        $hasQuarterlyAccess = false;

        try {
            if ($isLoggedIn) {
                $userId = (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0);
                $subCheck = check_subscription($userId);
                $subscriptionInfo = $subCheck['subscription'];
                $hasQuarterlyAccess = check_quarterly_access($userId);

                if (!$subCheck['valid'] && $subCheck['expired']) {
                    $subscriptionExpired = true;
                }

                $sessionId = max(0, intval($_GET['session_id'] ?? 0));
                [
                    $session,
                    $results,
                    $sessions,
                    $prevSession,
                    $persistence,
                    $comparativo,
                    $isAdmin
                ] = $this->_load_mode2_data($sessionId);

                // T5 — welcome banner on first Mode 2 visit
                if (!isset($_SESSION['shown_welcome'])) {
                    $showWelcome = true;
                    $_SESSION['shown_welcome'] = true;
                }
            } else {
                [$session, $results] = $this->_load_mode1_data();
            }
        } catch (\Throwable $e) {
            $dbError = true;
        }

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/home.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function ranking_guide($info)
    {
        $isLoggedIn = auth_controller::check_login();
        $hasSubscription = false;

        if ($isLoggedIn) {
            $userId  = (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0);
            $subCheck = check_subscription($userId);
            $hasSubscription = $subCheck['valid'];
        }

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/ranking_guide.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function terms($info)
    {
        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/terms.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function privacy($info)
    {
        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/privacy.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    private function _is_admin(): bool
    {
        foreach (($_SESSION[constant("cAppKey")]["credential"]["profiles_attach"] ?? []) as $profile) {
            if (($profile["adm"] ?? 'no') === 'yes') return true;
        }
        return false;
    }

    public function remove_session($info)
    {
        if (!auth_controller::check_login()) {
            http_response_code(403);
            exit();
        }

        if (!$this->_is_admin()) {
            http_response_code(403);
            exit();
        }

        validate_csrf($info["post"]["_csrf_token"] ?? null, $GLOBALS["home_url"]);

        $sessionId = max(0, intval($info["post"]["session_id"] ?? 0));
        if ($sessionId <= 0) {
            basic_redir($GLOBALS["home_url"]);
            exit();
        }

        try {
            $sm = new screener_sessions_model();
            $sm->set_filter([" idx = '$sessionId' "]);
            $sm->remove();
        } catch (\Throwable $e) {
            $_SESSION["messages_app"]["error"] = ["Erro ao remover ranking. Tente novamente."];
            basic_redir($GLOBALS["home_url"]);
            exit();
        }

        $redis = $GLOBALS['redis'] ?? null;
        if ($redis) {
            try {
                $redis->delete('ranking:' . $sessionId);
                $redis->delete('ranking_preview:' . $sessionId);
                $redis->delete('situacao:' . $sessionId);
                $redis->delete('persistence');
            } catch (Exception $e) { /* fail-open */
            }
        }

        $_SESSION["messages_app"]["success"] = ["Ranking removido com sucesso."];
        basic_redir($GLOBALS["home_url"]);
        exit();
    }

    public function unsubscribe_ranking($info): void
    {
        $uid   = (int)($info['get']['uid'] ?? 0);
        $token = (string)($info['get']['token'] ?? '');

        if ($uid <= 0 || $token === '') {
            http_response_code(400);
            echo "Link inválido.";
            return;
        }

        $redis   = $GLOBALS['redis'] ?? null;
        $rateKey = "unsub_ranking:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($redis && check_rate_limit($redis, $rateKey, 10)) {
            http_response_code(429);
            echo "Muitas tentativas. Aguarde alguns minutos.";
            return;
        }
        if ($redis) {
            increment_rate_limit($redis, $rateKey, 60);
        }

        if (!defined('UNSUBSCRIBE_HMAC_SECRET') || constant('UNSUBSCRIBE_HMAC_SECRET') === '') {
            http_response_code(500);
            error_log("Ranking unsubscribe: UNSUBSCRIBE_HMAC_SECRET não configurado");
            echo "Erro de configuração. Contate o suporte.";
            return;
        }

        $expected = hash_hmac('sha256', "ranking_unsub:{$uid}", constant('UNSUBSCRIBE_HMAC_SECRET'));
        if (!hash_equals($expected, $token)) {
            http_response_code(403);
            echo "Token inválido ou expirado.";
            return;
        }

        $m = new users_model();
        $safeUid = $m->get_con()->real_escape_string((string)$uid);
        $m->set_filter([" idx = '$safeUid' ", " active = 'yes' "]);
        $m->populate(['marketing_email_opt_in' => 'no']);
        $m->save();

        include(constant('cRootServer') . 'ui/common/head.php');
        echo '<div class="container py-5 text-center">'
            . '<h2 class="mb-3">Notificações canceladas</h2>'
            . '<p class="text-muted">Você não receberá mais e-mails de novo ranking.<br>'
            . 'Para outras comunicações (renovação de assinatura), entre em contato pelo suporte.</p>'
            . '<a href="/" class="btn btn-outline-primary mt-3">Voltar ao site</a>'
            . '</div>';
        include(constant('cRootServer') . 'ui/common/foot.php');
    }

    private function _load_mode1_data(): array
    {
        $sm = new screener_sessions_model();
        $sm->set_filter([" active = 'yes' ", " status = 'SUCESSO' "]);
        $sm->set_order([" created_at DESC "]);
        $sm->set_paginate([1]);
        $sm->load_data();
        $session = $sm->data[0] ?? null;

        if (!$session) {
            return [null, []];
        }

        $redis           = $GLOBALS['redis'] ?? null;
        $previewCacheKey = 'ranking_preview:' . $session['idx'];
        $rawResults      = null;

        if ($redis) {
            try {
                $rawResults = $redis->get($previewCacheKey);
            } catch (Exception $e) { /* fail-open */
            }
        }

        if ($rawResults === null) {
            $safeId = intval($session['idx']);
            $rm = new screener_results_model();
            $rm->set_filter([" active = 'yes' ", " screener_sessions_id = '$safeId' "]);
            $rm->set_order([" rank_position ASC "]);
            $rm->set_paginate([20]);
            $rm->load_data();
            $rawResults = $rm->data ?? [];

            if ($redis && !empty($rawResults)) {
                try {
                    $redis->set($previewCacheKey, $rawResults, self::CACHE_TTL_PREVIEW);
                } catch (Exception $e) { /* fail-open */
                }
            }
        }

        // Cache stores real data; randomization happens per-request (not cached)
        return [$session, $this->_apply_preview_randomization($rawResults)];
    }

    private function _apply_preview_randomization(array $rows): array
    {
        if (empty($rows)) return $rows;

        foreach ($rows as &$row) {
            if ((int)$row['rank_position'] <= 5) {
                $row['_visible'] = true;
            } else {
                $row['_visible'] = false;
                $row = $this->_scramble_preview_row($row);
            }
        }
        unset($row);

        return $rows;
    }

    private function _scramble_preview_row(array $row): array
    {
        static $tickers = [
            'WXYZ3',
            'ABCD4',
            'EFGH3',
            'IJKL4',
            'MNOP3',
            'QRST4',
            'UVWX3',
            'YZAB4',
            'CDEF3',
            'GHIJ4',
            'KLMN3',
            'OPQR4',
            'STUV3',
            'WXAB4',
            'CDYZ11',
        ];
        static $empresas = [
            'Corporação Alfa S.A.',
            'Holdings Beta Ltda',
            'Grupo Gama S.A.',
            'Indústrias Delta S.A.',
            'Conglomerado Épsilon',
            'Companhia Zeta S.A.',
            'Empresa Eta Participações',
            'Theta Investimentos S.A.',
            'Iota Capital Ltda',
            'Kappa Recursos S.A.',
            'Lambda Holdings',
            'Mu Investimentos S.A.',
        ];

        $row['ticker']      = $tickers[array_rand($tickers)];
        $row['empresa']     = $empresas[array_rand($empresas)];
        $row['ev_ebit']     = round(mt_rand(400, 2500) / 100, 2);
        $row['margem_ebit'] = round(mt_rand(150, 4500) / 100, 2);
        $row['volume']      = mt_rand(2000000, 400000000);

        return $row;
    }

    private function _load_mode2_data(int $requestedSessionId): array
    {
        $redis = $GLOBALS['redis'] ?? null;

        $sm = new screener_sessions_model();
        $sm->set_filter([" active = 'yes' ", " status = 'SUCESSO' "]);
        $sm->set_order([" created_at DESC "]);
        $sm->set_paginate([50]);
        $sm->load_data();
        $sessions = $sm->data ?? [];

        if (empty($sessions)) {
            return [null, [], [], null, [], ['saidas' => [], 'entradas' => []], false];
        }

        $session = null;
        if ($requestedSessionId > 0) {
            foreach ($sessions as $s) {
                if ((int)$s['idx'] === $requestedSessionId) {
                    $session = $s;
                    break;
                }
            }
        }
        if (!$session) {
            $session = $sessions[0];
        }
        $sessionId = (int)$session['idx'];

        $cacheKey = 'ranking:' . $sessionId;
        $results  = null;
        if ($redis) {
            try {
                $results = $redis->get($cacheKey);
            } catch (Exception $e) { /* fail-open */
            }
        }

        if ($results === null) {
            $rm = new screener_results_model();
            $rm->set_filter([" active = 'yes' ", " screener_sessions_id = '$sessionId' "]);
            $rm->set_order([" rank_position ASC "]);
            $rm->set_paginate([100]);
            $rm->load_data();
            $results = $rm->data ?? [];

            if ($redis && !empty($results)) {
                try {
                    $redis->set($cacheKey, $results, self::CACHE_TTL_RANKING);
                } catch (Exception $e) { /* fail-open */
                }
            }
        }

        $situacaoMap = $this->_get_situacao_map($sessionId);
        foreach ($results as &$row) {
            $row['situacao'] = $situacaoMap[$row['ticker']] ?? 'INDISPONÍVEL';
        }
        unset($row);

        $prevSession  = null;
        $foundCurrent = false;
        foreach ($sessions as $s) {
            if ($foundCurrent) {
                $prevSession = $s;
                break;
            }
            if ((int)$s['idx'] === $sessionId) {
                $foundCurrent = true;
            }
        }

        $comparativo = ['saidas' => [], 'entradas' => []];
        if ($prevSession) {
            $comparativo = $this->_get_comparison($sessionId, (int)$prevSession['idx'], $results);
        }

        $persistence = $this->_get_persistence($redis);

        return [$session, $results, $sessions, $prevSession, $persistence, $comparativo, $this->_is_admin()];
    }

    private function _get_situacao_map(int $sessionId): array
    {
        $redis    = $GLOBALS['redis'] ?? null;
        $cacheKey = 'situacao:' . $sessionId;

        if ($redis) {
            try {
                $cached = $redis->get($cacheKey);
                if ($cached !== null) return $cached;
            } catch (\Throwable $e) { /* fail-open */
            }
        }

        $con    = (new screener_sessions_model())->get_con();
        $stmt   = $con->query(
            "SELECT ticker, status_text, is_removed FROM screener_scraping_logs WHERE screener_sessions_id = $sessionId AND active = 'yes'"
        );
        $rows   = $con->results($stmt);
        $map    = [];
        foreach ($rows as $row) {
            $ticker = $row['ticker'];
            if ((int)($row['is_removed'] ?? 0) === 1) {
                $map[$ticker] = 'REMOVIDA';
            } elseif (!empty($row['status_text'])) {
                $map[$ticker] = htmlspecialchars((string)$row['status_text'], ENT_QUOTES, 'UTF-8');
            } else {
                $map[$ticker] = 'FASE OPERACIONAL';
            }
        }

        if ($redis && !empty($map)) {
            try {
                $redis->set($cacheKey, $map, self::CACHE_TTL_SITUACAO);
            } catch (\Throwable $e) { /* fail-open */
            }
        }

        return $map;
    }

    // Raw SQL needed: COUNT(*) + GROUP BY with empresa + GROUP_CONCAT for session names
    private function _get_persistence(?object $redis): array
    {
        if ($redis) {
            try {
                $cached = $redis->get('persistence');
                if ($cached !== null) return $cached;
            } catch (Exception $e) { /* fail-open */
            }
        }

        $con = (new screener_sessions_model())->get_con();

        $totalStmt    = $con->query("SELECT COUNT(*) as c FROM screener_sessions WHERE status = 'SUCESSO' AND active = 'yes'");
        $totalRows    = $con->results($totalStmt);
        $totalSessions = (int)($totalRows[0]['c'] ?? 0);

        $stmt = $con->query("
            SELECT r.ticker, r.empresa, COUNT(*) AS cnt,
                   GROUP_CONCAT(s.name ORDER BY s.created_at SEPARATOR ', ') AS session_names
            FROM screener_results r
            JOIN screener_sessions s ON r.screener_sessions_id = s.idx
            WHERE s.status = 'SUCESSO' AND s.active = 'yes' AND r.active = 'yes'
            GROUP BY r.ticker, r.empresa
            ORDER BY cnt DESC, r.ticker ASC
            LIMIT 20
        ");
        $rows = $con->results($stmt);

        foreach ($rows as &$row) {
            $row['total'] = $totalSessions;
        }
        unset($row);

        if ($redis && !empty($rows)) {
            try {
                $redis->set('persistence', $rows, self::CACHE_TTL_PERSISTENCE);
            } catch (Exception $e) { /* fail-open */
            }
        }

        return $rows;
    }

    private function _get_comparison(int $sessionId, int $prevSessionId, array $currentResults): array
    {
        $rm = new screener_results_model();
        $rm->set_filter([" active = 'yes' ", " screener_sessions_id = '$prevSessionId' "]);
        $rm->set_order([" rank_position ASC "]);
        $rm->set_paginate([100]);
        $rm->load_data();
        $prevResults = $rm->data ?? [];

        $currentMap = [];
        foreach ($currentResults as $r) {
            $currentMap[$r['ticker']] = $r;
        }
        $prevMap = [];
        foreach ($prevResults as $r) {
            $prevMap[$r['ticker']] = $r;
        }

        $saidas = [];
        foreach ($prevMap as $ticker => $row) {
            if (!isset($currentMap[$ticker])) {
                $saidas[] = [
                    'ticker'        => $ticker,
                    'empresa'       => $row['empresa'],
                    'prev_position' => $row['rank_position'],
                ];
            }
        }

        $entradas = [];
        foreach ($currentMap as $ticker => $row) {
            if (!isset($prevMap[$ticker])) {
                $entradas[] = [
                    'ticker'        => $ticker,
                    'empresa'       => $row['empresa'],
                    'curr_position' => $row['rank_position'],
                ];
            }
        }

        return ['saidas' => $saidas, 'entradas' => $entradas];
    }
}

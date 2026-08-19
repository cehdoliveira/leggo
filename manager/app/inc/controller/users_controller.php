<?php

/**
 * Padrão display / form / save / remove (ver plans/005-*.md, o exemplar).
 * Esta tela exercita as duas partes do padrão que profiles não alcança:
 * vínculos many-to-many (users_profiles, via attach()/save_attach()) e
 * identificador público gerado na criação (slug nasce no save(), não existe
 * campo de slug no formulário).
 *
 * `password` nunca é lido de $_POST nem exposto em nenhum set_field aqui —
 * a senha é definida pelo próprio usuário via definir-senha/<token> ou
 * redefinida pela ação reset-senha (em action()).
 *
 * action() é a exceção deliberada ao padrão: hospeda o que não é CRUD de um
 * registro (export-csv) e mudanças de estado sem formulário próprio
 * (ativar/inativar/reset-senha). Se uma delas ganhar tela própria, migra
 * para save()/remove() e sai daqui.
 */
class users_controller
{
    /** Colunas que a listagem aceita ordenar. ORDER BY não aceita bind. */
    private const ORDER_ALLOWED = ['name', 'mail', 'login', 'created_at', 'last_login'];

    /** Piso de itens por página: pedidos abaixo disso são elevados. */
    private const PER_PAGE_MIN = 20;

    /** Teto de itens por página: pedidos acima disso são rebaixados (evita dump da tabela via ?paginate=). */
    private const PER_PAGE_MAX = 200;

    /** Colunas da listagem — nunca password. */
    private const LIST_FIELDS = [" idx ", " name ", " mail ", " login ", " slug ", " active ", " enabled ", " created_at ", " last_login ", " email_verified_at "];

    /** Colunas do formulário — nunca password. */
    private const FORM_FIELDS = [" idx ", " name ", " mail ", " login ", " phone ", " slug ", " active ", " enabled ", " created_at "];

    /**
     * Traduz os parâmetros de busca em três coleções que caminham juntas:
     *   $done   — o que o usuário escolheu (repovoa a tela e remonta a URL de volta)
     *   $filter — as condições WHERE, com ? nos valores
     *   $params — os valores bindados, na mesma ordem dos ?
     *
     * @return array{0: array<string, string>, 1: array<string>, 2: array<mixed>}
     */
    private function filter(array $info): array
    {
        $get    = $info['get'] ?? [];
        $done   = [];
        $filter = [" active = 'yes' "];
        $params = [];

        $name = trim((string)($get['filter_name'] ?? ''));
        if ($name !== '') {
            $done['filter_name'] = $name;
            $filter[]            = " ( name LIKE ? OR mail LIKE ? ) ";
            $like                = '%' . addcslashes($name, '\\%_') . '%';
            $params[]            = $like;
            $params[]            = $like;
        }

        $enabled = trim((string)($get['filter_enabled'] ?? ''));
        if ($enabled !== '' && in_array($enabled, ['yes', 'no'], true)) {
            $done['filter_enabled'] = $enabled;
            $filter[]               = " enabled = ? ";
            $params[]               = $enabled;
        }

        // Pertinência a um conjunto: o usuário entra se tiver vínculo ATIVO com
        // o perfil escolhido. Não é atributo da própria linha.
        $profile = (int)($get['filter_profile'] ?? 0);
        if ($profile > 0) {
            $done['filter_profile'] = (string)$profile;
            $filter[]               = " idx IN ( SELECT users_id FROM users_profiles WHERE active = 'yes' AND profiles_id = ? ) ";
            $params[]               = $profile;
        }

        return [$done, $filter, $params];
    }

    /** Mapa idx=>nome de perfis ativos, para o <select> de filtro e para o formulário. */
    private function available_profiles(): array
    {
        return (new profiles_model())->data4select("idx", [" active = 'yes' "], "name");
    }

    /**
     * Troca null por '' recursivamente. toUtf8() (CommonFunctions.php) exige
     * string; sem isto, json_response() quebra em qualquer linha com uma
     * coluna nula (last_login, email_verified_at, ou as colunas de
     * profiles_attach vindas do SELECT * de attach()).
     */
    private function json_safe(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_array($value)) {
                $row[$key] = $this->json_safe($value);
            } elseif ($value === null) {
                $row[$key] = '';
            }
        }

        return $row;
    }

    public function display(array $info): void
    {
        global $users_url, $newuser_url, $user_url, $removeuser_url;

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        $format   = resolve_format($info);
        $paginate = min(self::PER_PAGE_MAX, max(self::PER_PAGE_MIN, (int)($info['get']['paginate'] ?? 0)));
        $offset   = (int)($info['sr'] ?? 0);

        [$ordenationColumn, $ordenationDirection] = resolve_ordenation(
            $info['get']['ordenation'] ?? null,
            self::ORDER_ALLOWED,
            'created_at',
            'desc'
        );

        [$done, $filter, $params] = $this->filter($info);

        try {
            $model = new users_model();

            // Contadores agregados dos cards do topo — independem dos filtros de
            // busca (sempre refletem a base inteira). Query coberta por
            // DashboardCountsTest.
            $countStmt = $model->select(
                [" COUNT(*) AS total ", " SUM(active = 'yes') AS ativos ", " SUM(active = 'yes' AND enabled = 'yes') AS habilitados "],
                "WHERE idx > 0"
            );
            $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'ativos' => 0, 'habilitados' => 0];

            $total_users   = (int)$counts['total'];
            $active_users  = (int)$counts['ativos'];
            $enabled_users = (int)$counts['habilitados'];
            $removed_users = $total_users - $active_users;

            $model->set_field(self::LIST_FIELDS);
            $model->set_filter($filter, $params);
            $model->set_order([" {$ordenationColumn} {$ordenationDirection} "]);
            // Paginação vale para .html e .json — nenhum dos dois devolve a
            // tabela inteira de uma vez.
            $model->set_paginate([$offset, $paginate]);

            // return_data() chama load_data(true) por baixo — recordset vira o
            // total SEM o LIMIT, a contagem da paginação. Não escreva um COUNT à mão.
            [$total, $users] = $model->return_data();
            $total = (int)$total;

            // Enriquece a página atual com os perfis vinculados — 1 query em lote
            // (AttachBatchTest), não 1 por linha.
            $model->attach(["profiles"]);
            $users = $model->data;

            $availableProfiles = $this->available_profiles();
        } catch (RuntimeException $e) {
            Logger::getInstance()->error("users display failed", ["error" => $e->getMessage()]);
            $users             = [];
            $total             = 0;
            $total_users       = 0;
            $active_users      = 0;
            $enabled_users     = 0;
            $removed_users     = 0;
            $availableProfiles = [];
        }

        if ($format === '.json') {
            // toUtf8() (CommonFunctions.php) exige string — colunas legitimamente
            // nulas (last_login, email_verified_at, login opcional, e as colunas
            // de profiles_attach vindas do SELECT * de attach()) quebrariam
            // json_response()/a_walk() antes de chegar lá. Contornado aqui (fora
            // de app/inc/lib) trocando null por '' só nesta saída; a view HTML
            // usa $users original e continua enxergando os null.
            json_response(["total" => $total, "row" => array_map([$this, 'json_safe'], $users)]);
        }

        // URL de volta: o endereço atual já com os filtros aplicados, codificado
        // para viajar como parâmetro — salvar ou cancelar traz o usuário de volta
        // à mesma busca.
        $form = [
            "done"    => rawurlencode($done !== [] ? set_url($users_url, $done) : $users_url),
            "pattern" => [
                "new"    => $newuser_url,
                "action" => $user_url,
                "remove" => $removeuser_url,
                "search" => $users_url,
            ],
        ];

        // Cabeçalhos clicáveis: [valor do próximo ordenation, classe do ícone]
        $ordenation = [];
        foreach (self::ORDER_ALLOWED as $column) {
            $ordenation[$column] = ordenation_header($column, $ordenationColumn, $ordenationDirection);
        }

        // $paginate tem piso de PER_PAGE_MIN, então nunca é 0 aqui.
        $totalPages = (int)ceil($total / $paginate);

        $alpineControllers = ['dashboard'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/dashboard.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function form(array $info): void
    {
        global $users_url, $newuser_url, $user_url;

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        $slug = $info[1] ?? null;
        $idx  = $slug !== null ? $this->idx_by_slug($slug) : 0;

        // $slug !== null mas idx_by_slug() devolveu 0 = link para um slug que
        // não existe (mais) — distinto de "sem slug" (cadastro). Sem este
        // guard, o form cai silenciosamente no modo cadastro.
        if ($slug !== null && $idx === 0) {
            $_SESSION["messages_app"]["danger"] = ["Usuário não encontrado."];
            basic_redir($users_url);
        }

        $done = (string)($info['get']['done'] ?? '');

        // Modo cadastro é o default; a presença do identificador vira edição.
        $data = [];
        $form = [
            "title"     => "Cadastrar Usuário",
            "url"       => $newuser_url,
            "done"      => $done,
            "cancelUrl" => back_url($info['get'] ?? [], $users_url),
        ];

        if ($idx > 0) {
            $model = new users_model();
            $model->set_field(self::FORM_FIELDS);
            $model->set_filter([" active = 'yes' ", " idx = ? "], [$idx]);
            $model->set_paginate([1]);
            $model->load_data(false);
            $model->attach(["profiles"]);
            $data = $model->data[0] ?? [];

            if ($data === []) {
                $_SESSION["messages_app"]["danger"] = ["Usuário não encontrado."];
                basic_redir($users_url);
            }

            $form["title"] = "Editar Usuário";
            $form["url"]   = sprintf($user_url, rawurlencode((string)$data['slug']));
        }

        $availableProfiles = $this->available_profiles();
        $currentProfileIds = array_map('intval', array_column($data['profiles_attach'] ?? [], 'idx'));

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/user.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function save(array $info): void
    {
        global $users_url;

        $post = $info['post'] ?? [];
        validate_csrf($post['_csrf_token'] ?? null, $users_url);

        $slug    = $info[1] ?? null;
        $idx     = $slug !== null ? $this->idx_by_slug($slug) : 0;
        $backUrl = back_url($post, $users_url);

        // $slug !== null mas idx_by_slug() devolveu 0 = link para um slug que
        // não existe (mais). Sem este guard, o código abaixo cai no ramo de
        // INSERT (idx <= 0) e cria um usuário novo — mas a mensagem de sucesso
        // é decidida por "$slug !== null", então relataria "atualizado" para
        // uma criação, mascarando a duplicata.
        if ($slug !== null && $idx === 0) {
            $_SESSION["messages_app"]["danger"] = ["Usuário não encontrado."];
            basic_redir($backUrl);
        }

        $name  = trim((string)($post['name'] ?? ''));
        $mail  = trim((string)($post['mail'] ?? ''));
        $login = trim((string)($post['login'] ?? ''));
        $phone = trim((string)($post['phone'] ?? ''));

        if ($name === '' || $mail === '') {
            $_SESSION["messages_app"]["danger"] = ["Nome e e-mail são obrigatórios."];
            basic_redir($backUrl);
        }
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION["messages_app"]["danger"] = ["E-mail inválido."];
            basic_redir($backUrl);
        }

        $rollback = false;
        $values   = ['name' => $name, 'mail' => $mail, 'login' => $login, 'phone' => $phone];

        try {
            $model = new users_model();

            // Filtro definido = UPDATE naquele registro; sem filtro = INSERT.
            if ($idx > 0) {
                $model->set_filter([" idx = ? "], [$idx]);
                $model->populate($values);
                $model->save();
            } else {
                // Identificação pública: sequência aleatória + data. Única sem
                // depender de contador, e opaca na URL.
                $values['slug'] = generate_key(10) . date("ymd");
                $model->populate($values);
                $idx = (int)$model->save();
            }

            $profileIds = array_map('intval', (array)($post['profiles_id'] ?? []));
            $profileIds = array_values(array_filter($profileIds, static fn(int $v): bool => $v > 0));

            $loggedInId = (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0);

            // Guard de autodemoção: login no manager exige perfil com adm='yes'
            // (auth_controller::login()) — sem este guard, o próprio admin
            // poderia se desvincular de todos os perfis admin por este
            // formulário e ficar sem acesso ao painel, sem ninguém poder
            // reverter pela UI. Mesmo espírito do guard de autorremoção em
            // remove().
            $keepOwnAdminAccess = false;
            if ($idx === $loggedInId) {
                $adminProfileIds = array_map('intval', array_keys(
                    (new profiles_model())->data4select("idx", [" active = 'yes' ", " adm = 'yes' "], "idx")
                ));
                if (array_intersect($profileIds, $adminProfileIds) === []) {
                    $_SESSION["messages_app"]["danger"] = ["Você não pode remover seu próprio acesso de administrador."];
                    $keepOwnAdminAccess = true;
                }
            }

            if ($keepOwnAdminAccess) {
                // Vínculos do próprio admin permanecem como estavam.
            } else {
                // Lista vazia desvincula tudo — save_attach() trata isso desde o
                // plano 012.
                $model->save_attach(['idx' => $idx, 'post' => ['profiles_id' => $profileIds]], ['profiles']);
            }

            $_SESSION["messages_app"]["success"] = [$slug !== null ? "Usuário atualizado com sucesso." : "Usuário criado com sucesso."];
        } catch (RuntimeException $e) {
            $rollback = true;
            Logger::getInstance()->error("users save failed", [
                "error" => $e->getMessage(),
                "idx"   => $idx,
            ]);
            $_SESSION["messages_app"]["danger"] = ["Falha ao salvar o usuário. Verifique se o e-mail já está em uso."];
        }

        // Três saídas: sem navegação (salvamento em segundo plano), URL de volta
        // preservando a busca, ou a listagem padrão.
        if (isset($post['no-redirect'])) {
            // json_response() fecha a transação (plano 002) — sem isso a escrita
            // seria revertida pelo __destruct() do localPDO.
            json_response(["ok" => !$rollback, "idx" => $idx], $rollback ? 500 : 200);
        }

        basic_redir($backUrl, rollback: $rollback);
    }

    public function remove(array $info): void
    {
        global $users_url;

        $post = $info['post'] ?? [];
        validate_csrf($post['_csrf_token'] ?? null, $users_url);

        $slug    = $info[1] ?? null;
        $idx     = $slug !== null ? $this->idx_by_slug($slug) : 0;
        $backUrl = back_url($post, $users_url);

        $adminId  = (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0);
        $rollback = false;

        if ($idx > 0 && $idx !== $adminId) {
            try {
                $model = new users_model();
                $model->set_filter([" idx = ? "], [$idx]);
                // Remoção lógica: marca active = 'no'. O registro some das consultas
                // (que sempre filtram por ativos) mas continua recuperável.
                $model->remove();
            } catch (RuntimeException $e) {
                $rollback = true;
                Logger::getInstance()->error("users remove failed", [
                    "error" => $e->getMessage(),
                    "idx"   => $idx,
                ]);
                $_SESSION["messages_app"]["danger"] = ["Falha ao remover o usuário."];
            }
        } elseif ($idx > 0) {
            $_SESSION["messages_app"]["danger"] = ["Você não pode remover a si mesmo."];
        }

        basic_redir($backUrl, rollback: $rollback);
    }

    /**
     * Exceção deliberada ao padrão display/form/save/remove: hospeda o que não
     * é CRUD de um registro (export-csv) e mudanças de estado sem formulário
     * próprio (ativar/inativar/reset-senha). 'criar', 'editar' e 'remover' NÃO
     * vivem aqui — viraram save()/remove().
     */
    public function action(array $info): void
    {
        global $users_url;

        $post   = $info['post'] ?? [];
        $action = $post['action'] ?? '';
        $idx    = (int)($post['idx'] ?? 0);

        validate_csrf($post['_csrf_token'] ?? null, $users_url);

        if ($action === 'export-csv') {
            $model = new users_model();
            $model->set_field([" idx ", " name ", " mail ", " login ", " enabled ", " active ", " created_at ", " last_login "]);
            $model->set_filter([" idx > 0 "]);
            $model->set_order([" created_at DESC "]);
            $model->load_data();

            $headers = ['idx', 'name', 'mail', 'login', 'enabled', 'active', 'created_at', 'last_login'];
            array_to_csv($model->data, 'usuarios_' . date('Y-m-d') . '.csv', $headers);
        }

        if ($idx <= 0) {
            basic_redir($users_url);
        }

        $rollback = false;

        try {
            $update = new users_model();
            $update->set_filter(["idx = ?"], [$idx]);

            if ($action === 'inativar') {
                $update->populate(["enabled" => "no"]);
                $update->save();
            } elseif ($action === 'ativar') {
                $update->populate(["enabled" => "yes"]);
                $update->save();
            } elseif ($action === 'reset-senha') {
                $resetUser = new users_model();
                $resetUser->set_field([" idx ", " name ", " mail "]);
                $resetUser->set_filter([" active = 'yes' ", " idx = ? "], [$idx]);
                $resetUser->set_paginate([1]);
                $resetUser->load_data();
                $user = $resetUser->data[0] ?? null;

                if ($user) {
                    $token   = random_token();
                    $expires = date("Y-m-d H:i:s", strtotime("+2 hours"));

                    $resetUser->set_filter(["idx = ?"], [$idx]);
                    $resetUser->populate([
                        "email_token"           => $token,
                        "email_token_expires_at" => $expires,
                    ]);
                    $resetUser->save();

                    $resetLink = canonical_url('SITE_CANONICAL_URL') . '/redefinir-senha/' . $token;
                    $name      = $user['name'];
                    $subject   = "Redefinição de senha — " . constant('cTitle');
                    ob_start();
                    include(constant("cRootServer") . "ui/mail/reset_password.php");
                    $body = ob_get_clean();

                    EmailQueue::enqueue($user['mail'], $subject, $body);

                    try {
                        $msgModel = new messages_model();
                        $msgModel->populate([
                            "to_mail" => $user['mail'],
                            "subject" => $subject,
                            "body"    => redact_email_body($body),
                            "sent_at" => date("Y-m-d H:i:s"),
                        ]);
                        $msgModel->save();
                    } catch (Exception $e) {
                        error_log("Erro ao salvar log de email: " . $e->getMessage());
                    }

                    $_SESSION["messages_app"]["success"] = ["Link de redefinição de senha enviado para " . htmlspecialchars($user['mail'], ENT_QUOTES, 'UTF-8') . "."];
                }
            }
        } catch (RuntimeException $e) {
            $rollback = true;
            Logger::getInstance()->error("users action failed", [
                "error"   => $e->getMessage(),
                "action"  => $action,
                "user_id" => $idx,
            ]);
        }

        basic_redir($users_url, rollback: $rollback);
    }

    /** Traduz o identificador público da URL no identificador interno. */
    private function idx_by_slug(string $slug): int
    {
        $found = (new users_model())->data4select(
            "name",
            [" active = 'yes' ", " slug = ? "],
            "idx",
            [$slug]
        );

        return (int)current($found);
    }

}

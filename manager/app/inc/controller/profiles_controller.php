<?php

/**
 * Padrão de controller do projeto: display / form / save / remove, apoiados
 * por filter() (tradução dos parâmetros de busca) e por
 * DOLModel::data4select() (mapa chave=>rótulo e resolução de slug em idx).
 *
 * Este arquivo é o exemplar de referência — veja plans/005-*.md.
 *
 * `adm` nunca é lido de $_POST nem gravado aqui: é o gate de privilégio de todo
 * o painel manager (ver auth_controller::login()) e aparece na tela como
 * somente leitura.
 */
class profiles_controller
{
    /** Colunas que a listagem aceita ordenar. ORDER BY não aceita bind. */
    private const ORDER_ALLOWED = ['name', 'slug', 'created_at'];

    /** Piso de itens por página: pedidos abaixo disso são elevados. */
    private const PER_PAGE_MIN = 20;

    private const SIDEBAR_COLOR = 'rgba(99, 102, 241, 0.92)';

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
            $filter[]            = " name LIKE ? ";
            $params[]            = '%' . addcslashes($name, '\\%_') . '%';
        }

        $adm = trim((string)($get['filter_adm'] ?? ''));
        if ($adm !== '' && in_array($adm, ['yes', 'no'], true)) {
            $done['filter_adm'] = $adm;
            $filter[]           = " adm = ? ";
            $params[]           = $adm;
        }

        $parent = (int)($get['filter_parent'] ?? 0);
        if ($parent > 0) {
            $done['filter_parent'] = (string)$parent;
            $filter[]              = " parent = ? ";
            $params[]              = $parent;
        }

        return [$done, $filter, $params];
    }

    public function display(array $info): void
    {
        global $profiles_url, $newprofile_url, $profile_url;

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        $format   = ($info[1] ?? '') === '.json' ? '.json' : '.html';
        $paginate = max(self::PER_PAGE_MIN, (int)($info['get']['paginate'] ?? 0));
        $offset   = (int)($info['sr'] ?? 0);

        [$ordenationColumn, $ordenationDirection] = resolve_ordenation(
            $info['get']['ordenation'] ?? null,
            self::ORDER_ALLOWED,
            'name'
        );

        [$done, $filter, $params] = $this->filter($info);

        try {
            $model = new profiles_model();
            $model->set_field([" idx ", " name ", " slug ", " adm ", " editabled ", " parent ", " created_at "]);
            $model->set_filter($filter, $params);
            $model->set_order([" {$ordenationColumn} {$ordenationDirection} "]);

            if ($format === '.html') {
                $model->set_paginate([$offset, $paginate]);
            }

            // return_data() chama load_data(true) por baixo — recordset vira o
            // total SEM o LIMIT, a contagem da paginação. Não escreva um COUNT à mão.
            [$total, $profiles] = $model->return_data();
            $total = (int)$total;

            $availableParents = (new profiles_model())->data4select("idx", [" active = 'yes' "], "name");
        } catch (RuntimeException $e) {
            Logger::getInstance()->error("profiles display failed", ["error" => $e->getMessage()]);
            $profiles         = [];
            $total            = 0;
            $availableParents = [];
        }

        if ($format === '.json') {
            json_response(["total" => $total, "row" => $profiles]);
        }

        $page          = 'Perfis';
        $sidebar_color = self::SIDEBAR_COLOR;

        // URL de volta: o endereço atual já com os filtros aplicados, codificado
        // para viajar como parâmetro — salvar ou cancelar traz o usuário de volta
        // à mesma busca.
        $form = [
            "done"    => rawurlencode($done !== [] ? set_url($profiles_url, $done) : $profiles_url),
            "pattern" => [
                "new"    => $newprofile_url,
                "action" => $profile_url,
                "search" => $profiles_url,
            ],
        ];

        // Cabeçalhos clicáveis: [valor do próximo ordenation, classe do ícone]
        $ordenation = [];
        foreach (self::ORDER_ALLOWED as $column) {
            $ordenation[$column] = ordenation_header($column, $ordenationColumn, $ordenationDirection);
        }

        // $paginate tem piso de PER_PAGE_MIN, então nunca é 0 aqui.
        $totalPages = (int)ceil($total / $paginate);

        $alpineControllers = ['profiles'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/profiles.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function form(array $info): void
    {
        global $profiles_url, $newprofile_url, $profile_url;

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        $slug = $info[1] ?? null;
        $idx  = $slug !== null ? $this->idx_by_slug($slug) : 0;

        // Modo cadastro é o default; a presença do identificador vira edição.
        $data = [];
        $form = [
            "title" => "Cadastrar Perfil",
            "url"   => $newprofile_url,
            "done"  => (string)($info['get']['done'] ?? ''),
        ];

        if ($idx > 0) {
            $model = new profiles_model();
            $model->set_field([" idx ", " name ", " slug ", " adm ", " editabled ", " parent ", " created_at "]);
            $model->set_filter([" active = 'yes' ", " idx = ? "], [$idx]);
            $model->set_paginate([1]);
            $model->load_data(false);
            $data = $model->data[0] ?? [];

            if ($data === []) {
                $_SESSION["messages_app"]["danger"] = ["Perfil não encontrado."];
                basic_redir($profiles_url);
            }

            $form["title"] = "Editar Perfil";
            $form["url"]   = sprintf($profile_url, rawurlencode((string)$data['slug']));
        }

        $availableParents = (new profiles_model())->data4select("idx", [" active = 'yes' "], "name");

        $page          = 'Perfil';
        $sidebar_color = self::SIDEBAR_COLOR;

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/profile.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function save(array $info): void
    {
        global $profiles_url;

        $post = $info['post'] ?? [];
        validate_csrf($post['_csrf_token'] ?? null, $profiles_url);

        $slug = $info[1] ?? null;
        $idx  = $slug !== null ? $this->idx_by_slug($slug) : 0;

        $name      = trim((string)($post['name'] ?? ''));
        $postSlug  = trim((string)($post['slug'] ?? ''));
        $parent    = (int)($post['parent'] ?? 0);
        $backUrl   = $this->back_url($post, $profiles_url);

        if ($name === '' || $postSlug === '') {
            $_SESSION["messages_app"]["danger"] = ["Nome e slug são obrigatórios."];
            basic_redir($backUrl);
        }
        if (!valid_slug($postSlug)) {
            $_SESSION["messages_app"]["danger"] = ["Slug inválido: use minúsculas, números, '-' ou '_' (ex.: meu-perfil)."];
            basic_redir($backUrl);
        }

        if ($idx > 0) {
            if (!$this->is_editabled($idx)) {
                $_SESSION["messages_app"]["danger"] = ["Este perfil é protegido e não pode ser editado."];
                basic_redir($backUrl);
            }
            if ($parent === $idx) {
                $_SESSION["messages_app"]["danger"] = ["Um perfil não pode ser pai de si mesmo."];
                basic_redir($backUrl);
            }
        }

        $rollback = false;

        try {
            $model = new profiles_model();

            // Filtro definido = UPDATE naquele registro; sem filtro = INSERT.
            if ($idx > 0) {
                $model->set_filter([" idx = ? "], [$idx]);
                $model->populate(['name' => $name, 'slug' => $postSlug, 'parent' => $parent]);
                $model->save();
            } else {
                $model->populate([
                    'name'      => $name,
                    'slug'      => $postSlug,
                    'parent'    => $parent,
                    'editabled' => 'yes',
                ]);
                $idx = (int)$model->save();
            }

            $_SESSION["messages_app"]["success"] = [$slug !== null ? "Perfil atualizado com sucesso." : "Perfil criado com sucesso."];
        } catch (RuntimeException $e) {
            $rollback = true;
            Logger::getInstance()->error("profiles save failed", [
                "error" => $e->getMessage(),
                "idx"   => $idx,
            ]);
            $_SESSION["messages_app"]["danger"] = ["Falha ao salvar o perfil. Verifique se o slug já está em uso."];
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
        global $profiles_url;

        $post = $info['post'] ?? [];
        validate_csrf($post['_csrf_token'] ?? null, $profiles_url);

        $slug    = $info[1] ?? null;
        $idx     = $slug !== null ? $this->idx_by_slug($slug) : 0;
        $backUrl = $this->back_url($post, $profiles_url);

        if ($idx > 0 && $this->is_editabled($idx)) {
            $model = new profiles_model();
            $model->set_filter([" idx = ? "], [$idx]);
            // Remoção lógica: marca active = 'no'. O registro some das consultas
            // (que sempre filtram por ativos) mas continua recuperável.
            $model->remove();
        } elseif ($idx > 0) {
            $_SESSION["messages_app"]["danger"] = ["Este perfil é protegido e não pode ser removido."];
        }

        basic_redir($backUrl);
    }

    /** Traduz o identificador público da URL no identificador interno. */
    private function idx_by_slug(string $slug): int
    {
        $found = (new profiles_model())->data4select(
            "name",
            [" active = 'yes' ", " slug = ? "],
            "idx",
            [$slug]
        );

        return (int)current($found);
    }

    private function is_editabled(int $idx): bool
    {
        $model = new profiles_model();
        $model->set_field([" idx ", " editabled "]);
        $model->set_filter([" active = 'yes' ", " idx = ? "], [$idx]);
        $model->set_paginate([1]);
        $model->load_data(false);

        return (($model->data[0]['editabled'] ?? 'yes') === 'yes');
    }

    /** URL de volta que o formulário carregou, ou a listagem padrão. */
    private function back_url(array $post, string $fallback): string
    {
        $done = trim((string)($post['done'] ?? ''));
        if ($done === '') {
            return $fallback;
        }

        $decoded = rawurldecode($done);

        // Só aceita destino interno — impede open redirect via campo do form.
        return str_starts_with($decoded, constant("cFrontend")) ? $decoded : $fallback;
    }
}

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

    /** Teto de itens por página: pedidos acima disso são rebaixados (evita dump da tabela via ?paginate=). */
    private const PER_PAGE_MAX = 200;

    /** Colunas do registro de perfil, usadas por display() e form(). */
    private const PROFILE_FIELDS = [" idx ", " name ", " slug ", " adm ", " editabled ", " parent ", " created_at "];

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

    /** Mapa idx=>nome de perfis ativos, para o <select> de "perfil pai". */
    private function available_parents(): array
    {
        return (new profiles_model())->data4select("idx", [" active = 'yes' "], "name");
    }

    /**
     * Todas as capacidades ativas, em [idx => name], para os checkboxes do form.
     * O vocabulario e fechado (migrations/012): a tela marca e desmarca, nunca
     * cria capacidade nova.
     *
     * @return array<int, string>
     */
    private function available_capabilities(): array
    {
        $model = new capabilities_model();
        $model->set_field([" idx ", " slug ", " name "]);
        $model->set_filter([" active = 'yes' "]);
        $model->set_order([" slug ASC "]);
        $model->load_data(false);

        $out = [];
        foreach ($model->data as $row) {
            $out[(int)$row['idx']] = sprintf('%s — %s', $row['slug'], $row['name']);
        }

        return $out;
    }

    /**
     * Capacidades atualmente vinculadas a um perfil, via attach() (convencao
     * de nomes do DOLModel resolve para profiles_capabilities). Extraido para
     * metodo proprio para ser testavel por reflection sem renderizar a tela.
     *
     * @return array<int, int>
     */
    private function selected_capabilities(int $idx): array
    {
        if ($idx <= 0) {
            return [];
        }

        $linked = new profiles_model();
        $linked->set_field([" idx "]);
        $linked->set_filter([" active = 'yes' ", " idx = ? "], [$idx]);
        $linked->set_paginate([1]);
        $linked->load_data(false);
        $linked->attach(["capabilities"]);

        $selected = [];
        foreach ($linked->data[0]['capabilities_attach'] ?? [] as $row) {
            $selected[] = (int)($row['idx'] ?? 0);
        }

        return $selected;
    }

    public function display(array $info): void
    {
        global $profiles_url, $newprofile_url, $profile_url;

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        $format   = resolve_format($info);
        $paginate = min(self::PER_PAGE_MAX, max(self::PER_PAGE_MIN, (int)($info['get']['paginate'] ?? 0)));
        $offset   = (int)($info['sr'] ?? 0);

        [$ordenationColumn, $ordenationDirection] = resolve_ordenation(
            $info['get']['ordenation'] ?? null,
            self::ORDER_ALLOWED,
            'name'
        );

        [$done, $filter, $params] = $this->filter($info);

        try {
            $model = new profiles_model();
            $model->set_field(self::PROFILE_FIELDS);
            $model->set_filter($filter, $params);
            $model->set_order([" {$ordenationColumn} {$ordenationDirection} "]);
            // Paginação vale para .html e .json — nenhum dos dois devolve a
            // tabela inteira de uma vez.
            $model->set_paginate([$offset, $paginate]);

            // return_data() chama load_data(true) por baixo — recordset vira o
            // total SEM o LIMIT, a contagem da paginação. Não escreva um COUNT à mão.
            [$total, $profiles] = $model->return_data();
            $total = (int)$total;

            // Uma query em lote para a pagina inteira (nao por linha) — o
            // attach() do DOLModel ja resolve isso (plano 004).
            $model->attach(["capabilities"], null, null, ["idx"]);
            $profiles = $model->data;

            $availableParents = $this->available_parents();
        } catch (RuntimeException $e) {
            Logger::getInstance()->error("profiles display failed", ["error" => $e->getMessage()]);
            $profiles         = [];
            $total            = 0;
            $availableParents = [];
        }

        if ($format === '.json') {
            json_response(["total" => $total, "row" => $profiles]);
        }

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

        // $slug !== null mas idx_by_slug() devolveu 0 = link para um slug que
        // não existe (mais) — distinto de "sem slug" (cadastro). Sem este
        // guard, o form cai silenciosamente no modo cadastro.
        if ($slug !== null && $idx === 0) {
            $_SESSION["messages_app"]["danger"] = ["Perfil não encontrado."];
            basic_redir($profiles_url);
        }

        $done = (string)($info['get']['done'] ?? '');

        // Modo cadastro é o default; a presença do identificador vira edição.
        $data = [];
        $form = [
            "title"     => "Cadastrar Perfil",
            "url"       => $newprofile_url,
            "done"      => $done,
            "cancelUrl" => $done !== '' ? safe_internal_url(rawurldecode($done), $profiles_url) : $profiles_url,
        ];

        if ($idx > 0) {
            $model = new profiles_model();
            $model->set_field(self::PROFILE_FIELDS);
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

        $availableParents      = $this->available_parents();
        $availableCapabilities = $this->available_capabilities();
        $selectedCapabilities  = $this->selected_capabilities($idx);

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

        $slug    = $info[1] ?? null;
        $idx     = $slug !== null ? $this->idx_by_slug($slug) : 0;
        $backUrl = back_url($post, $profiles_url);

        // $slug !== null mas idx_by_slug() devolveu 0 = link para um slug que
        // não existe (mais). Sem este guard, o código abaixo cai no ramo de
        // INSERT (idx <= 0) e cria um perfil novo — mas a mensagem de sucesso
        // é decidida por "$slug !== null", então relataria "atualizado" para
        // uma criação, mascarando a duplicata.
        if ($slug !== null && $idx === 0) {
            $_SESSION["messages_app"]["danger"] = ["Perfil não encontrado."];
            basic_redir($backUrl);
        }

        $name     = trim((string)($post['name'] ?? ''));
        $postSlug = trim((string)($post['slug'] ?? ''));
        $parent   = (int)($post['parent'] ?? 0);

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

            // Só mexe no vínculo quando o formulário trouxe a seção — o campo
            // oculto distingue "desmarquei tudo" (desvincula) de "form sem a
            // seção" (não mexe). Perfil protegido nunca chega aqui: o guard de
            // is_editabled() acima já barrou.
            if (isset($post['capabilities_sent'])) {
                $capabilityIds = array_values(array_filter(
                    array_map('intval', (array)($post['capabilities_id'] ?? [])),
                    fn(int $id): bool => $id > 0
                ));

                $model->save_attach(
                    ['idx' => $idx, 'post' => ['capabilities_id' => $capabilityIds]],
                    ['capabilities']
                );
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
        $backUrl = back_url($post, $profiles_url);

        if ($idx > 0 && $this->is_editabled($idx)) {
            try {
                $model = new profiles_model();
                $model->set_filter([" idx = ? "], [$idx]);
                // Remoção lógica: marca active = 'no'. O registro some das consultas
                // (que sempre filtram por ativos) mas continua recuperável.
                $model->remove();
            } catch (RuntimeException $e) {
                Logger::getInstance()->error("profiles remove failed", [
                    "error" => $e->getMessage(),
                    "idx"   => $idx,
                ]);
                $_SESSION["messages_app"]["danger"] = ["Falha ao remover o perfil."];
            }
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

}

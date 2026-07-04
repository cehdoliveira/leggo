<?php
class profiles_controller
{
    /**
     * Spike (plano 028): CRUD de `profiles`, mirror de `site_controller::users_action`.
     * `adm` nunca é lido de $_POST nem gravado por este controller — é o gate de
     * privilégio de todo o painel manager (ver auth_controller::login()) e é sempre
     * exibido como somente leitura na view. Ver plans/028-DESIGN.md.
     */
    public function index(array $info): void
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        $perPage = 25;
        $page    = (int)($info['get']['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }
        $offset = ($page - 1) * $perPage;

        try {
            $model = new profiles_model();

            $countStmt      = $model->execute_raw_prepared("SELECT COUNT(*) AS total FROM profiles WHERE active = 'yes'");
            $total_profiles = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $model->set_field([" idx ", " name ", " slug ", " adm ", " editabled ", " parent ", " created_at "]);
            $model->set_filter([" active = 'yes' "]);
            $model->set_order([" name ASC "]);
            $model->set_paginate([$offset, $perPage]);
            $model->load_data(false);
            $profiles = $model->data;

            // Lista completa (sem paginacao) apenas para popular o <select> de "parent"
            // nos formularios de criar/editar.
            $parentOptionsModel = new profiles_model();
            $parentOptionsModel->set_field([" idx ", " name "]);
            $parentOptionsModel->set_filter([" active = 'yes' "]);
            $parentOptionsModel->set_order([" name ASC "]);
            $parentOptionsModel->load_data(false);
            $availableParents = $parentOptionsModel->data;
        } catch (RuntimeException $e) {
            $profiles          = [];
            $total_profiles    = 0;
            $availableParents  = [];
        }

        $totalPages = (int)ceil($total_profiles / $perPage);

        $alpineControllers = ['profiles'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/profiles.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function action(array $info): void
    {
        global $profiles_url;

        $post   = $info['post'] ?? [];
        $action = $post['action'] ?? '';
        $idx    = (int)($post['idx'] ?? 0);

        validate_csrf($post['_csrf_token'] ?? null, $profiles_url);

        if ($action === 'criar') {
            $rollback = false;

            try {
                $name   = trim($post['name'] ?? '');
                $slug   = trim($post['slug'] ?? '');
                $parent = (int)($post['parent'] ?? 0);

                if ($name === '' || $slug === '') {
                    $_SESSION["messages_app"]["danger"] = ["Nome e slug são obrigatórios."];
                    basic_redir($profiles_url);
                }

                $profile = new profiles_model();
                $profile->populate([
                    'name'      => $name,
                    'slug'      => $slug,
                    'parent'    => $parent,
                    'editabled' => 'yes',
                ]);
                $profile->save();

                $_SESSION["messages_app"]["success"] = ["Perfil criado com sucesso."];
            } catch (RuntimeException $e) {
                $rollback = true;
                Logger::getInstance()->error("profiles_action(criar) failed", [
                    "error" => $e->getMessage(),
                ]);
                $_SESSION["messages_app"]["danger"] = ["Falha ao criar perfil. Verifique se o slug já está em uso."];
            }

            basic_redir($profiles_url, rollback: $rollback);
        }

        if ($idx <= 0) {
            basic_redir($profiles_url);
        }

        // Carrega o estado atual da linha alvo para checar o guard de `editabled`
        // ANTES de qualquer tentativa de edit/remove — nunca falha silenciosamente.
        $check = new profiles_model();
        $check->set_field([" idx ", " editabled "]);
        $check->set_filter([" idx = ? "], [$idx]);
        $check->set_paginate([1]);
        $check->load_data(false);
        $target = $check->data[0] ?? null;

        if (!$target) {
            basic_redir($profiles_url);
        }

        if (($action === 'editar' || $action === 'remover') && ($target['editabled'] ?? 'yes') === 'no') {
            $_SESSION["messages_app"]["danger"] = ["Este perfil é protegido e não pode ser editado ou removido."];
            basic_redir($profiles_url);
        }

        $rollback = false;

        try {
            $update = new profiles_model();
            $update->set_filter(["idx = ?"], [$idx]);

            if ($action === 'editar') {
                $name   = trim($post['name'] ?? '');
                $slug   = trim($post['slug'] ?? '');
                $parent = (int)($post['parent'] ?? 0);

                if ($name !== '' && $slug !== '') {
                    $update->populate([
                        'name'   => $name,
                        'slug'   => $slug,
                        'parent' => $parent,
                    ]);
                    $update->save();
                }
            } elseif ($action === 'remover') {
                $update->remove();
            }
        } catch (RuntimeException $e) {
            $rollback = true;
            Logger::getInstance()->error("profiles_action failed", [
                "error"  => $e->getMessage(),
                "action" => $action,
                "idx"    => $idx,
            ]);
        }

        basic_redir($profiles_url, rollback: $rollback);
    }
}

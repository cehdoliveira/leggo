<?php
class site_controller
{
    public function dashboard($info)
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        try {
            $model = new users_model();
            $model->set_field([" idx ", " name ", " mail ", " login ", " active ", " enabled ", " created_at ", " last_login ", " email_verified_at "]);
            $model->set_filter([" idx > 0 "]);
            $model->set_order([" created_at DESC "]);
            $model->load_data();
            $users = $model->data;
        } catch (RuntimeException $e) {
            $users = [];
        }

        $total_users   = count($users);
        $active_users  = count(array_filter($users, fn($u) => $u['active'] === 'yes'));
        $enabled_users = count(array_filter($users, fn($u) => $u['enabled'] === 'yes' && $u['active'] === 'yes'));
        $removed_users = $total_users - $active_users;

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/dashboard.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function users_action($info)
    {
        global $users_url;

        $post   = $info['post'] ?? [];
        $action = $post['action'] ?? '';
        $idx    = (int)($post['idx'] ?? 0);

        validate_csrf($post['_csrf_token'] ?? null, $users_url);

        if ($idx <= 0) {
            basic_redir($users_url);
            return;
        }

        $adminId = (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0);

        if ($action === 'remover' && $idx === $adminId) {
            basic_redir($users_url);
            return;
        }

        try {
            $update  = new users_model();
            $safeIdx = $update->get_con()->real_escape_string((string)$idx);
            $update->set_filter(["idx = '$safeIdx'"]);

            if ($action === 'inativar') {
                $update->populate(["enabled" => "no"]);
                $update->save();
            } elseif ($action === 'ativar') {
                $update->populate(["enabled" => "yes"]);
                $update->save();
            } elseif ($action === 'remover') {
                $update->remove();
            }
        } catch (RuntimeException $e) {
            // falha silenciosa — redireciona de volta
        }

        basic_redir($users_url);
    }
}

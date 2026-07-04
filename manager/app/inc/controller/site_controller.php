<?php
class site_controller
{
    public function dashboard(array $info): void
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
            $model = new users_model();

            $countStmt = $model->execute_raw_prepared(
                "SELECT COUNT(*) AS total, SUM(active = 'yes') AS ativos, SUM(active = 'yes' AND enabled = 'yes') AS habilitados FROM users WHERE idx > 0"
            );
            $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'ativos' => 0, 'habilitados' => 0];

            $total_users   = (int)$counts['total'];
            $active_users  = (int)$counts['ativos'];
            $enabled_users = (int)$counts['habilitados'];
            $removed_users = $total_users - $active_users;

            $model->set_field([" idx ", " name ", " mail ", " login ", " active ", " enabled ", " created_at ", " last_login ", " email_verified_at "]);
            $model->set_filter([" idx > 0 "]);
            $model->set_order([" created_at DESC "]);
            $model->set_paginate([$offset, $perPage]);
            $model->load_data(false);
            $users = $model->data;
        } catch (RuntimeException $e) {
            $users         = [];
            $total_users   = 0;
            $active_users  = 0;
            $enabled_users = 0;
            $removed_users = 0;
        }

        $totalPages = (int)ceil($total_users / $perPage);

        $alpineControllers = ['dashboard'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/dashboard.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function users_action(array $info): void
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

        $adminId = (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0);

        if ($action === 'remover' && $idx === $adminId) {
            basic_redir($users_url);
        }

        $rollback = false;

        try {
            $update  = new users_model();
            $update->set_filter(["idx = ?"], [$idx]);

            if ($action === 'inativar') {
                $update->populate(["enabled" => "no"]);
                $update->save();
            } elseif ($action === 'ativar') {
                $update->populate(["enabled" => "yes"]);
                $update->save();
            } elseif ($action === 'remover') {
                $update->remove();
            } elseif ($action === 'editar') {
                $name = trim($post['name'] ?? '');
                $mail = trim($post['mail'] ?? '');
                if ($name !== '' && $mail !== '') {
                    $update->populate(['name' => $name, 'mail' => $mail]);
                    $update->save();
                }
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

                    try {
                        if (class_exists("EmailProducer")) {
                            $producer = EmailProducer::getInstance();
                            $producer->send($user['mail'], $subject, $body);
                        }
                    } catch (Exception $e) {
                        error_log("Erro ao enviar reset de senha: " . $e->getMessage());
                    }

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
            Logger::getInstance()->error("users_action failed", [
                "error"   => $e->getMessage(),
                "action"  => $action,
                "user_id" => $idx,
            ]);
        }

        basic_redir($users_url, rollback: $rollback);
    }
}

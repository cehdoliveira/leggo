<?php
class site_controller
{
    public function dashboard(array $info): void
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        $update  = new users_model();
        try {
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
                    $token   = bin2hex(random_bytes(32));
                    $expires = date("Y-m-d H:i:s", strtotime("+2 hours"));

                    $resetUser->set_filter(["idx = ?"], [$idx]);
                    $resetUser->populate([
                        "email_token"           => $token,
                        "email_token_expires_at" => $expires,
                    ]);
                    $resetUser->save();

                    $canonicalBase = rtrim(constant('SITE_CANONICAL_URL'), '/');
                    $resetLink = $canonicalBase . '/redefinir-senha/' . $token;
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
                            "body"    => $body,
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
            Logger::getInstance()->error("users_action failed", [
                "error"   => $e->getMessage(),
                "action"  => $action,
                "user_id" => $idx,
            ]);
        }

        basic_redir($users_url);
    }
}

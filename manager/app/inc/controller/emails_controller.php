<?php
class emails_controller
{
    /**
     * Spike (plano 027): lista somente leitura das mensagens registradas em
     * `messages` (cadastro, forgot-password, reset, criação de usuário).
     * Sem reenvio, sem edição, sem export — ver plans/027-DESIGN.md.
     */
    public function index(array $info): void
    {
        $perPage = 25;
        $page    = (int)($info['get']['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }
        $offset = ($page - 1) * $perPage;
        $q      = trim($info['get']['q'] ?? '');

        try {
            $model = new messages_model();

            if ($q !== '') {
                $like         = '%' . addcslashes($q, '\\%_') . '%';
                $countStmt    = $model->select([" COUNT(*) AS total "], "WHERE active = 'yes' AND to_mail LIKE ?", [$like]);
                $total_emails = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

                $model->set_filter([" active = 'yes' ", " to_mail LIKE ? "], [$like]);
            } else {
                $countStmt    = $model->select([" COUNT(*) AS total "], "WHERE active = 'yes'");
                $total_emails = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
            }

            $model->set_field([" idx ", " to_mail ", " subject ", " body ", " sent_at "]);
            $model->set_order([" sent_at DESC "]);
            $model->set_paginate([$offset, $perPage]);
            $model->load_data(false);
            $emails = $model->data;
        } catch (RuntimeException $e) {
            $emails       = [];
            $total_emails = 0;
        }

        $totalPages = (int)ceil($total_emails / $perPage);

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/emails.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }
}

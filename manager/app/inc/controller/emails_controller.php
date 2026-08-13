<?php

/**
 * Lista somente leitura das mensagens registradas em `messages` (cadastro,
 * forgot-password, reset, criação de usuário). Sem reenvio, sem edição, sem
 * remoção — decisão de produto, ver plans/027-DESIGN.md.
 *
 * Segue o padrão display/filter do projeto; não tem form/save/remove porque a
 * tela não escreve. Exemplar do padrão: profiles_controller.
 */
class emails_controller
{
    private const ORDER_ALLOWED = ['to_mail', 'subject', 'sent_at'];

    private const PER_PAGE_MIN = 20;

    /**
     * @return array{0: array<string, string>, 1: array<string>, 2: array<mixed>}
     */
    private function filter(array $info): array
    {
        $get    = $info['get'] ?? [];
        $done   = [];
        $filter = [" active = 'yes' "];
        $params = [];

        $mail = trim((string)($get['filter_mail'] ?? ''));
        if ($mail !== '') {
            $done['filter_mail'] = $mail;
            $filter[]            = " to_mail LIKE ? ";
            $params[]            = '%' . addcslashes($mail, '\\%_') . '%';
        }

        $subject = trim((string)($get['filter_subject'] ?? ''));
        if ($subject !== '') {
            $done['filter_subject'] = $subject;
            $filter[]               = " subject LIKE ? ";
            $params[]               = '%' . addcslashes($subject, '\\%_') . '%';
        }

        return [$done, $filter, $params];
    }

    public function display(array $info): void
    {
        global $emails_url;

        $format   = ($info[1] ?? '') === '.json' ? '.json' : '.html';
        $paginate = max(self::PER_PAGE_MIN, (int)($info['get']['paginate'] ?? 0));
        $offset   = (int)($info['sr'] ?? 0);

        [$ordenationColumn, $ordenationDirection] = resolve_ordenation(
            $info['get']['ordenation'] ?? null,
            self::ORDER_ALLOWED,
            'sent_at',
            'desc'
        );

        [$done, $filter, $params] = $this->filter($info);

        try {
            $model = new messages_model();
            $model->set_field([" idx ", " to_mail ", " subject ", " body ", " sent_at "]);
            $model->set_filter($filter, $params);
            $model->set_order([" {$ordenationColumn} {$ordenationDirection} "]);

            if ($format === '.html') {
                $model->set_paginate([$offset, $paginate]);
            }

            // return_data() chama load_data(true) por baixo — recordset vira o
            // total SEM o LIMIT. Não escreva um COUNT à mão.
            [$total, $emails] = $model->return_data();
            $total = (int)$total;
        } catch (RuntimeException $e) {
            Logger::getInstance()->error("emails display failed", ["error" => $e->getMessage()]);
            $emails = [];
            $total  = 0;
        }

        if ($format === '.json') {
            json_response(["total" => $total, "row" => $emails]);
        }

        $page          = 'E-mails';
        $sidebar_color = 'rgba(56, 189, 248, 0.92)';

        $form = [
            "done"    => rawurlencode($done !== [] ? set_url($emails_url, $done) : $emails_url),
            "pattern" => [
                "search" => $emails_url,
            ],
        ];

        $ordenation = [];
        foreach (self::ORDER_ALLOWED as $column) {
            $ordenation[$column] = ordenation_header($column, $ordenationColumn, $ordenationDirection);
        }

        // $paginate tem piso de PER_PAGE_MIN, então nunca é 0 aqui.
        $totalPages = (int)ceil($total / $paginate);

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/emails.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }
}

<?php

/**
 * Import de usuários por CSV — fatia 1 do desenho em plans/018-DESIGN.md
 * (Step 6, versão mínima: só name+mail, sem login/enabled/perfis no arquivo).
 *
 * display()/form() seguem o padrão display/form/save/remove; action() hospeda
 * a confirmação (não é CRUD de um registro) e remove() é soft-delete do
 * rascunho ainda não aplicado.
 *
 * parseCsv() fica separado de save() (que faz o handle_upload()) de propósito:
 * handle_upload() usa is_uploaded_file(), que em CLI/PHPUnit nunca é true —
 * não há como testar o upload de ponta a ponta fora de um request HTTP real.
 * parseCsv() recebe o conteúdo já lido, então os testes exercitam toda a
 * classificação/validação sem depender de upload real.
 */
class usersimports_controller
{
    /** Teto de linhas de dados por arquivo — acima disso, rejeita antes de processar. */
    private const MAX_ROWS = 200;

    /** Subdiretório de upload dentro de UPLOAD_DIR. */
    private const UPLOAD_SUBDIR = 'users_imports';

    public function display(array $info): void
    {
        global $usersimports_url;

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        // Link "baixar modelo" — mesma rota GET, sem tela própria (não está
        // no inventário de rotas desta fatia).
        if (($info['get']['baixar_modelo'] ?? '') === '1') {
            array_to_csv([['name' => '', 'mail' => '']], 'modelo_import_usuarios.csv', ['name', 'mail']);
        }

        $model = new users_imports_model();
        $model->set_field([" idx ", " name ", " created_at ", " imported_at "]);
        $model->set_filter([" active = 'yes' "]);
        $model->set_order([" created_at DESC "]);
        $model->load_data(false);
        $imports = $model->data;

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/users_imports.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function form(array $info): void
    {
        global $usersimports_url;

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        $idx = (int)($info[1] ?? 0);

        if ($idx <= 0) {
            include(constant("cRootServer") . "ui/common/head.php");
            include(constant("cRootServer") . "ui/common/header.php");
            include(constant("cRootServer") . "ui/page/users_import_upload.php");
            include(constant("cRootServer") . "ui/common/footer.php");
            include(constant("cRootServer") . "ui/common/foot.php");

            return;
        }

        $model = new users_imports_model();
        $model->set_field([" idx ", " name ", " dados ", " imported_at "]);
        $model->set_filter([" active = 'yes' ", " idx = ? "], [$idx]);
        $model->set_paginate([1]);
        $model->load_data(false);
        $draft = $model->data[0] ?? null;

        if ($draft === null) {
            $_SESSION["messages_app"]["danger"] = ["Rascunho de import não encontrado."];
            basic_redir($usersimports_url);
        }

        $rows          = json_decode((string)$draft['dados'], true) ?: [];
        $totalRows     = count($rows);
        $criarRows     = array_values(array_filter($rows, static fn(array $r): bool => $r['status'] === 'criar'));
        $atualizarRows = array_values(array_filter($rows, static fn(array $r): bool => $r['status'] === 'atualizar'));
        $erroRows      = array_values(array_filter($rows, static fn(array $r): bool => $r['status'] === 'erro'));

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/users_import_preview.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function save(array $info): void
    {
        global $usersimports_url;

        $post = $info['post'] ?? [];
        validate_csrf($post['_csrf_token'] ?? null, $usersimports_url);

        $file       = $_FILES['arquivo'] ?? [];
        $uploadPath = handle_upload($file, self::UPLOAD_SUBDIR, ['allowed_types' => 'csv']);

        if ($uploadPath === false) {
            $_SESSION["messages_app"]["danger"] = ["Falha ao enviar o arquivo. Envie um CSV válido de até " . constant('UPLOAD_MAX_SIZE') . "MB."];
            basic_redir($usersimports_url . '/novo');
        }

        $filesystemPath = rtrim((string)constant('UPLOAD_DIR'), '/') . '/' . self::UPLOAD_SUBDIR . '/' . basename((string)$uploadPath);
        $content        = file_get_contents($filesystemPath);

        if ($content === false) {
            $_SESSION["messages_app"]["danger"] = ["Falha ao ler o arquivo enviado."];
            basic_redir($usersimports_url . '/novo');
        }

        $result = $this->parseCsv($content);

        if ($result['error'] !== null) {
            $_SESSION["messages_app"]["danger"] = [$result['error']];
            basic_redir($usersimports_url . '/novo');
        }

        $newIdx = 0;

        try {
            $draft = new users_imports_model();
            $draft->populate([
                'name'  => $file['name'] ?? 'import.csv',
                'dados' => json_encode($result['rows'], JSON_UNESCAPED_UNICODE),
            ]);
            $newIdx = (int)$draft->save();

            if ($newIdx <= 0) {
                $_SESSION["messages_app"]["danger"] = ["Falha ao salvar o rascunho do import."];
                basic_redir($usersimports_url . '/novo', rollback: true);
            }
        } catch (RuntimeException $e) {
            Logger::getInstance()->error("users import save failed", ["error" => $e->getMessage()]);
            $_SESSION["messages_app"]["danger"] = ["Falha ao salvar o rascunho do import."];
            basic_redir($usersimports_url . '/novo', rollback: true);
        }

        basic_redir($usersimports_url . '/' . $newIdx);
    }

    /**
     * POST /importar-usuarios — confirmação do rascunho (idx vem do POST, o
     * resto do formulário é ignorado: reprocessa o JSON salvo em `dados`).
     */
    public function action(array $info): void
    {
        global $usersimports_url;

        $post = $info['post'] ?? [];
        validate_csrf($post['_csrf_token'] ?? null, $usersimports_url);

        if (($post['action'] ?? '') !== 'confirmar') {
            basic_redir($usersimports_url);
        }

        $idx = (int)($post['idx'] ?? 0);
        if ($idx <= 0) {
            basic_redir($usersimports_url);
        }

        $model = new users_imports_model();

        // Lock explicito: duplo submit/F5/dois operadores confirmando o mesmo
        // idx concorrentemente não podem aplicar o import duas vezes.
        $lockStmt = $model->execute_raw_prepared(
            "SELECT idx, dados FROM users_imports WHERE idx = ? AND active = 'yes' AND imported_at IS NULL FOR UPDATE",
            [$idx]
        );
        $draft = $lockStmt->fetch(PDO::FETCH_ASSOC);

        if (!$draft) {
            $checkStmt = $model->execute_raw_prepared(
                "SELECT imported_at FROM users_imports WHERE idx = ? AND active = 'yes'",
                [$idx]
            );
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing && $existing['imported_at'] !== null) {
                $_SESSION["messages_app"]["success"] = ["Este import já foi aplicado em " . $existing['imported_at'] . "."];
            } else {
                $_SESSION["messages_app"]["danger"] = ["Rascunho de import não encontrado."];
            }

            basic_redir($usersimports_url);
        }

        $rows      = json_decode((string)$draft['dados'], true) ?: [];
        $rollback  = false;
        $created   = [];

        try {
            // Passada 1: todas as escritas em users, linha a linha. Qualquer
            // erro de banco aqui já reverte a transação inteira sozinho
            // (localPDO::executePrepared() faz rollback no catch de
            // PDOException antes de relançar como RuntimeException).
            foreach ($rows as $row) {
                if ($row['status'] === 'criar') {
                    $token = random_token();

                    $newUser = new users_model();
                    $newUser->populate([
                        'name'                   => $row['name'],
                        'mail'                   => $row['mail'],
                        'password'               => password_hash(random_token(), PASSWORD_BCRYPT),
                        'enabled'                => 'no',
                        'email_token'            => $token,
                        'email_token_expires_at' => date('Y-m-d H:i:s', strtotime('+72 hours')),
                    ]);
                    $createdIdx = (int)$newUser->save();

                    if ($createdIdx > 0) {
                        $newUser->save_attach(
                            ['idx' => $createdIdx, 'post' => ['profiles_id' => constant('DEFAULT_USER_PROFILE_ID')]],
                            ['profiles']
                        );
                        $created[] = ['idx' => $createdIdx, 'name' => $row['name'], 'mail' => $row['mail'], 'token' => $token];
                    }
                } elseif ($row['status'] === 'atualizar') {
                    $update = new users_model();
                    $update->set_filter([" active = 'yes' ", " mail = ? "], [$row['mail']]);
                    $update->populate(['name' => $row['name']]);
                    $update->save();
                }
            }

            // Passada 2: e-mail de convite para cada linha "criar" — só
            // dispara se a passada 1 inteira terminou sem erro. Mesmo padrão
            // de auth_controller::register(): falha de e-mail não desfaz o
            // usuário já criado.
            foreach ($created as $row) {
                try {
                    $name            = $row['name'];
                    $login           = $row['mail']; // sem coluna login nesta fatia — o rótulo do template aceita e-mail
                    $canonicalBase   = canonical_url('MANAGER_CANONICAL_URL');
                    $loginLink       = $canonicalBase . '/login';
                    $setPasswordLink = $canonicalBase . '/definir-senha/' . $row['token'];
                    $subject         = "Seus dados de acesso — " . constant('cTitle');
                    ob_start();
                    include(constant("cRootServer") . "ui/mail/new_admin_credentials.php");
                    $body = ob_get_clean();

                    if (class_exists("EmailProducer")) {
                        EmailProducer::getInstance()->send($row['mail'], $subject, $body);
                    }

                    $msgModel = new messages_model();
                    $msgModel->populate([
                        'to_mail' => $row['mail'],
                        'subject' => $subject,
                        'body'    => redact_email_body($body),
                        'sent_at' => date('Y-m-d H:i:s'),
                    ]);
                    $msgModel->save();
                } catch (Exception $e) {
                    error_log("Erro ao enviar email de import: " . $e->getMessage());
                }
            }

            $finish = new users_imports_model();
            $finish->set_filter([" idx = ? "], [$idx]);
            $finish->populate([
                'imported_at' => date('Y-m-d H:i:s'),
                'imported_by' => (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0),
            ]);
            $finish->save();

            $_SESSION["messages_app"]["success"] = ["Import aplicado com sucesso."];
        } catch (RuntimeException $e) {
            $rollback = true;
            Logger::getInstance()->error("users import confirm failed", ["error" => $e->getMessage(), "idx" => $idx]);
            $_SESSION["messages_app"]["danger"] = ["Falha ao aplicar o import. Nenhuma alteração foi salva."];
        }

        basic_redir($usersimports_url, rollback: $rollback);
    }

    public function remove(array $info): void
    {
        global $usersimports_url;

        $post = $info['post'] ?? [];
        validate_csrf($post['_csrf_token'] ?? null, $usersimports_url);

        $idx = (int)($info[1] ?? 0);
        if ($idx <= 0) {
            basic_redir($usersimports_url);
        }

        $rollback = false;

        try {
            $model     = new users_imports_model();
            $checkStmt = $model->execute_raw_prepared(
                "SELECT imported_at FROM users_imports WHERE idx = ? AND active = 'yes'",
                [$idx]
            );
            $row = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $_SESSION["messages_app"]["danger"] = ["Rascunho de import não encontrado."];
            } elseif ($row['imported_at'] !== null) {
                $_SESSION["messages_app"]["danger"] = ["Este import já foi aplicado e não pode ser removido."];
            } else {
                $model->set_filter([" idx = ? "], [$idx]);
                $model->remove();
                $_SESSION["messages_app"]["success"] = ["Rascunho removido."];
            }
        } catch (RuntimeException $e) {
            $rollback = true;
            Logger::getInstance()->error("users import remove failed", ["error" => $e->getMessage(), "idx" => $idx]);
            $_SESSION["messages_app"]["danger"] = ["Falha ao remover o rascunho."];
        }

        basic_redir($usersimports_url, rollback: $rollback);
    }

    /**
     * Converte o conteúdo do CSV (já sem BOM) em linhas classificadas
     * (criar/atualizar/erro). Não toca banco além da consulta de mails
     * existentes para classificar atualizar vs criar.
     *
     * $maxRows é parâmetro (não usa self::MAX_ROWS direto no corpo) para o
     * teste de teto poder reduzi-lo via reflexão sem mudar a constante de
     * produção.
     *
     * @return array{error: string|null, rows: array<int, array{row:int,name:?string,mail:?string,status:string,motivo:?string}>}
     */
    protected function parseCsv(string $content, int $maxRows = self::MAX_ROWS): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $lines = preg_split("/\r\n|\r|\n/", $content);
        $lines = $lines === false ? [] : $lines;
        while (!empty($lines) && trim((string)end($lines)) === '') {
            array_pop($lines);
        }

        if (empty($lines)) {
            return ['error' => 'Arquivo vazio.', 'rows' => []];
        }

        $headerLine = $this->toUtf8Line((string)array_shift($lines));
        $header     = array_map('trim', (array)str_getcsv($headerLine, ';', '"', '\\'));

        if (!in_array('name', $header, true) || !in_array('mail', $header, true)) {
            return ['error' => 'Cabeçalho do CSV precisa ter as colunas "name" e "mail".', 'rows' => []];
        }

        $dataLineCount = count($lines);
        if ($dataLineCount > $maxRows) {
            return ['error' => "Arquivo tem {$dataLineCount} linhas de dados; o teto é {$maxRows}.", 'rows' => []];
        }

        $rows         = [];
        $mailRowsSeen = [];
        $rowNumber    = 1; // linha 1 = cabeçalho

        foreach ($lines as $line) {
            $rowNumber++;
            $line = $this->toUtf8Line($line);
            $cols = (array)str_getcsv($line, ';', '"', '\\');

            if (count($cols) !== count($header)) {
                $rows[] = [
                    'row'    => $rowNumber,
                    'name'   => null,
                    'mail'   => null,
                    'status' => 'erro',
                    'motivo' => sprintf('Linha tem %d coluna(s); cabeçalho tem %d.', count($cols), count($header)),
                ];
                continue;
            }

            $assoc = array_combine($header, $cols);
            $name  = trim((string)($assoc['name'] ?? ''));
            $mail  = trim((string)($assoc['mail'] ?? ''));

            $motivos = [];
            if ($name === '') {
                $motivos[] = 'Nome é obrigatório.';
            } elseif (mb_strlen($name) > 255) {
                $motivos[] = 'Nome excede 255 caracteres.';
            }

            if ($mail === '') {
                $motivos[] = 'E-mail é obrigatório.';
            } elseif (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                $motivos[] = 'E-mail inválido.';
            }

            if ($motivos === [] && $mail !== '') {
                $mailRowsSeen[mb_strtolower($mail)][] = $rowNumber;
            }

            $rows[] = [
                'row'    => $rowNumber,
                'name'   => $name,
                'mail'   => $mail,
                'status' => $motivos === [] ? null : 'erro',
                'motivo' => $motivos === [] ? null : implode(' ', $motivos),
            ];
        }

        // Mail duplicado dentro do mesmo arquivo: todas as linhas envolvidas
        // viram erro, mesmo que cada uma isoladamente fosse válida.
        foreach ($mailRowsSeen as $rowNums) {
            if (count($rowNums) < 2) {
                continue;
            }
            foreach ($rows as &$r) {
                if (in_array($r['row'], $rowNums, true)) {
                    $r['status'] = 'erro';
                    $r['motivo'] = trim((string)(($r['motivo'] ?? '') . ' E-mail duplicado no arquivo.'));
                }
            }
            unset($r);
        }

        // Classifica o que sobrou (sem erro) em criar/atualizar — 1 query em
        // lote para os mails pendentes, não 1 por linha.
        $pendingMails = [];
        foreach ($rows as $r) {
            if ($r['status'] === null) {
                $pendingMails[] = $r['mail'];
            }
        }

        $existingMails = [];
        if ($pendingMails !== []) {
            $lookup       = new users_model();
            $placeholders = implode(',', array_fill(0, count($pendingMails), '?'));
            $stmt         = $lookup->execute_raw_prepared(
                "SELECT mail FROM users WHERE active = 'yes' AND mail IN ($placeholders)",
                $pendingMails
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $existingRow) {
                $existingMails[mb_strtolower((string)$existingRow['mail'])] = true;
            }
        }

        foreach ($rows as &$r) {
            if ($r['status'] !== null) {
                continue;
            }
            $r['status'] = isset($existingMails[mb_strtolower($r['mail'])]) ? 'atualizar' : 'criar';
        }
        unset($r);

        return ['error' => null, 'rows' => $rows];
    }

    /** Converte Windows-1252 para UTF-8 quando a linha não já for UTF-8 válido. */
    private function toUtf8Line(string $line): string
    {
        if ($line === '' || mb_check_encoding($line, 'UTF-8')) {
            return $line;
        }

        return mb_convert_encoding($line, 'UTF-8', 'Windows-1252');
    }
}

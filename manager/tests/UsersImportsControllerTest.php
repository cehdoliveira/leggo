<?php

declare(strict_types=1);

/**
 * Cobre a classificação do CSV (usersimports_controller::parseCsv(), fatia 1
 * do plans/018-DESIGN.md) e a confirmação do import (action()): idempotência
 * via imported_at e rollback do lote inteiro quando uma linha falha no meio.
 *
 * Padrão herdado de UsersControllerTest: extends DBTestCase, resetSingleton()
 * antes/depois de qualquer chamada que termine em basic_redir()
 * (fecha/reverte a transação do singleton), limpeza manual de fixture no
 * finally.
 *
 * parseCsv() é protected — chamado via ReflectionMethod, o mesmo padrão de
 * callPrivate() em UsersControllerTest. save() (que faz handle_upload()) não
 * é exercitado aqui: is_uploaded_file() nunca é true fora de um upload HTTP
 * real, então não há como simular o upload em CLI/PHPUnit.
 */
final class UsersImportsControllerTest extends DBTestCase
{
    /** Invoca usersimports_controller::parseCsv() (protected) em isolamento. */
    private function callParseCsv(string $content, ?int $maxRows = null): array
    {
        $controller = new usersimports_controller();
        $ref        = new ReflectionMethod($controller, 'parseCsv');
        $ref->setAccessible(true);

        return $maxRows === null
            ? $ref->invoke($controller, $content)
            : $ref->invoke($controller, $content, $maxRows);
    }

    private function makeActiveUser(string $name, string $mail): int
    {
        $insert = new users_model();
        $insert->populate([
            'name'     => $name,
            'mail'     => $mail,
            'password' => password_hash('secret', PASSWORD_BCRYPT),
        ]);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

        return $id;
    }

    public function testParseCsvValidRowClassifiedAsCriar(): void
    {
        $marker = uniqid();
        $mail   = "criar_{$marker}@example.com";

        $this->resetSingleton();
        try {
            $result = $this->callParseCsv("name;mail\nFulano de Tal;{$mail}\n");

            $this->assertNull($result['error']);
            $this->assertCount(1, $result['rows']);
            $this->assertSame('criar', $result['rows'][0]['status']);
            $this->assertSame('Fulano de Tal', $result['rows'][0]['name']);
            $this->assertSame($mail, $result['rows'][0]['mail']);
        } finally {
            $this->resetSingleton();
        }
    }

    public function testParseCsvExistingMailClassifiedAsAtualizar(): void
    {
        $marker = uniqid();
        $mail   = "atualizar_{$marker}@example.com";

        $this->resetSingleton();
        try {
            $this->makeActiveUser("original_{$marker}", $mail);

            $result = $this->callParseCsv("name;mail\nNome Novo;{$mail}\n");

            $this->assertNull($result['error']);
            $this->assertCount(1, $result['rows']);
            $this->assertSame('atualizar', $result['rows'][0]['status'], 'Mail ja existente e ativo deve classificar como atualizar');
        } finally {
            $this->resetSingleton();
        }
    }

    public function testParseCsvMissingMailIsRowError(): void
    {
        $this->resetSingleton();
        try {
            $result = $this->callParseCsv("name;mail\nSem Email;\n");

            $this->assertNull($result['error']);
            $this->assertSame('erro', $result['rows'][0]['status']);
            $this->assertStringContainsString('obrigatório', (string)$result['rows'][0]['motivo']);
        } finally {
            $this->resetSingleton();
        }
    }

    public function testParseCsvInvalidMailIsRowError(): void
    {
        $this->resetSingleton();
        try {
            $result = $this->callParseCsv("name;mail\nMail Invalido;nao-e-email\n");

            $this->assertSame('erro', $result['rows'][0]['status']);
            $this->assertStringContainsString('inválido', (string)$result['rows'][0]['motivo']);
        } finally {
            $this->resetSingleton();
        }
    }

    public function testParseCsvMissingNameIsRowError(): void
    {
        $marker = uniqid();

        $this->resetSingleton();
        try {
            $result = $this->callParseCsv("name;mail\n;sem_nome_{$marker}@example.com\n");

            $this->assertSame('erro', $result['rows'][0]['status']);
            $this->assertStringContainsString('Nome', (string)$result['rows'][0]['motivo']);
        } finally {
            $this->resetSingleton();
        }
    }

    public function testParseCsvHeaderOutOfOrderMapsByName(): void
    {
        $marker = uniqid();
        $mail   = "ordem_{$marker}@example.com";

        $this->resetSingleton();
        try {
            $result = $this->callParseCsv("mail;name\n{$mail};Nome Certo\n");

            $this->assertNull($result['error']);
            $this->assertSame('Nome Certo', $result['rows'][0]['name']);
            $this->assertSame($mail, $result['rows'][0]['mail']);
        } finally {
            $this->resetSingleton();
        }
    }

    public function testParseCsvColumnCountMismatchIsRowError(): void
    {
        $this->resetSingleton();
        try {
            $result = $this->callParseCsv("name;mail\nSo Uma Coluna\n");

            $this->assertNull($result['error']);
            $this->assertSame('erro', $result['rows'][0]['status']);
            $this->assertStringContainsString('coluna', (string)$result['rows'][0]['motivo']);
        } finally {
            $this->resetSingleton();
        }
    }

    public function testParseCsvStripsUtf8Bom(): void
    {
        $marker = uniqid();
        $mail   = "bom_{$marker}@example.com";
        $csv    = "\xEF\xBB\xBFname;mail\nCom Bom;{$mail}\n";

        $this->resetSingleton();
        try {
            $result = $this->callParseCsv($csv);

            $this->assertNull($result['error'], 'BOM UTF-8 nao removido faz o cabecalho "name" nao ser reconhecido');
            $this->assertCount(1, $result['rows']);
            $this->assertSame('criar', $result['rows'][0]['status']);
        } finally {
            $this->resetSingleton();
        }
    }

    public function testParseCsvConvertsWindows1252ToUtf8(): void
    {
        $marker    = uniqid();
        $mail      = "cp1252_{$marker}@example.com";
        $nameUtf8  = "José";
        $nameCp1252 = mb_convert_encoding($nameUtf8, 'Windows-1252', 'UTF-8');
        $csv       = "name;mail\n{$nameCp1252};{$mail}\n";

        $this->resetSingleton();
        try {
            $result = $this->callParseCsv($csv);

            $this->assertNull($result['error']);
            $this->assertSame($nameUtf8, $result['rows'][0]['name'], 'Linha em Windows-1252 deve ser convertida para UTF-8 antes do parse');
        } finally {
            $this->resetSingleton();
        }
    }

    public function testParseCsvLeadingFormulaCharacterTreatedAsPlainText(): void
    {
        $marker = uniqid();
        $mail   = "formula_{$marker}@example.com";
        $name   = '=SOMA(A1:A2)';

        $this->resetSingleton();
        try {
            $result = $this->callParseCsv("name;mail\n{$name};{$mail}\n");

            $this->assertNull($result['error']);
            $this->assertSame('criar', $result['rows'][0]['status'], 'Prefixo de formula nao deve gerar erro nem quebrar o parser');
            $this->assertSame($name, $result['rows'][0]['name'], 'Valor deve ser preservado como texto puro, sem sanitizacao de escrita');
        } finally {
            $this->resetSingleton();
        }
    }

    public function testParseCsvDuplicateMailInFileMarksBothRowsAsError(): void
    {
        $marker = uniqid();
        $mail   = "dup_{$marker}@example.com";

        $this->resetSingleton();
        try {
            $result = $this->callParseCsv("name;mail\nPrimeiro;{$mail}\nSegundo;{$mail}\n");

            $this->assertNull($result['error']);
            $this->assertCount(2, $result['rows']);
            $this->assertSame('erro', $result['rows'][0]['status']);
            $this->assertSame('erro', $result['rows'][1]['status']);
            $this->assertStringContainsString('duplicado', (string)$result['rows'][0]['motivo']);
            $this->assertStringContainsString('duplicado', (string)$result['rows'][1]['motivo']);
        } finally {
            $this->resetSingleton();
        }
    }

    public function testParseCsvRowCeilingRejectsWholeFile(): void
    {
        $marker = uniqid();
        $lines  = ["name;mail"];
        for ($i = 0; $i < 3; $i++) {
            $lines[] = "Linha {$i};ceiling_{$marker}_{$i}@example.com";
        }
        $csv = implode("\n", $lines) . "\n";

        $this->resetSingleton();
        try {
            // Teto reduzido para 2 apenas neste teste, via parametro do
            // metodo (reflexao) — a constante MAX_ROWS de producao (200) nao
            // e alterada.
            $result = $this->callParseCsv($csv, 2);

            $this->assertNotNull($result['error'], 'Arquivo com 3 linhas de dados deve ser rejeitado com teto=2');
            $this->assertSame([], $result['rows'], 'Nenhuma linha deve ser processada quando o teto e excedido');
            $this->assertStringContainsString('3', $result['error']);
        } finally {
            $this->resetSingleton();
        }
    }

    public function testParseCsvDiscardsForbiddenColumnsWithoutRowError(): void
    {
        $marker = uniqid();
        $mail   = "forbidden_{$marker}@example.com";
        $header = "idx;name;mail;login;enabled;active;created_at;last_login;password;adm;slug;email_token;email_token_expires_at;email_verified_at";
        $data   = "99;Nome Ok;{$mail};login_x;yes;yes;2024-01-01;2024-01-01;hash;yes;slug-x;tok;2024-01-01;2024-01-01";

        $this->resetSingleton();
        try {
            $result = $this->callParseCsv("{$header}\n{$data}\n");

            $this->assertNull($result['error']);
            $this->assertCount(1, $result['rows']);
            $this->assertSame('criar', $result['rows'][0]['status'], 'Colunas nunca aceitas devem ser descartadas silenciosamente, sem virar erro de linha');
            $this->assertSame('Nome Ok', $result['rows'][0]['name']);
            $this->assertSame($mail, $result['rows'][0]['mail']);
        } finally {
            $this->resetSingleton();
        }
    }

    public function testActionConfirmarTwiceIsIdempotent(): void
    {
        $GLOBALS['usersimports_url'] = constant('cFrontend') . 'importar-usuarios';
        $marker    = uniqid();
        $mail      = "confirm_once_{$marker}@example.com";
        $draftIdx  = null;
        $createdId = null;

        $this->resetSingleton();
        try {
            $rows = [
                ['row' => 2, 'name' => "Once {$marker}", 'mail' => $mail, 'status' => 'criar', 'motivo' => null],
            ];
            $draft = new users_imports_model();
            $draft->populate(['name' => 'once.csv', 'dados' => json_encode($rows)]);
            $draftIdx = (int) $draft->save();
            $this->assertGreaterThan(0, $draftIdx, 'Insert de fixture (draft) deve retornar um ID valido');

            $_SESSION['_csrf_token'] = 'tok-' . $marker;
            unset($_SESSION['_csrf_used']);

            // Primeira confirmacao: aplica de verdade (cria o usuario e marca imported_at).
            try {
                (new usersimports_controller())->action([
                    'post' => ['_csrf_token' => $_SESSION['_csrf_token'], 'action' => 'confirmar', 'idx' => $draftIdx],
                ]);
                $this->fail('action() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
            }

            // Forca transacao nova para a segunda chamada enxergar o commit da primeira.
            $this->resetSingleton();
            $_SESSION['_csrf_token'] = 'tok2-' . $marker;
            unset($_SESSION['_csrf_used']);

            try {
                (new usersimports_controller())->action([
                    'post' => ['_csrf_token' => $_SESSION['_csrf_token'], 'action' => 'confirmar', 'idx' => $draftIdx],
                ]);
                $this->fail('action() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertNotEmpty(
                    $_SESSION['messages_app']['success'] ?? [],
                    'Segunda confirmacao do mesmo idx deve ser no-op com mensagem de sucesso ("ja aplicado")'
                );
            }

            $check = new localPDO();
            $countStmt = $check->executePrepared("SELECT COUNT(*) as c FROM users WHERE mail = ?", [$mail]);
            $this->assertSame(1, (int)$countStmt->fetch(PDO::FETCH_ASSOC)['c'], 'Confirmar duas vezes nao deve duplicar a criacao do usuario');

            $userRow   = $check->executePrepared("SELECT idx FROM users WHERE mail = ?", [$mail])->fetch(PDO::FETCH_ASSOC);
            $createdId = $userRow ? (int)$userRow['idx'] : null;
        } finally {
            unset($_SESSION['_csrf_token'], $_SESSION['_csrf_used'], $_SESSION['messages_app']);
            $this->resetSingleton();
            $cleanup = new localPDO();
            if ($createdId) {
                $cleanup->executePrepared("DELETE FROM users_profiles WHERE users_id = ?", [$createdId]);
            }
            $cleanup->executePrepared("DELETE FROM messages WHERE to_mail = ?", [$mail]);
            $cleanup->executePrepared("DELETE FROM users WHERE mail = ?", [$mail]);
            if ($draftIdx) {
                $cleanup->executePrepared("DELETE FROM users_imports WHERE idx = ?", [$draftIdx]);
            }
        }
    }

    public function testActionConfirmarRollsBackEntireBatchOnError(): void
    {
        $GLOBALS['usersimports_url'] = constant('cFrontend') . 'importar-usuarios';
        $marker     = uniqid();
        $okMail     = "batch_ok_{$marker}@example.com";
        $dupMail    = "batch_dup_{$marker}@example.com";
        $existingId = null;
        $draftIdx   = null;

        $this->resetSingleton();
        try {
            // Fixture comitada ANTES de chamar action(): isola a transacao do
            // SUT da transacao de setup do teste, senao o rollback interno
            // de executePrepared() (localPDO.php:187-194) reverteria tambem
            // a propria fixture e o draft, tornando as asserções abaixo
            // impossiveis de distinguir causa e efeito.
            $existing = new users_model();
            $existing->populate(['name' => "dup_{$marker}", 'mail' => $dupMail, 'password' => password_hash('secret', PASSWORD_BCRYPT)]);
            $existingId = (int) $existing->save();
            $this->assertGreaterThan(0, $existingId);

            $rows = [
                ['row' => 2, 'name' => 'Ok',  'mail' => $okMail,  'status' => 'criar', 'motivo' => null],
                ['row' => 3, 'name' => 'Dup', 'mail' => $dupMail, 'status' => 'criar', 'motivo' => null],
            ];
            $draft = new users_imports_model();
            $draft->populate(['name' => 'lote.csv', 'dados' => json_encode($rows)]);
            $draftIdx = (int) $draft->save();
            $this->assertGreaterThan(0, $draftIdx);

            localPDO::getInstance()->commit();
            $this->resetSingleton();

            $_SESSION['_csrf_token'] = 'tok-' . $marker;
            unset($_SESSION['_csrf_used']);

            try {
                (new usersimports_controller())->action([
                    'post' => ['_csrf_token' => $_SESSION['_csrf_token'], 'action' => 'confirmar', 'idx' => $draftIdx],
                ]);
                $this->fail('action() deveria ter lancado TerminalResponse');
            } catch (TerminalResponse $e) {
                $this->assertSame(TerminalResponse::KIND_REDIRECT, $e->kind);
                $this->assertContains(
                    'Falha ao aplicar o import. Nenhuma alteração foi salva.',
                    $_SESSION['messages_app']['danger'] ?? [],
                    'Erro no meio do lote deve setar mensagem de danger'
                );
            }

            $check     = new localPDO();
            $countStmt = $check->executePrepared("SELECT COUNT(*) as c FROM users WHERE mail = ?", [$okMail]);
            $this->assertSame(0, (int)$countStmt->fetch(PDO::FETCH_ASSOC)['c'], 'Linha "criar" processada ANTES do erro deve ter sido revertida junto com o lote inteiro');

            $draftRow = $check->executePrepared("SELECT imported_at FROM users_imports WHERE idx = ?", [$draftIdx])->fetch(PDO::FETCH_ASSOC);
            $this->assertNotNull($draftRow, 'Draft comitado antes do teste deve continuar existindo');
            $this->assertNull($draftRow['imported_at'], 'imported_at NAO deve ser marcado quando o lote falha no meio');
        } finally {
            unset($_SESSION['_csrf_token'], $_SESSION['_csrf_used'], $_SESSION['messages_app']);
            $this->resetSingleton();
            $cleanup = new localPDO();
            $cleanup->executePrepared("DELETE FROM users WHERE mail IN (?, ?)", [$okMail, $dupMail]);
            if ($draftIdx) {
                $cleanup->executePrepared("DELETE FROM users_imports WHERE idx = ?", [$draftIdx]);
            }
        }
    }
}

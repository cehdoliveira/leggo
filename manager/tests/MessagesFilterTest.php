<?php

declare(strict_types=1);

/**
 * Cobre o filtro por destinatario usado por emails_controller::display()
 * (plano 042/007) — mesma forma de set_filter/execute_raw_prepared que o
 * controller usa, para garantir que o binding com `to_mail LIKE ?` funciona
 * como esperado.
 *
 * Tambem cobre, via reflection (metodo privado), a resolucao de formato
 * .json/.html de display() — mesmo padrao usado em ProfilesFilterTest e
 * UsersControllerTest.
 */
final class MessagesFilterTest extends DBTestCase
{
    private function makeMessage(string $toMail, string $subject = 'Assunto de teste'): int
    {
        $insert = new messages_model();
        $insert->populate([
            'to_mail' => $toMail,
            'subject' => $subject,
            'body'    => 'Corpo de teste',
        ]);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

        return $id;
    }

    /** Invoca um metodo privado de emails_controller para testar em isolamento. */
    private function callPrivate(string $method, array $args = []): mixed
    {
        $controller = new emails_controller();
        $ref        = new ReflectionMethod($controller, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($controller, $args);
    }

    public function testFilterByToMailReturnsOnlyMatchingRows(): void
    {
        $marker = uniqid();
        $this->makeMessage("alice_{$marker}_1@example.com");
        $this->makeMessage("alice_{$marker}_2@example.com");
        $this->makeMessage("bob_{$marker}@example.com");

        $like = '%' . addcslashes("alice_{$marker}", '\\%_') . '%';

        $model = new messages_model();
        $model->set_field([' idx ', ' to_mail ']);
        $model->set_filter([" active = 'yes' ", " to_mail LIKE ? "], [$like]);
        $model->set_order([' idx ASC ']);
        $model->load_data(false);

        $this->assertCount(2, $model->data, 'Filtro deve retornar apenas as 2 fixtures de alice');

        $countStmt = $model->execute_raw_prepared(
            "SELECT COUNT(*) AS total FROM messages WHERE active = 'yes' AND to_mail LIKE ?",
            [$like]
        );
        $total = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $this->assertSame(2, $total, 'COUNT com o mesmo filtro deve bater com o numero de linhas retornadas');
    }

    public function testFilterEscapesLikeWildcards(): void
    {
        $marker = uniqid();
        $toMail = "user_{$marker}@example.com";
        $this->makeMessage($toMail);

        // '%' sozinho, se NAO escapado, vira um curinga que casa com qualquer
        // string. Escapado (addcslashes), deve ser tratado como caractere
        // literal — e nenhuma fixture (sem '%' no to_mail) deve casar.
        $like = '%' . addcslashes('%', '\\%_') . '%';

        $model = new messages_model();
        $model->set_field([' idx ', ' to_mail ']);
        $model->set_filter([" active = 'yes' ", " to_mail LIKE ? "], [$like]);
        $model->set_order([' idx ASC ']);
        $model->load_data(false);

        $matched = array_column($model->data, 'to_mail');
        $this->assertNotContains($toMail, $matched, 'Um "%" literal escapado nao deve casar com um e-mail sem "%" no valor');
    }

    public function testRecordsetMatchesFilteredTotalWithoutManualCount(): void
    {
        $marker = uniqid();
        $this->makeMessage("carol_{$marker}_1@example.com");
        $this->makeMessage("carol_{$marker}_2@example.com");
        $this->makeMessage("carol_{$marker}_3@example.com");

        $like = '%' . addcslashes("carol_{$marker}", '\\%_') . '%';

        $model = new messages_model();
        $model->set_field([' idx ', ' to_mail ']);
        $model->set_filter([" active = 'yes' ", " to_mail LIKE ? "], [$like]);
        $model->set_order([' idx ASC ']);
        $model->set_paginate([0, 2]);
        $model->load_data();

        $this->assertCount(2, $model->data, 'A pagina traz 2 linhas por causa do LIMIT');
        $this->assertSame(3, (int) $model->get_recordset(), 'recordset ignora o LIMIT e conta o total filtrado');
    }

    public function testFilterBySubjectReturnsOnlyMatchingRows(): void
    {
        $marker = uniqid();
        $this->makeMessage("dave_{$marker}_1@example.com", "assunto_{$marker}_urgente");
        $this->makeMessage("dave_{$marker}_2@example.com", "assunto_{$marker}_urgente");
        $this->makeMessage("dave_{$marker}_3@example.com", "outro assunto qualquer");

        $like = '%' . addcslashes("assunto_{$marker}", '\\%_') . '%';

        $model = new messages_model();
        $model->set_field([' idx ', ' subject ']);
        $model->set_filter([" active = 'yes' ", " subject LIKE ? "], [$like]);
        $model->set_order([' idx ASC ']);
        $model->load_data(false);

        $this->assertCount(2, $model->data, 'Filtro por assunto deve retornar apenas as 2 fixtures marcadas');
    }

    public function testResolveFormatReturnsJsonForJsonSuffix(): void
    {
        $this->assertSame('.json', $this->callPrivate('resolve_format', [[1 => '.json']]));
    }

    public function testResolveFormatDefaultsToHtml(): void
    {
        $this->assertSame('.html', $this->callPrivate('resolve_format', [[]]));
        $this->assertSame('.html', $this->callPrivate('resolve_format', [[1 => '.html']]));
    }
}

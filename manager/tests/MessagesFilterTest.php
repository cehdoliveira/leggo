<?php

declare(strict_types=1);

/**
 * Cobre o filtro por destinatario usado por emails_controller::index()
 * (plano 042) — mesma forma de set_filter/execute_raw_prepared que o
 * controller usa, para garantir que o binding com `to_mail LIKE ?` funciona
 * como esperado.
 */
final class MessagesFilterTest extends DBTestCase
{
    private function makeMessage(string $toMail): int
    {
        $insert = new messages_model();
        $insert->populate([
            'to_mail' => $toMail,
            'subject' => 'Assunto de teste',
            'body'    => 'Corpo de teste',
        ]);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

        return $id;
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
}

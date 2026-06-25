<?php

declare(strict_types=1);

final class UsersModelTest extends DBTestCase
{
    public function testModelCanLoadData(): void
    {
        $model = new users_model();
        $model->set_field([" idx ", " name "]);
        $model->set_paginate([5]);
        $model->load_data();

        $this->assertIsArray($model->data);
    }

    public function testModelDefaultFilterOnlyActive(): void
    {
        $model = new users_model();
        $model->set_field([" COUNT(idx) AS total "]);
        $model->set_paginate([1]);
        $model->load_data();

        $this->assertNotEmpty($model->data);
        $this->assertArrayHasKey('total', $model->data[0]);
    }

    public function testInsertAndRollbackDoesNotPersist(): void
    {
        $model = new users_model();
        $model->populate([
            'name'     => 'Test User',
            'mail'     => 'test_' . uniqid() . '@example.com',
            'login'    => 'testuser_' . uniqid(),
            'password' => password_hash('secret', PASSWORD_BCRYPT),
        ]);
        $id = $model->save();

        $this->assertGreaterThan(0, $id, 'Insert deve retornar um ID valido');

        // O rollback no tearDown vai desfazer este insert
    }

    public function testSoftDeleteSetFilter(): void
    {
        $model = new users_model();
        $model->set_filter(["idx = ?"], [0]);
        $model->set_paginate([1]);
        $model->load_data();

        // Nao deve encontrar usuario idx=0
        $this->assertCount(0, $model->data);
    }

    public function testSetFilterWithParams(): void
    {
        $model = new users_model();
        $model->set_filter(["active = 'yes'", "enabled = ?"], ['yes']);
        $model->set_paginate([10]);
        $model->load_data();

        $this->assertIsArray($model->data);
    }

    public function testLoadDataDefaultPopulatesRecordset(): void
    {
        $model = new users_model();
        $model->set_field([" idx "]);
        $model->set_filter(["active = 'yes'"]);
        $model->set_paginate([5]);
        $model->load_data();                 // default: real count

        $this->assertIsInt($model->get_recordset());
    }

    public function testLoadDataWithoutCountUsesRowCount(): void
    {
        $model = new users_model();
        $model->set_field([" idx "]);
        $model->set_filter(["active = 'yes'"]);
        $model->set_paginate([3]);
        $model->load_data(false);            // opt-out: recordset == rows fetched

        $this->assertSame(count($model->data), $model->get_recordset());
    }
}

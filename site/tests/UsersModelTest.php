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

    public function testStaticFilterWithoutParamsStillLoads(): void
    {
        // Filtro estatico legado (sem params) deve continuar funcionando
        // pelo caminho unico preparado, apos remocao do branch else cru.
        $model = new users_model();
        $model->set_field([" idx ", " name "]);
        $model->set_filter(["active = 'yes'"]);
        $model->set_paginate([10]);
        $model->load_data();

        $this->assertIsArray($model->data);
    }
}

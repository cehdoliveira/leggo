<?php
class users_imports_model extends DOLModel
{
    protected array $field = [" idx ", " name ", " dados ", " imported_at ", " imported_by "];
    protected array $filter = [" active = 'yes' "];

    function __construct()
    {
        parent::__construct("users_imports");
    }
}

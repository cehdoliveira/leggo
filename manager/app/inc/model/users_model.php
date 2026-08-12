<?php
class users_model extends DOLModel
{
    protected array $field = [" idx ", " name ", " mail ", " login ", " slug "];
    protected array $filter = [" active = 'yes' "];

    function __construct()
    {
        parent::__construct("users");
    }
}

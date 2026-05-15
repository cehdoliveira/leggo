<?php
class users_model extends DOLModel
{
    protected $field = [" idx ", " name ", " mail ", " login "];
    protected $filter = [" active = 'yes' "];

    function __construct($bd = false)
    {
        return parent::__construct("users", $bd);
    }
}

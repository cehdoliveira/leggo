<?php
class capabilities_model extends DOLModel
{
    protected array $field = ["idx", "slug", "name"];
    protected array $filter = ["active = 'yes'"];

    function __construct()
    {
        parent::__construct("capabilities");
    }
}

<?php
class profiles_model extends DOLModel
{
    protected $field = ["idx", "name", "editabled", "slug", "adm", "parent"];
    protected $filter = ["active = 'yes'"];

    function __construct()
    {
        parent::__construct("profiles");
    }
}

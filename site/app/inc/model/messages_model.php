<?php
class messages_model extends DOLModel
{
    protected $filter = [" active = 'yes' "];

    function __construct()
    {
        return parent::__construct("messages");
    }
}

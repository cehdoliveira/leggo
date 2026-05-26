<?php
class messages_model extends DOLModel
{
    protected $filter = [" active = 'yes' "];

    function __construct()
    {
        parent::__construct("messages");
    }
}

<?php
class messages_model extends DOLModel
{
    protected array $filter = [" active = 'yes' "];

    function __construct()
    {
        parent::__construct("messages");
    }
}

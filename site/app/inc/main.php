<?php
include($_SERVER["DOCUMENT_ROOT"] . "/../app/inc/kernel.php");
require_once(constant("cRootServer_APP") . "/inc/lib/vendor/autoload.php");
require_once(constant("cRootServer_APP") . "/inc/lists.php");
require_once(constant("cRootServer_APP") . "/inc/lib/CommonFunctions.php");
require_once(constant("cRootServer_APP") . "/inc/urls.php");

if (empty($_SESSION['_csrf_token'])) {
	$_SESSION['_csrf_token'] = random_token();
}

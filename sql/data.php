<?php

require_once "DB.php";
require_once "../include/pear-database.php";

if(!ini_get('register_globals')){
	extract($HTTP_SERVER_VARS);
}

PEAR::setErrorHandling(PEAR_ERROR_CALLBACK, "error_handler");
list($progname, $type, $user, $pass, $db) = $argv;
$dbh = DB::connect("$type://$user:$pass@localhost/$db");
$me = getenv("USER");
$now = gmdate("Y-m-d H:i:s");

include "./addusers.php";
include "./addcategories.php";
include "./addpackages.php";
include "./addacls.php";

function error_handler($obj) {
	print "Error when adding users: ";
	print $obj->getMessage();
	print "\nMore info: ";
	print $obj->getUserInfo();
	print "\n";
	exit;
}

?>

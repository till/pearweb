--TEST--
auth_check() [no $auth_user]
--FILE--
<?php
// setup
require dirname(dirname(__FILE__)) . '/setup.php.inc';
$phpunit->assertFalse(auth_check('cellog', 'as if!'), 'test');
?>
===DONE===
--EXPECT--
===DONE===
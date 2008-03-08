<?php
require_once 'PEAR/PackageFileManager2.php';
PEAR::setErrorHandling(PEAR_ERROR_DIE);
$a = PEAR_PackageFileManager2::importOptions(
    dirname(__FILE__) . '/package_pepr.xml',
    array(
        'packagefile' => 'package_pepr.xml',
        'baseinstalldir' => '/',
        'filelistgenerator' => 'cvs',
        'roles' => array('*' => 'www'),
        'exceptions' => array('pearweb_pepr.php' => 'php'),
        'simpleoutput' => true,
        'include' => array(
            'cron/pepr.php',
            'public_html/pepr/',
            'include/pepr/',
            'include/Damblan/Search/Pepr.php',
            'sql/pearweb_pepr.xml',
        ),
    ));
$a->setReleaseVersion('1.0.0');
$a->setReleaseStability('stable');
$a->setAPIStability('stable');
$a->setNotes('
- split the pepr code from pearweb [dufuz]
');
$a->resetUsesrole();
$a->clearDeps();
$a->setPhpDep('5.2.3');
$a->setPearInstallerDep('1.7.1');
$a->addPackageDepWithChannel('required', 'PEAR', 'pear.php.net', '1.7.1');
$a->addPackageDepWithChannel('required', 'pearweb', 'pear.php.net', '1.18.0');
$a->addExtensionDep('required', 'pcre');
$a->addExtensionDep('required', 'mysqli');

$script = &$a->initPostinstallScript('pearweb_pepr.php');
$script->addParamGroup(
    'askdb',
    array(
        $script->getParam('yesno', 'Update pearweb database?', 'yesno', 'y'),
    )
    );
$script->addParamGroup(
    'init',
    array(
        $script->getParam('driver', 'Database driver', 'string', 'mysqli'),
        $script->getParam('user', 'Database User name', 'string', 'pear'),
        $script->getParam('password', 'Database password', 'password', 'pear'),
        $script->getParam('host', 'Database host', 'string', 'localhost'),
        $script->getParam('database', 'Database name', 'string', 'pear'),
    )
    );
$script->addParamGroup(
    'askhttpd',
    array(
        $script->getParam('yesno', 'Update httpd.conf to add pearweb? (y/n)', 'yesno', 'y'),
    )
    );
$script->addParamGroup(
    'httpdconf',
    array(
        $script->getParam('path', 'Full path to httpd.conf', 'string'),
    )
    );

$a->addPostinstallTask($script, 'pearweb_pepr.php');
$a->addReplacement('pearweb_pepr.php', 'pear-config', '@web-dir@', 'web_dir');
$a->addReplacement('pearweb_pepr.php', 'pear-config', '@php-dir@', 'php_dir');
$a->addReplacement('pearweb_pepr.php', 'package-info', '@version@', 'version');
$a->generateContents();
$a->writePackageFile();

if (isset($_SERVER['argv']) && @$_SERVER['argv'][1] == 'make') {
    $a->writePackageFile();
} else {
    $a->debugPackageFile();
}
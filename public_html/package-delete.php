<?php
/*
   +----------------------------------------------------------------------+
   | PEAR Web site version 1.0                                            |
   +----------------------------------------------------------------------+
   | Copyright (c) 2001-2003 The PHP Group                                |
   +----------------------------------------------------------------------+
   | This source file is subject to version 2.02 of the PHP license,      |
   | that is bundled with this package in the file LICENSE, and is        |
   | available at through the world-wide-web at                           |
   | http://www.php.net/license/2_02.txt.                                 |
   | If you did not receive a copy of the PHP license and are unable to   |
   | obtain it through the world-wide-web, please send a note to          |
   | license@php.net so we can mail you a copy immediately.               |
   +----------------------------------------------------------------------+
   | Authors:                                                             |
   +----------------------------------------------------------------------+
   $Id$
*/

/*
 * Interface to delete a package.
 */

response_header('Delete Package');
echo '<h1>Delete Package</h1>';

auth_require('pear.qa', 'pear.admin', 'pear.group');

if (!isset($_GET['id'])) {
    report_error('No package ID specified.');
    response_footer();
    exit;
}

require_once 'HTML/Form.php';
$form = new HTML_Form($_SERVER['PHP_SELF'] . '?id=' . $_GET['id'], 'POST');

if (!isset($_POST['confirm'])) {

    $bb = new Borderbox('Confirmation');

    $form->start();

    echo 'Are you sure that you want to delete the package?<br /><br />';
    $form->displaySubmit('yes', 'confirm');
    echo '&nbsp;';
    $form->displaySubmit('no', 'confirm');

    echo '<br /><br /><font color="#ff0000"><b>Warning:</b> Deleting
          the package will remove all package information and all
          releases!</font>';

    $form->end();

    $bb->end();

} else if ($_POST['confirm'] == 'yes') {

    // XXX: Implement backup functionality
    // make_backup($_GET['id']);

    $tables = array('releases'  => 'package', 
                    'maintains' => 'package',
                    'deps'      => 'package', 
                    'files'     => 'package',
                    'packages'  => 'id');

    echo "<pre>\n";

    $file_rm = 0;

    $query = "SELECT p.name, r.version FROM packages p, releases r
                WHERE p.id = r.package AND r.package = '" . $_GET['id'] . "'";

    $row = $dbh->getAll($query);

    foreach ($row as $value) {
        $file = sprintf("%s/%s-%s.tgz",
                        PEAR_TARBALL_DIR,
                        $value[0],
                        $value[1]);


        if (@unlink($file)) {
            echo "Deleting release archive \"" . $file . "\"\n";
            $file_rm++;
        } else {
            echo "<font color=\"#ff0000\">Unable to delete file " . $file . "</font>\n";
        }
    }

    echo "\n" . $file_rm . " file(s) deleted\n\n";

    $catid = package::info($_GET['id'], 'categoryid');
    $dbh->query("UPDATE categories SET npackages = npackages-1 WHERE id=$catid");

    foreach ($tables as $table => $field) {
        $query = sprintf("DELETE FROM %s WHERE %s = '%s'",
                         $table,
                         $field,
                         $_GET['id']
                         );

        echo "Removing package information from table \"" . $table . "\": ";
        $dbh->query($query);

        echo "<b>" . $dbh->affectedRows() . "</b> rows affected.\n";
    }

    echo "</pre>\nPackage " . $_GET['id'] . " has been deleted.\n";

} else if ($_POST['confirm'] == "no") {
    echo "The package has not been deleted.\n<br /><br />\n";
    echo 'Go back to the ' . make_link('/package/' . $_GET['id'], 'package details') . '.';
}

response_footer();
?>

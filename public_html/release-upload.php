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

auth_require("pear.dev");

define('HTML_FORM_MAX_FILE_SIZE', 16 * 1024 * 1024); // 16 MB
require_once "HTML/Form.php";
require_once "HTML/Table.php";

$display_form = true;
$display_verification = false;
$jumpto = false;

PEAR::pushErrorHandling(PEAR_ERROR_RETURN);
do {

    /** Upload Button **/
    if (isset($upload)) {
        include_once 'HTTP/Upload.php';
        $upload_obj = new HTTP_Upload('en');
        $file = $upload_obj->getFiles('distfile');
        if (PEAR::isError($file)) {
            display_error($file->getMessage()); break;
        }
        if ($file->isValid()) {
            $file->setName('uniq', 'pear-');
            $file->setValidExtensions('tgz', 'accept');
            $tmpfile = $file->moveTo(PEAR_UPLOAD_TMPDIR);
            if (PEAR::isError($tmpfile)) {
                display_error($tmpfile->getMessage()); break;
            }
            $tmpsize = $file->getProp('size');
        } elseif ($file->isMissing()) {
            display_error("No file has been uploaded."); break;
        } elseif ($file->isError()) {
            display_error($file->errorMsg()); break;
        }

        $display_form = false;
        $display_verification = true;

    /** Verify Button **/
    } elseif (isset($verify)) {
        $distfile = PEAR_UPLOAD_TMPDIR . '/' . basename($distfile);
        if (!@is_file($distfile)) {
            display_error("No verified file found."); break;
        }
        include_once "PEAR/Common.php";
        $util =& new PEAR_Common;
        $info = $util->infoFromTgzFile($distfile);

        $pacid = package::info($info['package'], 'id');
        if (PEAR::isError($pacid)) {
            display_error($pacid->getMessage()); break;
        }
        if (!user::isAdmin($_COOKIE['PEAR_USER']) &&
            !user::maintains($_COOKIE['PEAR_USER'], $pacid, "lead")) {
            display_error("You don't have permissions to upload this release."); break;
        }

        $e = package::updateInfo($pacid, array(
                                            'summary'     => $info['summary'],
                                            'description' => $info['description'],
                                            'license'     => $info['release_license']
                                              ));
        if (PEAR::isError($e)) {
            display_error($e->getMessage()); break;
        }
        $users = array();
        foreach ($info['maintainers'] as $user) {
            $users[strtolower($user['handle'])] = array("role" => $user['role'],
                                                        "active" => 1);
        }
        $e = maintainer::updateAll($pacid, $users);
        if (PEAR::isError($e)) {
            display_error($e->getMessage()); break;
        }
        $file = release::upload($info['package'], $info['version'], $info['release_state'],
                                $info['release_notes'], $distfile, md5_file($distfile));
        if (PEAR::isError($file)) {
            $ui = $file->getUserInfo();
            display_error("Error while uploading package: " .
                          $file->getMessage() . ($ui ? " ($ui)" : "") );
            break;
        }
        @unlink($distfile);
        PEAR::pushErrorHandling(PEAR_ERROR_PRINT, '<b>announce warnings: %s</b>');
        release::promote($info, $file);
        PEAR::popErrorHandling();
        response_header("Release Upload Finished");
        print "The release of package `" . $info['package'] . "' version `" . $info['version'] . "' ";
        print "has been completed successfully and the promoting cycle for it has started.<br /><br />";

        print '<center>'.
              make_link('/package/' . $info['package'], 'Visit package home') .
              '</center>';
        $display_form = $display_verification = false;

    /** Cancel Button **/
    } elseif (isset($cancel)) {

        $distfile = PEAR_UPLOAD_TMPDIR . '/' . basename($distfile);
        if (@is_file($distfile)) {
            @unlink($distfile);
        }
        header("Location: ". $_SERVER['PHP_SELF']); // XXX Better use HTTP::redirect() here.
        exit;
    }
} while (false);

PEAR::popErrorHandling();

if ($display_form) {
    $title = "Upload New Release";
    response_header($title);

    print "<h1>$title</h1>

Upload a new package distribution file built using `<code>pear
package</code>' here.  The information from your package.xml file will
be displayed on the next screen for verification. The maximum file size
is 16 MB.

<p />

Uploading new releases is restricted to each package's lead developer(s).

<p />

";

    // Remove that code when release-upload also create new packages
    if (!checkUser($auth_user->handle)) {
        display_error("You are not registered as lead developer for any packages.");
    }

    if (isset($errorMsg)) {
        print "<table>\n";
        print " <tr>\n";
        print "  <td>&nbsp;</td>\n";
        print "  <td><b>$errorMsg</b></td>\n";
        print " </tr>\n";
        print "</table>\n";
    }

    $bb = new BorderBox("Upload", "90%", "", 2, true);

    echo "<form enctype=\"multipart/form-data\" method=\"post\" action=\"" . $_SERVER['PHP_SELF'] . "\">\n";

    $form =& new HTML_Form($_SERVER['PHP_SELF'], 'POST');
    $bb->horizHeadRow("Distribution file", $form->returnFile("distfile"));
    $bb->fullRow($form->returnSubmit("Upload!", "upload"));

    echo "</form>\n";
    $bb->end();

    if ($jumpto) {
        print "\n<script language=\"JavaScript\">\n<!--\n";
        print "document.forms[1].$jumpto.focus();\n";
        print "// -->\n</script>\n";
    }
}

if ($display_verification) {
    include_once "PEAR/Common.php";
    response_header("Upload New Release: Verify");
    $util =& new PEAR_Common;
    // XXX this will leave files in PEAR_UPLOAD_TMPDIR if users don't
    // complete the next screen.  Janitor cron job recommended!
    $info = $util->infoFromTgzFile(PEAR_UPLOAD_TMPDIR . "/$tmpfile");
    // packge.xml conformance
    $util->validatePackageInfo($info, $errors, $warnings);
    if (count($errors)) {
        print '<h2>Fatal errors found:</h2>';
        print '(You should correct your package.xml file before you are able to continue.)';
        print '<ul>';
        foreach ($errors as $error) {
            print "<li><b>$error</b></li>\n";
        }
        print "</ul>";
    }
    if (count($warnings)) {
        print '<h2>Recommendations</h2>';
        print '(You may want to correct your package.xml file before you continue.)';
        print '<ul>';
        foreach ($warnings as $warning) {
            print "<li>$warning</li>\n";
        }
        print "</ul>";
    }
    $form =& new HTML_Form($_SERVER['PHP_SELF'], "POST");
    $form->addHidden('distfile', $tmpfile);
    // Don't show the next step button when errors found
    if (!count($errors)) {
        $form->addSubmit('verify', 'Verify Release');
    }
    $form->addSubmit('cancel', 'Cancel');

    // XXX ADD MASSIVE SANITY CHECKS HERE
    $bb = new BorderBox("Please verify that the following release ".
                        "information is correct:");
    print "<table border=\"0\" cellpadding=\"3\" cellspacing=\"0\">\n";
    print "<tr><th align=\"right\">Package:</th><td>$info[package]</td></tr>\n";
    print "<tr><th align=\"right\">Version:</th><td>$info[version]</td></tr>\n";

    foreach (array('summary', 'description', 'release_state', 'release_date', 'releases_notes') as $key) {
        if (!isset($info[$key])) {
            $info[$key] = "n/a";
        }
    }

    print "<tr><th align=\"right\" valign=\"top\">Summary:</th><td>" . htmlspecialchars($info['summary']) . "</td></tr>\n";
    print "<tr><th align=\"right\" valign=\"top\">Description:</th><td>" . nl2br(htmlspecialchars($info['description'])) . "</td></tr>\n";
    print "<tr><th align=\"right\" valign=\"top\">Release State:</th><td>" . $info['release_state'] . "</td></tr>\n";
    print "<tr><th align=\"right\" valign=\"top\">Release Date:</th><td>" . $info['release_date'] . "</td></tr>\n";
    print "<tr><th align=\"right\" valign=\"top\">Release Notes:</th><td>" . nl2br(htmlspecialchars($info['release_notes'])) . "</td></tr>\n";

    print "</table>\n";
    $form->display();
    $bb->end();
}

response_footer();

function display_error($msg)
{
    global $errorMsg;

    $errorMsg .= "<span class=\"error\">$msg</span><br />\n";
}

function checkUser($user, $pacid = null)
{
    global $dbh;
    $add = ($pacid) ? "AND p.id = " . $dbh->quote($pacid) : '';
    // It's a lead or user of the package
    $query = "SELECT m.handle
              FROM packages p, maintains m
              WHERE
                 m.handle = ? AND
                 p.id = m.package $add AND
                 (m.role IN ('lead', 'developer'))";
    $res = $dbh->getOne($query, array($user));
    if ($res !== null) {
        return true;
    }
    // Try to see if the user is an admin
    $res = user::isAdmin($user);
    return ($res === true);
}

?>

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
   | Authors: Stig S�ther Bakken <ssb@fast.no>                            |
   |          Tomas V.V.Cox <cox@php.net>                                 |
   |          Martin Jansen <mj@php.net>                                  |
   +----------------------------------------------------------------------+
   $Id$
*/

require_once 'DB/storage.php';
require_once 'PEAR/Common.php';
require_once 'HTTP.php';

// {{{ validate()

function validate($entity, $field, $value /* , $oldvalue, $object */) {
    switch ("$entity/$field") {
        case "users/handle":
            if (!preg_match('/^[a-z][a-z0-9]+$/i', $value)) {
                return false;
            }
            break;
        case "users/name":
            if (!$value) {
                return false;
            }
            break;
        case "users/email":
            if (!preg_match('/[a-z0-9_\.\+%]@[a-z0-9\.]+\.[a-z]+$', $email)) {
                return false;
            }
            break;
    }
    return true;
}

// }}}
// {{{ renumber_visitations()

/**
 *
 *
 * Some useful "visitation model" tricks:
 *
 * To find the number of child elements:
 *  (right - left - 1) / 2
 *
 * To find the number of child elements (including self):
 *  (right - left + 1) / 2
 *
 * To get all child nodes:
 *
 *  SELECT * FROM table WHERE left > <self.left> AND left < <self.right>
 *
 *
 * To get all child nodes, including self:
 *
 *  SELECT * FROM table WHERE left BETWEEN <self.left> AND <self.right>
 *  "ORDER BY left" gives tree view
 *
 * To get all leaf nodes:
 *
 *  SELECT * FROM table WHERE right-1 = left;
 */
function renumber_visitations($id, $parent = null)
{
    global $dbh;
    if ($parent === null) {
        $left = $dbh->getOne("select max(cat_right) + 1 from categories
                              where parent is null");
        $left = ($left !== null) ? $left : 1; // first node
    } else {
        $left = $dbh->getOne("select cat_right from categories where id = $parent");
    }
    $right = $left + 1;
    // update my self
    $err = $dbh->query("update categories
                        set cat_left = $left, cat_right = $right
                        where id = $id");
    if (PEAR::isError($err)) {
        return $err;
    }
    if ($parent === null) {
        return true;
    }
    $err = $dbh->query("update categories set cat_left = cat_left+2
                        where cat_left > $left");
    if (PEAR::isError($err)) {
        return $err;
    }
    // (cat_right >= $left) == update the parent but not the node itself
    $err = $dbh->query("update categories set cat_right = cat_right+2
                        where cat_right >= $left and id <> $id");
    if (PEAR::isError($err)) {
        return $err;
    }
    return true;
}

// }}}
// {{{ version_compare_firstelem()

function version_compare_firstelem($a, $b)
{
    reset($a);
    $elem = key($a);
    return version_compare($a[$elem], $b[$elem]);
}

// }}}

// These classes correspond to tables and methods define operations on
// each.  They are packaged into classes for easier xmlrpc
// integration.

/**
 * Class to handle categories
 *
 * @class   category
 * @package pearweb
 * @author  Stig S. Bakken <ssb@fast.no>
 * @author  Tomas V.V. Cox <cox@php.net>
 * @author  Martin Jansen <mj@php.net>
 * @author  Richard Heyes <richard@php.net>
 */
class category
{
    // {{{ *proto int    category::add(struct) API 1.0

    /**
     * Add new category
     *
     *    $data = array(
     *        'name'   => 'category name',
     *        'desc'   => 'category description',
     *        'parent' => 'category parent id'
     *        );
     *
     * @param array
     * @return mixed ID of the category or PEAR error object
     */
    function add($data)
    {
        global $dbh;
        $name = $data['name'];
        if (empty($name)) {
            return PEAR::raiseError('no name given');
        }
        $desc   = (empty($data['desc'])) ? 'none' : $data['desc'];
        $parent = (empty($data['parent'])) ? null : $data['parent'];

        $sql = 'INSERT INTO categories (id, name, description, parent)'.
             'VALUES (?, ?, ?, ?)';
        $id  = $dbh->nextId('categories');
        $err = $dbh->query($sql, array($id, $name, $desc, $parent));
        if (DB::isError($err)) {
            return $err;
        }
        $err = renumber_visitations($id, $parent);
        if (PEAR::isError($err)) {
            return $err;
        }
        return $id;
    }

    /**
     * Updates a categories details
     *
     * @param  integer $id   Category ID
     * @param  string  $name Category name
     * @param  string  $desc Category Description
     * @return mixed         True on success, pear_error otherwise
     */
    function update($id, $name, $desc = '')
    {
        return $GLOBALS['dbh']->query(sprintf('UPDATE categories SET name = %s, description = %s WHERE id = %d',
                                              $GLOBALS['dbh']->quote($name),
                                              $GLOBALS['dbh']->quote($desc),
                                              $id));
    }

    /**
     * Deletes a category
     *
     * @param integer $id Cateogry ID
     */
    function delete($id)
    {

        // if ($GLOBALS['dbh']->query('SELECT COUNT(*) FROM categories WHERE parent = ' . (int)$id) > 0) {
        //     return PEAR::raiseError('Cannot delete a category which has subcategories');
        // }
        //
        // // Get parent ID if any
        // $parentID = $GLOBALS['dbh']->getOne('SELECT parent FROM categories WHERE id = ' . $id);
        // if (!$parentID) {
        //     $nextID = $GLOBALS['dbh']->getOne('SELECT id FROM categories WHERE cat_left = ' . $GLOBALS['dbh']->getOne('SELECT cat_right + 1 FROM categories WHERE id = ' . $id));
        // } else {
        //     $nextID = $parentID;
        // }

        // Get parent ID if any
        $parentID = $GLOBALS['dbh']->getOne('SELECT parent FROM categories WHERE id = ' . $id);

        // Delete it
        $deleted_cat_left  = $GLOBALS['dbh']->getOne('SELECT cat_left FROM categories WHERE id = ' . $id);
        $deleted_cat_right = $GLOBALS['dbh']->getOne('SELECT cat_right FROM categories WHERE id = ' . $id);

        $GLOBALS['dbh']->query('DELETE FROM categories WHERE id = ' . $id);

        // Renumber
        $GLOBALS['dbh']->query('UPDATE categories SET cat_left = cat_left - 1, cat_right = cat_right - 1 WHERE cat_left > ' . $deleted_cat_left . ' AND cat_right < ' . $deleted_cat_right);
        $GLOBALS['dbh']->query('UPDATE categories SET cat_left = cat_left - 2, cat_right = cat_right - 2 WHERE cat_right > ' . $deleted_cat_right);

        // Update any child categories
        $GLOBALS['dbh']->query(sprintf('UPDATE categories SET parent = %s WHERE parent = %d', ($parentID ? $parentID : 'NULL'), $id));

        return true;
    }

    // }}}
    // {{{  proto array  category::listAll() API 1.0

    /**
     * List all categories
     *
     * @return array
     */
    function listAll()
    {
        global $dbh;
        return $dbh->getAll("SELECT * FROM categories ORDER BY name",
                            null, DB_FETCHMODE_ASSOC);
    }

    // }}}
    // {{{  proto array  category::getRecent(int, string) API 1.0

    /**
     * Get list of recent releases for the given category
     *
     * @param  int Number of releases to return
     * @param  string Name of the category
     * @return array
     */
    function getRecent($n, $category)
    {
        global $dbh;
        $recent = array();

        $query = "SELECT p.id AS id, " .
            "p.name AS name, " .
            "p.summary AS summary, " .
            "r.version AS version, " .
            "r.releasedate AS releasedate, " .
            "r.releasenotes AS releasenotes, " .
            "r.doneby AS doneby, " .
            "r.state AS state " .
            "FROM packages p, releases r, categories c " .
            "WHERE p.package_type = 'pear' AND p.id = r.package " .
            "AND p.category = c.id AND c.name = '" . $category . "'" .
            "ORDER BY r.releasedate DESC";

        $sth = $dbh->limitQuery($query, 0, $n);
        while ($sth->fetchInto($row, DB_FETCHMODE_ASSOC)) {
            $recent[] = $row;
        }
        return $recent;
    }

    // }}}
    // {{{ *proto bool   category::isValid(string) API 1.0

    /**
     * Determines if the given category is valid
     *
     * @access public
     * @param  string Name of the category
     * @return  boolean
     */
    function isValid($category)
    {
        global $dbh;
        $query = "SELECT id FROM categories WHERE name = ?";
        $sth = $dbh->query($query, array($category));
        return ($sth->numRows() > 0);
    }

    // }}}
}

/**
 * Class to handle packages
 *
 * @class   package
 * @package pearweb
 * @author  Stig S. Bakken <ssb@fast.no>
 * @author  Tomas V.V. Cox <cox@php.net>
 * @author  Martin Jansen <mj@php.net>
 */
class package
{
    // {{{ *proto int    package::add(struct) API 1.0

    /**
     * Add new package
     *
     * @param array
     * @return mixed ID of new package or PEAR error object
     */
    function add($data)
    {
        global $dbh, $auth_user;
        // name, category
        // license, summary, description
        // lead
        extract($data);
        if (empty($license)) {
            $license = "PEAR License";
        }
        if (!empty($category) && (int)$category == 0) {
            $category = $dbh->getOne("SELECT id FROM categories WHERE name = ?",
                                     array($category));
        }
        if (empty($category)) {
            return PEAR::raiseError("package::add: invalid `category' field");
        }
        if (empty($name)) {
            return PEAR::raiseError("package::add: invalid `name' field");
        }
        $query = "INSERT INTO packages (id,name,package_type,category,license,summary,description,homepage,cvs_link) VALUES(?,?,?,?,?,?,?,?,?)";
        $id = $dbh->nextId("packages");
        $err = $dbh->query($query, array($id, $name, $type, $category, $license, $summary, $description, $homepage, $cvs_link));
        if (DB::isError($err)) {
            return $err;
        }
        $sql = "UPDATE categories SET npackages = npackages + 1
                WHERE id = $category";
        if (DB::isError($err = $dbh->query($sql))) {
            return $err;
        }
        if (isset($lead) && DB::isError($err = maintainer::add($id, $lead, 'lead'))) {
            return $err;
        }

        $event = $auth_user->handle . " (" . $auth_user->name . ") has added a new package " . $name;
        $mailtext = $event . "\n\nApprove: http://pear.php.net/admin/package-approval.php?approve=" . $id;
        $mailtext .= "\nReject: http://pear.php.net/admin/package-approval.php?reject=" . $id;

        // {{{ Logging mechanism
        require_once "Damblan/Log.php";
        require_once "Damblan/Log/Mail.php";

        // Syslog
        $logger = new Damblan_Log;
        $logger->log($event);

        // Logging via email
        $logger = new Damblan_Log_Mail;
        $logger->setRecipients("pear-group@php.net");
        $logger->setHeader("From", $auth_user->email);
        $logger->setHeader("Message-Id", "<approve-request-" . $id . "@pear.php.net>");
        $logger->setHeader("Subject", "New package");
        $logger->log($mailtext);
        // }}}

        return $id;
    }

    // }}}
    // {{{ proto array package::getDownloadURL(struct, [string], [string], [string]) API 1.0

    /**
     * Get a download URL, or an array containing the latest version and its
     * release info.
     *
     * If a bundle is specified, then an array of information from getDownloadURL()
     * will be returned
     * @param array an array in format:
     *              array(
     *                'channel' => channel name (not used in pear),
     *                'package' => package name,
     *                ['version' => specific version to retrieve,]
     *                ['state' => specific state to retrieve,]
     *                ['bundle' => specific bundle to retrieve,]
     *              )
     * @param string preferred_state configuration value
     * @param string user IP address, used to retrieve the closest mirror
     *               (not implemented)
     * @param string preferred mirror
     *               (not implemented)
     * @return bool|array
     */
    function getDownloadURL($packageinfo, $prefstate = 'stable', $loc = null, $mirror = null)
    {
        if (!isset($packageinfo['package'])) {
            return PEAR::raiseError('getDownloadURL parameter $packageinfo must ' .
                'contain a "package" index');
        }
        if (isset($packageinfo['channel']) && $packageinfo['channel'] != 'pear.php.net') {
            return PEAR::raiseError('getDownloadURL channel must be pear.php.net');
        }
        $states = release::betterStates($prefstate, true);
        if (!$states) {
            return PEAR::raiseError("getDownloadURL: preferred state '$prefstate' " .
                'is not a valid stability state');
        }
        $package = $packageinfo['package'];
        $state = $version = null;
        if (isset($packageinfo['state'])) {
            $state = $packageinfo['state'];
        }
        if (isset($packageinfo['version'])) {
            $version = $packageinfo['version'];
        }
        $info = package::info($package); // get deps too
        $info = $info['releases'];
        if (!count($info)) {
            return false;
        }
        $found = false;
        $release = false;
        foreach ($info as $ver => $release) {
            if (isset($state)) {
                if ($release['state'] == $state) {
                    $found = true;
                    break;
                }
            } elseif (isset($version)) {
                if ($ver == $version) {
                    $found = true;
                    break;
                }
            } else {
                if (in_array($release['state'], $states)) {
                    $found = true;
                    break;
                }
            }
        }
        if ($found) {
            $release['package'] = $packageinfo['package'];
            $release['channel'] = 'pear.php.net';
            if (isset($release['deps'])) {
                foreach ($release['deps'] as $i => $dep) {
                    $dep['optional'] = $dep['optional'] ? 'yes' : 'no';
                    $dep['rel'] = $dep['relation'];
                    unset($dep['relation']);
                    $release['deps'][$i] = $dep;
                }
            }
            return 
                array('version' => $ver,
                      'info' => $release, 
                      'url' => 'http://' . $_SERVER['SERVER_NAME'] . '/get/' .
                               $package . '-' . $ver);
        } else {
            reset($info);
            list($ver, $release) = each($info);
            $release['package'] = $packageinfo['package'];
            $release['channel'] = 'pear.php.net';
            if (isset($release['deps'])) {
                foreach ($release['deps'] as $i => $dep) {
                    $dep['optional'] = $dep['optional'] ? 'yes' : 'no';
                    $dep['rel'] = $dep['relation'];
                    unset($dep['relation']);
                    $release['deps'][$i] = $dep;
                }
            }
            return array('version' => $ver,
                         'info' => $release);
        }
    }

    // }}}
    // {{{ proto array package::getDepDownloadURL(string, struct, struct, [string], [string], [string]) API 1.0

    /**
     * Get a download URL for a dependency, or an array containing the
     * latest version and its release info.
     *
     * If a bundle is specified, then an array of information
     * will be returned
     * @param string package.xml version for the dependency (1.0 or 2.0)
     * @param array dependency information
     * @param array dependent package information
     * @param string preferred state
     * @param string version_compare() relation to use for checking version
     * @param string user IP address, used to retrieve the closest mirror
     *               (not implemented)
     * @param string preferred mirror
     *               (not implemented)
     * @return bool|array
     */
    function getDepDownloadURL($xsdversion, $dependency, $deppackage,
                               $prefstate = 'stable', $loc = null, $mirror = null)
    {
        $info = package::info($dependency['name']);
        $info = $info['releases'];
        if (!count($info)) {
            return false;
        }
        $states = release::betterStates($prefstate, true);
        if (!$states) {
            return PEAR::raiseError("getDownloadURL: preferred state '$prefstate' " .
                'is not a valid stability state');
        }
        $exclude = array();
        $min = $max = $recommended = false;
        if ($xsdversion == '1.0') {
            $pinfo['package'] = $dependency['name'];
            $pinfo['channel'] = 'pear.php.net';
            switch ($dependency['rel']) {
                case 'ge' :
                    $min = $dependency['version'];
                break;
                case 'gt' :
                    $min = $dependency['version'];
                    $exclude = array($dependency['version']);
                break;
                case 'eq' :
                    $recommended = $dependency['version'];
                break;
                case 'lt' :
                    $max = $dependency['version'];
                    $exclude = array($dependency['version']);
                break;
                case 'le' :
                    $max = $dependency['version'];
                break;
                case 'not' :
                    $exclude = array($dependency['version']);
                break;
            }
        } elseif ($xsdversion == '2.0') {
            $pinfo['package'] = $dependency['name'];
            if ($dependency['channel'] != 'pear.php.net') {
                return PEAR::raiseError('getDepDownloadURL channel must be pear.php.net');
            }
            $min = isset($dependency['min']) ? $dependency['min'] : false;
            $max = isset($dependency['max']) ? $dependency['max'] : false;
            $recommended = isset($dependency['recommended']) ?
                $dependency['recommended'] : false;
            if (isset($dependency['exclude'])) {
                if (!isset($dependency['exclude'][0])) {
                    $exclude = array($dependency['exclude']);
                }
            }
        }
        $found = false;
        $release = false;
        foreach ($info as $ver => $release) {
            if (in_array($ver, $exclude)) { // skip excluded versions
                continue;
            }
            // allow newer releases to say "I'm OK with the dependent package"
            if ($xsdversion == '2.0' && isset($release['compatibility'])) {
                if (isset($release['compatibility'][$deppackage['channel']]
                      [$deppackage['package']]) && in_array($ver,
                        $release['compatibility'][$deppackage['channel']]
                        [$deppackage['package']])) {
                    $recommended = $ver;
                }
            }
            if ($recommended) {
                if ($ver != $recommended) { // if we want a specific
                    // version, then skip all others
                    continue;
                } else {
                    if (!in_array($release['state'], $states)) {
                        $release['package'] = $dependency['name'];
                        $release['channel'] = 'pear.php.net';
                        if (isset($release['deps'])) {
                            foreach ($release['deps'] as $i => $dep) {
                                $dep['optional'] = $dep['optional'] ? 'yes' : 'no';
                                $dep['rel'] = $dep['relation'];
                                unset($dep['relation']);
                                $release['deps'][$i] = $dep;
                            }
                        }
                        // the stability is too low, but we must return the
                        // recommended version if possible
                        return array('version' => $ver,
                                     'info' => $release);
                    }
                }
            }
            if ($min && version_compare($ver, $min, 'lt')) { // skip too old versions
                continue;
            }
            if ($max && version_compare($ver, $max, 'gt')) { // skip too new versions
                continue;
            }
            if (in_array($release['state'], $states)) { // if in the preferred state...
                $found = true; // ... then use it
                break;
            }
        }
        if ($found) {
            $release['package'] = $dependency['name'];
            $release['channel'] = 'pear.php.net';
            if (isset($release['deps'])) {
                foreach ($release['deps'] as $i => $dep) {
                    $dep['optional'] = $dep['optional'] ? 'yes' : 'no';
                    $dep['rel'] = $dep['relation'];
                    unset($dep['relation']);
                    $release['deps'][$i] = $dep;
                }
            }
            return
                array('version' => $ver,
                      'info' => $release, 
                      'url' => 'http://' . $_SERVER['SERVER_NAME'] . '/get/' .
                               $pinfo['package'] . '-' . $ver);
        } else {
            reset($info);
            list($ver, $release) = each($info);
            $release['package'] = $dependency['name'];
            $release['channel'] = 'pear.php.net';
            if (isset($release['deps'])) {
                foreach ($release['deps'] as $i => $dep) {
                    $dep['optional'] = $dep['optional'] ? 'yes' : 'no';
                    $dep['rel'] = $dep['relation'];
                    unset($dep['relation']);
                    $release['deps'][$i] = $dep;
                }
            }
            return array('version' => $ver,
                         'info' => $release);
        }
    }

    // }}}

    // {{{  proto struct package::optionalGroups(string, string) API 1.0

    /**
     * @param array array containing indexes "package" and "group" to determine which
     *              package and which optional dependency group to retrieve
     * @param string version to retrieve bundles from
     */
    function optionalGroups($package, $version)
    {
        // implement this later
        return array();
    }

    // }}}

    // {{{  proto struct package::info(string|int, [string], [bool]) API 1.0
    /*
     * Implemented $field values:
     * releases, notes, category, description, authors, categoryid,
     * packageid, authors
     */

    /**
     * Get package information
     *
     * @static
     * @param  mixed   Name of the package or it's ID
     * @param  string  Single field to fetch
     * @param  boolean Should PECL packages also be taken into account?
     * @return mixed
     */
    function info($pkg, $field = null, $allow_pecl = false)
    {
        global $dbh;

        if (is_numeric($pkg)) {
            $what = "id";
        } else {
            $what = "name";
        }

        if ($allow_pecl) {
             $package_type = "((p.package_type = 'pear' AND p.approved = 1) OR p.package_type = 'pecl') AND ";
        } else {
             $package_type = "p.package_type = 'pear' AND p.approved = 1 AND ";
        }
        $pkg_sql = "SELECT p.id AS packageid, p.name AS name, ".
             "p.package_type AS type, ".
             "c.id AS categoryid, c.name AS category, ".
             "p.stablerelease AS stable, p.license AS license, ".
             "p.summary AS summary, p.homepage AS homepage, ".
             "p.description AS description, p.cvs_link AS cvs_link, ".
             "p.doc_link as doc_link".
             " FROM packages p, categories c ".
             "WHERE " . $package_type . " c.id = p.category AND p.{$what} = ?";
        $rel_sql = "SELECT version, id, doneby, license, summary, ".
             "description, releasedate, releasenotes, state " . //, packagexmlversion ".
             "FROM releases ".
             "WHERE package = ? ".
             "ORDER BY releasedate DESC";
        $notes_sql = "SELECT id, nby, ntime, note FROM notes WHERE pid = ?";
        $deps_sql = "SELECT type, relation, version, name, release, optional
                     FROM deps
                     WHERE package = ? ORDER BY optional ASC";
        if ($field === null) {
            $info =
                 $dbh->getRow($pkg_sql, array($pkg), DB_FETCHMODE_ASSOC);
            $info['releases'] =
                 $dbh->getAssoc($rel_sql, false, array($info['packageid']),
                 DB_FETCHMODE_ASSOC);
            $rels = sizeof($info['releases']) ? array_keys($info['releases']) : array('');
            $info['stable'] = $rels[0];
            $info['notes'] =
                 $dbh->getAssoc($notes_sql, false, array(@$info['packageid']),
                 DB_FETCHMODE_ASSOC);
            $deps =
                 $dbh->getAll($deps_sql, array(@$info['packageid']),
                 DB_FETCHMODE_ASSOC);
            foreach($deps as $dep) {
                $rel_version = null;
                foreach($info['releases'] as $version => $rel) {
                    if ($rel['id'] == $dep['release']) {
                        $rel_version = $version;
                        break;
                    };
                };
                if ($rel_version !== null) {
                    unset($dep['release']);
                    $info['releases'][$rel_version]['deps'][] = $dep;
                };
            };
        } else {
            // get a single field
            if ($field == 'releases' || $field == 'notes') {
                if ($what == "name") {
                    $pid = $dbh->getOne("SELECT p.id FROM packages p ".
                                        "WHERE " . $package_type . " p.name = ?", array($pkg));
                } else {
                    $pid = $pkg;
                }
                if ($field == 'releases') {
                    $info = $dbh->getAssoc($rel_sql, false, array($pid),
                    DB_FETCHMODE_ASSOC);
                } elseif ($field == 'notes') {
                    $info = $dbh->getAssoc($notes_sql, false, array($pid),
                    DB_FETCHMODE_ASSOC);
                }
            } elseif ($field == 'category') {
                $sql = "SELECT c.name FROM categories c, packages p ".
                     "WHERE c.id = p.category AND " . $package_type . " p.{$what} = ?";
                $info = $dbh->getAssoc($sql, false, array($pkg));
            } elseif ($field == 'description') {
                $sql = "SELECT description FROM packages p WHERE " . $package_type . " p.{$what} = ?";
                $info = $dbh->query($sql, array($pkg));
            } elseif ($field == 'authors') {
                $sql = "SELECT u.handle, u.name, u.email, u.showemail, m.role
                        FROM maintains m, users u, packages p
                        WHERE " . $package_type ." m.package = p.id
                        AND p.$what = ?
                        AND m.handle = u.handle";
                $info = $dbh->getAll($sql, array($pkg), DB_FETCHMODE_ASSOC);
            } else {
                if ($field == 'categoryid') {
                    $dbfield = 'category';
                } elseif ($field == 'packageid') {
                    $dbfield = 'id';
                } else {
                    $dbfield = $field;
                }
                $sql = "SELECT $dbfield FROM packages p WHERE " . $package_type ." p.{$what} = ?";
                $info = $dbh->getOne($sql, array($pkg));
            }
        }
        return $info;
    }

    // }}}
    // {{{  proto struct package::listAll([bool], [bool], [bool]) API 1.0

    /**
     * List all packages
     *
     * @static
     * @param boolean Only list released packages?
     * @param boolean If listing released packages only, only list stable releases?
     * @return array
     */
    function listAll($released_only = true, $stable_only = true, $include_pecl=false)
    {
        global $dbh, $HTTP_RAW_POST_DATA;

        if (isset($HTTP_RAW_POST_DATA)) {
            $include_pecl = true;
        }
        if ($include_pecl) {
            $package_type = "";
        } else {
            $package_type = "p.package_type = 'pear' AND p.approved = 1 AND ";
        }

        $packageinfo = $dbh->getAssoc("SELECT p.name, p.id AS packageid, ".
            "c.id AS categoryid, c.name AS category, ".
            "p.license AS license, ".
            "p.summary AS summary, ".
            "p.description AS description, ".
            "m.handle AS lead ".
            " FROM packages p, categories c, maintains m ".
            "WHERE " . $package_type .
            " c.id = p.category ".
            "  AND p.id = m.package ".
            "  AND m.role = 'lead' ".
            "ORDER BY p.name", false, null, DB_FETCHMODE_ASSOC);
        $allreleases = $dbh->getAssoc(
            "SELECT p.name, r.id AS rid, r.version AS stable, r.state AS state ".
            "FROM packages p, releases r ".
            "WHERE " . $package_type .
            "p.id = r.package ".
            "ORDER BY r.releasedate ASC ", false, null, DB_FETCHMODE_ASSOC);
        $stablereleases = $dbh->getAssoc(
            "SELECT p.name, r.id AS rid, r.version AS stable, r.state AS state ".
            "FROM packages p, releases r ".
            "WHERE " . $package_type .
            "p.id = r.package ".
            ($released_only ? "AND r.state = 'stable' " : "").
            "ORDER BY r.releasedate ASC ", false, null, DB_FETCHMODE_ASSOC);
        $deps = $dbh->getAll(
            "SELECT package, release , type, relation, version, name ".
            "FROM deps", null, DB_FETCHMODE_ASSOC);
        foreach ($packageinfo as $pkg => $info) {
            $packageinfo[$pkg]['stable'] = false;
        }
        foreach ($stablereleases as $pkg => $stable) {
            $packageinfo[$pkg]['stable'] = $stable['stable'];
            $packageinfo[$pkg]['unstable'] = false;
            $packageinfo[$pkg]['state']  = $stable['state'];
        }
        if (!$stable_only) {
            foreach ($allreleases as $pkg => $stable) {
                if ($stable['state'] == 'stable') {
                    $packageinfo[$pkg]['stable'] = $stable['stable'];
                } else {
                    $packageinfo[$pkg]['unstable'] = $stable['stable'];
                }
                $packageinfo[$pkg]['state']  = $stable['state'];
            }
        }
        $var = !$stable_only ? 'allreleases' : 'stablereleases';
        foreach(array_keys($packageinfo) as $pkg) {
            $_deps = array();
            foreach($deps as $dep) {
                if ($dep['package'] == $packageinfo[$pkg]['packageid']
                    && isset($$var[$pkg])
                    && $dep['release'] == $$var[$pkg]['rid'])
                {
                    unset($dep['rid']);
                    unset($dep['release']);
                    if ($dep['type'] == 'pkg' && isset($packageinfo[$dep['name']])) {
                        $dep['package'] = $packageinfo[$dep['name']]['packageid'];
                    } else {
                        $dep['package'] = 0;
                    }
                    $_deps[] = $dep;
                };
            };
            $packageinfo[$pkg]['deps'] = $_deps;
        };

        if ($released_only) {
            if (!$stable_only) {
                foreach ($packageinfo as $pkg => $info) {
                    if (!isset($allreleases[$pkg]) && !isset($stablereleases[$pkg])) {
                        unset($packageinfo[$pkg]);
                    }
                }
            } else {
                foreach ($packageinfo as $pkg => $info) {
                    if (!isset($stablereleases[$pkg])) {
                        unset($packageinfo[$pkg]);
                    }
                }
            }
        }
        return $packageinfo;
    }

    // }}}
    // {{{  proto struct package::listAllwithReleases() API 1.0

    /**
     * Get list of packages and their releases
     *
     * @access public
     * @return array
     * @static
     */
    function listAllwithReleases()
    {
        global $dbh;

        $query = "SELECT p.id AS pid, p.name, r.id AS rid, r.version, r.state FROM packages p, releases r WHERE p.package_type = 'pear' AND p.approved = 1 AND p.id = r.package ORDER BY p.name, r.version DESC";
        $sth = $dbh->query($query);

        if (DB::isError($sth)) {
            return $sth;
        }

        while ($row = $sth->fetchRow(DB_FETCHMODE_ASSOC)) {
            $packages[$row['pid']]['name'] = $row['name'];
            $packages[$row['pid']]['releases'][] = array('id' => $row['rid'],
                                                         'version' => $row['version'],
                                                         'state' => $row['state']
                                                         );
        }

        return $packages;
    }

    // }}}
    // {{{  proto struct package::listLatestReleases([string]) API 1.0

    /**
     * List latest releases
     *
     * @static
     * @param  string Only list release with specific state (Optional)
     * @return array
     */
    function listLatestReleases($state = '')
    {
        global $dbh;
        $query =
             "SELECT ".
             "p.name AS package, ".
             "r.version AS version, ".
             "r.state AS state, ".
             "f.fullpath AS fullpath ".
             "FROM packages p, releases r, files f ".
             "WHERE p.package_type = 'pear' AND p.approved = 1 AND p.id = r.package ".
             "AND f.package = p.id ".
             "AND f.release = r.id";
        if (release::isValidState($state)) {
            $better = release::betterStates($state);
            $query .= " AND (r.state = '$state'";
            $i = 0;
            if (is_array($better)) {
                foreach ($better as $b) {
                    $query .= " OR r.state = '$b'";
                }
            }
            $query .= ")";
        }
        $query .= " ORDER BY p.name";
        $sortfunc = "version_compare_firstelem";
        $res = $dbh->getAssoc($query, false, null, DB_FETCHMODE_ASSOC, true);
        foreach ($res as $pkg => $ver) {
            if (sizeof($ver) > 1) {
                usort($ver, $sortfunc);
            }
            $res[$pkg] = array_pop($ver);
            $res[$pkg]['filesize'] = (int)@filesize($res[$pkg]['fullpath']);
            unset($res[$pkg]['fullpath']);
        }
        return $res;
    }

    // }}}
    // {{{  proto struct package::listUpgrades(struct) API 1.0

    /**
     * List available upgrades
     *
     * @static
     * @param array Array containing the currently installed packages
     * @return array
     */
    function listUpgrades($currently_installed)
    {
        global $dbh;
        if (sizeof($currently_installed) == 0) {
            return array();
        }
        $query = "SELECT ".
             "p.name AS package, ".
             "r.id AS releaseid, ".
             "r.package AS packageid, ".
             "r.version AS version, ".
             "r.state AS state, ".
             "r.doneby AS doneby, ".
             "r.license AS license, ".
             "r.summary AS summary, ".
             "r.description AS description, ".
             "r.releasedate AS releasedate, ".
             "r.releasenotes AS releasenotes ".
             "FROM releases r, packages p WHERE p.package_type = 'pear' AND p.approved = 1 AND r.package = p.id AND (";
        $conditions = array();
        foreach ($currently_installed as $package => $info) {
            extract($info); // state, version
            $conditions[] = "(package = '$package' AND state = '$state')";
        }
        $query .= implode(" OR ", $conditions) . ")";
        return $dbh->getAssoc($query, false, null, DB_FETCHMODE_ASSOC);
    }

    // }}}
    // {{{ +proto bool   package::updateInfo(string|int, struct) API 1.0

    /**
     * Updates fields of an existant package
     *
     * @param int $pkgid The package ID to update
     * @param array $data Assoc in the form 'field' => 'value'.
     * @return mixed True or PEAR_Error
     */
    function updateInfo($pkgid, $data)
    {
        global $dbh, $auth_user;
        $package_id = package::info($pkgid, 'id');
        if (PEAR::isError($package_id) || empty($package_id)) {
            return PEAR::raiseError("Package not registered or not approved. Please register it first with \"New Package\" or wait until it gets approved.");
        }
        if ($auth_user->isAdmin() == false) {
            $role = user::maintains($auth_user->handle, $package_id);
            if ($role != 'lead' && $role != 'developer') {
                return PEAR::raiseError('package::updateInfo: insufficient privileges');
            }
        }
        // XXX (cox) what about 'name'?
        $allowed = array('license', 'summary', 'description', 'category');
        $fields = $prep = array();
        foreach ($allowed as $a) {
            if (isset($data[$a])) {
                $fields[] = "$a = ?";
                $prep[]   = $data[$a];
            }
        }
        if (!count($fields)) {
            return;
        }
        $sql = 'UPDATE packages SET ' . implode(', ', $fields) .
               " WHERE id=$package_id";
        return $dbh->query($sql, $prep);
    }

    // }}}
    // {{{ getDependants()

    /**
     * Get packages that depend on the given package
     *
     * @param  string Name of the package
     * @return array  List of package that depend on $package
     */
    function getDependants($package) {
        global $dbh;
        $query = 'SELECT p.name AS p_name, ' .
            ' MAX(r.version) AS max_dep, ' .
            ' MAX(rm.version) as max_pkg ' .
            'FROM deps d, packages p ' .
            '  LEFT JOIN releases AS r ON (r.id = d.release) ' . 
            '  LEFT JOIN releases AS rm ON (rm.package = d.package) ' . 
            "WHERE d.package = p.id AND d.type = 'pkg' " .
            "      AND d.name = ? " .
            "GROUP BY d.package";
        return $dbh->getAll($query, array($package), DB_FETCHMODE_ASSOC);
    }

    // }}}
    // {{{  proto array  package::getRecent(int, string) API 1.0

    /**
     * Get list of recent releases for the given package
     *
     * @param  int Number of releases to return
     * @param  string Name of the package
     * @return array
     */
    function getRecent($n, $package)
    {
        global $dbh;
        $recent = array();

        $query = "SELECT p.id AS id, " .
            "p.name AS name, " .
            "p.summary AS summary, " .
            "r.version AS version, " .
            "r.releasedate AS releasedate, " .
            "r.releasenotes AS releasenotes, " .
            "r.doneby AS doneby, " .
            "r.state AS state " .
            "FROM packages p, releases r " .
            "WHERE p.package_type = 'pear' AND p.approved = 1 AND p.id = r.package " .
            "AND p.name = '" . $package . "'" .
            "ORDER BY r.releasedate DESC";

        $sth = $dbh->limitQuery($query, 0, $n);
        while ($sth->fetchInto($row, DB_FETCHMODE_ASSOC)) {
            $recent[] = $row;
        }
        return $recent;
    }

    // }}}
    // {{{ *proto bool   package::isValid(string) API 1.0

    /**
     * Determines if the given package is valid
     *
     * @access public
     * @param  string Name of the package
     * @return  boolean
     */
    function isValid($package)
    {
        global $dbh;
        $query = "SELECT id FROM packages WHERE package_type = 'pear' AND approved = 1 AND name = ?";
        $sth = $dbh->query($query, array($package));
        return ($sth->numRows() > 0);
    }

    // }}}
    // {{{ getNotes()

    /**
     * Get all notes for given package
     *
     * @access public
     * @param  int ID of the package
     * @return array
     */
    function getNotes($package)
    {
        global $dbh;

        $query = "SELECT * FROM notes WHERE pid = ? ORDER BY ntime";
        return $dbh->getAll($query, array($package), DB_FETCHMODE_ASSOC);
    }

    // }}}
}

/**
 * Class to handle maintainers
 *
 * @class   maintainer
 * @package pearweb
 * @author  Stig S. Bakken <ssb@fast.no>
 * @author  Tomas V.V. Cox <cox@php.net>
 * @author  Martin Jansen <mj@php.net>
 */
class maintainer
{
    // {{{ +proto int    maintainer::add(int|string, string, string) API 1.0

    /**
     * Add new maintainer
     *
     * @static
     * @param  mixed  Name of the package or it's ID
     * @param  string Handle of the user
     * @param  string Role of the user
     * @param  integer Is the developer actively working on the project?
     * @return mixed True or PEAR error object
     */
    function add($package, $user, $role, $active = 1)
    {
        global $dbh, $auth_user;

        if (!user::exists($user)) {
            return PEAR::raiseError("User $user does not exist");
        }
        if (is_string($package)) {
            $package = package::info($package, 'id');
        }
        $err = $dbh->query("INSERT INTO maintains VALUES(?,?,?,?)",
                           array($user, $package, $role, $active));
        if (DB::isError($err)) {
            return $err;
        }
        return true;
    }

    // }}}
    // {{{  proto struct maintainer::get(int|string, [bool]) API 1.0

    /**
     * Get maintainer(s) for package
     *
     * @static
     * @param  mixed Name of the package or it's ID
     * @param  boolean Only return lead maintainers?
     * @return array
     */
    function get($package, $lead = false)
    {
        global $dbh;
        if (is_string($package)) {
            $package = package::info($package, 'id');
        }
        $query = "SELECT handle, role, active FROM maintains WHERE package = ?";
        if ($lead) {
            $query .= " AND role = 'lead'";
        }
        $query .= " ORDER BY active DESC";

        return $dbh->getAssoc($query, true, array($package), DB_FETCHMODE_ASSOC);
    }

    // }}}
    // {{{  proto struct maintainer::getByUser(string) API 1.0

    /**
     * Get the roles of a specific user
     *
     * @static
     * @param  string Handle of the user
     * @return array
     */
    function getByUser($user)
    {
        global $dbh;
        $query = 'SELECT p.name, m.role FROM packages p, maintains m WHERE p.package_type = \'pear\' AND p.approved = 1 AND m.package = p.id AND m.handle = ?';
        return $dbh->getAssoc($query, false, array($user));
    }

    // }}}
    // {{{  proto bool   maintainer::isValidRole(string) API 1.0

    /**
     * Check if role is valid
     *
     * @static
     * @param string Name of the role
     * @return boolean
     */
    function isValidRole($role)
    {
        static $roles;
        if (empty($roles)) {
            $roles = PEAR_Common::getUserRoles();
        }
        return in_array($role, $roles);
    }

    // }}}
    // {{{ +proto bool   maintainer::remove(int|string, string) API 1.0

    /**
     * Remove user from package
     *
     * @static
     * @param  mixed Name of the package or it's ID
     * @param  string Handle of the user
     * @return True or PEAR error object
     */
    function remove($package, $user)
    {
        global $dbh, $auth_user;
        if (!$auth_user->isAdmin() && !user::maintains($auth_user->handle, $package, 'lead')) {
            return PEAR::raiseError('maintainer::remove: insufficient privileges');
        }
        if (is_string($package)) {
            $package = package::info($package, 'id');
        }
        $sql = "DELETE FROM maintains WHERE package = ? AND handle = ?";
        return $dbh->query($sql, array($package, $user));
    }

    // }}}
    // {{{ +proto bool   maintainer::updateAll(int, array) API 1.0

    /**
     * Update user and roles of a package
     *
     * @static
     * @param int $pkgid The package id to update
     * @param array $users Assoc array containing the list of users
     *                     in the form: '<user>' => array('role' => '<role>', 'active' => '<active>')
     * @return mixed PEAR_Error or true
     */
    function updateAll($pkgid, $users)
    {
        require_once "Damblan/Log.php";

        global $dbh, $auth_user;

        $admin = $auth_user->isAdmin();
        $qa    = $auth_user->isQA();

        // Only admins and leads can do this.
        if (maintainer::mayUpdate($pkgid) == false) {
            return PEAR::raiseError('maintainer::updateAll: insufficient privileges');
        }

        $logger = new Damblan_Log;
        $pkg_name = package::info((int)$pkgid, "name", true); // Needed for logging
        if (empty($pkg_name)) {
            PEAR::raiseError('maintainer::updateAll: no such package');
        }

        $old = maintainer::get($pkgid);
        if (DB::isError($old)) {
            return $old;
        }
        $old_users = array_keys($old);
        $new_users = array_keys($users);

        if (!$admin && !$qa && !in_array($auth_user->handle, $new_users)) {
            return PEAR::raiseError("You can not delete your own maintainer role or you will not ".
                                    "be able to complete the update process. Set your name ".
                                    "in package.xml or let the new lead developer upload ".
                                    "the new release");
        }
        foreach ($users as $user => $u) {
            $role = $u['role'];
            $active = $u['active'];

            if (!maintainer::isValidRole($role)) {
                return PEAR::raiseError("invalid role '$role' for user '$user'");
            }
            // The user is not present -> add him
            if (!in_array($user, $old_users)) {
                $e = maintainer::add($pkgid, $user, $role, $active);
                if (PEAR::isError($e)) {
                    return $e;
                }
                $logger->log("[Maintainer] NEW: " . $user . " (" . $role . ") to package " . $pkg_name . " by " . $auth_user->handle);
                continue;
            }
            // Users exists but role has changed -> update it
            if ($role != $old[$user]['role']) {
                $res = maintainer::update($pkgid, $user, $role, $active);
                if (DB::isError($res)) {
                    return $res;
                }
                $logger->log("[Maintainer] UPDATE: " . $user . " (" . $role . ") to package " . $pkg_name . " by " . $auth_user->handle);
            }
        }
        // Drop users who are no longer maintainers
        foreach ($old_users as $old_user) {
            if (!in_array($old_user, $new_users)) {
                $res = maintainer::remove($pkgid, $old_user);
                if (DB::isError($res)) {
                    return $res;
                }
                $logger->log("[Maintainer] REMOVED: " . $old_user . " (" . $role . ") to package " . $pkg_name . " by " . $auth_user->handle);
            }
        }
        return true;
    }

    // }}}
    // {{{

    /**
     * Update maintainer entry
     *
     * @access public
     * @param  int Package ID
     * @param  string Username
     * @param  string Role
     * @param  string Is the developer actively working on the package?
     */
    function update($package, $user, $role, $active) {
        global $dbh;

        $query = "UPDATE maintains SET role = ?, active = ? " .
            "WHERE package = ? AND handle = ?";
        return $dbh->query($query, array($role, $active, $package, $user));
    }

    // {{{ NOEXPORT  maintainer::mayUpdate(int)

    /**
     * Checks if the current user is allowed to update the maintainer data
     *
     * @access public
     * @param  int  ID of the package
     * @return boolean
     */
    function mayUpdate($package) {
        global $auth_user;

        $admin = $auth_user->isAdmin();
        $qa    = $auth_user->isQA();

        if (!$admin && !$qa && !user::maintains($auth_user->handle, $package, 'lead')) {
            return false;
        }

        return true;
    }

    // }}}
}

/**
 * Class to handle releases
 *
 * @class   release
 * @package pearweb
 * @author  Stig S. Bakken <ssb@fast.no>
 * @author  Tomas V.V. Cox <cox@php.net>
 * @author  Martin Jansen <mj@php.net>
 */
class release
{
    // {{{  proto array  release::getRecent([int]) API 1.0

    /**
     * Get recent releases
     *
     * @static
     * @param  integer Number of releases to return
     * @return array
     */
    function getRecent($n = 5)
    {
        global $dbh;
        $sth = $dbh->limitQuery("SELECT packages.id AS id, ".
                                "packages.name AS name, ".
                                "packages.summary AS summary, ".
                                "releases.version AS version, ".
                                "releases.releasedate AS releasedate, ".
                                "releases.releasenotes AS releasenotes, ".
                                "releases.doneby AS doneby, ".
                                "releases.state AS state ".
                                "FROM packages, releases ".
                                "WHERE packages.id = releases.package ".
                                "AND packages.approved = 1 ".
                                "AND packages.package_type = 'pear' ".
                                "ORDER BY releases.releasedate DESC", 0, $n);
        $recent = array();
        // XXX Fixme when DB gets limited getAll()
        while ($sth->fetchInto($row, DB_FETCHMODE_ASSOC)) {
            $recent[] = $row;
        }
        return $recent;
    }

    // }}}
    // {{{  proto array  release::getDateRange(int,int) API 1.0

    /**
     * Get release in a specific time range
     *
     * @static
     * @param integer Timestamp of start date
     * @param integer Timestamp of end date
     * @return array
     */
    function getDateRange($start,$end)
    {
        global $dbh;

        $recent = array();
        if (!is_numeric($start)) {
            return $recent;
        }
        if (!is_numeric($end)) {
            return $recent;
        }
        $start_f = date('Y-m-d 00:00:00',$start);
        $end_f = date('Y-m-d 00:00:00',$end);
        // limited to 50 to stop overkill on the server!
        $sth = $dbh->limitQuery("SELECT packages.id AS id, ".
                                "packages.name AS name, ".
                                "packages.summary AS summary, ".
                                "packages.description AS description, ".
                                "releases.version AS version, ".
                                "releases.releasedate AS releasedate, ".
                                "releases.releasenotes AS releasenotes, ".
                                "releases.doneby AS doneby, ".
                                "releases.state AS state ".
                                "FROM packages, releases ".
                                "WHERE packages.id = releases.package ".
                                "AND releases.releasedate > '{$start_f}' AND releases.releasedate < '{$end_f}'".
                                "ORDER BY releases.releasedate DESC",0,50);

        while ($sth->fetchInto($row, DB_FETCHMODE_ASSOC)) {
            $recent[] = $row;
        }
        return $recent;
    }

    // }}}
    // {{{ +proto string release::upload(string, string, string, string, binary, string) API 1.0

    /**
     * Upload new release
     *
     * @static
     * @param string Name of the package
     * @param string Version string
     * @param string State of the release
     * @param string Release notes
     * @param string Filename of the release tarball
     * @param string MD5 checksum of the tarball
     */
    function upload($package, $version, $state, $relnotes, $tarball, $md5sum)
    {
        global $auth_user;
        $role = user::maintains($auth_user->handle, $package);
        if ($role != 'lead' && $role != 'developer' && !$auth_user->isAdmin()) {
            return PEAR::raiseError('release::upload: insufficient privileges');
        }
        $ref = release::validateUpload($package, $version, $state, $relnotes, $tarball, $md5sum);
        if (PEAR::isError($ref)) {
            return $ref;
        }

        return release::confirmUpload($package, $version, $state, $relnotes, $md5sum, $ref['package_id'], $ref['file']);
    }

    // }}}
    // {{{ +proto string release::validateUpload(string, string, string, string, binary, string) API 1.0

    /**
     * Determine if uploaded file is a valid release
     *
     * @param string Name of the package
     * @param string Version string
     * @param string State of the release
     * @param string Release notes
     * @param string Filename of the release tarball
     * @param string MD5 checksum of the tarball
     * @return mixed
     */
    function validateUpload($package, $version, $state, $relnotes, $tarball, $md5sum)
    {
        global $dbh, $auth_user;
        $role = user::maintains($auth_user->handle, $package);
        if ($role != 'lead' && $role != 'developer' && !$auth_user->isAdmin()) {
            return PEAR::raiseError('release::validateUpload: insufficient privileges');
        }
        // (2) verify that package exists
        $package_id = package::info($package, 'id');
        if (PEAR::isError($package_id) || empty($package_id)) {
            return PEAR::raiseError("package `$package' must be registered first");
        }

        // (3) verify that version does not exist
        $test = $dbh->getOne("SELECT version FROM releases ".
                             "WHERE package = ? AND version = ?",
                             array($package_id, $version));
        if (PEAR::isError($test)) {
            return $test;
        }
        if ($test) {
            return PEAR::raiseError("already exists: $package $version");
        }

        // (4) store tar ball to temp file
        $tempfile = sprintf("%s/%s%s-%s.tgz",
                            PEAR_TARBALL_DIR, ".new.", $package, $version);
        $file = sprintf("%s/%s-%s.tgz", PEAR_TARBALL_DIR, $package, $version);
        if (!@copy($tarball, $tempfile)) {
            return PEAR::raiseError("writing $tempfile failed: $php_errormsg");
        }

        if (!isset($package_id)) {
            return PEAR::raiseError("bad upload: package_id missing");
        }

        // later: do lots of integrity checks on the tarball
        if (!@rename($tempfile, $file)) {
            return PEAR::raiseError("renaming failed: $php_errormsg");
        }

        // (5) verify MD5 checksum
        $testsum = md5_file($file);
        if ($testsum != $md5sum) {
            $bytes = strlen($data);
            return PEAR::raiseError("bad md5 checksum (checksum=$testsum ($bytes bytes: $data), specified=$md5sum)");
        }

        return array("package_id" => $package_id,
                     "file" => $file
                     );
    }

    // }}}
    // {{{ +proto bool   release::confirmUpload(string) API 1.0

    /**
     * Confirm release upload
     *
     * @static
     * @return string  the file name of the upload or PEAR_Error object if problems
     */
    function confirmUpload($package, $version, $state, $relnotes, $md5sum, $package_id, $file)
    {
        global $dbh, $auth_user, $_PEAR_Common_dependency_types,
               $_PEAR_Common_dependency_relations;

        // Update releases table
        $query = "INSERT INTO releases (id,package,version,state,doneby,".
             "releasedate,releasenotes) VALUES(?,?,?,?,?,NOW(),?)";
        $sth = $dbh->prepare($query);
        $release_id = $dbh->nextId("releases");
        $dbh->execute($sth, array($release_id, $package_id, $version, $state,
                                  $auth_user->handle, $relnotes));
        // Update files table
        $query = "INSERT INTO files ".
             "(id,package,release,md5sum,basename,fullpath) ".
             "VALUES(?,?,?,?,?,?)";
        $sth = $dbh->prepare($query);
        $file_id = $dbh->nextId("files");
        $ok = $dbh->execute($sth, array($file_id, $package_id, $release_id,
                                        $md5sum, basename($file), $file));
        /*
         * Code duplication with deps error
         * Should be droped soon or later using transaction
         * (and add mysql4 as a pe(ar|cl)web requirement)
         */
        if (PEAR::isError($ok)) {
            $dbh->query("DELETE FROM releases WHERE id = $release_id");
            @unlink($file);
            return $ok;
        }

        // Update dependency table
        $query = "INSERT INTO deps " .
            "(package, release, type, relation, version, name, optional) " .
            "VALUES (?,?,?,?,?,?,?)";
        $sth = $dbh->prepare($query);

        /*
         * The dependencies are only accessible via the package
         * definition. Because of this we need to instantiate
         * a PEAR_Common object here.
         */
        $common = new PEAR_Common();
        $pkg_info = $common->InfoFromTgzFile($file);

        foreach ($pkg_info as $key => $value) {
            if ($key == "release_deps") {
                foreach ($value as $dep) {
                    $prob = array();

                    if (empty($dep['type']) ||
                        !in_array($dep['type'], $_PEAR_Common_dependency_types))
                    {
                        $prob[] = 'type';
                    }

                    if (empty($dep['name'])) {
                        /*
                         * NOTE from pajoye in ver 1.166:
                         * This works for now.
                         * This would require a 'cleaner' InfoFromXXX
                         * which may return a defined set of data using
                         * default values if required.
                         */
                        if (strtolower($dep['type']) == 'php') {
                            $dep['name'] = 'PHP';
                        } else {
                            $prob[] = 'name';
                        }
                    }

                    if (empty($dep['rel']) ||
                        !in_array($dep['rel'], $_PEAR_Common_dependency_relations))
                    {
                        $prob[] = 'rel';
                    }

                    if (empty($dep['optional'])) {
                        $optional = 0;
                    } else {
                        if ($dep['optional'] != strtolower($dep['optional'])) {
                            $prob[] = 'optional';
                        }
                        if ($dep['optional'] == 'yes') {
                            $optional = 1;
                        } else {
                            $optional = 0;
                        }
                    }

                    if (count($prob)) {
                        $res = PEAR::raiseError('The following attribute(s) ' .
                                'were missing or need proper values: ' .
                                implode(', ', $prob));
                    } else {
                        $res = $dbh->execute($sth,
                                array(
                                    $package_id,
                                    $release_id,
                                    $dep['type'],
                                    $dep['rel'],
                                    @$dep['version'],
                                    $dep['name'],
                                    $optional));
                    }

                    if (PEAR::isError($res)) {
                        $dbh->query('DELETE FROM deps WHERE ' .
                                    "release = $release_id");
                        $dbh->query('DELETE FROM releases WHERE ' .
                                    "id = $release_id");
                        @unlink($file);
                        return $res;
                    }
                }
            }
        }

        // Add release archive file to API documentation queue
        $query = "INSERT INTO apidoc_queue (filename, queued) "
             . "VALUES ('" . $file. "', NOW())";

        /*
         * Don't abort the release if something goes wrong.
         */
        $dbh->pushErrorHandling(PEAR_ERROR_RETURN);
        $sth = $dbh->query($query);
        $dbh->popErrorHandling();

        // Update Cache
        include_once 'xmlrpc-cache.php';
        XMLRPC_Cache::remove('package.listAll', array(false));
        XMLRPC_Cache::remove('package.listAll', array(true));
        XMLRPC_Cache::remove('package.info', array($package, null));

        return $file;
    }

    // }}}
    // {{{ +proto bool   release::dismissUpload(string) API 1.0

    /**
     * Dismiss release upload
     *
     * @param string
     * @return boolean
     */
    function dismissUpload($upload_ref)
    {
        return (bool)@unlink($upload_ref);
    }

    // }}}
    // {{{ NOEXPORT      release::HTTPdownload(string, [string], [string], [bool])

    /**
     * Download release via HTTP
     *
     * Not for xmlrpc export!
     *
     * @param string Name of the package
     * @param string Version string
     * @param string Filename
     * @param boolean Uncompress file before downloading?
     * @return mixed
     * @static
     */
    function HTTPdownload($package, $version = null, $file = null, $uncompress = false)
    {
        global $dbh;
        $package_id = package::info($package, 'packageid', true);

        if (!$package_id) {
            return PEAR::raiseError("release download:: package '".htmlspecialchars($package).
                                    "' does not exist");
        } elseif (PEAR::isError($package_id)) {
            return $package_id;
        }

        if ($file !== null) {
            if (substr($file, -4) == '.tar') {
                $file = substr($file, 0, -4) . '.tgz';
                $uncompress = true;
            }
            $row = $dbh->getRow("SELECT fullpath, release, id FROM files ".
                                "WHERE UPPER(basename) = ?", array(strtoupper($file)),
                                DB_FETCHMODE_ASSOC);
            if (PEAR::isError($row)) {
                return $row;
            } elseif ($row === null) {
                return PEAR::raiseError("File '$file' not found");
            }
            $path = $row['fullpath'];
            $log_release = $row['release'];
            $log_file = $row['id'];
            $basename = $file;
        } elseif ($version == null) {
            // Get the most recent version
            $row = $dbh->getRow("SELECT id FROM releases ".
                                "WHERE package = $package_id ".
                                "ORDER BY releasedate DESC", DB_FETCHMODE_ASSOC);
            if (PEAR::isError($row)) {
                return $row;
            }
            $release_id = $row['id'];
        } elseif (release::isValidState($version)) {
            $version = strtolower($version);
            // Get the most recent version with a given state
            $row = $dbh->getRow("SELECT id FROM releases ".
                                "WHERE package = $package_id ".
                                "AND state = '$version' ".
                                "ORDER BY releasedate DESC",
                                DB_FETCHMODE_ASSOC);
            if (PEAR::isError($row)) {
                return $row;
            }
            $release_id = $row['id'];
            if (!isset($release_id)) {
                return PEAR::raiseError("$package does not have any releases with state \"$version\"");
            }
        } else {
            // Get a specific release
            $row = $dbh->getRow("SELECT id FROM releases ".
                                "WHERE package = $package_id ".
                                "AND version = '$version'",
                                DB_FETCHMODE_ASSOC);
            if (PEAR::isError($row)) {
                return $row;
            }
            $release_id = $row['id'];
        }
        if (!isset($path) && isset($release_id)) {
            $sql = "SELECT fullpath, basename, id FROM files WHERE release = ".
                 $release_id;
            $row = $dbh->getRow($sql, DB_FETCHMODE_ORDERED);
            if (PEAR::isError($row)) {
                return $row;
            }
            list($path, $basename, $log_file) = $row;
            if (empty($path) || !@is_file($path)) {
                return PEAR::raiseError("release download:: no version information found");
            }
        }
        if (isset($path)) {
            if (!isset($log_release)) {
                $log_release = $release_id;
            }

            release::logDownload($package_id, $log_release, $log_file);

            header('Last-modified: '.HTTP::date(filemtime($path)));
            header('Content-type: application/octet-stream');
            if ($uncompress) {
                $tarname = preg_replace('/\.tgz$/', '.tar', $basename);
                header('Content-disposition: attachment; filename="'.$tarname.'"');
                readgzfile($path);
            } else {
                header('Content-disposition: attachment; filename="'.$basename.'"');
                header('Content-length: '.filesize($path));
                readfile($path);
            }

            return true;
        }
        header('HTTP/1.0 404 Not Found');
        print 'File not found';
    }

    // }}}
    // {{{  proto bool   release::isValidState(string) API 1.0

    /**
     * Determine if release state is valid
     *
     * @static
     * @param string State
     * @return boolean
     */
    function isValidState($state)
    {
        static $states = array('devel', 'snapshot', 'alpha', 'beta', 'stable');
        return in_array($state, $states);
    }

    // }}}
    // {{{  proto array  release::betterStates(string) API 1.0

    /**
     * Convert a state into an array of less stable states
     *
     * @param string Release state
     * @param boolean include the state in the array returned
     * @return boolean
     */
    function betterStates($state, $include = false)
    {
        static $states = array('snapshot', 'devel', 'alpha', 'beta', 'stable');
        $i = array_search($state, $states);
        if ($include) {
            $i--;
        }
        if ($i === false) {
            return false;
        }
        return array_slice($states, $i + 1);
    }

    // }}}
    // {{{ NOEXPORT      release::logDownload(integer, string, string)

    /**
     * Log release download
     *
     * @param integer ID of the package
     * @param integer ID of the release
     * @param string Filename
     */
    function logDownload($package, $release_id, $file = null)
    {
        global $dbh;

        $id = $dbh->nextId("downloads");

        $query = "INSERT INTO downloads (id, file, package, release, dl_when, dl_who, dl_host) VALUES (?, ?, ?, ?, ?, ?, ?)";

        $err = $dbh->query($query, array($id, (int)$file, $package,
                                         $release_id, date("Y-m-d H:i:s"),
                                         $_SERVER['REMOTE_ADDR'],
                                         gethostbyaddr($_SERVER['REMOTE_ADDR'])
                                         ));

        // {{{ Update package_stats table

        // Check if an entry for the release already exists
        $query = "SELECT COUNT(pid) FROM package_stats WHERE pid = ? AND rid = ?";
        $exists = $dbh->getOne($query, array($package, $release_id));

        if ($exists == 0) {
            $pkg_info = package::info($package, null, true);

            $query = 'SELECT version FROM releases'
                   . ' WHERE package = ? AND id = ?';
            $version = $dbh->getOne($query, array($package, $release_id));

            if (!$version) {
                return PEAR::raiseError('release:: the package you requested'
                                        . ' has no release by that number');
            }

            $query = 'INSERT INTO package_stats'
                   . ' (dl_number, package, release, pid, rid, cid, last_dl)'
                   . ' VALUES (1, ?, ?, ?, ?, ?, ?)';

            $dbh->query($query, array($pkg_info['name'],
                                      $version,
                                      $package,
                                      $release_id,
                                      $pkg_info['categoryid'],
                                      date('Y-m-d H:i:s')
                                      )
                        );
        } else {
            $query = 'UPDATE package_stats '
                   . ' SET dl_number = dl_number + 1,'
                   . " last_dl = '" . date('Y-m-d H:i:s') . "'"
                   . ' WHERE pid = ? AND rid = ?';
            $dbh->query($query, array($package, $release_id));
        }

        // }}}
    }

    // }}}
    // {{{ +proto string release::promote(array, string) API 1.0

    /**
     * Promote new release
     *
     * @param array Coming from PEAR_common::infoFromDescFile('package.xml')
     * @param string Filename of the new uploaded release
     * @return void
     */
    function promote($pkginfo, $upload)
    {
        if ($_SERVER['SERVER_NAME'] != 'pear.php.net') {
            return;
        }
        $pacid   = package::info($pkginfo['package'], 'packageid');
        $authors = package::info($pkginfo['package'], 'authors');
        $txt_authors = '';
        foreach ($authors as $a) {
            $txt_authors .= $a['name'];
            if ($a['showemail']) {
                $txt_authors .= " <{$a['email']}>";
            }
            $txt_authors .= " ({$a['role']})\n";
        }
        $upload = basename($upload);
        $release = "{$pkginfo['package']}-{$pkginfo['version']} ({$pkginfo['release_state']})";
        $txtanounce =<<<END
The new PEAR package $release has been released at http://pear.php.net/.

Release notes
-------------
{$pkginfo['release_notes']}

Package Info
-------------
{$pkginfo['description']}

Related Links
-------------
Package home: http://pear.php.net/package/$pkginfo[package]
   Changelog: http://pear.php.net/package/$pkginfo[package]/download/$pkginfo[version]
    Download: http://pear.php.net/get/$upload

Authors
-------------
$txt_authors
END;

        $to   = '"PEAR general list" <pear-general@lists.php.net>';
        $from = '"PEAR Announce" <pear-dev@lists.php.net>';
        $subject = "[ANNOUNCEMENT] $release Released.";
        mail($to, $subject, $txtanounce, "From: $from", "-f pear-sys@php.net");
    }

    // }}}
    // {{{ NOEXPORT      release::remove(int, int)

    /**
     * Remove release
     *
     * @param integer ID of the package
     * @param integer ID of the release
     * @return boolean
     */
    function remove($package, $release)
    {
        global $dbh, $auth_user;
        if (!$auth_user->isAdmin() && !$auth_user->isQA() &&
            !user::maintains($auth_user->handle, $package, 'lead')) {
            return PEAR::raiseError('release::remove: insufficient privileges');
        }

        $success = true;

        // get files that have to be removed
        $query = sprintf("SELECT fullpath FROM files WHERE package = '%s' AND release = '%s'",
                         $package,
                         $release);

        $sth = $dbh->query($query);

        while ($row = $sth->fetchRow(DB_FETCHMODE_ASSOC)) {
            if (!@unlink($row['fullpath'])) {
                $success = false;
            }
        }

        $query = sprintf("DELETE FROM files WHERE package = '%s' AND release = '%s'",
                         $package,
                         $release
                         );
        $sth = $dbh->query($query);

        $query = sprintf("DELETE FROM releases WHERE package = '%s' AND id = '%s'",
                         $package,
                         $release
                         );
        $sth = $dbh->query($query);

        if (PEAR::isError($sth)) {
            return false;
        } else {
            return true;
        }
    }

    // }}}
    // {{{ getFAQ()

    /**
     * Get FAQ items for given package version
     *
     * @param string Name of the package
     * @param string Version string of the package
     * @return mixed PEAR_Error or Array
     */
    function getFAQ($package, $version)
    {
        global $dbh;

        $query = "SELECT f.* FROM packages_faq f, packages p, releases r "
            . "WHERE p.name = ? AND p.id = r.package AND r.version = ? AND r.id = f.release";

        return $dbh->getAll($query, array($package, $version), DB_FETCHMODE_ASSOC);
    }
    // }}}
}


/**
 * Class to handle notes
 *
 * @class   note
 * @package pearweb
 * @author  Stig S. Bakken <ssb@fast.no>
 * @author  Tomas V.V. Cox <cox@php.net>
 * @author  Martin Jansen <mj@php.net>
 */
class note
{
    // {{{ +proto bool   note::add(string, int, string, string) API 1.0

    function add($key, $value, $note, $author = "")
    {
        global $dbh;
        if (empty($author)) {
            $author = $_COOKIE['PEAR_USER'];
        }
        $nid = $dbh->nextId("notes");
        $stmt = $dbh->prepare("INSERT INTO notes (id,$key,nby,ntime,note) ".
                              "VALUES(?,?,?,?,?)");
        $res = $dbh->execute($stmt, array($nid, $value, $author,
                             gmdate('Y-m-d H:i'), $note));
        if (DB::isError($res)) {
            return $res;
        }
        return true;
    }

    // }}}
    // {{{ +proto bool   note::remove(int) API 1.0

    function remove($id)
    {
        global $dbh;
        $id = (int)$id;
        $res = $dbh->query("DELETE FROM notes WHERE id = $id");
        if (DB::isError($res)) {
            return $res;
        }
        return true;
    }

    // }}}
    // {{{ +proto bool   note::removeAll(string, int) API 1.0

    function removeAll($key, $value)
    {
        global $dbh;
        $res = $dbh->query("DELETE FROM notes WHERE $key = ". $dbh->quote($value));
        if (DB::isError($res)) {
            return $res;
        }
        return true;
    }

    // }}}
}

class user
{
    // {{{ *proto bool   user::remove(string) API 1.0

    function remove($uid)
    {
        global $dbh;
        note::removeAll("uid", $uid);
        $dbh->query('DELETE FROM users WHERE handle = '. $dbh->quote($uid));
        return ($dbh->affectedRows() > 0);
    }

    // }}}
    // {{{ *proto bool   user::rejectRequest(string, string) API 1.0

    function rejectRequest($uid, $reason)
    {
        global $dbh;
        list($email) = $dbh->getRow('SELECT email FROM users WHERE handle = ?',
                                    array($uid));
        note::add("uid", $uid, "Account rejected: $reason");
        $msg = "Your PEAR account request was rejected by " . $_COOKIE['PEAR_USER'] . ":\n\n".
             "$reason\n";
        $xhdr = "From: " . $_COOKIE['PEAR_USER'] . "@php.net";
        mail($email, "Your PEAR Account Request", $msg, $xhdr, "-f pear-sys@php.net");
        return true;
    }

    // }}}
    // {{{ *proto bool   user::activate(string) API 1.0

    function activate($uid, $karmalevel = 'pear.dev')
    {
        require_once "Damblan/Karma.php";

        global $dbh;

        $user =& new PEAR_User($dbh, $uid);
        $karma = new Damblan_Karma($dbh);

        if (@$user->registered) {
            return false;
        }
        @$arr = unserialize($user->userinfo);
        note::removeAll("uid", $uid);
        $user->set('registered', 1);
        /* $user->set('ppp_only', 0); */
        if (is_array($arr)) {
            $user->set('userinfo', $arr[1]);
        }
        $user->set('created', gmdate('Y-m-d H:i'));
        $user->set('createdby', $_COOKIE['PEAR_USER']);
        $user->store();
        $karma->grant($user->handle, $karmalevel);
        note::add("uid", $uid, "Account opened");
        $msg = "Your PEAR account request has been opened.\n".
             "To log in, go to http://pear.php.net/ and click on \"login\" in\n".
             "the top-right menu.\n";
        $xhdr = "From: " . $_COOKIE['PEAR_USER'] . "@php.net";
        mail($user->email, "Your PEAR Account Request", $msg, $xhdr, "-f pear-sys@php.net");
        return true;
    }

    // }}}
    // {{{ +proto bool   user::isAdmin(string) API 1.0

    function isAdmin($handle)
    {
        require_once "Damblan/Karma.php";

        global $dbh;
        $karma = new Damblan_Karma($dbh);

        return $karma->has($handle, "pear.admin");
    }

    // }}}
    // {{{ +proto bool   user::isQA(string) API post 1.0

    function isQA($handle)
    {
        require_once 'Damblan/Karma.php';

        global $dbh;
        $karma = new Damblan_Karma($dbh);

        return $karma->has($handle, 'pear.qa');
    }

    // }}}
    // {{{  proto bool   user::listAdmins() API 1.0

    function listAdmins()
    {
        require_once "Damblan/Karma.php";

        global $dbh;
        $karma = new Damblan_Karma($dbh);

        return $karma->getUser("pear.admin");
    }

    // }}}
    // {{{ +proto bool   user::exists(string) API 1.0

    function exists($handle)
    {
        global $dbh;
        $sql = "SELECT handle FROM users WHERE handle=?";
        $res = $dbh->query($sql, array($handle));
        return ($res->numRows() > 0);
    }

    // }}}
    // {{{ +proto string user::maintains(string|int, [string]) API 1.0

    function maintains($user, $pkgid, $role = 'any')
    {
        global $dbh;
        $package_id = package::info($pkgid, 'id');
        if ($role == 'any') {
            return $dbh->getOne('SELECT role FROM maintains WHERE handle = ? '.
                                'AND package = ?', array($user, $package_id));
        }
        if (is_array($role)) {
            return $dbh->getOne('SELECT role FROM maintains WHERE handle = ? AND package = ? '.
                                'AND role IN ("?")', array($user, $package_id, implode('","', $role)));
        }
        return $dbh->getOne('SELECT role FROM maintains WHERE handle = ? AND package = ? '.
                            'AND role = ?', array($user, $package_id, $role));
    }

    // }}}
    // {{{  proto string user::info(string, [string]) API 1.0

    function info($user, $field = null)
    {
        global $dbh;
        if ($field === null) {
            $row = $dbh->getRow('SELECT * FROM users WHERE registered = 1 AND handle = ?',
                                array($user), DB_FETCHMODE_ASSOC);
            unset($row['password']);
            return $row;
        }
        if ($field == 'password' || preg_match('/[^a-z]/', $user)) {
            return null;
        }
        return $dbh->getRow('SELECT ! FROM users WHERE handle = ?',
                            array($field, $user), DB_FETCHMODE_ASSOC);

    }

    // }}}
    // {{{ listAll()

    function listAll($registered_only = true)
    {
        global $dbh;
        $query = "SELECT * FROM users";
        if ($registered_only === true) {
            $query .= " WHERE registered = 1";
        }
        $query .= " ORDER BY handle";
        return $dbh->getAll($query, null, DB_FETCHMODE_ASSOC);
    }

    // }}}
    // {{{ add()

    /**
     * Add a new user account
     *
     * During most of this method's operation, PEAR's error handling
     * is set to PEAR_ERROR_RETURN.
     *
     * But, during the DB_storage::set() phase error handling is set to
     * PEAR_ERROR_CALLBACK the report_warning() function.  So, if an
     * error happens a warning message is printed AND the incomplete
     * user information is removed.
     *
     * @param array $data  Information about the user
     *
     * @return mixed  true if there are no problems, false if sending the
     *                email failed, 'set error' if DB_storage::set() failed
     *                or an array of error messages for other problems
     *
     * @access public
     */
    function add(&$data)
    {
        global $dbh;

        PEAR::pushErrorHandling(PEAR_ERROR_RETURN);
        $errors = array();

        $required = array(
            'handle'     => 'Username',
            'firstname'  => 'First Name',
            'lastname'   => 'Last Name',
            'email'      => 'Email address',
            'purpose'    => 'Intended purpose',
        );

        $name = $data['firstname'] . " " . $data['lastname'];

        foreach ($required as $field => $desc) {
            if (empty($data[$field])) {
                $data['jumpto'] = $field;
                $errors[] = 'Please enter ' . $desc;
            }
        }

        if (!preg_match(PEAR_COMMON_USER_NAME_REGEX, $data['handle'])) {
            $errors[] = 'Username must start with a letter and contain'
                      . ' only letters and digits';
        }

        if ($data['password'] != $data['password2']) {
            $data['password'] = $data['password2'] = "";
            $data['jumpto'] = "password";
            $errors[] = 'Passwords did not match';
        }

        if (!$data['password']) {
            $data['jumpto'] = "password";
            $errors[] = 'Empty passwords not allowed';
        }

        $handle = strtolower($data['handle']);
        $obj =& new PEAR_User($dbh, $handle);

        if (isset($obj->created)) {
            $data['jumpto'] = "handle";
            $errors[] = 'Sorry, that username is already taken';
        }

        if ($errors) {
            $data['display_form'] = true;
            return $errors;
        }

        $err = $obj->insert($handle);

        if (DB::isError($err)) {
            if ($err->getCode() == DB_ERROR_CONSTRAINT) {
                $data['display_form'] = true;
                $data['jumpto'] = 'handle';
                $errors[] = 'Sorry, that username is already taken';
            } else {
                $data['display_form'] = false;
                $errors[] = $err;
            }
            return $errors;
        }

        $data['display_form'] = false;
        $md5pw = md5($data['password']);
        $showemail = @(bool)$data['showemail'];
        // hack to temporarily embed the "purpose" in
        // the user's "userinfo" column
        $userinfo = serialize(array($data['purpose'], $data['moreinfo']));
        $set_vars = array('name' => $name,
                          'email' => $data['email'],
                          'homepage' => $data['homepage'],
                          'showemail' => $showemail,
                          'password' => $md5pw,
                          'registered' => 0,
                          'userinfo' => $userinfo);

        PEAR::pushErrorHandling(PEAR_ERROR_CALLBACK, 'report_warning');
        foreach ($set_vars as $var => $value) {
            $err = $obj->set($var, $value);
            if (PEAR::isError($err)) {
                user::remove($data['handle']);
                return 'set error';
            }
        }
        PEAR::popErrorHandling();

        $msg = "Requested from:   {$_SERVER['REMOTE_ADDR']}\n".
               "Username:         {$handle}\n".
               "Real Name:        {$name}\n".
               (isset($data['showemail']) ? "Email:            {$data['email']}\n" : "") .
               "Purpose:\n".
               "{$data['purpose']}\n\n".
               "To handle: http://{$_SERVER['SERVER_NAME']}/admin/?acreq={$handle}\n";

        if ($data['moreinfo']) {
            $msg .= "\nMore info:\n{$data['moreinfo']}\n";
        }

        $xhdr = "From: $name <{$data['email']}>\nMessage-Id: <account-request-{$handle}@pear.php.net>\n";
        // $xhdr .= "\nBCC: pear-group@php.net";
        $subject = "PEAR Account Request: {$handle}";

        if (DEVBOX == false) {
            $ok = @mail('pear-group@php.net', $subject, $msg, $xhdr,
                        '-f pear-sys@php.net');
        } else {
            $ok = true;
        }

        PEAR::popErrorHandling();

        return $ok;
    }

    // }}}
    // {{{ update

    /**
     * Update user information
     *
     * @access public
     * @param  array User information
     * @return object Instance of PEAR_User
     */
    function update($data) {
        global $dbh;

        $fields = array("name", "email", "homepage", "showemail", "userinfo", "pgpkeyid", "wishlist");

        $user =& new PEAR_User($dbh, $data['handle']);
        foreach ($data as $key => $value) {
            if (!in_array($key, $fields)) {
                continue;
            }
            $user->set($key, $value);
        }
        $user->store();

        return $user;
    }

    // }}}
    // {{{ getRecentReleases(string, [int])

    /**
     * Get recent releases for the given user
     *
     * @access public
     * @param  string Handle of the user
     * @param  int    Number of releases (default is 10)
     * @return array
     */
    function getRecentReleases($handle, $n = 10)
    {
        global $dbh;
        $recent = array();

        $query = "SELECT p.id AS id, " .
            "p.name AS name, " .
            "p.summary AS summary, " .
            "r.version AS version, " .
            "r.releasedate AS releasedate, " .
            "r.releasenotes AS releasenotes, " .
            "r.doneby AS doneby, " .
            "r.state AS state " .
            "FROM packages p, releases r, maintains m " .
            "WHERE p.package_type = 'pear' AND p.id = r.package " .
            "AND p.id = m.package AND m.handle = '" . $handle . "' " .
            "ORDER BY r.releasedate DESC";

        $sth = $dbh->limitQuery($query, 0, $n);
        while ($sth->fetchInto($row, DB_FETCHMODE_ASSOC)) {
            $recent[] = $row;
        }
        return $recent;
    }

    // }}}
    // {{{ * proto array   user::getWhoIsWho() API 1.0

    /**
     * Get list of current developers and the packages they maintain.
     *
     * The output of this method is used on public websites for
     * a Who Is Who of the PEAR developers.  In order to avoid abuse,
     * access to this method via XML_RPC is granted based on a whitelist
     * of IP addresses.
     *
     * @access public
     * @return array
     */
    function getWhoIsWho() {
        global $dbh;

        // IP whitelist
        if (!in_array($_SERVER['REMOTE_ADDR'], array('209.61.191.11'))) {
            return array();
        }

        $query_maintainers = "SELECT p.name, m.role, p.package_type "
            . "FROM maintains m, packages p "
            . "WHERE m.package = p.id AND m.handle = ?";
        $maintainers = $dbh->prepare($query_maintainers);

        $group = $dbh->prepare("SELECT COUNT(id) "
                               . "FROM karma "
                               . "WHERE level = 'pear.group' AND user = ?");

        /* The PECL developers don't have the "pear.dev" karma level.
         * Thus every registered user needs to be checked.
         */
        $query = "SELECT handle, name, homepage, userinfo "
            . "FROM users WHERE registered = 1";

        $query_group = "SELECT user FROM karma WHERE level = 'pear.group'";

        $group_ids = $dbh->getCol($query_group);
        $group_ids = array_flip($group_ids);

        $users = $dbh->getAll($query, null, DB_FETCHMODE_ASSOC);

        foreach ($users as $id => $user) {
            // Figure out which packages are maintained by the user
            $sth = $dbh->execute($maintainers, array($user['handle']));

            // Skip if the user is maintaining nothing
            if ($sth->numRows() == 0) {
                unset($users[$id]);
                continue;
            }

            while ($row =& $sth->fetchRow(DB_FETCHMODE_ASSOC)) {
                $users[$id]['maintains'][] = $row;

                if (!isset($users[$id]['type'])) {
                    $users[$id]['type'] = 0;
                }

                switch ($users[$id]['type']) {
                case '3':
                    break;

                case '2':
                    if ($row['package_type'] == 'pear') {
                        $users[$id]['type'] = 3;
                    }
                    break;

                case '1':
                    if ($row['package_type'] == 'pecl') {
                        $users[$id]['type'] = 3;
                    }
                    break;

                default:
                    if ($row['package_type'] == 'pecl') {
                        $users[$id]['type'] = 2;
                    } else {
                        $users[$id]['type'] = 1;
                    }
                    break;
                }
            }

            if (isset($group_ids[$user['handle']])) {
                $users[$id]['group'] = 1;
            } else {
                $users[$id]['group'] = 0;
            }
        }

        return $users;
    }

    // }}}
}

class channel
{
    /**
     * Add a new channel to the database
     * @todo add server validation
     *       - verify it is a valid server name
     *       - connect to xmlrpc and retrieve the channel.xml
     *         to verify that this is possible
     */
    function add($name, $server)
    {
        global $dbh;
        $query = 'INSERT INTO channels (name, server) VALUES (?, ?)';
        $err = $dbh->query($query, array($name, $server));
        if (DB::isError($err)) {
            return $err;
        }
        // clear cache
        include_once 'xmlrpc-cache.php';
        XMLRPC_Cache::remove('channel.listAll', array());
    }

    // {{{ proto array   channel::listAll() API 1.0
    /**
     * List all registered channels
     * @return array Format: array(array(channel name, server), array(channel name, server),... )
     */
    function listAll()
    {
        global $dbh;
        $query = 'SELECT * FROM channels';
        return $dbh->getAll($query, null, DB_FETCHMODE_ORDERED);
    }
    // }}}
    // {{{ proto string   channel::update([int]) API 1.0
    /**
     * Retrieve updated channel.xml contents.
     *
     * If $time is newer than the last change to the channel.xml, returns
     * false
     * @param int timestamp retrieved from time()
     * @return false|string
     */
    function update($time = null)
    {
        global $dbh;
        if ($time === null) {
            $time = time();
        }
        if (strtotime('August 16, 2004') > $time) {
            // this is the current PEAR channel's channel.xml
            return '<?xml version="1.0" encoding="ISO-8859-1" ?>
<!DOCTYPE package SYSTEM "http://pear.php.net/dtd/channel-1.0.dtd">
<channel version="1.0">
 <name>pear</name>
 <summary>PHP Extension and Application Repository</summary>
 <protocols>
  <xmlrpc host="pear.php.net">
   <function version="1.0">logintest</function>
   <function version="1.0">package.listLatestReleases</function>
   <function version="1.0">package.listAll</function>
   <function version="1.0">package.info</function>
   <function version="1.0">package.getDownloadURL</function>
   <function version="1.0">channel.listAll</function>
   <function version="1.0">channel.update</function>
  </xmlrpc>
 </protocols>
</channel>';
        }
        return false;
    }
}

class statistics
{
    // {{{ package()

    /**
     * Get general package statistics
     *
     * @param  integer ID of the package
     * @return array
     */
    function package($id)
    {
        global $dbh;
        $query = "SELECT SUM(dl_number) FROM package_stats WHERE pid = " . (int)$id;
        return $dbh->getOne($query);
    }

    // }}}
    // {{{ release()

    function release($id, $rid = "")
    {
        global $dbh;

        $query = 'SELECT s.release, s.dl_number, s.last_dl, r.releasedate '
            . 'FROM package_stats AS s '
            . 'LEFT JOIN releases AS r ON (s.rid = r.id) '
            . "WHERE pid = " . (int)$id;
        if (!empty($rid)) {
            $query .= " AND rid = " . (int)$rid;
        }
        $query .= " GROUP BY rid ORDER BY rid DESC";

        return $dbh->getAll($query, DB_FETCHMODE_ASSOC);
    }

    // }}}
}

// {{{ +proto string logintest() API 1.0

function logintest()
{
    return true;
}

// }}}
// {{{ class PEAR_User

class PEAR_User extends DB_storage
{
    function PEAR_User(&$dbh, $user)
    {
        $this->DB_storage("users", "handle", $dbh);
        $this->pushErrorHandling(PEAR_ERROR_RETURN);
        $this->setup($user);
        $this->popErrorHandling();
    }

    function is($handle)
    {
        if (!empty($_COOKIE['PEAR_USER'])) {
            $ret = strtolower($_COOKIE['PEAR_USER']);
        } else {
            $ret = strtolower($this->handle);
        }
        return (strtolower($handle) == $ret);
    }

    function isAdmin()
    {
        return (user::isAdmin($this->handle));
    }

    function isQA()
    {
        return user::isQA($this->handle);
    }

    /**
     * Generate link for user
     *
     * @access public
     * @return string
     */
    function makeLink()
    {
        return make_link("/user/" . $this->handle . "/", $this->name);
    }
}

// }}}
// {{{ class PEAR_Package

class PEAR_Package extends DB_storage
{
    function PEAR_Package(&$dbh, $package, $keycol = "name")
    {
        $this->DB_storage("packages", $keycol, $dbh);
        $this->pushErrorHandling(PEAR_ERROR_RETURN);
        $this->setup($package);
        $this->popErrorHandling();
    }

    /**
     * Generate link for package
     *
     * Returns HTML-code that creates a link to /package/<package>
     *
     * @access public
     * @return string
     */
    function makeLink()
    {
        return make_link("/package/" . $this->name . "/", $this->name);
    }
}

// }}}
// {{{ class PEAR_Release

class PEAR_Release extends DB_storage
{
    function PEAR_Release(&$dbh, $release)
    {
        $this->DB_storage("releases", "id", $dbh);
        $this->pushErrorHandling(PEAR_ERROR_RETURN);
        $this->setup($release);
        $this->popErrorHandling();
    }
}

// }}}
// {{{ class PEAR Proposal

//class PEAR_Proposal extends DB_storage
//{
//    function PEAR_Proposal(&$dbh, $package, $keycol = "id")
//    {
//        $this->DB_storage("package_proposals", $keycol, $dbh);
//        $this->pushErrorHandling(PEAR_ERROR_RETURN);
//        $this->setup($package);
//        $this->popErrorHandling();
//    }
//}

// }}}

if (!function_exists("md5_file")) {
    function md5_file($filename) {
        $fp = @fopen($filename, "r");
        if (is_resource($fp)) {
            return md5(fread($fp, filesize($filename)));
        }
        return null;
    }
}

/**
 * Converts a Unix timestamp to a date() formatted string in the UTC time zone
 *
 * @param int    $ts      a Unix timestamp from the local machine.  If none
 *                         is provided the current time is used.
 * @param string $format  a format string, as per http://php.net/date
 *
 * @return string  the time formatted time
 */
function make_utc_date($ts = null, $format = 'Y-m-d H:i \U\T\C') {
    if (!$ts) {
        $ts = time();
    }
    return gmdate($format, $ts);
}

?>

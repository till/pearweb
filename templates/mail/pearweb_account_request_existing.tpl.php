<?php

    /**
     * Used variables in this template:
     *
     * %username%       username of the requested account
     * %firstname%      first name of the person requesting the account
     * %lastname%       last name of the person requesting the account
     */

    $tpl = array(
        'Reply-To' => array(SITE_BIG . ' Webmaster <' . PEAR_WEBMASTER_EMAIL . '>'),
        'Subject' => '[ACCOUNT-REQUEST] Account request : %username%',
        'Body' => 'An account has been requested by %firstname% %lastname%

This account is for managing package %package%.

To handle the request please click on the following link:
http://' . PEAR_CHANNELNAME . '/admin/index.php?acreq=%username%'
     );
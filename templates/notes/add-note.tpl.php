<?php response_header('User Note Added'); ?>
<h1>Note addition</h1>
    <div>
    Note added, it will be reviewed soon and you will be contacted if there's any problem.
    Go back to the previous url <a href="<?php echo htmlspecialchars($redirect) ?>" 
                                   title="Previous Note">http://<?php echo PEAR_CHANNELNAME . htmlspecialchars($redirect) ?></a>
    </div>
<?php 

response_footer(); 

?>

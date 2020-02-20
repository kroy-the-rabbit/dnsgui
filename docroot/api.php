<?php
    $base = realpath(dirname(__FILE__));
    require_once("{$base}/../include/global.inc.php");

    if ($_POST['action'] == 'deleteLocalZoneMapping' && is_numeric($_POST['mapping_id'])) {
        $dns = new \dns;
        $dns->deleteLocalZoneMapping($_POST['mapping_id']);
    }

?>

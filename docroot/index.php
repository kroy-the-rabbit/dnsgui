<?php



$base = realpath(dirname(__FILE__));
require_once("{$base}/../include/global.inc.php");

//file_get_contents("$base/../unbound-php-management/zones.conf.txt");
//
//

$tab = "dns";

if (preg_match("/dns/",$_SERVER['REQUEST_URI'])) {
    $tab = "dns";
##}else if (preg_match("/dhcp/",$_SERVER['REQUEST_URI'])) {
##    $tab = "dhcp";
}


require_once("{$base}/../view/index.html");

?>

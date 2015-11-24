<?php
$module_info=array(
         "name"            =>"plugwise"
         ,"description"    =>"Plugwise USB Driver"
         ,"version"        =>"0.1"
         ,"author"         =>"Cedric Marie-Marthe"
         ,"bootstrap"      =>"plugwise_usb"
         ,"driver"         =>"plugwise_usb_driver.php"
         ,"socket"	   =>array()
);

load_module($module_info);
?>

<?php
$options["servers"] = array("127.0.0.1:11211");
$options["debug"] = false;
$memc =new Memcache; 
$memc->connect("127.0.0.1");
$myarr = array("one","two", 3);
$memc->set("key_one", $myarr);
$val = $memc->get("key_one");
print $val[0]."\n"; // prints 'one‘
print $val[1]."\n"; // prints 'two‘
print $val[2]."\n"; // prints 3
print $memc->getVersion()."\n";

$te = new mysqli("127.0.0.1", "root", "123", "12");


?>

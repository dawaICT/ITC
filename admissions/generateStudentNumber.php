
<?php

require "../db/connect.php";
$fourRandomDigit = rand(1000,9999);
$Year = date("Y");
$Sid = $Year.$fourRandomDigit;
echo $Sid;

?>
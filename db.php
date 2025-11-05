<?php


$con = new mysqli("localhost","s66550_mono_api","3QXEagVECFEfCvdFQVCN","s66550_mono");
if ($con->connect_error){

  	die('Could not connect: ' . $con->connect_error);
        
        

}
$con->set_charset("utf8mb4"); // Ställ in teckenkodningen
?>
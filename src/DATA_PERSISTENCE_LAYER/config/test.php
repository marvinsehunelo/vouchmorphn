<?php
$password = "1234";
$hashed = password_hash($password, PASSWORD_BCRYPT);
echo $hashed;
?>


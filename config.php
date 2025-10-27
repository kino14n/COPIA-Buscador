<?php
$db = new PDO('mysql:host=localhost;dbname=buscador_sistema;charset=utf8','user','pass');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
?>

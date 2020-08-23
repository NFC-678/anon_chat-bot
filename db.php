<?php
require 'rb.php';
$db_host = 'localhost';
$db_name = 'name';
$db_user = 'user';
db_password = 'password';
R::setup("mysql:host={$db_host};dbname={$db_name}","{$db_user}","{$db_password}");

<?php
require __DIR__ . '/../app/bootstrap.php';
audit('logout');
$_SESSION=[];
session_destroy();
redirect('/login');

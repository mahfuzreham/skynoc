<?php
require __DIR__ . '/../app/bootstrap.php';
if (current_user()) { redirect('/dashboard.php'); }
redirect('/login.php');

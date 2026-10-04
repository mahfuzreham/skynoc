<?php
require __DIR__ . '/../app/bootstrap.php';

if (!empty($_SESSION['impersonating_admin_id'])) {
    $adminId = (int)$_SESSION['impersonating_admin_id'];
    $resellerId = (int)($_SESSION['impersonating_reseller_id'] ?? 0);
    audit('reseller_impersonation_ended','resellers',$resellerId,'Admin returned from reseller session');
    session_regenerate_id(true);
    $_SESSION['user_id'] = $adminId;
    unset($_SESSION['impersonating_admin_id'], $_SESSION['impersonating_reseller_id']);
    redirect('/admin');
}

audit('logout');
$_SESSION=[];
session_destroy();
redirect('/login');

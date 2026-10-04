<?php
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }
require_once __DIR__ . '/skynocbillingnotify.php';
add_hook('InvoicePaid', 10, 'skynocbillingnotify_invoice_paid');

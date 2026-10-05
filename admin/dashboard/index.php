<?php
ob_start(); require __DIR__.'/../../public/admin.php'; $html=ob_get_clean();
$script="<script>(function(){var c=document.querySelector('.content');if(!c)return;[...c.children].forEach(e=>{if(!e.classList.contains('page-head')&&!e.classList.contains('notice')&&!e.classList.contains('stats'))e.style.display='none'});document.querySelectorAll('.side-link').forEach(a=>{if(a.textContent.trim()==='Dashboard')a.href='/admin/dashboard'});document.title='SkyNoc Admin • Dashboard'})()</script><script src='/admin-page-router.js'></script>";
echo str_replace('</body>',$script.'</body>',$html);
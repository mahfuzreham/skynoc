(function(){
  const path=location.pathname.replace(/\/+$/,'')||'/admin/dashboard';
  const side=document.querySelector('.sidebar');
  if(!side)return;
  const fallbackLinks=[
    ['/admin/dashboard','Dashboard','Workspace','▦'],['/admin/packages','Packages','Workspace','◈'],['/admin/orders','Orders','Workspace','▤'],['/admin/deposits','Deposits','Workspace','◫'],['/admin/licenses','License Inventory','Workspace','▥'],['/admin/license-inventory','Inventory Edit / Manual Activation','Workspace','✎'],
    ['/admin/license-add','Add License','Licensing','＋'],['/admin/license-transfer','License Transfer','Licensing','⇄'],['/admin/order-edit','Edit Orders / License Key','Licensing','✎'],['/admin/order-fulfill','Order Fulfillment','Licensing','✓'],['/admin/renew','Renewals / Billing','Licensing','↻'],['/admin/providers','Provider Accounts','Licensing','▣'],['/admin/reissues','License Reissues','Licensing','↻'],
    ['/admin/package-pricing','Package Pricing / Profit','Billing','৳'],['/admin/invoice-settings','Invoice Settings','Billing','▤'],
    ['/admin/reseller-add','Add Reseller','Resellers','＋'],['/admin/reseller-activate','Activate Resellers','Resellers','●'],['/admin/reseller-manage','Reseller Management','Resellers','♟'],['/admin/reseller-funds','Reseller Funds','Resellers','$'],['/admin/reseller-levels','Reseller Levels','Resellers','★'],
    ['/admin/hostname','Cloudflare Hostnames','Products','⌁'],['/admin/tickets','Support Tickets','Support','✉'],['/admin/user-management','User Management','Access & API','♙'],['/admin/staff','Staff Management','Access & API','♟'],['/admin/api-keys','API Keys','Access & API','⚿'],['/admin/reports','Reports','Reports','↗'],['/admin/coupons','Coupons','Reports','◇'],
    ['/admin/settings','General Settings','Settings','⚙'],['/admin/settings/payments','Payment Methods','Settings','৳'],['/admin/settings/binance','Binance / Crypto','Settings','₿'],['/admin/settings/sms','SMS Automation','Settings','▣'],['/admin/settings/discord','Discord','Settings','◉'],['/admin/settings/telegram','Telegram','Settings','✈'],['/admin/settings/telegram/message','Telegram Messages','Settings','☷'],['/admin/settings/smtp','SMTP / Email','Settings','@'],['/admin/settings/whitelabel','White-label Settings','Settings','◇']
  ];
  function enhance(){
    let groups=side.querySelectorAll('.nav-group');
    if(!groups.length){
      const footer=side.querySelector('.sidebar-footer');if(!footer)return;
      const byGroup={};fallbackLinks.forEach(x=>(byGroup[x[2]]??=[]).push(x));
      Object.entries(byGroup).forEach(([group,items])=>{const d=document.createElement('details');d.className='nav-group';if(items.some(x=>x[0].replace(/\/+$/,'')===path)||group==='Workspace')d.open=true;const s=document.createElement('summary');s.className='nav-group-title';s.textContent=group;d.appendChild(s);const b=document.createElement('div');b.className='nav-group-body';items.forEach(x=>{const a=document.createElement('a');a.className='side-link';a.href=x[0];a.textContent=x[1];if(x[0].replace(/\/+$/,'')===path)a.classList.add('active');b.appendChild(a)});d.appendChild(b);side.insertBefore(d,footer);});groups=side.querySelectorAll('.nav-group');
    }
    groups.forEach(group=>{const active=group.querySelector('.side-link.active');if(active)group.open=true;group.querySelectorAll('.side-link').forEach(a=>{if(a.getAttribute('href')?.replace(/\/+$/,'')===path)a.classList.add('active');});});
  }
  enhance();
  const menuBtn=document.getElementById('menuBtn');const overlay=document.getElementById('overlay');
  if(menuBtn)menuBtn.onclick=function(){side.classList.toggle('open');if(overlay)overlay.classList.toggle('show');};
  if(overlay)overlay.onclick=function(){side.classList.remove('open');overlay.classList.remove('show');};
})();

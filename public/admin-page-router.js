(function(){
  const path=location.pathname.replace(/\/+$/,'')||'/admin/dashboard';
  const links=[
    ['/admin/dashboard','Dashboard','Workspace','▦'],
    ['/admin/packages','Packages','Workspace','◈'],
    ['/admin/orders','Orders','Workspace','▤'],
    ['/admin/deposits','Deposits','Workspace','◫'],
    ['/admin/licenses','License Inventory','Workspace','▥'],
    ['/admin/license-add','Add License','Licensing','＋'],
    ['/admin/license-transfer','License Transfer','Licensing','⇄'],
    ['/admin/order-edit','Edit Orders / License Key','Licensing','✎'],
    ['/admin/order-fulfill','Order Fulfillment','Licensing','✓'],
    ['/admin/renew','Renewals / Billing','Licensing','↻'],
    ['/admin/package-pricing','Package Pricing / Profit','Billing','৳'],
    ['/admin/reseller-activate','Activate Resellers','Resellers','●'],
    ['/admin/resellers','Reseller Management','Resellers','♟'],
    ['/admin/reseller-funds','Reseller Funds','Resellers','$'],
    ['/admin/reseller-levels','Reseller Levels','Resellers','★'],
    ['/admin/reissues','Reissues','Operations','↻'],
    ['/admin/tickets','Tickets','Operations','✉'],
    ['/admin/providers','Providers','Operations','▣'],
    ['/admin/hostname','Cloudflare Hostnames','Products','⌁'],
    ['/admin/reports','Reports','Reports','↗'],
    ['/admin/coupons','Coupons','Reports','◇'],
    ['/admin/api-keys','API Keys','Developer','⌘'],
    ['/admin/staff','Staff & Permissions','Developer','♙'],
    ['/admin/settings','General Settings','Settings','⚙'],
    ['/admin/settings/payments','Payment Methods','Settings','৳'],
    ['/admin/settings/binance','Binance / Crypto','Settings','₿'],
    ['/admin/settings/discord','Discord','Settings','◉'],
    ['/admin/settings/telegram','Telegram','Settings','✈'],
    ['/admin/settings/telegram/message','Telegram Messages','Settings','☷'],
    ['/admin/settings/smtp','SMTP / Email','Settings','@'],
    ['/admin/invoice-settings','Invoice Settings','Settings','▤'],
    ['/admin/settings/whitelabel','White Label','Settings','◇']
  ];

  const side=document.querySelector('.sidebar');
  if(!side)return;

  // Keep the existing sidebar brand/footer, but replace the old navigation links
  // with a complete generated menu so every admin page remains reachable.
  const brand=side.querySelector('.brand');
  const footer=side.querySelector('.sidebar-footer');
  side.querySelectorAll('.nav-label,.side-link').forEach(el=>el.remove());

  const groups=[];
  links.forEach(item=>{
    if(!groups.includes(item[2]))groups.push(item[2]);
  });

  groups.forEach(group=>{
    const label=document.createElement('div');
    label.className='nav-label';
    label.textContent=group;
    side.insertBefore(label,footer);
    links.filter(x=>x[2]===group).forEach(item=>{
      const a=document.createElement('a');
      a.className='side-link';
      a.href=item[0];
      a.innerHTML='<span>'+item[3]+'</span>'+item[1];
      const href=item[0].replace(/\/+$/,'');
      if(href===path)a.classList.add('active');
      side.insertBefore(a,footer);
    });
  });

  // Fix links on legacy dashboard anchors if an older page is cached.
  document.querySelectorAll('.side-link').forEach(a=>{
    const h=(a.getAttribute('href')||'').replace(/\/+$/,'');
    if(h===path)a.classList.add('active');
  });
})();
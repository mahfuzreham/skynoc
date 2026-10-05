(function(){
  const path=location.pathname.replace(/\/+$/,'')||'/admin/dashboard';
  const links=[
    ['/admin/dashboard','Dashboard','Workspace','▦'],
    ['/admin/dashboard#packages','Packages','Workspace','◈'],
    ['/admin/dashboard#orders','Orders','Workspace','▤'],
    ['/admin/dashboard#deposits','Deposits','Workspace','◫'],
    ['/admin/dashboard#licenses','License Inventory','Workspace','▥'],
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
    ['/admin/hostname','Cloudflare Hostnames','Products','⌁'],
    ['/admin/reports','Reports','Reports','↗'],
    ['/admin/coupons','Coupons','Reports','◇'],
    ['/admin/settings','General Settings','Settings','⚙'],
    ['/admin/settings/payments','Payment Methods','Settings','৳'],
    ['/admin/settings/binance','Binance / Crypto','Settings','₿'],
    ['/admin/settings/discord','Discord','Settings','◉'],
    ['/admin/settings/telegram','Telegram','Settings','✈'],
    ['/admin/settings/telegram/message','Telegram Messages','Settings','☷'],
    ['/admin/settings/smtp','SMTP / Email','Settings','@'],
    ['/admin/invoice-settings','Invoice Settings','Settings','▤']
  ];

  const side=document.querySelector('.sidebar');
  if(!side)return;
  const footer=side.querySelector('.sidebar-footer');
  if(!footer)return;

  // Rebuild only the navigation area. Brand and logged-in user footer remain untouched.
  side.querySelectorAll('.nav-label,.side-link').forEach(el=>el.remove());

  const groups=[];
  links.forEach(item=>{if(!groups.includes(item[2]))groups.push(item[2]);});
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
      const base=item[0].split('#')[0].replace(/\/+$/,'');
      if(base===path)a.classList.add('active');
      side.insertBefore(a,footer);
    });
  });

  // Mobile sidebar behaviour used by the main admin shell.
  const menuBtn=document.getElementById('menuBtn');
  const overlay=document.getElementById('overlay');
  if(menuBtn){menuBtn.onclick=function(){side.classList.toggle('open');if(overlay)overlay.classList.toggle('show');};}
  if(overlay){overlay.onclick=function(){side.classList.remove('open');overlay.classList.remove('show');};}
})();
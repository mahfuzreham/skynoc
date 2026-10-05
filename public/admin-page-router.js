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
    ['/admin/providers','Provider Accounts','Licensing','▣'],
    ['/admin/package-pricing','Package Pricing / Profit','Billing','৳'],
    ['/admin/reseller-activate','Activate Resellers','Resellers','●'],
    ['/admin/reseller-manage','Reseller Management','Resellers','♟'],
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

  side.querySelectorAll('.nav-label,.side-link,.nav-group').forEach(el=>el.remove());

  const groups=[];
  links.forEach(item=>{if(!groups.includes(item[2]))groups.push(item[2]);});

  groups.forEach((group,index)=>{
    const items=links.filter(x=>x[2]===group);
    const active=items.some(x=>x[0].replace(/\/+$/,'')===path);
    const details=document.createElement('details');
    details.className='nav-group';
    if(active || group==='Workspace')details.open=true;

    const summary=document.createElement('summary');
    summary.className='nav-group-title';
    summary.innerHTML='<span class="nav-group-icon">'+({Workspace:'▦',Licensing:'◈',Billing:'৳',Resellers:'♟',Products:'⌁',Reports:'↗',Settings:'⚙'}[group]||'•')+'</span><span>'+group+'</span><span class="nav-chevron">⌄</span>';
    details.appendChild(summary);

    const body=document.createElement('div');
    body.className='nav-group-body';
    items.forEach(item=>{
      const a=document.createElement('a');
      a.className='side-link';
      a.href=item[0];
      a.innerHTML='<span class="side-link-icon">'+item[3]+'</span><span>'+item[1]+'</span>';
      const base=item[0].replace(/\/+$/,'');
      if(base===path)a.classList.add('active');
      body.appendChild(a);
    });
    details.appendChild(body);
    side.insertBefore(details,footer);
  });

  const style=document.createElement('style');
  style.textContent=`
    .nav-group{margin:4px 10px 8px;border-radius:12px;overflow:hidden}
    .nav-group-title{list-style:none;display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;cursor:pointer;font-size:12px;font-weight:800;color:#667085;user-select:none}
    .nav-group-title::-webkit-details-marker{display:none}
    .nav-group-title:hover{background:#f2f4f7;color:#344054}
    .nav-group-icon{width:20px;text-align:center;font-size:15px}
    .nav-chevron{margin-left:auto;transition:transform .18s ease;font-size:14px}
    .nav-group[open]>.nav-group-title .nav-chevron{transform:rotate(180deg)}
    .nav-group-body{display:flex;flex-direction:column;gap:2px;padding:2px 4px 7px}
    .nav-group .side-link{display:flex;align-items:center;gap:9px;text-decoration:none;padding:9px 10px 9px 30px;border-radius:9px;font-size:13px;color:#475467;font-weight:600}
    .nav-group .side-link:hover{background:#f2f4f7;color:#101828}
    .nav-group .side-link.active{background:#eef2ff;color:#3447d6;font-weight:800}
    .side-link-icon{width:18px;text-align:center;opacity:.8}
    @media(max-width:900px){.nav-group{margin-left:7px;margin-right:7px}.nav-group .side-link{padding-left:26px}}
  `;
  document.head.appendChild(style);

  const menuBtn=document.getElementById('menuBtn');
  const overlay=document.getElementById('overlay');
  if(menuBtn){menuBtn.onclick=function(){side.classList.toggle('open');if(overlay)overlay.classList.toggle('show');};}
  if(overlay){overlay.onclick=function(){side.classList.remove('open');overlay.classList.remove('show');};}
})();

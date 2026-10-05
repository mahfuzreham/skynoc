(function(){
  const path=location.pathname.replace(/\/+$/,'')||'/admin';
  const links=[
    ['/admin/dashboard','Dashboard'],['/admin/packages','Packages'],['/admin/orders','Orders'],['/admin/deposits','Deposits'],['/admin/licenses','License Inventory'],['/admin/providers','Providers'],['/admin/resellers','Resellers'],['/admin/reissues','Reissues'],['/admin/tickets','Tickets'],['/admin/api-keys','API Keys'],['/admin/staff','Staff'],['/admin/reseller-levels','Reseller Levels'],['/admin/reports','Reports'],['/admin/coupons','Coupons'],['/admin/settings','Settings']
  ];
  document.querySelectorAll('.side-link').forEach(a=>{const h=a.getAttribute('href');if(h&&h.charAt(0)==='#'){const key=h.slice(1);const hit=links.find(x=>x[0]==='/admin/'+key);if(hit)a.href=hit[0];}});
  const side=document.querySelector('.sidebar');
  if(side){
    const label=[...side.querySelectorAll('.nav-label')].find(x=>x.textContent.trim().toLowerCase()==='management');
    if(label){
      const wanted=['providers','resellers','reissues','tickets','api-keys','staff'];
      wanted.forEach(key=>{if(side.querySelector('.side-link[href="/admin/'+key+'"]'))return;const a=document.createElement('a');a.className='side-link';a.href='/admin/'+key;a.innerHTML='<span>•</span>'+links.find(x=>x[0]==='/admin/'+key)[1];label.parentNode.insertBefore(a,label);});
    }
    side.querySelectorAll('.side-link').forEach(a=>{const h=(a.getAttribute('href')||'').replace(/\/+$/,'')||'/';if(h===path)a.classList.add('active');else a.classList.remove('active');});
  }
})();
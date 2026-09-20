{{-- ٢١-٧: رموز تتبع بلاغاتك محفوظة في هذا المتصفح (ipa-my-reports) — تتابعها بضغطة بلا كتابة الرمز. لا يصل الخادم منها شيء. --}}
<div id="myReports" class="card p-3 mt-3" hidden>
  <div class="fw-bold mb-2"><i class="bi bi-bookmark-check me-1"></i> بلاغاتك من هذا الجهاز</div>
  <div class="d-flex flex-column gap-1" data-list></div>
</div>
@push('scripts')
<script>
(function(){
  var box=document.getElementById('myReports'); if(!box) return;
  var list=[]; try{ list=JSON.parse(localStorage.getItem('ipa-my-reports')||'[]'); }catch(e){}
  if(!Array.isArray(list)||!list.length) return;
  var wrap=box.querySelector('[data-list]');
  list.slice(0,8).forEach(function(r){
    if(!r||!/^[A-Z0-9]{6,20}$/.test(r.c||'')) return;
    var a=document.createElement('a'); a.className='btn btn-sm btn-outline-success text-start';
    a.href={{ \Illuminate\Support\Js::from(route('incident.track')) }}+'?code='+encodeURIComponent(r.c);
    a.textContent=(r.n||'بلاغ')+' — '+(r.p||'')+' · '+(r.d||'');
    wrap.appendChild(a);
  });
  if(wrap.children.length) box.hidden=false;
})();
</script>
@endpush

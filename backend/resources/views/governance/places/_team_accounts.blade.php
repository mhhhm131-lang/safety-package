{{-- ١٩-٥: اقتراح الحسابات بجانب الاسم (المسار القائم /api/team-accounts من ١٥-٤) — اختيار الحساب يملأ الاسم إن كان فارغاً --}}
<script>
(function(){
  var d=document.getElementById('teamAccts'); if(!d) return;
  fetch('/api/team-accounts',{credentials:'same-origin',headers:{Accept:'application/json'}})
    .then(function(r){return r.ok?r.json():[];})
    .then(function(j){
      if(!Array.isArray(j)) return;
      var byU={};
      j.forEach(function(a){ byU[String(a.u).toLowerCase()]=a; var o=document.createElement('option'); o.value=a.u; o.label=a.n+' — '+a.r; d.appendChild(o); });
      document.querySelectorAll('[data-role-row]').forEach(function(row){
        var u=row.querySelector('input[name$="[user]"]'), n=row.querySelector('input[name$="[name]"]');
        if(!u||!n) return;
        u.addEventListener('change',function(){ var a=byU[u.value.trim().toLowerCase()]; if(a&&!n.value.trim()) n.value=a.n; });
      });
    }).catch(function(){});
})();
</script>

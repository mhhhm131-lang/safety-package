{{-- المرحلة ٢١-٢ (قرار ٥٤): وضع التجربة — كل من يدخل يعرف أن ما يُدخله الآن يُحذف --}}
@if(\App\Core\Trial\TrialMode::isOn())
<div id="trialBanner" role="status" style="background:#fff3cd;color:#664d03;border-bottom:2px solid #d9b25a;text-align:center;font-weight:700;font-size:.85rem;padding:.35rem .75rem">
  <i class="bi bi-cone-striped"></i> وضع التجربة — كل ما يُدخل الآن يُحذف عند إنهائها
</div>
@endif

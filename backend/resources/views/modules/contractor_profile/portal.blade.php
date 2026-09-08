<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بوابة رفع الوثائق — {{ $contractor->name }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <style>
        body { background: #f8f9fa; }
        .portal-card { max-width: 640px; margin: 3rem auto; }
    </style>
</head>
<body>
<div class="portal-card">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white text-center py-3">
            <h5 class="mb-0"><i class="bi bi-upload"></i> بوابة رفع الوثائق</h5>
            <small>{{ $contractor->name }}</small>
        </div>
        <div class="card-body p-4">
            <p class="text-muted small mb-4">
                يرجى رفع الوثائق المطلوبة. سيقوم مدير السلامة بمراجعتها والتواصل معك.
                <strong>الرابط صالح لمرة واحدة فقط.</strong>
            </p>

            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0 small">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST"
                  action="{{ route('contractor-portal.submit', $profile->portal_link_token) }}"
                  enctype="multipart/form-data"
                  id="portalForm">
                @csrf

                <div id="docsList">
                    <div class="doc-row border rounded p-3 mb-3">
                        <div class="row g-2">
                            <div class="col-md-5">
                                <label class="form-label small fw-semibold">نوع الوثيقة</label>
                                <select name="documents[0][document_type]" class="form-select form-select-sm" required>
                                    <option value="">— اختر —</option>
                                    <option value="cr">السجل التجاري</option>
                                    <option value="insurance">وثيقة التأمين</option>
                                    <option value="safety_cert">شهادة السلامة</option>
                                    <option value="iso_cert">شهادة ISO</option>
                                    <option value="license">رخصة</option>
                                    <option value="other">أخرى</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">تاريخ الانتهاء</label>
                                <input type="date" name="documents[0][expiry_date]" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <input type="file" name="documents[0][file]"
                                    class="form-control form-control-sm"
                                    accept=".pdf,.jpg,.jpeg,.png" required>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="button" id="addDocBtn" class="btn btn-sm btn-outline-secondary mb-3">
                    <i class="bi bi-plus"></i> إضافة وثيقة أخرى
                </button>

                <div class="d-grid mt-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send"></i> إرسال الوثائق
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let docIndex = 1;
document.getElementById('addDocBtn').addEventListener('click', function() {
    const row = document.querySelector('.doc-row').cloneNode(true);
    row.querySelectorAll('[name]').forEach(el => {
        el.name = el.name.replace(/\[\d+\]/, '[' + docIndex + ']');
        if (el.type !== 'select-one') el.value = '';
    });
    document.getElementById('docsList').appendChild(row);
    docIndex++;
});
</script>
</body>
</html>

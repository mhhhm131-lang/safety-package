<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>تسجيل الزوار - كشك</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .kiosk-container {
            max-width: 600px;
            width: 100%;
            padding: 20px;
        }
        .kiosk-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .kiosk-header {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .kiosk-header h1 {
            margin: 0;
            font-size: 2rem;
        }
        .kiosk-header p {
            margin: 10px 0 0;
            opacity: 0.9;
        }
        .kiosk-body {
            padding: 30px;
        }
        .form-floating > label {
            right: 0;
            left: auto;
        }
        .btn-check-in {
            background: linear-gradient(135deg, #198754 0%, #157347 100%);
            border: none;
            padding: 15px 30px;
            font-size: 1.2rem;
            border-radius: 10px;
        }
        .btn-check-out {
            background: linear-gradient(135deg, #dc3545 0%, #bb2d3b 100%);
            border: none;
            padding: 15px 30px;
            font-size: 1.2rem;
            border-radius: 10px;
        }
        .success-screen {
            display: none;
            text-align: center;
            padding: 40px;
        }
        .success-screen .qr-container {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 15px;
            display: inline-block;
            margin: 20px 0;
        }
        .success-screen .badge-number {
            font-size: 2rem;
            font-weight: bold;
            color: #0d6efd;
        }
        .checkout-success {
            display: none;
        }
        .tab-pills {
            display: flex;
            margin-bottom: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 5px;
        }
        .tab-pill {
            flex: 1;
            text-align: center;
            padding: 15px;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.3s;
        }
        .tab-pill.active {
            background: #0d6efd;
            color: white;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
    </style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>
    <div class="kiosk-container">
        <div class="kiosk-card">
            <div class="kiosk-header">
                <h1><i class="bi bi-person-badge me-2"></i>تسجيل الزوار</h1>
                <p>مرحباً بك في نظام إدارة الزوار</p>
            </div>

            <div class="kiosk-body">
                <!-- Tab Pills -->
                <div class="tab-pills">
                    <div class="tab-pill active" data-tab="checkin">
                        <i class="bi bi-box-arrow-in-left me-1"></i>تسجيل دخول
                    </div>
                    <div class="tab-pill" data-tab="checkout">
                        <i class="bi bi-box-arrow-right me-1"></i>تسجيل خروج
                    </div>
                </div>

                <!-- Check-in Form -->
                <div class="tab-content active" id="checkin-tab">
                    <form id="checkinForm">
                        <div class="mb-3">
                            <label class="form-label">المبنى <span class="text-danger">*</span></label>
                            <select name="building_id" class="form-select form-select-lg" required>
                                <option value="">اختر المبنى</option>
                                @foreach($buildings as $building)
                                    <option value="{{ $building->id }}">{{ $building->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">الاسم الكامل <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control form-control-lg"
                                   placeholder="أدخل اسمك الكامل" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">رقم الهاتف</label>
                                <input type="tel" name="phone" class="form-control form-control-lg"
                                       placeholder="05xxxxxxxx">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">الشركة</label>
                                <input type="text" name="company" class="form-control form-control-lg"
                                       placeholder="اسم الشركة">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">سبب الزيارة</label>
                            <input type="text" name="purpose" class="form-control form-control-lg"
                                   placeholder="مثال: اجتماع، صيانة، توصيل">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">رقم لوحة المركبة (إن وجد)</label>
                            <input type="text" name="vehicle_plate" class="form-control form-control-lg"
                                   placeholder="مثال: ABC 1234">
                        </div>

                        <div class="form-check mb-4">
                            <input type="checkbox" class="form-check-input" id="needsAssistance" name="needs_assistance">
                            <label class="form-check-label" for="needsAssistance">
                                أحتاج مساعدة خاصة أثناء حالات الطوارئ
                            </label>
                        </div>

                        <div id="assistanceTypes" class="mb-4" style="display: none;">
                            <label class="form-label">نوع المساعدة المطلوبة</label>
                            <select name="assistance_type" class="form-select">
                                <option value="">اختر</option>
                                <option value="wheelchair">كرسي متحرك</option>
                                <option value="visual">ضعف بصري</option>
                                <option value="hearing">ضعف سمعي</option>
                                <option value="mobility">صعوبة حركة</option>
                                <option value="medical">حالة طبية</option>
                                <option value="other">أخرى</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-check-in text-white w-100">
                            <i class="bi bi-check-circle me-2"></i>تسجيل الدخول
                        </button>
                    </form>
                </div>

                <!-- Check-out Tab -->
                <div class="tab-content" id="checkout-tab">
                    <div class="text-center mb-4">
                        <i class="bi bi-qr-code-scan display-1 text-primary"></i>
                        <p class="mt-3">امسح رمز QR الخاص بك أو أدخل رقم البطاقة</p>
                    </div>

                    <form id="checkoutForm">
                        <div class="mb-4">
                            <label class="form-label">رمز QR أو رقم البطاقة</label>
                            <input type="text" name="qr_token" class="form-control form-control-lg text-center"
                                   placeholder="أدخل الرمز هنا" required>
                        </div>

                        <button type="submit" class="btn btn-check-out text-white w-100">
                            <i class="bi bi-box-arrow-right me-2"></i>تسجيل الخروج
                        </button>
                    </form>
                </div>

                <!-- Success Screen (Check-in) -->
                <div class="success-screen" id="checkinSuccess">
                    <i class="bi bi-check-circle-fill text-success display-1"></i>
                    <h2 class="mt-3">تم التسجيل بنجاح!</h2>
                    <p class="text-muted">مرحباً بك</p>

                    <div class="qr-container">
                        <img id="successQrImage" src="" alt="QR Code" style="max-width: 200px;">
                    </div>

                    <p class="badge-number" id="successBadgeNumber"></p>
                    <p class="text-muted">احتفظ بهذا الرمز لتسجيل الخروج</p>

                    <div class="d-flex gap-3 justify-content-center mt-4">
                        <button class="btn btn-outline-primary btn-lg" onclick="printBadge()">
                            <i class="bi bi-printer me-1"></i>طباعة
                        </button>
                        <button class="btn btn-primary btn-lg" onclick="resetKiosk()">
                            <i class="bi bi-arrow-repeat me-1"></i>زائر جديد
                        </button>
                    </div>
                </div>

                <!-- Success Screen (Check-out) -->
                <div class="checkout-success" id="checkoutSuccess">
                    <div class="text-center">
                        <i class="bi bi-hand-thumbs-up-fill text-success display-1"></i>
                        <h2 class="mt-3">مع السلامة!</h2>
                        <p class="text-muted" id="checkoutDuration"></p>
                        <button class="btn btn-primary btn-lg mt-4" onclick="resetKiosk()">
                            <i class="bi bi-arrow-repeat me-1"></i>زائر جديد
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <p class="text-center text-white-50 mt-4">
            <small>نظام OHSMS للسلامة والصحة المهنية</small>
        </p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        // Tab switching
        document.querySelectorAll('.tab-pill').forEach(pill => {
            pill.addEventListener('click', function() {
                document.querySelectorAll('.tab-pill').forEach(p => p.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

                this.classList.add('active');
                document.getElementById(this.dataset.tab + '-tab').classList.add('active');
            });
        });

        // Toggle assistance types
        document.getElementById('needsAssistance').addEventListener('change', function() {
            document.getElementById('assistanceTypes').style.display = this.checked ? 'block' : 'none';
        });

        // Check-in form
        document.getElementById('checkinForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const data = Object.fromEntries(formData.entries());
            data.needs_assistance = document.getElementById('needsAssistance').checked;

            try {
                const response = await fetch('/api/emergency/visitors/check-in', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify(data)
                });

                const result = await response.json();

                if (result.success) {
                    document.getElementById('checkin-tab').style.display = 'none';
                    document.getElementById('checkinSuccess').style.display = 'block';
                    const qrBox = document.getElementById('successQrImage');
                    const holder = document.createElement('div'); holder.id = 'successQrBox'; qrBox.replaceWith(holder);
                    new QRCode(holder, {text: result.data.qr_content || result.data.qr_token, width: 220, height: 220});
                    document.getElementById('successBadgeNumber').textContent = result.data.badge_number;
                    document.querySelector('.tab-pills').style.display = 'none';
                } else {
                    alert(result.message || 'حدث خطأ في التسجيل');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('حدث خطأ في الاتصال');
            }
        });

        // Check-out form
        document.getElementById('checkoutForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const qrToken = this.querySelector('[name="qr_token"]').value;

            try {
                const response = await fetch('/api/emergency/visitors/check-out-qr', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ qr_token: qrToken })
                });

                const result = await response.json();

                if (result.success) {
                    document.getElementById('checkout-tab').style.display = 'none';
                    document.getElementById('checkoutSuccess').style.display = 'block';
                    document.getElementById('checkoutDuration').textContent =
                        `شكراً ${result.data.visitor_name}، مدة الزيارة: ${result.data.duration}`;
                    document.querySelector('.tab-pills').style.display = 'none';
                } else {
                    alert(result.message || 'رمز غير صحيح');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('حدث خطأ في الاتصال');
            }
        });

        function resetKiosk() {
            document.getElementById('checkinForm').reset();
            document.getElementById('checkoutForm').reset();
            document.getElementById('checkin-tab').style.display = 'block';
            document.getElementById('checkout-tab').style.display = 'none';
            document.getElementById('checkinSuccess').style.display = 'none';
            document.getElementById('checkoutSuccess').style.display = 'none';
            document.querySelector('.tab-pills').style.display = 'flex';
            document.querySelectorAll('.tab-pill')[0].click();
        }

        function printBadge() {
            const qrImage = document.getElementById('successQrImage').src;
            const badgeNumber = document.getElementById('successBadgeNumber').textContent;

            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html dir="rtl">
                <head>
                    <title>بطاقة الزائر</title>
                    <style>
                        body {
                            font-family: Arial, sans-serif;
                            text-align: center;
                            padding: 20px;
                        }
                        .badge-card {
                            border: 2px solid #333;
                            padding: 20px;
                            display: inline-block;
                            border-radius: 10px;
                        }
                        .badge-number {
                            font-size: 1.5rem;
                            font-weight: bold;
                            margin-top: 10px;
                        }
                    </style>
                </head>
                <body>
                    <div class="badge-card">
                        <h2>بطاقة زائر</h2>
                        <img src="${qrImage}" style="max-width: 150px;">
                        <p class="badge-number">${badgeNumber}</p>
                        <p><small>امسح الرمز عند الخروج</small></p>
                    </div>
                </body>
                </html>
            `);
            printWindow.document.close();
            setTimeout(() => printWindow.print(), 500);
        }
    </script>
</body>
</html>

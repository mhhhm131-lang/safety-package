{{--
    Panic Button Component
    Usage: @include('modules.emergency.components.panic-button')
    Or: @include('modules.emergency.components.panic-button', ['floating' => true])
--}}

@props(['floating' => false, 'size' => 'normal'])

@php
    $buttonClass = $floating ? 'panic-button-floating' : 'panic-button-inline';
    $sizeClass = $size === 'small' ? 'panic-button-sm' : ($size === 'large' ? 'panic-button-lg' : '');
@endphp

<style>
    .panic-button-container {
        --panic-red: #dc3545;
        --panic-red-dark: #bb2d3b;
    }

    .panic-button {
        background: var(--panic-red);
        color: white;
        border: none;
        border-radius: 50%;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(220, 53, 69, 0.4);
    }

    .panic-button:hover {
        background: var(--panic-red-dark);
        transform: scale(1.05);
        box-shadow: 0 6px 20px rgba(220, 53, 69, 0.5);
    }

    .panic-button:active {
        transform: scale(0.95);
    }

    .panic-button-inline {
        width: 60px;
        height: 60px;
        font-size: 24px;
    }

    .panic-button-inline.panic-button-sm {
        width: 40px;
        height: 40px;
        font-size: 18px;
    }

    .panic-button-inline.panic-button-lg {
        width: 80px;
        height: 80px;
        font-size: 32px;
    }

    .panic-button-floating {
        position: fixed;
        bottom: 30px;
        left: 30px;
        width: 70px;
        height: 70px;
        font-size: 28px;
        z-index: 9999;
        animation: panic-pulse 2s infinite;
    }

    @keyframes panic-pulse {
        0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
        50% { box-shadow: 0 0 0 15px rgba(220, 53, 69, 0); }
        100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
    }

    .panic-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.8);
        z-index: 10000;
        display: none;
        align-items: center;
        justify-content: center;
    }

    .panic-modal-overlay.active {
        display: flex;
    }

    .panic-modal {
        background: white;
        border-radius: 16px;
        padding: 30px;
        max-width: 400px;
        width: 90%;
        text-align: center;
    }

    .panic-type-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
        margin: 20px 0;
    }

    .panic-type-btn {
        padding: 20px;
        border: 2px solid #dee2e6;
        border-radius: 12px;
        background: white;
        cursor: pointer;
        transition: all 0.2s;
    }

    .panic-type-btn:hover {
        border-color: var(--panic-red);
        background: #fff5f5;
    }

    .panic-type-btn i {
        font-size: 32px;
        display: block;
        margin-bottom: 8px;
    }

    .panic-type-btn.type-panic i { color: #dc3545; }
    .panic-type-btn.type-medical i { color: #e83e8c; }
    .panic-type-btn.type-fire i { color: #fd7e14; }
    .panic-type-btn.type-security i { color: #6f42c1; }

    .panic-cancel-btn {
        background: #6c757d;
        color: white;
        border: none;
        padding: 12px 30px;
        border-radius: 8px;
        cursor: pointer;
        margin-top: 15px;
    }

    .panic-sending {
        display: none;
    }

    .panic-sending.active {
        display: block;
    }

    .panic-sending .spinner {
        width: 50px;
        height: 50px;
        border: 4px solid #f3f3f3;
        border-top: 4px solid var(--panic-red);
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 0 auto 20px;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
</style>

<div class="panic-button-container">
    <button type="button" class="panic-button {{ $buttonClass }} {{ $sizeClass }}" onclick="openPanicModal()" title="تنبيه الذعر">
        <i class="bi bi-exclamation-triangle-fill"></i>
    </button>

    <div class="panic-modal-overlay" id="panicModalOverlay">
        <div class="panic-modal">
            <div id="panicTypeSelection">
                <h4 style="color: #dc3545; margin-bottom: 10px;">
                    <i class="bi bi-exclamation-triangle-fill"></i> تنبيه طوارئ
                </h4>
                <p class="text-muted">اختر نوع الطوارئ:</p>

                <div class="panic-type-grid">
                    <button class="panic-type-btn type-panic" onclick="triggerPanic('panic')">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        <span>ذعر عام</span>
                    </button>
                    <button class="panic-type-btn type-medical" onclick="triggerPanic('medical')">
                        <i class="bi bi-heart-pulse-fill"></i>
                        <span>طوارئ طبية</span>
                    </button>
                    <button class="panic-type-btn type-fire" onclick="triggerPanic('fire')">
                        <i class="bi bi-fire"></i>
                        <span>حريق</span>
                    </button>
                    <button class="panic-type-btn type-security" onclick="triggerPanic('security')">
                        <i class="bi bi-shield-exclamation"></i>
                        <span>تهديد أمني</span>
                    </button>
                </div>

                <button class="panic-cancel-btn" onclick="closePanicModal()">إلغاء</button>
            </div>

            <div class="panic-sending" id="panicSending">
                <div class="spinner"></div>
                <h5>جاري إرسال التنبيه...</h5>
                <p class="text-muted">يتم تحديد موقعك وإرسال التنبيه لفريق الأمن</p>
            </div>

            <div class="panic-sending" id="panicSuccess" style="display: none;">
                <i class="bi bi-check-circle-fill text-success" style="font-size: 60px;"></i>
                <h5 class="text-success mt-3">تم إرسال التنبيه</h5>
                <p class="text-muted">فريق الأمن في الطريق إليك. ابقَ في مكانك.</p>
                <button class="panic-cancel-btn" onclick="closePanicModal()">حسناً</button>
            </div>

            <div class="panic-sending" id="panicError" style="display: none;">
                <i class="bi bi-x-circle-fill text-danger" style="font-size: 60px;"></i>
                <h5 class="text-danger mt-3">فشل الإرسال</h5>
                <p class="text-muted" id="panicErrorMsg">حدث خطأ. حاول مرة أخرى.</p>
                <button class="panic-cancel-btn" onclick="retryPanic()">إعادة المحاولة</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    let currentLocation = null;
    let lastAlertType = null;

    window.openPanicModal = function() {
        document.getElementById('panicModalOverlay').classList.add('active');
        document.getElementById('panicTypeSelection').style.display = 'block';
        document.getElementById('panicSending').style.display = 'none';
        document.getElementById('panicSuccess').style.display = 'none';
        document.getElementById('panicError').style.display = 'none';

        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    currentLocation = {
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        accuracy_meters: position.coords.accuracy
                    };
                },
                (error) => {
                    console.warn('Could not get location:', error);
                    currentLocation = null;
                },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        }
    };

    window.closePanicModal = function() {
        document.getElementById('panicModalOverlay').classList.remove('active');
    };

    window.triggerPanic = function(alertType) {
        lastAlertType = alertType;
        document.getElementById('panicTypeSelection').style.display = 'none';
        document.getElementById('panicSending').style.display = 'block';
        document.getElementById('panicSending').classList.add('active');

        const data = {
            alert_type: alertType,
            severity: 'high'
        };

        if (currentLocation) {
            data.latitude = currentLocation.latitude;
            data.longitude = currentLocation.longitude;
            data.accuracy_meters = currentLocation.accuracy_meters;
        }

        fetch('/api/emergency/panic/trigger', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            credentials: 'same-origin',
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(result => {
            document.getElementById('panicSending').style.display = 'none';
            if (result.success) {
                document.getElementById('panicSuccess').style.display = 'block';
            } else {
                document.getElementById('panicErrorMsg').textContent = result.message || 'حدث خطأ. حاول مرة أخرى.';
                document.getElementById('panicError').style.display = 'block';
            }
        })
        .catch(error => {
            console.error('Panic trigger error:', error);
            document.getElementById('panicSending').style.display = 'none';
            document.getElementById('panicErrorMsg').textContent = 'فشل الاتصال. تأكد من اتصالك بالإنترنت.';
            document.getElementById('panicError').style.display = 'block';
        });
    };

    window.retryPanic = function() {
        if (lastAlertType) {
            document.getElementById('panicError').style.display = 'none';
            triggerPanic(lastAlertType);
        } else {
            document.getElementById('panicError').style.display = 'none';
            document.getElementById('panicTypeSelection').style.display = 'block';
        }
    };
})();
</script>

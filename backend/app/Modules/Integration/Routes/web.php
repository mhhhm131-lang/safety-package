<?php

use App\Modules\Integration\Controllers\DeviceController;
use App\Modules\Integration\Controllers\IoTController;
use App\Modules\Integration\Controllers\IotDevicesController;
use App\Modules\Integration\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
 * المرحلة ٥ — إنترنت الأشياء. الشاشات تحت /app/emergency/iot، الواجهة البرمجية تحت /api/iot بجلسة المتصفح،
 * وWebhooks الأجهزة بلا جلسة بتوقيع HMAC لكل جهاز.
 *   integration.manage (مسؤول السلامة والمناوب): الأجهزة والقائمة المسموحة والبروتوكولات
 *   emergency.trigger: أوامر الأنظمة (أبواب/مصاعد/تكييف/شاشات/إغلاق)
 *   emergency.view|respond: الاطلاع على الحالة
 */
Route::middleware(['web', 'auth'])->prefix('app/emergency/iot')->name('emergency.iot.')->group(function () {
    Route::middleware('permission:emergency.view,emergency.respond')->group(function () {
        Route::get('/', [IotDevicesController::class, 'dashboard'])->name('dashboard');
        Route::get('/events', [IotDevicesController::class, 'events'])->name('events');
        Route::get('/cameras', [DeviceController::class, 'camerasDashboard'])->name('cameras.dashboard');
        Route::get('/wearables', [DeviceController::class, 'wearablesDashboard'])->name('wearables.dashboard');
        Route::get('/wearables/alerts', [DeviceController::class, 'wearablesAlerts'])->name('wearables.alerts');
    });
    Route::middleware('permission:integration.manage')->group(function () {
        Route::get('/devices', [IotDevicesController::class, 'index'])->name('devices.index');
        Route::get('/devices/create', [IotDevicesController::class, 'create'])->name('devices.create');
        Route::post('/devices', [IotDevicesController::class, 'store'])->name('devices.store');
        Route::post('/devices/allowed-hosts', [IotDevicesController::class, 'allowedHosts'])->name('devices.allowed');
        Route::get('/devices/{device}', [IotDevicesController::class, 'show'])->name('devices.show')->whereNumber('device');
        Route::get('/devices/{device}/edit', [IotDevicesController::class, 'edit'])->name('devices.edit');
        Route::put('/devices/{device}', [IotDevicesController::class, 'update'])->name('devices.update');
        Route::delete('/devices/{device}', [IotDevicesController::class, 'destroy'])->name('devices.destroy');
        Route::post('/devices/{device}/test', [IotDevicesController::class, 'test'])->name('devices.test');
        Route::post('/devices/{device}/rotate-secret', [IotDevicesController::class, 'rotateSecret'])->name('devices.rotate');
        Route::get('/cameras/create', [DeviceController::class, 'camerasCreate'])->name('cameras.create');
        Route::post('/cameras', [DeviceController::class, 'camerasStore'])->name('cameras.store');
    });
});

Route::middleware(['web', 'auth'])->prefix('api/iot')->name('api.iot.')->group(function () {
    Route::middleware('permission:emergency.view,emergency.respond')->group(function () {
        Route::get('/buildings/{building}/status', [IoTController::class, 'getSystemStatus'])->name('status');
        Route::get('/fire-panel/buildings/{building}/status', [IoTController::class, 'getFirePanelStatus'])->name('fire-panel.status');
        Route::get('/fire-panel/buildings/{building}/zones', [IoTController::class, 'getFireZones'])->name('fire-panel.zones');
        Route::get('/access-control/buildings/{building}/occupancy', [IoTController::class, 'getOccupancy'])->name('access-control.occupancy');
        Route::get('/access-control/buildings/{building}/personnel', [IoTController::class, 'getPersonnelInBuilding'])->name('access-control.personnel');
        Route::get('/access-control/buildings/{building}/doors', [IoTController::class, 'getDoorStatuses'])->name('access-control.doors');
        Route::get('/access-control/buildings/{building}/mustering', [IoTController::class, 'getMusteringData'])->name('access-control.mustering');
        Route::get('/elevator/buildings/{building}/status', [IoTController::class, 'getElevatorStatuses'])->name('elevator.status');
        Route::get('/hvac/buildings/{building}/status', [IoTController::class, 'getHvacStatus'])->name('hvac.status');
        Route::get('/signage/buildings/{building}/displays', [IoTController::class, 'getDisplays'])->name('signage.displays');
        Route::get('/lockdown/buildings/{building}/status', [IoTController::class, 'getLockdownStatus'])->name('lockdown.status');
        Route::get('/cameras', [DeviceController::class, 'cameras'])->name('cameras.index');
        Route::get('/cameras/building/{buildingId}', [DeviceController::class, 'camerasByBuilding'])->name('cameras.by-building');
        Route::get('/wearables', [DeviceController::class, 'wearables'])->name('wearables.index');
        Route::get('/wearables/alerts', [DeviceController::class, 'wearableAlerts'])->name('wearables.alerts.index');
    });
    Route::middleware('permission:emergency.trigger')->group(function () {
        Route::post('/fire-panel/buildings/{building}/acknowledge', [IoTController::class, 'acknowledgeAlarm'])->name('fire-panel.acknowledge');
        Route::post('/fire-panel/buildings/{building}/silence', [IoTController::class, 'silenceAlarm'])->name('fire-panel.silence');
        Route::post('/fire-panel/buildings/{building}/reset', [IoTController::class, 'resetFirePanel'])->name('fire-panel.reset');
        Route::post('/access-control/buildings/{building}/unlock', [IoTController::class, 'unlockDoor'])->name('access-control.unlock');
        Route::post('/access-control/buildings/{building}/lock', [IoTController::class, 'lockDoor'])->name('access-control.lock');
        Route::post('/access-control/buildings/{building}/emergency-unlock', [IoTController::class, 'emergencyUnlock'])->name('access-control.emergency-unlock');
        Route::post('/elevator/buildings/{building}/fire-recall', [IoTController::class, 'activateFireRecall'])->name('elevator.fire-recall');
        Route::post('/elevator/buildings/{building}/firefighter-mode', [IoTController::class, 'activateFirefighterMode'])->name('elevator.firefighter');
        Route::post('/elevator/buildings/{building}/normal', [IoTController::class, 'returnElevatorsToNormal'])->name('elevator.normal');
        Route::post('/hvac/buildings/{building}/smoke-control', [IoTController::class, 'activateSmokeControl'])->name('hvac.smoke-control');
        Route::post('/hvac/buildings/{building}/shutdown', [IoTController::class, 'emergencyHvacShutdown'])->name('hvac.shutdown');
        Route::post('/hvac/buildings/{building}/purge', [IoTController::class, 'activatePurgeMode'])->name('hvac.purge');
        Route::post('/hvac/buildings/{building}/normal', [IoTController::class, 'returnHvacToNormal'])->name('hvac.normal');
        Route::post('/signage/buildings/{building}/emergency-alert', [IoTController::class, 'pushEmergencyAlert'])->name('signage.alert');
        Route::post('/signage/buildings/{building}/evacuation-routes', [IoTController::class, 'showEvacuationRoutes'])->name('signage.evacuation');
        Route::post('/signage/buildings/{building}/all-clear', [IoTController::class, 'showAllClear'])->name('signage.all-clear');
        Route::post('/signage/buildings/{building}/normal', [IoTController::class, 'returnSignageToNormal'])->name('signage.normal');
        Route::post('/lockdown/buildings/{building}/initiate', [IoTController::class, 'initiateLockdown'])->name('lockdown.initiate');
        Route::post('/lockdown/buildings/{building}/lift', [IoTController::class, 'liftLockdown'])->name('lockdown.lift');
    });
    Route::middleware('permission:integration.manage')->prefix('protocols')->name('protocols.')->group(function () {
        Route::post('/bacnet/test', [IoTController::class, 'testBacnetConnection'])->name('bacnet.test');
        Route::post('/bacnet/read', [IoTController::class, 'readBacnetProperty'])->name('bacnet.read');
        Route::post('/bacnet/discover', [IoTController::class, 'discoverBacnetDevices'])->name('bacnet.discover');
        Route::post('/modbus/test', [IoTController::class, 'testModbusConnection'])->name('modbus.test');
        Route::post('/modbus/read', [IoTController::class, 'readModbusRegisters'])->name('modbus.read');
        Route::post('/mqtt/test', [IoTController::class, 'testMqttConnection'])->name('mqtt.test');
        Route::post('/mqtt/publish', [IoTController::class, 'publishMqttMessage'])->name('mqtt.publish');
    });
    // الكاميرات والأساور: تسجيل لكل حساب، والاستلام للمستجيبين
    Route::middleware('permission:integration.manage')->post('/cameras', [DeviceController::class, 'storeCamera'])->name('cameras.store');
    Route::post('/wearables/register', [DeviceController::class, 'registerWearable'])->name('wearables.register');
    Route::post('/wearables/{wearable}/heartbeat', [DeviceController::class, 'heartbeat'])->name('wearables.heartbeat');
    Route::post('/wearables/{wearable}/alert', [DeviceController::class, 'triggerWearableAlert'])->name('wearables.alert');
    Route::middleware('permission:emergency.respond,emergency.trigger')->group(function () {
        Route::post('/wearables/alerts/{alert}/acknowledge', [DeviceController::class, 'acknowledgeAlert'])->name('wearables.alerts.acknowledge');
        Route::post('/wearables/alerts/{alert}/resolve', [DeviceController::class, 'resolveAlert'])->name('wearables.alerts.resolve');
    });
});

// Webhooks الأجهزة: بلا جلسة — التوقيع هو المصادقة. مرشح معدل يحمي من الإغراق.
Route::middleware(['throttle:iot-webhook'])->post('/api/iot/webhooks/{device}', [WebhookController::class, 'handle'])->name('api.iot.webhook')->whereNumber('device');

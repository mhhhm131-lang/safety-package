<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // خلف وكيل Render: نقرأ X-Forwarded-Proto وإلا صارت التحويلات http:// ورفضها المتصفح (mixed content)
        $middleware->trustProxies(at: '*');
        // المرحلة ٥: Webhooks الأجهزة بلا جلسة ولا CSRF — التوقيع HMAC لكل جهاز هو المصادقة (WebhookController)
        $middleware->validateCsrfTokens(except: ['api/iot/webhooks/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // مسؤول السلامة يرى نص الخطأ (بلا تتبع) ليُشخَّص الخلل على Render بلا وصول للسجلات.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (config('app.debug') || !$request->user() || $request->user()->role() !== 'system_admin') {
                return null;
            }
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException) {
                return null;
            }
            $msg = class_basename($e).': '.mb_substr($e->getMessage(), 0, 600);
            return $request->expectsJson()
                ? response()->json(['message' => 'خطأ في الخادم', 'error' => $msg], 500)
                : response('<!doctype html><meta charset="utf-8"><body dir="rtl" style="font-family:sans-serif;padding:24px"><h2>خطأ في الخادم</h2><pre dir="ltr" style="white-space:pre-wrap">'.e($msg).'</pre><a href="/app">← الرئيسية</a></body>', 500);
        });
    })->create();

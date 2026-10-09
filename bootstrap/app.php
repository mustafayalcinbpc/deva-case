<?php

use App\Exceptions\CleaningRuleViolation;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [EnsureUserIsActive::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // İş kuralı ihlali kullanıcı hatasıdır: önceki sayfaya mesajla dönülür (docs/plan-ekranlar.md).
        $exceptions->render(function (CleaningRuleViolation $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['rule' => $e->rule, 'message' => $e->getMessage(), 'context' => $e->context], 422);
            }

            return back()
                ->withInput()
                ->withErrors(['workflow' => $e->getMessage()])
                ->with('violation', ['rule' => $e->rule, 'context' => $e->context]);
        });
    })->create();

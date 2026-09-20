<?php

use App\Http\Middleware\PortalSession;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
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
        $middleware->web(append: [
            SetLocale::class,
        ]);

        // Every response, not just web: the JSON API and the deploy webhook
        // should not advertise the PHP version either.
        $middleware->append(SecurityHeaders::class);

        // The Pilgrim Portal's own gate. An alias rather than a group, so
        // the entry route — the one that spends a link — is deliberately
        // outside it.
        $middleware->alias(['portal' => PortalSession::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A URL that matches no route never reaches the web middleware group,
        // so SetLocale has not run by the time the error page renders. Without
        // this, a 404 under /dv/ came back as an English document — English
        // text, dir="ltr", and both of its "back to the site" links pointing
        // at /en, stranding a Dhivehi visitor in the wrong language.
        //
        // Returning null hands the exception back to Laravel's own rendering;
        // this callback only adjusts the locale it will render in.
        $exceptions->render(function (Throwable $e, Request $request): null {
            $locale = SetLocale::localeInPath($request->path());

            if ($locale !== null) {
                app()->setLocale($locale);
            }

            return null;
        });
    })->create();

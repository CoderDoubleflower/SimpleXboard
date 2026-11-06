<?php

use App\Http\Controllers\V1\Client\ClientController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Subscription-only members do not have a public account portal.
Route::get('/', fn () => response('SimpleXboard', 200, ['Content-Type' => 'text/plain; charset=UTF-8']));

Route::get('/{admin_path}', function (Request $request, string $admin_path) {
    $expected = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
    abort_unless(hash_equals((string) $expected, $admin_path), 404);
    if (admin_setting('safe_mode_enable', false) && admin_setting('app_url')) {
        abort_unless($request->getHost() === parse_url(admin_setting('app_url'), PHP_URL_HOST), 403);
    }
    return response()->view('admin', [
        'title' => admin_setting('app_name', 'SimpleXboard'),
        'version' => config('app.version'),
        'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))),
    ])->header('Cache-Control', 'no-store')
      ->header('X-Content-Type-Options', 'nosniff')
      ->header('X-Frame-Options', 'DENY')
      ->header('Referrer-Policy', 'no-referrer');
});

Route::get('/{subscription_path}/{token}', [ClientController::class, 'subscribe'])
    ->middleware('client')->name('client.subscribe');

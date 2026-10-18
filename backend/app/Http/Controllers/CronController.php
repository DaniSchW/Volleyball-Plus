<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

class CronController extends Controller
{
    public function __invoke(string $token): JsonResponse
    {
        $expected = (string) config('app.cron_token');

        abort_if($expected === '' || ! hash_equals($expected, $token), 404);

        Artisan::call('schedule:run');

        return response()->json(['status' => 'ok']);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class SpaController extends Controller
{
    public function __invoke(): Response
    {
        $entry = public_path('app.html');

        abort_unless(is_file($entry), 503, __('app.errors.frontend_missing'));

        return response(file_get_contents($entry), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache',
        ]);
    }
}

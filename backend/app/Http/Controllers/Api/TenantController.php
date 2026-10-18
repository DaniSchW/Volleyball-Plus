<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class TenantController extends Controller
{
    /**
     * Public facts about a department, needed before login (name, language).
     */
    public function show(TenantContext $context): JsonResponse
    {
        $tenant = $context->get();

        return response()->json(['data' => [
            'slug' => $tenant->slug,
            'logo_url' => $tenant->logo_file_id ? '/api/v1/t/'.$tenant->slug.'/logo' : null,
            'name' => $tenant->name,
            'default_locale' => $tenant->default_locale,
        ]]);
    }
}

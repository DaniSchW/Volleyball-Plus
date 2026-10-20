<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Operator data for imprint, privacy policy and terms – public, entered by
 * a superadmin under "Platform → Legal". The texts live in the frontend
 * and fill in these details.
 */
class LegalController extends Controller
{
    public const KEY = 'platform.legal';

    public const FIELDS = ['name', 'company', 'street', 'postal_code', 'city', 'country', 'email', 'phone', 'vat_id', 'updated_on'];

    public function show(): JsonResponse
    {
        return response()->json(['data' => self::data()]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->isSuperadmin(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:160'],
            'street' => ['required', 'string', 'max:160'],
            'postal_code' => ['required', 'string', 'max:10'],
            'city' => ['required', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'vat_id' => ['nullable', 'string', 'max:40'],
        ]);
        Setting::write(self::KEY, array_map(fn ($v) => is_string($v) ? (trim($v) ?: null) : $v, $data) + ['updated_on' => now()->toDateString()]);

        return $this->show();
    }

    /** @return array<string, string|null> */
    public static function data(): array
    {
        $stored = (array) Setting::read(self::KEY, []);
        $data = [];
        foreach (self::FIELDS as $field) {
            $data[$field] = $stored[$field] ?? null;
        }
        $data['country'] ??= 'Deutschland';
        // Until entered: at least the superadmin's address as contact.
        $data['email'] ??= config('platform.superadmins')[0] ?? null;
        $data['complete'] = $data['name'] !== null && $data['street'] !== null && $data['city'] !== null;

        return $data;
    }
}

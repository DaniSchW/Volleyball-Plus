<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationCategory;
use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Support\Auth\CurrentMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Which notifications the member gets in this department, per category
 * and channel (push, e-mail).
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(private CurrentMember $current) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->present()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array', 'max:20'],
            'preferences.*.category' => ['required', 'distinct', Rule::enum(NotificationCategory::class)],
            'preferences.*.push' => ['required', 'boolean'],
            'preferences.*.email' => ['required', 'boolean'],
        ]);
        $member = $this->current->get();

        foreach ($data['preferences'] as $row) {
            $category = NotificationCategory::from($row['category']);
            $isDefault = (bool) $row['push'] === $category->defaultPush() && (bool) $row['email'] === $category->defaultEmail();
            $query = NotificationPreference::query()->where('tenant_member_id', $member->id)->where('category', $category);

            $isDefault
                ? $query->delete()
                : NotificationPreference::query()->updateOrCreate(
                    ['tenant_member_id' => $member->id, 'category' => $category],
                    ['push' => (bool) $row['push'], 'email' => (bool) $row['email']],
                );
        }

        return response()->json(['data' => $this->present()]);
    }

    /**
     * @return list<array{category: string, push: bool, email: bool}>
     */
    private function present(): array
    {
        $stored = NotificationPreference::query()->where('tenant_member_id', $this->current->get()->id)->get()->keyBy(fn ($p) => $p->category->value);

        return array_map(fn (NotificationCategory $c) => [
            'category' => $c->value,
            'push' => $stored->get($c->value)?->push ?? $c->defaultPush(),
            'email' => $stored->get($c->value)?->email ?? $c->defaultEmail(),
        ], NotificationCategory::cases());
    }
}

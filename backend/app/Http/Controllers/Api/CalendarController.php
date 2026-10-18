<?php

namespace App\Http\Controllers\Api;

use App\Enums\MemberStatus;
use App\Http\Controllers\Controller;
use App\Models\CalendarFeed;
use App\Models\Event;
use App\Models\EventInvitee;
use App\Models\TenantMember;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Events\IcsCalendar;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Free calendar sync: a secret ICS address per member that calendar apps
 * subscribe to (own events and those of the children).
 */
class CalendarController extends Controller
{
    public function show(CurrentMember $current, TenantContext $tenants): JsonResponse
    {
        $feed = CalendarFeed::query()->where('tenant_member_id', $current->get()->id)->first()
            ?? $this->createFeed($current->get());

        return response()->json(['data' => ['url' => $this->url($tenants->get()->slug, $feed->token_encrypted)]]);
    }

    /** New address; the old one stops working (e.g. if it was shared by mistake). */
    public function reset(CurrentMember $current, TenantContext $tenants): JsonResponse
    {
        CalendarFeed::query()->where('tenant_member_id', $current->get()->id)->delete();
        $feed = $this->createFeed($current->get());

        return response()->json(['data' => ['url' => $this->url($tenants->get()->slug, $feed->token_encrypted)]]);
    }

    /** The subscription itself (public, protected by the secret token). */
    public function feed(Request $request, string $token, TenantContext $tenants, Permissions $permissions, IcsCalendar $ics): Response
    {
        $token = preg_replace('/\.ics$/', '', $token);
        $feed = CalendarFeed::query()->where('token_hash', hash('sha256', (string) $token))->first();
        $member = $feed ? TenantMember::query()->find($feed->tenant_member_id) : null;
        abort_if($member === null || $member->status !== MemberStatus::Active, 404);

        $events = Event::query()
            ->whereIn('id', EventInvitee::query()->whereIn('person_id', $permissions->actingPersonIds($member))->select('event_id'))
            ->whereBetween('starts_at', [Carbon::now()->subDays(60), Carbon::now()->addDays(366)])
            ->orderBy('starts_at')
            ->get();

        $tenant = $tenants->get();
        app()->setLocale($member->account?->locale ?? $tenant->default_locale);

        return response($ics->render($tenant->name, $events, (string) config('app.url'), $tenant->slug), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="volleyball-plus.ics"',
            'Cache-Control' => 'private, max-age=900',
        ]);
    }

    private function createFeed(TenantMember $member): CalendarFeed
    {
        $token = Str::random(40);

        return CalendarFeed::create([
            'tenant_member_id' => $member->id,
            'token_hash' => hash('sha256', $token),
            'token_encrypted' => $token,
        ]);
    }

    private function url(string $slug, string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/t/'.$slug.'/calendar/'.$token.'.ics';
    }
}

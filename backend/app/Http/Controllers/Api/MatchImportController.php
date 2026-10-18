<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Import\MatchImport;
use App\Support\Import\ParsedMatch;
use App\Support\Import\ScheduleParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Import of a team's match schedule from a file (.ics/.ical or SAMS CSV):
 * first a preview, then the chosen rows. The file is sent with both calls
 * and not stored.
 */
class MatchImportController extends Controller
{
    public function __construct(
        private CurrentMember $current,
        private Permissions $permissions,
        private ScheduleParser $parser,
        private MatchImport $import,
    ) {}

    public function preview(Request $request, Team $team): JsonResponse
    {
        abort_unless($this->permissions->canManageEventsOf($this->current->get(), $team), 403);
        $data = $request->validate($this->rules() + ['own_name' => ['nullable', 'string', 'max:120']]);

        return response()->json(['data' => $this->import->preview($team, $this->matches($request), $data['own_name'] ?? null)]);
    }

    public function store(Request $request, Team $team): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->permissions->canManageEventsOf($member, $team), 403);
        $data = $request->validate($this->rules() + [
            'own_name' => ['required', 'string', 'max:120'],
            'keys' => ['required', 'array', 'max:'.ScheduleParser::MAX_MATCHES],
            'keys.*' => ['string', 'max:191'],
        ]);

        $matches = $this->matches($request);
        $names = collect($matches)->flatMap(fn (ParsedMatch $m) => [$m->home, $m->away, $m->referee])->filter()->unique();
        if (! $names->contains($data['own_name'])) {
            throw ValidationException::withMessages(['own_name' => __('import.own_name_required')]);
        }

        return response()->json(['data' => $this->import->apply($team, $matches, $data['own_name'], $data['keys'], $member)]);
    }

    /**
     * @return list<ParsedMatch>
     */
    private function matches(Request $request): array
    {
        return $this->parser->parse((string) file_get_contents($request->file('file')->getRealPath()), $this->current->get()->tenant->timezone);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return ['file' => ['required', 'file', 'max:1024', 'extensions:ics,ical,ifb,csv,txt']];
    }
}

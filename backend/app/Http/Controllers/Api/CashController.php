<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashBook;
use App\Models\CashEntry;
use App\Models\Person;
use App\Models\TeamMembership;
use App\Support\Auth\CurrentMember;
use App\Support\Auth\Permissions;
use App\Support\Cash\CashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Team cash books (trainers, organizers, admins) and the department cash
 * book with the overview of all books (admins).
 */
class CashController extends Controller
{
    public function __construct(private CurrentMember $current, private Permissions $permissions, private CashService $cash) {}

    public function index(): JsonResponse
    {
        $member = $this->current->get();
        $books = $this->cash->visibleBooks($member);
        $rows = $books->map(fn (CashBook $book) => $this->presentBook($book) + ['summary' => $this->cash->summary($book)])->values();
        $teamBooks = $rows->where('kind', CashBook::KIND_TEAM);

        return response()->json(['data' => [
            'books' => $rows->all(),
            // Sum over all team books (admins: the overview).
            'teams_total' => $this->permissions->isAdmin($member) ? $teamBooks->sum(fn ($row) => $row['summary']['balance']) : null,
            'total' => $this->permissions->isAdmin($member) ? $rows->sum(fn ($row) => $row['summary']['balance']) : null,
        ]]);
    }

    public function show(Request $request, CashBook $book): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->cash->canManage($member, $book), 403);
        [$from, $to, $category] = $this->filters($request);

        return response()->json(['data' => $this->presentBook($book) + [
            'summary' => $this->cash->summary($book, $from, $to),
            'entries' => $this->cash->entries($book, $from, $to, $category),
            'categories' => $this->cash->categories(),
            'persons' => $this->persons($book),
        ]]);
    }

    public function update(Request $request, CashBook $book): JsonResponse
    {
        abort_unless($this->cash->canManage($this->current->get(), $book), 403);
        $data = $request->validate(['opening_balance_cents' => ['required', 'integer', 'between:-1000000000,1000000000']]);

        $book->forceFill($data)->save();

        return response()->json(['data' => $this->presentBook($book) + ['summary' => $this->cash->summary($book)]]);
    }

    public function store(Request $request, CashBook $book): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->cash->canManage($member, $book), 403);
        $data = $request->validate([
            'booked_on' => ['required', 'date_format:Y-m-d'],
            'amount_cents' => ['required', 'integer', 'not_in:0', 'between:-1000000000,1000000000'],
            'category' => ['nullable', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:300'],
            'person_id' => ['nullable', 'string'],
        ]);
        if (! empty($data['person_id'])) {
            abort_unless(Person::query()->whereKey($data['person_id'])->exists(), 422, __('cash.person_invalid'));
        }

        $entry = CashEntry::create([
            'cash_book_id' => $book->id,
            'booked_on' => $data['booked_on'],
            'amount_cents' => $data['amount_cents'],
            'category' => isset($data['category']) && trim($data['category']) !== '' ? trim($data['category']) : null,
            'description' => trim($data['description']),
            'person_id' => $data['person_id'] ?? null,
            'created_by' => $member->id,
        ]);

        return response()->json(['data' => $this->cash->present($entry->load('person'), null, $this->cash->memberNames(collect([$member->id])))], 201);
    }

    /** Bookings are never changed or deleted; a cancelled one stays visible. */
    public function cancel(CashEntry $entry): JsonResponse
    {
        $member = $this->current->get();
        abort_unless($this->cash->canManage($member, $entry->book), 403);
        abort_if($entry->cancelled_at !== null, 422, __('cash.already_cancelled'));

        $entry->forceFill(['cancelled_at' => Carbon::now(), 'cancelled_by' => $member->id])->save();

        return response()->json(['message' => 'ok']);
    }

    /** CSV for spreadsheets (semicolon, UTF-8 with BOM – opens directly in Excel and LibreOffice). */
    public function export(Request $request, CashBook $book): StreamedResponse
    {
        abort_unless($this->cash->canManage($this->current->get(), $book), 403);
        [$from, $to, $category] = $this->filters($request);
        $entries = array_reverse($this->cash->entries($book, $from, $to, $category));
        $summary = $this->cash->summary($book, $from, $to);
        $name = $this->presentBook($book)['name'];

        $filename = str($name)->slug()->append('-', $from ?? 'start', '-', $to ?? Carbon::now()->toDateString(), '.csv')->toString();

        return response()->streamDownload(function () use ($entries, $summary, $from) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $amount = fn (?int $cents) => $cents === null ? '' : number_format($cents / 100, 2, ',', '');
            fputcsv($out, array_map(fn ($key) => __('cash.csv.'.$key), ['date', 'description', 'category', 'person', 'income', 'expense', 'balance', 'status', 'created_by']), ';', '"', '');
            fputcsv($out, [$from ?? '', __('cash.csv.carried'), '', '', '', '', $amount($summary['carried']), '', ''], ';', '"', '');
            foreach ($entries as $e) {
                fputcsv($out, [
                    $e['booked_on'], $e['description'], $e['category'] ?? '', $e['person']['name'] ?? '',
                    $e['amount_cents'] > 0 ? $amount($e['amount_cents']) : '',
                    $e['amount_cents'] < 0 ? $amount(-$e['amount_cents']) : '',
                    $amount($e['balance_after']),
                    $e['cancelled_at'] ? __('cash.csv.cancelled') : '',
                    $e['created_by'] ?? '',
                ], ';', '"', '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'category' => ['nullable', 'string', 'max:60'],
        ]);

        return [$data['from'] ?? null, $data['to'] ?? null, $data['category'] ?? null];
    }

    /** @return array<string, mixed> */
    private function presentBook(CashBook $book): array
    {
        return [
            'id' => $book->id,
            'kind' => $book->kind,
            'team_id' => $book->team_id,
            'name' => $book->isDepartment() ? __('cash.department') : ($book->team->name ?? ''),
            'archived' => $book->team?->archived_at !== null,
            'opening_balance_cents' => $book->opening_balance_cents,
        ];
    }

    /**
     * People to pick for a booking: the team's roster, for the department book everybody.
     *
     * @return list<array{id: string, name: string}>
     */
    private function persons(CashBook $book): array
    {
        $query = Person::query()->orderBy('last_name')->orderBy('first_name');
        if (! $book->isDepartment()) {
            $query->whereIn('id', TeamMembership::query()->where('team_id', $book->team_id)->select('person_id'));
        }

        return $query->limit(1000)->get()
            ->map(fn (Person $p) => ['id' => $p->id, 'name' => trim($p->first_name.' '.$p->last_name)])
            ->values()->all();
    }
}

<?php

namespace App\Support\Cash;

use App\Enums\PersonRelation;
use App\Models\CashBook;
use App\Models\CashEntry;
use App\Models\PersonAccount;
use App\Models\Team;
use App\Models\TenantMember;
use App\Support\Auth\Permissions;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Team cash books and the department's cash book: balances, sums and who
 * may see them. No payments – the books only record what happened.
 */
class CashService
{
    public function __construct(private Permissions $permissions) {}

    public function teamBook(Team $team): CashBook
    {
        return $this->book(CashBook::KIND_TEAM, $team->id, $team->id);
    }

    public function departmentBook(): CashBook
    {
        return $this->book(CashBook::KIND_DEPARTMENT, null, CashBook::KIND_DEPARTMENT);
    }

    private function book(string $kind, ?string $teamId, string $key): CashBook
    {
        $existing = CashBook::query()->where('book_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return CashBook::create(['kind' => $kind, 'team_id' => $teamId, 'book_key' => $key]);
        } catch (QueryException) {
            // Created in parallel by another request.
            return CashBook::query()->where('book_key', $key)->firstOrFail();
        }
    }

    public function canManage(TenantMember $member, CashBook $book): bool
    {
        if ($this->permissions->isAdmin($member)) {
            return true;
        }

        return ! $book->isDepartment() && $book->team !== null && $this->permissions->canManageCashOf($member, $book->team);
    }

    /**
     * Books the member keeps: own teams (trainer/organizer); admins all
     * teams, archived ones only when they have bookings, and the department.
     *
     * @return Collection<int, CashBook>
     */
    public function visibleBooks(TenantMember $member): Collection
    {
        $admin = $this->permissions->isAdmin($member);
        $teams = Team::query()->orderBy('name')->get()
            ->filter(fn (Team $team) => $admin || ($team->archived_at === null && $this->permissions->canManageCashOf($member, $team)));
        $withEntries = CashBook::query()->whereHas('entries')->pluck('team_id')->filter()->flip();

        $books = $teams
            ->filter(fn (Team $team) => $team->archived_at === null || $withEntries->has($team->id))
            ->map(fn (Team $team) => $this->teamBook($team)->setRelation('team', $team))
            ->values();

        return $admin ? $books->prepend($this->departmentBook()) : $books;
    }

    /**
     * Balance overall and within a period (dates as Y-m-d, both optional).
     *
     * @return array{opening: int, carried: int, income: int, expenses: int, balance: int, closing: int}
     */
    public function summary(CashBook $book, ?string $from = null, ?string $to = null): array
    {
        $active = CashEntry::query()->where('cash_book_id', $book->id)->whereNull('cancelled_at');
        $total = (int) (clone $active)->sum('amount_cents');
        $before = $from ? (int) (clone $active)->where('booked_on', '<', $from)->sum('amount_cents') : 0;
        $within = (clone $active)
            ->when($from, fn ($q) => $q->where('booked_on', '>=', $from))
            ->when($to, fn ($q) => $q->where('booked_on', '<=', $to));
        $income = (int) (clone $within)->where('amount_cents', '>', 0)->sum('amount_cents');
        $expenses = (int) (clone $within)->where('amount_cents', '<', 0)->sum('amount_cents');

        return [
            'opening' => $book->opening_balance_cents,
            // Balance at the start of the period.
            'carried' => $book->opening_balance_cents + $before,
            'income' => $income,
            'expenses' => $expenses,
            // Balance at the end of the period.
            'closing' => $book->opening_balance_cents + $before + $income + $expenses,
            // Current balance over all bookings.
            'balance' => $book->opening_balance_cents + $total,
        ];
    }

    /**
     * Entries of the period, newest first, each with the running balance
     * after it (cancelled entries show none).
     *
     * @return list<array<string, mixed>>
     */
    public function entries(CashBook $book, ?string $from, ?string $to, ?string $category): array
    {
        $all = CashEntry::query()->with('person')->where('cash_book_id', $book->id)
            ->orderBy('booked_on')->orderBy('created_at')->orderBy('id')->get();

        $running = $book->opening_balance_cents;
        $rows = [];
        foreach ($all as $entry) {
            if ($entry->cancelled_at === null) {
                $running += $entry->amount_cents;
            }
            $date = $entry->booked_on->toDateString();
            if (($from && $date < $from) || ($to && $date > $to) || ($category !== null && $entry->category !== $category)) {
                continue;
            }
            $rows[] = ['entry' => $entry, 'balance_after' => $entry->cancelled_at === null ? $running : null];
        }

        $names = $this->memberNames($all->pluck('created_by')->merge($all->pluck('cancelled_by')));

        return array_map(fn (array $row) => $this->present($row['entry'], $row['balance_after'], $names), array_reverse($rows));
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    public function present(CashEntry $entry, ?int $balanceAfter, array $names): array
    {
        return [
            'id' => $entry->id,
            'booked_on' => $entry->booked_on->toDateString(),
            'amount_cents' => $entry->amount_cents,
            'category' => $entry->category,
            'description' => $entry->description,
            'person' => $entry->person ? ['id' => $entry->person->id, 'name' => trim($entry->person->first_name.' '.$entry->person->last_name)] : null,
            'created_by' => $names[$entry->created_by] ?? null,
            'created_at' => $entry->created_at?->toIso8601String(),
            'cancelled_at' => $entry->cancelled_at?->toIso8601String(),
            'cancelled_by' => $entry->cancelled_by ? ($names[$entry->cancelled_by] ?? null) : null,
            'balance_after' => $balanceAfter,
        ];
    }

    /**
     * Names of department members (from their own profile).
     *
     * @param  Collection<int, string|null>  $ids
     * @return array<string, string>
     */
    public function memberNames(Collection $ids): array
    {
        return PersonAccount::query()->with('person')
            ->whereIn('tenant_member_id', $ids->filter()->unique()->values())
            ->where('relation', PersonRelation::Own)
            ->get()
            ->filter(fn (PersonAccount $link) => $link->person !== null)
            ->mapWithKeys(fn (PersonAccount $link) => [$link->tenant_member_id => trim($link->person->first_name.' '.$link->person->last_name)])
            ->all();
    }

    /**
     * Categories for suggestions: the usual ones plus those already used.
     *
     * @return list<string>
     */
    public function categories(): array
    {
        $used = CashEntry::query()->whereNotNull('category')->distinct()->pluck('category');

        return collect(__('cash.default_categories'))->merge($used)->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }
}

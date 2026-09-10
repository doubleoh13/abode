<?php

namespace App\Models\Financial;

use App\Enums\Financial\RecurrenceFrequency;
use Carbon\CarbonImmutable;
use Database\Factories\Financial\RecurringTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['financial_payee_id', 'memo', 'metadata', 'frequency', 'interval', 'starts_on', 'next_due_on', 'ends_on', 'lead_days'])]
class RecurringTransaction extends Model
{
    /** @use HasFactory<RecurringTransactionFactory> */
    use HasFactory;

    protected $table = 'financial_recurring_transactions';

    /**
     * @return BelongsTo<Payee, $this>
     */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class, 'financial_payee_id');
    }

    /**
     * @return HasMany<RecurringPosting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(RecurringPosting::class, 'financial_recurring_transaction_id')->orderBy('position');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'financial_recurring_transaction_id');
    }

    /**
     * Due dates from the pointer forward through the horizon, honoring the
     * end date. Each step is measured from starts_on rather than the previous
     * occurrence so month-end clamping never accumulates.
     *
     * @return list<CarbonImmutable>
     */
    public function occurrencesDueBy(CarbonImmutable $horizon): array
    {
        $anchor = CarbonImmutable::instance($this->starts_on);
        $limit = $this->ends_on === null ? $horizon : min($horizon, CarbonImmutable::instance($this->ends_on));
        $occurrences = [];
        $periods = 0;

        while (true) {
            $candidate = $this->frequency->advance($anchor, $periods * $this->interval);
            $periods++;

            if ($candidate->lessThan($this->next_due_on)) {
                continue;
            }

            if ($candidate->greaterThan($limit)) {
                return $occurrences;
            }

            $occurrences[] = $candidate;
        }
    }

    /**
     * How many days ahead of the due date an occurrence posts: the
     * schedule's own setting, else the application default.
     */
    public function leadDays(): int
    {
        return $this->lead_days ?? (int) config('financial.recurring_lead_days');
    }

    public function occurrenceAfter(CarbonImmutable $date): CarbonImmutable
    {
        $anchor = CarbonImmutable::instance($this->starts_on);
        $periods = 1;

        while (true) {
            $candidate = $this->frequency->advance($anchor, $periods * $this->interval);

            if ($candidate->greaterThan($date)) {
                return $candidate;
            }

            $periods++;
        }
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'frequency' => RecurrenceFrequency::class,
            'interval' => 'integer',
            'starts_on' => 'immutable_date',
            'next_due_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'lead_days' => 'integer',
        ];
    }
}

<?php

namespace App\Models\Financial;

use App\Casts\BigDecimalCast;
use App\Enums\Financial\AccountType;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasNotes;
use Database\Factories\Financial\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['account_type', 'financial_institution_id', 'simplefin_account_id', 'simplefin_synced_at', 'simplefin_balance', 'simplefin_balance_date', 'parent_id', 'allow_postings', 'name', 'opened_at', 'closed_at'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasAttachments, HasFactory, HasNotes;

    protected $table = 'financial_accounts';

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'financial_institution_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    /**
     * @return HasMany<BankTransaction, $this>
     */
    public function bankTransactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'financial_account_id');
    }

    /**
     * Bank-reported rows not yet linked to a posting.
     *
     * @return HasMany<BankTransaction, $this>
     */
    public function unmatchedBankTransactions(): HasMany
    {
        return $this->bankTransactions()->whereNull('financial_posting_id');
    }

    /**
     * This account's id followed by every descendant's.
     *
     * @return array<int, int>
     */
    public function subtreeIds(): array
    {
        $childIdsByParent = Account::query()
            ->whereNotNull('parent_id')
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id')
            ->map(fn (Collection $children): array => $children->pluck('id')->all());

        $ids = [$this->id];
        $queue = [$this->id];

        while ($queue !== []) {
            $parentId = array_shift($queue);

            foreach ($childIdsByParent->get($parentId, []) as $childId) {
                $ids[] = $childId;
                $queue[] = $childId;
            }
        }

        return $ids;
    }

    /**
     * Full colon-delimited path derived from ancestry, prefixed
     * with the account type: "Expenses:food:dining-out".
     */
    protected function path(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $segments = [$this->name];
                $ancestor = $this->parent;

                while ($ancestor !== null) {
                    array_unshift($segments, $ancestor->name);
                    $ancestor = $ancestor->parent;
                }

                array_unshift($segments, $this->account_type->pathPrefix());

                return implode(':', $segments);
            },
        );
    }

    /**
     * The path as URL segments: "expenses/food/dining-out".
     */
    protected function slugPath(): Attribute
    {
        return Attribute::make(
            get: fn (): string => implode('/', array_map(Str::slug(...), explode(':', $this->path))),
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'allow_postings' => 'boolean',
            'opened_at' => 'date',
            'closed_at' => 'date',
            'simplefin_synced_at' => 'immutable_datetime',
            'simplefin_balance' => BigDecimalCast::class,
            'simplefin_balance_date' => 'immutable_date',
        ];
    }
}

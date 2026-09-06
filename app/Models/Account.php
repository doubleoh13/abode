<?php

namespace App\Models;

use App\Enums\AccountType;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_type', 'institution_id', 'parent_id', 'name', 'opened_at', 'closed_at'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
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
     * Full colon-delimited path derived from ancestry, prefixed
     * with the account type: "expenses:food:dining-out".
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'opened_at' => 'date',
            'closed_at' => 'date',
        ];
    }
}

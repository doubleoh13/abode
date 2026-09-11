<?php

use App\Enums\Financial\AccountType;
use App\Enums\Permission;
use App\Models\Financial\Account;
use App\Models\Financial\BankTransaction;
use App\Models\Financial\Commodity;
use App\Models\Financial\Lot;
use App\Models\Financial\Payee;
use App\Models\Financial\Posting;
use App\Models\Financial\Transaction;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;

function journalLeg(Account $account, Commodity $commodity, int|string $amount, array $overrides = []): array
{
    $posting = [
        'status' => in_array($account->account_type, [AccountType::Asset, AccountType::Liability], true) ? 'cleared' : null,
        'financial_account_id' => $account->id,
        'financial_commodity_id' => $commodity->id,
        'amount' => (string) $amount,
        ...$overrides,
    ];

    $posting['amount'] = (string) $posting['amount'];

    if (isset($posting['lot']['cost'])) {
        $posting['lot']['cost'] = (string) $posting['lot']['cost'];
    }

    return $posting;
}

function journalSeedAcquisition(Account $account, Commodity $commodity, int|string $quantity, int|string $cost, string $date = '2026-01-05'): Lot
{
    $lot = Lot::factory()->ofCommodity($commodity)->create(['cost' => $cost, 'acquired_at' => $date]);
    $transaction = Transaction::factory()->on($date)->create();
    Posting::factory()->forTransaction($transaction, 0)->inAccount($account)->withLot($lot)->create(['amount' => $quantity]);

    return $lot;
}

test('guests receive a 401', function () {
    $this->getJson('/api/v1/financial/transactions')->assertUnauthorized();
});

test('a viewer cannot write', function () {
    actingWithPermissions(Permission::ViewFinances);

    $this->postJson('/api/v1/financial/transactions', [])->assertForbidden();
});

describe('with finance permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances, Permission::ManageFinances);

        $this->usd = Commodity::query()->where('code', 'USD')->firstOrFail();
        $this->checking = Account::factory()->ofType(AccountType::Asset)->create();
        $this->groceries = Account::factory()->ofType(AccountType::Expense)->create();
        $this->brokerage = Account::factory()->ofType(AccountType::Asset)->create();
        $this->gains = Account::factory()->ofType(AccountType::Income)->create();
        $this->fbtc = Commodity::factory()->create(['display_precision' => 8]);
    });

    test('required transaction fields use form language', function () {
        $this->postJson('/api/v1/financial/transactions', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'Enter a transaction date.')
            ->assertJsonPath('errors.postings.0', 'Add at least two postings.');
    });

    test('posting fields use form language', function () {
        $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                [
                    'status' => 'cleared',
                    'financial_account_id' => null,
                    'financial_commodity_id' => $this->usd->id,
                    'amount' => '-100',
                ],
                journalLeg($this->groceries, $this->usd, 100),
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'postings.0.financial_account_id' => 'Choose an account.',
            ]);
    });

    test('a simple transaction is created with server-assigned positions and derived status', function () {
        $payee = Payee::factory()->create();

        $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'financial_payee_id' => $payee->id,
            'memo' => 'Weekly groceries',
            'postings' => [
                journalLeg($this->checking, $this->usd, -12_34),
                journalLeg($this->groceries, $this->usd, 12_34),
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.date', '2026-08-01')
            ->assertJsonPath('data.payee.id', $payee->id)
            ->assertJsonPath('data.status', 'cleared')
            ->assertJsonPath('data.postings.0.position', 0)
            ->assertJsonPath('data.postings.1.position', 1);
    });

    test('an eighteen-decimal on-chain quantity round trips exactly', function () {
        $ether = Commodity::factory()->create(['code' => 'ETH', 'display_precision' => 18]);
        $quantity = '123.456789012345678901';

        $response = $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->brokerage, $ether, $quantity, ['lot' => ['cost' => '425000']]),
                journalLeg($this->checking, $this->usd, '-425000'),
            ],
        ])->assertCreated()
            ->assertJsonPath('data.postings.0.amount', $quantity)
            ->assertJsonPath('data.postings.0.lot.cost', '425000');

        $posting = Posting::query()->findOrFail($response->json('data.postings.0.id'));

        expect($posting->amount)->toBeInstanceOf(BigDecimal::class)
            ->and((string) $posting->amount)->toBe($quantity);
    });

    test('fractional amounts and USD lot costs round trip independently of display precision', function () {
        $response = $this->postJson('/api/v1/financial/transactions', [
            'date' => '2024-01-01',
            'postings' => [
                journalLeg($this->brokerage, $this->fbtc, '426.674', ['lot' => ['cost' => '4040.60278']]),
                journalLeg($this->checking, $this->usd, '-4040.60278'),
            ],
        ])->assertCreated()
            ->assertJsonPath('data.postings.0.amount', '426.674')
            ->assertJsonPath('data.postings.0.lot.cost', '4040.60278')
            ->assertJsonPath('data.postings.1.amount', '-4040.60278');

        $this->getJson('/api/v1/financial/transactions/'.$response->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.postings.0.amount', '426.674')
            ->assertJsonPath('data.postings.0.lot.cost', '4040.60278');

        expect($this->usd->refresh()->display_precision)->toBe(2);
    });

    test('decimal storage boundaries round trip exactly', function (string $amount) {
        $this->postJson('/api/v1/financial/transactions', [
            'date' => '2024-01-01',
            'postings' => [
                journalLeg($this->checking, $this->usd, $amount),
                journalLeg($this->groceries, $this->usd, '-'.$amount),
            ],
        ])->assertCreated()->assertJsonPath('data.postings.0.amount', $amount);
    })->with([
        'smallest fraction' => '0.'.str_repeat('0', 24).'1',
        'maximum magnitude' => str_repeat('9', 53).'.'.str_repeat('9', 25),
    ]);

    test('the index is date-descending and paginated, with bank links on postings', function () {
        Transaction::factory()->on('2026-03-01')->create();
        $newest = Transaction::factory()->on('2026-07-01')->create();
        $posting = Posting::factory()->forTransaction($newest, 0)->create();
        $bankRow = BankTransaction::factory()->linkedTo($posting)->create();

        $this->getJson('/api/v1/financial/transactions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('data.0.postings.0.bank_transaction.id', $bankRow->id)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.total', 2);
    });

    test('unbalanced postings are rejected with the residual', function () {
        $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->checking, $this->usd, -100),
                journalLeg($this->groceries, $this->usd, 99),
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('postings')
            ->assertJsonPath('errors.postings.0', 'Postings must balance at cost.');
    });

    test('a transaction needs at least two postings', function () {
        $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [journalLeg($this->checking, $this->usd, -100)],
        ])->assertUnprocessable()->assertJsonPath('errors.postings.0', 'Add at least two postings.');
    });

    test('zero amounts and unknown statuses are rejected', function () {
        $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->checking, $this->usd, 0, ['status' => 'settled']),
                journalLeg($this->groceries, $this->usd, 100),
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'postings.0.amount' => 'Enter a valid nonzero amount.',
                'postings.0.status' => 'Choose a valid status.',
            ]);
    });

    test('posting amounts require bounded decimal strings', function (mixed $amount) {
        $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                [
                    'status' => 'cleared',
                    'financial_account_id' => $this->checking->id,
                    'financial_commodity_id' => $this->usd->id,
                    'amount' => $amount,
                ],
                journalLeg($this->groceries, $this->usd, '100'),
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'postings.0.amount' => 'Enter a valid nonzero amount.',
            ]);
    })->with([
        'JSON number' => 100,
        'leading zero' => '01',
        'plus sign' => '+1',
        'too many fractional digits' => '0.'.str_repeat('1', 26),
        'zero decimal' => '0.00',
        'exponent notation' => '1e3',
        'more than 53 integer digits' => str_repeat('1', 54),
    ]);

    test('lot costs require bounded nonnegative decimal strings', function (mixed $cost) {
        $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                [...journalLeg($this->brokerage, $this->fbtc, '100'), 'lot' => ['cost' => $cost]],
                journalLeg($this->checking, $this->usd, '-100'),
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'postings.0.lot.cost' => 'Enter a valid nonnegative lot cost.',
            ]);
    })->with([
        'negative' => '-1',
        'JSON number' => 1,
        'too many integer digits' => str_repeat('9', 54),
        'too many fractional digits' => '0.'.str_repeat('1', 26),
    ]);

    test('status is required only for asset and liability postings', function () {
        $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->checking, $this->usd, -100, ['status' => null]),
                journalLeg($this->groceries, $this->usd, 100, ['status' => 'cleared']),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'postings.0.status',
        ]);
    });

    test('non-reconcilable account statuses are ignored on create and update', function (AccountType $accountType, mixed $status) {
        $account = Account::factory()->ofType($accountType)->create();
        $payload = [
            'date' => '2024-01-01',
            'postings' => [
                journalLeg($this->checking, $this->usd, '-100'),
                journalLeg($account, $this->usd, '100', ['status' => $status]),
            ],
        ];

        $response = $this->postJson('/api/v1/financial/transactions', $payload)
            ->assertCreated()->assertJsonPath('data.postings.1.status', null);
        $transactionId = $response->json('data.id');
        $this->assertDatabaseHas('financial_postings', [
            'financial_transaction_id' => $transactionId,
            'financial_account_id' => $account->id,
            'status' => null,
        ]);

        $this->putJson('/api/v1/financial/transactions/'.$transactionId, $payload)
            ->assertOk()->assertJsonPath('data.postings.1.status', null);
        $this->assertDatabaseHas('financial_postings', [
            'financial_transaction_id' => $transactionId,
            'financial_account_id' => $account->id,
            'status' => null,
        ]);
    })->with([AccountType::Income, AccountType::Expense, AccountType::Equity])
        ->with(['valid' => ['cleared'], 'invalid' => ['invalid'], 'object' => [['unexpected' => 'object']], 'number' => [123], 'null' => [null]]);

    test('an account with children rejects postings unless flagged', function () {
        Account::factory()->ofType(AccountType::Asset)->childOf($this->checking)->create();

        $payload = [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->checking, $this->usd, -10_00),
                journalLeg($this->groceries, $this->usd, 10_00),
            ],
        ];

        $this->postJson('/api/v1/financial/transactions', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['postings.0.financial_account_id']);

        $this->checking->update(['allow_postings' => true]);

        $this->postJson('/api/v1/financial/transactions', $payload)->assertCreated();
    });

    test('a base-currency posting cannot reference a lot', function () {
        $lot = Lot::factory()->ofCommodity($this->fbtc)->create();

        $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->checking, $this->usd, -100, ['financial_lot_id' => $lot->id]),
                journalLeg($this->groceries, $this->usd, 100),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('postings.0.financial_lot_id');
    });

    test('a non-currency posting must reference or create a lot, but not both', function () {
        $lot = journalSeedAcquisition($this->brokerage, $this->fbtc, 100, 100);

        $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->brokerage, $this->fbtc, 100),
                journalLeg($this->checking, $this->usd, -100),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('postings.0.financial_lot_id');

        $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->brokerage, $this->fbtc, 100, [
                    'financial_lot_id' => $lot->id,
                    'lot' => ['cost' => 100],
                ]),
                journalLeg($this->checking, $this->usd, -100),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('postings.0.financial_lot_id');
    });

    test('a referenced lot must hold the posting commodity', function () {
        $otherCommodity = Commodity::factory()->create(['display_precision' => 8]);
        $lot = journalSeedAcquisition($this->brokerage, $otherCommodity, 100, 100);

        $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->brokerage, $this->fbtc, -50, ['financial_lot_id' => $lot->id]),
                journalLeg($this->checking, $this->usd, 50),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('postings.0.financial_lot_id');
    });

    test('a new lot requires a positive amount', function () {
        $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->brokerage, $this->fbtc, -100, ['lot' => ['cost' => 100]]),
                journalLeg($this->checking, $this->usd, 100),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('postings.0.amount');
    });

    test('a referenced lot without acquired quantity is rejected', function () {
        $lot = Lot::factory()->ofCommodity($this->fbtc)->create();

        $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->brokerage, $this->fbtc, -50, ['financial_lot_id' => $lot->id]),
                journalLeg($this->checking, $this->usd, 50),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('postings.0.financial_lot_id');
    });

    test('a buy creates a lot defaulting acquired_at to the transaction date', function () {
        $response = $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->brokerage, $this->fbtc, 50_000_000, ['lot' => ['cost' => 425_000]]),
                journalLeg($this->checking, $this->usd, -425_000),
            ],
        ])->assertCreated();

        $lot = Lot::query()->findOrFail($response->json('data.postings.0.financial_lot_id'));

        expect($lot->acquired_at->toDateString())->toBe('2026-08-01')
            ->and((string) $lot->cost)->toBe('425000')
            ->and($lot->financial_commodity_id)->toBe($this->fbtc->id);
    });

    test('a sale balances through allocated basis and an explicit gains leg', function () {
        $lot = journalSeedAcquisition($this->brokerage, $this->fbtc, 50_000_000, 425_000);

        $payload = [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->brokerage, $this->fbtc, -20_000_000, ['financial_lot_id' => $lot->id]),
                journalLeg($this->checking, $this->usd, 190_000),
                journalLeg($this->gains, $this->usd, -20_000),
            ],
        ];

        $this->postJson('/api/v1/financial/transactions', $payload)->assertCreated();

        $payload['postings'][1]['amount'] = '190001';

        $this->postJson('/api/v1/financial/transactions', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('postings');
    });

    test('a multi-leg same-lot sale balances under largest-remainder allocation', function () {
        $lot = journalSeedAcquisition($this->brokerage, $this->fbtc, 7, 100);

        $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->brokerage, $this->fbtc, -3, ['financial_lot_id' => $lot->id]),
                journalLeg($this->brokerage, $this->fbtc, -2, ['financial_lot_id' => $lot->id]),
                journalLeg($this->brokerage, $this->fbtc, -2, ['financial_lot_id' => $lot->id]),
                journalLeg($this->checking, $this->usd, 100),
            ],
        ])->assertCreated();
    });

    test('an update syncs postings by id', function () {
        $created = $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->checking, $this->usd, -100),
                journalLeg($this->groceries, $this->usd, 60),
                journalLeg($this->gains, $this->usd, 40),
            ],
        ])->assertCreated();

        $transactionId = $created->json('data.id');
        [$first, $second, $third] = $created->json('data.postings');
        $droppedPosting = Posting::query()->findOrFail($third['id']);
        $dining = Account::factory()->ofType(AccountType::Expense)->create();

        $this->putJson("/api/v1/financial/transactions/{$transactionId}", [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->checking, $this->usd, -150, ['id' => $first['id']]),
                journalLeg($this->groceries, $this->usd, 60, ['id' => $second['id']]),
                journalLeg($dining, $this->usd, 90),
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.postings.0.id', $first['id'])
            ->assertJsonPath('data.postings.0.amount', '-150')
            ->assertJsonPath('data.postings.2.position', 2)
            ->assertJsonPath('data.postings.2.financial_account_id', $dining->id);

        $this->assertModelMissing($droppedPosting);
    });

    test('a zero posting amount is rejected by the database', function () {
        expect(fn () => Posting::factory()->create(['amount' => 0]))
            ->toThrow(QueryException::class, 'financial_postings_amount_nonzero');
    });

    test('an update can reorder kept postings', function () {
        $created = $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->checking, $this->usd, -100),
                journalLeg($this->groceries, $this->usd, 100),
            ],
        ])->assertCreated();

        $transactionId = $created->json('data.id');
        [$first, $second] = $created->json('data.postings');

        $this->putJson("/api/v1/financial/transactions/{$transactionId}", [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->groceries, $this->usd, 100, ['id' => $second['id']]),
                journalLeg($this->checking, $this->usd, -100, ['id' => $first['id']]),
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.postings.0.id', $second['id'])
            ->assertJsonPath('data.postings.0.position', 0)
            ->assertJsonPath('data.postings.1.id', $first['id'])
            ->assertJsonPath('data.postings.1.position', 1);
    });

    test('an update rejects duplicate posting ids', function () {
        $created = $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->checking, $this->usd, -100),
                journalLeg($this->groceries, $this->usd, 100),
            ],
        ])->assertCreated();

        $transactionId = $created->json('data.id');
        $postingId = $created->json('data.postings.0.id');

        $this->putJson("/api/v1/financial/transactions/{$transactionId}", [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->checking, $this->usd, -100, ['id' => $postingId]),
                journalLeg($this->groceries, $this->usd, 100, ['id' => $postingId]),
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'postings.1.id' => 'Each posting may appear only once.',
            ]);
    });

    test('a posting id from another transaction is rejected', function () {
        $foreign = Posting::factory()->create();

        $created = $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->checking, $this->usd, -100),
                journalLeg($this->groceries, $this->usd, 100),
            ],
        ])->assertCreated();

        $this->putJson("/api/v1/financial/transactions/{$created->json('data.id')}", [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->checking, $this->usd, -100, ['id' => $foreign->id]),
                journalLeg($this->groceries, $this->usd, 100),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('postings.0.id');
    });

    test('editing an acquisition re-derives its lot in place', function () {
        $created = $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->brokerage, $this->fbtc, 50_000_000, ['lot' => ['cost' => 425_000]]),
                journalLeg($this->checking, $this->usd, -425_000),
            ],
        ])->assertCreated();

        $lotId = $created->json('data.postings.0.financial_lot_id');
        [$acquisition, $cash] = $created->json('data.postings');

        $this->putJson("/api/v1/financial/transactions/{$created->json('data.id')}", [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->brokerage, $this->fbtc, 50_000_000, [
                    'id' => $acquisition['id'],
                    'lot' => ['cost' => 430_000, 'acquired_at' => '2026-07-15'],
                ]),
                journalLeg($this->checking, $this->usd, -430_000, ['id' => $cash['id']]),
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.postings.0.financial_lot_id', $lotId);

        $lot = Lot::query()->findOrFail($lotId);

        expect((string) $lot->cost)->toBe('430000')
            ->and($lot->acquired_at->toDateString())->toBe('2026-07-15');
    });

    test('deleting a transaction sweeps lots nothing else references', function () {
        $created = $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->brokerage, $this->fbtc, 50_000_000, ['lot' => ['cost' => 425_000]]),
                journalLeg($this->checking, $this->usd, -425_000),
            ],
        ])->assertCreated();

        $lot = Lot::query()->findOrFail($created->json('data.postings.0.financial_lot_id'));

        $this->deleteJson("/api/v1/financial/transactions/{$created->json('data.id')}")->assertNoContent();

        $this->assertModelMissing($lot);
    });

    test('deleting an acquisition whose lot other transactions consume conflicts', function () {
        $lot = journalSeedAcquisition($this->brokerage, $this->fbtc, 50_000_000, 425_000);
        $acquisitionTransactionId = $lot->postings()->firstOrFail()->financial_transaction_id;

        $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'postings' => [
                journalLeg($this->brokerage, $this->fbtc, -20_000_000, ['financial_lot_id' => $lot->id]),
                journalLeg($this->checking, $this->usd, 190_000),
                journalLeg($this->gains, $this->usd, -20_000),
            ],
        ])->assertCreated();

        $this->deleteJson("/api/v1/financial/transactions/{$acquisitionTransactionId}")->assertConflict();
    });

    test('transaction status is the least advanced asset or liability posting', function () {
        $transaction = Transaction::factory()->create();
        Posting::factory()->forTransaction($transaction, 0)->inAccount($this->checking)->pending()->create();
        Posting::factory()->forTransaction($transaction, 1)->inAccount($this->groceries)->create();

        $this->getJson("/api/v1/financial/transactions/{$transaction->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
    });

    test('non asset or liability postings do not contribute to transaction status', function () {
        $transaction = Transaction::factory()->create();
        Posting::factory()->forTransaction($transaction, 0)->inAccount($this->checking)->reconciled()->create();
        Posting::factory()->forTransaction($transaction, 1)->inAccount($this->groceries)->create();

        $this->getJson("/api/v1/financial/transactions/{$transaction->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'reconciled');
    });

    test('a transaction without asset or liability postings has no status', function () {
        $transaction = Transaction::factory()->create();
        Posting::factory()->forTransaction($transaction, 0)->inAccount($this->gains)->create(['status' => null]);
        Posting::factory()->forTransaction($transaction, 1)->inAccount($this->groceries)->create(['status' => null]);

        $this->getJson("/api/v1/financial/transactions/{$transaction->id}")
            ->assertOk()
            ->assertJsonPath('data.status', null);
    });

    describe('index filtering', function () {
        function journalEntry(string $date, Account $debit, Account $credit, array $attributes = [], string $debitStatus = 'cleared'): Transaction
        {
            $transaction = Transaction::factory()->on($date)->create($attributes);
            $usd = Commodity::query()->where('code', 'USD')->firstOrFail();

            Posting::factory()->forTransaction($transaction, 0)->inAccount($debit)->ofCommodity($usd)
                ->create(['amount' => 100, 'status' => $debitStatus]);
            Posting::factory()->forTransaction($transaction, 1)->inAccount($credit)->ofCommodity($usd)
                ->create(['amount' => -100, 'status' => null]);

            return $transaction;
        }

        function indexIds(object $context, string $query): array
        {
            return collect($context->getJson("/api/v1/financial/transactions?{$query}")->assertOk()->json('data'))
                ->pluck('id')
                ->all();
        }

        test('an account filter matches the account and its descendants', function () {
            $child = Account::factory()->ofType(AccountType::Asset)->create(['parent_id' => $this->checking->id]);
            $inChild = journalEntry('2026-08-01', $child, $this->groceries);
            $inParent = journalEntry('2026-08-02', $this->checking, $this->groceries);
            journalEntry('2026-08-03', $this->brokerage, $this->groceries);

            expect(indexIds($this, "financial_account_id={$this->checking->id}"))
                ->toBe([$inParent->id, $inChild->id]);
        });

        test('a search matches payee names and memos case-insensitively', function () {
            $payee = Payee::factory()->create(['name' => 'Meijer']);
            $byPayee = journalEntry('2026-08-01', $this->checking, $this->groceries, ['financial_payee_id' => $payee->id]);
            $byMemo = journalEntry('2026-08-02', $this->checking, $this->groceries, ['memo' => 'Meijer run']);
            journalEntry('2026-08-03', $this->checking, $this->groceries, ['memo' => 'Costco']);

            expect(indexIds($this, 'search=meijer'))->toBe([$byMemo->id, $byPayee->id]);
        });

        test('a date range brackets the journal', function () {
            journalEntry('2026-08-01', $this->checking, $this->groceries);
            $within = journalEntry('2026-08-15', $this->checking, $this->groceries);
            journalEntry('2026-09-01', $this->checking, $this->groceries);

            expect(indexIds($this, 'from=2026-08-10&to=2026-08-20'))->toBe([$within->id]);
        });

        test('a status filter applies the least-advanced derivation', function () {
            $pending = journalEntry('2026-08-01', $this->checking, $this->groceries, [], 'pending');
            Posting::factory()->forTransaction($pending, 2)->inAccount($this->brokerage)->ofCommodity($this->usd)
                ->create(['amount' => '0.5', 'status' => 'cleared']);
            $cleared = journalEntry('2026-08-02', $this->checking, $this->groceries);
            $reconciled = journalEntry('2026-08-03', $this->checking, $this->groceries, [], 'reconciled');

            expect(indexIds($this, 'status[]=pending'))->toBe([$pending->id])
                ->and(indexIds($this, 'status[]=cleared'))->toBe([$cleared->id])
                ->and(indexIds($this, 'status[]=reconciled'))->toBe([$reconciled->id])
                ->and(indexIds($this, 'status[]=cleared&status[]=reconciled'))
                ->toBe([$reconciled->id, $cleared->id]);
        });

        test('an exclude status filter hides the derived status and keeps statusless transactions', function () {
            $pending = journalEntry('2026-08-01', $this->checking, $this->groceries, [], 'pending');
            $cleared = journalEntry('2026-08-02', $this->checking, $this->groceries);
            $reconciled = journalEntry('2026-08-03', $this->checking, $this->groceries, [], 'reconciled');
            $statusless = Transaction::factory()->on('2026-08-04')->create();
            Posting::factory()->forTransaction($statusless, 0)->inAccount($this->gains)->ofCommodity($this->usd)
                ->create(['amount' => 100, 'status' => null]);
            Posting::factory()->forTransaction($statusless, 1)->inAccount($this->groceries)->ofCommodity($this->usd)
                ->create(['amount' => -100, 'status' => null]);

            expect(indexIds($this, 'exclude_status[]=reconciled'))
                ->toBe([$statusless->id, $cleared->id, $pending->id])
                ->and(indexIds($this, 'exclude_status[]=pending&exclude_status[]=cleared'))
                ->toBe([$statusless->id, $reconciled->id])
                ->and(indexIds($this, 'status[]=pending&status[]=cleared&exclude_status[]=cleared'))
                ->toBe([$pending->id]);

            $this->getJson('/api/v1/financial/transactions?exclude_status[]=settled')
                ->assertUnprocessable()
                ->assertJsonValidationErrors('exclude_status.0');
        });

        test('an unknown filter account is rejected', function () {
            $this->getJson('/api/v1/financial/transactions?financial_account_id=999999')
                ->assertUnprocessable()
                ->assertJsonValidationErrors('financial_account_id');
        });
    });

    test('metadata round-trips on transactions and postings', function () {
        $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-08-01',
            'metadata' => ['import_id' => 'bank-123'],
            'postings' => [
                journalLeg($this->checking, $this->usd, -100, ['metadata' => ['statement_line' => 7]]),
                journalLeg($this->groceries, $this->usd, 100),
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.metadata.import_id', 'bank-123')
            ->assertJsonPath('data.postings.0.metadata.statement_line', 7);
    });
});

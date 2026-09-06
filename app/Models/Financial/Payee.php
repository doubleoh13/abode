<?php

namespace App\Models\Financial;

use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasNotes;
use Database\Factories\Financial\PayeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name'])]
class Payee extends Model
{
    /** @use HasFactory<PayeeFactory> */
    use HasAttachments, HasFactory, HasNotes;

    protected $table = 'financial_payees';
}

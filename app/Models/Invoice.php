<?php

namespace App\Models;

use App\Services\Accounting\Decimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Invoice extends BaseModel
{
    protected $appends = ['payment_status', 'paid_amount', 'remaining_amount', 'posting_status', 'tax_unknown', 'payment_activity'];

    public function getPaymentActivityAttribute(): array
    {
        if (! $this->accounting_managed) {
            return [];
        }

        return DB::table('accounting_payment_allocations')
            ->join('accounting_bank_transactions', 'accounting_bank_transactions.id', '=', 'accounting_payment_allocations.bank_transaction_id')
            ->where('invoice_id', $this->id)->get(['accounting_payment_allocations.amount', 'accounting_bank_transactions.transaction_date',
                'accounting_bank_transactions.direction', 'accounting_bank_transactions.currency'])->map(fn ($a) => (array) $a)->all();
    }

    public function getPaidAmountAttribute(): string
    {
        if (! $this->accounting_managed) {
            return ($this->status === 'paid' || $this->paid_at) ? (string) $this->total : '0.00';
        }

        return Decimal::sum(DB::table('accounting_payment_allocations')->where('invoice_id', $this->id)->pluck('amount'));
    }

    public function getPaymentStatusAttribute(): string
    {
        $paid = $this->paid_amount;

        return bccomp($paid, '0', 2) === 0 ? 'unpaid' : (bccomp($paid, (string) $this->total, 2) >= 0 ? 'paid' : 'partial');
    }

    public function getPostingStatusAttribute(): string
    {
        if (! $this->accounting_managed) {
            return 'unposted';
        }
        $entry = DB::table('accounting_entries')->where('company_id', $this->company_id)->where('event_key', 'invoice:'.$this->id)->first();

        return ! $entry ? 'unposted' : (DB::table('accounting_entries')->where('reverses_entry_id', $entry->id)->exists() ? 'reversed' : 'posted');
    }

    public function getRemainingAmountAttribute(): string
    {
        if ($this->accounting_managed && $this->corrects_invoice_id) {
            return '0.00';
        }
        $total = $this->posting_status === 'reversed' ? '0' : (string) $this->total;

        return bcsub($total, $this->paid_amount, 2);
    }

    public function getTaxUnknownAttribute(): bool
    {
        return $this->accounting_managed && ($this->relationLoaded('items') ? ($this->items->isEmpty() || $this->items->contains(fn ($i) => $i->tax_treatment === 'unknown'))
            : (! $this->items()->exists() || $this->items()->where('tax_treatment', 'unknown')->exists()));
    }

    protected function casts(): array
    {
        return ['issued_at' => 'date', 'due_at' => 'date', 'paid_at' => 'datetime', 'issued_snapshot' => 'array', 'accounting_managed' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function freightLoad(): BelongsTo
    {
        return $this->belongsTo(Load::class, 'load_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}

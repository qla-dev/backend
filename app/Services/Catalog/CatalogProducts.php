<?php

namespace App\Services\Catalog;

use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company's product/service catalogue: one list for CRM offers, service templates and POS.
 * Agent note: SmartFreight is the master. PantheonCatalogSync pulls tHE_SetItem and pushes products
 * created here; products that came from PANTHEON are edited in PANTHEON (only "active" is local).
 */
class CatalogProducts
{
    public function __construct(private AccountingLedger $ledger) {}

    public static function available(): bool
    {
        static $available;

        return $available ??= Schema::hasTable('catalog_products');
    }

    public function search(int $companyId, ?string $search, ?string $kind, int $limit = 50, bool $activeOnly = true): array
    {
        if (! self::available()) {
            return [];
        }
        $query = DB::table('catalog_products')->where('company_id', $companyId)->when($activeOnly, fn ($q) => $q->where('active', true))
            ->when($kind, fn ($q, $k) => $q->where('kind', $k));
        if (filled($search)) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $search).'%';
            $query->where(fn ($q) => $q->where('code', 'like', $term)->orWhere('name', 'like', $term));
        }

        return $query->orderBy('name')->limit(min(max($limit, 1), 200))->get()->all();
    }

    public function save(int $companyId, int $actor, array $data): object
    {
        return DB::transaction(function () use ($companyId, $actor, $data) {
            $existing = ! empty($data['id']) ? DB::table('catalog_products')->where('company_id', $companyId)->where('id', $data['id'])->lockForUpdate()->first() : null;
            $this->ledger->require(empty($data['id']) || $existing !== null, 'Product does not belong to this company.');
            if ($existing && $existing->source === 'pantheon') {
                // PANTHEON owns its articles; here only availability in SmartFreight can change.
                DB::table('catalog_products')->where('id', $existing->id)->update(['active' => (bool) ($data['active'] ?? $existing->active), 'updated_at' => now()]);

                return DB::table('catalog_products')->find($existing->id);
            }
            $code = strtoupper(trim((string) $data['code']));
            $this->ledger->require(! DB::table('catalog_products')->where('company_id', $companyId)->where('code', $code)->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists(),
                'This product code already exists.');
            $values = ['code' => $code, 'name' => mb_substr((string) $data['name'], 0, 80), 'kind' => $data['kind'] ?? 'service', 'unit' => strtoupper(mb_substr((string) ($data['unit'] ?? 'KOM'), 0, 3)),
                'item_set' => $data['item_set'] ?? null, 'sale_price' => isset($data['sale_price']) && $data['sale_price'] !== '' ? Decimal::value((string) $data['sale_price'], 4) : null,
                'currency' => $data['currency'] ?? 'BAM', 'vat_percent' => isset($data['vat_percent']) && $data['vat_percent'] !== '' ? Decimal::value((string) $data['vat_percent'], 4) : null,
                'vat_code' => isset($data['vat_code']) && $data['vat_code'] !== '' ? strtoupper((string) $data['vat_code']) : null, 'active' => (bool) ($data['active'] ?? true), 'updated_at' => now()];
            if ($existing) {
                DB::table('catalog_products')->where('id', $existing->id)->update($values + ['revision' => $existing->revision + 1]);
                $id = $existing->id;
            } else {
                $id = DB::table('catalog_products')->insertGetId($values + ['company_id' => $companyId, 'source' => 'smartfreight', 'created_by' => $actor, 'created_at' => now()]);
            }
            $this->ledger->audit($companyId, $actor, 'catalog_product', $id, $existing ? 'product_updated' : 'product_created', ['code' => $code]);

            return DB::table('catalog_products')->find($id);
        });
    }

    /** Line defaults from a product (CRM offer line, template line, work order line, POS line). */
    public function line(int $companyId, ?int $productId): ?object
    {
        return $productId && self::available() ? DB::table('catalog_products')->where('company_id', $companyId)->where('id', $productId)->first() : null;
    }
}

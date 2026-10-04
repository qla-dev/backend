<?php

namespace App\Services\Catalog;

use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\PantheonConnector;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue <-> PANTHEON tHE_SetItem (see docs/agents/pantheon-integration.md).
 *
 * - Pull: articles of the configured item sets (connector.catalog_item_sets, default USL,OPR; Trendy's
 *   25 000 production parts in set 120 are only pulled when that set is chosen) with stock summed over
 *   all warehouses from tHE_Stock. PANTHEON is the master for its own articles.
 * - Push: products created in SmartFreight go to tHE_SetItem in catalog_push_item_set when
 *   catalog_push_enabled and the connector write switch are on. anQId is an identity column.
 *   An article PANTHEON already has under the same code is linked, never overwritten.
 * - Never: delete in PANTHEON, change an article that came from PANTHEON, write tHE_Stock.
 */
class PantheonCatalogSync
{
    public function __construct(private PantheonConnector $pantheon, private AccountingLedger $ledger) {}

    public function sync(int $companyId, bool $write = true): array
    {
        $connector = DB::table('accounting_pantheon_connectors')->where('company_id', $companyId)->first();
        $this->ledger->require($connector !== null, 'Pantheon connector is not configured.');
        $remote = $this->pantheon->connection($companyId);
        $items = $this->pantheon->remoteTable($companyId, 'tHE_SetItem');
        $sets = array_values(array_filter(array_map('trim', explode(',', (string) $connector->catalog_item_sets))));
        $summary = ['pulled' => 0, 'created' => 0, 'pushed' => [], 'linked' => [], 'waiting' => []];

        // Pull first, so a code PANTHEON already has is linked instead of pushed twice.
        if ($sets !== []) {
            $remote->table($items)->whereIn('acSetOfItem', $sets)->where('acActive', 'T')->orderBy('acIdent')
                ->select(['acIdent', 'acName', 'acUM', 'acSetOfItem', 'acVATCode', 'anVAT', 'anSalePrice', 'acCurrency'])
                ->chunk(1000, function ($rows) use ($companyId, $remote, &$summary) {
                    $codes = $rows->map(fn ($r) => trim($r->acIdent))->all();
                    $stock = $remote->table($this->pantheon->remoteTable($companyId, 'tHE_Stock'))->whereIn('acIdent', $codes)
                        ->selectRaw('acIdent, SUM(anStock) as stock')->groupBy('acIdent')->pluck('stock', 'acIdent')->mapWithKeys(fn ($v, $k) => [trim($k) => $v]);
                    $local = DB::table('catalog_products')->where('company_id', $companyId)->whereIn('code', $codes)->get()->keyBy('code');
                    foreach ($rows as $row) {
                        $code = trim($row->acIdent);
                        $values = ['name' => mb_substr(trim((string) $row->acName) ?: $code, 0, 80), 'unit' => trim((string) $row->acUM) ?: 'KOM', 'item_set' => trim((string) $row->acSetOfItem) ?: null,
                            'vat_code' => trim((string) $row->acVATCode) ?: null, 'vat_percent' => $row->anVAT === null ? null : (string) $row->anVAT,
                            'sale_price' => (float) $row->anSalePrice > 0 ? (string) $row->anSalePrice : null, 'currency' => in_array(trim((string) $row->acCurrency), ['', 'KM'], true) ? 'BAM' : trim($row->acCurrency),
                            'kind' => in_array(trim((string) $row->acSetOfItem), ['USL', 'OPR'], true) ? 'service' : 'product', 'stock' => isset($stock[$code]) ? (string) $stock[$code] : null, 'synced_at' => now(), 'updated_at' => now()];
                        $existing = $local->get($code);
                        if (! $existing) {
                            DB::table('catalog_products')->insert($values + ['company_id' => $companyId, 'code' => $code, 'source' => 'pantheon', 'created_at' => now()]);
                            $summary['created']++;
                        } elseif ($existing->source === 'pantheon') {
                            DB::table('catalog_products')->where('id', $existing->id)->update($values);
                        } else {
                            // Created in SmartFreight and PANTHEON has it: linked, our content stays, stock is read.
                            DB::table('catalog_products')->where('id', $existing->id)->update(['stock' => $values['stock'], 'pantheon_revision' => $existing->revision, 'synced_at' => now()]);
                        }
                        $summary['pulled']++;
                    }
                });
        }

        $canWrite = $write && $connector->allow_write && $connector->catalog_push_enabled && $connector->catalog_push_item_set;
        $pending = DB::table('catalog_products')->where('company_id', $companyId)->where('source', 'smartfreight')
            ->where(fn ($q) => $q->whereNull('pantheon_revision')->orWhereColumn('pantheon_revision', '<', 'revision'))->get();
        foreach ($pending as $product) {
            if (! $product->vat_code || ! $remote->table($this->pantheon->remoteTable($companyId, 'tHE_SetTax'))->where('acVATCode', $product->vat_code)->exists()) {
                $summary['waiting'][] = ['code' => $product->code, 'problems' => ['vat_code_required']];

                continue;
            }
            if (! $canWrite) {
                $summary['waiting'][] = ['code' => $product->code, 'problems' => ['write_disabled']];

                continue;
            }
            $remoteRow = $remote->table($items)->where('acIdent', $product->code)->first(['acIdent', 'acNote']);
            $marker = 'SF:'.$companyId.':product:'.$product->id;
            $values = ['acName' => $product->name, 'acUM' => $product->unit, 'acVATCode' => $product->vat_code, 'anVAT' => (string) ($product->vat_percent ?? 0),
                'anSalePrice' => (string) ($product->sale_price ?? 0), 'acCurrency' => $product->currency === 'BAM' ? 'KM' : $product->currency,
                'acActive' => $product->active ? 'T' : 'F', 'anUserChg' => (int) $connector->clerk_id, 'adTimeChg' => now()];
            if (! $remoteRow) {
                $remote->table($items)->insert($values + ['acIdent' => $product->code, 'acSetOfItem' => $connector->catalog_push_item_set, 'acCode' => $product->code,
                    'acNote' => $marker, 'anUserIns' => (int) $connector->clerk_id, 'adTimeIns' => now()]);
                $summary['pushed'][] = $product->code;
            } elseif (str_starts_with((string) $remoteRow->acNote, $marker)) {
                $remote->table($items)->where('acIdent', $product->code)->update($values); // our own article: follow local edits
                $summary['pushed'][] = $product->code;
            } else {
                $summary['linked'][] = $product->code; // PANTHEON's article under the same code: never overwritten
            }
            DB::table('catalog_products')->where('id', $product->id)->update(['pantheon_revision' => $product->revision, 'synced_at' => now()]);
        }
        DB::table('accounting_pantheon_connectors')->where('company_id', $companyId)->update(['last_catalog_sync_at' => now()]);

        return $summary;
    }
}

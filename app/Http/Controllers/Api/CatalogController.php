<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingAccess;
use App\Services\Catalog\CatalogProducts;
use App\Services\Catalog\PantheonCatalogSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Product/service catalogue shared by CRM, service templates and POS. PANTHEON is reached only through the catalogue sync. */
final class CatalogController extends Controller
{
    public function __construct(private AccountingAccess $access, private CatalogProducts $catalog, private PantheonCatalogSync $sync) {}

    private function company(Request $r, array $anyOf): int
    {
        $id = (int) $r->route('company');
        abort_unless(array_intersect($anyOf, $this->access->abilities($r->user(), $id)) !== [], 403, 'Catalogue permission required.');

        return $id;
    }

    private function response(mixed $data): JsonResponse
    {
        return response()->json(['data' => $data, 'message' => 'Catalogue operation completed.', 'errors' => [], 'meta' => []]);
    }

    public function index(Request $r): JsonResponse
    {
        $id = $this->company($r, ['view', 'prepare', 'crm', 'pos', 'ops']);
        $f = $r->validate(['search' => ['nullable', 'string', 'max:80'], 'kind' => ['nullable', Rule::in(['service', 'product'])], 'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'all' => ['nullable', 'boolean'], 'with_templates' => ['nullable', 'boolean']]);
        $data = ['products' => $this->catalog->search($id, $f['search'] ?? null, $f['kind'] ?? null, (int) ($f['limit'] ?? 50), ! ($f['all'] ?? false))];
        if ($f['with_templates'] ?? false) {
            // POS can sell a whole service template (its revenue lines, or one line at its planned value).
            $data['templates'] = DB::table('ops_service_templates')->where('company_id', $id)->where('active', true)->orderBy('name')->get()->map(function ($t) {
                $t->items = DB::table('ops_service_template_items')->where('template_id', $t->id)->orderBy('position')->get();

                return $t;
            });
        }

        return $this->response($data);
    }

    public function save(Request $r): JsonResponse
    {
        $id = $this->company($r, ['prepare', 'setup', 'ops']);
        $data = $r->validate(['id' => ['nullable', 'integer'], 'code' => ['required_without:id', 'nullable', 'string', 'max:16'], 'name' => ['required_without:id', 'nullable', 'string', 'max:80'],
            'kind' => ['nullable', Rule::in(['service', 'product'])], 'unit' => ['nullable', 'string', 'max:3'], 'item_set' => ['nullable', 'string', 'max:3'],
            'sale_price' => ['nullable', 'numeric', 'min:0'], 'currency' => ['nullable', 'regex:/^[A-Z]{3}$/'], 'vat_percent' => ['nullable', 'numeric', 'between:0,100'],
            'vat_code' => ['nullable', 'string', 'max:2'], 'active' => ['nullable', 'boolean']]);

        return $this->response($this->catalog->save($id, $r->user()->id, $data));
    }

    public function pantheonSettings(Request $r): JsonResponse
    {
        $id = $this->company($r, ['integrations']);
        if ($r->isMethod('post')) {
            $data = $r->validate(['catalog_item_sets' => ['nullable', 'regex:/^[0-9A-Z]{1,3}(,[0-9A-Z]{1,3}){0,20}$/'], 'catalog_push_enabled' => ['required', 'boolean'],
                'catalog_push_item_set' => ['nullable', 'regex:/^[0-9A-Z]{1,3}$/']]);
            abort_unless(DB::table('accounting_pantheon_connectors')->where('company_id', $id)->exists(), 422, 'Connect PANTHEON first.');
            DB::table('accounting_pantheon_connectors')->where('company_id', $id)->update(['catalog_item_sets' => $data['catalog_item_sets'] ?? '',
                'catalog_push_enabled' => $data['catalog_push_enabled'], 'catalog_push_item_set' => $data['catalog_push_item_set'] ?? null, 'updated_by' => $r->user()->id, 'updated_at' => now()]);
        }

        return $this->response(DB::table('accounting_pantheon_connectors')->where('company_id', $id)->first(['catalog_item_sets', 'catalog_push_enabled', 'catalog_push_item_set', 'last_catalog_sync_at']));
    }

    /** write=false pulls PANTHEON articles and only reports what would be pushed. */
    public function syncPantheon(Request $r): JsonResponse
    {
        $id = $this->company($r, ['integrations']);
        $data = $r->validate(['write' => ['required', 'boolean']]);

        return $this->response($this->sync->sync($id, (bool) $data['write']));
    }
}

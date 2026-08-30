<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Load;
use App\Models\LoadStop;
use App\Models\Shipment;
use App\Models\User;
use App\Services\CustomsDocumentGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;
use ZipArchive;

class CustomsDocumentGeneratorTest extends TestCase
{
    public function test_it_maps_load_and_form_data_and_removes_every_template_variable(): void
    {
        $owner = new User(['name' => 'Owner Person']);
        $company = new Company([
            'name' => 'Freightbook Logistics', 'address' => 'Main 1', 'city' => 'Sarajevo',
            'country_code' => 'BA', 'tax_number' => '420000001',
        ]);
        $company->setRelation('owner', $owner);

        $consignee = new Customer([
            'company_name' => 'Importer Company', 'billing_address' => 'Import 2',
            'city' => 'Mostar', 'tax_number' => '420000002',
        ]);
        $consignee->setRelation('user', new User(['name' => 'Importer User']));

        $customerProfile = new Customer(['company_name' => 'Customer Company']);
        $customer = new User(['name' => 'Customer User']);
        $customer->setRelation('customerProfile', $customerProfile);

        $load = new Load([
            'id' => 145, 'public_id' => 'load-public-id', 'title' => 'Fresh eggs',
            'goods_type' => 'Eggs', 'declared_value' => 1000, 'currency' => 'EUR',
            'shipment_value_currency' => 'EUR', 'incoterms' => 'DAP',
            'contact' => ['name' => 'Load Contact'],
        ]);
        $load->created_at = Carbon::parse('2026-08-30');
        $load->setRelation('company', $company);
        $load->setRelation('consignee', $consignee);
        $load->setRelation('customer', $customer);
        $load->setRelation('shipment', new Shipment(['tracking_number' => 'FB-145']));
        $load->setRelation('stops', new Collection([
            new LoadStop(['type' => 'pickup', 'city' => 'Sarajevo', 'country_code' => 'BA']),
            new LoadStop(['type' => 'delivery', 'city' => 'Munich', 'country_code' => 'DE']),
        ]));

        foreach (['DIS', 'OSI', 'DV1', 'ZNP', 'PZT'] as $code) {
            $file = app(CustomsDocumentGenerator::class)->generate($load, $code, [
                'currency_tariff' => '1,95583', 'signature_person' => 'Signer',
            ]);
            $xml = $this->documentXml($file['path']);

            $this->assertStringNotContainsString('${', $xml, "{$code} still contains a template variable.");
            $this->assertStringContainsString('Importer Company', $xml);
            @unlink($file['path']);
        }
    }

    private function documentXml(string $path): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = '';
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (str_starts_with($name, 'word/') && str_ends_with($name, '.xml')) {
                $xml .= $zip->getFromIndex($index);
            }
        }
        $zip->close();

        return html_entity_decode(strip_tags($xml));
    }
}

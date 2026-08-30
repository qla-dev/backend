<?php

namespace App\Services;

use App\Models\Load;
use PhpOffice\PhpWord\TemplateProcessor;

class CustomsDocumentGenerator
{
    public function __construct(private readonly CustomsDocumentCatalog $catalog) {}

    /** @return array{path: string, name: string} */
    public function generate(Load $load, string $code, array $formData = []): array
    {
        $document = $this->catalog->find($code);
        abort_unless($document, 404);

        $filename = $this->catalog->templateFilename($document);
        abort_unless($filename, 404);

        $templatePath = resource_path("customs-document-templates/{$filename}");
        abort_unless(is_file($templatePath), 404);

        $load->loadMissing(['company.owner', 'customer.customer', 'consignee.user', 'stops', 'shipment']);
        $processor = new TemplateProcessor($templatePath);
        $values = [...$this->sharedValues($load), ...$this->documentValues($filename, $load, $formData)];

        foreach ($processor->getVariables() as $variable) {
            $processor->setValue($variable, $this->stringValue($values[$variable] ?? ''));
        }

        $path = tempnam(sys_get_temp_dir(), 'freightbook_customs_');
        if ($path === false) {
            abort(500, 'Unable to create the customs document.');
        }
        $docxPath = $path.'.docx';
        @unlink($path);
        $processor->saveAs($docxPath);

        $reference = $load->shipment?->tracking_number ?: $load->public_id ?: $load->id;

        return [
            'path' => $docxPath,
            'name' => strtolower(pathinfo($filename, PATHINFO_FILENAME)).'_'.preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $reference).'.docx',
        ];
    }

    private function sharedValues(Load $load): array
    {
        $company = $load->company;
        $importer = $load->consignee ?: $load->customer?->customer;
        $supplier = $company;
        $stops = $load->stops;
        $origin = $stops->first(fn ($stop) => $stop->type === 'pickup') ?: $stops->first();
        $destination = $stops->first(fn ($stop) => $stop->type === 'delivery') ?: $stops->last();
        $companyAddress = $this->joinAddress($company?->address, $company?->city, $company?->country_code);
        $importerAddress = $this->joinAddress($importer?->billing_address, $importer?->city, $importer?->country_code);
        $reference = $load->shipment?->tracking_number ?: $load->public_id ?: $load->id;

        return [
            'today' => now()->format('d.m.Y'),
            'invoice_date' => optional($load->created_at)->format('d.m.Y') ?: now()->format('d.m.Y'),
            'invoice_reference_no' => $reference,
            'invoice_incoterm' => $load->incoterms,
            'invoice_incoterm_destination' => $destination?->city,
            'invoice_border_entry' => $origin?->address ?: $origin?->city,
            'invoice_border_entry_city' => $origin?->city,
            'invoice_destination_office' => $destination?->city,
            'invoice_procedure_code' => '',
            'invoice_JCI' => '',
            'invoice_JCI_date' => '',
            'itco' => $load->budget,
            'itcu' => $load->currency,
            'insurance' => $load->price_insurance,
            'insurance_currency' => $load->currency,
            'representation_type' => '',

            'company_name' => mb_strtoupper((string) $company?->name),
            'company_address' => mb_strtoupper($companyAddress),
            'company_id' => mb_strtoupper((string) ($company?->tax_number ?: $company?->vat_number ?: $company?->registration_number)),

            'importer_name' => $importer?->company_name ?: $importer?->name ?: $load->customer?->name,
            'importer_tax_id' => $importer?->tax_number ?: $importer?->vat_number,
            'importer_address' => $importerAddress,
            'importer_address_street_name' => $importer?->billing_address,
            'importer_address_street_number' => '',
            'importer_address_zip' => '',
            'importer_address_city' => $importer?->city,
            'importer_address_country' => $importer?->country_code,

            'supplier_name' => $supplier?->name,
            'supplier_tax_id' => $supplier?->tax_number ?: $supplier?->vat_number,
            'supplier_address' => $companyAddress,
            'supplier_address_street_name' => $supplier?->address,
            'supplier_address_street_number' => '',
            'supplier_address_zip' => '',
            'supplier_address_city' => $supplier?->city,
            'supplier_address_country' => $supplier?->country_code,
        ];
    }

    private function documentValues(string $filename, Load $load, array $formData): array
    {
        if ($filename === 'dis.docx') {
            return [
                'broj_disp' => $formData['broj_disp'] ?? 'GENERALNA DISPOZICIJA ',
                'text_before' => $formData['text_before'] ?? 'U skladu sa odredbama člana 5. ZOCP-a (Sl.Glasnik BiH broj 58/15) ovlašćujemo Vas:',
                'text_after' => $formData['text_after'] ?? 'da u naše ime i za naš račun, poduzmete sve radnje i postupke kod carinskih, inspekcijskih i drugih organa, koje su potrebne za provođenje postupka carinjenja robe naslovljene na našu firmu.',
                'signature_person' => $formData['signature_person'] ?? $load->company?->owner?->name,
            ];
        }

        if ($filename === 'osi.docx') {
            return [
                'osiguranje_text' => $formData['osiguranje_text'] ?? 'Izjavljujemo pod punom odgovornošću da uvezenu robu nismo osigurali u transportu a prevozni troškovi iznose: ',
                'signature_person' => $formData['signature_person'] ?? $load->company?->owner?->name,
            ];
        }

        if ($filename === 'znp.docx') {
            return array_merge([
                'roba' => $load->goods_type ?: $load->title,
                'odgovorna_osoba' => $load->contact['name'] ?? $load->contact['person'] ?? $load->company?->owner?->name,
                'valuta' => $load->shipment_value_currency ?: $load->currency,
            ], $formData);
        }

        if ($filename === 'dv1.docx') {
            $rate = (float) str_replace(',', '.', (string) ($formData['currency_tariff'] ?? 1));
            $amount = (float) ($load->declared_value ?? 0);
            $converted = $amount * ($rate ?: 1);
            $values = [
                'place' => $formData['place'] ?? '',
                'c_tariff' => $formData['currency_tariff'] ?? '1',
                'group' => '', '/group' => '',
                '1_n' => '001', '1_c' => $load->shipment_value_currency ?: $load->currency,
                '1_t' => $this->number($amount), '1_tt' => $this->number($converted),
                '1_tm' => $this->number($converted),
            ];
            foreach (['7a', '7b', '7c', '8a', '8b', '9a', '9b'] as $section) {
                $yes = filter_var($formData["section{$section}_da"] ?? false, FILTER_VALIDATE_BOOL);
                $values[$section.'d'] = $yes ? 'X' : '';
                $values[$section.'n'] = $yes ? '' : 'X';
            }
            foreach ([1, 2, 3] as $slot) {
                foreach (['n', 'c', 't', 'tt', 'et', 'ot', 'os', 'tb', 'zt', 'int', 'tc', 'tm'] as $field) {
                    $values["{$slot}_{$field}"] ??= '';
                }
            }

            return $values;
        }

        if ($filename === 'zut.docx') {
            $values = [];
            foreach (range(1, 12) as $row) {
                $values["zut_type_{$row}"] = '';
                $values["zut_amount_{$row}"] = '';
                $values["zut_document_{$row}"] = '';
            }

            return $values;
        }

        return $formData;
    }

    private function joinAddress(mixed ...$parts): string
    {
        return collect($parts)->filter(fn ($part) => filled($part))->implode(', ');
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function number(float $value): string
    {
        return $value === 0.0 ? '' : str_replace('.', ',', number_format($value, 2, '.', ''));
    }
}

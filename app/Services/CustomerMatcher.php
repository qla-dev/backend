<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CustomerMatcher
{
    public function match(array $identity): ?array
    {
        $name = trim((string) ($identity['name'] ?? ''));
        $taxNumber = $this->taxNumber((string) ($identity['tax_number'] ?? ''));
        $city = trim((string) ($identity['city'] ?? ''));
        $countryCode = strtoupper(trim((string) ($identity['country_code'] ?? '')));

        if ($name === '' && $taxNumber === '') {
            return null;
        }

        $nameTerms = $this->nameTerms($name);
        $query = Customer::query()->with('user');
        $query->where(function (Builder $customerQuery) use ($taxNumber, $nameTerms): void {
            if ($taxNumber !== '') {
                $customerQuery->where('tax_number', $taxNumber)
                    ->orWhere('vat_number', $taxNumber);
            }

            foreach ($nameTerms as $term) {
                $customerQuery->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('company_name', 'like', "%{$term}%");
            }
        });

        $matches = $query
            ->orderByRaw('source_sort_order IS NULL')
            ->orderBy('source_sort_order')
            ->limit(50)
            ->get()
            ->map(fn (Customer $customer): array => [
                'customer' => $customer,
                'score' => $this->score($customer, $name, $taxNumber, $city, $countryCode),
            ])
            ->sortByDesc('score')
            ->values();
        $match = $matches->first();

        $runnerUpScore = $matches->get(1, ['score' => null])['score'];
        if (! $match || $match['score'] < 70 || ($match['score'] < 1000 && $runnerUpScore === $match['score'])) {
            return null;
        }

        /** @var Customer $customer */
        $customer = $match['customer'];

        return [
            'id' => $customer->id,
            'text' => $customer->name ?: $customer->company_name ?: "Customer #{$customer->id}",
            'name' => $customer->name ?: $customer->company_name,
            'tax_number' => $customer->tax_number,
            'country_code' => $customer->country_code,
            'city' => $customer->city,
            'address' => $customer->billing_address,
            'source' => $customer->source,
        ];
    }

    private function score(Customer $customer, string $name, string $taxNumber, string $city, string $countryCode): int
    {
        $candidateTaxNumbers = array_filter([
            $this->taxNumber((string) $customer->tax_number),
            $this->taxNumber((string) $customer->vat_number),
        ]);
        if ($taxNumber !== '' && in_array($taxNumber, $candidateTaxNumbers, true)) {
            return 1000;
        }

        $needle = $this->companyName($name);
        $candidateNames = array_filter([
            $this->companyName((string) $customer->name),
            $this->companyName((string) $customer->company_name),
        ]);
        $score = collect($candidateNames)->max(function (string $candidate) use ($needle): int {
            if ($needle !== '' && $candidate === $needle) {
                return 100;
            }

            return $needle !== '' && (str_contains($candidate, $needle) || str_contains($needle, $candidate)) ? 75 : 0;
        }) ?? 0;

        if ($city !== '' && Str::lower((string) $customer->city) === Str::lower($city)) {
            $score += 15;
        }
        if ($countryCode !== '' && strtoupper((string) $customer->country_code) === $countryCode) {
            $score += 10;
        }

        return $score;
    }

    private function nameTerms(string $name): array
    {
        $normalized = $this->companyName($name);
        if ($normalized === '') {
            return [];
        }

        $firstWord = collect(explode(' ', $normalized))
            ->first(fn (string $word): bool => mb_strlen($word) >= 3);

        return array_values(array_unique(array_filter([$normalized, $firstWord])));
    }

    private function companyName(string $value): string
    {
        $value = Str::lower(Str::ascii(trim($value)));
        $value = preg_replace('/\b(?:d\.?\s*o\.?\s*o\.?|gmbh|ag|ltd|llc|inc|doo)\b/u', ' ', $value) ?? $value;

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value);
    }

    private function taxNumber(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]+/i', '', trim($value)) ?? '');
    }
}

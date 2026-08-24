<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CustomerMatcher
{
    public function matchConsignee(array $scan): ?array
    {
        $dedicated = [
            'name' => $scan['consigneeName'] ?? '',
            'tax_number' => $scan['consigneeTaxNumber'] ?? '',
            'city' => $scan['consigneeCity'] ?? '',
            'country_code' => $scan['consigneeCountryCode'] ?? '',
        ];
        $allCandidates = collect([$scan['receiver'] ?? null])
            ->concat($scan['customerCandidates'] ?? [])
            ->push($scan['sender'] ?? null)
            ->filter(fn ($candidate): bool => is_array($candidate))
            ->values();
        $preferred = collect([$dedicated])
            ->concat($allCandidates->filter(fn (array $candidate): bool => $this->isReceiverRole((string) ($candidate['role'] ?? ''))))
            ->map(fn (array $candidate): array => $this->customerIdentity($candidate))
            ->unique(fn (array $identity): string => json_encode($identity))
            ->values()
            ->all();

        $preferredMatch = $this->matchAny($preferred);
        if ($preferredMatch) {
            return $preferredMatch;
        }

        return $this->matchAny(
            $allCandidates
                ->reject(fn (array $candidate): bool => $this->isReceiverRole((string) ($candidate['role'] ?? '')))
                ->map(fn (array $candidate): array => $this->customerIdentity($candidate))
                ->values()
                ->all()
        );
    }

    public function matchAny(array $identities): ?array
    {
        // Mirror deklarant.ba: VAT/tax ID is authoritative and must be attempted for every party
        // before any fuzzy name can accidentally claim a match belonging to another party.
        foreach ($identities as $identity) {
            if (! is_array($identity) || $this->taxNumber((string) ($identity['tax_number'] ?? '')) === '') {
                continue;
            }

            $match = $this->match(['tax_number' => $identity['tax_number']]);
            if ($match) {
                return $match;
            }
        }

        foreach ($identities as $identity) {
            if (! is_array($identity)) {
                continue;
            }

            $match = $this->match($identity);
            if ($match) {
                return $match;
            }
        }

        return null;
    }

    public function match(array $identity): ?array
    {
        $name = trim((string) ($identity['name'] ?? ''));
        $taxNumber = $this->taxNumber((string) ($identity['tax_number'] ?? ''));
        $city = trim((string) ($identity['city'] ?? ''));
        $countryCode = strtoupper(trim((string) ($identity['country_code'] ?? '')));

        if ($name === '' && $taxNumber === '') {
            return null;
        }

        $nameTerms = $this->nameTerms($name, $city);
        $literalTerms = $this->symbolicNameTerms($name);
        $priorityMatches = collect();
        if ($taxNumber !== '') {
            $priorityMatches = $priorityMatches->concat(
                Customer::query()
                    ->with('user')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('tax_number', $taxNumber)
                        ->orWhere('vat_number', $taxNumber))
                    ->limit(10)
                    ->get()
            );
        }
        if ($literalTerms !== []) {
            $priorityMatches = $priorityMatches->concat(
                Customer::query()
                    ->with('user')
                    ->where(function (Builder $query) use ($literalTerms): void {
                        foreach ($literalTerms as $term) {
                            $query->orWhere('name', 'like', "%{$term}%")
                                ->orWhere('company_name', 'like', "%{$term}%");
                        }
                    })
                    ->limit(50)
                    ->get()
            );
        }

        $broadMatches = Customer::query()
            ->with('user')
            ->where(function (Builder $query) use ($nameTerms): void {
                foreach ($nameTerms as $term) {
                    $query->orWhere('name', 'like', "%{$term}%")
                        ->orWhere('company_name', 'like', "%{$term}%");
                }
            })
            ->orderByRaw('source_sort_order IS NULL')
            ->orderBy('source_sort_order')
            ->limit(50)
            ->get();

        $matches = $priorityMatches
            ->concat($broadMatches)
            ->unique('id')
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

        $needle = $this->companyName($name, [$city]);
        $candidateNames = array_filter([
            $this->companyName((string) $customer->name, [$city, (string) $customer->city]),
            $this->companyName((string) $customer->company_name, [$city, (string) $customer->city]),
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

    private function nameTerms(string $name, string $city): array
    {
        $normalized = $this->companyName($name, [$city]);
        if ($normalized === '') {
            return [];
        }

        $firstWord = collect(explode(' ', $normalized))
            ->first(fn (string $word): bool => mb_strlen($word) >= 3);

        return array_values(array_unique(array_filter([$normalized, $firstWord])));
    }

    private function companyName(string $value, array $cities = []): string
    {
        $value = Str::lower(Str::ascii(trim($value)));
        $value = preg_replace('/\b(?:d\s*\.?\s*o\s*\.?\s*o|doo|gmbh|ag|ltd|llc|inc|company|trgovina|trading|import|export)\b\.?/u', ' ', $value) ?? $value;

        foreach ($cities as $city) {
            $normalizedCity = Str::lower(Str::ascii(trim($city)));
            if ($normalizedCity !== '') {
                $value = str_replace($normalizedCity, ' ', $value);
            }
        }

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value);
    }

    private function symbolicNameTerms(string $name): array
    {
        $name = Str::lower(Str::ascii(trim($name)));
        preg_match_all('/(?<![a-z0-9])[a-z0-9]{1,3}\s*&\s*[a-z0-9]{1,3}(?![a-z0-9])/i', $name, $matches);

        return collect($matches[0] ?? [])
            ->flatMap(function (string $term): array {
                $compact = preg_replace('/\s+/', '', $term) ?? $term;
                $spaced = preg_replace('/\s*&\s*/', ' & ', $term) ?? $term;

                return [$compact, $spaced];
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function customerIdentity(array $candidate): array
    {
        return [
            'name' => $candidate['name'] ?? '',
            'tax_number' => $candidate['taxNumber'] ?? $candidate['tax_number'] ?? '',
            'city' => $candidate['city'] ?? '',
            'country_code' => $candidate['countryCode'] ?? $candidate['country_code'] ?? '',
        ];
    }

    private function isReceiverRole(string $role): bool
    {
        return preg_match('/buyer|receiver|consignee|customer|importer|delivery|ship\s*to|bill\s*to|kupac|primalac|empf[aä]nger/i', $role) === 1;
    }

    private function taxNumber(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]+/i', '', trim($value)) ?? '');
    }
}

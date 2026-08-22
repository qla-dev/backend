<?php

namespace Tests\Unit;

use App\Services\RelativeLoadDateResolver;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RelativeLoadDateResolverTest extends TestCase
{
    #[DataProvider('relativeDates')]
    public function test_it_resolves_relative_dates_from_the_server_date(
        string $description,
        array $current,
        string $expectedField,
        string $expectedDate,
    ): void {
        $resolver = new RelativeLoadDateResolver;
        $result = $resolver->apply(
            $description,
            ['pickupDate' => '2024-07-03', 'deliveryDate' => '2024-07-04'],
            $current,
            new DateTimeImmutable('2026-08-22'),
        );

        $this->assertSame($expectedDate, $result[$expectedField]);
    }

    public static function relativeDates(): array
    {
        return [
            'Bosnian tomorrow becomes pickup' => ['sutra', [], 'pickupDate', '2026-08-23'],
            'Bosnian day after tomorrow becomes delivery' => ['prekosutra', ['pickupDate' => '2026-08-23'], 'deliveryDate', '2026-08-24'],
            'Bosnian in three days' => ['preuzimanje za 3 dana', [], 'pickupDate', '2026-08-25'],
            'Bosnian in five days' => ['isporuka za 5 dana', ['pickupDate' => '2026-08-23'], 'deliveryDate', '2026-08-27'],
            'English tomorrow' => ['pickup tomorrow', [], 'pickupDate', '2026-08-23'],
            'German day after tomorrow' => ['Lieferung übermorgen', ['pickupDate' => '2026-08-23'], 'deliveryDate', '2026-08-24'],
        ];
    }

    public function test_it_leaves_raw_dates_untouched(): void
    {
        $resolver = new RelativeLoadDateResolver;
        $result = ['pickupDate' => '2026-09-10', 'deliveryDate' => ''];

        $this->assertSame($result, $resolver->apply('10.09.2026', $result, [], new DateTimeImmutable('2026-08-22')));
    }
}

<?php

namespace Tests\Unit;

use App\Services\LenaIntent;
use App\Http\Controllers\Api\DispatchChatController;
use App\Models\Message;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class LenaIntentTest extends TestCase
{
    public function test_customs_calculations_select_legal_mode_in_active_languages(): void
    {
        foreach (['Treba mi obračun carinskih pristojbi', 'Calculate customs duties for these invoices', 'Bitte Zollabgaben berechnen', 'Provjeri PDV u dokumentu'] as $text) {
            $this->assertSame('legal', LenaIntent::detect($text));
        }
    }

    public function test_general_calculations_skip_the_mode_menu(): void
    {
        foreach (['Izračunaj volumen za 10 paleta', 'Calculate the total transport cost', 'Bitte das Volumen berechnen'] as $text) {
            $this->assertSame('free', LenaIntent::detect($text));
        }
        $this->assertNull(LenaIntent::detect('Hello'));
        $this->assertNull(LenaIntent::detect('New load: 100 kg apples'));
    }

    public function test_document_text_selects_legal_mode_and_explicit_switch_wins(): void
    {
        $controller = new DispatchChatController;
        $method = new \ReflectionMethod($controller, 'activeGuidedMode');
        $document = new Message;
        $document->body = 'Attached declaration.pdf.';
        $document->attachments = [['name' => 'declaration.pdf', 'loadScan' => ['documentText' => 'Obračun carinskih pristojbi: 123.45 BAM']]];
        $this->assertSame('legal', $method->invoke($controller, new Collection([$document])));
        $choice = new Message;
        $choice->body = '[[LENA_ACTION:legal_upload_load]]';
        $this->assertSame('add', $method->invoke($controller, new Collection([$choice, $document])));
    }

    public function test_attachment_context_retains_every_document_and_spreadsheet(): void
    {
        $message = new Message;
        $message->attachments = [
            ['name' => 'old.pdf', 'loadScan' => ['documentText' => 'Tax base 100']],
            ['name' => 'new.pdf', 'loadScan' => ['documentText' => 'Tax base 120']],
            ['name' => 'items.csv', 'documentText' => 'item,amount\nA,20'],
        ];
        $method = new \ReflectionMethod(DispatchChatController::class, 'attachmentContext');
        $context = $method->invoke(new DispatchChatController, $message);
        foreach (['old.pdf', 'new.pdf', 'items.csv', 'Tax base 100', 'Tax base 120', 'A,20'] as $value) {
            $this->assertStringContainsString($value, $context);
        }
    }

    public function test_payment_email_starts_a_concrete_task(): void
    {
        $email = "Marko,\nIzvoli deklaracije I OCPove\nMolim te uplate prema prilogu\nHvala";
        $this->assertSame('legal', LenaIntent::detect($email));
        $this->assertTrue(LenaIntent::isPaymentRequest($email));
        $this->assertFalse(LenaIntent::isPaymentRequest('Attached AIS_U2608-01194.pdf.'));
    }
}

<?php

namespace Tests\Unit;

use App\Models\Document;
use App\Models\Load;
use App\Models\LoadNote;
use App\Models\LoadStop;
use App\Models\ShipmentWorkspace;
use App\Models\User;
use App\Services\LenaLoadDetailsContext;
use Tests\TestCase;

class LenaLoadDetailsContextTest extends TestCase
{
    // Unsaved models only. No queries, migrations, fixtures or database writes.
    private function loadDetails(): Load
    {
        $load = new Load(['title' => 'Apples', 'status' => 'booked', 'requires_adr' => false, 'budget' => 0]);
        foreach (['customer', 'company', 'consignee', 'assignedDriver', 'vehicle', 'shipment', 'vehicleReturnInspection'] as $relation) {
            $load->setRelation($relation, null);
        }
        foreach (['notes', 'documents', 'routes'] as $relation) {
            $load->setRelation($relation, collect());
        }
        $load->setRelation('stops', collect([new LoadStop([
            'type' => 'pickup', 'position' => 1, 'address' => 'Dock 4', 'window_starts_at' => '2026-09-12 08:00:00',
        ])]));
        $workspace = new ShipmentWorkspace([
            'load_snapshot' => ['transport_type' => 'road'],
            'operational_checklist' => [['key' => 'cmr_and_documents', 'status' => 'blocked', 'action_value' => 'Missing signature']],
        ]);
        $workspace->setRelation('acceptedOffer', null);
        $load->setRelation('shipmentWorkspace', $workspace);

        return $load;
    }

    public function test_it_includes_normalized_checklist_and_complete_load_fields(): void
    {
        $result = (new LenaLoadDetailsContext)->snapshot($this->loadDetails(), 7, true);

        $this->assertFalse($result['load']['requires_adr']);
        $this->assertSame(0, $result['load']['budget']);
        $this->assertSame('Dock 4', $result['stops'][0]['address']);
        $this->assertNotEmpty($result['stops'][0]['window_starts_at']);
        $items = collect($result['workspace']['operational_checklist'])->keyBy('key');
        $this->assertSame('Missing signature', $items['cmr_and_documents']['action_value']);
        $this->assertSame('in_delivery', $items['cmr_and_documents']['required_for_status']);
        $this->assertSame('provider', $items['cmr_and_documents']['responsible_party']);
        $this->assertSame('review', $items['proof_of_delivery']['required_for_status']);
        $this->assertSame('in_delivery', $items['proof_of_delivery']['waiting_for_status']);
    }

    public function test_it_excludes_other_authors_private_notes_and_file_paths(): void
    {
        $load = $this->loadDetails();
        $author = new User(['name' => 'Dispatcher', 'password' => 'must-not-leak']);
        $load->setRelation('notes', collect([
            (new LoadNote(['body' => 'Public instructions', 'is_private' => false, 'author_user_id' => 8]))->setRelation('author', $author),
            (new LoadNote(['body' => 'My private note', 'is_private' => true, 'author_user_id' => 7]))->setRelation('author', $author),
            (new LoadNote(['body' => 'Someone else private', 'is_private' => true, 'author_user_id' => 8]))->setRelation('author', $author),
        ]));
        $load->setRelation('documents', collect([new Document([
            'name' => 'CMR.pdf', 'type' => 'CMR', 'reference' => 'CMR-1', 'comment' => 'Awaiting signature', 'path' => 'secret/storage/path',
        ])]));

        $result = (new LenaLoadDetailsContext)->snapshot($load, 7, true);
        $this->assertCount(2, $result['notes']);
        $this->assertSame('CMR-1', $result['documents'][0]['reference']);
        $this->assertFalse($result['document_content_available']);
        $this->assertArrayNotHasKey('path', $result['documents'][0]);
        $this->assertArrayNotHasKey('password', $result['notes'][0]['author']);
        $this->assertStringNotContainsString('Someone else private', json_encode($result));
    }

    public function test_note_rows_are_read_even_though_the_load_has_a_notes_column_of_its_own(): void
    {
        // loads.notes is the free-text note typed into the Post a load form, and it shadows the
        // notes() relation of LoadNote rows on the model - reading the rows off the property gave
        // "Call to a member function filter() on null" for every load whose column was empty.
        foreach ([null, 'Call before pickup'] as $column) {
            $load = $this->loadDetails();
            $load->notes = $column;
            $load->setRelation('notes', collect([
                (new LoadNote(['body' => 'Public instructions', 'is_private' => false, 'author_user_id' => 8]))
                    ->setRelation('author', new User(['name' => 'Dispatcher'])),
            ]));

            $result = (new LenaLoadDetailsContext)->snapshot($load, 7, true);

            $this->assertSame('Public instructions', $result['notes'][0]['body']);
        }
    }

    public function test_marketplace_access_does_not_expose_operational_sections(): void
    {
        $result = (new LenaLoadDetailsContext)->snapshot($this->loadDetails(), 7, false);

        $this->assertSame('not_authorized', $result['operational_access']);
        foreach (['notes', 'documents', 'workspace', 'accepted_offer', 'parties', 'vehicle_return'] as $section) {
            $this->assertArrayNotHasKey($section, $result);
        }
    }

    public function test_a_follow_up_uses_changed_status_notes_and_document_list(): void
    {
        $load = $this->loadDetails();
        $context = new LenaLoadDetailsContext;
        $first = $context->snapshot($load, 7, true);
        $load->status = 'in_delivery';
        $load->setRelation('notes', collect([
            (new LoadNote(['body' => 'Use dock 5 now', 'author_user_id' => 7]))->setRelation('author', null),
        ]));
        $load->setRelation('documents', collect([new Document(['name' => 'Signed CMR.pdf'])]));
        $second = $context->snapshot($load, 7, true);

        $this->assertSame('booked', $first['load']['status']);
        $this->assertSame('in_delivery', $second['load']['status']);
        $this->assertSame('Use dock 5 now', $second['notes'][0]['body']);
        $this->assertSame('Signed CMR.pdf', $second['documents'][0]['name']);
    }
}

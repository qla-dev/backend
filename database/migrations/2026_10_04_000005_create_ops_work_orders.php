<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * FreightBook Ops: the forwarding work order (špediterski nalog), a copy of PANTHEON Proizvodnja
 * adapted to logistics. Spec: docs/pantheon-proizvodnja/freightbook_ops_prijedlog.sql.
 * SmartFreight is the source of truth and works without PANTHEON; ops_pantheon_* only drive the
 * optional sync (push ops_orders -> tHF_WOEx, pull closing back). Nothing here writes PANTHEON.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ops_service_templates')) {
            // <= tHF_SetPrSt: kind of job (FTL import, groupage export, customs, storage) with planned lines.
            Schema::create('ops_service_templates', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('company_id')->constrained()->restrictOnDelete();
                $t->string('code', 30);
                $t->string('name', 160);
                $t->string('transport_type', 60)->nullable();
                $t->unsignedTinyInteger('variant')->default(0);
                $t->boolean('active')->default(true);
                $t->unsignedBigInteger('created_by');
                $t->timestamps();
                $t->unique(['company_id', 'code', 'variant']);
            });
        }
        if (! Schema::hasTable('ops_service_template_items')) {
            Schema::create('ops_service_template_items', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('template_id')->constrained('ops_service_templates')->restrictOnDelete();
                $t->unsignedInteger('position');
                $t->string('item_type', 12); // cost | operation | revenue
                $t->string('item_code', 30);
                $t->string('description', 160);
                $t->string('unit', 10)->default('KOM');
                $t->decimal('planned_qty', 18, 4)->default(1);
                $t->decimal('planned_price', 18, 4)->nullable();
                $t->unsignedBigInteger('default_supplier_partner_id')->nullable();
                $t->unique(['template_id', 'position']);
            });
        }
        if (! Schema::hasTable('ops_orders')) {
            // <= tHF_WOEx: created when a load becomes a booked shipment (1:1 with shipment_workspace) or manually.
            Schema::create('ops_orders', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('company_id')->constrained()->restrictOnDelete();
                $t->string('reference', 20);
                $t->string('doc_type', 4)->default('SF00');
                $t->unsignedBigInteger('workspace_id')->nullable()->unique();
                $t->unsignedBigInteger('load_id')->nullable()->index();
                $t->unsignedBigInteger('crm_document_id')->nullable()->index();
                $t->unsignedBigInteger('template_id')->nullable();
                $t->unsignedBigInteger('customer_partner_id')->nullable();
                $t->string('customer_name')->nullable();
                $t->string('title')->nullable();
                $t->string('department', 30)->nullable();
                $t->unsignedBigInteger('responsible_user_id')->nullable();
                // open | dispatched | in_progress | partially_closed | closed | cancelled (PANTHEON acStatusMF O/D/P/R/Z)
                $t->string('status', 20)->default('open');
                $t->unsignedTinyInteger('priority')->default(5);
                $t->decimal('planned_qty', 18, 4)->default(1);
                $t->string('unit', 10)->default('KOM');
                $t->decimal('realised_qty', 18, 4)->nullable();
                $t->decimal('damaged_qty', 18, 4)->nullable();
                $t->dateTime('planned_start_at')->nullable();
                $t->dateTime('planned_end_at')->nullable();
                $t->dateTime('finished_at')->nullable();
                $t->char('currency', 3)->default('BAM');
                $t->decimal('agreed_revenue', 14, 2)->nullable();
                $t->text('note')->nullable();
                $t->text('close_problems')->nullable();
                $t->unsignedInteger('revision')->default(1);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'reference']);
                $t->index(['company_id', 'status']);
            });
        }
        if (! Schema::hasTable('ops_order_items')) {
            // <= tHF_WOExItem: cost (subcontractor), operation (internal work) or revenue line with plan and actual.
            Schema::create('ops_order_items', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('order_id')->constrained('ops_orders')->restrictOnDelete();
                $t->unsignedInteger('position');
                $t->string('item_type', 12);
                $t->string('item_code', 30);
                $t->string('description', 160);
                $t->string('unit', 10)->default('KOM');
                $t->decimal('planned_qty', 18, 4)->default(0);
                $t->decimal('actual_qty', 18, 4)->nullable();
                $t->decimal('planned_price', 18, 4)->nullable();
                $t->decimal('actual_price', 18, 4)->nullable();
                $t->unsignedBigInteger('supplier_partner_id')->nullable();
                $t->unsignedBigInteger('invoice_item_id')->nullable();
                $t->boolean('finished')->default(false);
                $t->timestamps();
                $t->unique(['order_id', 'position']);
            });
        }
        if (! Schema::hasTable('ops_work_logs')) {
            // <= tHF_WOExItemWork: worker time and downtime (waiting at the border or loading dock).
            Schema::create('ops_work_logs', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('order_item_id')->constrained('ops_order_items')->restrictOnDelete();
                $t->unsignedBigInteger('user_id');
                $t->date('work_date');
                $t->decimal('minutes', 10, 2);
                $t->decimal('downtime_minutes', 10, 2)->default(0);
                $t->string('downtime_type', 30)->nullable();
                $t->string('note', 500)->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('ops_events')) {
            // <= tHF_WOExRegOper: milestones (booked, loaded, border, customs, delivered, POD).
            Schema::create('ops_events', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('order_id')->constrained('ops_orders')->restrictOnDelete();
                $t->string('event_type', 30);
                $t->dateTime('occurred_at');
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedBigInteger('tracking_event_id')->nullable();
                $t->string('note', 500)->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['order_id', 'occurred_at']);
            });
        }
        if (! Schema::hasTable('ops_order_documents')) {
            // <= tHF_LinkMoveWOEx: invoices (cost/revenue) and documents (CMR, declaration, POD) of the order.
            Schema::create('ops_order_documents', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('order_id')->constrained('ops_orders')->restrictOnDelete();
                $t->unsignedBigInteger('order_item_id')->nullable();
                $t->string('role', 20); // commitment | cost | internal_work | revenue | damage | pod | attachment
                $t->unsignedBigInteger('invoice_id')->nullable();
                $t->unsignedBigInteger('document_id')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['order_id', 'role']);
            });
        }
        if (! Schema::hasTable('ops_pantheon_sync')) {
            // Optional PANTHEON sync state; the connection itself is accounting_pantheon_connectors.
            Schema::create('ops_pantheon_sync', function (Blueprint $t): void {
                $t->foreignId('company_id')->primary()->constrained()->restrictOnDelete();
                $t->boolean('sync_enabled')->default(false);
                $t->char('order_doc_type', 4)->nullable();
                $t->date('push_orders_from')->nullable();
                $t->dateTime('pull_cursor')->nullable();
                $t->timestamp('last_synced_at')->nullable();
                $t->string('last_sync_status', 12)->nullable();
                $t->text('last_sync_error')->nullable();
                $t->json('last_sync_summary')->nullable();
                $t->unsignedBigInteger('updated_by');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('ops_pantheon_links')) {
            Schema::create('ops_pantheon_links', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('company_id')->constrained()->restrictOnDelete();
                $t->string('entity_type', 20); // order | item
                $t->unsignedBigInteger('local_id');
                $t->string('pantheon_key', 30);
                $t->unsignedInteger('local_revision')->default(0);
                $t->dateTime('remote_changed_at')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'entity_type', 'local_id'], 'ops_pantheon_local');
                $t->unique(['company_id', 'entity_type', 'pantheon_key'], 'ops_pantheon_remote');
            });
        }
        if (Schema::hasTable('crm_documents') && ! Schema::hasColumn('crm_documents', 'load_draft_id')) {
            // CRM -> tracking: the offer that became a load; the stage then follows the shipment.
            Schema::table('crm_documents', function (Blueprint $t): void {
                $t->unsignedBigInteger('load_draft_id')->nullable();
                $t->unsignedBigInteger('load_id')->nullable()->index();
            });
        }
        if (! Schema::hasColumn('load_drafts', 'crm_document_id')) {
            Schema::table('load_drafts', function (Blueprint $t): void {
                $t->unsignedBigInteger('crm_document_id')->nullable()->index();
            });
        }
        if (! Schema::hasColumn('loads', 'crm_document_id')) {
            Schema::table('loads', function (Blueprint $t): void {
                $t->unsignedBigInteger('crm_document_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Operational records must be preserved. This migration has no destructive rollback.');
    }
};

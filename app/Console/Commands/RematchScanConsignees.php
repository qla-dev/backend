<?php

namespace App\Console\Commands;

use App\Models\LoadDraft;
use App\Models\Message;
use App\Services\CustomerMatcher;
use Illuminate\Console\Command;

class RematchScanConsignees extends Command
{
    protected $signature = 'load-scans:rematch-consignees {--conversation= : Only rematch one conversation ID}';

    protected $description = 'Re-run customer matching for saved LenaAI load scans without a consignee';

    public function handle(CustomerMatcher $customers): int
    {
        $updatedMessages = 0;
        $updatedDrafts = 0;

        $query = Message::query()
            ->with('conversation:id,load_draft_id')
            ->whereNotNull('attachments');
        if ($this->option('conversation')) {
            $query->where('conversation_id', (int) $this->option('conversation'));
        }

        $query
            ->orderBy('id')
            ->chunkById(100, function ($messages) use ($customers, &$updatedMessages, &$updatedDrafts): void {
                foreach ($messages as $message) {
                    $attachments = $message->attachments ?? [];
                    $changed = false;

                    foreach ($attachments as &$attachment) {
                        $scan = $attachment['loadScan'] ?? null;
                        if (! is_array($scan) || ! empty($scan['consignee'])) {
                            continue;
                        }

                        $match = $customers->matchConsignee($scan);
                        if (! $match) {
                            continue;
                        }

                        $attachment['loadScan']['consignee'] = $match;
                        $changed = true;

                        $draftId = $message->conversation?->load_draft_id;
                        if ($draftId && LoadDraft::query()->whereKey($draftId)->whereNull('consignee_customer_id')->update([
                            'consignee_customer_id' => $match['id'],
                        ])) {
                            $updatedDrafts++;
                        }
                    }
                    unset($attachment);

                    if ($changed) {
                        $message->attachments = $attachments;
                        $message->save();
                        $updatedMessages++;
                    }
                }
            });

        $this->info("Messages updated: {$updatedMessages}");
        $this->info("Drafts updated: {$updatedDrafts}");

        return self::SUCCESS;
    }
}

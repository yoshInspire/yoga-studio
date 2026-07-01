<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Support\VisitDatesFormatter;
use Illuminate\Console\Command;

class FixImportedVisitDates extends Command
{
    protected $signature = 'studio:fix-imported-visit-dates {--dry-run : Show changes without saving}';

    protected $description = 'Add year to imported visit dates in subscription admin notes';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;

        Subscription::query()
            ->whereNotNull('admin_note')
            ->where('admin_note', 'like', '%Посещения:%')
            ->orderBy('id')
            ->each(function (Subscription $subscription) use ($dryRun, &$updated) {
                $normalized = VisitDatesFormatter::normalizeAdminNote($subscription->admin_note, $subscription);

                if ($normalized === $subscription->admin_note) {
                    return;
                }

                $this->line(sprintf(
                    '#%d %s: %s',
                    $subscription->id,
                    $subscription->user?->fullName() ?? '—',
                    $this->extractVisitDates($subscription->admin_note).' → '.$this->extractVisitDates($normalized),
                ));

                if (! $dryRun) {
                    $subscription->update(['admin_note' => $normalized]);
                }

                $updated++;
            });

        $this->info(($dryRun ? 'Would update' : 'Updated')." {$updated} subscription(s).");

        return self::SUCCESS;
    }

    private function extractVisitDates(string $note): string
    {
        if (preg_match('/Посещения:\s*(.+?)(?:\n|$)/u', $note, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }
}

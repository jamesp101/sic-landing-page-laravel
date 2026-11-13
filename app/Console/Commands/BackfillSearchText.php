<?php

namespace App\Console\Commands;

use App\Content\Content;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes `search_text` for every Post and Hero section from its content. `updated_at` is left as it was. Run once
 * after the column was added (new rows are filled on save). Safe to re-run.
 */
class BackfillSearchText extends Command
{
    protected $signature = 'sic:backfill-search';

    protected $description = 'Recompute search_text of every post and hero section';

    private const BATCH = 100;

    public function handle(): int
    {
        $this->backfill('posts', 'posts');
        $this->backfill('hero sections', 'hero_sections');

        return self::SUCCESS;
    }

    private function backfill(string $label, string $table): void
    {
        $afterId = 0;
        $changed = 0;
        $total = 0;
        while (($rows = DB::table($table)->where('id', '>', $afterId)->orderBy('id')->limit(self::BATCH)->get(['id', 'content', 'search_text']))->isNotEmpty()) {
            foreach ($rows as $row) {
                $searchText = Content::forStorage((string) $row->content)->searchText;
                if ($searchText !== $row->search_text) {
                    // The query builder, not the model: it must not touch `updated_at`.
                    DB::table($table)->where('id', $row->id)->update(['search_text' => $searchText]);
                    $changed++;
                }
            }
            $total += $rows->count();
            $afterId = $rows->last()->id;
        }
        $this->info("{$label}: {$changed} of {$total} updated.");
    }
}

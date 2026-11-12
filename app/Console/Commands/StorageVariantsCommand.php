<?php

namespace App\Console\Commands;

use App\Models\CarouselItem;
use App\Storage\ImageVariant;
use Illuminate\Console\Command;

/**
 * Creates the missing variants (`<key>.w640.webp` for Highlight images, `<key>.w960.webp` for Slide photos,
 * `<key>.w128.webp` for Department logos and Member portraits) of stored images. Run once after deploying variants
 * to existing data; safe to run again (existing variants are kept).
 */
class StorageVariantsCommand extends Command
{
    protected $signature = 'storage:variants';

    protected $description = 'Create the missing image variants of stored images';

    public function handle(): int
    {
        $report = ImageVariant::backfillVariants(CarouselItem::query()->pluck('image_path'));
        $this->line("Variants created: {$report['created']}, already there: {$report['existing']}, skipped: ".count($report['skipped']));
        foreach ($report['skipped'] as $skipped) {
            $this->line("  {$skipped['key']}: {$skipped['reason']}");
        }

        return self::SUCCESS;
    }
}

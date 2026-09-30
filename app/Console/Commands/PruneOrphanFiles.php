<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\ProductImage;
use App\Services\CompanyLogoStore;
use App\Services\ProductImageStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneOrphanFiles extends Command
{
    protected $signature = 'storage:prune-orphans {--dry-run : List the files without deleting them}';

    protected $description = 'Delete stored product images and logos that no database row points to any more';

    /** A file this young may belong to an upload that has not been recorded yet. */
    private const GRACE_SECONDS = 3600;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $removed = 0;

        $folders = [
            [ProductImageStore::DISK, 'product-images', fn () => ProductImage::withoutGlobalScopes()->pluck('path')->all()],
            [CompanyLogoStore::DISK, 'company-logos', fn () => Company::withoutGlobalScopes()->whereNotNull('logo_path')->pluck('logo_path')->all()],
        ];

        foreach ($folders as [$diskName, $folder, $referenced]) {
            $disk = Storage::disk($diskName);
            $known = array_flip($referenced());

            foreach ($disk->allFiles($folder) as $path) {
                if (isset($known[$path]) || time() - $disk->lastModified($path) < self::GRACE_SECONDS) {
                    continue;
                }

                $this->line(($dry ? 'Would delete ' : 'Deleting ').$path);

                if (! $dry) {
                    $disk->delete($path);
                }

                $removed++;
            }
        }

        $this->info(($dry ? 'Found ' : 'Deleted ')."{$removed} orphan file(s).");

        return self::SUCCESS;
    }
}

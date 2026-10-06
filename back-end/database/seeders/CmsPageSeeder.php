<?php

namespace Database\Seeders;

use App\Enums\PageStatus;
use App\Models\Page;
use App\Services\CmsSectionDefinition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CmsPageSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $definitions = app(CmsSectionDefinition::class);
            foreach ($definitions->pages() as $key => $title) {
                $page = Page::firstOrCreate(['key' => $key], ['title' => $title, 'slug' => $key, 'status' => PageStatus::Draft]);
                foreach (array_keys($definitions->sections($key)) as $sort => $sectionKey) {
                    $page->sections()->firstOrCreate(['key' => $sectionKey], ['type' => $definitions->sections($key)[$sectionKey], 'sort_order' => $sort, 'is_active' => true, 'data' => null]);
                }
            }
        });
    }
}

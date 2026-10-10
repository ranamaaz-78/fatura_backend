<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the public website says in its FAQ and its legal pages, in English and Spanish, kept here so the platform
     * admin can change it without a release. It starts with what the site said before this existed.
     */
    public function up(): void
    {
        Schema::create('site_faqs', function (Blueprint $table) {
            $table->id();
            $table->text('question_en');
            $table->text('answer_en');
            // Left empty, the English is shown instead.
            $table->text('question_es')->nullable();
            $table->text('answer_es')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('site_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('title_en');
            $table->string('title_es')->nullable();
            $table->longText('body_en');
            $table->longText('body_es')->nullable();
            $table->timestamps();
        });

        $path = database_path('seeders/site_content.json');

        if (! File::exists($path)) {
            return;
        }

        $data = json_decode(File::get($path), true) ?: [];
        $now = now();

        foreach (($data['faqs'] ?? []) as $position => $faq) {
            DB::table('site_faqs')->insert($faq + ['sort_order' => $position + 1, 'is_published' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach (($data['pages'] ?? []) as $slug => $page) {
            DB::table('site_pages')->insert($page + ['slug' => $slug, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_pages');
        Schema::dropIfExists('site_faqs');
    }
};

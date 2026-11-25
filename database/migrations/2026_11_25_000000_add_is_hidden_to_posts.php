<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A Hidden post: left out of every public list, but its own page still opens (noindex). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('posts', 'is_hidden')) {
            Schema::table('posts', function (Blueprint $table) {
                $table->boolean('is_hidden')->default(false)->after('hide_author');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('posts', 'is_hidden')) {
            Schema::table('posts', function (Blueprint $table) {
                $table->dropColumn('is_hidden');
            });
        }
    }
};

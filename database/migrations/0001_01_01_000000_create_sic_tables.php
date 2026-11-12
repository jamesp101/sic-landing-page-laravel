<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table and column names match the Astro/Prisma schema (itself the original Laravel schema), so an existing
 * database can be adopted in place: every table that already exists is left untouched. The Better Auth tables
 * of the Astro site (auth_sessions, auth_accounts, auth_verifications, auth_rate_limits) are not used and not
 * created here; `users.password` already holds the bcrypt hash they mirrored.
 */
return new class extends Migration
{
    public function up(): void
    {
        $create = function (string $table, Closure $definition): void {
            if (! Schema::hasTable($table)) {
                Schema::create($table, $definition);
            }
        };

        $create('departments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->longText('description')->nullable();
            $table->longText('hero_content')->nullable();
            $table->string('logo_path')->nullable();
            $table->timestamps();
        });

        $create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->boolean('email_verified')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->text('image')->nullable();
            $table->string('password')->nullable();
            $table->string('remember_token')->nullable();
            $table->string('role')->default('department_user');
            $table->unsignedInteger('department_id')->nullable();
            $table->timestamps();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });

        $create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        $create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        $create('pending_uploads', function (Blueprint $table) {
            $table->increments('id');
            $table->string('key')->unique();
            $table->unsignedInteger('user_id');
            $table->timestamp('expires_at')->index();
        });

        $create('carousel_items', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->string('image_path');
            $table->text('link_url')->nullable();
            $table->longText('overlay')->nullable();
            $table->string('video_path')->nullable();
            $table->text('video_url')->nullable();
            $table->integer('order')->default(0);
            $table->unsignedInteger('department_id')->nullable();
            $table->boolean('show_on_main')->default(true);
            $table->timestamps();
            $table->foreign('department_id')->references('id')->on('departments')->cascadeOnDelete();
            $table->index(['show_on_main', 'order']);
            $table->index(['department_id', 'order']);
        });

        $create('members', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('role');
            $table->unsignedInteger('department_id');
            $table->unsignedInteger('parent_id')->nullable();
            $table->integer('order')->default(0);
            $table->string('image_path')->nullable();
            $table->timestamps();
            $table->foreign('department_id')->references('id')->on('departments')->cascadeOnDelete();
            $table->foreign('parent_id')->references('id')->on('members')->nullOnDelete();
        });

        $create('navigation_links', function (Blueprint $table) {
            $table->increments('id');
            $table->string('label');
            $table->text('url');
            $table->integer('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('parent_id')->nullable();
            $table->timestamps();
            $table->foreign('parent_id')->references('id')->on('navigation_links')->cascadeOnDelete();
        });

        $create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content');
            // Plain text of `content` for admin and public search (kept by Content::searchTextOf).
            $table->longText('search_text')->default('');
            // article | event | announcement
            $table->string('type')->index();
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->string('highlight_image_path')->nullable();
            $table->unsignedInteger('department_id')->nullable();
            $table->unsignedInteger('user_id');
            $table->boolean('is_featured')->default(false);
            $table->boolean('hide_author')->default(false);
            $table->timestamps();
            $table->foreign('department_id')->references('id')->on('departments')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('created_at');
            $table->index(['department_id', 'created_at']);
        });

        $create('downloads', function (Blueprint $table) {
            $table->increments('id');
            $table->string('file_name');
            $table->string('file_path');
            $table->integer('file_size');
            // JSON-encoded string[]
            $table->longText('tags')->nullable();
            $table->string('directory')->nullable();
            $table->unsignedInteger('department_id')->nullable();
            $table->unsignedInteger('user_id');
            $table->timestamps();
            $table->foreign('department_id')->references('id')->on('departments')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['department_id', 'directory']);
        });

        $create('download_directories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('department_id')->nullable();
            $table->timestamps();
            $table->foreign('department_id')->references('id')->on('departments')->cascadeOnDelete();
        });

        $create('hero_sections', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->longText('content');
            // Plain text of `content` for admin search (kept by Content::searchTextOf).
            $table->longText('search_text')->default('');
            $table->integer('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('department_id')->nullable();
            $table->timestamps();
            $table->foreign('department_id')->references('id')->on('departments')->cascadeOnDelete();
            $table->index(['department_id', 'is_active', 'order']);
        });

        $create('settings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('key')->unique();
            $table->longText('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'settings', 'hero_sections', 'download_directories', 'downloads', 'posts', 'navigation_links',
            'members', 'carousel_items', 'pending_uploads', 'sessions', 'password_reset_tokens', 'users', 'departments',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

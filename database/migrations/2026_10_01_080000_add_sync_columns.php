<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Offline-first sync: every teacher and contribution gets a client-generatable
 * UUID so records created on a device without network keep their identity when
 * they reach the server. Teachers become soft-deletable so deletions sync too.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['teachers', 'contributions'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->uuid('uuid')->nullable()->after('id');
            });
            DB::table($table)->whereNull('uuid')->orderBy('id')->each(function ($row) use ($table) {
                DB::table($table)->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
            });
            Schema::table($table, function (Blueprint $t) {
                $t->unique('uuid');
                $t->index('updated_at');
            });
        }

        Schema::table('teachers', function (Blueprint $t) {
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('teachers', fn (Blueprint $t) => $t->dropSoftDeletes());
        foreach (['teachers', 'contributions'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropUnique(['uuid']);
                $t->dropIndex(['updated_at']);
                $t->dropColumn('uuid');
            });
        }
    }
};

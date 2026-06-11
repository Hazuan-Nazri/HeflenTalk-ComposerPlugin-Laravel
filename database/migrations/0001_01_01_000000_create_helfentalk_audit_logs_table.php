<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('helfentalk.audit.table', 'helfentalk_audit_logs');

        Schema::create($table, function (Blueprint $table)
        {
            $table->id();
            $table->string('user_id')->nullable()->index();
            $table->string('role')->nullable();
            $table->string('table_name')->index();
            $table->string('operation');
            $table->json('detail')->nullable();
            $table->unsignedInteger('affected_rows')->default(0);
            $table->boolean('committed')->default(false);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('helfentalk.audit.table', 'helfentalk_audit_logs'));
    }
};

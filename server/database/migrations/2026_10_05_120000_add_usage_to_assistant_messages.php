<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What an assistant turn cost (ADR 0068 §8): model requests, and prompt,
     * output, cached and thinking tokens, summed over the turn.
     */
    public function up(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->json('usage')->nullable()->after('actions');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->dropColumn('usage');
        });
    }
};

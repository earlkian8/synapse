<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persistent history for the Synapse assistant so conversations survive across
     * sessions and devices (per user, tenant-scoped).
     *
     *  - **assistant_conversations**: one chat thread owned by a user, with a
     *    title (auto-derived from the first message) and a pin flag.
     *  - **assistant_messages**: the turns within a thread. Assistant turns also
     *    carry the agent `steps` transcript and result `actions` (cards) so the
     *    timeline re-renders exactly when a thread is reopened.
     *
     * Both tables are tenant-scoped (`organization_id`).
     */
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->boolean('pinned')->default(false);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'pinned']);
            $table->index(['user_id', 'last_activity_at']);
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->string('role'); // user|assistant
            $table->text('body')->nullable();
            $table->json('steps')->nullable();       // agent timeline (assistant turns)
            $table->json('actions')->nullable();     // result cards (assistant turns)
            $table->json('attachments')->nullable(); // attached file names (user turns)
            $table->boolean('failed')->default(false); // assistant error → retryable
            $table->timestamps();

            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};

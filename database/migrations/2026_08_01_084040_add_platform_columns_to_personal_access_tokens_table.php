<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turn Sanctum's token table into an API key store for a platform.
     *
     * Sanctum already provides the parts that matter: a polymorphic `tokenable`, so a team can own a key
     * rather than a person; a SHA-256 hash of the secret; abilities; expiry; and last-used tracking. What
     * it has no notion of is who minted the key, which of a customer's two environments it acts in, how to
     * show it in a list without revealing it, or a rotation that does not break the caller.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            // Attribution, not ownership. The team owns the key, so a member leaving cannot take a
            // customer's integration down with them — nullOnDelete keeps the key working and simply
            // forgets who created it.
            $table->foreignId('created_by')->nullable()->after('name')
                ->constrained('users')->nullOnDelete();

            // Which of the customer's two worlds the key acts in. Stored rather than parsed back out of
            // the prefix, so it can be queried and indexed.
            $table->string('mode', 8)->default('live')->after('created_by')->index();

            // The last characters of the secret, so a key is recognisable in a list. The secret itself is
            // never recoverable; only its hash is stored.
            $table->string('last_four', 8)->nullable()->after('mode');

            $table->string('last_used_ip', 45)->nullable()->after('last_used_at');

            // Rotation issues a replacement immediately and lets the old key keep working for a grace
            // period, so a customer can deploy the new one without an outage. A revoked key is kept
            // rather than deleted: a `last_used_at` after this timestamp is how you learn someone is
            // still sending the old one.
            $table->timestamp('revoked_at')->nullable()->after('last_used_ip')->index();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['mode', 'last_four', 'last_used_ip', 'revoked_at']);
        });
    }
};

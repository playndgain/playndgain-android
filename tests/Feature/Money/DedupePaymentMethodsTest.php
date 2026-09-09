<?php

use App\Enums\PaymentMethodType;
use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\Publisher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The migration is invoked by hand, the way ReleaseHeldEarningsTest does it:
 * RefreshDatabase already ran it against empty tables, and the unique key it
 * adds has to be dropped before duplicates can exist at all.
 */
it('keeps the active destination per type and audits the rest', function () {
    Schema::table('payment_methods', fn (Blueprint $table) => $table->dropUnique(['publisher_id', 'type']));

    $publisher = Publisher::factory()->create();

    $older = PaymentMethod::create(['publisher_id' => $publisher->id, 'type' => PaymentMethodType::Paypal, 'details' => ['email' => 'old@example.com'], 'is_default' => true]);
    $newer = PaymentMethod::create(['publisher_id' => $publisher->id, 'type' => PaymentMethodType::Paypal, 'details' => ['email' => 'new@example.com'], 'is_default' => false]);
    $crypto = PaymentMethod::create(['publisher_id' => $publisher->id, 'type' => PaymentMethodType::Crypto, 'details' => ['asset' => 'USDT', 'network' => 'TRC20', 'address' => 'T1'], 'is_default' => false]);

    (require database_path('migrations/2026_09_02_000002_dedupe_payment_methods.php'))->up();

    expect(PaymentMethod::query()->withoutGlobalScopes()->where('publisher_id', $publisher->id)->pluck('id')->sort()->values()->all())
        ->toBe([$older->id, $crypto->id])
        ->and(AuditLog::query()->where('action', 'payment_method.deduplicated')->where('subject_id', $newer->id)->exists())->toBeTrue();
});

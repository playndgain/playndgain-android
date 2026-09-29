<?php

use App\Networks\Drivers\AdtogameDriver;
use App\Networks\Drivers\AyetStudiosDriver;
use App\Networks\Drivers\ClickwallDriver;
use App\Networks\Drivers\DirectDriver;
use App\Networks\Drivers\GemiAdDriver;
use App\Networks\Drivers\HangMyAdsDriver;
use App\Networks\Drivers\KlinkDriver;
use App\Networks\Drivers\MonlixDriver;
use App\Networks\Drivers\MyChipsDriver;
use App\Networks\Drivers\NotikDriver;
use App\Networks\Drivers\OfferyDriver;
use App\Networks\Drivers\OGAdsDriver;
use App\Networks\Drivers\SkylupDriver;
use App\Networks\NetworkDriverManager;
use App\Providers\NetworkServiceProvider;

beforeEach(function () {
    $this->app->register(NetworkServiceProvider::class);
    $this->manager = $this->app->make(NetworkDriverManager::class);
});

it('exposes a postback url every registered driver can render', function () {
    foreach ($this->manager->all() as $driver => $class) {
        expect($class::postbackQuery())->toBeArray()
            ->and($class::postbackPath($driver))
            ->toStartWith(url('/postback/'.$driver))
            ->not->toContain('%7B');
    }
});

it('builds the documented paste url for a network that writes its own template', function (string $class, string $query) {
    expect($class::postbackPath($class::key()))
        ->toBe(url('/postback/'.$class::key()).'?'.$query);
})->with([
    'ayetstudios' => [
        AyetStudiosDriver::class,
        'callback_type={callback_type}&transaction_id={transaction_id}&is_chargeback={is_chargeback}&payout_usd={payout_usd}&currency_amount={currency_amount}&custom_1={custom_1}&external_identifier={external_identifier}&offer_id={offer_id}&offer_name={offer_name}&ip={ip}',
    ],
    'gemiad' => [
        GemiAdDriver::class,
        'userId={USER_ID}&offerId={OFFER_ID}&offerName={OFFER_NAME}&eventName={EVENT_NAME}&payout={PAYOUT}&txid={TXID}&status={STATUS}&ip={IPADDR}&hash={HASH}',
    ],
    'adtogame' => [
        AdtogameDriver::class,
        'click_id={sub2}&source_id={sub1}&user_id={user_id}&transaction_id={transaction_id}&conversion_id={conversion_id}&campaign_id={offer_id}&campaign_name={offer_name}&event_id={event_id}&event_name={event_name}&payout={payout_amount}&currency={offer_currency}&ip={session_ip}',
    ],
    'hangmyads' => [
        HangMyAdsDriver::class,
        'subid={subid}&offerid={offerid}&pay={pay}&goalname={goalname}&convid={convid}&offer_name={offer_name}',
    ],
    'direct' => [
        DirectDriver::class,
        'transaction_id={transaction_id}&click_id={click_id}&payout={payout}&secret={secret}&status={status}&offer_id={offer_id}',
    ],
    'monlix' => [
        MonlixDriver::class,
        'transaction_id={{transactionId}}&payout={{payout}}&status={{status}}&subid={{subId}}&user_id={{userId}}&offer_name={{taskName}}&ip={{userIp}}&secret={{secretKey}}',
    ],
    'mychips' => [
        MyChipsDriver::class,
        'click_id={click_id}&aff_sub1={aff_sub1}&payout={payout}&user_id={user_id}&event_name={event_name}&campaign_id={campaign_id}&postback_id={postback_id}&user_payout={user_payout}&conversion_country={conversion_country}&conversion_time={unix_timestamp}',
    ],
    'ogads' => [
        OGAdsDriver::class,
        'click_id={aff_sub4}&payout={payout}&offer_id={offer_id}&offer_name={offer_name}&ip={session_ip}&datetime={datetime}&session_timestamp={session_timestamp}&affiliate_id={affiliate_id}&source={source}&aff_sub={aff_sub}&aff_sub2={aff_sub2}&aff_sub3={aff_sub3}&aff_sub5={aff_sub5}',
    ],
    'klink' => [
        KlinkDriver::class,
        'payout={{payout}}&status={{status}}&userId={{userId}}&offerId={{offerId}}&offerName={{offerName}}&eventId={{eventId}}&eventName={{eventName}}&taskId={{taskId}}&eventType={{eventType}}&conversionId={{conversionId}}&reversedConversionId={{reversedConversionId}}&k1={{k1}}&k2={{k2}}&k3={{k3}}&k4={{k4}}&k5={{k5}}&ip={{ip}}',
    ],
    'clickwall' => [
        ClickwallDriver::class,
        'user={user_id}&reward={amount}&payout={payout}&tx={txid}&oid={offer_id}&offer_name={offer_name}&status={status}&ip={user_ip}',
    ],
    'skylup' => [
        SkylupDriver::class,
        'click_id=#{click.publisher.clickId}&payout=#{payout.theyGetInDollars}&txid=#{id}&status=#{status.state}&offer_id=#{offer.offerId}&offer_name=#{offer.name}&event_id=#{offer.eventTypeId}&event_name=#{eventType.name}&ip=#{click.user.connection.ip}',
    ],
]);

it('leaves the path bare when the network fires a fixed payload', function (string $class) {
    expect($class::postbackQuery())->toBe([])
        ->and($class::postbackPath($class::key()))->toBe(url('/postback/'.$class::key()));
})->with([
    'notik' => [NotikDriver::class],
    'offery' => [OfferyDriver::class],
]);

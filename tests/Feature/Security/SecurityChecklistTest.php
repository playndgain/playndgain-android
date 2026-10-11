<?php

use App\Enums\PaymentMethodType;
use App\Enums\WithdrawalStatus;
use App\Filament\Publisher\Auth\Login as PublisherLogin;
use App\Filament\Publisher\Resources\Apps\AppResource;
use App\Models\App;
use App\Models\Network;
use App\Models\OfferClick;
use App\Models\PaymentMethod;
use App\Models\Publisher;
use App\Models\Withdrawal;
use App\Support\Docs\DocSource;
use App\Support\Identity;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;
use Livewire\Livewire;
use Spatie\Backup\Tasks\Backup\DbDumperFactory;

require_once __DIR__.'/../Wall/WallFixtures.php';

/**
 * docs/11-infrastructure.md §10 and docs/00-overview.md §8, as assertions.
 *
 * A checklist is a promise; the rows below are the ones that can be checked by
 * a machine, so they are checked by a machine on every run instead of being
 * ticked once by whoever happened to be doing the launch. The rows that are not
 * here — UFW, fail2ban, SSH keys, Redis password, `.env` mode 600, the restore
 * drill — are the operator's and cannot be seen from inside the application;
 * they are listed in deploy/README.md, which is where someone standing on the
 * box will look for them.
 *
 * Some rows are enforced by configuration this application ships but does not
 * execute — the nginx blocks in deploy/. Those are asserted against the files
 * in deploy/, because a header the repository never states is a header the next
 * rebuild invents from memory.
 */

/**
 * The cast is not the assertion.
 *
 * `$casts['secret_key'] => 'encrypted'` can be deleted and a test that only
 * reads the model back still passes, because a plaintext column round-trips
 * perfectly. So the raw column is read past Eloquent and searched for the
 * plaintext it must not contain, and then decrypted to prove the value is ours
 * and not merely unreadable.
 */
function assertColumnEncrypted(Model $model, string $column, string $plaintext): void
{
    expect($model->getCasts()[$column] ?? null)->toStartWith('encrypted');

    $raw = DB::table($model->getTable())->where('id', $model->getKey())->value($column);

    expect($raw)->toBeString()
        ->and((string) $raw)->not->toContain($plaintext)
        ->and(Crypt::decryptString((string) $raw))->toContain($plaintext);

    // And it still comes back out the other side — an encrypted column nobody
    // can read is a different outage.
    $fresh = $model->newQuery()->findOrFail($model->getKey());

    expect(json_encode($fresh->getAttribute($column)))->toContain($plaintext);
}

/** Contents of a file under deploy/, which is where the ops half of §10 lives. */
function deployFile(string $path): string
{
    $full = base_path('deploy/'.$path);

    expect(is_file($full))->toBeTrue();

    return (string) file_get_contents($full);
}

it('encrypts app secrets, network credentials, payment details and withdrawal details at rest', function () {
    $app = App::factory()->create();

    // Generated in App::booted(), so it is read back through the cast before it
    // can be compared with what is on disk.
    assertColumnEncrypted($app, 'secret_key', (string) $app->secret_key);

    $network = Network::factory()->create(['credentials' => ['api_key' => 'NEEDLE-network-key']]);
    assertColumnEncrypted($network, 'credentials', 'NEEDLE-network-key');

    $publisher = Publisher::factory()->create();

    $method = PaymentMethod::query()->create([
        'publisher_id' => $publisher->id,
        'type' => PaymentMethodType::Paypal,
        'details' => ['email' => 'NEEDLE-payout@example.com'],
    ]);
    assertColumnEncrypted($method, 'details', 'NEEDLE-payout@example.com');

    $withdrawal = Withdrawal::query()->create([
        'publisher_id' => $publisher->id,
        'payment_method_id' => $method->id,
        'amount' => '25.000000',
        'method' => PaymentMethodType::Paypal,
        'payment_details' => 'NEEDLE-payout@example.com',
        'status' => WithdrawalStatus::Requested,
        'requested_at' => now(),
    ]);
    assertColumnEncrypted($withdrawal, 'payment_details', 'NEEDLE-payout@example.com');
});

it('rate limits the wall and the click endpoint', function () {
    expect(RateLimiter::limiter('wall'))->not->toBeNull()
        ->and(RateLimiter::limiter('click'))->not->toBeNull();

    // Registered is not the same as applied. ThrottleTest proves the wall limit
    // sheds traffic for real; this asserts the routes are the ones wearing it.
    expect(Route::getRoutes()->getByName('wall')?->gatherMiddleware() ?? [])->toContain('throttle:wall')
        ->and(Route::getRoutes()->getByName('wall.click')?->gatherMiddleware() ?? [])->toContain('throttle:click');
});

/**
 * `/postback/{driver}` is the one public endpoint that writes a database row
 * before it has authenticated anything — PostbackController logs first, on
 * purpose, because that row is the only answer to a network claiming it sent
 * conversions we never credited. Unthrottled, that same property lets a flood
 * of garbage fill `incoming_postbacks` at whatever rate the link allows.
 *
 * Both halves are asserted: a limiter named `postback` exists, and the route
 * wears it. Laravel resolves the `throttle:postback` alias to the middleware
 * class by the time `gatherMiddleware()` sees it, so match either spelling —
 * asserting only the alias passes today and breaks on a framework detail that
 * has nothing to do with the endpoint being protected.
 */
it('rate limits the inbound postback endpoint', function () {
    expect(RateLimiter::limiter('postback'))->not->toBeNull();

    $middleware = Route::getRoutes()->getByName('postback')?->gatherMiddleware() ?? [];

    $throttled = collect($middleware)->contains(
        fn (mixed $entry): bool => is_string($entry) && str_ends_with($entry, ':postback')
    );

    expect($throttled)->toBeTrue(
        "the postback route carries no throttle: add ->middleware('throttle:postback') in routes/web.php"
    );
});

it('rate limits panel login', function () {
    Filament::setCurrentPanel('publisher');

    $publisher = Publisher::factory()->create();

    foreach (range(1, 5) as $ignored) {
        Livewire::test(PublisherLogin::class)
            ->fillForm(['email' => $publisher->user->email, 'password' => 'wrong-password'])
            ->call('authenticate');
    }

    // The sixth attempt carries the *correct* password and is still refused.
    // That is the property worth asserting: PanelLoginTest already proves these
    // credentials work, so a guest here can only mean the limiter held.
    Livewire::test(PublisherLogin::class)
        ->fillForm(['email' => $publisher->user->email, 'password' => 'password'])
        ->call('authenticate');

    $this->assertGuest();
});

it('serves the wall with nothing that stops a publisher embedding it', function () {
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);
    wallGeo('US');

    $response = wallGet($app)->assertOk();

    // The single most likely way to break every integration at once
    // (docs/11 §2). A global hardening middleware would show up here first.
    expect($response->headers->get('X-Frame-Options'))->toBeNull()
        ->and((string) $response->headers->get('Content-Security-Policy'))->not->toContain('frame-ancestors \'self\'')
        ->and((string) $response->headers->get('Content-Security-Policy'))->not->toContain('frame-ancestors \'none\'');
});

it('ships nginx blocks that frame-protect everything except the wall', function () {
    // The application sets no framing header on any surface — on this stack the
    // header is nginx's, per docs/11 §2. So the asymmetry §10 demands is
    // asserted where it is actually configured, in the files this repository
    // hands the server.
    $headers = deployFile('nginx/snippets/playndgain-security-headers.conf');

    expect($headers)->toContain('X-Frame-Options "SAMEORIGIN"')
        ->and($headers)->toContain('Strict-Transport-Security');

    foreach (['nginx/playndgain.com.conf', 'nginx/api.playndgain.com.conf'] as $path) {
        expect(deployFile($path))->toContain('snippets/playndgain-security-headers.conf');
    }

    $wall = deployFile('nginx/wall.playndgain.com.conf');

    expect($wall)->toContain('add_header X-Frame-Options "" always;')
        ->and($wall)->toContain('frame-ancestors *')
        // HSTS is for the panels only (§10). On the wall it would be set for
        // every publisher whose page embeds us.
        ->and($wall)->not->toContain('Strict-Transport-Security')
        ->and($wall)->not->toContain('snippets/playndgain-security-headers.conf');
});

/**
 * The same asymmetry, in the container image.
 *
 * docs/13-dokploy.md ships this application as three containers behind
 * Dokploy's Traefik, and the server blocks there are rendered from
 * docker/nginx/templates rather than installed from deploy/nginx. Two copies of
 * a rule is how a rule drifts, so the test that guards the rule guards both —
 * and the snippets, which carry the actual header lines, are not copied at all:
 * the image installs the files above verbatim, which is what the Dockerfile
 * assertion below pins.
 */
it('keeps the container nginx blocks in step with the deployed ones', function () {
    $container = function (string $path): string {
        $full = base_path($path);

        expect(is_file($full))->toBeTrue();

        return (string) file_get_contents($full);
    };

    // One source for the header lines themselves. If this COPY ever becomes a
    // second copy under docker/, the wall's rule can change in one place and
    // not the other.
    expect($container('Dockerfile'))->toContain('deploy/nginx/snippets/');

    $wall = $container('docker/nginx/templates/20-wall.conf.template');

    expect($wall)->toContain('add_header X-Frame-Options "" always;')
        ->and($wall)->toContain('frame-ancestors *')
        ->and($wall)->toContain('fastcgi_hide_header X-Frame-Options;')
        ->and($wall)->not->toContain('Strict-Transport-Security')
        ->and($wall)->not->toContain('snippets/playndgain-security-headers.conf');

    foreach (['10-site', '30-api'] as $name) {
        expect($container("docker/nginx/templates/{$name}.conf.template"))
            ->toContain('snippets/playndgain-security-headers.conf');
    }

    // An unmatched Host — a preview domain, a probe by IP — must land on the
    // site block, whose headers are the strict ones. Default-serving the wall
    // instead would hand framing-off to any name nobody configured.
    expect($container('docker/nginx/templates/10-site.conf.template'))->toContain('listen 80 default_server;')
        ->and($wall)->not->toContain('listen 80 default_server;');

    foreach (['10-site', '20-wall', '30-api'] as $name) {
        expect($container("docker/nginx/templates/{$name}.conf.template"))
            ->toContain('root /var/www/html/public;');
    }

    // Behind a proxy the wall's `limit_req` keys on the proxy unless nginx is
    // told where the client address really is — one key for every visitor, and
    // the 60r/m budget spent by the first busy second. The fraud engine reads
    // the same address.
    expect($container('docker/nginx/nginx.conf'))->toContain('real_ip_header    X-Forwarded-For;')
        ->and($container('docker/nginx/nginx.conf'))->toContain('real_ip_recursive on;');
});

it('points every embed at the site host, which serves /wall framable', function () {
    config(['app.url' => 'https://playndgain.com', 'playndgain.wall_url' => null]);

    expect(Identity::wallUrl())->toBe('https://playndgain.com')
        ->and(AppResource::snippet(null))
        ->toContain('src="https://playndgain.com/wall?appid=YOUR_APP_ID')
        ->and(DocSource::substitute('{APP_URL}/wall?appid=1 {APP_URL}/docs'))
        ->toBe('https://playndgain.com/wall?appid=1 https://playndgain.com/docs');

    // PLAYNDGAIN_WALL_URL still overrides, for a deploy that wants the wall
    // on its own host again.
    config(['playndgain.wall_url' => 'https://wall.example.test/']);

    expect(Identity::wallUrl())->toBe('https://wall.example.test');
});

it('clears framing on the site host for /wall only', function () {
    $sites = [
        'deploy' => deployFile('nginx/playndgain.com.conf'),
        'container' => (string) file_get_contents(base_path('docker/nginx/templates/10-site.conf.template')),
    ];

    foreach ($sites as $site) {
        // The rest of the host keeps the strict set, panels included.
        expect($site)->toContain('include /etc/nginx/snippets/playndgain-security-headers.conf;')
            ->and($site)->not->toContain('return 308');

        expect(preg_match('/location ~ \^\/wall\(\/\|\$\) \{(.*?)\n    \}/s', $site, $match))->toBe(1);
        $wall = $match[1];

        // It must run PHP itself: falling through try_files would answer from
        // the \.php$ block, whose inherited headers include SAMEORIGIN.
        expect($wall)->toContain('fastcgi_pass ')
            ->and($wall)->toContain('fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;')
            ->and($wall)->toContain('fastcgi_hide_header X-Frame-Options;')
            ->and($wall)->toContain('add_header X-Frame-Options "" always;')
            ->and($wall)->toContain('frame-ancestors *')
            ->and($wall)->toContain('limit_req zone=wall')
            ->and($wall)->not->toContain('SAMEORIGIN')
            ->and($wall)->not->toContain('try_files')
            ->and($wall)->not->toContain('Strict-Transport-Security');
    }
});

it('lets the nginx worker write spilled request bodies', function () {
    // Alpine's nginx package owns /var/lib/nginx/tmp as user `nginx`. This
    // image runs workers as www-data. A Livewire POST that exceeds
    // client_body_buffer_size — the sent-mail view action ships the full HTML
    // in the form — spills to disk. If that directory is not writable the
    // worker returns 500 without ever reaching PHP.
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));
    $entrypoint = (string) file_get_contents(base_path('docker/entrypoint.sh'));
    $nginx = (string) file_get_contents(base_path('docker/nginx/nginx.conf'));

    expect($nginx)->toContain('user  www-data;')
        ->and($nginx)->toContain('client_body_temp_path /var/lib/nginx/tmp/client_body;')
        ->and($dockerfile)->toContain('/var/lib/nginx/tmp')
        ->and($dockerfile)->toContain('chown -R www-data:www-data /var/lib/nginx')
        ->and($entrypoint)->toContain('/var/lib/nginx/tmp')
        ->and($entrypoint)->toContain('chown -R www-data:www-data /var/lib/nginx');
});

it('sends CORS on webfonts so a srcdoc email preview can load Sora', function () {
    // Sent-mail HTML is rendered in a sandboxed srcdoc iframe (origin null).
    // @font-face against the site origin is a cross-origin fetch and the
    // browser drops the file unless Access-Control-Allow-Origin is present.
    foreach ([
        'deploy/nginx/playndgain.com.conf',
        'docker/nginx/templates/10-site.conf.template',
    ] as $path) {
        expect((string) file_get_contents(base_path($path)))
            ->toContain('Access-Control-Allow-Origin "*"');
    }
});

it('rolls app start-first for Dokploy Stack deploys', function () {
    $compose = (string) file_get_contents(base_path('docker-compose.yml'));
    $script = (string) file_get_contents(base_path('docker/dokploy-stack-deploy.sh'));

    expect($compose)->toContain('order: start-first')
        ->and($compose)->toContain('replicas: 1')
        ->and($compose)->toContain('failure_action: rollback')
        ->and(substr_count($compose, 'order: stop-first'))->toBe(3)
        ->and($compose)->toContain('disable: true')
        ->and($compose)->toContain('/proc/1/cmdline')
        ->and($compose)->toContain('php artisan horizon')
        ->and($compose)->not->toContain('horizon:status')
        ->and($compose)->toContain('playndgain-storage')
        ->and($script)->toContain('--detach=true')
        ->and($script)->toContain('--rollback-order stop-first')
        ->and($script)->toContain('docker service rollback')
        ->and($script)->toContain('com.docker.compose.project')
        ->and($script)->toContain('not stacking a second update')
        ->and($script)->not->toContain('--detach=false')
        ->and(is_file(base_path('docker/migrate-locked.php')))->toBeTrue()
        ->and((string) file_get_contents(base_path('Dockerfile')))
        ->toContain('migrate-locked.php')
        ->and((string) file_get_contents(base_path('Dockerfile')))
        ->toContain('mariadb-connector-c')
        ->and((string) file_get_contents(base_path('Dockerfile')))
        ->not->toContain('HEALTHCHECK');
});

it('skips tls on mysqldump so the alpine client can reach docker mysql', function () {
    $dumper = DbDumperFactory::createFromConnection('mysql');

    expect(config('database.connections.mysql.dump.skip_ssl'))->toBeTrue()
        ->and(config('database.connections.mysql.dump.ssl_flag'))->toBe('skip-ssl')
        ->and($dumper->getContentsOfCredentialsFile())->toContain('skip-ssl');
});

it('creates local-disk directories the scheduler can enter', function () {
    $path = 'filament-email-log/_perm-check/note.txt';

    Storage::disk('local')->put($path, 'ok');

    $dir = storage_path('app/private/filament-email-log/_perm-check');

    expect(is_dir($dir))->toBeTrue()
        ->and(fileperms($dir) & 0777)->toBe(0750);

    Storage::disk('local')->deleteDirectory('filament-email-log/_perm-check');
});

it('lets the nightly backup walk storage even when one email-log dir is unreadable', function () {
    // Filament-email writes a 0700 directory per message under
    // storage/app/private/filament-email-log. One of those that www-data
    // cannot enter used to abort backup:run entirely. The log itself is
    // already in MySQL (html_body / text_body); the on-disk copy is a
    // 90-day .eml cache, same class of regenerable blob as GeoIP.
    $exclude = config('backup.backup.source.files.exclude');

    expect(config('backup.backup.source.files.ignore_unreadable_directories'))->toBeTrue()
        ->and($exclude)->toContain(storage_path('app/private/filament-email-log'))
        ->and(config('filesystems.disks.local.permissions.dir.private'))->toBe(0750);

    $entrypoint = (string) file_get_contents(base_path('docker/entrypoint.sh'));

    expect($entrypoint)->toContain('chown -R www-data:www-data storage bootstrap/cache')
        ->and($entrypoint)->toContain('chmod -R ug+rwX storage/app/private');
});

it('ships the S3 flysystem adapter so BACKUP_DISK=s3 can resolve', function () {
    // Laravel lists this as optional. Production Spatie backups use the s3
    // disk against R2, and FilesystemManager dies without the class.
    expect(class_exists(PortableVisibilityConverter::class))->toBeTrue()
        ->and((string) file_get_contents(base_path('composer.json')))
        ->toContain('"league/flysystem-aws-s3-v3"');
});

it('keeps APP_DEBUG off in production', function () {
    // Asserted against the wiring rather than the running environment: this
    // suite runs with APP_ENV=testing, so `config('app.debug')` here says
    // nothing about the production box.
    //
    // Two things make the row true. First, an omitted APP_DEBUG is false, so a
    // production .env that never mentions it is safe.
    expect(file_get_contents(config_path('app.php')))
        ->toContain("env('APP_DEBUG', false)");

    // Second, an explicit APP_DEBUG=true alongside APP_ENV=production stops the
    // deploy. Nothing inside the application can enforce that — config('app.debug')
    // is whatever .env says — so the deploy script is the enforcement point.
    $deploy = deployFile('deploy.sh');

    expect($deploy)->toContain('APP_DEBUG')
        ->and($deploy)->toContain('REFUSING TO DEPLOY');
});

it('requires admin 2FA by default', function () {
    expect(config('playndgain.require_admin_2fa'))->toBeTrue();

    // phpunit.xml pins the variable to true, so the line above is a check on
    // the pin. The default is what a production box gets when nobody sets it.
    expect(file_get_contents(config_path('playndgain.php')))
        ->toContain("env('PLAYNDGAIN_REQUIRE_ADMIN_2FA', true)");
});

it('issues UUIDv4 click ids', function () {
    [$app, $network] = wallFixture();
    wallGeo('US');
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    wallClick($app, $offer, 'USER_1');
    wallClick($app, $offer, 'USER_2');

    $ids = OfferClick::query()->pluck('click_id')->all();

    expect($ids)->toHaveCount(2);

    foreach ($ids as $id) {
        // The `4` is the assertion. A time-ordered UUID (v1, v6, v7) or
        // Str::orderedUuid() would satisfy "is a uuid" and fail here — and it
        // is exactly what an innocent-looking swap for index locality would
        // introduce. docs/00 §5.3: never sequential, never guessable.
        expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
    }

    expect($ids[0])->not->toBe($ids[1]);
});

it('keeps storage and bootstrap/cache outside the web root', function () {
    $public = (string) realpath(public_path());

    expect($public)->not->toBe('');

    foreach ([storage_path(), base_path('bootstrap/cache')] as $path) {
        expect(str_starts_with((string) realpath($path), $public.DIRECTORY_SEPARATOR))->toBeFalse();
    }

    // And the document root the servers are given is public/, not the project
    // root — the other way this row goes wrong.
    foreach (['nginx/playndgain.com.conf', 'nginx/wall.playndgain.com.conf', 'nginx/api.playndgain.com.conf'] as $path) {
        expect(deployFile($path))->toContain('root /var/www/playndgain/public;');
    }
});

/**
 * The full-page cache is on the landing route and must stay there.
 *
 * docs/11 §2 scopes it that way for performance reasons, but the reason it
 * matters here is disclosure: the wall's HTML is per end user — their offer
 * list, their reward balance, their consent state — and a response cache in
 * front of it would serve one user's page to the next. The wall already sends
 * `private, no-store`, which stops a proxy; this stops us.
 */
it('caches whole responses on the landing route and nowhere else', function () {
    $cached = fn (string $name): bool => collect(Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [])
        ->contains(fn (mixed $entry): bool => is_string($entry) && str_contains(strtolower($entry), 'cacheresponse'));

    expect($cached('landing'))->toBeTrue();

    foreach (['wall', 'wall.offer', 'wall.click', 'wall.icon', 'privacy.data', 'postback', 'contact'] as $name) {
        expect($cached($name))->toBeFalse("{$name} must never be response-cached");
    }
});

it('leaves the response cache off in tests', function () {
    // Not a preference. A cached landing page would hand one test's HTML to the
    // next test's assertions, and the failure would look like a content bug.
    //
    // Falsy rather than `false`: phpunit.xml delivers the value as a string and
    // the package reads it with a plain env() call, so what lands in config is
    // an empty string. The middleware's own check is the same boolean cast.
    expect(config('responsecache.enabled'))->toBeFalsy();
});

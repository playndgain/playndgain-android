<?php

use App\Models\Publisher;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Opcodes\LogViewer\Facades\LogViewer;

it('sends a guest to the admin login instead of a bare 403', function () {
    $this->get('/log-viewer')->assertRedirect('/admin/login');
});

it('refuses a publisher the log viewer', function () {
    $this->actingAs(Publisher::factory()->create()->user)
        ->get('/log-viewer')
        ->assertForbidden();
});

it('lets an admin who signed in through the panel open the log viewer', function () {
    config()->set('playndgain.require_admin_2fa', false);
    Filament\Facades\Filament::setCurrentPanel('admin');

    $admin = User::factory()->admin()->create();

    Livewire\Livewire::test(Login::class)
        ->fillForm([
            'email' => $admin->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->get('/log-viewer')->assertOk();
});

it('refuses a guest the log viewer api', function () {
    $this->getJson(route('log-viewer.files'))->assertUnauthorized();
});

it('lets a bearer token read the log viewer api', function () {
    config()->set('playndgain.log_viewer_api_token', 'secret-token');
    config()->set('log-viewer.include_files', ['log-viewer-api-test.log']);

    $logFile = storage_path('logs/log-viewer-api-test.log');

    file_put_contents($logFile, <<<'LOG'
[2026-09-10 12:00:00] testing.INFO: Wall rendered for user 1
[2026-09-10 12:00:01] testing.ERROR: Payment gateway timeout {"offer_id":42}
LOG);

    LogViewer::clearFileCache();

    try {
        $files = $this->withToken('secret-token')
            ->getJson(route('log-viewer.files'))
            ->assertOk()
            ->json();

        expect(collect($files)->pluck('name'))->toContain('log-viewer-api-test.log');

        $identifier = collect($files)->firstWhere('name', 'log-viewer-api-test.log')['identifier'];

        $this->withToken('secret-token')
            ->getJson(route('log-viewer.logs', [
                'file' => $identifier,
                'query' => 'Payment gateway',
            ]))
            ->assertOk()
            ->assertJsonPath('logs.0.message', 'Payment gateway timeout');

        $this->withToken('secret-token')
            ->deleteJson(route('log-viewer.files.delete', $identifier))
            ->assertForbidden();
    } finally {
        if (is_file($logFile)) {
            unlink($logFile);
        }
    }
});

it('refuses the log viewer api with a wrong bearer token', function () {
    config()->set('playndgain.log_viewer_api_token', 'secret-token');

    $this->withToken('wrong-token')
        ->getJson(route('log-viewer.files'))
        ->assertUnauthorized();
});

<?php

use App\Mail\Transport\HostingerTransport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

uses(TestCase::class);

it('posts the message to the Hostinger Mail API send endpoint', function () {
    $captured = null;

    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured = [
            'method' => $method,
            'url' => $url,
            'headers' => $options['headers'] ?? [],
            'body' => json_decode($options['body'], true),
        ];

        return new MockResponse('', ['http_code' => 200]);
    });

    $transport = new HostingerTransport(
        'test-token',
        'AC123mailbox',
        'https://api.mail.hostinger.com',
        $client,
    );

    $email = (new Email)
        ->from(new Address('no-reply@playndgain.com', 'Playndgain'))
        ->to('user@example.com')
        ->cc('cc@example.com')
        ->subject('Hello')
        ->html('<p>Hi</p>')
        ->text('Hi');

    $envelope = Envelope::create($email);

    $transport->send($email, $envelope);

    expect($captured)->not->toBeNull()
        ->and($captured['method'])->toBe('POST')
        ->and($captured['url'])->toBe('https://api.mail.hostinger.com/api/v1/mailboxes/AC123mailbox/send')
        ->and($captured['body'])->toMatchArray([
            'to' => ['user@example.com'],
            'cc' => ['cc@example.com'],
            'subject' => 'Hello',
            'html' => '<p>Hi</p>',
            'text' => 'Hi',
            'displayName' => 'Playndgain',
        ]);

    $auth = collect($captured['headers'])
        ->first(fn (string $header): bool => str_starts_with(strtolower($header), 'authorization:'));

    expect($auth)->toContain('Bearer test-token');
});

it('throws when the Hostinger Mail API rejects the message', function () {
    $client = new MockHttpClient([
        new MockResponse(json_encode(['error' => 'mailbox not found', 'code' => 'ERR_MAILBOX_NOT_FOUND']), [
            'http_code' => 404,
        ]),
    ]);

    $transport = new HostingerTransport('token', 'ACmissing', client: $client);

    $email = (new Email)
        ->from('no-reply@playndgain.com')
        ->to('user@example.com')
        ->subject('Hello')
        ->text('Hi');

    $transport->send($email);
})->throws(TransportException::class, 'Hostinger Mail API rejected the message (404)');

it('registers the hostinger mailer driver', function () {
    config([
        'mail.default' => 'hostinger',
        'mail.mailers.hostinger' => ['transport' => 'hostinger'],
        'services.hostinger.token' => 'test-token',
        'services.hostinger.mailbox_id' => 'AC123',
        'services.hostinger.base_url' => 'https://api.mail.hostinger.com',
    ]);

    $mailer = app('mail.manager')->mailer('hostinger');

    expect((string) $mailer->getSymfonyTransport())->toBe('hostinger');
});

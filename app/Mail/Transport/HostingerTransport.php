<?php

namespace App\Mail\Transport;

use Exception;
use SensitiveParameter;
use Stringable;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends through Hostinger Mail API:
 * POST /api/v1/mailboxes/{mailboxResourceId}/send
 *
 * @see https://api.mail.hostinger.com/
 */
class HostingerTransport extends AbstractTransport implements Stringable
{
    protected HttpClientInterface $client;

    public function __construct(
        #[SensitiveParameter] protected string $token,
        protected string $mailboxId,
        protected string $baseUrl = 'https://api.mail.hostinger.com',
        ?HttpClientInterface $client = null,
    ) {
        parent::__construct();

        $this->client = $client ?? HttpClient::create();
    }

    /**
     * {@inheritDoc}
     *
     * @throws TransportException
     */
    protected function doSend(SentMessage $message): void
    {
        $url = rtrim($this->baseUrl, '/').'/api/v1/mailboxes/'.rawurlencode($this->mailboxId).'/send';

        try {
            $response = $this->client->request('POST', $url, [
                'auth_bearer' => $this->token,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => $this->payload($message),
            ]);

            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (Exception $exception) {
            throw new TransportException(
                sprintf('Request to Hostinger Mail API failed. Reason: %s.', $exception->getMessage()),
                is_int($exception->getCode()) ? $exception->getCode() : 0,
                $exception,
            );
        }

        if ($status < 200 || $status >= 300) {
            $decoded = json_decode($body, true);
            $reason = is_array($decoded)
                ? ($decoded['error'] ?? $decoded['message'] ?? $body)
                : $body;

            throw new TransportException(
                sprintf('Hostinger Mail API rejected the message (%d): %s', $status, is_string($reason) ? $reason : json_encode($reason)),
                $status,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(SentMessage $message): array
    {
        $original = $message->getOriginalMessage();

        if (! $original instanceof Message) {
            throw new TransportException('Hostinger Mail API transport requires a MIME Message instance.');
        }

        $email = MessageConverter::toEmail($original);
        $envelope = $message->getEnvelope();
        $sender = $envelope->getSender();

        $payload = [
            'to' => $this->addresses($this->recipients($email, $envelope)),
            'cc' => $this->addresses($email->getCc()),
            'bcc' => $this->addresses($email->getBcc()),
            'subject' => $email->getSubject(),
            'html' => $email->getHtmlBody(),
            'text' => $email->getTextBody(),
            'attachments' => $this->attachments($email),
        ];

        if ($sender->getName() !== '') {
            $payload['displayName'] = $sender->getName();
        }

        return array_filter(
            $payload,
            fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '',
        );
    }

    /**
     * @return list<Address>
     */
    protected function recipients(Email $email, Envelope $envelope): array
    {
        return array_values(array_filter(
            $envelope->getRecipients(),
            fn (Address $address): bool => ! in_array($address, array_merge($email->getCc(), $email->getBcc()), true),
        ));
    }

    /**
     * @param  list<Address>  $addresses
     * @return list<string>
     */
    protected function addresses(array $addresses): array
    {
        return array_map(
            fn (Address $address): string => $address->getAddress(),
            $addresses,
        );
    }

    /**
     * @return list<array{filename: string, content: string, encoding: string, contentType?: string, cid?: string}>
     */
    protected function attachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename') ?: 'attachment';
            $contentType = $headers->get('Content-Type')?->getBody();
            $disposition = $headers->getHeaderBody('Content-Disposition') ?: 'attachment';

            $item = [
                'filename' => $filename,
                'content' => base64_encode($attachment->getBody()),
                'encoding' => 'base64',
            ];

            if (is_string($contentType) && $contentType !== '') {
                $item['contentType'] = $contentType;
            }

            if ($disposition === 'inline') {
                $item['cid'] = $attachment->hasContentId() ? $attachment->getContentId() : $filename;
            }

            $attachments[] = $item;
        }

        return $attachments;
    }

    public function __toString(): string
    {
        return 'hostinger';
    }
}

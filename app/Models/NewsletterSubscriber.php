<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * An address on the mailing list.
 *
 * The row carries its own consent record — when it was taken (`created_at`),
 * from which page (`source`) and from roughly where (`ip`, masked) — and its
 * own way off (`unsubscribed_at`). A row is never deleted on unsubscribe: the
 * date is what proves the address asked to be dropped, and a deleted row would
 * silently accept the same address again on the next release note.
 *
 * @property string $email
 * @property string|null $source
 * @property Carbon|null $unsubscribed_at
 * @property string|null $ip
 */
class NewsletterSubscriber extends Model
{
    /** @var list<string> */
    protected $fillable = ['email', 'source', 'unsubscribed_at', 'ip'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['unsubscribed_at' => 'datetime'];
    }

    public function isSubscribed(): bool
    {
        return $this->unsubscribed_at === null;
    }

    /**
     * The link that goes in the footer of anything sent to this list.
     *
     * Signed rather than a stored token: the signature is the credential, so
     * there is no column to leak and no lookup that turns a guessed id into an
     * opt-out for somebody else's address. No expiry — a person reading a
     * release note from six months ago is exactly who needs this to work.
     */
    public function unsubscribeUrl(): string
    {
        return URL::signedRoute('newsletter.unsubscribe', ['subscriber' => $this->getKey()]);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSubscribed(Builder $query): Builder
    {
        return $query->whereNull('unsubscribed_at');
    }
}

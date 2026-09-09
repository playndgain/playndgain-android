---
title: Verifying the signature
description: Complete, runnable callback handlers in PHP, Node.js, Python, Go and Ruby.
---

Every postback carries a signature over the transaction, the payout and your app secret:

```txt
signature = sha256( trans_id + payout_usd + app_secret )
```

Plain concatenation, no separators, lower-case hex.

Until you check it, your callback URL is an open endpoint that credits any player any amount to anyone who guesses it.

## Three rules

**Hash the string exactly as it arrived.** `payout_usd` has six decimal places and may carry a minus sign. Parse it to a float, round it, or reformat it before hashing, and the signature will never match. Read the raw query value, hash that, and convert to a number afterwards.

**Compare in constant time.** `hash_equals`, `timingSafeEqual`, `hmac.compare_digest`, `subtle.ConstantTimeCompare`, `OpenSSL.secure_compare`. Not `==`.

**Reject before you do anything else.** No database write, no logging of the amount, no partial credit. A `403` and nothing else.

## Complete handlers

Each of these runs as written. The only thing you supply is `PLAYNDGAIN_SECRET` in the environment.

They read the macro names as parameter keys: `payout_usd`, `currency_amount`, `signature`. If your callback URL uses shorter keys, as the [quick start](/docs/quick-start) does, change the lookups to match your own URL.

<div class="doc-tabs" x-data="{ lang: 'php' }"><div class="doc-tabs-bar" role="tablist" aria-label="Language" x-cloak><button type="button" role="tab" @click="lang='php'" :aria-selected="lang==='php'" :class="{ 'is-on': lang==='php' }">PHP</button><button type="button" role="tab" @click="lang='node'" :aria-selected="lang==='node'" :class="{ 'is-on': lang==='node' }">Node.js</button><button type="button" role="tab" @click="lang='python'" :aria-selected="lang==='python'" :class="{ 'is-on': lang==='python' }">Python</button><button type="button" role="tab" @click="lang='go'" :aria-selected="lang==='go'" :class="{ 'is-on': lang==='go' }">Go</button><button type="button" role="tab" @click="lang='ruby'" :aria-selected="lang==='ruby'" :class="{ 'is-on': lang==='ruby' }">Ruby</button></div>

<div class="doc-tab" x-show="lang==='php'">

```php
<?php
// Complete PLAYNDGAIN callback handler.
// Run with:  PLAYNDGAIN_SECRET=… php -S localhost:8080 callback.php

$secret = getenv('PLAYNDGAIN_SECRET');

$db = new PDO('sqlite:playndgain.db', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// (trans_id, status), never trans_id alone: a reversal carries the SAME
// trans_id as the credit it undoes, and a key on trans_id alone would
// dedupe the reversal away and silently keep the coins.
$db->exec('CREATE TABLE IF NOT EXISTS tx (
    trans_id TEXT, status TEXT, amount REAL, PRIMARY KEY (trans_id, status)
)');

$transId = $_GET['trans_id'] ?? '';
$status  = $_GET['status'] ?? '';
$payout  = $_GET['payout_usd'] ?? '';   // the string as sent, never round it

$expected = hash('sha256', $transId . $payout . $secret);

if (! hash_equals($expected, $_GET['signature'] ?? '')) {
    http_response_code(403);
    exit('invalid signature');
}

$amount = (float) ($_GET['currency_amount'] ?? 0);

try {
    // The unique index is the dedupe. Checking with a SELECT first loses the
    // race when two retries land on two workers in the same millisecond.
    $db->prepare('INSERT INTO tx VALUES (?, ?, ?)')->execute([$transId, $status, $amount]);
} catch (PDOException) {
    exit('ok');   // already processed. Still a 2xx, or we retry it
}

if ($amount < 0) {
    debit_user($_GET['user_id'], abs($amount));    // reversal: take it back
} else {
    credit_user($_GET['user_id'], $amount);
}

echo 'ok';   // we need a 2xx

function credit_user(string $userId, float $amount): void
{
    printf("credit %s %.2f\n", $userId, $amount);
}

function debit_user(string $userId, float $amount): void
{
    printf("debit  %s %.2f\n", $userId, $amount);
}
```

</div>

<div class="doc-tab" x-show="lang==='node'" x-cloak>

```js
// Complete PLAYNDGAIN callback handler. Zero dependencies.
// Run with:  PLAYNDGAIN_SECRET=… node callback.mjs

import { createServer } from 'node:http';
import { createHash, timingSafeEqual } from 'node:crypto';

const SECRET = process.env.PLAYNDGAIN_SECRET;

// In production this is a UNIQUE index on (trans_id, status), and you catch
// the constraint violation. A Set is enough to see the shape.
//
// The status is part of the key, never trans_id alone: a reversal carries the
// SAME trans_id as the credit it undoes, and a key on trans_id alone would
// dedupe the reversal away and silently keep the coins.
const seen = new Set();

const safeEqual = (a, b) => {
  const x = Buffer.from(a, 'utf8');
  const y = Buffer.from(b, 'utf8');
  return x.length === y.length && timingSafeEqual(x, y);
};

const creditUser = (userId, amount) => console.log('credit', userId, amount.toFixed(2));
const debitUser  = (userId, amount) => console.log('debit ', userId, amount.toFixed(2));

createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');

  if (url.pathname !== '/playndgain/callback') return void res.writeHead(404).end();

  const q = url.searchParams;
  const transId = q.get('trans_id') ?? '';
  const status  = q.get('status') ?? '';
  const payout  = q.get('payout_usd') ?? '';   // the string as sent, never round it

  const expected = createHash('sha256').update(transId + payout + SECRET).digest('hex');

  if (!safeEqual(expected, q.get('signature') ?? '')) {
    return void res.writeHead(403).end('invalid signature');
  }

  const key = transId + ':' + status;

  if (seen.has(key)) return void res.writeHead(200).end('ok');   // a retry
  seen.add(key);

  const amount = Number(q.get('currency_amount'));

  if (amount < 0) {
    debitUser(q.get('user_id'), Math.abs(amount));   // reversal: take it back
  } else {
    creditUser(q.get('user_id'), amount);
  }

  res.writeHead(200).end('ok');   // we need a 2xx
}).listen(8080);
```

</div>

<div class="doc-tab" x-show="lang==='python'" x-cloak>

```python
# Complete PLAYNDGAIN callback handler. Standard library only.
# Run with:  PLAYNDGAIN_SECRET=… python callback.py

import hashlib
import hmac
import os
import sqlite3
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlparse

SECRET = os.environ["PLAYNDGAIN_SECRET"]

db = sqlite3.connect("playndgain.db", check_same_thread=False)

# (trans_id, status), never trans_id alone: a reversal carries the SAME
# trans_id as the credit it undoes, and a key on trans_id alone would dedupe
# the reversal away and silently keep the coins.
db.execute(
    "CREATE TABLE IF NOT EXISTS tx "
    "(trans_id TEXT, status TEXT, amount REAL, PRIMARY KEY (trans_id, status))"
)


def credit_user(user_id, amount):
    print(f"credit {user_id} {amount:.2f}")


def debit_user(user_id, amount):
    print(f"debit  {user_id} {amount:.2f}")


class Callback(BaseHTTPRequestHandler):
    def do_GET(self):
        query = {k: v[0] for k, v in parse_qs(urlparse(self.path).query).items()}

        trans_id = query.get("trans_id", "")
        status = query.get("status", "")
        payout = query.get("payout_usd", "")  # the string as sent, never round it

        expected = hashlib.sha256((trans_id + payout + SECRET).encode()).hexdigest()

        if not hmac.compare_digest(expected, query.get("signature", "")):
            return self.reply(403, "invalid signature")

        amount = float(query.get("currency_amount", "0"))

        try:
            # The unique index is the dedupe. A SELECT first loses the race.
            db.execute("INSERT INTO tx VALUES (?, ?, ?)", (trans_id, status, amount))
            db.commit()
        except sqlite3.IntegrityError:
            return self.reply(200, "ok")  # already processed

        if amount < 0:
            debit_user(query.get("user_id"), abs(amount))  # reversal
        else:
            credit_user(query.get("user_id"), amount)

        self.reply(200, "ok")  # we need a 2xx

    def reply(self, code, body):
        self.send_response(code)
        self.send_header("Content-Type", "text/plain")
        self.end_headers()
        self.wfile.write(body.encode())


HTTPServer(("", 8080), Callback).serve_forever()
```

</div>

<div class="doc-tab" x-show="lang==='go'" x-cloak>

```go
// Complete PLAYNDGAIN callback handler. Standard library only.
// Run with:  PLAYNDGAIN_SECRET=… go run callback.go

package main

import (
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"log"
	"net/http"
	"os"
	"strconv"
	"sync"
)

var (
	secret = os.Getenv("PLAYNDGAIN_SECRET")

	// In production this is a UNIQUE index on (trans_id, status), and you
	// check the constraint error. A map is enough to see the shape.
	//
	// The status is part of the key, never trans_id alone: a reversal carries
	// the SAME trans_id as the credit it undoes, and a key on trans_id alone
	// would dedupe the reversal away and silently keep the coins.
	mu   sync.Mutex
	seen = map[string]bool{}
)

func creditUser(userID string, amount float64) { log.Printf("credit %s %.2f", userID, amount) }
func debitUser(userID string, amount float64)  { log.Printf("debit  %s %.2f", userID, amount) }

func callback(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	transID := q.Get("trans_id")
	payout := q.Get("payout_usd") // the string as sent, never round it

	sum := sha256.Sum256([]byte(transID + payout + secret))
	expected := hex.EncodeToString(sum[:])

	if subtle.ConstantTimeCompare([]byte(expected), []byte(q.Get("signature"))) != 1 {
		http.Error(w, "invalid signature", http.StatusForbidden)
		return
	}

	key := transID + ":" + q.Get("status")

	mu.Lock()
	duplicate := seen[key]
	seen[key] = true
	mu.Unlock()

	if duplicate {
		w.Write([]byte("ok")) // a retry of one we already handled
		return
	}

	amount, err := strconv.ParseFloat(q.Get("currency_amount"), 64)
	if err != nil {
		http.Error(w, "bad amount", http.StatusBadRequest)
		return
	}

	if amount < 0 {
		debitUser(q.Get("user_id"), -amount) // reversal: take it back
	} else {
		creditUser(q.Get("user_id"), amount)
	}

	w.Write([]byte("ok")) // we need a 2xx
}

func main() {
	http.HandleFunc("/playndgain/callback", callback)
	log.Fatal(http.ListenAndServe(":8080", nil))
}
```

</div>

<div class="doc-tab" x-show="lang==='ruby'" x-cloak>

```ruby
# Complete PLAYNDGAIN callback handler.
# Run with:  gem install sinatra && PLAYNDGAIN_SECRET=… ruby callback.rb

require "sinatra"
require "digest"
require "openssl"

SECRET = ENV.fetch("PLAYNDGAIN_SECRET")

# In production this is a UNIQUE index on (trans_id, status), and you rescue
# the constraint error. A Hash is enough to see the shape.
#
# The status is part of the key, never trans_id alone: a reversal carries the
# SAME trans_id as the credit it undoes, and a key on trans_id alone would
# dedupe the reversal away and silently keep the coins.
SEEN = {}
LOCK = Mutex.new

def credit_user(user_id, amount)
  puts format("credit %s %.2f", user_id, amount)
end

def debit_user(user_id, amount)
  puts format("debit  %s %.2f", user_id, amount)
end

get "/playndgain/callback" do
  trans_id = params["trans_id"].to_s
  payout   = params["payout_usd"].to_s   # the string as sent, never round it

  expected = Digest::SHA256.hexdigest(trans_id + payout + SECRET)

  unless OpenSSL.secure_compare(expected, params["signature"].to_s)
    halt 403, "invalid signature"
  end

  key = "#{trans_id}:#{params['status']}"

  duplicate = LOCK.synchronize do
    SEEN.key?(key).tap { SEEN[key] = true }
  end

  halt 200, "ok" if duplicate   # a retry of one we already handled

  amount = params["currency_amount"].to_f

  if amount.negative?
    debit_user(params["user_id"], amount.abs)   # reversal: take it back
  else
    credit_user(params["user_id"], amount)
  end

  "ok"   # we need a 2xx
end
```

</div>

</div>

## Test your handler by hand

Compute a signature for a fake transaction and call your own endpoint:

```bash
SECRET='your-app-secret'
TRANS='OFFER19624243-Gw2CpY'

fire () {   # fire <payout_usd> <currency_amount> <status>
  SIG=$(printf '%s' "$TRANS$1$SECRET" | sha256sum | cut -d' ' -f1)
  curl -i "http://localhost:8080/playndgain/callback\
?user_id=USER_123&trans_id=$TRANS&payout_usd=$1&currency_amount=$2&status=$3&signature=$SIG"
}

fire 0.091000 91.00 credited     # credits 91
fire 0.091000 91.00 credited     # a retry. Must credit nothing, must return 200
fire -0.091000 -91.00 reversed   # the reversal. Must DEBIT 91
```

Three calls test everything that matters: the signature, the [dedupe](/docs/retries-and-failure), and the [reversal branch](/docs/chargebacks-reversals).

The third call reuses the **same** `trans_id`, because that is what a real reversal does. If your dedupe key is `trans_id` alone, that call is swallowed as a duplicate and your player keeps coins you were charged back for. Key on `(trans_id, status)`.

Then change one character of the signature and confirm you get a `403` and no credit. That is the fourth call, and then you are finished.

## When it always fails

Almost always one of two things: the payout string was reformatted before hashing, or the secret in your environment belongs to a different app. [Troubleshooting](/docs/troubleshooting) has the four causes in the order they actually occur, and a two-line debug print that identifies which one you have.

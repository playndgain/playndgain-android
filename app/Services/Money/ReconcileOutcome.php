<?php

namespace App\Services\Money;

/**
 * One cell of the reconciliation matrix — docs/04 §3.
 *
 * The matrix is sixteen combinations of "what we decided" against "what the
 * network now says", and every one of them has to be spelled out: the cells
 * that look alike are the ones that differ in whether money moves. Naming the
 * outcomes separates *choosing* a cell, which is pure and testable against the
 * documented table, from *applying* it, which happens under a row lock and
 * writes to the ledger.
 */
enum ReconcileOutcome
{
    /** Nothing changes but the upstream audit fields. */
    case Duplicate;

    /** Approve and pay: ledger earning, Credit postback. */
    case Credit;

    /** A flag rule fired — the row stays pending for the review queue. */
    case HoldPending;

    /** A block rule fired — rejected with the rule key. */
    case RejectFraud;

    /** The network declined before we ever credited anyone. No chargeback. */
    case RejectNetwork;

    /** Money back: chargeback and a Reversal postback. */
    case Reverse;

    /**
     * The network rejected something it had already paid for, on a connector
     * that does not mean "take the money back" by it. A human decides.
     */
    case AlertRejectedAfterCredit;

    /** Our own rules refused this conversion. An upstream approval does not overturn that. */
    case RefuseInternalRejection;
}

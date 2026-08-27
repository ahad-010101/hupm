<?php

namespace App\Domain\Notifications;

/**
 * The six things an administrator must be told about.  [TDD §10, WP-31/34]
 *
 * Every one of these is a **silent** failure. Nothing errors, no page breaks,
 * no resident complains — until a month later when the numbers do not add up.
 * On shared hosting there is no daemon to crash and no supervisor to notice, so
 * an email is the only mechanism that reaches somebody who is not looking.
 *
 * A separate enum from {@see NotificationTemplate}, which is the twelve
 * resident-facing triggers named in FR-NTF-01. These travel the other way, to a
 * different audience, on a different trigger, and folding them in would make
 * that enum's own description untrue.
 *
 * **Each alert carries its own cooldown.** An alert that repeats every hour is
 * an alert that gets filtered to a folder nobody opens, which is functionally
 * the same as never sending it. The admin console shows every condition
 * continuously anyway — the email is the nudge, not the record.
 *
 * The sixth was added by the WP-34 security review. The first five are money
 * going quietly wrong; this one is somebody probing an endpoint, which is a
 * different kind of silence but the same problem — nothing breaks, so nobody
 * looks.
 */
enum SystemAlert: string
{
    case ReconciliationStale = 'reconciliation_stale';
    case BackupFailed = 'backup_failed';
    case FailedJobs = 'failed_jobs';
    case HighReturnRate = 'high_return_rate';
    case UnmatchedPayment = 'unmatched_payment';
    case WebhookRejected = 'webhook_rejected';

    public function subject(): string
    {
        return match ($this) {
            // Named for what it means rather than what broke. Somebody reading
            // this on a phone at the weekend needs the consequence first.
            self::ReconciliationStale => 'Action needed: payments are not being reconciled',
            self::BackupFailed => 'Action needed: the nightly backup failed',
            self::FailedJobs => 'Action needed: several background jobs are failing',
            self::HighReturnRate => 'Action needed: an unusual number of payments were returned',
            self::UnmatchedPayment => 'Action needed: a payment has gone unmatched',
            // Not "attack" and not "action needed": the likeliest cause by far
            // is a rotated signature key, and a subject line that cries breach
            // for a configuration slip is a subject line that stops being read.
            self::WebhookRejected => 'Check: payment webhooks are being rejected',
        };
    }

    /** One sentence on what has actually gone wrong. */
    public function summary(): string
    {
        return match ($this) {
            self::ReconciliationStale => 'The job that reads settled payments from the bank has not '
                .'succeeded recently. Balances have stopped tracking reality — residents may be '
                .'chased for rent they have already paid.',
            self::BackupFailed => 'The nightly database backup did not complete. Until it does, a '
                .'failure would cost everything since the last good copy.',
            self::FailedJobs => 'Background work is failing repeatedly. Emails, charges and '
                .'reconciliation all run through the same queue.',
            self::HighReturnRate => 'More payments than usual were returned by the bank in one day. '
                .'That is normally a batch problem rather than several residents at once.',
            self::UnmatchedPayment => 'A payment the gateway knows about has not been matched to an '
                .'account. It is never voided automatically — somebody has to look at it.',
            self::WebhookRejected => 'Several webhook requests failed their signature check. Either '
                .'the signature key here no longer matches the one in the gateway — in which case '
                .'settlement notices are being silently dropped — or somebody is posting to the '
                .'endpoint. Reconciliation is authoritative either way, so no money is at risk.',
        };
    }

    /** What the person reading this should actually do. */
    public function action(): string
    {
        return match ($this) {
            self::ReconciliationStale => 'Open Payments in the admin console and use Reconcile now. '
                .'If it still does not run, the cron entry is the usual cause — check it uses the '
                .'full path to PHP rather than bare `php`.',
            self::BackupFailed => 'Check free disk space first, then the backup log. Do not wait for '
                .'the next night: run it by hand once the cause is clear.',
            self::FailedJobs => 'Open the failed jobs list. If they share one cause, fix it and retry '
                .'them together rather than one at a time.',
            self::HighReturnRate => 'Open Payments and look at the return codes. A run of R01s is '
                .'residents; a run of anything else is usually us or the bank.',
            self::UnmatchedPayment => 'Open Payments, filter to unmatched, and match it to the account '
                .'by hand. Do not void it — the money may well have moved.',
            self::WebhookRejected => 'Compare the signature key in the Authorize.Net dashboard with '
                .'the one in the environment file. If they match, the requests are not ours — the '
                .'audit log has the addresses, and nothing further is needed, because an unsigned '
                .'request never reached anything.',
        };
    }

    /**
     * How long before this may be sent again.
     *
     * Chosen per alert rather than one global number, because the cost of
     * repeating differs. Reconciliation being stale is one situation that lasts
     * until somebody acts, so daily is plenty. A returned-payment spike is a
     * fresh fact each day. An unmatched payment is a single item, and a second
     * one deserves its own email.
     */
    public function cooldownHours(): int
    {
        return match ($this) {
            self::ReconciliationStale, self::BackupFailed, self::FailedJobs => 24,
            self::HighReturnRate => 24,
            self::UnmatchedPayment => 24,
            // Six hours, not twenty-four. A mismatched key drops every
            // settlement notice until somebody fixes it, and unlike the others
            // this condition can start and stop several times in a day.
            self::WebhookRejected => 6,
        };
    }
}

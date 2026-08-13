<?php

use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplate;
use App\Jobs\SendNotification;
use App\Mail\TemplatedMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Notification layer  [WP-03, FR-NTF-01]
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->notifications = app(NotificationService::class);
});

it('AC-NTF-01 logs every dispatch with a status and a timestamp', function () {
    Queue::fake();

    $id = $this->notifications->send(
        NotificationTemplate::RentDue,
        'tenant@example.test',
        ['amount' => '$300.00', 'dueDate' => '1 September 2026', 'url' => 'https://x.test'],
        tenantId: null,
    );

    $row = DB::table('notification_logs')->find($id);

    expect($row->status)->toBe('queued')
        ->and($row->template)->toBe('rent_due')
        ->and($row->recipient)->toBe('tenant@example.test')
        ->and($row->subject)->toBe('Rent due 1 September 2026')
        ->and($row->channel)->toBe('email')
        ->and($row->queued_at)->not->toBeNull()
        ->and($row->created_at)->not->toBeNull();

    Queue::assertPushed(SendNotification::class);
});

it('AC-NTF-01 records the row before dispatch, so a crash still leaves evidence', function () {
    Queue::fake();

    $this->notifications->send(NotificationTemplate::PasswordReset, 'a@b.test', []);

    // The log row exists even though the job has not run. Silence is the
    // failure mode this table exists to eliminate.
    expect(DB::table('notification_logs')->where('status', 'queued')->count())->toBe(1);
});

it('marks the row sent once the job completes', function () {
    Mail::fake();

    $id = $this->notifications->send(NotificationTemplate::PasswordReset, 'a@b.test', [
        'name' => 'A', 'url' => 'https://x.test', 'expiresOn' => '1 September 2026',
    ]);

    // Run the job inline, as the queue worker would.
    (new SendNotification($id, NotificationTemplate::PasswordReset, 'a@b.test', 'Reset your password', [
        'name' => 'A', 'url' => 'https://x.test', 'expiresOn' => '1 September 2026',
    ]))->handle($this->notifications);

    $row = DB::table('notification_logs')->find($id);

    expect($row->status)->toBe('sent')->and($row->sent_at)->not->toBeNull();
    Mail::assertSent(TemplatedMail::class);
});

it('AC-NTF-02 retries three times with backoff before giving up', function () {
    $job = new SendNotification(1, NotificationTemplate::RentDue, 'a@b.test', 'Subject', []);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60]);
});

it('AC-NTF-02 records failed with the error text retained', function () {
    Queue::fake();

    $id = $this->notifications->send(NotificationTemplate::RentDue, 'a@b.test', []);

    $job = new SendNotification($id, NotificationTemplate::RentDue, 'a@b.test', 'Subject', []);
    $job->failed(new RuntimeException('Resend API unreachable'));

    $row = DB::table('notification_logs')->find($id);

    expect($row->status)->toBe('failed')
        ->and($row->error)->toBe('Resend API unreachable');
});

it('AC-NTF-02 stores the message only, never a stack trace', function () {
    Queue::fake();

    $id = $this->notifications->send(NotificationTemplate::RentDue, 'a@b.test', []);
    (new SendNotification($id, NotificationTemplate::RentDue, 'a@b.test', 'S', []))
        ->failed(new RuntimeException('boom'));

    // A trace can carry request data, and nothing resembling bank detail may
    // reach a log (I-5).
    expect(DB::table('notification_logs')->find($id)->error)->not->toContain('#0 ');
});

it('AC-NTF-03 records not_deliverable for a tenant with no email address', function () {
    Queue::fake();

    $id = $this->notifications->send(NotificationTemplate::RentDue, null, [], tenantId: null);

    $row = DB::table('notification_logs')->find($id);

    expect($row->status)->toBe('not_deliverable')
        ->and($row->recipient)->toBeNull()
        ->and($row->error)->toContain('No email address');

    // Never a silent no-op and never an exception — but also never a queued
    // job, because there is nowhere to send it.
    Queue::assertNothingPushed();
});

it('AC-NTF-03 treats an empty string the same as a missing address', function () {
    Queue::fake();

    $id = $this->notifications->send(NotificationTemplate::RentDue, '   ');

    expect(DB::table('notification_logs')->find($id)->status)->toBe('not_deliverable');
});

it('AC-NTF-03 surfaces undeliverable messages to admin', function () {
    Queue::fake();

    $this->notifications->send(NotificationTemplate::RentDue, null);
    $this->notifications->send(NotificationTemplate::RentDue, 'ok@example.test');

    $attention = $this->notifications->needingAttention();

    expect($attention)->toHaveCount(1)
        ->and($attention->first()->status)->toBe('not_deliverable');
});

it('sends to the two seeded tenants who have no email as not_deliverable', function () {
    Queue::fake();
    $this->seed(Database\Seeders\DemoDataSeeder::class);

    // Q-4 is unanswered; the demo portfolio deliberately contains tenants with
    // no address so this path is exercised by real data.
    $tenants = DB::table('tenants')->whereNull('email')->get();
    expect($tenants)->not->toBeEmpty();

    foreach ($tenants as $tenant) {
        $this->notifications->send(NotificationTemplate::RentDue, $tenant->email, [], $tenant->id);
    }

    expect(DB::table('notification_logs')->where('status', 'not_deliverable')->count())
        ->toBe($tenants->count());
});

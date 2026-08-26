<?php

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\LedgerEntry;
use App\Models\SignatureEvent;
use App\Support\AuditLogger;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Immutability  [WP-02 DoD, INVARIANT I-3, AC-LED-01, AC-AUD-02]
|--------------------------------------------------------------------------
|
| D-04 moved immutability from "omit updated_at" to a model guard, because the
| specified tables must still transition a status column. These tests are what
| make that trade sound: the guard has to be strictly stronger than the missing
| column, not merely different.
|
*/

uses(RefreshDatabase::class);

function makeLedgerEntry(array $overrides = []): LedgerEntry
{
    $propertyId = DB::table('properties')->insertGetId([
        'name' => 'P', 'street_address' => '1 St', 'city' => 'Atlanta',
        'state' => 'GA', 'postal_code' => '30301', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $unitId = DB::table('units')->insertGetId([
        'property_id' => $propertyId, 'unit_number' => 'A', 'status' => 'occupied',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $tenantId = DB::table('tenants')->insertGetId([
        'first_name' => 'A', 'last_name' => 'B', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $leaseId = DB::table('leases')->insertGetId([
        'unit_id' => $unitId, 'tenant_id' => $tenantId,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'total_contract_rent' => 900, 'tenant_portion' => 300, 'ha_portion' => 600,
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $id = DB::table('ledger_entries')->insertGetId(array_merge([
        'lease_id' => $leaseId, 'tenant_id' => $tenantId,
        'type' => 'charge', 'category' => 'rent', 'payer' => 'tenant',
        'amount' => '300.00', 'status' => 'posted', 'posted_on' => '2026-09-01',
        'description' => 'Rent', 'created_at' => now(), 'updated_at' => now(),
    ], $overrides));

    return LedgerEntry::findOrFail($id);
}

it('AC-LED-01 refuses to change a posted amount', function () {
    $entry = makeLedgerEntry();

    $entry->amount = Money::fromString('999.00');

    expect(fn () => $entry->save())->toThrow(ImmutableRecordException::class);
});

it('I-3 allows the status transition a payment lifecycle requires', function () {
    // pending → cleared on settlement, cleared → returned on an ACH return.
    // This is the change D-04 exists to permit.
    $entry = makeLedgerEntry(['type' => 'payment', 'amount' => '-300.00', 'status' => 'pending', 'charge_key' => null]);

    $entry->status = 'cleared';
    $entry->save();

    expect($entry->fresh()->status)->toBe('cleared');
});

it('refuses to change anything else, even alongside a legal status change', function () {
    $entry = makeLedgerEntry();

    $entry->status = 'void';
    $entry->description = 'Rewritten history';

    expect(fn () => $entry->save())->toThrow(ImmutableRecordException::class);

    // And nothing was written — the whole save is rejected, not partially applied.
    expect($entry->fresh()->description)->toBe('Rent');
});

it('AC-LED-01 refuses to delete a ledger entry', function () {
    $entry = makeLedgerEntry();

    expect(fn () => $entry->delete())->toThrow(ImmutableRecordException::class);
    expect(LedgerEntry::count())->toBe(1);
});

it('AC-AUD-02 refuses to delete or alter an audit row', function () {
    app(AuditLogger::class)->record('test.action');
    $row = AuditLog::firstOrFail();

    expect(fn () => $row->delete())->toThrow(ImmutableRecordException::class);

    $row->action = 'tampered';
    expect(fn () => $row->save())->toThrow(ImmutableRecordException::class);
});

it('AC-AUD-02 refuses to delete or alter a signature event', function () {
    $tenantId = DB::table('tenants')->insertGetId([
        'first_name' => 'A', 'last_name' => 'B', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $userId = DB::table('users')->insertGetId([
        'tenant_id' => $tenantId, 'name' => 'Signer', 'email' => 'signer@hupm.test',
        'password' => 'x', 'role' => 'tenant', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $documentId = DB::table('documents')->insertGetId([
        'tenant_id' => $tenantId, 'category' => 'current_lease', 'title' => 'Lease',
        'original_filename' => 'lease.pdf', 'stored_path' => 'x/y.pdf',
        'mime_type' => 'application/pdf', 'size_bytes' => 100,
        'sha256' => str_repeat('a', 64), 'version' => 1,
        'is_signed' => false, 'visible_to_tenant' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $requestId = DB::table('signature_requests')->insertGetId([
        'document_id' => $documentId, 'tenant_id' => $tenantId, 'user_id' => $userId,
        'status' => 'signed', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $eventId = DB::table('signature_events')->insertGetId([
        'signature_request_id' => $requestId,
        'event' => 'signed',
        'typed_name' => 'A B',
        'button_label' => 'I agree and sign',
        'document_sha256' => str_repeat('a', 64),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'occurred_at' => now(),
    ]);

    $event = SignatureEvent::findOrFail($eventId);

    // The evidence chain is the entire legal weight behind a first-party
    // signature. A chain that can be edited proves nothing.
    $event->typed_name = 'Someone Else';
    expect(fn () => $event->save())->toThrow(ImmutableRecordException::class);
    expect(fn () => $event->delete())->toThrow(ImmutableRecordException::class);

    expect(SignatureEvent::count())->toBe(1);
});

it('lets a document change until it is signed, then freezes it', function () {
    $tenantId = DB::table('tenants')->insertGetId([
        'first_name' => 'A', 'last_name' => 'B', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $id = DB::table('documents')->insertGetId([
        'tenant_id' => $tenantId, 'category' => 'current_lease', 'title' => 'Lease',
        'original_filename' => 'lease.pdf', 'stored_path' => 'x/y.pdf',
        'mime_type' => 'application/pdf', 'size_bytes' => 100,
        'sha256' => str_repeat('a', 64), 'version' => 1,
        'is_signed' => false, 'visible_to_tenant' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $document = Document::findOrFail($id);

    // Unsigned: metadata may still be corrected.
    $document->title = 'Lease 2026';
    $document->save();
    expect($document->fresh()->title)->toBe('Lease 2026');

    // Signing is itself a permitted write.
    $document->is_signed = true;
    $document->save();

    // After signing, nothing may change: signature_events.document_sha256
    // references these bytes as evidence (BR-26).
    $signed = $document->fresh();
    $signed->title = 'Tampered';

    expect(fn () => $signed->save())->toThrow(ImmutableRecordException::class);
    expect(fn () => $signed->delete())->toThrow(ImmutableRecordException::class);
});

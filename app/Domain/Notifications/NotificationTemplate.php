<?php

namespace App\Domain\Notifications;

/**
 * The twelve transactional triggers named in FR-NTF-01.
 *
 * An enum rather than loose strings so a typo in a template key is a fatal
 * error at the call site, not an email that silently never arrives — and so
 * `notification_logs.template` is queryable against a closed set when someone
 * asks "did we email them about the late fee?".
 *
 * Subjects live here rather than in the Blade files because they are part of
 * the tone rules (UI §8) and benefit from being readable in one list:
 *   - never "failed" to a tenant about a pending ACH payment — "processing"
 *   - delinquency wording is neutral and actionable, never accusatory
 *   - the Housing Authority portion is never named or implied (I-4)
 */
enum NotificationTemplate: string
{
    case WelcomeSetPassword = 'welcome_set_password';
    case PasswordReset = 'password_reset';
    case RentDue = 'rent_due';
    case PaymentReceipt = 'payment_receipt';
    case PaymentReturned = 'payment_returned';
    case LateFeePosted = 'late_fee_posted';
    case ManagementReview = 'management_review';
    case MaintenanceStatusChange = 'maintenance_status_change';
    case NoticeIssued = 'notice_issued';
    case SignatureRequest = 'signature_request';
    case SignatureCompleted = 'signature_completed';
    case WeatherAlert = 'weather_alert';

    /** @param array<string, mixed> $data */
    public function subject(array $data = []): string
    {
        return match ($this) {
            self::WelcomeSetPassword => 'Set up your resident account',
            self::PasswordReset => 'Reset your password',
            // Keys are camelCase throughout, matching the Blade variables —
            // one naming convention for the whole payload.
            self::RentDue => 'Rent due '.($data['dueDate'] ?? 'soon'),
            self::PaymentReceipt => 'We received your payment',
            // Not "Payment failed". An ACH return is a bank event, and the
            // tenant needs to know what to do, not to feel accused.
            self::PaymentReturned => 'Your payment was returned by the bank',
            self::LateFeePosted => 'A late fee has been added to your account',
            self::ManagementReview => 'Please contact us about your account',
            self::MaintenanceStatusChange => 'Update on your maintenance request',
            self::NoticeIssued => $data['subject'] ?? 'A notice from your property manager',
            self::SignatureRequest => 'A document needs your signature',
            self::SignatureCompleted => 'Your signed document',
            self::WeatherAlert => 'Weather alert for your area',
        };
    }

    public function view(): string
    {
        return 'mail.'.$this->value;
    }
}

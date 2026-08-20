<?php

namespace App\Support;

/**
 * The Electronic Records Consent text, versioned.  [FR-SIG-01, BR-25, ESIGN]
 *
 * **The text lives in code, not in the database or a settings row.** Under
 * ESIGN what matters is proving *what a person agreed to*, and a consent record
 * that stores only "agreed, version 1" is worth nothing if version 1 is a row
 * somebody edited afterwards. Here the text is in version control, its hash is
 * stored on every consent record, and the two can be compared years later.
 *
 * Changing the wording means adding a new version, never editing an existing
 * one. `hashFor()` will stop matching old records the moment a character
 * changes, which is the point: that is how tampering shows up.
 */
class ElectronicRecordsConsent
{
    public const CURRENT_VERSION = '1.0';

    /**
     * Every version ever published, kept forever.
     *
     * A consent record from 2026 has to remain verifiable in 2031, and it can
     * only be verified against the exact text that was on screen.
     *
     * @var array<string, string>
     */
    private const TEXTS = [
        '1.0' => <<<'TEXT'
        Consent to use electronic records and signatures

        By continuing, you agree that we may provide you with records relating to your
        tenancy in electronic form, and that you may sign documents electronically.

        What this means
        - Documents such as your lease, notices and agreements may be sent to you and
          signed by you electronically instead of on paper.
        - An electronic signature you make in this way has the same legal effect as a
          signature in ink.
        - We will keep a record of each document you sign, including the date and time,
          your typed name, your computer's internet address, and a unique fingerprint of
          the exact document you saw.

        Your rights
        - You may ask for a paper copy of any document at no charge. Contact the office
          and we will post one to you.
        - You may withdraw this consent at any time by contacting the office. Withdrawing
          it does not affect documents you have already signed.
        - You may update the email address we use for you at any time from your account.

        What you need
        - An email address you can access, and a device that can open PDF files.

        If you do not agree, contact the office and we will make arrangements on paper.
        TEXT,
    ];

    public function version(): string
    {
        return self::CURRENT_VERSION;
    }

    public function text(?string $version = null): string
    {
        $version ??= self::CURRENT_VERSION;

        return self::TEXTS[$version]
            ?? throw new \InvalidArgumentException("There is no consent text version '{$version}'.");
    }

    /** The hash stored on the consent record, and checked against later. */
    public function hashFor(?string $version = null): string
    {
        return hash('sha256', $this->text($version));
    }

    /**
     * Does this stored record still match the text it claims to be?
     *
     * False means either the text was edited in place — which the class comment
     * forbids — or the record was tampered with. Either way, the consent can no
     * longer be relied on, and that is worth surfacing rather than assuming.
     */
    public function verify(string $version, string $storedHash): bool
    {
        if (! array_key_exists($version, self::TEXTS)) {
            return false;
        }

        return hash_equals($this->hashFor($version), $storedHash);
    }
}

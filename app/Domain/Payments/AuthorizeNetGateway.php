<?php

namespace App\Domain\Payments;

use App\Exceptions\GatewayUnavailableException;
use App\Models\Payment;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Authorize.Net, over the JSON API.  [TDD §9.1, FR-PAY-01/02]
 *
 * **We never see a bank account number.** Accept Hosted collects the
 * instrument on Authorize.Net's own page; CIM keeps it. Everything this class
 * sends is an amount, an invoice reference and a profile id, and everything it
 * receives is a transaction id, a status and an already-masked descriptor
 * (I-5, AC-PAY-05). That is what makes the columns this schema does not have
 * unnecessary rather than merely absent.
 *
 * Written against the HTTP API rather than the official SDK deliberately. The
 * SDK ships its own logger that will happily write a full request body to a
 * file, which is precisely what I-5 forbids and precisely the thing nobody
 * would notice until an audit. A handful of `Http::` calls is a smaller
 * surface than a dependency whose default behaviour has to be disarmed.
 */
class AuthorizeNetGateway
{
    /** ACH SEC code for a tenant-initiated online payment (TDD §9.1). */
    public const SEC_CODE_WEB = 'WEB';

    private const ENDPOINTS = [
        'sandbox' => [
            'api' => 'https://apitest.authorize.net/xml/v1/request.api',
            'hosted' => 'https://test.authorize.net/payment/payment',
        ],
        'production' => [
            'api' => 'https://api.authorize.net/xml/v1/request.api',
            'hosted' => 'https://accept.authorize.net/payment/payment',
        ],
    ];

    /** Settlement queries retry; payment submissions never do (TDD §9.1). */
    private const RETRY_TIMES = 3;

    private const TIMEOUT_SECONDS = 20;

    public function isConfigured(): bool
    {
        return (bool) config('services.authorize_net.login_id')
            && (bool) config('services.authorize_net.transaction_key');
    }

    public function environment(): string
    {
        $environment = config('services.authorize_net.environment', 'sandbox');

        return isset(self::ENDPOINTS[$environment]) ? $environment : 'sandbox';
    }

    /** Where the browser posts the token. Never hard-coded at a call site. */
    public function hostedPageUrl(): string
    {
        return self::ENDPOINTS[$this->environment()]['hosted'];
    }

    /**
     * A one-time token for the Accept Hosted payment form.  [FR-PAY-01 step 4]
     *
     * The token embeds the amount and the return URLs, so a tenant cannot
     * alter what they are about to pay by editing anything in the browser —
     * the amount is agreed here, server-side, before the page exists.
     */
    public function hostedPaymentToken(
        Payment $payment,
        Tenant $tenant,
        string $returnUrl,
        string $cancelUrl,
        ?string $customerProfileId = null,
    ): string {
        $this->assertUsableReturnUrl($returnUrl);

        $transaction = [
            'transactionType' => 'authCaptureTransaction',
            'amount' => $payment->amount->toDecimalString(),
            'order' => [
                // The payment id is the invoice reference, which is how a
                // settlement line is matched back to a ledger row at WP-14.
                'invoiceNumber' => (string) $payment->id,
                'description' => 'Rent payment',
            ],
            'customer' => ['email' => $tenant->email],
        ];

        if ($customerProfileId !== null) {
            $transaction['profile'] = ['customerProfileId' => $customerProfileId];
        }

        $response = $this->send([
            'getHostedPaymentPageRequest' => [
                'merchantAuthentication' => $this->authentication(),
                'transactionRequest' => $transaction,
                'hostedPaymentSettings' => [
                    'setting' => $this->hostedSettings($returnUrl, $cancelUrl),
                ],
            ],
        ]);

        $token = $response['token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new GatewayUnavailableException(
                'We could not open the secure payment form. Nothing has been charged. Please try again shortly.',
                ['payment_id' => $payment->id],
            );
        }

        return $token;
    }

    /**
     * Authorize.Net will not accept `localhost` as a return URL.
     *
     * It accepts 127.0.0.1 and any real domain, but rejects the hostname
     * `localhost` with "must begin with http:// or https://" — a message that
     * describes something else entirely and sends you looking in the wrong
     * place. Found against the live sandbox; no fixture would have said a word.
     *
     * Named here as the configuration error it is, rather than surfacing as
     * "the provider is unreachable", because those two need different fixes and
     * only one of them is yours. Rewriting the host is not the answer: the
     * session cookie is issued for `localhost`, so a tenant returned to
     * 127.0.0.1 would arrive logged out.
     *
     * Public so the intent can check it before writing anything. A failed
     * payment row is evidence that the provider was down (F1); it is not
     * evidence for our own misconfiguration, and filing one would be noise.
     */
    public function assertUsableReturnUrl(string $url): void
    {
        if (! preg_match('~^https?://localhost(:\d+)?(/|$)~i', $url)) {
            return;
        }

        throw new GatewayUnavailableException(
            GatewayUnavailableException::NOT_AVAILABLE,
            [
                'reason' => 'APP_URL uses localhost, which Authorize.Net rejects as a return URL. '
                    .'Set APP_URL to http://127.0.0.1:8000 locally, or a real domain.',
            ],
        );
    }

    /**
     * Do the credentials work?
     *
     * The cheapest call Authorize.Net offers: it moves no money and creates
     * nothing. `hupm:preflight` runs it the day credentials arrive, because
     * "the host can reach api.authorize.net" and "the account works" are
     * different facts and only one of them is worth knowing.
     */
    public function authenticates(): bool
    {
        try {
            $this->send([
                'authenticateTestRequest' => ['merchantAuthentication' => $this->authentication()],
            ], retry: true);
        } catch (GatewayUnavailableException) {
            return false;
        }

        return true;
    }

    /**
     * Find the transaction a payment just created, by asking.
     *
     * **Accept Hosted's redirect mode returns no transaction data.** Its
     * documentation is explicit: the continue button "sends an HTTP GET request
     * to that URL", and the response fields — `transId` among them — come back
     * only through the iframe communicator, which a redirect integration does
     * not have. So a resident who has genuinely just paid returns to us with
     * nothing in hand, and we cannot tell them apart from one whose payment was
     * declined or who never submitted at all.
     *
     * Guessing either way is wrong in a way that costs money. Told it worked
     * when it did not, a resident does not pay; told it failed when it worked,
     * they pay twice. So we ask instead: the invoice number we sent is our
     * payment id, and a transaction created seconds ago is at the top of the
     * unsettled list.
     *
     * Reconciliation remains the authority (R-6). This only shortens the gap
     * between a resident paying and the screen being able to say so.
     *
     * @return array<string, mixed>|null the transaction, or null if the gateway
     *                                   has never heard of this payment
     */
    public function findUnsettledByInvoice(string $invoiceNumber): ?array
    {
        $response = $this->send([
            'getUnsettledTransactionListRequest' => [
                'merchantAuthentication' => $this->authentication(),
                'sorting' => ['orderBy' => 'submitTimeUTC', 'orderDescending' => true],
                // Newest first, so the payment made moments ago is on page one.
                // Unsettled means "since the last batch closed", which on this
                // portfolio is a day of activity, not a backlog.
                'paging' => ['limit' => 100, 'offset' => 1],
            ],
        ], retry: true);

        foreach ($response['transactions'] ?? [] as $transaction) {
            if ((string) ($transaction['invoiceNumber'] ?? '') === $invoiceNumber) {
                return $transaction;
            }
        }

        return null;
    }

    /**
     * What the gateway knows about one transaction.
     *
     * Used by the return handler and, authoritatively, by WP-14's daily
     * reconciliation — webhooks are supplementary and must never be the sole
     * mechanism (R-6).
     *
     * @return array<string, mixed>
     */
    public function transactionDetails(string $transactionId): array
    {
        $response = $this->send([
            'getTransactionDetailsRequest' => [
                'merchantAuthentication' => $this->authentication(),
                'transId' => $transactionId,
            ],
        ], retry: true);

        return $response['transaction'] ?? [];
    }

    /**
     * Batches settled in a window.  [FR-PAY-04 step 1]
     *
     * The reporting API, not the webhook: ACH returns are reported on
     * settlement statements and a webhook may arrive late, twice or never
     * (R-6). This is the authoritative source and the only one.
     *
     * @return list<array<string, mixed>>
     */
    public function settledBatches(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $response = $this->send([
            'getSettledBatchListRequest' => [
                'merchantAuthentication' => $this->authentication(),
                'includeStatistics' => 'false',
                // The API wants UTC and rejects a window it considers invalid.
                'firstSettlementDate' => $from->utc()->format('Y-m-d\TH:i:s\Z'),
                'lastSettlementDate' => $to->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
        ], retry: true);

        return $response['batchList'] ?? [];
    }

    /**
     * Every transaction in one settled batch.
     *
     * @return list<array<string, mixed>>
     */
    public function batchTransactions(string $batchId): array
    {
        $response = $this->send([
            'getTransactionListRequest' => [
                'merchantAuthentication' => $this->authentication(),
                'batchId' => $batchId,
                'sorting' => ['orderBy' => 'submitTimeUTC', 'orderDescending' => 'false'],
            ],
        ], retry: true);

        return $response['transactions'] ?? [];
    }

    /**
     * This tenant's CIM customer profile id, if they have one.
     *
     * Looked up by `merchantCustomerId` rather than kept in a column of our
     * own. The id already exists at the gateway, and a second copy here would
     * only be a chance to disagree — plus a migration, for a value we can ask
     * for whenever we need it.
     */
    public function customerProfileId(Tenant $tenant): ?string
    {
        try {
            $response = $this->send([
                'getCustomerProfileRequest' => [
                    'merchantAuthentication' => $this->authentication(),
                    'merchantCustomerId' => $this->merchantCustomerId($tenant),
                ],
            ], retry: true);
        } catch (GatewayUnavailableException) {
            // "No such profile" arrives as an error response. A tenant who has
            // never saved a method is the normal case, not a failure.
            return null;
        }

        $id = $response['profile']['customerProfileId'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function createCustomerProfile(Tenant $tenant): string
    {
        $response = $this->send([
            'createCustomerProfileRequest' => [
                'merchantAuthentication' => $this->authentication(),
                'profile' => [
                    'merchantCustomerId' => $this->merchantCustomerId($tenant),
                    'description' => $tenant->fullName(),
                    'email' => $tenant->email,
                ],
            ],
        ]);

        $id = $response['customerProfileId'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new GatewayUnavailableException('We could not set up a saved payment method.');
        }

        return $id;
    }

    /**
     * The saved bank accounts on a CIM profile, already masked by the gateway.
     *
     * Authorize.Net returns `XXXX6789`. Nothing here reconstructs a full
     * number, and nothing could — the digits were never sent to us.
     *
     * @return list<array{id: string, descriptor: string}>
     */
    public function paymentProfiles(string $customerProfileId): array
    {
        $response = $this->send([
            'getCustomerProfileRequest' => [
                'merchantAuthentication' => $this->authentication(),
                'customerProfileId' => $customerProfileId,
                'includeIssuerInfo' => 'false',
            ],
        ], retry: true);

        $found = [];

        foreach ($response['profile']['paymentProfiles'] ?? [] as $profile) {
            $id = $profile['customerPaymentProfileId'] ?? null;
            $bank = $profile['payment']['bankAccount'] ?? null;

            if (! is_string($id) || ! is_array($bank)) {
                // A card profile, if one ever exists on the account. Cards are
                // out of scope (Q-7) and we do not list what we cannot charge.
                continue;
            }

            $found[] = ['id' => $id, 'descriptor' => $this->describeBankAccount($bank)];
        }

        return $found;
    }

    /**
     * "Checking ••••6789" — the whole of what we are permitted to hold (AC-PAY-06).
     *
     * @param  array<string, mixed>  $bank
     */
    public function describeBankAccount(array $bank): string
    {
        $type = match ($bank['accountType'] ?? '') {
            'checking', 'businessChecking' => 'Checking',
            'savings' => 'Savings',
            default => 'Bank account',
        };

        // The gateway already masks it as XXXX6789. Keeping the last four is
        // keeping what it showed us, not deriving anything from what it did not.
        $digits = substr((string) preg_replace('/\D/', '', (string) ($bank['accountNumber'] ?? '')), -4);

        return $digits === '' ? $type : $type.' ••••'.$digits;
    }

    /**
     * Verify a webhook signature.  [API-HOOK-01]
     *
     * HMAC-SHA512 of the raw body under the **Signature Key** — a different
     * value from the Transaction Key, and hex rather than text. Compared with
     * hash_equals so the comparison cannot be timed.
     */
    public function webhookSignatureIsValid(string $rawBody, ?string $header): bool
    {
        $key = config('services.authorize_net.signature_key');

        if (! $key || ! $header) {
            return false;
        }

        // Header form: "sha512=ABC123…"
        $provided = str_contains($header, '=') ? substr($header, strpos($header, '=') + 1) : $header;
        $binaryKey = @hex2bin($key);

        if ($binaryKey === false) {
            return false;
        }

        return hash_equals(
            strtoupper(hash_hmac('sha512', $rawBody, $binaryKey)),
            strtoupper(trim($provided)),
        );
    }

    /**
     * Accept Hosted form configuration.
     *
     * @return list<array{settingName: string, settingValue: string}>
     */
    private function hostedSettings(string $returnUrl, string $cancelUrl): array
    {
        $settings = [
            'hostedPaymentReturnOptions' => [
                'showReceipt' => false,
                'url' => $returnUrl,
                'urlText' => 'Return to your account',
                'cancelUrl' => $cancelUrl,
                'cancelUrlText' => 'Cancel',
            ],
            'hostedPaymentButtonOptions' => ['text' => 'Pay'],
            // Cards are out of scope for v1 (Q-7). Offering a card field we do
            // not reconcile would be worse than not offering one.
            'hostedPaymentPaymentOptions' => [
                'cardCodeRequired' => false,
                'showCreditCard' => false,
                'showBankAccount' => true,
            ],
            'hostedPaymentSecurityOptions' => ['captcha' => false],
            // Offer to save the bank account as a CIM profile (FR-PAY-02). The
            // instrument stays with Authorize.Net; we get an id back.
            'hostedPaymentCustomerOptions' => [
                'showEmail' => false,
                'requiredEmail' => false,
                'addPaymentProfile' => true,
            ],
            'hostedPaymentOrderOptions' => ['show' => true],
        ];

        $encoded = [];

        foreach ($settings as $name => $value) {
            $encoded[] = [
                'settingName' => $name,
                'settingValue' => json_encode($value, JSON_THROW_ON_ERROR),
            ];
        }

        return $encoded;
    }

    /** @return array<string, string> */
    private function authentication(): array
    {
        return [
            'name' => (string) config('services.authorize_net.login_id'),
            'transactionKey' => (string) config('services.authorize_net.transaction_key'),
        ];
    }

    private function merchantCustomerId(Tenant $tenant): string
    {
        return 'hupm-tenant-'.$tenant->id;
    }

    /**
     * One request/response cycle.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(array $payload, bool $retry = false): array
    {
        if (! $this->isConfigured()) {
            throw new GatewayUnavailableException(
                GatewayUnavailableException::NOT_AVAILABLE,
                ['reason' => 'missing credentials'],
            );
        }

        $request = Http::timeout(self::TIMEOUT_SECONDS)->acceptJson()->asJson();

        // Only reads retry. Re-sending a payment submission because a response
        // was slow is how a tenant gets debited twice (TDD §9.1).
        if ($retry) {
            $request = $request->retry(self::RETRY_TIMES, 200, throw: false);
        }

        try {
            $response = $request->post(self::ENDPOINTS[$this->environment()]['api'], $payload);
        } catch (ConnectionException $e) {
            throw new GatewayUnavailableException(context: ['reason' => $e->getMessage()]);
        }

        if ($response->failed()) {
            throw new GatewayUnavailableException(context: ['http_status' => $response->status()]);
        }

        return $this->decode($response->body(), (string) array_key_first($payload));
    }

    /**
     * Remove our own credentials from text the gateway echoed back at us.
     *
     * Belt and braces: the text is also truncated, because an error message is
     * a diagnostic and a log line is not a place to store an unbounded string
     * somebody else controls.
     */
    private function redact(string $text): string
    {
        $secrets = array_filter([
            (string) config('services.authorize_net.login_id'),
            (string) config('services.authorize_net.transaction_key'),
            (string) config('services.authorize_net.signature_key'),
        ]);

        foreach ($secrets as $secret) {
            $text = str_ireplace([$secret, htmlspecialchars($secret, ENT_QUOTES)], '[redacted]', $text);
        }

        return mb_substr($text, 0, 300);
    }

    /** @return array<string, mixed> */
    private function decode(string $body, string $requestName): array
    {
        // Authorize.Net's JSON API prefixes every response with a UTF-8 BOM,
        // which json_decode rejects outright. Documented nowhere prominent, and
        // reliably the first hour of anyone's integration.
        $decoded = json_decode(ltrim($body, "\xEF\xBB\xBF\x00"), true);

        if (! is_array($decoded)) {
            throw new GatewayUnavailableException(context: ['reason' => 'unreadable response']);
        }

        if (($decoded['messages']['resultCode'] ?? null) !== 'Ok') {
            /** @var array<string, mixed> $message */
            $message = $decoded['messages']['message'][0] ?? [];

            // Logged without the payload: the request carries a customer
            // profile id and the response can carry a masked account, and
            // neither belongs in a log file that gets emailed around (I-5).
            //
            // The text is redacted as well, which is not obvious until you see
            // it happen: Authorize.Net quotes the value it objected to, so a
            // rejected API Login ID comes back inside the error message and
            // goes straight into the log. Found by making a real call with a
            // deliberately wrong credential.
            Log::warning('Authorize.Net rejected a request.', [
                'request' => $requestName,
                'code' => $message['code'] ?? null,
                'text' => $this->redact((string) ($message['text'] ?? '')),
            ]);

            throw new GatewayUnavailableException(
                $this->tenantMessageFor($message),
                [
                    'request' => $requestName,
                    'code' => $message['code'] ?? null,
                ],
            );
        }

        return $decoded;
    }

    /**
     * Which of the tenant-safe messages fits this rejection.
     *
     * Only the duplicate is singled out, because it is the only rejection whose
     * correct advice is the opposite of the default. Everything else really is
     * "we could not get an answer, nothing was charged, try shortly".
     *
     * Matched on the **text** as well as the code. A duplicate surfaces as
     * `E00027` at the API level and as reason code `11` inside a transaction
     * response, depending on where in the flow it is refused; the word is the
     * one thing present in every version of it.
     *
     * @param  array<string, mixed>  $message
     */
    private function tenantMessageFor(array $message): string
    {
        $code = (string) ($message['code'] ?? '');
        $text = strtolower((string) ($message['text'] ?? ''));

        if ($code === '11' || str_contains($text, 'duplicate')) {
            return GatewayUnavailableException::DUPLICATE;
        }

        return GatewayUnavailableException::OUTAGE;
    }
}

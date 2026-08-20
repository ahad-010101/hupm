import { useEffect, useRef, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';
import Alert from '@/Components/Alert';
import FormField from '@/Components/FormField';

/**
 * Sign a document.  [UI §3.6, FR-SIG-02, AC-SIG-01/04]
 *
 * Three gates, in order, and none of them is decorative:
 *
 *   1. **Consent** to transact electronically, recorded before anything else
 *      (BR-25). Without it there is no signature to argue about.
 *   2. **Reading the whole document.** The scroll is reported to the server as
 *      its own event — a disabled button is a courtesy, and the server refuses
 *      independently (AC-SIG-04).
 *   3. **A typed legal name**, plus a control whose exact label is stored as
 *      evidence of intent.
 *
 * A session that expires mid-signature records nothing and leaves the request
 * pending (F1). The screen says so plainly, because "did that go through?" is
 * the worst question to be left with.
 */
export default function Sign({ request, document, consent, buttonLabel, signerName, errors = {} }) {
    const [consented, setConsented] = useState(consent.recorded);
    const [consentError, setConsentError] = useState(null);
    const [scrolled, setScrolled] = useState(false);
    const [savingConsent, setSavingConsent] = useState(false);
    const paneRef = useRef(null);

    const form = useForm({
        typed_name: '',
        scrolled_complete: false,
        consent_acknowledged: false,
    });

    // Reported to the server the first time they reach the bottom, so the
    // certificate can say they read it rather than that a button was enabled.
    useEffect(() => {
        const pane = paneRef.current;

        if (!pane || scrolled || !consented) {
            return undefined;
        }

        const check = async () => {
            const atBottom = pane.scrollHeight - pane.scrollTop - pane.clientHeight < 24;

            if (!atBottom) {
                return;
            }

            setScrolled(true);
            form.setData('scrolled_complete', true);

            try {
                await window.axios.post(`/portal/sign/${request.id}/scrolled`);
            } catch {
                // The server checks independently at signing time, so a failure
                // here costs nothing but a retry.
                setScrolled(false);
                form.setData('scrolled_complete', false);
            }
        };

        pane.addEventListener('scroll', check, { passive: true });
        check();

        return () => pane.removeEventListener('scroll', check);
    }, [consented, scrolled, request.id]);

    const recordConsent = async () => {
        setSavingConsent(true);
        setConsentError(null);

        try {
            await window.axios.post(`/portal/sign/${request.id}/consent`, {
                agreed: true,
                version: consent.version,
            });
            setConsented(true);
            form.setData('consent_acknowledged', true);
        } catch (failure) {
            setConsentError(
                failure.response?.data?.message
                    ?? 'We could not record that just now. Please try again.',
            );
        } finally {
            setSavingConsent(false);
        }
    };

    if (request.signed) {
        return (
            <PortalLayout header="Signed">
                <Head title="Signed" />
                <Alert tone="success" title="This document is signed">
                    A copy with its certificate is in your documents and has been emailed to you.
                </Alert>
                <Link
                    href={`/portal/documents/${request.signed_document_id}`}
                    className="mt-4 inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700"
                >
                    Open the signed copy
                </Link>
            </PortalLayout>
        );
    }

    if (request.expired) {
        return (
            <PortalLayout header="Signature request expired">
                <Head title="Signature request expired" />
                <Alert tone="warning" title="This request has expired">
                    Nothing has been signed. Please contact the office and we will send a new one.
                </Alert>
            </PortalLayout>
        );
    }

    return (
        <PortalLayout header={document.title}>
            <Head title={`Sign ${document.title}`} />

            {/* Gate 1 — consent (BR-25, AC-SIG-01). */}
            {!consented && (
                <section className="rounded-lg border border-gray-200 bg-white p-5">
                    <h2 className="text-lg font-semibold text-gray-900">
                        Before you sign electronically
                    </h2>

                    <div className="mt-3 max-h-72 overflow-y-auto whitespace-pre-line rounded-md border border-gray-200 bg-gray-50 p-4 text-base">
                        {consent.text}
                    </div>

                    {consentError && (
                        <Alert tone="error" className="mt-3">{consentError}</Alert>
                    )}

                    <button
                        type="button"
                        onClick={recordConsent}
                        disabled={savingConsent}
                        className="mt-4 inline-flex min-h-touch w-full items-center justify-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 disabled:opacity-60 sm:w-auto"
                    >
                        {savingConsent ? 'Recording…' : 'I agree to use electronic records'}
                    </button>

                    <p className="mt-2 text-sm text-gray-600">
                        You can ask for paper copies at any time, at no charge.
                    </p>
                </section>
            )}

            {consented && (
                <>
                    <section className="rounded-lg border border-gray-200 bg-white p-5">
                        <h2 className="text-lg font-semibold text-gray-900">Read the document</h2>
                        <p className="mt-1 text-sm text-gray-600">{document.category}</p>

                        {/* Gate 2 — the whole thing, scrolled (AC-SIG-04). An
                            embedded PDF is not readable on a small screen, so
                            the file opens in whatever the device already knows
                            how to use, and the scroll pane below is the record
                            of what was presented. */}
                        <div
                            ref={paneRef}
                            tabIndex={0}
                            className="mt-3 max-h-96 overflow-y-auto rounded-md border border-gray-200 bg-gray-50 p-4 text-base"
                        >
                            <p className="mb-3">
                                <a
                                    href={document.download_url}
                                    className="font-semibold underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                >
                                    Open {document.title}
                                </a>{' '}
                                to read it in full.
                            </p>
                            <p className="mb-3">
                                Scroll to the bottom of this panel to confirm you have read it. The
                                time you did so is recorded with your signature.
                            </p>
                            <div className="h-64" aria-hidden="true" />
                            <p className="font-medium text-gray-900">
                                You have reached the end of the document.
                            </p>
                        </div>

                        <p aria-live="polite" className="mt-2 text-sm">
                            {scrolled ? (
                                <span className="font-medium text-credit-fg">
                                    Recorded: you have read the whole document.
                                </span>
                            ) : (
                                <span className="text-gray-600">
                                    Scroll to the end of the panel above to continue.
                                </span>
                            )}
                        </p>
                    </section>

                    <section className="mt-4 rounded-lg border border-gray-200 bg-white p-5">
                        <h2 className="text-lg font-semibold text-gray-900">Sign</h2>

                        {/* Said before they sign, not buried in a policy. */}
                        <p className="mt-1 text-base text-gray-700">
                            We will record your typed name, the date and time, your device's
                            internet address, and a fingerprint of the exact document you have read.
                        </p>

                        <FormField
                            label="Your full legal name"
                            value={form.data.typed_name}
                            onChange={(e) => form.setData('typed_name', e.target.value)}
                            error={form.errors.typed_name ?? errors.typed_name}
                            hint={`On file as ${signerName}.`}
                            className="mt-3"
                            maxLength={150}
                            required
                        />

                        <button
                            type="button"
                            disabled={form.processing || !scrolled || form.data.typed_name.trim() === ''}
                            onClick={() => form.post(`/portal/sign/${request.id}`)}
                            className="inline-flex min-h-touch w-full items-center justify-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 disabled:opacity-60 sm:w-auto"
                        >
                            {form.processing ? 'Signing…' : buttonLabel}
                        </button>

                        <p className="mt-2 text-sm text-gray-600">
                            If your session ends before you press this, nothing is signed and the
                            request stays open.
                        </p>
                    </section>
                </>
            )}
        </PortalLayout>
    );
}

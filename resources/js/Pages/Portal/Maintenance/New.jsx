import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';
import Alert from '@/Components/Alert';
import FormField from '@/Components/FormField';

/**
 * Report a repair.  [UI §3.5, FR-MNT-01, BR-23]
 *
 * Three steps, and the order is the point. **The emergency warning comes
 * first and has to be acknowledged before anything else is on screen**
 * (BR-23): someone with a gas leak or no heat in January should be phoning,
 * not typing. The 911 instruction and the out-of-hours number are on that
 * first panel, not a click away.
 *
 * Nothing is lost between steps — the form holds one state object, so going
 * back never costs the tenant what they typed. That matters more here than
 * anywhere: this form is filled in on a phone, one-handed, usually while
 * something is actively going wrong.
 */

const STEPS = ['Emergency', 'What is wrong', 'Access'];

/**
 * Which step owns which field.
 *
 * Submitting happens on the last step, but a rejected field may live on an
 * earlier one — and its message renders inside a panel the tenant can no longer
 * see. Without this the form simply refuses to submit and says nothing at all,
 * which reads as a broken button. Every error message has to say what to do
 * next (UI §8), and the first part of that is being on screen.
 *
 * `photos` belongs to the last step; anything unlisted falls there too, so a
 * new field cannot go quietly missing.
 */
export const STEP_FIELDS = [
    ['emergency_acknowledged', 'is_emergency'],
    ['category', 'description', 'date_began'],
    ['permission_to_enter', 'preferred_contact', 'contact_phone', 'pets_present', 'best_access_time', 'photos'],
];

/** The earliest step carrying an error, or null when none of them do. */
export function firstStepWithError(errors) {
    const keys = Object.keys(errors ?? {});

    if (keys.length === 0) {
        return null;
    }

    const index = STEP_FIELDS.findIndex((fields) =>
        keys.some((key) => fields.includes(key.split('.')[0])),
    );

    // An error on a field no step claims still has to be seen, so fall back to
    // the last step rather than swallowing it.
    return index === -1 ? STEP_FIELDS.length - 1 : index;
}

export default function New({
    categories = {},
    hasLease,
    unit,
    tenantName,
    tenantPhone,
    emergencyPhone,
    maxFiles = 5,
    maxMegabytes = 20,
}) {
    const [step, setStep] = useState(0);
    const [files, setFiles] = useState([]);
    const [rejected, setRejected] = useState([]);
    const [submitFailed, setSubmitFailed] = useState(false);

    const { data, setData, post, processing, errors, transform } = useForm({
        emergency_acknowledged: false,
        category: '',
        description: '',
        date_began: '',
        permission_to_enter: '',
        preferred_contact: 'email',
        contact_phone: tenantPhone ?? '',
        pets_present: false,
        best_access_time: '',
        is_emergency: false,
    });

    const chooseFiles = (event) => {
        const chosen = [...event.target.files];
        const tooBig = chosen.filter((f) => f.size > maxMegabytes * 1048576);

        // Rejected before it is ever sent, so nothing the tenant typed is at
        // risk (AC-MNT-02). The server refuses it too.
        setFiles(chosen.filter((f) => f.size <= maxMegabytes * 1048576).slice(0, maxFiles));
        setRejected(tooBig.map((f) => f.name));
    };

    const submit = (e) => {
        e.preventDefault();

        transform((form) => ({ ...form, photos: files }));

        post('/portal/maintenance', {
            forceFormData: true,
            onError: (failures) => {
                // Go to where the problem is. Leaving the tenant on the last
                // panel while the message sits on the second one is the same as
                // showing nothing.
                const target = firstStepWithError(failures);

                if (target !== null) {
                    setStep(target);
                    setSubmitFailed(true);
                }
            },
            onSuccess: () => setSubmitFailed(false),
        });
    };

    if (!hasLease) {
        return (
            <PortalLayout header="Report a repair">
                <Head title="Report a repair" />
                <Alert tone="warning" title="No active tenancy">
                    We could not find an active tenancy for you. Please contact the office.
                </Alert>
            </PortalLayout>
        );
    }

    return (
        <PortalLayout header="Report a repair">
            <Head title="Report a repair" />

            {submitFailed && (
                // Said once, at the top, as well as beside the field. A tenant
                // who pressed Send and was moved back two panels needs to know
                // why they moved (UI §7).
                <Alert tone="warning" className="mb-4" title="We need one more thing before we can send this">
                    Nothing has been lost — everything you typed is still here. Please check the
                    highlighted answer below, then send it again.
                </Alert>
            )}

            <ol className="mb-4 flex gap-2 text-sm" aria-label="Progress">
                {STEPS.map((label, i) => {
                    const needsAttention = (STEP_FIELDS[i] ?? []).some(
                        (field) => Object.keys(errors).some((key) => key.split('.')[0] === field),
                    );

                    return (
                        <li
                            key={label}
                            aria-current={i === step ? 'step' : undefined}
                            className={`flex-1 rounded-md border px-2 py-1 text-center ${
                                needsAttention
                                    ? 'border-overdue-fg bg-overdue-bg font-semibold text-overdue-fg'
                                    : i === step
                                      ? 'border-brand-600 bg-brand-50 font-semibold text-brand-700'
                                      : 'border-gray-300 bg-white text-gray-600'
                            }`}
                        >
                            {label}
                            {/* Not colour alone (UI §9). */}
                            {needsAttention && <span className="ml-1" aria-label="needs attention">!</span>}
                        </li>
                    );
                })}
            </ol>

            <form onSubmit={submit} noValidate className="rounded-lg border border-gray-200 bg-white p-5">
                {/* Step 1 — BR-23. Nothing else is reachable until this is read. */}
                {step === 0 && (
                    <div>
                        <h2 className="text-lg font-semibold text-gray-900">If this is an emergency</h2>

                        <Alert tone="error" className="mt-3" title="Life-threatening? Call 911 first.">
                            Gas smell, fire, flooding, no heat in freezing weather, or anything that
                            could hurt someone — <strong>call 911</strong>, then call us.
                            {emergencyPhone && (
                                <>
                                    {' '}Our emergency line is{' '}
                                    <a
                                        href={`tel:${emergencyPhone.replace(/[^\d+]/g, '')}`}
                                        className="font-semibold underline"
                                    >
                                        {emergencyPhone}
                                    </a>
                                    .
                                </>
                            )}
                        </Alert>

                        <p className="mt-3 text-base text-gray-700">
                            This form is checked during office hours. It is not the fastest way to
                            reach someone in an emergency.
                        </p>

                        <label className="mt-4 flex items-start gap-3 text-base">
                            <input
                                type="checkbox"
                                checked={data.emergency_acknowledged}
                                onChange={(e) => setData('emergency_acknowledged', e.target.checked)}
                                className="mt-1 size-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                            />
                            <span>I have read this and my situation is not life-threatening.</span>
                        </label>

                        {errors.emergency_acknowledged && (
                            <p className="mt-2 text-base text-overdue-fg">{errors.emergency_acknowledged}</p>
                        )}

                        <button
                            type="button"
                            disabled={!data.emergency_acknowledged}
                            onClick={() => setStep(1)}
                            className="mt-4 inline-flex min-h-touch w-full items-center justify-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60 sm:w-auto"
                        >
                            Continue
                        </button>
                    </div>
                )}

                {/* Step 2 — the problem. */}
                {step === 1 && (
                    <div>
                        <h2 className="mb-3 text-lg font-semibold text-gray-900">What is wrong?</h2>

                        {unit && <p className="mb-3 text-base text-gray-600">{unit}</p>}

                        <FormField label="What sort of problem is it?" error={errors.category} required>
                            <select
                                value={data.category}
                                onChange={(e) => setData('category', e.target.value)}
                                className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                            >
                                <option value="">Choose one…</option>
                                {Object.entries(categories).map(([value, label]) => (
                                    <option key={value} value={value}>{label}</option>
                                ))}
                            </select>
                        </FormField>

                        <FormField label="Describe the problem" error={errors.description} required>
                            <textarea
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                rows={5}
                                maxLength={5000}
                                placeholder="What is happening, where in the home, and when you first noticed."
                                className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                            />
                        </FormField>

                        <FormField
                            label="When did it start?"
                            type="date"
                            value={data.date_began}
                            onChange={(e) => setData('date_began', e.target.value)}
                            error={errors.date_began}
                            hint="Roughly is fine."
                        />

                        <label className="mb-4 flex items-start gap-3 text-base">
                            <input
                                type="checkbox"
                                checked={data.is_emergency}
                                onChange={(e) => setData('is_emergency', e.target.checked)}
                                className="mt-1 size-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                            />
                            <span>
                                This is urgent and cannot wait.
                                <span className="block text-sm text-gray-600">
                                    Urgent requests go to the top of the queue.
                                </span>
                            </span>
                        </label>

                        <div className="flex gap-3">
                            <button
                                type="button"
                                onClick={() => setStep(0)}
                                className="min-h-touch rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50"
                            >
                                Back
                            </button>
                            <button
                                type="button"
                                onClick={() => setStep(2)}
                                className="min-h-touch flex-1 rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 sm:flex-none"
                            >
                                Continue
                            </button>
                        </div>
                    </div>
                )}

                {/* Step 3 — getting in, and photos. */}
                {step === 2 && (
                    <div>
                        <h2 className="mb-3 text-lg font-semibold text-gray-900">Getting in, and photos</h2>

                        <FormField label="May we enter if you are out?" error={errors.permission_to_enter} required>
                            <div className="flex gap-4">
                                {[['1', 'Yes'], ['0', 'No']].map(([value, label]) => (
                                    <label key={value} className="flex items-center gap-2 text-base">
                                        <input
                                            type="radio"
                                            name="permission_to_enter"
                                            value={value}
                                            checked={String(data.permission_to_enter) === value}
                                            onChange={(e) => setData('permission_to_enter', e.target.value)}
                                            className="size-5 border-gray-300 text-brand-600 focus:ring-brand-600"
                                        />
                                        {label}
                                    </label>
                                ))}
                            </div>
                        </FormField>

                        <FormField label="How should we reach you?" error={errors.preferred_contact} required>
                            <select
                                value={data.preferred_contact}
                                onChange={(e) => setData('preferred_contact', e.target.value)}
                                className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                            >
                                <option value="email">Email</option>
                                <option value="phone">Phone</option>
                            </select>
                        </FormField>

                        {data.preferred_contact === 'phone' && (
                            <FormField
                                label="Phone number"
                                value={data.contact_phone}
                                onChange={(e) => setData('contact_phone', e.target.value)}
                                error={errors.contact_phone}
                                inputMode="tel"
                                required
                            />
                        )}

                        <FormField
                            label="Best time to come"
                            value={data.best_access_time}
                            onChange={(e) => setData('best_access_time', e.target.value)}
                            error={errors.best_access_time}
                            hint="For example: weekday mornings, or after 4pm."
                            maxLength={100}
                        />

                        <label className="mb-4 flex items-start gap-3 text-base">
                            <input
                                type="checkbox"
                                checked={data.pets_present}
                                onChange={(e) => setData('pets_present', e.target.checked)}
                                className="mt-1 size-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                            />
                            <span>
                                There are pets in the home.
                                <span className="block text-sm text-gray-600">
                                    So whoever comes knows before they open the door.
                                </span>
                            </span>
                        </label>

                        <div className="mb-4">
                            <label htmlFor="photos" className="mb-1 block text-base font-medium text-gray-900">
                                Photos or a short video
                            </label>
                            <input
                                id="photos"
                                type="file"
                                multiple
                                // Camera capture on a phone (UI §3.5) — a photo
                                // of the leak saves a visit to go and look at it.
                                accept="image/jpeg,image/png,image/heic,image/heif,video/mp4"
                                capture="environment"
                                onChange={chooseFiles}
                                className="block w-full text-base"
                            />
                            <p className="mt-1 text-sm text-gray-600">
                                Up to {maxFiles} files, {maxMegabytes} MB each.
                            </p>

                            {rejected.length > 0 && (
                                <Alert tone="warning" className="mt-2" title="Some files were too large">
                                    {rejected.join(', ')} — over {maxMegabytes} MB. Everything else you
                                    have filled in has been kept.
                                </Alert>
                            )}

                            {files.length > 0 && (
                                <ul className="mt-2 text-sm text-gray-700">
                                    {files.map((f) => (
                                        <li key={f.name}>{f.name}</li>
                                    ))}
                                </ul>
                            )}

                            {errors.photos && <p className="mt-2 text-base text-overdue-fg">{errors.photos}</p>}
                        </div>

                        <div className="flex gap-3">
                            <button
                                type="button"
                                onClick={() => setStep(1)}
                                className="min-h-touch rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50"
                            >
                                Back
                            </button>
                            <button
                                type="submit"
                                disabled={processing}
                                className="min-h-touch flex-1 rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 disabled:opacity-60 sm:flex-none"
                            >
                                {processing ? 'Sending…' : 'Send request'}
                            </button>
                        </div>
                    </div>
                )}
            </form>

            <Link
                href="/portal/maintenance"
                className="mt-4 inline-block text-base underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
            >
                Your other requests
            </Link>
        </PortalLayout>
    );
}

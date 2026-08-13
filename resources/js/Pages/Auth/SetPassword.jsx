import { Head, useForm } from '@inertiajs/react';
import FormField from '@/Components/FormField';
import Alert from '@/Components/Alert';

/**
 * Setting a password from an invitation link.  [FR-AUTH-02, AC-AUTH-05]
 *
 * When the link has expired or already been used, no form is shown at all —
 * offering one and then rejecting the submission wastes the person's time and
 * teaches them the system is unreliable. They get an explanation and a resend
 * button instead.
 */
export default function SetPassword({ valid, email, userId, token }) {
    const { data, setData, post, processing, errors } = useForm({
        password: '',
        password_confirmation: '',
    });

    const resend = useForm({});

    const submit = (e) => {
        e.preventDefault();
        post(window.location.pathname + window.location.search);
    };

    return (
        <div className="mx-auto max-w-md px-4 py-12">
            <Head title="Set your password" />

            <h1 className="mb-6 text-2xl font-semibold text-gray-900">Set your password</h1>

            {!valid ? (
                <>
                    <Alert tone="warning" title="This link is no longer valid">
                        It has either expired or already been used. Request a new one and we will
                        email it to you.
                    </Alert>

                    <button
                        type="button"
                        disabled={resend.processing}
                        onClick={() => resend.post(`/set-password/${userId}/resend`)}
                        className="mt-4 min-h-touch rounded-md bg-brand-600 px-4 py-2 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                    >
                        {resend.processing ? 'Sending…' : 'Email me a new link'}
                    </button>
                </>
            ) : (
                <form onSubmit={submit} noValidate>
                    <p className="mb-4 text-base text-gray-700">
                        Choose a password for <strong>{email}</strong>. It must be at least 12
                        characters.
                    </p>

                    <FormField
                        label="New password"
                        type="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        error={errors.password}
                        autoComplete="new-password"
                        required
                    />

                    <FormField
                        label="Confirm password"
                        type="password"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        error={errors.password_confirmation}
                        autoComplete="new-password"
                        required
                    />

                    <button
                        type="submit"
                        disabled={processing}
                        className="min-h-touch w-full rounded-md bg-brand-600 px-4 py-2 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                    >
                        {processing ? 'Saving…' : 'Set password and continue'}
                    </button>
                </form>
            )}
        </div>
    );
}

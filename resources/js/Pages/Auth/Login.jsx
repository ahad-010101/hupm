import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import FormField from '@/Components/FormField';
import Alert from '@/Components/Alert';

/**
 * Sign in.  [AC-AUTH-01…04, UI §6, §9]
 *
 * Rewritten from the generated scaffolding, which used a raw indigo focus ring
 * — a colour that appears nowhere else in this product — and labelled the
 * fields "Email" and "Password" with no indication of whose account this is or
 * what signing in gets you.
 *
 * Built for the resident persona in PRD §7: 58, Housing Choice Voucher, Android
 * phone, rarely uses a computer, will use something only if it works in three
 * taps. So: 16px inputs (below that iOS zooms the viewport and she loses her
 * place), 44px targets throughout, one column, and the error where the field is.
 *
 * There is no "create an account" link because there is no self-registration —
 * accounts are issued by invitation, and a test asserts the route does not
 * exist. The wording says so rather than leaving somebody hunting for a button
 * that was never built.
 */
export default function Login({ status, canResetPassword }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();

        post('/login', {
            // Clear the password whatever the outcome. A failed attempt leaving
            // it in the box is one wrong keystroke from a second failure, and
            // five of those is a lockout (AC-AUTH-03).
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout
            title="Sign in"
            intro="Pay your rent, report a repair, and read anything we have sent you."
        >
            <Head title="Sign in" />

            {status && (
                <Alert tone="success" title="" className="mb-4">
                    {status}
                </Alert>
            )}

            {/*
                One message for a wrong address and a wrong password alike
                (AC-AUTH-02), so this page never confirms whether an account
                exists. It is shown once, at the top, because the server cannot
                say which field was wrong without giving that away.
            */}
            {errors.email && (
                <Alert tone="error" title="We could not sign you in" className="mb-4">
                    {errors.email}
                </Alert>
            )}

            <form onSubmit={submit} noValidate>
                <FormField
                    label="Email address"
                    type="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    autoComplete="username"
                    autoFocus
                    required
                />

                <FormField
                    label="Password"
                    type="password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    error={errors.password}
                    autoComplete="current-password"
                    required
                />

                <label className="mb-4 flex min-h-touch items-center gap-3">
                    <input
                        type="checkbox"
                        name="remember"
                        checked={data.remember}
                        onChange={(e) => setData('remember', e.target.checked)}
                        className="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                    />
                    <span className="text-base text-gray-800">Keep me signed in on this device</span>
                </label>

                <button
                    type="submit"
                    disabled={processing}
                    className="hupm-nudge inline-flex min-h-touch w-full items-center justify-center rounded-md bg-brand-600 px-5 py-3 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                >
                    {processing ? 'Signing in…' : 'Sign in'}
                </button>
            </form>

            {canResetPassword && (
                <p className="mt-5 text-center">
                    <Link
                        href="/forgot-password"
                        className="inline-flex min-h-touch items-center text-base text-gray-700 underline hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        I have forgotten my password
                    </Link>
                </p>
            )}

            <p className="mt-5 border-t border-gray-200 pt-5 text-base text-gray-700">
                Accounts are set up by the office. If you rent from us and have never signed in,{' '}
                <a
                    href="/contact"
                    className="font-medium underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    ask us for an invitation
                </a>
                .
            </p>
        </GuestLayout>
    );
}

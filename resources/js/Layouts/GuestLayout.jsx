import { usePage } from '@inertiajs/react';

/**
 * The shell for every signed-out screen.  [UI §2.1, §6, §9]
 *
 * This is the door between the public site and the portal, so it is built to
 * look like the same product rather than like the framework's scaffolding it
 * replaced. The teal is the brand token the whole application uses; the indigo
 * that was here came from a generator and matched nothing.
 *
 * Two things are here that a login page does not usually carry, and both earn
 * their place:
 *
 * - **A way back to the public site.** Somebody who arrived by guessing the URL
 *   should not be stuck at a password box.
 * - **The emergency number.** A resident with a burst pipe may well try to sign
 *   in before they think to look for it, and a phone number is a great deal
 *   more use to them at that moment than a form.
 */
export default function GuestLayout({ children, title, intro }) {
    const { company = {} } = usePage().props;

    const dialable = (number) => `tel:${String(number).replace(/[^0-9+]/g, '')}`;

    return (
        <div className="flex min-h-screen flex-col bg-gray-50">
            <header className="border-b border-gray-200 bg-white">
                <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
                    {/* Plain <a>, never Inertia's Link. The public site is
                        Blade and its route group carries no Inertia middleware,
                        so an Inertia visit gets plain HTML back and Inertia
                        renders it in an error overlay instead of navigating.
                        Leaving the app is a real page load. */}
                    <a
                        href="/"
                        className="inline-flex min-h-touch items-center text-lg font-semibold text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        {company.name ?? 'Heads Up Enterprises'}
                    </a>

                    <a
                        href="/"
                        className="hupm-nudge inline-flex min-h-touch items-center text-base text-gray-700 underline hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Back to the website
                    </a>
                </div>
            </header>

            <main className="flex flex-1 items-start justify-center px-4 py-10 sm:items-center sm:py-16">
                <div className="w-full sm:max-w-md">
                    {title && (
                        <div className="mb-6">
                            <h1 className="text-3xl font-semibold tracking-tight text-gray-900">
                                {title}
                            </h1>
                            {intro && (
                                <p className="mt-2 text-base leading-relaxed text-gray-700">{intro}</p>
                            )}
                        </div>
                    )}

                    <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm sm:p-8">
                        {children}
                    </div>

                    {/* Never buried in a list. This is the one piece of
                        information somebody here may be looking for in a hurry. */}
                    <div className="mt-6 rounded-xl border border-overdue-border bg-overdue-bg p-4">
                        <p className="font-semibold text-overdue-fg">Emergency maintenance</p>

                        {company.emergency_phone ? (
                            <p className="mt-1">
                                <a
                                    href={dialable(company.emergency_phone)}
                                    className="inline-flex min-h-touch items-center text-xl font-bold text-overdue-fg underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-overdue-fg"
                                >
                                    {company.emergency_phone}
                                </a>
                            </p>
                        ) : (
                            /* [GATE] company.emergency_phone is unset. Saying so
                               beats a blank space where a number belongs. */
                            <p className="mt-1 text-gray-800">
                                The out-of-hours number has not been published yet.
                                {company.phone && (
                                    <>
                                        {' '}
                                        During office hours, telephone{' '}
                                        <a href={dialable(company.phone)} className="font-semibold underline">
                                            {company.phone}
                                        </a>
                                        .
                                    </>
                                )}
                            </p>
                        )}

                        <p className="mt-1 text-sm text-gray-700">
                            For fire, gas or flooding, call 911 first.
                        </p>
                    </div>
                </div>
            </main>

            <footer className="border-t border-gray-200 px-4 py-6 text-center text-sm text-gray-600">
                &copy; {new Date().getFullYear()} {company.name ?? 'Heads Up Enterprises'}
            </footer>
        </div>
    );
}

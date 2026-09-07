import { Link, usePage } from '@inertiajs/react';
import Money from '@/Components/Money';
import UserMenu from '@/Components/UserMenu';

/**
 * Housing authority portal shell.  [WP-43, D-29]
 *
 * Desktop-leaning, unlike the resident portal: an agency officer works at a
 * desk with a caseload, not on a phone in a corridor.
 *
 * The total in the bar is **the agency's own portion**, and there is no figure
 * anywhere in this shell that belongs to a resident. That is D-29, the mirror
 * of I-4: a resident's own arrears are not this agency's business.
 */
const TABS = [
    { href: '/agency', label: 'Leases', exact: true },
    { href: '/agency/statement', label: 'Statement' },
];

export default function HaLayout({ authority = null, total = null, header, children }) {
    const { url, props } = usePage();
    const flash = props.flash ?? {};

    return (
        <div className="min-h-screen bg-gray-50">
            <header className="border-b border-gray-200 bg-white">
                <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <Link
                        href="/agency"
                        className="text-lg font-semibold text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        {authority?.name ?? 'Housing authority'}
                    </Link>

                    <div className="flex items-center gap-4">
                        {total !== null && (
                            <span className="rounded-full border border-gray-200 px-3 py-1 text-base">
                                <span className="text-gray-600">Owed </span>
                                <Money value={total} balance />
                            </span>
                        )}
                        <UserMenu />
                    </div>
                </div>

                <nav aria-label="Sections" className="mx-auto max-w-5xl px-4">
                    <ul className="flex gap-1">
                        {TABS.map((tab) => {
                            const active = tab.exact ? url === tab.href : url.startsWith(tab.href);

                            return (
                                <li key={tab.href}>
                                    <Link
                                        href={tab.href}
                                        aria-current={active ? 'page' : undefined}
                                        className={`inline-flex min-h-touch items-center border-b-2 px-3 text-base ${
                                            active
                                                ? 'border-brand-600 font-semibold text-brand-700'
                                                : 'border-transparent text-gray-600 hover:text-gray-900'
                                        }`}
                                    >
                                        {tab.label}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </nav>
            </header>

            <main className="mx-auto max-w-5xl px-4 py-6">
                {header && <h1 className="mb-4 text-xl font-semibold text-gray-900">{header}</h1>}

                {flash.status && (
                    <div className="mb-4 rounded-md border border-settled-border bg-settled-bg p-3 text-base text-settled-fg">
                        {flash.status}
                    </div>
                )}

                {children}
            </main>
        </div>
    );
}

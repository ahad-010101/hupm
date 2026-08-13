import { Link, usePage } from '@inertiajs/react';
import Money from '@/Components/Money';

/**
 * Tenant portal shell.  [UI §2.2, §6]
 *
 * Mobile-first, and not as a slogan: the dominant tenant persona is phone-only
 * and low-frequency, so the phone layout is the real one and the desktop bar is
 * the adaptation.
 *
 * Bottom tabs rather than a hamburger — five destinations, thumb-reachable, no
 * hidden navigation for someone who opens the app once a month and should not
 * have to relearn it.
 *
 * The balance chip is persistent on every screen (UI §2.2) so a tenant never
 * has to navigate to find out what they owe. It renders through <Money balance>,
 * so a credit shows as "Credit $X" rather than a minus figure.
 *
 * INVARIANT I-4: nothing in this shell may expose the Housing Authority
 * portion. The balance passed in is the tenant portion only.
 */

const TABS = [
    { href: '/portal', label: 'Home', glyph: '⌂' },
    { href: '/portal/pay', label: 'Pay', glyph: '$' },
    { href: '/portal/maintenance', label: 'Requests', glyph: '✎' },
    { href: '/portal/documents', label: 'Docs', glyph: '▤' },
    { href: '/portal/more', label: 'More', glyph: '⋯' },
];

function isActive(url, href) {
    return href === '/portal' ? url === '/portal' : url.startsWith(href);
}

export default function PortalLayout({ balance = null, pendingAmount = null, header, children }) {
    const { url } = usePage();

    return (
        <div className="min-h-screen bg-gray-50">
            <header className="sticky top-0 z-30 border-b border-gray-200 bg-white">
                <div className="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-3">
                    <Link href="/portal" className="text-lg font-semibold text-gray-900">
                        Heads&nbsp;Up
                    </Link>

                    {/* Top bar appears only above 1024px. UI §6 keeps the tenant
                        on bottom tabs through tablet as well as mobile — the
                        persona is phone-first and the muscle memory should not
                        change on a larger screen. */}
                    <nav aria-label="Portal" className="hidden gap-1 lg:flex">
                        {TABS.slice(0, 4).map((tab) => (
                            <Link
                                key={tab.href}
                                href={tab.href}
                                aria-current={isActive(url, tab.href) ? 'page' : undefined}
                                className={`rounded-md px-3 py-2 text-base font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 ${
                                    isActive(url, tab.href)
                                        ? 'bg-brand-50 text-brand-700'
                                        : 'text-gray-700 hover:bg-gray-100'
                                }`}
                            >
                                {tab.label}
                            </Link>
                        ))}
                    </nav>

                    {balance !== null && (
                        <div className="rounded-full border border-gray-200 bg-gray-50 px-3 py-1.5 text-right">
                            <span className="sr-only">Current balance: </span>
                            <Money value={balance} balance className="text-base" />
                            {/* ACH takes 2–5 business days. Showing the pending
                                amount beside the balance is what stops a tenant
                                concluding the payment failed and paying twice
                                (UI §3.1). */}
                            {pendingAmount && (
                                <span className="block text-xs text-gray-600">
                                    <Money value={pendingAmount} /> processing
                                </span>
                            )}
                        </div>
                    )}
                </div>

                {header && (
                    <div className="mx-auto max-w-5xl px-4 pb-3">
                        <h1 className="text-xl font-semibold text-gray-900">{header}</h1>
                    </div>
                )}
            </header>

            {/* Bottom padding reserves room for the tab bar so the last row of
                content is not trapped underneath it. */}
            <main className="mx-auto max-w-5xl px-4 py-6 pb-[calc(theme(spacing.tab-bar)+1.5rem)] lg:pb-6">
                {children}
            </main>

            <nav
                aria-label="Portal"
                className="fixed inset-x-0 bottom-0 z-30 border-t border-gray-200 bg-white lg:hidden"
            >
                <ul className="mx-auto flex max-w-5xl">
                    {TABS.map((tab) => {
                        const active = isActive(url, tab.href);

                        return (
                            <li key={tab.href} className="flex-1">
                                <Link
                                    href={tab.href}
                                    aria-current={active ? 'page' : undefined}
                                    className={`flex min-h-touch flex-col items-center justify-center gap-0.5 py-2 text-xs font-medium focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-600 ${
                                        active ? 'text-brand-700' : 'text-gray-600'
                                    }`}
                                >
                                    <span aria-hidden="true" className="text-lg leading-none">
                                        {tab.glyph}
                                    </span>
                                    {tab.label}
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </nav>
        </div>
    );
}

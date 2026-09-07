import { Link, usePage } from '@inertiajs/react';
import UserMenu from '@/Components/UserMenu';

/**
 * Contractor portal shell.  [WP-44, UI §6]
 *
 * Phone-first, and more so than the resident portal: a contractor reads this
 * standing at a front door with one hand free. Two destinations, both
 * thumb-reachable, no hidden navigation.
 *
 * **There is no balance chip here and there never will be.** The resident shell
 * carries one because a resident's own balance is the thing they came for; a
 * contractor has no financial relationship with anybody in this system, and the
 * absence is the boundary WP-44 exists to hold.
 */
export default function VendorLayout({ vendor = null, header, children }) {
    const { url, props } = usePage();
    const flash = props.flash ?? {};

    return (
        <div className="min-h-screen bg-gray-50 pb-20">
            <header className="border-b border-gray-200 bg-white">
                <div className="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-3">
                    <Link
                        href="/work"
                        className="text-lg font-semibold text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        {vendor?.name ?? 'Your jobs'}
                    </Link>
                    <UserMenu />
                </div>
            </header>

            <main className="mx-auto max-w-3xl px-4 py-5">
                {header && <h1 className="mb-4 text-xl font-semibold text-gray-900">{header}</h1>}

                {flash.status && (
                    <div className="mb-4 rounded-md border border-settled-border bg-settled-bg p-3 text-base text-settled-fg">
                        {flash.status}
                    </div>
                )}

                {children}
            </main>

            <nav
                aria-label="Sections"
                className="fixed inset-x-0 bottom-0 border-t border-gray-200 bg-white"
            >
                <ul className="mx-auto flex max-w-3xl">
                    {[
                        { href: '/work', label: 'Jobs', glyph: '🔧' },
                    ].map((tab) => (
                        <li key={tab.href} className="flex-1">
                            <Link
                                href={tab.href}
                                aria-current={url.startsWith(tab.href) ? 'page' : undefined}
                                className={`flex min-h-touch flex-col items-center justify-center py-2 text-sm ${
                                    url.startsWith(tab.href)
                                        ? 'font-semibold text-brand-700'
                                        : 'text-gray-600'
                                }`}
                            >
                                <span aria-hidden="true">{tab.glyph}</span>
                                {tab.label}
                            </Link>
                        </li>
                    ))}
                </ul>
            </nav>
        </div>
    );
}

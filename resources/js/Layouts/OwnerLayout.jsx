import { Link, usePage } from '@inertiajs/react';

/**
 * Owner shell.  [UI §2.4]
 *
 * A single top bar and no sidebar, because the role has two screens. Anything
 * more elaborate would imply navigation that does not exist.
 *
 * [GATE Q-11] Whether the owner role ships at all, and what it may see, is
 * unanswered. It is behind the `roles.owner_enabled` setting, default false.
 *
 * AC-DOC-04: an owner reaching any document route gets 403. That is enforced by
 * policy, not by the absence of a link here — a shell that merely omits the
 * navigation is not access control.
 */

const ITEMS = [
    { href: '/owner', label: 'Summary' },
    { href: '/owner/reports', label: 'Reports' },
];

export default function OwnerLayout({ header, children }) {
    const { url } = usePage();

    return (
        <div className="min-h-screen bg-gray-50">
            <header className="border-b border-gray-200 bg-white">
                <div className="mx-auto flex max-w-5xl items-center gap-6 px-4 py-3">
                    <span className="text-lg font-semibold text-gray-900">HUPM</span>

                    <nav aria-label="Owner" className="flex gap-1">
                        {ITEMS.map((item) => {
                            const active = item.href === '/owner' ? url === '/owner' : url.startsWith(item.href);

                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    aria-current={active ? 'page' : undefined}
                                    className={`min-h-touch rounded-md px-3 py-2 text-base font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 ${
                                        active ? 'bg-brand-50 text-brand-700' : 'text-gray-700 hover:bg-gray-100'
                                    }`}
                                >
                                    {item.label}
                                </Link>
                            );
                        })}
                    </nav>
                </div>
            </header>

            <main className="mx-auto max-w-5xl px-4 py-6">
                {header && <h1 className="mb-4 text-xl font-semibold text-gray-900">{header}</h1>}
                {children}
            </main>
        </div>
    );
}

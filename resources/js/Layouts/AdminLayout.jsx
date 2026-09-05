import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import Alert from '@/Components/Alert';
import UserMenu from '@/Components/UserMenu';
import {
    BanknotesIcon,
    BookOpenIcon,
    BuildingOffice2Icon,
    ChartBarIcon,
    ClipboardDocumentCheckIcon,
    Cog6ToothIcon,
    GlobeAltIcon,
    DocumentTextIcon,
    ExclamationTriangleIcon,
    FolderIcon,
    HomeIcon,
    MegaphoneIcon,
    PencilSquareIcon,
    ReceiptPercentIcon,
    ShieldCheckIcon,
    TruckIcon,
    UsersIcon,
    WrenchScrewdriverIcon,
} from '@heroicons/react/24/outline';

/**
 * Admin console shell.  [UI §2.3, §6]
 *
 * Desktop-first, unlike the portal — but the ticket queue and exceptions panel
 * must work on a phone, because the manager triages from the car (UI §6). So
 * the sidebar becomes an off-canvas drawer below 640px rather than disappearing.
 *
 * The exceptions badge is in the top bar on every screen by design: the things
 * it counts (a stale reconciliation, a returned payment, an unmatched
 * remittance) are exactly what goes unnoticed until someone complains.
 */

/** Groups and order are fixed by UI §2.3 — do not reorder to taste. */
const NAV_GROUPS = [
    { label: 'Overview', items: [{ href: '/admin', label: 'Dashboard', icon: HomeIcon }] },
    {
        label: 'Portfolio',
        items: [
            { href: '/admin/properties', label: 'Properties', icon: BuildingOffice2Icon },
            { href: '/admin/tenants', label: 'Tenants', icon: UsersIcon },
            { href: '/admin/leases', label: 'Leases', icon: DocumentTextIcon },
        ],
    },
    {
        label: 'Money',
        items: [
            { href: '/admin/ledger', label: 'Ledger', icon: BookOpenIcon },
            { href: '/admin/charges', label: 'Charges', icon: ReceiptPercentIcon },
            { href: '/admin/payments', label: 'Payments', icon: BanknotesIcon },
            { href: '/admin/delinquency', label: 'Delinquency', icon: ExclamationTriangleIcon },
            { href: '/admin/arrangements', label: 'Arrangements', icon: ClipboardDocumentCheckIcon },
        ],
    },
    {
        label: 'Operations',
        items: [
            { href: '/admin/maintenance', label: 'Maintenance', icon: WrenchScrewdriverIcon },
            { href: '/admin/documents', label: 'Documents', icon: FolderIcon },
            { href: '/admin/signatures', label: 'Signatures', icon: PencilSquareIcon },
            { href: '/admin/notices', label: 'Notices', icon: MegaphoneIcon },
        ],
    },
    {
        label: 'Insight',
        items: [
            { href: '/admin/reports', label: 'Reports', icon: ChartBarIcon },
            { href: '/admin/audit', label: 'Audit', icon: ShieldCheckIcon },
        ],
    },
    {
        label: 'System',
        items: [
            /*
             | Import (API-ADM-36/37) is hidden, not built — client decision,
             | 26 Aug 2026: out of scope for now.
             |
             |   { href: '/admin/import', label: 'Import', icon: ArrowUpTrayIcon },
             |
             | Hiding the link changes nothing about WP-08 itself, which is still
             | the M1 gate and still blocked twice over: the Rent Manager export
             | has not been supplied, and no real tenant financial data moves
             | before the WP-00H host checklist passes. The plan is explicit that
             | the importer is built against that export's real columns rather
             | than an invented shape, so there was nothing to put behind this
             | link that would not have been rebuilt.
             */
            { href: '/admin/vendors', label: 'Vendors', icon: TruckIcon },
            /*
             | Users (API-ADM-39) is hidden, not built — client decision,
             | 26 Aug 2026: out of scope for now.
             |
             | UI §2.3 fixes this group as Import · Vendors · Users · Settings,
             | so this is a deliberate departure from it. Restoring the entry is
             | two lines once the screen exists — the entry below, plus the
             | `UserGroupIcon` import that went with it:
             |
             |   { href: '/admin/users', label: 'Users', icon: UserGroupIcon },
             |
             | Worth knowing while it is gone: nothing in the product can create
             | a staff account, and `AdminUserSeeder` refuses to run outside
             | local. On the host the first admin is a hand-written INSERT, and
             | a departing member of staff is suspended the same way.
             */
            // Public site (WP-36). UI §2.3 fixes this group as Import ·
            // Vendors · Users · Settings; D-27 adds one before Settings, since
            // editing the website is configuration rather than operations.
            { href: '/admin/website', label: 'Public site', icon: GlobeAltIcon },
            { href: '/admin/settings', label: 'Settings', icon: Cog6ToothIcon },
        ],
    },
];

function isActive(url, href) {
    return href === '/admin' ? url === '/admin' : url.startsWith(href);
}

/**
 * @param {boolean} collapsible  When true the labels hide below 1024px, leaving
 *                               an icon rail (UI §6, tablet). The drawer passes
 *                               false because it always has room for labels.
 */
function SidebarNav({ url, onNavigate, collapsible = false }) {
    // Labels stay in the DOM and become sr-only rather than disappearing, so the
    // icon rail is still navigable by screen reader and every link keeps an
    // accessible name.
    const labelClasses = collapsible ? 'sr-only lg:not-sr-only' : '';
    const groupClasses = collapsible ? 'hidden lg:block' : '';

    return (
        <nav aria-label="Admin" className="space-y-6 p-2 lg:p-4">
            {NAV_GROUPS.map((group) => (
                <div key={group.label}>
                    <p
                        className={`mb-1 px-2 text-xs font-semibold uppercase tracking-wider text-gray-500 ${groupClasses}`}
                    >
                        {group.label}
                    </p>
                    <ul className="space-y-0.5">
                        {group.items.map((item) => {
                            const active = isActive(url, item.href);
                            const Icon = item.icon;

                            return (
                                <li key={item.href}>
                                    <Link
                                        href={item.href}
                                        onClick={onNavigate}
                                        aria-current={active ? 'page' : undefined}
                                        title={collapsible ? item.label : undefined}
                                        className={`flex min-h-touch items-center justify-center gap-3 rounded-md px-2 py-2 text-base focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-600 lg:justify-start ${
                                            active
                                                ? 'bg-brand-50 font-semibold text-brand-700'
                                                : 'text-gray-700 hover:bg-gray-100'
                                        }`}
                                    >
                                        <Icon aria-hidden="true" className="h-5 w-5 shrink-0" />
                                        <span className={labelClasses}>{item.label}</span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ))}
        </nav>
    );
}

export default function AdminLayout({ header, children }) {
    const { url, props } = usePage();
    const reconciliation = props.reconciliation;
    // Shared from the server on every admin response, not passed down per page:
    // a badge that is only right on the dashboard lies on every other screen.
    const exceptionCount = props.exceptionSummary?.total ?? 0;
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [term, setTerm] = useState(props.results?.term ?? '');

    const submitSearch = (event) => {
        event.preventDefault();
        router.get('/admin/search', { q: term });
    };

    return (
        <div className="min-h-screen bg-gray-50">
            <header className="sticky top-0 z-30 border-b border-gray-200 bg-white">
                <div className="flex items-center gap-3 px-4 py-3">
                    <button
                        type="button"
                        onClick={() => setDrawerOpen(true)}
                        aria-label="Open navigation"
                        aria-expanded={drawerOpen}
                        className="min-h-touch min-w-touch rounded-md text-gray-700 hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600 sm:hidden"
                    >
                        <span aria-hidden="true" className="text-xl">
                            ☰
                        </span>
                    </button>

                    <Link href="/admin" className="text-lg font-semibold text-gray-900">
                        HUPM
                    </Link>

                    <div className="ml-auto flex items-center gap-3">
                        {/* Submits to a page rather than filtering a dropdown:
                            results are a place with a URL and a back button. */}
                        <form onSubmit={submitSearch} className="hidden md:block">
                            <label htmlFor="admin-search" className="sr-only">
                                Search residents, units and ticket numbers
                            </label>
                            <input
                                id="admin-search"
                                name="q"
                                type="search"
                                value={term}
                                onChange={(event) => setTerm(event.target.value)}
                                placeholder="Search residents, units, tickets…"
                                className="w-64 rounded-md border-gray-300 text-base"
                            />
                        </form>

                        <Link
                            href="/admin/exceptions"
                            className="relative flex min-h-touch min-w-touch items-center justify-center rounded-md px-2 text-gray-700 hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600"
                        >
                            {/* Count is in the accessible name, not only in a
                                coloured dot (UI §9). */}
                            <span aria-hidden="true" className="text-lg">
                                ⚑
                            </span>
                            <span className="sr-only">
                                {exceptionCount} item{exceptionCount === 1 ? '' : 's'} need attention
                            </span>
                            {exceptionCount > 0 && (
                                <span
                                    aria-hidden="true"
                                    className="absolute -right-0.5 -top-0.5 rounded-full bg-overdue-fg px-1.5 text-xs font-semibold text-white"
                                >
                                    {exceptionCount}
                                </span>
                            )}
                        </Link>

                        <UserMenu />
                    </div>
                </div>
            </header>

            <div className="flex">
                {/* Tablet: a 64px icon rail. Desktop: the full sidebar (UI §6).
                    One element rather than two, so the two presentations cannot
                    drift apart. */}
                <aside className="hidden w-16 shrink-0 border-r border-gray-200 bg-white sm:block lg:w-60">
                    <SidebarNav url={url} collapsible />
                </aside>

                {/* Mobile: off-canvas drawer. */}
                {drawerOpen && (
                    <div className="fixed inset-0 z-40 sm:hidden">
                        <button
                            type="button"
                            aria-label="Close navigation"
                            onClick={() => setDrawerOpen(false)}
                            className="absolute inset-0 h-full w-full bg-black/50"
                        />
                        <div className="absolute inset-y-0 left-0 w-72 overflow-y-auto bg-white shadow-xl">
                            <SidebarNav url={url} onNavigate={() => setDrawerOpen(false)} />
                        </div>
                    </div>
                )}

                <main className="min-w-0 flex-1 px-4 py-6">
                    {header && <h1 className="mb-4 text-xl font-semibold text-gray-900">{header}</h1>}

                    {/* R-6 / UI §3.9. Admin-wide, because a reconciliation that
                        stopped is invisible by nature: nothing errors, nothing
                        looks broken, balances just quietly drift from reality.
                        On shared hosting the usual cause is a cron entry with
                        the wrong PHP path, which fails without a sound. */}
                    {reconciliation?.stale && (
                        <Alert
                            tone="error"
                            className="mb-4"
                            title="Payment reconciliation has not run since yesterday"
                        >
                            The last successful run was {reconciliation.hours_ago} hours ago. Until it
                            runs, settled payments will not clear and returned payments will not show.
                            Check the cron entry, then{' '}
                            <Link
                                href="/admin/payments"
                                className="font-semibold underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                            >
                                run it by hand
                            </Link>
                            .
                        </Alert>
                    )}

                    {children}
                </main>
            </div>
        </div>
    );
}

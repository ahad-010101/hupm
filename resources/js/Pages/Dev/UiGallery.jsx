import { useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import axe from 'axe-core';
import AdminLayout from '@/Layouts/AdminLayout';
import OwnerLayout from '@/Layouts/OwnerLayout';
import PortalLayout from '@/Layouts/PortalLayout';
import Alert from '@/Components/Alert';
import ConfirmDialog from '@/Components/ConfirmDialog';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import FormField from '@/Components/FormField';
import Money from '@/Components/Money';
import SkeletonCard from '@/Components/SkeletonCard';
import StatusBadge from '@/Components/StatusBadge';

/**
 * Component gallery — local only.  [WP-05]
 *
 * Exists so the acceptance criteria can be checked by looking rather than by
 * reading source: every component in every state, inside each layout, at every
 * breakpoint. Not a deliverable screen — it never ships, because the route that
 * serves it is registered only in local and testing.
 */

const LAYOUTS = { portal: PortalLayout, admin: AdminLayout, owner: OwnerLayout };

function Section({ title, note, children }) {
    return (
        <section className="mb-8">
            <h2 className="mb-1 text-lg font-semibold text-gray-900">{title}</h2>
            {note && <p className="mb-3 max-w-prose text-sm text-gray-600">{note}</p>}
            <div className="rounded-lg border border-gray-200 bg-white p-4">{children}</div>
        </section>
    );
}

export default function UiGallery({ layout = 'portal' }) {
    const Layout = LAYOUTS[layout] ?? PortalLayout;
    const [dialogOpen, setDialogOpen] = useState(false);
    const [fieldValue, setFieldValue] = useState('');

    // axe is bundled unconditionally rather than behind import.meta.env.DEV,
    // because the assets we actually serve are a production build — a DEV guard
    // would mean the accessibility check never runs on the thing being shipped.
    // The cost is confined to this chunk, and the route serving it exists only
    // in local and testing.
    useEffect(() => {
        window.__axe = () =>
            axe.run(document, { resultTypes: ['violations'] }).then((results) => ({
                critical: results.violations.filter((v) => v.impact === 'critical'),
                serious: results.violations.filter((v) => v.impact === 'serious'),
                moderate: results.violations.filter((v) => v.impact === 'moderate'),
                minor: results.violations.filter((v) => v.impact === 'minor'),
            }));
    }, []);

    const ledgerRows = [
        { id: 1, date: '1 September 2026', description: 'Rent', amount: '300.00', status: 'posted' },
        { id: 2, date: '3 September 2026', description: 'Payment received', amount: '-300.00', status: 'cleared' },
        { id: 3, date: '6 September 2026', description: 'Late fee', amount: '50.00', status: 'posted' },
        { id: 4, date: '8 September 2026', description: 'Payment received', amount: '-75.00', status: 'pending' },
    ];

    const columns = [
        { key: 'date', header: 'Date' },
        { key: 'description', header: 'Description' },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            render: (row) => <Money value={row.amount} />,
        },
        {
            key: 'status',
            header: 'Status',
            align: 'right',
            render: (row) => <StatusBadge status={row.status} />,
        },
    ];

    return (
        <Layout header="Component gallery" balance="-125.00" pendingAmount="75.00" exceptionCount={3}>
            <Head title="Component gallery" />

            <nav aria-label="Gallery layouts" className="mb-6 flex flex-wrap gap-2">
                {Object.keys(LAYOUTS).map((name) => (
                    <Link
                        key={name}
                        href={`/dev/ui/${name}`}
                        aria-current={layout === name ? 'page' : undefined}
                        className={`inline-flex min-h-touch items-center rounded-md border px-3 py-2 text-base capitalize focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 ${
                            layout === name
                                ? 'border-brand-600 bg-brand-50 font-semibold text-brand-700'
                                : 'border-gray-300 bg-white text-gray-700'
                        }`}
                    >
                        {name} layout
                    </Link>
                ))}
            </nav>

            <Section
                title="Money"
                note="Two decimals and a symbol always. A negative balance is 'Credit $X' in green — never a minus figure (UI §7, §8). Values arrive as strings; a number would mean floating-point arithmetic happened upstream."
            >
                <dl className="grid gap-3 sm:grid-cols-2">
                    {[
                        ['Charge', <Money value="300.00" />],
                        ['Whole dollars in', <Money value="5" />],
                        ['One decimal in', <Money value="300.5" />],
                        ['Thousands', <Money value="1234567.89" />],
                        ['Signed ledger row', <Money value="-300.00" />],
                        ['Balance in credit', <Money value="-125.00" balance />],
                        ['Zero', <Money value="0" balance />],
                        ['Unknown', <Money value={null} />],
                    ].map(([label, node]) => (
                        <div key={label} className="flex justify-between gap-4 border-b border-gray-100 pb-2">
                            <dt className="text-sm text-gray-600">{label}</dt>
                            <dd className="text-base">{node}</dd>
                        </div>
                    ))}
                </dl>
            </Section>

            <Section
                title="StatusBadge"
                note="Colour plus text plus glyph, never colour alone (UI §9). A pending ACH payment reads 'Processing' — never 'Failed', which would make a tenant pay twice."
            >
                <div className="flex flex-wrap gap-2">
                    {['pending', 'cleared', 'returned', 'posted', 'void', 'current', 'management_review',
                      'active', 'ended', 'vacant', 'occupied', 'invited', 'not_deliverable'].map((status) => (
                        <StatusBadge key={status} status={status} />
                    ))}
                </div>
            </Section>

            <Section title="Alert" note="Warnings and errors carry role='alert'. Every message says what to do next (UI §8).">
                <div className="space-y-3">
                    <Alert tone="info" title="Payment submitted — processing">
                        ACH payments take 2–5 business days to clear. Your balance updates when it settles.
                    </Alert>
                    <Alert tone="success" title="You're all paid up">Next rent is due 1 September 2026.</Alert>
                    <Alert tone="warning" title="Your account needs attention">
                        Please contact management to arrange payment.
                    </Alert>
                    <Alert tone="error" title="We could not complete that request">
                        Nothing was charged. Please try again, or call the office if it keeps happening.
                    </Alert>
                </div>
            </Section>

            <Section title="DataTable" note="A real table above 640px; stacked cards below it, because a side-scrolling financial table on a phone hides the column that matters (UI §6).">
                <DataTable columns={columns} rows={ledgerRows} caption="Recent account activity" />
            </Section>

            <Section title="EmptyState" note="Never a blank panel — an unexplained empty region reads as a broken page (FS §18.1).">
                <EmptyState
                    title="No documents yet."
                    description="Your lease will appear here once uploaded."
                />
            </Section>

            <Section title="FormField" note="Real labels, aria-live errors, 16px text so iOS does not zoom on focus (UI §6, §9).">
                <div className="max-w-md">
                    <FormField
                        label="Email address"
                        type="email"
                        value={fieldValue}
                        onChange={(e) => setFieldValue(e.target.value)}
                        hint="We use this to send payment receipts."
                        required
                    />
                    <FormField
                        label="Amount"
                        value="12.345"
                        onChange={() => {}}
                        error="Enter an amount with at most two decimal places."
                    />
                </div>
            </Section>

            <Section title="SkeletonCard" note="Shown after 200ms, not immediately — a skeleton that flashes is worse than none (FS §18.2).">
                <div className="grid gap-3 sm:grid-cols-2">
                    <SkeletonCard />
                    <SkeletonCard lines={2} />
                </div>
            </Section>

            <Section title="ConfirmDialog" note="Focus is trapped and returned; Escape cancels. No optimistic UI on financial actions (FS §18.4).">
                <button
                    type="button"
                    onClick={() => setDialogOpen(true)}
                    className="min-h-touch rounded-md bg-brand-600 px-4 py-2 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    Open dialog
                </button>

                <ConfirmDialog
                    open={dialogOpen}
                    title="Record this payment?"
                    confirmLabel="Record payment"
                    tone="danger"
                    onCancel={() => setDialogOpen(false)}
                    onConfirm={() => setDialogOpen(false)}
                >
                    This posts <Money value="300.00" /> to the tenant ledger. Corrections are made with a
                    reversing entry, so this cannot be edited afterwards.
                </ConfirmDialog>
            </Section>
        </Layout>
    );
}

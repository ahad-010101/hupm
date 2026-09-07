import { useMemo, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import HaLayout from '@/Layouts/HaLayout';
import Alert from '@/Components/Alert';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import Money from '@/Components/Money';

/**
 * Every lease an agency funds, and what it owes on each.  [WP-43]
 *
 * **One payment per lease, never a lump sum.** "Pay all" walks the outstanding
 * leases and starts one payment for each, so every payment stays attached to
 * the lease it settles — nothing is ever split afterwards, and reconciliation
 * is untouched. That is the R-9 risk designed out rather than managed.
 *
 * Everything on this page is the agency's own portion (D-29). No figure here
 * belongs to a resident.
 */
export default function Dashboard({ authority, leases = [], total, outstandingCount }) {
    const [busy, setBusy] = useState(null);
    const [error, setError] = useState(null);
    const gatewayForm = useRef(null);
    const [handoff, setHandoff] = useState(null);

    const outstanding = useMemo(
        () => leases.filter((lease) => Number(lease.balance) > 0),
        [leases],
    );

    // One lease at a time. The gateway takes over the page on the first, and
    // the agency returns to finish the rest — which is honest about what is
    // happening rather than pretending seventeen debits are one.
    const pay = async (lease) => {
        setBusy(lease.id);
        setError(null);

        try {
            const { data } = await window.axios.post('/agency/pay', {
                lease_id: lease.id,
                amount: lease.balance,
                idempotency_key: crypto.randomUUID(),
            });

            setHandoff(data);
            requestAnimationFrame(() => gatewayForm.current?.submit());
        } catch (failure) {
            setError(
                failure.response?.data?.message ??
                    'We could not start that payment. Nothing has been charged.',
            );
            setBusy(null);
        }
    };

    const columns = [
        {
            key: 'property',
            header: 'Property',
            render: (l) => (
                <span>
                    <span className="font-medium text-gray-900">{l.property}</span>
                    <span className="block text-sm text-gray-600">
                        Unit {l.unit} · {l.resident}
                        {l.hap_contract_number ? ` · HAP ${l.hap_contract_number}` : ''}
                    </span>
                </span>
            ),
        },
        {
            key: 'monthly_portion',
            header: 'Monthly',
            align: 'right',
            render: (l) => <Money value={l.monthly_portion} />,
        },
        {
            key: 'balance',
            header: 'Owed now',
            align: 'right',
            render: (l) => <Money value={l.balance} balance />,
        },
        {
            key: 'actions',
            header: 'Actions',
            align: 'right',
            render: (l) =>
                Number(l.balance) > 0 ? (
                    <button
                        type="button"
                        disabled={busy !== null}
                        onClick={() => pay(l)}
                        className="underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                    >
                        {busy === l.id ? 'Opening…' : 'Pay'}
                    </button>
                ) : (
                    <span className="text-sm text-gray-600">Nothing due</span>
                ),
        },
    ];

    return (
        <HaLayout authority={authority} total={total} header="Leases you fund">
            <Head title="Leases you fund" />

            {error && (
                <Alert tone="error" className="mb-4" title="We could not start that payment">
                    {error}
                </Alert>
            )}

            {outstanding.length > 0 && (
                <section className="mb-6 rounded-lg border border-gray-200 bg-white p-4">
                    <p className="text-base text-gray-800">
                        <strong>{outstandingCount}</strong>{' '}
                        {outstandingCount === 1 ? 'lease has' : 'leases have'} an outstanding
                        amount, <Money value={total} /> in total.
                    </p>
                    <p className="mt-1 text-sm text-gray-600">
                        Each lease is paid separately, so every payment is matched to its own
                        tenancy. Start with the first and you will be brought back here for the rest.
                    </p>
                    <button
                        type="button"
                        disabled={busy !== null}
                        onClick={() => pay(outstanding[0])}
                        className="mt-3 inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                    >
                        {busy !== null ? 'Opening…' : `Pay ${outstanding[0].property}, unit ${outstanding[0].unit}`}
                    </button>
                </section>
            )}

            <DataTable
                columns={columns}
                rows={leases}
                caption="Leases funded by this authority"
                empty={
                    <EmptyState
                        title="No active leases."
                        description="Leases funded by this authority will appear here."
                    />
                }
            />

            {/* Accept Hosted expects the token in a POST body, so the hand-off is
                a real form submission rather than a redirect. */}
            {handoff && (
                <form ref={gatewayForm} method="POST" action={handoff.redirect_url} className="hidden">
                    <input type="hidden" name="token" value={handoff.hosted_token} />
                </form>
            )}
        </HaLayout>
    );
}

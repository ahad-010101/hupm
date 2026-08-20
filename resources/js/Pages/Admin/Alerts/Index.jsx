import { Head, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Alert from '@/Components/Alert';
import EmptyState from '@/Components/EmptyState';
import FormField from '@/Components/FormField';
import StatusBadge from '@/Components/StatusBadge';

/**
 * Weather and emergency alerts.  [FR-NTF-03, UI §3.1 banner]
 *
 * Two sources, one list: what the National Weather Service issued, and what an
 * admin issued by hand. Residents see both in the same dashboard banner, so
 * they are shown together here too.
 */

const SUGGESTED = [
    'Water shut off',
    'Power outage',
    'Gas leak',
    'Lift out of service',
    'Building access restricted',
    'Emergency repair in progress',
];

export default function Index({ alerts = [], properties = [], flash = {} }) {
    const issue = useForm({ property_id: '', event_type: '', headline: '', expires_at: '' });
    const poll = useForm({});

    const withoutCounty = properties.filter((p) => p.weather_capable && !p.county);

    return (
        <AdminLayout header="Alerts">
            <Head title="Alerts" />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}
            {flash.error && <Alert tone="error" className="mb-4">{flash.error}</Alert>}

            {withoutCounty.length > 0 && (
                <Alert tone="info" className="mb-4" title="Some properties have no county recorded">
                    {/* Over-alerting teaches people to ignore the banner, which
                        is the one thing it cannot afford. */}
                    {withoutCounty.length}{' '}
                    {withoutCounty.length === 1 ? 'property' : 'properties'} will receive any alert
                    issued for the whole state, because there is no county to narrow it to. Adding
                    the county on the property record fixes that.
                </Alert>
            )}

            <section className="mb-6 rounded-lg border border-gray-200 bg-white p-5">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-lg font-semibold text-gray-900">Issue an alert</h2>
                    <button
                        type="button"
                        disabled={poll.processing}
                        onClick={() => poll.post('/admin/alerts/poll', { preserveScroll: true })}
                        className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                    >
                        {poll.processing ? 'Checking…' : 'Check the weather service now'}
                    </button>
                </div>

                <p className="mb-4 text-base text-gray-700">
                    For anything the weather service does not cover — a burst pipe, a power cut, a
                    lift out of service. Residents at that property are emailed immediately and see
                    it on their dashboard.
                </p>

                <div className="grid gap-x-4 sm:grid-cols-2">
                    <FormField label="Property" error={issue.errors.property_id} required>
                        <select
                            value={issue.data.property_id}
                            onChange={(e) => issue.setData('property_id', e.target.value)}
                            className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                        >
                            <option value="">Choose a property…</option>
                            {properties.map((p) => (
                                <option key={p.id} value={p.id}>{p.name}</option>
                            ))}
                        </select>
                    </FormField>

                    <FormField
                        label="What is happening"
                        value={issue.data.event_type}
                        onChange={(e) => issue.setData('event_type', e.target.value)}
                        error={issue.errors.event_type}
                        list="alert-suggestions"
                        maxLength={100}
                        required
                    />
                    <datalist id="alert-suggestions">
                        {SUGGESTED.map((s) => <option key={s} value={s} />)}
                    </datalist>
                </div>

                <FormField
                    label="What residents need to know"
                    value={issue.data.headline}
                    onChange={(e) => issue.setData('headline', e.target.value)}
                    error={issue.errors.headline}
                    hint="Say what is happening and what they should do. This is the whole message."
                    maxLength={500}
                    required
                />

                <FormField
                    label="In effect until"
                    type="datetime-local"
                    value={issue.data.expires_at}
                    onChange={(e) => issue.setData('expires_at', e.target.value)}
                    error={issue.errors.expires_at}
                    hint="Optional. After this it stops showing on their dashboard."
                />

                <button
                    type="button"
                    disabled={issue.processing || !issue.data.property_id || !issue.data.headline}
                    onClick={() => issue.post('/admin/alerts', {
                        preserveScroll: true,
                        onSuccess: () => issue.reset('event_type', 'headline', 'expires_at'),
                    })}
                    className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 disabled:opacity-60"
                >
                    {issue.processing ? 'Sending…' : 'Issue alert and notify residents'}
                </button>
            </section>

            <h2 className="mb-3 text-lg font-semibold text-gray-900">Recent alerts</h2>

            {alerts.length === 0 ? (
                <EmptyState
                    title="Nothing active."
                    description="The weather service is checked every hour. Anything it issues for your properties appears here."
                />
            ) : (
                <ul className="space-y-2">
                    {alerts.map((alert) => (
                        <li key={alert.id} className="rounded-lg border border-gray-200 bg-white p-4">
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <span className="text-base font-semibold text-gray-900">{alert.event}</span>
                                <span className="flex flex-wrap items-center gap-2">
                                    {/* Never colour alone (UI §9). */}
                                    <StatusBadge
                                        status={alert.live ? 'active' : 'ended'}
                                        label={alert.live ? 'In effect' : 'Ended'}
                                        tone={alert.live ? 'overdue' : 'neutral'}
                                    />
                                    <StatusBadge
                                        status={alert.manual ? 'manual' : 'nws'}
                                        label={alert.manual ? 'Issued by the office' : 'Weather service'}
                                        tone="neutral"
                                    />
                                </span>
                            </div>

                            <p className="mt-1 text-base text-gray-700">{alert.property}</p>
                            {alert.headline && (
                                <p className="mt-1 text-base text-gray-700">{alert.headline}</p>
                            )}

                            <p className="mt-2 text-sm text-gray-600">
                                {alert.severity && <>{alert.severity} · </>}
                                {alert.expires_on ? <>until {alert.expires_on}</> : 'no end time given'}
                                {alert.notified_on && <> · residents notified {alert.notified_on}</>}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </AdminLayout>
    );
}

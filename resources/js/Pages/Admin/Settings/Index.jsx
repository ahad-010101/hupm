import { useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Alert from '@/Components/Alert';

/**
 * System settings, and the decisions still waiting on an answer.  [API-ADM-40]
 *
 * Two things happen on this screen and they are deliberately not the same
 * control. **Changing** a setting alters how the system behaves. **Confirming**
 * one records that the client has answered the question behind it — and
 * confirming the shipped default is a perfectly good answer. Go-live blocks on
 * the confirmations, not on the values, so a screen that conflated them would
 * leave no way to say "yes, that one is right as it is".
 *
 * Warnings sit next to the control rather than in a manual. "Do not switch this
 * on before an attorney has read the fee terms" is only useful at the moment
 * somebody reaches for the switch.
 */

function Field({ setting, onSaved }) {
    const form = useForm({ key: setting.key, value: setting.value });
    const dirty = form.data.value !== setting.value;

    const save = (event) => {
        event.preventDefault();
        form.patch('/admin/settings', { preserveScroll: true, onSuccess: onSaved });
    };

    const control = () => {
        if (setting.input === 'bool') {
            return (
                <select
                    value={form.data.value}
                    onChange={(e) => form.setData('value', e.target.value)}
                    className="min-h-touch w-full rounded-md border-gray-300 text-base sm:w-64"
                >
                    <option value="true">On</option>
                    <option value="false">Off</option>
                </select>
            );
        }

        if (setting.input === 'select') {
            return (
                <select
                    value={form.data.value}
                    onChange={(e) => form.setData('value', e.target.value)}
                    className="min-h-touch w-full rounded-md border-gray-300 text-base sm:w-96"
                >
                    {Object.entries(setting.options ?? {}).map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </select>
            );
        }

        return (
            <input
                type={setting.input === 'number' ? 'number' : 'text'}
                value={form.data.value}
                min={setting.min ?? undefined}
                max={setting.max ?? undefined}
                onChange={(e) => form.setData('value', e.target.value)}
                className="min-h-touch w-full rounded-md border-gray-300 text-base sm:w-96"
            />
        );
    };

    return (
        <form onSubmit={save} className="border-t border-gray-200 p-4 first:border-t-0">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <label className="block text-base font-semibold text-gray-900">{setting.label}</label>
                    <p className="mt-0.5 max-w-prose text-base text-gray-600">{setting.help}</p>
                </div>

                {setting.gated && (
                    <span
                        className={`shrink-0 rounded-full px-2.5 py-0.5 text-sm font-medium ${
                            setting.confirmed_at
                                ? 'bg-settled-bg text-settled-fg'
                                : 'bg-due-bg text-due-fg'
                        }`}
                    >
                        {setting.confirmed_at ? '✓ Confirmed' : '◷ Awaiting the client'}
                    </span>
                )}
            </div>

            {setting.warning && (
                <Alert tone="warning" className="mt-3">
                    {setting.warning}
                </Alert>
            )}

            <div className="mt-3 flex flex-wrap items-center gap-2">
                {control()}

                <button
                    type="submit"
                    disabled={!dirty || form.processing}
                    className="min-h-touch rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-40"
                >
                    {form.processing ? 'Saving…' : 'Save'}
                </button>

                {dirty && (
                    <button
                        type="button"
                        onClick={() => form.setData('value', setting.value)}
                        className="min-h-touch rounded-md border border-gray-300 px-3 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Cancel
                    </button>
                )}
            </div>

            {form.errors.value && <p className="mt-2 text-base text-overdue-fg">{form.errors.value}</p>}

            <p className="mt-2 font-mono text-xs text-gray-500">{setting.reference || setting.key}</p>

            {setting.gated && !setting.confirmed_at && <ConfirmDecision setting={setting} onSaved={onSaved} />}

            {setting.gated && setting.confirmed_at && (
                <p className="mt-2 text-sm text-gray-600">
                    Confirmed by {setting.confirmed_by ?? 'an administrator'} on{' '}
                    {new Date(setting.confirmed_at).toLocaleDateString('en-GB', {
                        day: 'numeric',
                        month: 'long',
                        year: 'numeric',
                    })}
                    .
                </p>
            )}
        </form>
    );
}

function ConfirmDecision({ setting, onSaved }) {
    const form = useForm({ key: setting.key });
    const [asking, setAsking] = useState(false);

    const confirm = () => {
        form.post('/admin/settings/confirm', { preserveScroll: true, onSuccess: onSaved });
    };

    if (!asking) {
        return (
            <button
                type="button"
                onClick={() => setAsking(true)}
                className="mt-3 inline-flex min-h-touch items-center rounded-md border border-gray-300 px-3 text-base font-medium text-gray-800 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
            >
                Mark as agreed with the client
            </button>
        );
    }

    return (
        <Alert tone="info" className="mt-3" title="Has the client agreed this?">
            <p className="mb-3">
                This records that the question behind this setting has been answered — whether the
                answer was to change it or to leave it as it is. It stops blocking go-live, and who
                confirmed it goes on the record.
            </p>
            <div className="flex flex-wrap gap-2">
                <button
                    type="button"
                    onClick={confirm}
                    disabled={form.processing}
                    className="min-h-touch rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-50"
                >
                    {form.processing ? 'Recording…' : 'Yes, they have agreed'}
                </button>
                <button
                    type="button"
                    onClick={() => setAsking(false)}
                    className="min-h-touch rounded-md border border-gray-300 px-3 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    Not yet
                </button>
            </div>
        </Alert>
    );
}

export default function Index({ settings = [], groups = [], unconfirmed = [] }) {
    const { flash } = usePage().props;
    const [savedAt, setSavedAt] = useState(null);

    return (
        <AdminLayout header="Settings">
            <Head title="Settings" />

            {flash?.status && (
                <Alert tone="success" className="mb-4">
                    {flash.status}
                </Alert>
            )}

            {unconfirmed.length > 0 ? (
                <Alert
                    tone="warning"
                    className="mb-5"
                    title={`${unconfirmed.length} decision${unconfirmed.length === 1 ? '' : 's'} still waiting on the client`}
                >
                    <p className="mb-2">
                        Each of these ships with a sensible default and is a setting rather than
                        something written into the code, so a late answer costs a configuration
                        change. <strong>Go-live cannot complete while any of them is unconfirmed</strong> —
                        not because the system will not run, but because nobody has agreed how it
                        should behave.
                    </p>
                    <p>
                        Confirming the default counts as an answer. The point is that somebody
                        decided, not that anything changed.
                    </p>
                </Alert>
            ) : (
                <Alert tone="success" className="mb-5" title="Every gated decision has been answered">
                    Nothing here is blocking go-live.
                </Alert>
            )}

            <div className="space-y-6">
                {groups.map((group) => (
                    <section key={group} className="rounded-lg border border-gray-200 bg-white">
                        <h2 className="border-b border-gray-200 px-4 py-3 text-base font-semibold text-gray-900">
                            {group}
                        </h2>

                        <div>
                            {settings
                                .filter((s) => s.group === group)
                                .map((setting) => (
                                    <Field
                                        key={setting.key}
                                        setting={setting}
                                        onSaved={() => setSavedAt(Date.now())}
                                    />
                                ))}
                        </div>
                    </section>
                ))}
            </div>

            <p className="mt-5 max-w-prose text-sm text-gray-600">
                Every change here is recorded — what it was, what it became and who did it. These
                values decide how money is applied, when an account is chased and what a resident is
                charged, so the history matters as much as the current setting.
            </p>
        </AdminLayout>
    );
}

import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import FormField from '@/Components/FormField';
import Alert from '@/Components/Alert';
import StatusBadge from '@/Components/StatusBadge';
import ConfirmDialog from '@/Components/ConfirmDialog';

/**
 * One public page.  [WP-36, D-27]
 *
 * Page settings, then an ordered list of sections. Every field on every section
 * is generated from the catalogue the server sent, so **a new section type is a
 * catalogue entry and a Blade partial — no React at all.**
 *
 * Each section is its own form, submitted on its own. One page-wide form would
 * throw away every edit on the page whenever a single section failed validation.
 */

/**
 * One control, chosen by the catalogue's field kind.
 *
 * `...rest` matters: FormField clones its child to give it the id its
 * <label for> points at, plus the aria wiring. A wrapper that swallows those
 * props leaves an unlabelled control that looks perfectly labelled on screen.
 */
function Field({ spec, value, onChange, routeOptions, ...rest }) {
    const shared =
        'block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600';

    if (spec.input === 'route') {
        return (
            <select
                {...rest}
                value={value ?? ''}
                onChange={(e) => onChange(e.target.value)}
                className={`min-h-touch ${shared}`}
            >
                {/* Blank is meaningful: it hides the button entirely. */}
                <option value="">No button</option>
                {Object.entries(routeOptions).map(([name, label]) => (
                    <option key={name} value={name}>
                        {label}
                    </option>
                ))}
            </select>
        );
    }

    if (spec.input === 'textarea' || spec.input === 'lines' || spec.input === 'rich') {
        return (
            <textarea
                {...rest}
                value={value ?? ''}
                onChange={(e) => onChange(e.target.value)}
                rows={spec.input === 'rich' ? 10 : 4}
                maxLength={spec.max ?? 4000}
                className={shared}
            />
        );
    }

    return (
        <input
            {...rest}
            type="text"
            value={value ?? ''}
            onChange={(e) => onChange(e.target.value)}
            maxLength={spec.max ?? 255}
            className={`min-h-touch ${shared}`}
        />
    );
}

function SectionCard({ page, section, spec, routeOptions, index, total }) {
    const [open, setOpen] = useState(false);
    const [confirming, setConfirming] = useState(false);

    const form = useForm({
        is_enabled: section.is_enabled,
        payload: section.payload ?? {},
    });

    const setField = (key, value) => form.setData('payload', { ...form.data.payload, [key]: value });

    const setItem = (i, key, value) => {
        const items = [...(form.data.payload.items ?? [])];
        items[i] = { ...items[i], [key]: value };
        setField('items', items);
    };

    const addItem = () => setField('items', [...(form.data.payload.items ?? []), {}]);

    const removeItem = (i) =>
        setField(
            'items',
            (form.data.payload.items ?? []).filter((_, at) => at !== i),
        );

    const save = (e) => {
        e.preventDefault();
        form.patch(`/admin/website/${page.slug}/sections/${section.id}`, { preserveScroll: true });
    };

    const move = (direction) =>
        router.post(
            `/admin/website/${page.slug}/sections/${section.id}/move`,
            { direction },
            { preserveScroll: true },
        );

    const remove = () =>
        router.delete(`/admin/website/${page.slug}/sections/${section.id}`, { preserveScroll: true });

    const items = form.data.payload.items ?? [];

    return (
        <li className="rounded-lg border border-gray-200 bg-white">
            <div className="flex flex-wrap items-center gap-3 p-4">
                <div className="min-w-0 flex-1">
                    <p className="flex flex-wrap items-center gap-2 font-semibold text-gray-900">
                        {spec?.label ?? section.type}
                        {!section.is_enabled && (
                            <StatusBadge status="hidden" label="Hidden" tone="neutral" />
                        )}
                    </p>
                    <p className="mt-0.5 truncate text-sm text-gray-600">{section.summary}</p>
                </div>

                {/* Move buttons rather than drag-and-drop: no drag library is
                    installed, and buttons work from a keyboard, from a screen
                    reader and from a phone — which is where this console is
                    actually used. */}
                <div className="flex gap-1">
                    <button
                        type="button"
                        onClick={() => move('up')}
                        disabled={index === 0}
                        aria-label={`Move ${spec?.label ?? section.type} up`}
                        className="inline-flex min-h-touch min-w-touch items-center justify-center rounded-md border border-gray-300 text-lg hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-40"
                    >
                        <span aria-hidden="true">↑</span>
                    </button>
                    <button
                        type="button"
                        onClick={() => move('down')}
                        disabled={index === total - 1}
                        aria-label={`Move ${spec?.label ?? section.type} down`}
                        className="inline-flex min-h-touch min-w-touch items-center justify-center rounded-md border border-gray-300 text-lg hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-40"
                    >
                        <span aria-hidden="true">↓</span>
                    </button>
                    <button
                        type="button"
                        onClick={() => setOpen((was) => !was)}
                        aria-expanded={open}
                        className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-3 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        {open ? 'Close' : 'Edit'}
                    </button>
                </div>
            </div>

            {open && (
                <form onSubmit={save} className="border-t border-gray-200 p-4">
                    {spec?.blurb && <p className="mb-4 text-sm text-gray-600">{spec.blurb}</p>}

                    {form.errors.payload && (
                        <Alert tone="error" title="This section was not saved" className="mb-4">
                            {form.errors.payload}
                        </Alert>
                    )}

                    {Object.entries(spec?.fields ?? {}).map(([key, field]) => (
                        <FormField key={key} label={field.label} hint={field.help}>
                            <Field
                                spec={field}
                                value={form.data.payload[key]}
                                onChange={(value) => setField(key, value)}
                                routeOptions={routeOptions}
                            />
                        </FormField>
                    ))}

                    {spec?.items && (
                        <fieldset className="mt-2">
                            <legend className="mb-2 text-base font-semibold">{spec.items.label}</legend>

                            <ol className="space-y-4">
                                {items.map((item, i) => (
                                    <li key={i} className="rounded-md border border-gray-200 p-4">
                                        <div className="mb-2 flex items-center justify-between">
                                            <span className="text-sm font-medium text-gray-600">
                                                {spec.items.label} {i + 1}
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() => removeItem(i)}
                                                className="inline-flex min-h-touch items-center rounded-md px-2 text-sm text-overdue-fg underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-overdue-fg"
                                            >
                                                Remove
                                            </button>
                                        </div>

                                        {Object.entries(spec.items.fields).map(([key, field]) => (
                                            <FormField key={key} label={field.label} hint={field.help}>
                                                <Field
                                                    spec={field}
                                                    value={item[key]}
                                                    onChange={(value) => setItem(i, key, value)}
                                                    routeOptions={routeOptions}
                                                />
                                            </FormField>
                                        ))}
                                    </li>
                                ))}
                            </ol>

                            {items.length < spec.items.max && (
                                <button
                                    type="button"
                                    onClick={addItem}
                                    className="mt-3 inline-flex min-h-touch items-center rounded-md border border-gray-300 px-3 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                >
                                    Add {spec.items.label.toLowerCase().replace(/s$/, '')}
                                </button>
                            )}
                        </fieldset>
                    )}

                    <label className="mt-4 flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={form.data.is_enabled}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                            className="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                        />
                        <span className="text-base">Show this section on the page</span>
                    </label>

                    <div className="mt-4 flex flex-wrap gap-3">
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                        >
                            {form.processing ? 'Saving…' : 'Save section'}
                        </button>

                        <button
                            type="button"
                            onClick={() => setConfirming(true)}
                            className="inline-flex min-h-touch items-center rounded-md border border-overdue-border px-4 text-base font-medium text-overdue-fg hover:bg-overdue-bg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-overdue-fg"
                        >
                            Remove section
                        </button>
                    </div>
                </form>
            )}

            <ConfirmDialog
                open={confirming}
                title="Remove this section?"
                confirmLabel="Remove"
                onCancel={() => setConfirming(false)}
                onConfirm={() => {
                    setConfirming(false);
                    remove();
                }}
            >
                The words in it are deleted. If you only want it off the page for now, close this and
                untick “Show this section” instead.
            </ConfirmDialog>
        </li>
    );
}

export default function Edit({ page, sections = [], catalogue = {}, routeOptions = {}, flash = {} }) {
    const settings = useForm({
        title: page.title ?? '',
        nav_label: page.nav_label ?? '',
        meta_description: page.meta_description ?? '',
        is_published: page.is_published,
        show_in_nav: page.show_in_nav,
        nav_position: page.nav_position ?? 0,
    });

    const [adding, setAdding] = useState('');

    const saveSettings = (e) => {
        e.preventDefault();
        settings.patch(`/admin/website/${page.slug}`, { preserveScroll: true });
    };

    const addSection = () => {
        if (!adding) return;
        router.post(`/admin/website/${page.slug}/sections`, { type: adding }, { preserveScroll: true });
        setAdding('');
    };

    return (
        <AdminLayout header={page.title}>
            <Head title={`${page.title} — Public site`} />

            <div className="mb-4 flex flex-wrap items-center gap-3">
                <Link
                    href="/admin/website"
                    className="inline-flex min-h-touch items-center text-base underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    All pages
                </Link>

                {page.url && (
                    /* A plain link, not Inertia: this leaves the console for the
                       public site, and opening it in the same tab would lose the
                       editor's place. */
                    <a
                        href={page.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex min-h-touch items-center text-base underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        View this page
                        <span className="sr-only">(opens in a new tab)</span>
                    </a>
                )}
            </div>

            {flash.status && (
                <Alert tone="success" title="Saved" className="mb-4">
                    {flash.status}
                </Alert>
            )}

            <section className="mb-8 rounded-lg border border-gray-200 bg-white p-4">
                <h2 className="mb-4 text-lg font-semibold">Page settings</h2>

                <form onSubmit={saveSettings} className="max-w-2xl">
                    <FormField
                        label="Title"
                        value={settings.data.title}
                        onChange={(e) => settings.setData('title', e.target.value)}
                        error={settings.errors.title}
                        hint="Shown in the browser tab and in search results."
                        maxLength={150}
                        required
                    />

                    <FormField
                        label="Menu label"
                        value={settings.data.nav_label}
                        onChange={(e) => settings.setData('nav_label', e.target.value)}
                        error={settings.errors.nav_label}
                        hint="Optional. A shorter name for the menu. Blank uses the title."
                        maxLength={40}
                    />

                    <FormField
                        label="Search description"
                        error={settings.errors.meta_description}
                        hint={`Shown under the title in search results. ${settings.data.meta_description.length} of 160 characters.`}
                    >
                        <textarea
                            value={settings.data.meta_description}
                            onChange={(e) => settings.setData('meta_description', e.target.value)}
                            rows={3}
                            maxLength={160}
                            className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                        />
                    </FormField>

                    <div className="flex flex-wrap gap-6">
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={settings.data.is_published}
                                onChange={(e) => settings.setData('is_published', e.target.checked)}
                                className="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                            />
                            <span className="text-base">Published</span>
                        </label>

                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={settings.data.show_in_nav}
                                onChange={(e) => settings.setData('show_in_nav', e.target.checked)}
                                className="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                            />
                            <span className="text-base">Show in the menu</span>
                        </label>
                    </div>

                    <button
                        type="submit"
                        disabled={settings.processing}
                        className="mt-4 inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                    >
                        {settings.processing ? 'Saving…' : 'Save settings'}
                    </button>
                </form>
            </section>

            <section>
                <h2 className="mb-1 text-lg font-semibold">Sections</h2>
                <p className="mb-4 max-w-3xl text-base text-gray-700">
                    The page is built from these, top to bottom. A new section arrives hidden, so you
                    can write it before anybody sees it.
                </p>

                <ol className="space-y-3">
                    {sections.map((section, index) => (
                        <SectionCard
                            key={section.id}
                            page={page}
                            section={section}
                            spec={catalogue[section.type]}
                            routeOptions={routeOptions}
                            index={index}
                            total={sections.length}
                        />
                    ))}
                </ol>

                <div className="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-dashed border-gray-300 p-4">
                    <label className="text-sm">
                        <span className="mb-1 block font-medium text-gray-700">Add a section</span>
                        <select
                            value={adding}
                            onChange={(e) => setAdding(e.target.value)}
                            className="min-h-touch w-full rounded-md border-gray-300 text-base sm:w-72"
                        >
                            <option value="">Choose a kind…</option>
                            {Object.entries(catalogue).map(([type, spec]) => (
                                <option key={type} value={type}>
                                    {spec.label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <button
                        type="button"
                        onClick={addSection}
                        disabled={!adding}
                        className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-50"
                    >
                        Add to this page
                    </button>

                    {adding && catalogue[adding] && (
                        <p className="w-full text-sm text-gray-600">{catalogue[adding].blurb}</p>
                    )}
                </div>
            </section>
        </AdminLayout>
    );
}

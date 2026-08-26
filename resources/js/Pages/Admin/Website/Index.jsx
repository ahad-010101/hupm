import { Head, Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';

/**
 * The public site.  [WP-36, D-27]
 *
 * A fixed list. Pages are edited, never created or deleted — `routes/public.php`
 * stays the complete list of public URLs, so there is no "Add page" here and
 * that is the point rather than an omission.
 */
export default function Index({ pages = [] }) {
    const columns = [
        {
            key: 'title',
            header: 'Page',
            render: (page) => (
                <Link
                    href={`/admin/website/${page.slug}`}
                    className="font-medium text-gray-900 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    {page.title}
                </Link>
            ),
        },
        {
            key: 'url',
            header: 'Address',
            hideOnMobile: true,
            render: (page) =>
                page.url ? (
                    <span className="font-mono text-sm text-gray-600">
                        {page.url.replace(/^https?:\/\/[^/]+/, '') || '/'}
                    </span>
                ) : (
                    <span className="text-gray-500">—</span>
                ),
        },
        {
            key: 'is_published',
            header: 'Status',
            render: (page) => (
                <StatusBadge
                    status={page.is_published ? 'published' : 'hidden'}
                    label={page.is_published ? 'Published' : 'Hidden'}
                    tone={page.is_published ? 'settled' : 'neutral'}
                />
            ),
        },
        {
            key: 'sections',
            header: 'Content',
            render: (page) => (
                <span className="text-gray-700">
                    {page.sections} {page.sections === 1 ? 'section' : 'sections'}
                    {page.hidden > 0 && (
                        <span className="block text-sm text-gray-600">{page.hidden} hidden</span>
                    )}
                </span>
            ),
        },
        {
            key: 'nav',
            header: 'In the menu',
            hideOnMobile: true,
            render: (page) => (page.show_in_nav ? page.nav_label : <span className="text-gray-500">—</span>),
        },
    ];

    return (
        <AdminLayout header="Public site">
            <Head title="Public site" />

            <p className="mb-6 max-w-3xl text-base text-gray-700">
                The pages anyone can read without signing in. Change any of the words on them here —
                no deploy, no developer. The addresses themselves are fixed in the application, so a
                page cannot be added or removed from this screen.
            </p>

            <DataTable
                caption="Public pages"
                columns={columns}
                rows={pages}
                rowKey={(page) => page.slug}
                empty={
                    <EmptyState
                        title="No pages yet."
                        description="Run the content seeder to install the shipped copy."
                    />
                }
            />
        </AdminLayout>
    );
}

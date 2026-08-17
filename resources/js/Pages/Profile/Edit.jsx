import { Head, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import OwnerLayout from '@/Layouts/OwnerLayout';
import PortalLayout from '@/Layouts/PortalLayout';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

/**
 * Account settings.
 *
 * Renders inside whichever shell the signed-in role belongs to. It previously
 * used Breeze's AuthenticatedLayout, which was deleted: that layout linked to
 * `route('dashboard')`, a route removed in WP-04 when each role gained its own
 * home, so the page was already broken for every role.
 */
const LAYOUTS = { admin: AdminLayout, owner: OwnerLayout, tenant: PortalLayout };

export default function Edit({ mustVerifyEmail, status }) {
    const { auth } = usePage().props;
    const Layout = LAYOUTS[auth?.user?.role] ?? PortalLayout;

    return (
        <Layout header="Your account settings">
            <Head title="Account settings" />

            <div className="max-w-2xl space-y-6">
                <section className="rounded-lg border border-gray-200 bg-white p-6">
                    <UpdateProfileInformationForm mustVerifyEmail={mustVerifyEmail} status={status} />
                </section>

                <section className="rounded-lg border border-gray-200 bg-white p-6">
                    <UpdatePasswordForm />
                </section>

                <section className="rounded-lg border border-gray-200 bg-white p-6">
                    <DeleteUserForm />
                </section>
            </div>
        </Layout>
    );
}

import { Head } from '@inertiajs/react';
import OwnerLayout from '@/Layouts/OwnerLayout';
import Alert from '@/Components/Alert';

/** [GATE Q-11] Whether the owner role ships, and its scope, is unanswered. */
export default function Summary() {
    return (
        <OwnerLayout header="Summary">
            <Head title="Summary" />

            <Alert tone="info" title="Owner view">
                Portfolio summary and reports arrive in WP-28, once the scope of this role is
                confirmed.
            </Alert>
        </OwnerLayout>
    );
}

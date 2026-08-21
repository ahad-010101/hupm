import { Link, useForm, usePage } from '@inertiajs/react';
import Dropdown from '@/Components/Dropdown';

/**
 * Who is signed in, and how to stop being signed in.
 *
 * Both shells were built without one, so `/logout` and `/profile` existed as
 * routes that nothing on any screen pointed at — you could get in and not out.
 * On a shared office machine that is a real problem rather than an untidy one:
 * the next person to sit down is still the last person as far as the audit log
 * is concerned, and every ledger adjustment they post carries the wrong name.
 *
 * Signing out is a POST, so it is a button inside a form rather than a link. A
 * GET that ends a session can be triggered by anything that prefetches a URL.
 */
export default function UserMenu({ tone = 'light' }) {
    const { auth } = usePage().props;
    const { post, processing } = useForm({});

    if (!auth?.user) {
        return null;
    }

    const signOut = (event) => {
        event.preventDefault();
        post('/logout');
    };

    const label = auth.user.name || auth.user.email;

    return (
        <Dropdown>
            <Dropdown.Trigger>
                <button
                    type="button"
                    className={`flex min-h-touch items-center gap-1.5 rounded-md px-2 text-base focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 ${
                        tone === 'light' ? 'text-gray-700 hover:bg-gray-100' : 'text-white hover:bg-white/10'
                    }`}
                >
                    {/* The name is the accessible label; the caret is
                        decoration (UI §9). */}
                    <span className="max-w-[10rem] truncate">{label}</span>
                    <span aria-hidden="true" className="text-xs">
                        ▾
                    </span>
                </button>
            </Dropdown.Trigger>

            <Dropdown.Content>
                <div className="border-b border-gray-200 px-4 py-2">
                    <p className="truncate text-sm font-medium text-gray-900">{label}</p>
                    <p className="truncate text-sm text-gray-600">{auth.user.email}</p>
                </div>

                <Dropdown.Link href="/profile">Your details</Dropdown.Link>

                <form onSubmit={signOut}>
                    <button
                        type="submit"
                        disabled={processing}
                        className="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none disabled:opacity-50"
                    >
                        {processing ? 'Signing out…' : 'Sign out'}
                    </button>
                </form>
            </Dropdown.Content>
        </Dropdown>
    );
}

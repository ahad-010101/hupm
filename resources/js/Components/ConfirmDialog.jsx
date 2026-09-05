import { useEffect, useRef } from 'react';

/**
 * A confirmation modal for irreversible or financial actions.  [UI §7, §9]
 *
 * Focus moves to the dialog on open, is trapped while it is open, and returns
 * to the trigger on close. Escape cancels. Without that, a keyboard user tabs
 * straight past the dialog into the page behind it and can activate the very
 * control the confirmation was protecting.
 *
 * The confirm button disables itself on click. FS §18.4 requires no optimistic
 * UI on any financial action and no duplicate submission — the server-side
 * idempotency key is the real guard, but a disabled button removes the most
 * common way to trigger it.
 */
export default function ConfirmDialog({
    open,
    title,
    children,
    confirmLabel = 'Confirm',
    cancelLabel = 'Cancel',
    tone = 'brand',
    processing = false,
    onConfirm,
    onCancel,
}) {
    const dialogRef = useRef(null);
    const confirmRef = useRef(null);
    const previouslyFocused = useRef(null);

    // Escape has to call the CURRENT onCancel, but the effect below must not
    // re-run when a new one arrives — see the dependency note there. A ref
    // gives us the latest handler without making it a dependency.
    const cancelRef = useRef(onCancel);

    useEffect(() => {
        cancelRef.current = onCancel;
    });

    useEffect(() => {
        if (!open) return undefined;

        previouslyFocused.current = document.activeElement;
        confirmRef.current?.focus();

        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                cancelRef.current?.();
                return;
            }

            if (event.key !== 'Tab') return;

            const focusable = dialogRef.current?.querySelectorAll(
                'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
            );

            if (!focusable?.length) return;

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            previouslyFocused.current?.focus?.();
        };
        // `open` ONLY, deliberately.
        //
        // `onCancel` used to be a dependency, and every caller passes an inline
        // arrow — so its identity changed on every render. Typing one character
        // into a field inside the dialog re-rendered the parent, which tore this
        // effect down (restoring focus to the trigger) and re-ran it (moving
        // focus to the confirm button). The field lost focus after a single
        // keystroke, which made the ledger adjustment form unusable.
        //
        // The handler is read through cancelRef instead, so Escape still calls
        // the current one without the effect depending on its identity.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    if (!open) return null;

    const confirmClasses =
        tone === 'danger'
            ? 'bg-overdue-fg hover:bg-red-900 focus-visible:outline-overdue-fg'
            : 'bg-brand-600 hover:bg-brand-700 focus-visible:outline-brand-600';

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-4 sm:items-center">
            <div
                ref={dialogRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby="confirm-dialog-title"
                className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl"
            >
                <h2 id="confirm-dialog-title" className="text-lg font-semibold text-gray-900">
                    {title}
                </h2>

                <div className="mt-2 text-base text-gray-700">{children}</div>

                <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        onClick={onCancel}
                        disabled={processing}
                        className="min-h-touch rounded-md border border-gray-300 px-4 py-2 text-base font-medium text-gray-700 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                    >
                        {cancelLabel}
                    </button>
                    <button
                        ref={confirmRef}
                        type="button"
                        onClick={onConfirm}
                        disabled={processing}
                        className={`min-h-touch rounded-md px-4 py-2 text-base font-semibold text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-60 ${confirmClasses}`}
                    >
                        {processing ? 'Working…' : confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}

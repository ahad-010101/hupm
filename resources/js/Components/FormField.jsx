import { cloneElement, isValidElement, useId } from 'react';

/**
 * A labelled form control with an accessible error.  [UI §9, FS §18.3]
 *
 * Every field has a real <label for> — not a placeholder standing in for one,
 * which disappears on focus and is invisible to screen readers.
 *
 * Errors are announced through an aria-live region and wired with
 * aria-describedby + aria-invalid, so a keyboard or screen-reader user learns
 * why a submission was rejected without hunting for red text.
 *
 * Inputs are 16px minimum: below that, iOS Safari zooms the viewport on focus
 * and the tenant loses their place in the form (UI §6).
 */
export default function FormField({
    label,
    name,
    type = 'text',
    value,
    onChange,
    error,
    hint,
    required = false,
    disabled = false,
    children,
    className = '',
    ...props
}) {
    const id = useId();
    const errorId = `${id}-error`;
    const hintId = `${id}-hint`;

    const describedBy = [error ? errorId : null, hint ? hintId : null].filter(Boolean).join(' ');

    /*
     * A passed-in control gets the same wiring the built-in input gets.
     *
     * Without this the <label for> points at an id nothing carries, so every
     * <select> rendered through this component — country, state, city, county —
     * is an unlabelled combobox to a screen reader, while looking perfectly
     * labelled on screen. The docblock above promises a real <label for>; this
     * is what keeps that true for the children form as well.
     *
     * An id the caller set wins, and anything that is not a single element is
     * left alone.
     */
    const control = isValidElement(children)
        ? cloneElement(children, {
            id: children.props.id ?? id,
            'aria-invalid': error ? 'true' : children.props['aria-invalid'],
            'aria-describedby': children.props['aria-describedby'] ?? (describedBy || undefined),
        })
        : children;

    return (
        <div className={`mb-4 ${className}`}>
            <label htmlFor={id} className="mb-1 block text-base font-medium text-gray-900">
                {label}
                {required && (
                    <>
                        <span aria-hidden="true" className="ml-0.5 text-overdue-fg">
                            *
                        </span>
                        <span className="sr-only"> (required)</span>
                    </>
                )}
            </label>

            {hint && (
                <p id={hintId} className="mb-1 text-sm text-gray-600">
                    {hint}
                </p>
            )}

            {control ?? (
                <input
                    id={id}
                    name={name}
                    type={type}
                    value={value}
                    onChange={onChange}
                    required={required}
                    disabled={disabled}
                    aria-invalid={error ? 'true' : undefined}
                    aria-describedby={describedBy || undefined}
                    className={`block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600 disabled:bg-gray-100 ${
                        error ? 'border-overdue-fg' : ''
                    }`}
                    {...props}
                />
            )}

            {/* Present in the DOM even when empty so assistive tech has a live
                region to observe before the first error arrives. */}
            <p id={errorId} role="alert" aria-live="polite" className="mt-1 min-h-[1.25rem] text-sm text-overdue-fg">
                {error}
            </p>
        </div>
    );
}

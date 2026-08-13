/**
 * An empty state.  [UI §7, FS §18.1]
 *
 * Never a blank panel. The specs write these strings out per screen — "No
 * documents yet. Your lease will appear here once uploaded." — because an
 * empty region with no explanation reads as a broken page, and a tenant who
 * thinks the system is broken telephones the office.
 */
export default function EmptyState({ title, description, action, className = '' }) {
    return (
        <div
            className={`rounded-lg border border-dashed border-gray-300 bg-gray-50 px-6 py-10 text-center ${className}`}
        >
            <p className="text-base font-medium text-gray-900">{title}</p>
            {description && <p className="mx-auto mt-1 max-w-prose text-base text-gray-600">{description}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}

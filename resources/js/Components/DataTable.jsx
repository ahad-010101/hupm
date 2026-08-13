import EmptyState from '@/Components/EmptyState';

/**
 * A responsive table.  [UI §6, §9]
 *
 * Mobile stacks each row as a card, desktop renders a real <table>. Both come
 * from the same column definition, so there is one source of truth for what a
 * row means and the two presentations cannot drift apart.
 *
 * The mobile view is genuinely stacked cards rather than a horizontally
 * scrolling table: the dominant tenant persona is phone-only, and a side-
 * scrolling financial table on a 375px screen hides the column that matters.
 *
 * Accessibility: real <th scope="col">, and a <caption> that screen readers
 * announce before the data (UI §9).
 *
 * @param {Array<{key: string, header: string, render?: Function, align?: string, hideOnMobile?: boolean}>} columns
 */
export default function DataTable({
    columns,
    rows,
    caption,
    rowKey = (row) => row.id,
    empty,
    className = '',
}) {
    if (!rows || rows.length === 0) {
        return empty ?? <EmptyState title="Nothing to show yet." />;
    }

    const alignment = (column) => (column.align === 'right' ? 'text-right' : 'text-left');

    return (
        <div className={className}>
            {/* Desktop and tablet: a real table. */}
            <div className="hidden overflow-x-auto sm:block">
                <table className="min-w-full divide-y divide-gray-200">
                    {caption && <caption className="sr-only">{caption}</caption>}
                    <thead className="bg-gray-50">
                        <tr>
                            {columns.map((column) => (
                                <th
                                    key={column.key}
                                    scope="col"
                                    className={`px-4 py-3 text-sm font-semibold text-gray-900 ${alignment(column)}`}
                                >
                                    {column.header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-200 bg-white">
                        {rows.map((row) => (
                            <tr key={rowKey(row)} className="hover:bg-gray-50">
                                {columns.map((column) => (
                                    <td
                                        key={column.key}
                                        className={`px-4 py-3 text-base text-gray-700 ${alignment(column)}`}
                                    >
                                        {column.render ? column.render(row) : row[column.key]}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {/* Mobile: one card per row, label and value paired. */}
            <ul className="space-y-3 sm:hidden">
                {caption && <li className="sr-only">{caption}</li>}
                {rows.map((row) => (
                    <li key={rowKey(row)} className="rounded-lg border border-gray-200 bg-white p-4">
                        <dl className="space-y-2">
                            {columns
                                .filter((column) => !column.hideOnMobile)
                                .map((column) => (
                                    <div key={column.key} className="flex justify-between gap-4">
                                        <dt className="text-sm text-gray-600">{column.header}</dt>
                                        <dd className="text-base text-gray-900">
                                            {column.render ? column.render(row) : row[column.key]}
                                        </dd>
                                    </div>
                                ))}
                        </dl>
                    </li>
                ))}
            </ul>
        </div>
    );
}

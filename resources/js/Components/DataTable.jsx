import { useId } from 'react';
import EmptyState from '@/Components/EmptyState';

/**
 * A responsive table.  [UI §6, §9, WP-38]
 *
 * Mobile stacks each row as a card, desktop renders a real <table>. Both come
 * from the same column definition, so there is one source of truth for what a
 * row means and the two presentations cannot drift apart.
 *
 * The mobile view is genuinely stacked cards rather than a horizontally
 * scrolling table: the dominant tenant persona is phone-only, and a side-
 * scrolling financial table on a 375px screen hides the column that matters.
 *
 * Sorting therefore cannot be "click the column header": on mobile there are no
 * headers to click, and the admin console has to stay usable on a phone (UI §6
 * — the manager triages from the car). So a sortable table renders clickable
 * headers on desktop and an equivalent <select> above the cards, both calling
 * the same onSort.
 *
 * Accessibility: real <th scope="col">, real <button> inside it, aria-sort on
 * the active column, and direction shown with a glyph plus screen-reader text
 * rather than colour alone (UI §9).
 *
 * Sorting is opt-in. Without `sort` and `onSort` this renders exactly as it did
 * before, which is what keeps the other call sites untouched.
 *
 * @param {Array<{key: string, header: string, render?: Function, align?: string, hideOnMobile?: boolean, sortable?: boolean, sortLabels?: {asc: string, desc: string}}>} columns
 * @param {{key: string, direction: 'asc'|'desc'}} [sort]
 * @param {(key: string, direction: 'asc'|'desc') => void} [onSort]
 */
export default function DataTable({
    columns,
    rows,
    caption,
    rowKey = (row) => row.id,
    empty,
    className = '',
    sort,
    onSort,
}) {
    const selectId = useId();

    if (!rows || rows.length === 0) {
        return empty ?? <EmptyState title="Nothing to show yet." />;
    }

    const alignment = (column) => (column.align === 'right' ? 'text-right' : 'text-left');

    const sortable = (column) => Boolean(onSort && column.sortable);
    // A column may sort by something other than the value it renders — an
    // "Address" cell composed of five fields sorts by city, a "Units" cell
    // sorts by the withCount alias.
    const sortKey = (column) => column.sortKey ?? column.key;
    const isActive = (column) => sortable(column) && sort?.key === sortKey(column);

    // A fresh column starts ascending; the active one toggles. This mirrors the
    // server's fallback in App\Support\ListSort so the first click and the
    // first load agree.
    const nextDirection = (column) => (isActive(column) && sort?.direction === 'asc' ? 'desc' : 'asc');

    const label = (column, direction) =>
        column.sortLabels?.[direction] ?? `${column.header}, ${direction === 'asc' ? 'ascending' : 'descending'}`;

    const sortableColumns = columns.filter(sortable);

    return (
        <div className={className}>
            {/* Mobile: the cards below have no header row, so sorting needs its
                own control. Rendered only when something is actually sortable. */}
            {sortableColumns.length > 0 && (
                <div className="mb-3 sm:hidden">
                    <label htmlFor={selectId} className="block text-sm font-medium text-gray-700">
                        Sort by
                    </label>
                    <select
                        id={selectId}
                        className="mt-1 block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-teal-600 focus:ring-teal-600"
                        value={sort ? `${sort.key}:${sort.direction}` : ''}
                        onChange={(event) => {
                            const [key, direction] = event.target.value.split(':');
                            onSort(key, direction);
                        }}
                    >
                        {sortableColumns.flatMap((column) =>
                            ['asc', 'desc'].map((direction) => (
                                <option key={`${sortKey(column)}:${direction}`} value={`${sortKey(column)}:${direction}`}>
                                    {label(column, direction)}
                                </option>
                            )),
                        )}
                    </select>
                </div>
            )}

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
                                    aria-sort={
                                        isActive(column)
                                            ? sort.direction === 'asc'
                                                ? 'ascending'
                                                : 'descending'
                                            : sortable(column)
                                              ? 'none'
                                              : undefined
                                    }
                                    className={`px-4 py-3 text-sm font-semibold text-gray-900 ${alignment(column)}`}
                                >
                                    {sortable(column) ? (
                                        <button
                                            type="button"
                                            onClick={() => onSort(sortKey(column), nextDirection(column))}
                                            className={`group inline-flex items-center gap-1 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 ${
                                                column.align === 'right' ? 'flex-row-reverse' : ''
                                            }`}
                                        >
                                            <span>{column.header}</span>
                                            {/* A glyph, not a colour (UI §9), plus the
                                                state in words for screen readers. */}
                                            <span aria-hidden="true" className={isActive(column) ? 'text-teal-700' : 'text-gray-400'}>
                                                {isActive(column) ? (sort.direction === 'asc' ? '▲' : '▼') : '↕'}
                                            </span>
                                            <span className="sr-only">
                                                {isActive(column)
                                                    ? `sorted ${sort.direction === 'asc' ? 'ascending' : 'descending'}, activate to reverse`
                                                    : 'not sorted, activate to sort ascending'}
                                            </span>
                                        </button>
                                    ) : (
                                        column.header
                                    )}
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

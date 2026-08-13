/**
 * Loading placeholder.  [UI §7, FS §18.2]
 *
 * Shown after 200ms, not immediately — a skeleton that flashes on a fast
 * response is more distracting than no skeleton at all. Callers control the
 * delay; this is only the shape.
 *
 * aria-hidden with a sibling status message: a screen reader should hear
 * "Loading", not a description of grey rectangles.
 */
export default function SkeletonCard({ lines = 3, className = '' }) {
    return (
        <>
            <span className="sr-only" role="status">
                Loading
            </span>
            <div
                aria-hidden="true"
                className={`animate-pulse rounded-lg border border-gray-200 bg-white p-4 ${className}`}
            >
                <div className="mb-3 h-4 w-1/3 rounded bg-gray-200" />
                {Array.from({ length: lines }).map((_, index) => (
                    <div
                        key={index}
                        className="mb-2 h-3 rounded bg-gray-100"
                        style={{ width: `${90 - index * 15}%` }}
                    />
                ))}
            </div>
        </>
    );
}

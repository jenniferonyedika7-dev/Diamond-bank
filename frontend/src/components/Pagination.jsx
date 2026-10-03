import Button from './Button.jsx'

/** pagination: { current_page, last_page, total } from ApiResponse::paginated. */
export default function Pagination({ pagination, onPageChange }) {
  if (!pagination || pagination.last_page <= 1) return null
  const { current_page: page, last_page: last, total } = pagination

  return (
    <nav aria-label="Pagination" className="mt-4 flex items-center justify-between gap-3 text-sm text-slate-600">
      <p>
        Page {page} of {last} · {total} total
      </p>
      <div className="flex gap-2">
        <Button variant="secondary" className="py-1.5" disabled={page <= 1} onClick={() => onPageChange(page - 1)}>
          Previous
        </Button>
        <Button variant="secondary" className="py-1.5" disabled={page >= last} onClick={() => onPageChange(page + 1)}>
          Next
        </Button>
      </div>
    </nav>
  )
}

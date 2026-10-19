const CONTROL =
  'block w-full rounded-lg border bg-white px-3 py-2.5 text-base text-slate-900 shadow-sm transition-[border-color,box-shadow] duration-150 placeholder:text-slate-400 focus:outline-none focus-visible:outline-none focus-visible:ring-3 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-500 disabled:shadow-none [&[readonly]]:border-slate-200 [&[readonly]]:bg-slate-50 [&[readonly]]:text-slate-600 sm:text-sm'

const STATES = {
  normal: 'border-slate-300 hover:border-navy-400 focus-visible:border-navy-600 focus-visible:ring-navy-600/20',
  error: 'border-red-400 hover:border-red-500 focus-visible:border-red-600 focus-visible:ring-red-600/20',
}

/**
 * A labelled input, select (when children are given) or textarea (as="textarea"). The error text is
 * linked with aria-describedby, together with the optional hint and any extra
 * ids in describedBy (e.g. a password-rules box).
 */
export default function FormField({ id, label, error, hint, describedBy, as, children, className = '', ...props }) {
  const hintId = hint ? `${id}-hint` : undefined
  const errorId = error ? `${id}-error` : undefined
  const ariaDescribedBy = [describedBy, hintId, errorId].filter(Boolean).join(' ') || undefined
  const controlProps = {
    id,
    name: id,
    'aria-invalid': error ? true : undefined,
    'aria-describedby': ariaDescribedBy,
    className: `${CONTROL} ${error ? STATES.error : STATES.normal}`,
    ...props,
  }

  return (
    <div className={className}>
      <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-slate-700">
        {label}
      </label>
      {children ? (
        <select {...controlProps}>{children}</select>
      ) : as === 'textarea' ? (
        <textarea rows={3} {...controlProps} />
      ) : (
        <input {...controlProps} />
      )}
      {hint && (
        <p id={hintId} className="mt-1.5 text-xs text-slate-500">
          {hint}
        </p>
      )}
      {error && (
        <p id={errorId} className="mt-1.5 flex items-start gap-1.5 text-sm text-red-700">
          <svg viewBox="0 0 20 20" className="mt-0.5 size-4 shrink-0" fill="currentColor" aria-hidden="true">
            <path
              fillRule="evenodd"
              d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-8-5a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-1.5 0v-4.5A.75.75 0 0 1 10 5Zm0 10a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"
              clipRule="evenodd"
            />
          </svg>
          <span>{error}</span>
        </p>
      )}
    </div>
  )
}

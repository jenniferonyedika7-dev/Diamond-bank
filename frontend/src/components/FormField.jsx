const CONTROL =
  'block w-full rounded-lg border bg-white px-3 py-2.5 text-base text-slate-900 shadow-sm placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-navy-600 focus-visible:ring-offset-1 sm:text-sm'

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
    className: `${CONTROL} ${error ? 'border-red-400' : 'border-slate-300'}`,
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
        <p id={hintId} className="mt-1 text-xs text-slate-500">
          {hint}
        </p>
      )}
      {error && (
        <p id={errorId} className="mt-1 text-sm text-red-700">
          {error}
        </p>
      )}
    </div>
  )
}

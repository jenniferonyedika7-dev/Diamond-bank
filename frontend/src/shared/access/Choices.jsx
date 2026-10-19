// Native radios and checkboxes in the bank's navy, with a gold keyboard focus ring.
const CONTROL =
  'mt-0.5 size-4 shrink-0 cursor-pointer accent-navy-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-500'

const OPTION = 'flex cursor-pointer items-start gap-2 rounded-lg px-2 py-1.5 -mx-2 text-sm text-slate-800 transition-colors hover:bg-navy-50'

/** A fieldset of radio buttons. options: [{ value, label, hint? }] */
export function RadioGroup({ name, legend, options, value, onChange, error }) {
  return (
    <fieldset aria-describedby={error ? `${name}-error` : undefined}>
      <legend className="mb-1.5 text-sm font-medium text-slate-700">{legend}</legend>
      <div className="space-y-1">
        {options.map((option) => (
          <label key={option.value} className={OPTION}>
            <input
              type="radio"
              name={name}
              value={option.value}
              checked={value === option.value}
              onChange={() => onChange(option.value)}
              className={CONTROL}
            />
            <span>
              {option.label}
              {option.hint && <span className="block text-xs text-slate-500">{option.hint}</span>}
            </span>
          </label>
        ))}
      </div>
      {error && (
        <p id={`${name}-error`} className="mt-1.5 text-sm text-red-700">
          {error}
        </p>
      )}
    </fieldset>
  )
}

export function Checkbox({ id, label, checked, onChange, error }) {
  return (
    <div>
      <label htmlFor={id} className={OPTION}>
        <input
          id={id}
          type="checkbox"
          checked={checked}
          onChange={(e) => onChange(e.target.checked)}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? `${id}-error` : undefined}
          className={CONTROL}
        />
        <span>{label}</span>
      </label>
      {error && (
        <p id={`${id}-error`} className="mt-1.5 text-sm text-red-700">
          {error}
        </p>
      )}
    </div>
  )
}

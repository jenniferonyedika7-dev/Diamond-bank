/** A fieldset of radio buttons. options: [{ value, label, hint? }] */
export function RadioGroup({ name, legend, options, value, onChange, error }) {
  return (
    <fieldset aria-describedby={error ? `${name}-error` : undefined}>
      <legend className="mb-1.5 text-sm font-medium text-slate-700">{legend}</legend>
      <div className="space-y-2">
        {options.map((option) => (
          <label key={option.value} className="flex items-start gap-2 text-sm text-slate-800">
            <input
              type="radio"
              name={name}
              value={option.value}
              checked={value === option.value}
              onChange={() => onChange(option.value)}
              className="mt-0.5 size-4 accent-navy-700"
            />
            <span>
              {option.label}
              {option.hint && <span className="block text-xs text-slate-500">{option.hint}</span>}
            </span>
          </label>
        ))}
      </div>
      {error && (
        <p id={`${name}-error`} className="mt-1 text-sm text-red-700">
          {error}
        </p>
      )}
    </fieldset>
  )
}

export function Checkbox({ id, label, checked, onChange, error }) {
  return (
    <div>
      <label htmlFor={id} className="flex items-start gap-2 text-sm text-slate-800">
        <input
          id={id}
          type="checkbox"
          checked={checked}
          onChange={(e) => onChange(e.target.checked)}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? `${id}-error` : undefined}
          className="mt-0.5 size-4 accent-navy-700"
        />
        <span>{label}</span>
      </label>
      {error && (
        <p id={`${id}-error`} className="mt-1 text-sm text-red-700">
          {error}
        </p>
      )}
    </div>
  )
}

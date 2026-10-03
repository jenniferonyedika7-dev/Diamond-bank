import { useEffect, useId, useRef } from 'react'

/**
 * A native <dialog> opened with showModal(): focus is trapped inside, Escape
 * closes it and the page behind is inert. Rendered only while open.
 */
export default function Modal({ title, onClose, children, size = 'md' }) {
  const ref = useRef(null)
  const titleId = useId()

  useEffect(() => {
    const dialog = ref.current
    dialog.showModal()
    return () => dialog.close()
  }, [])

  return (
    <dialog
      ref={ref}
      aria-labelledby={titleId}
      onCancel={(event) => {
        event.preventDefault()
        onClose()
      }}
      className={`m-auto w-[calc(100%-2rem)] ${size === 'lg' ? 'max-w-2xl' : 'max-w-md'} rounded-2xl bg-white p-0 text-slate-800 shadow-xl backdrop:bg-slate-900/50`}
    >
      <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
        <h2 id={titleId} className="text-lg font-semibold text-navy-900">
          {title}
        </h2>
        <button
          type="button"
          onClick={onClose}
          className="-m-1 rounded-md p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-800 focus-visible:outline-2 focus-visible:outline-navy-600"
        >
          <span className="sr-only">Close</span>
          <svg viewBox="0 0 20 20" className="size-5" fill="currentColor" aria-hidden="true">
            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
          </svg>
        </button>
      </div>
      <div className="px-5 py-5">{children}</div>
    </dialog>
  )
}

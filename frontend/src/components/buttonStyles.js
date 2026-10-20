// Shared by <Button> and by router Links that look like buttons.
const VARIANTS = {
  primary:
    'bg-navy-900 text-white shadow-sm hover:bg-navy-700 hover:shadow active:bg-navy-950 disabled:bg-navy-900 disabled:opacity-55',
  danger: 'bg-red-700 text-white shadow-sm hover:bg-red-800 hover:shadow active:bg-red-900 disabled:bg-red-700 disabled:opacity-55',
  secondary:
    'bg-white text-navy-900 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-navy-50 hover:ring-navy-300 active:bg-navy-100 disabled:bg-slate-50 disabled:text-slate-400 disabled:ring-slate-200',
}

const BASE =
  'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold transition-[background-color,box-shadow,color] duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-500 disabled:cursor-not-allowed disabled:shadow-none'

/** The button look for something that is not a <button>, e.g. a router Link. */
export function buttonClass(variant = 'primary', extra = '') {
  return `${BASE} ${VARIANTS[variant]} ${extra}`.trim()
}

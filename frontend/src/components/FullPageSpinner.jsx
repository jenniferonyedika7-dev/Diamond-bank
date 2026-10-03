import Spinner from './Spinner.jsx'

export default function FullPageSpinner({ label = 'Loading…' }) {
  return (
    <div className="flex min-h-dvh flex-col items-center justify-center gap-3 text-navy-900" role="status">
      <Spinner className="size-8" />
      <p className="text-sm text-slate-600">{label}</p>
    </div>
  )
}

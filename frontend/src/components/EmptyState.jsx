export default function EmptyState({ title, children, action }) {
  return (
    <div className="rounded-xl border border-dashed border-navy-200 bg-white px-6 py-10 text-center">
      <p className="font-medium text-navy-900">{title}</p>
      {children && <p className="mt-1 text-sm text-slate-600">{children}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  )
}

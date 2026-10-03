import Brand from './Brand.jsx'

/** Centered card for the login, registration and password pages. */
export default function AuthLayout({ title, subtitle, children, wide = false }) {
  return (
    <div className="flex min-h-dvh flex-col">
      <header className="bg-navy-900 px-4 py-4 text-white">
        <div className="mx-auto max-w-5xl">
          <Brand className="text-lg" />
        </div>
      </header>
      <main className="flex flex-1 justify-center px-4 py-8 sm:py-12">
        <div className={`w-full ${wide ? 'max-w-2xl' : 'max-w-md'}`}>
          <div className="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:p-8">
            <h1 className="text-2xl font-semibold text-navy-900">{title}</h1>
            {subtitle && <p className="mt-1 text-sm text-slate-600">{subtitle}</p>}
            <div className="mt-6">{children}</div>
          </div>
        </div>
      </main>
    </div>
  )
}

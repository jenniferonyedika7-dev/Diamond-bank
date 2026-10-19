import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { Link, NavLink, useLocation, useNavigate } from 'react-router'
import { isActive, NAV } from '../lib/nav.js'
import { dashboardPath, displayName, ROLE_LABEL } from '../lib/roles.js'
import Brand from './Brand.jsx'
import Spinner from './Spinner.jsx'

/**
 * An open/closed menu that closes after navigating (without an effect), on a click, tap or
 * keyboard focus outside it, and on Escape (which returns focus to its button).
 */
function useMenu() {
  const { pathname } = useLocation()
  const [state, setState] = useState({ open: false, path: pathname })
  const open = state.open && state.path === pathname
  const rootRef = useRef(null)
  const buttonRef = useRef(null)
  const setOpen = useCallback((value) => setState({ open: value, path: pathname }), [pathname])

  useEffect(() => {
    if (!open) return undefined
    function onOutside(event) {
      if (!rootRef.current?.contains(event.target)) setOpen(false)
    }
    function onKeyDown(event) {
      if (event.key !== 'Escape') return
      setOpen(false)
      buttonRef.current?.focus()
    }
    document.addEventListener('pointerdown', onOutside)
    document.addEventListener('focusin', onOutside)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('pointerdown', onOutside)
      document.removeEventListener('focusin', onOutside)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open, setOpen])

  return { open, setOpen, rootRef, buttonRef }
}

function Chevron({ open, className = '' }) {
  return (
    <svg viewBox="0 0 20 20" className={`size-4 transition-transform ${open ? 'rotate-180' : ''} ${className}`} fill="currentColor" aria-hidden="true">
      <path d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" />
    </svg>
  )
}

// Bar items: white on navy; the current page has a gold underline.
function barItemClass(active) {
  return `inline-flex items-center gap-1 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
    active
      ? 'bg-navy-800 text-white shadow-[inset_0_-2px_0_0_var(--color-accent-400)]'
      : 'text-navy-100 hover:bg-navy-800/70 hover:text-white'
  }`
}

// Items in a dropdown panel; the current page has a gold edge.
const PANEL_ITEM =
  'block w-full px-4 py-2 text-left text-sm text-slate-700 transition-colors hover:bg-navy-50 hover:text-navy-900 focus-visible:bg-navy-50 focus-visible:outline-offset-[-2px] aria-[current=page]:bg-navy-50 aria-[current=page]:font-semibold aria-[current=page]:text-navy-900 aria-[current=page]:shadow-[inset_3px_0_0_0_var(--color-accent-500)]'

const PANEL = 'absolute top-full z-40 mt-2 w-56 overflow-hidden rounded-xl bg-white py-1.5 shadow-lg ring-1 ring-slate-200'

function NavGroup({ group, pathname }) {
  const { open, setOpen, rootRef, buttonRef } = useMenu()
  const panelId = useId()
  const active = group.items.some((item) => isActive(item, pathname))

  return (
    <div ref={rootRef} className="relative">
      <button
        ref={buttonRef}
        type="button"
        aria-expanded={open}
        aria-controls={panelId}
        onClick={() => setOpen(!open)}
        className={barItemClass(active)}
      >
        {group.label}
        <Chevron open={open} />
      </button>
      <ul id={panelId} className={`${PANEL} left-0 ${open ? '' : 'hidden'}`}>
        {group.items.map((item) => (
          <li key={item.to}>
            <NavLink to={item.to} end={item.end} onClick={() => setOpen(false)} className={PANEL_ITEM}>
              {item.label}
            </NavLink>
          </li>
        ))}
      </ul>
    </div>
  )
}

function SearchIcon({ className = '' }) {
  return (
    <svg viewBox="0 0 20 20" className={className} fill="currentColor" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.45 4.39l3.08 3.08a.75.75 0 1 1-1.06 1.06l-3.08-3.08A7 7 0 0 1 2 9Z"
        clipRule="evenodd"
      />
    </svg>
  )
}

/** Opens the role's existing customer search with the term filled in. */
function NavSearch({ search, className = '' }) {
  const navigate = useNavigate()
  const inputId = useId()
  const [term, setTerm] = useState('')

  function handleSubmit(event) {
    event.preventDefault()
    const value = term.trim()
    const params = new URLSearchParams(value ? { ...search.params, search: value } : search.params).toString()
    navigate(params ? `${search.to}?${params}` : search.to)
    setTerm('')
  }

  return (
    <form role="search" onSubmit={handleSubmit} className={`relative ${className}`}>
      <label htmlFor={inputId} className="sr-only">
        Search customers
      </label>
      <input
        id={inputId}
        type="search"
        value={term}
        onChange={(event) => setTerm(event.target.value)}
        placeholder="Search customers"
        autoComplete="off"
        className="block w-full rounded-lg border border-navy-700 bg-navy-800 py-2 pl-3 pr-10 text-sm text-white placeholder:text-navy-200 transition-colors hover:border-navy-500 focus:border-accent-400 focus:bg-navy-950 focus:outline-none focus-visible:outline-none"
      />
      <button
        type="submit"
        className="absolute inset-y-0 right-0 flex w-9 items-center justify-center rounded-r-lg text-navy-200 transition-colors hover:text-accent-300 focus-visible:text-accent-300 focus-visible:outline-offset-[-2px]"
      >
        <span className="sr-only">Search</span>
        <SearchIcon className="size-4" />
      </button>
    </form>
  )
}

function Initials({ name }) {
  const letters = name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('')
  return (
    <span aria-hidden="true" className="flex size-8 shrink-0 items-center justify-center rounded-full bg-accent-400 text-xs font-bold text-navy-900">
      {letters || '?'}
    </span>
  )
}

function LogoutButton({ onLogout, signingOut, className }) {
  return (
    <button type="button" onClick={onLogout} disabled={signingOut} aria-busy={signingOut || undefined} className={className}>
      {signingOut ? (
        <span className="inline-flex items-center gap-2">
          <Spinner /> Signing out…
        </span>
      ) : (
        'Log out'
      )}
    </button>
  )
}

function UserMenu({ name, role, extra, links, onLogout, signingOut }) {
  const { open, setOpen, rootRef, buttonRef } = useMenu()
  const panelId = useId()

  return (
    <div ref={rootRef} className="relative">
      <button
        ref={buttonRef}
        type="button"
        aria-expanded={open}
        aria-controls={panelId}
        onClick={() => setOpen(!open)}
        className="flex items-center gap-2.5 rounded-lg py-1 pl-1 pr-2 text-left transition-colors hover:bg-navy-800/70"
      >
        <Initials name={name} />
        <span className="leading-tight">
          <span className="block max-w-40 truncate text-sm font-medium text-white">{name}</span>
          <span className="block text-xs text-navy-200">{role}</span>
        </span>
        <Chevron open={open} className="text-navy-200" />
        <span className="sr-only">: account menu</span>
      </button>
      <div id={panelId} className={`${PANEL} right-0 w-64 ${open ? '' : 'hidden'}`}>
        <div className="border-b border-slate-100 px-4 pb-3 pt-2">
          <p className="truncate text-sm font-semibold text-navy-900">{name}</p>
          <p className="text-xs text-slate-500">{role}</p>
          {extra && <p className="mt-1 text-xs text-slate-600">{extra}</p>}
        </div>
        <ul className="py-1">
          {links.map((link) => (
            <li key={link.to}>
              <NavLink to={link.to} onClick={() => setOpen(false)} className={PANEL_ITEM}>
                {link.label}
              </NavLink>
            </li>
          ))}
          <li className={links.length > 0 ? 'mt-1 border-t border-slate-100 pt-1' : ''}>
            <LogoutButton onLogout={onLogout} signingOut={signingOut} className={`${PANEL_ITEM} font-medium text-red-700 hover:bg-red-50 hover:text-red-800`} />
          </li>
        </ul>
      </div>
    </div>
  )
}

// Items in the phone menu panel.
const MOBILE_ITEM =
  'block rounded-md px-3 py-2.5 text-base font-medium text-navy-100 transition-colors hover:bg-navy-800 hover:text-white aria-[current=page]:bg-navy-800 aria-[current=page]:text-white aria-[current=page]:shadow-[inset_3px_0_0_0_var(--color-accent-400)]'

function MobileMenu({ nav, items, search, name, role, extra, links, onLogout, signingOut }) {
  const { open, setOpen, rootRef, buttonRef } = useMenu()

  return (
    <div ref={rootRef} className="lg:hidden">
      <button
        ref={buttonRef}
        type="button"
        aria-expanded={open}
        aria-controls="mobile-nav"
        onClick={() => setOpen(!open)}
        className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-white ring-1 ring-inset ring-navy-700 transition-colors hover:bg-navy-800"
      >
        <svg viewBox="0 0 20 20" className="size-5" fill="currentColor" aria-hidden="true">
          {open ? (
            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
          ) : (
            <path
              fillRule="evenodd"
              d="M2 4.75A.75.75 0 0 1 2.75 4h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 4.75ZM2 10a.75.75 0 0 1 .75-.75h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 10Zm0 5.25a.75.75 0 0 1 .75-.75h14.5a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1-.75-.75Z"
              clipRule="evenodd"
            />
          )}
        </svg>
        Menu
      </button>

      <div id="mobile-nav" className={`absolute inset-x-0 top-full z-40 max-h-[calc(100dvh-4rem)] overflow-y-auto border-t border-navy-800 bg-navy-900 px-4 pb-5 pt-3 shadow-lg ${open ? '' : 'hidden'}`}>
        {search && <NavSearch search={search} className="mb-3" />}

        {items.length > 0 && (
          <nav aria-label={nav.label}>
            <ul className="space-y-1">
              {items.map((item) =>
                item.items ? (
                  <li key={item.label} className="pt-2">
                    <p className="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-accent-300">{item.label}</p>
                    <ul className="space-y-1">
                      {item.items.map((sub) => (
                        <li key={sub.to}>
                          <NavLink to={sub.to} end={sub.end} onClick={() => setOpen(false)} className={`${MOBILE_ITEM} pl-6`}>
                            {sub.label}
                          </NavLink>
                        </li>
                      ))}
                    </ul>
                  </li>
                ) : (
                  <li key={item.to}>
                    <NavLink to={item.to} end={item.end} onClick={() => setOpen(false)} className={MOBILE_ITEM}>
                      {item.label}
                    </NavLink>
                  </li>
                ),
              )}
            </ul>
          </nav>
        )}

        <div className="mt-4 border-t border-navy-800 pt-4">
          <div className="flex items-center gap-3 px-3">
            <Initials name={name} />
            <div className="min-w-0 leading-tight">
              <p className="truncate text-sm font-medium text-white">{name}</p>
              <p className="text-xs text-navy-200">{role}</p>
              {extra && <p className="mt-0.5 text-xs text-navy-200">{extra}</p>}
            </div>
          </div>
          <ul className="mt-3 space-y-1">
            {links.map((link) => (
              <li key={link.to}>
                <NavLink to={link.to} onClick={() => setOpen(false)} className={MOBILE_ITEM}>
                  {link.label}
                </NavLink>
              </li>
            ))}
            <li>
              <LogoutButton onLogout={onLogout} signingOut={signingOut} className={`${MOBILE_ITEM} w-full text-left`} />
            </li>
          </ul>
        </div>
      </div>
    </div>
  )
}

/**
 * The navy bar on every signed-in page: logo, the role's sections (dropdown groups for the
 * admin), the customer search for staff and admins, and the user menu. Below lg it folds
 * into a Menu button. While a password change is forced, only Log out is offered, as the
 * other pages would send the user straight back. extra: a line about the user (the staff
 * member's branch), shown in a strip under the bar and in the menus.
 */
export default function TopNav({ user, extra, onLogout, signingOut }) {
  const { pathname } = useLocation()
  const nav = NAV[user?.role]
  const restricted = !nav || Boolean(user?.must_change_password)
  const items = restricted ? [] : nav.items
  const search = restricted ? null : nav.search
  const links = restricted ? [] : nav.account
  const name = displayName(user) || 'Signed in'
  const role = ROLE_LABEL[user?.role] ?? ''
  const userProps = { name, role, extra, links, onLogout, signingOut }

  return (
    <header className="sticky top-0 z-30 bg-navy-900 text-white shadow-md">
      <div className="relative mx-auto flex h-16 max-w-7xl items-center gap-4 px-4">
        <Link to={dashboardPath(user)} className="shrink-0 rounded-md py-1 transition-opacity hover:opacity-90">
          <Brand className="text-lg" />
          <span className="sr-only"> home</span>
        </Link>

        {items.length > 0 && (
          <nav aria-label={nav.label} className="hidden lg:block">
            <ul className="flex items-center gap-1">
              {items.map((item) => (
                <li key={item.to ?? item.label}>
                  {item.items ? (
                    <NavGroup group={item} pathname={pathname} />
                  ) : (
                    <NavLink to={item.to} end={item.end} className={({ isActive: active }) => barItemClass(active)}>
                      {item.label}
                    </NavLink>
                  )}
                </li>
              ))}
            </ul>
          </nav>
        )}

        <div className="ml-auto flex items-center gap-3">
          {search && <NavSearch search={search} className="hidden w-56 lg:block xl:w-64" />}
          <div className="hidden lg:block">
            <UserMenu {...userProps} />
          </div>
          <MobileMenu nav={nav ?? { label: 'Menu' }} items={items} search={search} {...userProps} />
        </div>
      </div>
      <div className="h-0.5 bg-gradient-to-r from-accent-500 via-accent-400 to-accent-500" aria-hidden="true" />
      {extra && (
        <div className="border-b border-slate-200 bg-white">
          <p className="mx-auto max-w-7xl px-4 py-1.5 text-xs text-slate-600">{extra}</p>
        </div>
      )}
    </header>
  )
}

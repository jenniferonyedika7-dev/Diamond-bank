import { useState } from 'react'
import Alert from '../components/Alert.jsx'
import Button from '../components/Button.jsx'
import ConfirmDialog from '../components/ConfirmDialog.jsx'
import EmptyState from '../components/EmptyState.jsx'
import FormField from '../components/FormField.jsx'
import Modal from '../components/Modal.jsx'
import PageHeader from '../components/PageHeader.jsx'
import Pagination from '../components/Pagination.jsx'
import Spinner from '../components/Spinner.jsx'
import { api, getErrorMessage, getFieldErrors } from '../lib/api.js'
import { useApiList, useDebounced } from './useApiList.js'

/**
 * Table + create/edit modal + delete confirmation for one admin resource.
 *
 * config: {
 *   endpoint, idKey, title, description, singular ("branch"), emptyText,
 *   columns: [{ key, label, render?(row) }],   the first column is the row's name
 *   fields:  [{ name, label, type?, ...inputProps }],
 *   searchable?, searchLabel?, paginated?, formErrorExtra?(fieldErrors)
 * }
 */
export default function ResourcePage({ config }) {
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const debouncedSearch = useDebounced(search.trim())
  const params = config.paginated ? { page, search: debouncedSearch || undefined } : undefined
  const { items, pagination, loading, error, reload } = useApiList(config.endpoint, params)

  const [editing, setEditing] = useState(null) // null | 'new' | row
  const [deleting, setDeleting] = useState(null)
  const [flash, setFlash] = useState('')

  const singular = config.singular
  const nameOf = (row) => row[config.columns[0].key]

  function done(message) {
    setEditing(null)
    setDeleting(null)
    setFlash(message)
    reload()
  }

  const addButton = (
    <Button onClick={() => setEditing('new')}>
      Add {singular}
    </Button>
  )

  return (
    <section>
      <PageHeader title={config.title} description={config.description} action={addButton} />
      <Alert tone="success" className="mb-4">
        {flash}
      </Alert>

      {config.searchable && (
        <div className="mb-4 max-w-sm">
          <FormField
            id={`${config.idKey}-search`}
            label={config.searchLabel ?? 'Search'}
            type="search"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
          />
        </div>
      )}

      {loading ? (
        <div className="flex items-center gap-2 text-sm text-slate-600" role="status">
          <Spinner /> Loading…
        </div>
      ) : error ? (
        <Alert>{error}</Alert>
      ) : items.length === 0 ? (
        debouncedSearch ? (
          <EmptyState title={`No ${config.title.toLowerCase()} match "${debouncedSearch}".`} />
        ) : (
          <EmptyState title={config.emptyText} action={addButton} />
        )
      ) : (
        <>
          {/* Cards on small screens */}
          <ul className="space-y-3 md:hidden">
            {items.map((row) => (
              <li key={row[config.idKey]} className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <p className="font-medium text-slate-900">{nameOf(row)}</p>
                <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                  {config.columns.slice(1).map((col) => (
                    <div key={col.key} className="contents">
                      <dt className="text-slate-500">{col.label}</dt>
                      <dd className="text-slate-800">{col.render ? col.render(row) : row[col.key]}</dd>
                    </div>
                  ))}
                </dl>
                <RowActions row={row} name={nameOf(row)} onEdit={setEditing} onDelete={setDeleting} />
              </li>
            ))}
          </ul>

          {/* Table from md up */}
          <div className="hidden overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200 md:block">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                <tr>
                  {config.columns.map((col) => (
                    <th key={col.key} scope="col" className={`px-4 py-3 ${col.align === 'right' ? 'text-right' : ''}`}>
                      {col.label}
                    </th>
                  ))}
                  <th scope="col" className="px-4 py-3 text-right">
                    <span className="sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {items.map((row) => (
                  <tr key={row[config.idKey]} className="hover:bg-slate-50">
                    {config.columns.map((col, i) => (
                      <td
                        key={col.key}
                        className={`px-4 py-3 ${i === 0 ? 'font-medium text-slate-900' : 'text-slate-700'} ${col.align === 'right' ? 'text-right tabular-nums' : ''}`}
                      >
                        {col.render ? col.render(row) : row[col.key]}
                      </td>
                    ))}
                    <td className="px-4 py-3 text-right whitespace-nowrap">
                      <RowActions row={row} name={nameOf(row)} onEdit={setEditing} onDelete={setDeleting} inline />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <Pagination pagination={pagination} onPageChange={setPage} />
        </>
      )}

      {editing && (
        <ResourceForm
          config={config}
          row={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={done}
        />
      )}

      {deleting && (
        <ConfirmDialog
          title={`Delete ${singular}?`}
          confirmLabel={`Delete ${singular}`}
          busyLabel="Deleting…"
          onClose={() => setDeleting(null)}
          onConfirm={async () => {
            const { data } = await api.delete(`${config.endpoint}/${deleting[config.idKey]}`)
            done(data.message)
          }}
        >
          <p>
            Delete <strong>{nameOf(deleting)}</strong>? This can't be undone.
          </p>
        </ConfirmDialog>
      )}
    </section>
  )
}

function RowActions({ row, name, onEdit, onDelete, inline = false }) {
  const link = 'font-medium underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-navy-600 rounded'
  return (
    <div className={`flex gap-4 text-sm ${inline ? 'justify-end' : 'mt-3'}`}>
      <button type="button" className={`${link} text-navy-700`} onClick={() => onEdit(row)}>
        Edit<span className="sr-only"> {name}</span>
      </button>
      <button type="button" className={`${link} text-red-700`} onClick={() => onDelete(row)}>
        Delete<span className="sr-only"> {name}</span>
      </button>
    </div>
  )
}

function ResourceForm({ config, row, onClose, onSaved }) {
  const [values, setValues] = useState(() =>
    Object.fromEntries(config.fields.map((f) => [f.name, row?.[f.name] ?? ''])),
  )
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    setFieldErrors({})
    try {
      const { data } = row
        ? await api.put(`${config.endpoint}/${row[config.idKey]}`, values)
        : await api.post(config.endpoint, values)
      onSaved(data.message)
    } catch (err) {
      setError(getErrorMessage(err))
      setFieldErrors(getFieldErrors(err))
      setSubmitting(false)
    }
  }

  const formId = `${config.idKey}-form`

  return (
    <Modal title={row ? `Edit ${config.singular}` : `Add ${config.singular}`} onClose={onClose}>
      <form id={formId} onSubmit={handleSubmit} noValidate className="space-y-4">
        {error && (
          <Alert>
            {error}
            {config.formErrorExtra?.(fieldErrors)}
          </Alert>
        )}
        {config.fields.map(({ name, label, ...inputProps }) => (
          <FormField
            key={name}
            id={`${formId}-${name}`}
            name={name}
            label={label}
            required
            value={values[name]}
            onChange={(e) => setValues((prev) => ({ ...prev, [name]: e.target.value }))}
            error={fieldErrors[name]}
            {...inputProps}
          />
        ))}
        <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={submitting} loadingText="Saving…">
            {row ? 'Save changes' : `Add ${config.singular}`}
          </Button>
        </div>
      </form>
    </Modal>
  )
}

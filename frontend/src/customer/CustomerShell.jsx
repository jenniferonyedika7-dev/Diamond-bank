import { useCallback, useEffect, useMemo, useState } from 'react'
import AppLayout from '../components/AppLayout.jsx'
import { api, getErrorMessage } from '../lib/api.js'
import { CustomerDataContext } from './customerData.js'

/** Loads the customer's overview once for the whole area; pages call reload() after a transfer. */
export default function CustomerShell() {
  const [state, setState] = useState({ status: 'loading', overview: null, error: '' })

  const reload = useCallback(
    () =>
      api
        .get('/api/v1/customer/overview')
        .then(({ data }) => setState({ status: 'ready', overview: data.data, error: '' }))
        .catch((err) => setState((prev) => ({ ...prev, status: prev.overview ? 'ready' : 'error', error: getErrorMessage(err) }))),
    [],
  )

  useEffect(() => {
    reload()
  }, [reload])

  const value = useMemo(() => ({ ...state, reload }), [state, reload])

  return (
    <CustomerDataContext.Provider value={value}>
      <AppLayout />
    </CustomerDataContext.Provider>
  )
}

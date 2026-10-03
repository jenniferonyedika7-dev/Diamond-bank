import { useCallback, useEffect, useState } from 'react'
import { api, getErrorMessage } from '../lib/api.js'

/**
 * Loads a list endpoint. Handles both plain lists (data: [...]) and
 * ApiResponse::paginated (data: { items, pagination, ...extra }). Any extra keys
 * (e.g. the audit log's filters) are returned as `extra`. Call reload() after a change.
 */
export function useApiList(endpoint, params) {
  const [state, setState] = useState({ items: [], pagination: null, extra: {}, loading: true, error: '' })
  const [version, setVersion] = useState(0)
  const query = JSON.stringify(params ?? {})

  useEffect(() => {
    let ignore = false
    api
      .get(endpoint, { params: JSON.parse(query) })
      .then(({ data }) => {
        if (ignore) return
        const paginated = data.data && !Array.isArray(data.data)
        const { items, pagination, ...extra } = paginated ? data.data : { items: data.data, pagination: null }
        setState({ items, pagination, extra, loading: false, error: '' })
      })
      .catch((err) => !ignore && setState((prev) => ({ ...prev, loading: false, error: getErrorMessage(err) })))
    return () => {
      ignore = true
    }
  }, [endpoint, query, version])

  const reload = useCallback(() => setVersion((v) => v + 1), [])

  return { ...state, reload }
}

/** value, delayed until the user stops typing. */
export function useDebounced(value, delay = 300) {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(timer)
  }, [value, delay])
  return debounced
}

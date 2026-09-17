import { useEffect, useState } from 'react'

export function useService<T>(loader: () => Promise<T>) {
  const [data, setData] = useState<T>()
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string>()
  const [revision, setRevision] = useState(0)

  useEffect(() => {
    let current = true
    loader()
      .then((value) => current && setData(value))
      .catch((caught) => current && setError(caught instanceof Error ? caught.message : 'This module could not be loaded. Try again.'))
      .finally(() => current && setLoading(false))
    return () => { current = false }
  }, [loader, revision])

  return { data, loading, error, reload: () => setRevision((value) => value + 1) }
}

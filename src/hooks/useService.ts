import { useEffect, useState } from 'react'

export function useService<T>(loader: () => Promise<T>) {
  const [data, setData] = useState<T>()
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string>()

  useEffect(() => {
    let current = true
    setLoading(true)
    loader()
      .then((value) => current && setData(value))
      .catch(() => current && setError('This simulated module could not be loaded. Try again.'))
      .finally(() => current && setLoading(false))
    return () => { current = false }
  }, [loader])

  return { data, loading, error }
}

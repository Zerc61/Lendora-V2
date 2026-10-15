/*
 * Sinkronisasi filter daftar dengan query URL. Nilai dibaca sekali saat
 * mount (tautan bisa langsung membawa filter), lalu ditulis ulang dengan
 * replace — tombol kembali tidak menumpuk kepingan filter lama.
 */

export function readQuery(route, keys) {
  const values = {}
  for (const key of keys) {
    const value = route.query[key]
    if (typeof value === 'string' && value !== '') {
      values[key] = value
    }
  }
  return values
}

export function writeQuery(router, route, values) {
  const query = {}
  for (const [key, value] of Object.entries(values)) {
    if (value !== '' && value != null) {
      query[key] = String(value)
    }
  }
  router.replace({ query }).catch(() => {})
}

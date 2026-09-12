function asIso(value) {
  if (!value) return null;
  if (value instanceof Date) return value.toISOString();
  const raw = String(value);
  const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw) ? `${raw.replace(" ", "T")}Z` : raw;
  const date = new Date(normalized);
  return Number.isNaN(date.getTime()) ? raw : date.toISOString();
}

export function notificationForSocket(row) {
  return {
    id: String(row.id),
    type: String(row.type || "system"),
    title: String(row.title || ""),
    body: String(row.body || ""),
    deep_link: row.deep_link ? String(row.deep_link) : null,
    created_at: asIso(row.created_at),
    read_at: asIso(row.read_at),
  };
}

export function notificationRowsAfter(rows, cursor) {
  const after = Number(cursor || 0);
  return (rows || []).filter((row) => Number(row.id || 0) > after);
}

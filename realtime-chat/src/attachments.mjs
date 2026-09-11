export function normalizeAttachment(raw) {
  if (!raw || typeof raw !== "object") return undefined;
  return {
    id: String(raw.id || ""),
    name: String(raw.name || "پیوست"),
    mimeType: String(raw.mime_type || raw.mimeType || "application/octet-stream"),
    size: Math.max(0, Number(raw.size || 0)),
    url: String(raw.url || raw.preview_url || raw.previewUrl || "") || undefined,
  };
}

export function attachmentForStorage(raw) {
  const attachment = normalizeAttachment(raw);
  if (!attachment) return null;
  return {
    id: attachment.id,
    name: attachment.name,
    mime_type: attachment.mimeType,
    size: attachment.size,
    url: attachment.url || "",
  };
}

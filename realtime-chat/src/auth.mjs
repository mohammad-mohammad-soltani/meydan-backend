import crypto from "node:crypto";

function safeEqual(a, b) {
  const left = Buffer.from(a);
  const right = Buffer.from(b);
  return left.length === right.length && crypto.timingSafeEqual(left, right);
}

export function verifySocketTicket(token, secret, now = Math.floor(Date.now() / 1000)) {
  if (!token || !secret) throw new Error("missing socket credentials");
  const [body, signature, extra] = String(token).split(".");
  if (!body || !signature || extra) throw new Error("invalid socket ticket");
  const expected = crypto.createHmac("sha256", secret).update(body).digest("base64url");
  if (!safeEqual(signature, expected)) throw new Error("invalid socket signature");
  let payload;
  try {
    payload = JSON.parse(Buffer.from(body, "base64url").toString("utf8"));
  } catch {
    throw new Error("invalid socket payload");
  }
  const uid = Number(payload?.uid);
  const exp = Number(payload?.exp);
  if (!Number.isInteger(uid) || uid <= 0 || !Number.isFinite(exp) || exp < now) throw new Error("expired socket ticket");
  return { ...payload, uid, exp };
}

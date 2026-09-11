import assert from "node:assert/strict";
import crypto from "node:crypto";
import test from "node:test";
import { verifySocketTicket } from "../src/auth.mjs";

function encode(value) {
  return Buffer.from(value).toString("base64url");
}

function ticket(payload, secret) {
  const body = encode(JSON.stringify(payload));
  const signature = crypto.createHmac("sha256", secret).update(body).digest("base64url");
  return `${body}.${signature}`;
}

test("accepts a valid non-expired socket ticket", () => {
  const secret = "test-secret";
  const now = Math.floor(Date.now() / 1000);
  const result = verifySocketTicket(ticket({ uid: 42, iat: now, exp: now + 60, jti: "abc" }, secret), secret, now);
  assert.equal(result.uid, 42);
});

test("rejects tampered and expired tickets", () => {
  const secret = "test-secret";
  const now = Math.floor(Date.now() / 1000);
  assert.throws(() => verifySocketTicket(`${ticket({ uid: 42, iat: now, exp: now + 60 }, secret)}x`, secret, now));
  assert.throws(() => verifySocketTicket(ticket({ uid: 42, iat: now - 120, exp: now - 1 }, secret), secret, now));
});

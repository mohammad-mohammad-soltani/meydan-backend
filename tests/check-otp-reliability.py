#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).parents[1] / "wp-content/plugins/meydan-core/src"
auth = (root / "Rest/AuthController.php").read_text(encoding="utf-8")
otp = (root / "Auth/OtpService.php").read_text(encoding="utf-8")
sms = (root / "Auth/SmsProvider.php").read_text(encoding="utf-8")
response = (root / "Support/Response.php").read_text(encoding="utf-8")

# Both OTP endpoints must turn unexpected exceptions into the API JSON envelope.
assert "private function otpOperation" in auth
assert "return $this->otpOperation('request'" in auth
assert "return $this->otpOperation('verify'" in auth
assert "otp_verify_exception" in auth

# A transport timeout is ambiguous: the provider may already have sent the SMS.
# Keep the challenge usable and return a 202 response to the client.
assert "isTransportUncertain" in otp
assert "'delivery_status' => 'uncertain'" in otp
assert "$wpdb->delete($table, ['challenge_id' => $challenge]);" in otp
assert "if (!$this->isTransportUncertain($sent))" in otp
assert "'status' => 503" in sms
assert "'status' => 502" in sms

# Every API error includes an opaque request id so production failures are traceable.
assert "'meta' => ['request_id' => self::requestId()]" in response
print("OTP reliability contract OK.")

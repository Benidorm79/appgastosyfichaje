import hashlib
import hmac


def test_php_and_service_signature_contract():
    secret = "test-secret"
    timestamp = "1700000000"
    request_id = "abc123"
    body = b'{"question":"prueba"}'
    canonical = "\n".join([timestamp, request_id, "POST", "/v1/query", hashlib.sha256(body).hexdigest()])
    signature = hmac.new(secret.encode(), canonical.encode(), hashlib.sha256).hexdigest()
    assert signature == "6799b7cbb1c9b3d8165fa2bd4b1afeb6fba2785b5a34f9416bed4f4e8dac8f39"


def test_signature_changes_if_body_is_modified():
    secret = b"test-secret"
    prefix = "1700000000\nabc123\nPOST\n/v1/query\n"
    original = prefix + hashlib.sha256(b'{"question":"prueba"}').hexdigest()
    changed = prefix + hashlib.sha256(b'{"question":"otra"}').hexdigest()
    original_signature = hmac.new(secret, original.encode(), hashlib.sha256).hexdigest()
    changed_signature = hmac.new(secret, changed.encode(), hashlib.sha256).hexdigest()
    assert not hmac.compare_digest(original_signature, changed_signature)

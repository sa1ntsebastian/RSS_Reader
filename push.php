<?php
declare(strict_types=1);

/**
 * Minimal Web-Push helper. Sends payload-less notifications via VAPID.
 * Payload-less keeps it simple — the service worker can fetch fresh data
 * itself when the push wakes it up.
 */

function push_keys_path(): string { return __DIR__ . '/data/vapid.json'; }
function push_subs_path(): string { return __DIR__ . '/data/push-subscriptions.json'; }
function push_seen_path(): string { return __DIR__ . '/data/push-seen.json'; }

/** Generate or load a VAPID keypair. Returns ['public'=>base64url, 'private'=>base64url]. */
function push_keys(): ?array {
    $path = push_keys_path();
    if (is_file($path)) {
        $k = json_decode((string)file_get_contents($path), true);
        if (is_array($k) && !empty($k['public']) && !empty($k['private'])) return $k;
    }
    if (!function_exists('openssl_pkey_new')) return null;
    $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$res) return null;
    $details = openssl_pkey_get_details($res);
    if (!$details || empty($details['ec'])) return null;
    // Public key = uncompressed point: 0x04 || x(32) || y(32)
    $pub  = "\x04" . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $priv = str_pad($details['ec']['d'], 32, "\x00", STR_PAD_LEFT);
    $keys = ['public' => push_b64u($pub), 'private' => push_b64u($priv)];
    @file_put_contents($path, json_encode($keys, JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $keys;
}

function push_b64u(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function push_b64u_decode(string $s): string {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return base64_decode($s);
}

/** Build a VAPID JWT for the given push origin. */
function push_jwt(string $audience, string $subject, array $keys): ?string {
    $header  = push_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = push_b64u(json_encode([
        'aud' => $audience,
        'exp' => time() + 12 * 3600,
        'sub' => $subject,
    ]));
    $input = $header . '.' . $payload;

    $privBin = push_b64u_decode($keys['private']);
    // Build a PEM private key from the raw 32-byte scalar + public point
    $pubBin = push_b64u_decode($keys['public']);
    $pem = push_ec_pem($privBin, $pubBin);
    if (!$pem) return null;
    $pkey = openssl_pkey_get_private($pem);
    if (!$pkey) return null;

    $signature = '';
    if (!openssl_sign($input, $signature, $pkey, OPENSSL_ALGO_SHA256)) return null;

    // OpenSSL gives DER-encoded ECDSA — convert to raw r||s (64 bytes)
    $raw = push_der_to_raw($signature);
    if ($raw === null) return null;
    return $input . '.' . push_b64u($raw);
}

/** Construct a PEM-encoded ECPrivateKey for prime256v1 from raw scalar + uncompressed point. */
function push_ec_pem(string $priv32, string $pub65): ?string {
    if (strlen($priv32) !== 32 || strlen($pub65) !== 65) return null;
    // ECPrivateKey ::= SEQUENCE {
    //   version INTEGER (1),
    //   privateKey OCTET STRING,
    //   parameters [0] EXPLICIT OBJECT IDENTIFIER (P-256 = 1.2.840.10045.3.1.7),
    //   publicKey [1] EXPLICIT BIT STRING
    // }
    $oidP256 = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";    // OID 1.2.840.10045.3.1.7
    $params  = "\xa0" . push_asn_len(strlen($oidP256)) . $oidP256;
    $bitStr  = "\x03" . push_asn_len(1 + strlen($pub65)) . "\x00" . $pub65;
    $pubExpl = "\xa1" . push_asn_len(strlen($bitStr)) . $bitStr;
    $privOct = "\x04" . push_asn_len(32) . $priv32;
    $version = "\x02\x01\x01";
    $body    = $version . $privOct . $params . $pubExpl;
    $seq     = "\x30" . push_asn_len(strlen($body)) . $body;
    $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($seq), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    return $pem;
}

function push_asn_len(int $len): string {
    if ($len < 0x80) return chr($len);
    $hex = ltrim(sprintf('%x', $len), '0');
    if (strlen($hex) % 2) $hex = '0' . $hex;
    $bin = hex2bin($hex);
    return chr(0x80 | strlen($bin)) . $bin;
}

function push_der_to_raw(string $der): ?string {
    // Parse: SEQUENCE { INTEGER r, INTEGER s }
    $i = 0;
    if (($der[$i++] ?? '') !== "\x30") return null;
    [$slen, $i] = push_asn_read_len($der, $i);
    if ($slen === null) return null;
    $end = $i + $slen;

    $rs = [];
    for ($k = 0; $k < 2; $k++) {
        if (($der[$i++] ?? '') !== "\x02") return null;
        [$ilen, $i] = push_asn_read_len($der, $i);
        if ($ilen === null) return null;
        $val = substr($der, $i, $ilen);
        $i += $ilen;
        // Trim leading zero (added when high bit set)
        $val = ltrim($val, "\x00");
        if (strlen($val) > 32) return null;
        $rs[] = str_pad($val, 32, "\x00", STR_PAD_LEFT);
    }
    if ($i !== $end) return null;
    return $rs[0] . $rs[1];
}

function push_asn_read_len(string $der, int $i): array {
    $b = ord($der[$i++] ?? "\x00");
    if ($b < 0x80) return [$b, $i];
    $n = $b & 0x7f;
    if ($n === 0 || $n > 4) return [null, $i];
    $len = 0;
    for ($k = 0; $k < $n; $k++) $len = ($len << 8) | ord($der[$i++] ?? "\x00");
    return [$len, $i];
}

function push_load_subs(): array {
    $f = push_subs_path();
    if (!is_file($f)) return [];
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function push_save_subs(array $subs): void {
    $f = push_subs_path();
    $tmp = $f . '.tmp';
    @file_put_contents($tmp, json_encode($subs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @rename($tmp, $f);
}

/**
 * RFC 8291 + RFC 8188 (aes128gcm) payload encryption for Web Push.
 * Returns the encrypted body bytes ready for transmission, or null on failure.
 */
function push_encrypt_payload(string $plaintext, string $uaPub, string $authSecret): ?string {
    if (strlen($uaPub) !== 65 || strlen($authSecret) !== 16) return null;
    if (!function_exists('openssl_pkey_derive')) return null;

    // Ephemeral server keypair
    $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$eph) return null;
    $det = openssl_pkey_get_details($eph);
    if (!$det || empty($det['ec'])) return null;
    $asPub = "\x04" . str_pad($det['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($det['ec']['y'], 32, "\x00", STR_PAD_LEFT);

    $uaPubKey = openssl_pkey_get_public(push_ec_pub_pem($uaPub));
    if (!$uaPubKey) return null;
    $sharedSecret = openssl_pkey_derive($uaPubKey, $eph, 32);
    if (!$sharedSecret) return null;

    // RFC 8291 key derivation
    $prkKey = hash_hmac('sha256', $sharedSecret, $authSecret, true);
    $keyInfo = "WebPush: info\x00" . $uaPub . $asPub;
    $ikm = substr(hash_hmac('sha256', $keyInfo . "\x01", $prkKey, true), 0, 32);

    // RFC 8188 aes128gcm content encoding
    $salt  = random_bytes(16);
    $prk   = hash_hmac('sha256', $ikm, $salt, true);
    $cek   = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01",     $prk, true), 0, 12);

    // Plaintext + last-record delimiter (0x02)
    $padded = $plaintext . "\x02";
    $tag = '';
    $ciphertext = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) return null;

    // Body: salt(16) || record_size(4) || idlen(1) || keyid(=asPub,65) || ct+tag
    return $salt . pack('N', 4096) . chr(strlen($asPub)) . $asPub . $ciphertext . $tag;
}

/** Build a SubjectPublicKeyInfo PEM from a raw uncompressed P-256 point. */
function push_ec_pub_pem(string $pub65): string {
    $oidEcPub = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";
    $oidP256  = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
    $alg = "\x30" . push_asn_len(strlen($oidEcPub) + strlen($oidP256)) . $oidEcPub . $oidP256;
    $bit = "\x03" . push_asn_len(1 + strlen($pub65)) . "\x00" . $pub65;
    $seq = "\x30" . push_asn_len(strlen($alg) + strlen($bit)) . $alg . $bit;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($seq), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function push_default_subject(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Strip port; mailto: must be a syntactically reasonable address.
    $host = preg_replace('/:\d+$/', '', $host);
    if ($host === 'localhost' || $host === '') $host = 'example.com';
    return 'mailto:noreply@' . $host;
}

/** Send one push notification to a single subscription. */
function push_send_one(array $sub, array $vapid, string $subject, ?array $payload = null, ?string &$err = null, ?string &$respOut = null): int {
    $endpoint = (string)($sub['endpoint'] ?? '');
    if ($endpoint === '') { $err = 'no_endpoint'; return -1; }
    $aud = (string)parse_url($endpoint, PHP_URL_SCHEME) . '://' . (string)parse_url($endpoint, PHP_URL_HOST);
    $jwt = push_jwt($aud, $subject, $vapid);
    if (!$jwt) { $err = 'jwt_failed'; return -1; }

    $headers = [
        'Authorization: vapid t=' . $jwt . ', k=' . $vapid['public'],
        'TTL: 86400',
    ];
    $body = '';
    if ($payload !== null) {
        $uaPub = push_b64u_decode((string)($sub['keys']['p256dh'] ?? ''));
        $auth  = push_b64u_decode((string)($sub['keys']['auth']    ?? ''));
        if (strlen($uaPub) !== 65 || strlen($auth) !== 16) {
            $err = 'bad_subscription_keys'; return -1;
        }
        $enc = push_encrypt_payload(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $uaPub, $auth);
        if ($enc === null) { $err = 'encrypt_failed'; return -1; }
        $body = $enc;
        $headers[] = 'Content-Type: application/octet-stream';
        $headers[] = 'Content-Encoding: aes128gcm';
    }
    $headers[] = 'Content-Length: ' . strlen($body);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false) $err = 'curl: ' . curl_error($ch);
    elseif ($code >= 300) $err = 'http ' . $code;
    $respOut = is_string($resp) ? $resp : '';
    curl_close($ch);
    return $code;
}

function push_send_all(string $subject, ?array $payload = null): array {
    $vapid = push_keys();
    if (!$vapid) return ['error' => 'no keys', 'sent' => 0, 'gone' => 0, 'failed' => 0];
    $subs = push_load_subs();
    $stats = ['sent' => 0, 'gone' => 0, 'failed' => 0, 'detail' => []];
    $alive = [];
    foreach ($subs as $s) {
        if (empty($s['endpoint'])) continue;
        $err = null; $resp = null;
        $code = push_send_one($s, $vapid, $subject, $payload, $err, $resp);
        $host = parse_url($s['endpoint'], PHP_URL_HOST) ?: 'unknown';
        if ($code >= 200 && $code < 300) { $stats['sent']++; $alive[] = $s; }
        elseif ($code === 404 || $code === 410) { $stats['gone']++; }
        else {
            $stats['failed']++; $alive[] = $s;
            $stats['detail'][] = [
                'host'  => $host,
                'code'  => $code,
                'error' => $err,
                'body'  => mb_substr((string)$resp, 0, 280),
            ];
        }
    }
    if ($stats['gone'] > 0) push_save_subs($alive);
    return $stats;
}

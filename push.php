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

/** Send a payload-less push to one subscription endpoint. Returns HTTP status. */
function push_send_one(string $endpoint, array $keys, string $subject, ?array $payload = null): int {
    $aud = (string)parse_url($endpoint, PHP_URL_SCHEME) . '://' . (string)parse_url($endpoint, PHP_URL_HOST);
    $jwt = push_jwt($aud, $subject, $keys);
    if (!$jwt) return -1;

    $headers = [
        'Authorization: vapid t=' . $jwt . ', k=' . $keys['public'],
        'TTL: 86400',
    ];
    $body = '';
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Encoding: identity';
        // NOTE: encrypted payload requires aes128gcm/AES-GCM with ECDH; not
        // implemented here. Browsers may refuse identity-encoded bodies.
        // Default operation is payload-less (body=''), which always works.
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
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/** Send a notification to every stored subscription. Drops gone (404/410) ones. */
function push_send_all(string $subject, ?array $payload = null): array {
    $keys = push_keys();
    if (!$keys) return ['error' => 'no keys'];
    $subs = push_load_subs();
    $stats = ['sent' => 0, 'gone' => 0, 'failed' => 0];
    $alive = [];
    foreach ($subs as $s) {
        if (empty($s['endpoint'])) continue;
        $code = push_send_one($s['endpoint'], $keys, $subject, $payload);
        if ($code >= 200 && $code < 300) { $stats['sent']++; $alive[] = $s; }
        elseif ($code === 404 || $code === 410) { $stats['gone']++; }
        else { $stats['failed']++; $alive[] = $s; }
    }
    if ($stats['gone'] > 0) push_save_subs($alive);
    return $stats;
}

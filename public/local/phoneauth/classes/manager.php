<?php

namespace local_phoneauth;

defined('MOODLE_INTERNAL') || die();

class manager {
    private const SESSION_CODE = 'local_phoneauth_sms_code';
    private const SESSION_PHONE = 'local_phoneauth_sms_phone';
    private const SESSION_TIME = 'local_phoneauth_sms_time';

    public static function normalize_phone(string $phone): string {
        return preg_replace('/[^0-9]/', '', trim($phone));
    }

    public static function is_valid_phone(string $phone): bool {
        return (bool) preg_match('/^1[3-9][0-9]{9}$/', self::normalize_phone($phone));
    }

    public static function get_last_sent_time(): int {
        return (int) ($_SESSION[self::SESSION_TIME] ?? 0);
    }

    public static function get_send_interval(): int {
        return max(1, (int) get_config('local_phoneauth', 'send_interval'));
    }

    public static function get_code_expiry(): int {
        return max(60, (int) get_config('local_phoneauth', 'code_expiry'));
    }

    public static function store_code(string $phone, string $code): void {
        $_SESSION[self::SESSION_PHONE] = self::normalize_phone($phone);
        $_SESSION[self::SESSION_CODE] = $code;
        $_SESSION[self::SESSION_TIME] = time();
    }

    public static function clear_code(): void {
        unset($_SESSION[self::SESSION_PHONE], $_SESSION[self::SESSION_CODE], $_SESSION[self::SESSION_TIME]);
    }

    public static function verify_code(string $phone, string $code): bool {
        $phone = self::normalize_phone($phone);
        $storedphone = (string) ($_SESSION[self::SESSION_PHONE] ?? '');
        $storedcode = (string) ($_SESSION[self::SESSION_CODE] ?? '');
        $storedtime = (int) ($_SESSION[self::SESSION_TIME] ?? 0);

        if (!$storedphone || !$storedcode || !$storedtime) {
            return false;
        }

        if ((time() - $storedtime) > self::get_code_expiry()) {
            return false;
        }

        return hash_equals($storedphone, $phone) && hash_equals($storedcode, trim($code));
    }

    public static function send_code(string $phone): array {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $phone = self::normalize_phone($phone);
        if (!self::is_valid_phone($phone)) {
            return ['ok' => false, 'message' => get_string('phoneinvalid', 'local_phoneauth')];
        }

        $lastsent = self::get_last_sent_time();
        if ($lastsent && (time() - $lastsent) < self::get_send_interval()) {
            return ['ok' => false, 'message' => get_string('smsratelimited', 'local_phoneauth')];
        }

        $code = sprintf('%06d', random_int(0, 999999));
        self::store_code($phone, $code);

        if (!empty(get_config('local_phoneauth', 'demo_mode'))) {
            return ['ok' => true, 'message' => 'demo', 'code' => $code];
        }

        $accesskeyid = (string) get_config('local_phoneauth', 'aliyun_accesskeyid');
        $accesskeysecret = (string) get_config('local_phoneauth', 'aliyun_accesskeysecret');
        $signname = (string) get_config('local_phoneauth', 'aliyun_signname');
        $templatecode = (string) get_config('local_phoneauth', 'aliyun_templatecode');

        if ($accesskeyid === '' || $accesskeysecret === '' || $signname === '' || $templatecode === '') {
            return ['ok' => false, 'message' => get_string('smsnotconfigured', 'local_phoneauth')];
        }

        $host = 'dysmsapi.aliyuncs.com';
        $params = [
            'PhoneNumbers' => $phone,
            'SignName' => $signname,
            'TemplateCode' => $templatecode,
            'TemplateParam' => json_encode(['code' => $code], JSON_UNESCAPED_UNICODE),
        ];

        $date = gmdate('Y-m-d\\TH:i:s\\Z');
        $nonce = uniqid('', true);
        $canonicalquerystring = preg_replace('/%5B[0-9]+%5D/simU', '', http_build_query($params));
        $canonicalquerystring = str_replace('&amp;', '&', $canonicalquerystring);

        $canonicalheaders = [
            'host:' . $host,
            'x-acs-action:SendSms',
            'x-acs-date:' . $date,
            'x-acs-signature-nonce:' . $nonce,
            'x-acs-version:2017-05-25',
        ];

        $signedheaders = 'host;x-acs-action;x-acs-date;x-acs-signature-nonce;x-acs-version';
        $canonicalrequest = "GET\n/\n" . $canonicalquerystring . "\n" . implode("\n", $canonicalheaders) . "\n\n" .
            $signedheaders . "\n" . hash('sha256', '');
        $stringtosign = "ACS3-HMAC-SHA256\n" . hash('sha256', $canonicalrequest);
        $signature = bin2hex(hash_hmac('sha256', $stringtosign, $accesskeysecret, true));
        $authorization = 'ACS3-HMAC-SHA256 Credential=' . $accesskeyid . ',SignedHeaders=' . $signedheaders . ',Signature=' . $signature;

        $curl = new \curl();
        $curl->setHeader('Host: ' . $host);
        $curl->setHeader('Authorization: ' . $authorization);
        $curl->setHeader('x-acs-action: SendSms');
        $curl->setHeader('x-acs-version: 2017-05-25');
        $curl->setHeader('x-acs-signature-nonce: ' . $nonce);
        $curl->setHeader('x-acs-date: ' . $date);

        $url = 'https://' . $host . '/?' . $canonicalquerystring;
        $response = $curl->get($url);
        $result = json_decode($response, true);

        if (($result['Code'] ?? '') === 'OK') {
            return ['ok' => true, 'message' => 'sent'];
        }

        return [
            'ok' => false,
            'message' => get_string('smssendfailed', 'local_phoneauth') . ': ' . ($result['Message'] ?? 'unknown'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace ASM\Media;

final class RemoteFetcher
{
    /** @var callable */
    private $resolver;
    private int $maxRedirects;

    public function __construct(?callable $resolver = null, int $maxRedirects = 3)
    {
        $this->resolver = $resolver ?? array($this, 'resolve');
        $this->maxRedirects = $maxRedirects;
    }

    /** @return string|\WP_Error */
    public function fetch(string $url, int $maxBytes)
    {
        if ($maxBytes < 1) {
            return new \WP_Error('asm_remote_invalid_limit', 'Limit pobrania musi być dodatni.', array('status' => 400));
        }

        $current = $url;
        for ($redirect = 0; $redirect <= $this->maxRedirects; $redirect++) {
            $safe = $this->validateUrl($current);
            if ($safe instanceof \WP_Error) {
                return $safe;
            }
            if (! function_exists('wp_safe_remote_get')) {
                return new \WP_Error('asm_remote_unavailable', 'Klient HTTP WordPressa nie jest dostępny.', array('status' => 503));
            }

            $response = wp_safe_remote_get($current, array(
                'timeout' => 20,
                'redirection' => 0,
                'reject_unsafe_urls' => true,
                'limit_response_size' => $maxBytes + 1,
                'headers' => array('Accept' => 'image/*,application/pdf'),
            ));
            if (is_wp_error($response)) {
                return $response;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            if (in_array($status, array(301, 302, 303, 307, 308), true)) {
                $location = (string) wp_remote_retrieve_header($response, 'location');
                if ($location === '') {
                    return new \WP_Error('asm_remote_invalid_redirect', 'Przekierowanie nie zawiera adresu docelowego.', array('status' => 502));
                }
                $current = $this->absoluteRedirect($current, $location);
                if ($current === '') {
                    return new \WP_Error('asm_remote_invalid_redirect', 'Nieprawidłowy adres przekierowania.', array('status' => 502));
                }
                continue;
            }
            if ($status < 200 || $status >= 300) {
                return new \WP_Error('asm_remote_http_error', 'Serwer źródłowy zwrócił błąd.', array('status' => 502, 'remote_status' => $status));
            }

            $body = (string) wp_remote_retrieve_body($response);
            if (strlen($body) > $maxBytes) {
                return new \WP_Error('asm_remote_too_large', 'Pobrany plik przekracza limit.', array('status' => 413));
            }
            return $body;
        }

        return new \WP_Error('asm_remote_too_many_redirects', 'Przekroczono limit przekierowań.', array('status' => 502));
    }

    /** @return true|\WP_Error */
    private function validateUrl(string $url)
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return new \WP_Error('asm_remote_https_required', 'Dozwolone są wyłącznie adresy HTTPS.', array('status' => 400));
        }
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return new \WP_Error('asm_remote_invalid_url', 'Nieprawidłowy adres zdalny.', array('status' => 400));
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? array($host) : (array) call_user_func($this->resolver, $host);
        if ($addresses === array()) {
            return new \WP_Error('asm_remote_dns_failed', 'Nie udało się rozwiązać nazwy hosta.', array('status' => 502));
        }
        foreach ($addresses as $address) {
            if (
                ! filter_var(
                    $address,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                )
            ) {
                return new \WP_Error(
                    'asm_remote_private_address',
                    'Adres wskazuje na prywatną lub zastrzeżoną sieć.',
                    array('status' => 400)
                );
            }
        }
        return true;
    }

    /** @return array<int,string> */
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = array();
        foreach ((array) $records as $record) {
            if (! empty($record['ip'])) {
                $addresses[] = (string) $record['ip'];
            }
            if (! empty($record['ipv6'])) {
                $addresses[] = (string) $record['ipv6'];
            }
        }
        return array_values(array_unique($addresses));
    }

    private function absoluteRedirect(string $base, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME)) {
            return $location;
        }
        $baseParts = parse_url($base);
        if (! is_array($baseParts) || empty($baseParts['host'])) {
            return '';
        }
        $authority = 'https://' . $baseParts['host'];
        if (isset($baseParts['port'])) {
            $authority .= ':' . (int) $baseParts['port'];
        }
        if (strpos($location, '/') === 0) {
            return $authority . $location;
        }
        $basePath = (string) ($baseParts['path'] ?? '/');
        return $authority . rtrim(str_replace('\\', '/', dirname($basePath)), '/') . '/' . $location;
    }
}

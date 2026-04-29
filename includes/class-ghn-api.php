<?php
/**
 * GHN API Wrapper
 *
 * Handles all communication with Giao Hang Nhanh API.
 */

defined('ABSPATH') || exit;

class GHN_API {

    private const BASE_URL = 'https://online-gateway.ghn.vn/shiip/public-api';
    private const TIMEOUT   = 15;

    private string $token;
    private int    $shop_id;

    public function __construct() {
        $this->token    = (string) get_option('ghn_token', '');
        $this->shop_id  = (int) get_option('ghn_shop_id', 0);
    }

    public function is_configured(): bool {
        return !empty($this->token) && $this->shop_id > 0;
    }

    /**
     * Create a shipping order on GHN.
     */
    public function create_order(array $params): array {
        return $this->request('POST', '/v2/shipping-order/create', $params);
    }

    /**
     * Get order detail by GHN order_code.
     */
    public function get_order(string $order_code): array {
        return $this->request('POST', '/v2/shipping-order/detail', [
            'order_code' => $order_code,
        ]);
    }

    /**
     * Calculate shipping fee.
     */
    public function calc_fee(array $params): array {
        return $this->request('POST', '/v2/shipping-order/fee', $params);
    }

    /**
     * Get available services between two districts.
     */
    public function get_services(int $from_district, int $to_district): array {
        return $this->request('POST', '/v2/shipping-order/available-services', [
            'shop_id'      => $this->shop_id,
            'from_district' => $from_district,
            'to_district'   => $to_district,
        ]);
    }

    /**
     * Get districts list.
     */
    public function get_districts(): array {
        return $this->request('GET', '/master-data/district');
    }

    /**
     * Get wards by district_id.
     */
    public function get_wards(int $district_id): array {
        return $this->request('POST', '/master-data/ward', [
            'district_id' => $district_id,
        ]);
    }

    /**
     * Get pick shifts.
     */
    public function get_pick_shifts(): array {
        return $this->request('GET', '/v2/shift/date');
    }

    /**
     * Print GHN order (get A5 label URL).
     */
    public function print_order(array $order_codes): array {
        return $this->request('POST', '/v2/a5/gen-token', [
            'order_codes' => $order_codes,
        ]);
    }

    /* ------------------------------------------------------------------
     *  Internal HTTP helper
     * ----------------------------------------------------------------*/

    private function request(string $method, string $endpoint, array $body = []): array {
        $url = self::BASE_URL . $endpoint;

        $headers = [
            'Content-Type'  => 'application/json',
            'Token'         => $this->token,
            'ShopId'        => (string) $this->shop_id,
        ];

        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => self::TIMEOUT,
        ];

        if ('POST' === $method && !empty($body)) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
                'data'    => null,
            ];
        }

        $code   = wp_remote_retrieve_response_code($response);
        $result = json_decode(wp_remote_retrieve_body($response), true);

        if (200 !== $code || !isset($result['code']) || 200 !== $result['code']) {
            return [
                'success' => false,
                'message' => $result['message'] ?? "HTTP $code",
                'data'    => null,
                'raw'     => $result,
            ];
        }

        return [
            'success' => true,
            'message' => 'OK',
            'data'    => $result['data'] ?? null,
        ];
    }
}

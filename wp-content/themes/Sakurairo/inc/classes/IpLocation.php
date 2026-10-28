<?php

namespace Sakura\API;

/**
 * 获取IP地理位置
 *
 * 数据源说明：
 * - 主用 ipwho.is：HTTPS、支持中文与 IPv6、无频率限制，实测从源站 20/20 成功、约 0.7s。
 * - 备用 ip-api.com：原实现用这个，但它是 HTTP 且从源站实测约 40% 超时（5s 才失败），
 *   只在主源失败时兜底，超时被压到很短，避免把页面拖住。
 *
 * 失败处理：任何取源失败都不再向页面抛警告，而是写日志并做 10 分钟负缓存，
 * 保证同一个 IP 在一个窗口期内最多付出一次超时代价。
 */
class IpLocation
{
    /** 失败后多久内不再尝试取源（秒）。 */
    const OUTAGE_TTL = 600;

    /** 主源超时（秒），可通过过滤器 sakura_geo_lookup_timeout 调整。 */
    const DEFAULT_TIMEOUT = 3;

    /** @var string 要查询的IP地址 */
    private $ip;
    /** @var string 国家 */
    private $country = '';
    /** @var string 地区 */
    private $region = '';
    /** @var string 城市 */
    private $city = '';

    public function __construct(string $ip)
    {
        $this->ip = $ip;
    }

    /**
     * 取源超时时间。
     *
     * @return float
     */
    private function getTimeout()
    {
        $timeout = (float) apply_filters('sakura_geo_lookup_timeout', self::DEFAULT_TIMEOUT);
        return $timeout > 0 ? $timeout : (float) self::DEFAULT_TIMEOUT;
    }

    /**
     * 是否为“取源不可用”窗口期。
     *
     * @return bool
     */
    private function isOutage()
    {
        return (bool) get_transient('ip_location_outage');
    }

    /**
     * 标记取源不可用，窗口期内直接返回失败，不再发起请求。
     *
     * @return void
     */
    private function markOutage()
    {
        set_transient('ip_location_outage', '1', self::OUTAGE_TTL);
    }

    /**
     * 记录一次失败。
     *
     * 这里用 error_log 而不是 trigger_error：线上 php.ini 是 display_errors=On
     * 且 WP_DEBUG=false（WordPress 因此不会把 display_errors 关掉），
     * trigger_error(E_USER_WARNING) 会把警告直接印到访客看到的页面上。
     * 只在进入不可用窗口时记录一次，避免刷日志。
     *
     * @param string $message 失败原因。
     * @return void
     */
    private function logFailure($message)
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            // 生产环境仅在新窗口开启时记一条，避免重复刷屏。
            if ($this->isOutage()) {
                return;
            }
        }
        error_log('[iro-geo] ' . $message);
    }

    /**
     * 通过 ipwho.is 获取IP地理位置。
     *
     * 接口文档：https://ipwho.is/
     *
     * @return boolean 成功返回true，失败返回false
     */
    private function getIpLocationFromIpWhois()
    {
        $languages = array(
            'fr'    => 'fr',
            'en'    => 'en',
            'zh_CN' => 'zh-CN',
            'de'    => 'de',
            'es'    => 'es',
            'pt_BR' => 'pt-BR',
            'ja'    => 'ja',
            'ru'    => 'ru',
        );

        $lang = get_locale();
        $lang = isset($languages[$lang]) ? $languages[$lang] : 'en';

        $url = 'https://ipwho.is/' . rawurlencode($this->ip) . '?lang=' . rawurlencode($lang);

        $response = wp_remote_get($url, array(
            'timeout'     => $this->getTimeout(),
            'redirection' => 2,
            'headers'     => array('Accept' => 'application/json'),
        ));

        if (is_wp_error($response)) {
            $this->logFailure('ipwho.is 请求失败：' . $response->get_error_message());
            return false;
        }

        if (200 !== (int) wp_remote_retrieve_response_code($response)) {
            $this->logFailure('ipwho.is 返回状态码 ' . wp_remote_retrieve_response_code($response));
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            $this->logFailure('ipwho.is 返回的不是 JSON');
            return false;
        }

        if (empty($data['success'])) {
            $message = isset($data['message']) ? $data['message'] : 'unknown error';
            $this->logFailure('ipwho.is 查询失败：' . $message);
            return false;
        }

        $this->country = isset($data['country']) ? (string) $data['country'] : '';
        $this->region  = isset($data['region']) ? (string) $data['region'] : '';
        $this->city    = isset($data['city']) ? (string) $data['city'] : '';
        return true;
    }

    /**
     * 通过 IP-API 兜底获取IP地理位置。
     *
     * 接口文档：https://ip-api.com/docs/api:json
     *
     * @return boolean 成功返回true，失败返回false
     */
    private function getIpLocationByIpApi()
    {
        // 检查速率限制
        if (get_transient('ip_location_rate_limit')) {
            $this->logFailure('ip-api.com 处于速率限制窗口');
            return false;
        }

        $languages = array(
            'fr'    => 'fr',
            'en'    => 'en',
            'zh_CN' => 'zh-CN',
            'de'    => 'de',
            'es'    => 'es',
            'pt_BR' => 'pt-BR',
            'ja'    => 'ja',
            'ru'    => 'ru',
        );

        $lang = get_locale();
        $lang = isset($languages[$lang]) ? $languages[$lang] : 'en';

        $url = 'http://ip-api.com/json/' . rawurlencode($this->ip) . '?fields=49177&lang=' . rawurlencode($lang);

        // 兜底源给更短的超时，避免主源已经慢过之后又把页面拖满。
        $timeout = min($this->getTimeout(), 2.0);

        $response = wp_remote_get($url, array('timeout' => $timeout));

        if (is_wp_error($response)) {
            $this->logFailure('ip-api.com 请求失败：' . $response->get_error_message());
            return false;
        }

        $headers = wp_remote_retrieve_headers($response);
        if (isset($headers['X-Rl'], $headers['X-Ttl'])) {
            $remaining = (int) $headers['X-Rl'];
            if ($remaining <= 2) {
                set_transient('ip_location_rate_limit', 'is_limit', max(1, (int) $headers['X-Ttl']));
            }
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['status']) || 'success' !== $data['status']) {
            $message = isset($data['message']) ? $data['message'] : '返回数据不可用';
            $this->logFailure('ip-api.com 查询失败：' . $message);
            return false;
        }

        $this->country = isset($data['country']) ? (string) $data['country'] : '';
        $this->region  = isset($data['regionName']) ? (string) $data['regionName'] : '';
        $this->city    = isset($data['city']) ? (string) $data['city'] : '';
        return true;
    }

    /**
     * 输出地理位置信息
     *
     * @return array 地理位置信息数组
     */
    private function outputLocation()
    {
        return array(
            'country' => $this->country,
            'region'  => $this->region,
            'city'    => $this->city,
        );
    }

    /**
     * 检查IP地理位置信息的字段是否完整
     *
     * 只要求国家非空：不少 IP（尤其移动网络、IPv6）没有 region/city 粒度，
     * 原实现要求三者齐全，会把“查到了但没有城市”误判成失败并抛警告。
     *
     * @param array $data 地理位置信息数组
     * @return boolean true，可用；false，不可用
     */
    private function checkCompleteness(array $data)
    {
        return !empty($data['country']);
    }

    /**
     * 检查IP地址的合法性（排除空地址、非法地址以及私有地址和保留地址）
     *
     * @param string $ip 待检查的IP地址
     * @return boolean true，合法；false，非法
     */
    public static function checkIpValid(string $ip)
    {
        if (empty($ip)) {
            return false;
        }
        // 检查是否是合法的 IPv4 或 IPv6 地址
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // 检查是否是保留地址
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * 获取IP地址的地理位置信息
     *
     * @return mixed 成功时返回地理位置信息数组array('country' => '国家','region' => '地区（省份）','city' => '城市')；失败时返回false
     */
    public function getLocation()
    {
        // 检查IP地址的合法性
        if (!static::checkIpValid($this->ip)) {
            return false;
        }

        // 取源不可用窗口期内直接失败，不发起请求（负缓存）。
        if ($this->isOutage()) {
            return false;
        }

        $ok = $this->getIpLocationFromIpWhois() || $this->getIpLocationByIpApi();

        $data = $this->outputLocation();
        if ($ok && $this->checkCompleteness($data)) {
            return $data;
        }

        $this->markOutage();
        return false;
    }
}

/**
 * IP地理位置解析输出
 */
class IpLocationParse
{
    //国家
    public $country;
    //地区
    public $region;
    //城市
    public $city;

    public function __construct(array $data)
    {
        $this->country = isset($data['country']) ? $data['country'] : '';
        $this->region  = isset($data['region']) ? $data['region'] : '';
        $this->city    = isset($data['city']) ? $data['city'] : '';
    }

    /**
     * 通过HTML格式输出IP地理位置信息
     *
     * @return string HTML格式地理位置信息
     */
    public function getLocationHtml()
    {
        $html = '';
        $html .= '<div class="ip-location">';
        $html .= '<div class="ip-location-country">' . $this->country . '</div>';
        $html .= '<div class="ip-location-region">' . $this->region . '</div>';
        $html .= '<div class="ip-location-city">' . $this->city . '</div>';
        $html .= '</div>';
        return $html;
    }

    /**
     * 获取简洁的IP地址地理信息
     *
     * 把非空的部分拼起来，避免缺省字段造成连续空格。
     *
     * @return string “国家 地区（省份） 城市”
     */
    public function getLocationConcision()
    {
        $parts = array_filter(array($this->country, $this->region, $this->city), 'strlen');
        return implode(' ', $parts);
    }

    /**
     * 通过评论ID获取IP地理位置信息，当数据库里不存在IP地理位置信息时会自动请求接口获取
     *
     * @param int $comment_id 评论ID
     * @return string 成功时返回IP地理位置信息：“国家 地区（省份） 城市”；失败时返回“Unknown”或“Reserved Address”或“Empty Address”
     */
    public static function getIpLocationByCommentId(int $commentId)
    {
        $ipLocation = get_comment_meta($commentId, 'iro_ip_location', true);
        if ($ipLocation) {
            $location = new IpLocationParse($ipLocation);
            return $location->getLocationConcision();
        }

        // 解析IP地址地理位置
        $commentIp = get_comment_author_IP($commentId);
        if (empty($commentIp)) {
            return __('Empty Address');
        }
        if (!IPLocation::checkIpValid($commentIp)) {
            return __('Reserved Address');
        }

        $ipLocation = new IPLocation($commentIp);
        $location = $ipLocation->getLocation();
        // 记录IP地理位置信息
        if ($location) {
            if (iro_opt('save_location')) {
                add_comment_meta($commentId, 'iro_ip_location', $location);
            }
            $locationParse = new IpLocationParse($location);
            return $locationParse->getLocationConcision();
        }

        return __('Unknown');
    }

    /**
     * 获取单个IP地址地理位置信息
     *
     * @param string $ip IP地址
     * @return string 成功时返回IP地理位置信息：“国家 地区（省份） 城市”；失败时返回“Unknown”或“Reserved Address”或“Empty Address”
     */
    public static function getIpLocationByIp(string $ip)
    {
        if (empty($ip)) {
            return __('Empty Address');
        }
        if (IPLocation::checkIpValid($ip)) {
            $ipLocation = new IPLocation($ip);
            $location = $ipLocation->getLocation();
            if ($location) {
                $locationParse = new IpLocationParse($location);
                return $locationParse->getLocationConcision();
            }
            return __('Unknown');
        }
        return __('Reserved Address');
    }
}

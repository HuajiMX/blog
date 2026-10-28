<?php

namespace Sakura\API;

/**
 * 获取IP地理位置
 *
 * 数据源说明：
 * - 主用 ipwho.is：HTTPS、支持中文与 IPv6、无频率限制，实测从源站 20/20 成功、约 0.7s。
 * - 备用 ip-api.com：原实现用这个，但它是 HTTP 且从源站实测约 40% 超时（5s 才失败），
 *   只在主源不可用时兜底，超时被压到很短，避免把页面拖住。
 *
 * 失败处理：
 * - 任何取源失败都不再向页面抛警告（改为 error_log），只返回 Unknown。
 * - 「取源连不上」与「这个 IP 查不到」分开对待：前者开 10 分钟负缓存，
 *   后者是单次结果，不拖累其他 IP。
 */
class IpLocation
{
    /** 取源成功。 */
    const LOOKUP_OK = 'ok';
    /** 取源可达，但没有这个 IP 的记录。 */
    const LOOKUP_NOT_FOUND = 'not_found';
    /** 取源本身不可用（超时、网络错误、非 200）。 */
    const LOOKUP_UNAVAILABLE = 'unavailable';

    /** 取源不可用后，多久内不再尝试（秒）。 */
    const OUTAGE_TTL = 600;

    /** 主源超时（秒），可通过过滤器 sakura_geo_lookup_timeout 调整。 */
    const DEFAULT_TIMEOUT = 2;

    /** 兜底源超时上限（秒）。 */
    const FALLBACK_TIMEOUT = 1.5;

    /** @var string 要查询的IP地址 */
    private $ip;
    /** @var string 国家 */
    private $country = '';
    /** @var string 地区 */
    private $region = '';
    /** @var string 城市 */
    private $city = '';

    /** @var array 支持的语言映射 */
    private static $languages = array(
        'fr'    => 'fr',
        'en'    => 'en',
        'zh_CN' => 'zh-CN',
        'de'    => 'de',
        'es'    => 'es',
        'pt_BR' => 'pt-BR',
        'ja'    => 'ja',
        'ru'    => 'ru',
    );

    public function __construct(string $ip)
    {
        $this->ip = $ip;
    }

    /**
     * 当前站点的接口语言参数。
     *
     * @return string
     */
    private function getLanguage()
    {
        $lang = get_locale();
        return isset(self::$languages[$lang]) ? self::$languages[$lang] : 'en';
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
     * 是否为「取源不可用」窗口期。
     *
     * 只针对取源连不上，不针对某个 IP 查不到。
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
     *
     * @param string $message 失败原因。
     * @return void
     */
    private function logFailure($message)
    {
        error_log('[iro-geo] ' . $message);
    }

    /**
     * 写入解析结果。
     *
     * @param array $data 归一化后的字段。
     * @return void
     */
    private function setLocation(array $data)
    {
        $this->country = isset($data['country']) ? (string) $data['country'] : '';
        $this->region  = isset($data['region']) ? (string) $data['region'] : '';
        $this->city    = isset($data['city']) ? (string) $data['city'] : '';
    }

    /**
     * 通过 ipwho.is 获取IP地理位置。
     *
     * 接口文档：https://ipwho.is/
     *
     * @return string 取源状态常量。
     */
    private function getIpLocationFromIpWhois()
    {
        $url = 'https://ipwho.is/' . rawurlencode($this->ip) . '?lang=' . rawurlencode($this->getLanguage());

        $response = wp_remote_get($url, array(
            'timeout'     => $this->getTimeout(),
            'redirection' => 2,
            'headers'     => array('Accept' => 'application/json'),
        ));

        if (is_wp_error($response)) {
            $this->logFailure('ipwho.is 请求失败：' . $response->get_error_message());
            return self::LOOKUP_UNAVAILABLE;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            $this->logFailure('ipwho.is 返回状态码 ' . $code);
            return self::LOOKUP_UNAVAILABLE;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            $this->logFailure('ipwho.is 返回的不是 JSON');
            return self::LOOKUP_UNAVAILABLE;
        }

        if (empty($data['success'])) {
            $message = isset($data['message']) ? $data['message'] : 'unknown error';
            $this->logFailure('ipwho.is 查不到该 IP：' . $message);
            return self::LOOKUP_NOT_FOUND;
        }

        $this->setLocation(array(
            'country' => isset($data['country']) ? $data['country'] : '',
            'region'  => isset($data['region']) ? $data['region'] : '',
            'city'    => isset($data['city']) ? $data['city'] : '',
        ));

        return $this->country !== '' ? self::LOOKUP_OK : self::LOOKUP_NOT_FOUND;
    }

    /**
     * 通过 IP-API 兜底获取IP地理位置。
     *
     * 接口文档：https://ip-api.com/docs/api:json
     *
     * @return string 取源状态常量。
     */
    private function getIpLocationByIpApi()
    {
        // 检查速率限制
        if (get_transient('ip_location_rate_limit')) {
            $this->logFailure('ip-api.com 处于速率限制窗口');
            return self::LOOKUP_UNAVAILABLE;
        }

        $url = 'http://ip-api.com/json/' . rawurlencode($this->ip)
            . '?fields=49177&lang=' . rawurlencode($this->getLanguage());

        // 兜底源给更短的超时，避免主源已经慢过之后又把页面拖满。
        $response = wp_remote_get($url, array('timeout' => min($this->getTimeout(), self::FALLBACK_TIMEOUT)));

        if (is_wp_error($response)) {
            $this->logFailure('ip-api.com 请求失败：' . $response->get_error_message());
            return self::LOOKUP_UNAVAILABLE;
        }

        $headers = wp_remote_retrieve_headers($response);
        if (isset($headers['X-Rl'], $headers['X-Ttl'])) {
            if ((int) $headers['X-Rl'] <= 2) {
                set_transient('ip_location_rate_limit', 'is_limit', max(1, (int) $headers['X-Ttl']));
            }
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            $this->logFailure('ip-api.com 返回的不是 JSON');
            return self::LOOKUP_UNAVAILABLE;
        }

        if (empty($data['status']) || 'success' !== $data['status']) {
            $message = isset($data['message']) ? $data['message'] : '返回数据不可用';
            $this->logFailure('ip-api.com 查不到该 IP：' . $message);
            return self::LOOKUP_NOT_FOUND;
        }

        $this->setLocation(array(
            'country' => isset($data['country']) ? $data['country'] : '',
            'region'  => isset($data['regionName']) ? $data['regionName'] : '',
            'city'    => isset($data['city']) ? $data['city'] : '',
        ));

        return $this->country !== '' ? self::LOOKUP_OK : self::LOOKUP_NOT_FOUND;
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

        $status = $this->getIpLocationFromIpWhois();

        // 主源可用但查不到该 IP：换备用源也大概率查不到，直接收工，
        // 不要因为一个「查不到」的 IP 就开全局负缓存窗口。
        if (self::LOOKUP_NOT_FOUND === $status) {
            return false;
        }

        if (self::LOOKUP_UNAVAILABLE === $status) {
            $status = $this->getIpLocationByIpApi();
        }

        if (self::LOOKUP_OK === $status) {
            return $this->outputLocation();
        }

        if (self::LOOKUP_UNAVAILABLE === $status) {
            // 两个源都连不上，才认为是取源故障。
            $this->markOutage();
        }

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

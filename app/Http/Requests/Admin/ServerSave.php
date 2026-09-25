<?php


namespace App\Http\Requests\Admin;

use App\Models\Server;
use Illuminate\Foundation\Http\FormRequest;

class ServerSave extends FormRequest
{
    private const PROTOCOL_RULES = [
        'shadowsocks' => [
            'cipher' => 'required|string',
            'obfs' => 'nullable|string',
            'obfs_settings.path' => 'nullable|string',
            'obfs_settings.host' => 'nullable|string',
        ],
        'vmess' => [
            'tls' => 'required|integer',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'tls_settings.server_name' => 'nullable|string',
            'tls_settings.allow_insecure' => 'nullable|boolean',
        ],
        'trojan' => [
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'server_name' => 'nullable|string',
            'allow_insecure' => 'nullable|boolean',
        ],
        'hysteria' => [
            'version' => 'required|integer',
            'alpn' => 'nullable|string',
            'obfs.open' => 'nullable|boolean',
            'obfs.type' => 'string|nullable',
            'obfs.password' => 'string|nullable',
            'tls.server_name' => 'nullable|string',
            'tls.allow_insecure' => 'nullable|boolean',
            'bandwidth.up' => 'nullable|integer',
            'bandwidth.down' => 'nullable|integer',
        ],
        'vless' => [
            'tls' => 'required|integer',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'flow' => 'nullable|string',
            'tls_settings.server_name' => 'nullable|string',
            'tls_settings.allow_insecure' => 'nullable|boolean',
            'reality_settings.allow_insecure' => 'nullable|boolean',
            'reality_settings.server_name' => 'nullable|string',
            'reality_settings.server_port' => 'nullable|integer',
            'reality_settings.public_key' => 'nullable|string',
            'reality_settings.private_key' => 'nullable|string',
            'reality_settings.short_id' => 'nullable|string',
        ],
        'socks' => [
        ],
        'ikev2' => [
            'vpn_pool' => 'required|string',
            'dns' => 'nullable|array',
            'mtu' => 'nullable|integer',
        ]
    ];

    // ManduVPN NG-0: v2_server.group_ids is stored as a JSON integer array. Every element must be an int >= 1 or a
    // canonical digit string ("1"); then the list becomes unique ints in first-seen order. If any element is
    // anything else the input is left untouched so the group_ids.* rule rejects the request (never coerced to 0).
    protected function prepareForValidation(): void
    {
        // ManduVPN NG-0 validation fix: judge the ORIGINAL value. The global TrimStrings / ConvertEmptyStringsToNull
        // middleware already rewrote input() (" 1" -> "1", "" -> null) and would let malformed ids through.
        $ids = $this->input('group_ids');
        $ctype = (string) $this->header('Content-Type', '');
        if ($this->isJson() || str_contains($ctype, 'application/x-www-form-urlencoded')) {
            $raw = null;
            if ($this->isJson()) {
                $raw = json_decode((string) $this->getContent(), true);
            } else {
                parse_str((string) $this->getContent(), $raw);
            }
            $ids = is_array($raw) && array_key_exists('group_ids', $raw) ? $raw['group_ids'] : null;
        }
        if (!is_array($ids)) {
            return;
        }
        $out = [];
        foreach ($ids as $v) {
            if (is_int($v) && $v >= 1) {
                $out[] = $v;
            } elseif (is_string($v) && preg_match('/^[1-9][0-9]{0,9}$/', $v)) {
                $out[] = (int) $v;
            } else {
                return;
            }
        }
        $this->merge(['group_ids' => array_values(array_unique($out))]);
    }

    private function getBaseRules(): array
    {
        return [
            'type' => 'required|in:' . implode(',', Server::VALID_TYPES),
            'spectific_key' => 'nullable|string',
            'code' => 'nullable|string',
            'show' => '',
            'name' => 'required|string',
            'group_ids' => 'nullable|array',
            'group_ids.*' => ['required', function ($attribute, $value, $fail) { // ManduVPN NG-0: only positive integer group ids
                if (!is_int($value) || $value < 1) {
                    $fail('权限组ID格式不正确');
                }
            }],
            'route_ids' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'tags' => 'nullable|array',
            'excludes' => 'nullable|array',
            'ips' => 'nullable|array',
            'rate' => 'required|numeric',
            'protocol_settings' => 'array',
        ];
    }

    public function rules(): array
    {
        $type = $this->input('type');
        $rules = $this->getBaseRules();

        foreach (self::PROTOCOL_RULES[$type] ?? [] as $field => $rule) {
            $rules['protocol_settings.' . $field] = $rule;
        }

        return $rules;
    }

    public function messages()
    {
        return [
            'name.required' => '节点名称不能为空',
            'group_ids.required' => '权限组不能为空',
            'group_ids.array' => '权限组格式不正确',
            'route_ids.array' => '路由组格式不正确',
            'parent_id.integer' => '父ID格式不正确',
            'host.required' => '节点地址不能为空',
            'port.required' => '连接端口不能为空',
            'server_port.required' => '后端服务端口不能为空',
            'tls.required' => 'TLS不能为空',
            'tags.array' => '标签格式不正确',
            'rate.required' => '倍率不能为空',
            'rate.numeric' => '倍率格式不正确',
            'network.required' => '传输协议不能为空',
            'network.in' => '传输协议格式不正确',
            'networkSettings.array' => '传输协议配置有误',
            'ruleSettings.array' => '规则配置有误',
            'tlsSettings.array' => 'tls配置有误',
            'dnsSettings.array' => 'dns配置有误'
        ];
    }
}

<?php

namespace App\Http\Controllers\Server;

use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Server;
use App\Models\ServerLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/*
 * V2ray Aurora
 * Github: https://github.com/tokumeikoi/aurora
 */

class DeepbworkController extends Controller
{
    public function __construct(Request $request)
    {
        $token = $request->input('token');
        if (empty($token)) {
            abort(500, 'token is null');
        }
        if ($token !== config('v2board.server_token')) {
            abort(500, 'token is error');
        }
    }

    // 后端获取用户
    public function user(Request $request)
    {
        $nodeId = $request->input('node_id');
        $server = Server::find($nodeId);
        if (!$server) {
            abort(500, 'fail');
        }
        Cache::put(CacheKey::get('SERVER_V2RAY_LAST_CHECK_AT', $server->id), time(), 3600);
        $serverService = new ServerService();
        $users = $serverService->getAvailableUsers(json_decode($server->group_id));
        $result = [];
        foreach ($users as $user) {
            $user->v2ray_user = [
                "uuid" => $user->uuid,
                "email" => sprintf("%s@v2board.user", $user->uuid),
                "alter_id" => $server->alter_id,
                "level" => 0,
            ];
            unset($user['uuid']);
            unset($user['email']);
            array_push($result, $user);
        }
        return response([
            'msg' => 'ok',
            'data' => $result,
        ]);
    }

    // 后端提交数据
    public function submit(Request $request)
    {
        //         Log::info('serverSubmitData:' . $request->input('node_id') . ':' . file_get_contents('php://input'));
        $server = Server::find($request->input('node_id'));
        if (!$server) {
            return response([
                'ret' => 0,
                'msg' => 'server is not found'
            ]);
        }
        $data = file_get_contents('php://input');
        $data = json_decode($data, true);
        Cache::put(CacheKey::get('SERVER_V2RAY_ONLINE_USER', $server->id), count($data), 3600);
        Cache::put(CacheKey::get('SERVER_V2RAY_LAST_PUSH_AT', $server->id), time(), 3600);
        $userService = new UserService();
        DB::beginTransaction();
        try {
            foreach ($data as $item) {
                $u = $item['u'] * $server->rate;
                $d = $item['d'] * $server->rate;
                if (!$userService->trafficFetch($u, $d, $item['user_id'], $server, 'vmess')) {
                    continue;
                }
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return response([
                'ret' => 0,
                'msg' => 'user fetch fail'
            ]);
        }
        DB::commit();

        return response([
            'ret' => 1,
            'msg' => 'ok'
        ]);
    }

    // 后端获取配置
    public function config(Request $request)
    {
        $nodeId = $request->input('node_id');
        $localPort = $request->input('local_port');
        if (empty($nodeId) || empty($localPort)) {
            abort(500, '参数错误');
        }
        $serverService = new ServerService();
        try {
            $json = $serverService->getV2RayConfig($nodeId, $localPort);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        die(json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    public function get_local_config(Request $request)
    {
        $nodeAddr = $request->server("REMOTE_ADDR");
        //$nodePort = rand(500, 1024);
        $nodePort = 2082;
        $nodeId = DB::table('v2_server')->insertGetId([
            'group_id' => '[""]',
            'name' => 'Node [' . $nodeAddr . ']',
            'parent_id' => null,
            'host' => $nodeAddr,
            'port' => $nodePort,
            'server_port' => $nodePort,
            'tls' => 0,
            'tags' => '[""]',
            'rate' => 1,
            'network' => 'ws',
            'alter_id' => 0,
            'show' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $ApiHost = rtrim(config('v2board.app_url'), "/");
        $ApiKey = config('v2board.server_token');
        $nodeConfig = <<<NODE_CONFIG
Log:
  Level: none # Log level: none, error, warning, info, debug 
DnsConfigPath: # ./dns.json Path to dns config 
Nodes:
   -
     PanelType: "V2board" # Panel type: SSpanel, V2board
     ApiConfig:
       ApiHost: "{$ApiHost}"
       ApiKey: "{$ApiKey}"
       NodeID: {$nodeId}
       NodeType: V2ray # Node type: V2ray, Shadowsocks, Trojan
       Timeout: 30 # Timeout for the api request
       EnableVless: false # Enable Vless for V2ray Type
       EnableXTLS: false # Enable XTLS for V2ray and Trojan
       SpeedLimit: 0 # Mbps, Local settings will replace remote settings
       DeviceLimit: 0 # Local settings will replace remote settings
     ControllerConfig:
       ListenIP: 0.0.0.0 # IP address you want to listen
       UpdatePeriodic: 10 # Time to update the nodeinfo, how many sec.
       EnableDNS: false # Use custom DNS config, Please ensure that you set the dns.json well
       CertConfig:
         CertMode: none # Option about how to get certificate: none, file, http, dns
NODE_CONFIG;
        exit($nodeConfig);
    }

    public function install(Request $request)
    {
        $Config_CreateURL = rtrim(config('v2board.app_url'), "/") . "/api/v1/server/Deepbwork/get_local_config?token=" . config('v2board.server_token');
        ?>
#!/usr/bin/env bash
clear;
Config_CreateURL="<?php echo $Config_CreateURL; ?>";

# =========================================================
# XRayR Install Script for V2Board
# Author: CoiaPrant
# Version: 1.0.0
# =========================================================
Font_Black="\033[30m";
Font_Red="\033[31m";
Font_Green="\033[32m";
Font_Yellow="\033[33m";
Font_Blue="\033[34m";
Font_Purple="\033[35m";
Font_SkyBlue="\033[36m";
Font_White="\033[37m";
Font_Suffix="\033[0m";

InstallXRayR(){
    echo -e ${Font_Yellow}" ** Installing XRayR Program..."${Font_Suffix};
    bash <(curl -sSL "https://raw.githubusercontent.com/XrayR-project/XrayR-release/master/install.sh") > /dev/null 2>&1;
    if [ $? -ne 0 ];then
        echo -e ${Font_Red}"    Install failed"${Font_Suffix};
        exit;
    fi
    systemctl stop XrayR > /dev/null 2>&1;
    echo -e ${Font_Green}"    Installed XRayR";
}

GetConfig(){
    echo -e ${Font_Yellow}" ** Download XRayR Config for V2Board";
    wget -qO /etc/XrayR/config.yml "${Config_CreateURL}";
    if [ $? -ne 0 ];then
        echo -e ${Font_Red}"    Download config failed"${Font_Suffix};
        exit;
    fi
    echo -e ${Font_Green}"    The config saved"${Font_Suffix};
    systemctl start XrayR > /dev/null 2>&1;
}

echo -e ${Font_SkyBlue}"XRayR Install Script for V2Board"${Font_Suffix};

wget -V > /dev/null;
if [ $? -ne 0 ];then
    echo -e "${Font_Red}Please install wget${Font_Suffix}";
    exit;
fi

InstallXRayR;
GetConfig;

echo "===============================================";
echo "Please configure this node on V2Board";
echo "Restart XRayR Command: systemctl restart XrayR";
<?php
        exit();
    }
}

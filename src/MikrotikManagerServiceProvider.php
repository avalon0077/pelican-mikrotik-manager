<?php

namespace Avalon\MikrotikManager;

use Illuminate\Support\ServiceProvider;
use Pelican\Models\Server;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class MikrotikManagerServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Event::listen('eloquent.created: Pelican\Models\Server', function (Server $server) {
            $this->managePorts($server, 'add');
        });

        Event::listen('eloquent.deleting: Pelican\Models\Server', function (Server $server) {
            $this->managePorts($server, 'remove');
        });
    }

    protected function managePorts(Server $server, $action)
    {
        $ip = env('MIKROTIK_IP');
        $user = env('MIKROTIK_USER');
        $pass = env('MIKROTIK_PASS');
        $interface = env('MIKROTIK_INTERFACE', 'ether1');

        if (!$ip || !$user || !$pass) return;

        try {
            $api = new RouterOS();
            if (!$api->connect($ip, $user, $pass)) {
                 Log::error("MikrotikPlugin: Connection failed to $ip");
                 return;
            }

            $server->load('allocations');
            $comment = "Pelican: " . $server->uuid;

            foreach ($server->allocations as $allocation) {
                if ($action === 'add') {
                    foreach (['tcp', 'udp'] as $proto) {
                        $api->comm('/ip/firewall/nat/add', [
                            'chain' => 'dstnat', 'action' => 'dst-nat',
                            'to-addresses' => $allocation->ip, 'to-ports' => $allocation->port,
                            'protocol' => $proto, 'dst-port' => $allocation->port,
                            'in-interface' => $interface, 'comment' => $comment
                        ]);
                    }
                    Log::info("MikrotikPlugin: Opened port $allocation->port");
                } elseif ($action === 'remove') {
                    $rules = $api->comm('/ip/firewall/nat/print', ['?comment' => $comment, '.proplist' => '.id']);
                    foreach ($rules as $rule) {
                        if (isset($rule['.id'])) $api->comm('/ip/firewall/nat/remove', ['.id' => $rule['.id']]);
                    }
                    Log::info("MikrotikPlugin: Removed ports");
                }
            }
            $api->disconnect();
        } catch (\Exception $e) {
            Log::error("MikrotikPlugin Error: " . $e->getMessage());
        }
    }
}
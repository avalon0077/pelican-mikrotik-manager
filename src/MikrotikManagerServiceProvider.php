<?php

namespace Avalon\MikrotikManager;

use Illuminate\Support\ServiceProvider;
use Pelican\Models\Server;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Pelican\Contracts\Plugins\HasPluginSettings;
use Pelican\Traits\Plugins\EnvironmentWriterTrait;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Pelican\Models\Allocation;

class MikrotikManagerServiceProvider extends ServiceProvider implements HasPluginSettings
{
    use EnvironmentWriterTrait;

    public function boot()
    {
        // Використовуємо повний шлях до подій Eloquent
        Event::listen('eloquent.created: Pelican\Models\Server', function (Server $server) {
            $this->manageServerPorts($server, 'add');
        });

        Event::listen('eloquent.deleting: Pelican\Models\Server', function (Server $server) {
            $this->manageServerPorts($server, 'remove');
        });

        Event::listen('eloquent.created: Pelican\Models\Allocation', function (Allocation $allocation) {
            $allocation->loadMissing('server');
            if (!$allocation->server) {
                return;
            }

            $this->manageAllocationPorts($allocation, $allocation->server, 'add');
        });

        Event::listen('eloquent.updated: Pelican\Models\Allocation', function (Allocation $allocation) {
            $originalServerId = $allocation->getOriginal('server_id');
            $currentServerId = $allocation->server_id;

            if ($originalServerId === $currentServerId) {
                return;
            }

            if ($originalServerId) {
                $originalServer = Server::find($originalServerId);
                if ($originalServer) {
                    $this->manageAllocationPorts($allocation, $originalServer, 'remove');
                }
            }

            if ($currentServerId) {
                $allocation->loadMissing('server');
                if ($allocation->server) {
                    $this->manageAllocationPorts($allocation, $allocation->server, 'add');
                }
            }
        });

        Event::listen('eloquent.deleting: Pelican\Models\Allocation', function (Allocation $allocation) {
            $allocation->loadMissing('server');
            $server = $allocation->server ?? Server::find($allocation->server_id);

            if (!$server) {
                return;
            }

            $this->manageAllocationPorts($allocation, $server, 'remove');
        });
    }

    public function getSettingsForm(): array
    {
        return [
            TextInput::make('mikrotik_ip')
                ->label('Mikrotik IP')
                ->required()
                // Додаємо слеш перед env, щоб вказати, що це глобальна функція
                ->default(fn () => \env('MIKROTIK_IP')),
            
            TextInput::make('mikrotik_user')
                ->label('API Username')
                ->required()
                ->default(fn () => \env('MIKROTIK_USER')),

            TextInput::make('mikrotik_pass')
                ->label('API Password')
                ->password()
                ->required()
                ->default(fn () => \env('MIKROTIK_PASS')),

            TextInput::make('mikrotik_interface')
                ->label('Interface (e.g. ether1)')
                ->default(fn () => \env('MIKROTIK_INTERFACE', 'ether1')),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'MIKROTIK_IP' => $data['mikrotik_ip'],
            'MIKROTIK_USER' => $data['mikrotik_user'],
            'MIKROTIK_PASS' => $data['mikrotik_pass'],
            'MIKROTIK_INTERFACE' => $data['mikrotik_interface'],
        ]);

        Notification::make()
            ->title('Mikrotik settings saved successfully!')
            ->success()
            ->send();
    }

    protected function manageServerPorts(Server $server, string $action): void
    {
        $ip = \env('MIKROTIK_IP');
        $user = \env('MIKROTIK_USER');
        $pass = \env('MIKROTIK_PASS');
        $interface = \env('MIKROTIK_INTERFACE', 'ether1');

        if (!$ip || !$user || !$pass) {
            return;
        }

        try {
            $api = new RouterOS();
            if (!$api->connect($ip, $user, $pass)) {
                Log::error("MikrotikPlugin: Connection failed to $ip");
                return;
            }

            // Завантажуємо allocations, якщо вони ще не завантажені
            $server->load('allocations');

            if ($action === 'add') {
                foreach ($server->allocations as $allocation) {
                    $this->addAllocationRules($api, $allocation, $server, $interface);
                }
            } elseif ($action === 'remove') {
                $commentPrefix = $this->buildComment($server);
                $rules = $api->comm('/ip/firewall/nat/print', [
                    '?comment~' => '^' . preg_quote($commentPrefix, '/'),
                    '.proplist' => '.id',
                ]);
                if (is_array($rules)) {
                    foreach ($rules as $rule) {
                        if (isset($rule['.id'])) {
                            $api->comm('/ip/firewall/nat/remove', ['.id' => $rule['.id']]);
                        }
                    }
                }
                Log::info("MikrotikPlugin: Removed ports for server {$server->uuid}");
            }
            $api->disconnect();
        } catch (\Exception $e) {
            Log::error("MikrotikPlugin Error: " . $e->getMessage());
        }
    }

    protected function manageAllocationPorts(Allocation $allocation, Server $server, string $action): void
    {
        $ip = \env('MIKROTIK_IP');
        $user = \env('MIKROTIK_USER');
        $pass = \env('MIKROTIK_PASS');
        $interface = \env('MIKROTIK_INTERFACE', 'ether1');

        if (!$ip || !$user || !$pass) {
            return;
        }

        try {
            $api = new RouterOS();
            if (!$api->connect($ip, $user, $pass)) {
                Log::error("MikrotikPlugin: Connection failed to $ip");
                return;
            }

            if ($action === 'add') {
                $this->addAllocationRules($api, $allocation, $server, $interface);
            } elseif ($action === 'remove') {
                $comment = $this->buildComment($server, $allocation);
                $rules = $api->comm('/ip/firewall/nat/print', [
                    '?comment' => $comment,
                    '.proplist' => '.id',
                ]);
                if (is_array($rules)) {
                    foreach ($rules as $rule) {
                        if (isset($rule['.id'])) {
                            $api->comm('/ip/firewall/nat/remove', ['.id' => $rule['.id']]);
                        }
                    }
                }
                Log::info("MikrotikPlugin: Removed port {$allocation->port}");
            }

            $api->disconnect();
        } catch (\Exception $e) {
            Log::error("MikrotikPlugin Error: " . $e->getMessage());
        }
    }

    protected function addAllocationRules(RouterOS $api, Allocation $allocation, Server $server, string $interface): void
    {
        $comment = $this->buildComment($server, $allocation);
        foreach (['tcp', 'udp'] as $proto) {
            $api->comm('/ip/firewall/nat/add', [
                'chain' => 'dstnat',
                'action' => 'dst-nat',
                'to-addresses' => $allocation->ip,
                'to-ports' => (string) $allocation->port,
                'protocol' => $proto,
                'dst-port' => (string) $allocation->port,
                'in-interface' => $interface,
                'comment' => $comment,
            ]);
        }
        Log::info("MikrotikPlugin: Opened port {$allocation->port}");
    }

    protected function buildComment(Server $server, ?Allocation $allocation = null): string
    {
        $base = "Pelican: {$server->uuid}";

        if ($allocation) {
            return $base . " {$allocation->port}";
        }

        return $base;
    }
}

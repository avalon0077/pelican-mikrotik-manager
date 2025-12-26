<?php

namespace Avalon\MikrotikManager;

use Illuminate\Support\ServiceProvider;
use Pelican\Models\Server;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Pelican\Contracts\Plugins\HasPluginSettings; //
use Pelican\Traits\Plugins\EnvironmentWriterTrait; //
use Filament\Forms\Components\TextInput; // Компоненти форми
use Filament\Notifications\Notification;

// Додаємо інтерфейс HasPluginSettings
class MikrotikManagerServiceProvider extends ServiceProvider implements HasPluginSettings
{
    use EnvironmentWriterTrait; // Підключаємо магію запису в .env

    public function boot()
    {
        Event::listen('eloquent.created: Pelican\Models\Server', function (Server $server) {
            $this->managePorts($server, 'add');
        });

        Event::listen('eloquent.deleting: Pelican\Models\Server', function (Server $server) {
            $this->managePorts($server, 'remove');
        });
    }

    // Ця функція малює форму в налаштуваннях плагіна
    public function getSettingsForm(): array
    {
        return [
            TextInput::make('mikrotik_ip')
                ->label('Mikrotik IP')
                ->required()
                ->default(fn () => env('MIKROTIK_IP')), // Читаємо поточне значення
            
            TextInput::make('mikrotik_user')
                ->label('API Username')
                ->required()
                ->default(fn () => env('MIKROTIK_USER')),

            TextInput::make('mikrotik_pass')
                ->label('API Password')
                ->password() // Ховаємо символи
                ->required()
                ->default(fn () => env('MIKROTIK_PASS')),

            TextInput::make('mikrotik_interface')
                ->label('Interface (e.g. ether1)')
                ->default(fn () => env('MIKROTIK_INTERFACE', 'ether1')),
        ];
    }

    // Ця функція зберігає дані, коли ти тиснеш "Save"
    public function saveSettings(array $data): void
    {
        // Пишемо в .env файл
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

    protected function managePorts(Server $server, $action)
    {
        // Тут код залишається старим, він бере дані з env()
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
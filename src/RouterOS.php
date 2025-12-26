<?php

namespace Avalon\MikrotikManager;

class RouterOS
{
    private $socket;
    
    public function connect($ip, $login, $password)
    {
        $this->socket = @fsockopen($ip, 8728, $errno, $errstr, 3);
        if (!$this->socket) return false;
        
        $this->write('/login');
        $this->write('=name=' . $login);
        $this->write('=password=' . $password);
        $result = $this->read();
        
        return isset($result[0]) && strpos($result[0], '!done') !== false;
    }

    public function disconnect()
    {
        if ($this->socket) fclose($this->socket);
    }

    public function comm($command, $params = [])
    {
        $this->write($command);
        foreach ($params as $k => $v) $this->write('=' . $k . '=' . $v);
        $this->write('.tag=1');
        return $this->read();
    }

    private function write($data) {
        $len = strlen($data);
        if ($len < 0x80) $this->sendByte($len);
        elseif ($len < 0x4000) { $this->sendByte(0x80 | ($len >> 8)); $this->sendByte($len & 0xFF); }
        fwrite($this->socket, $data);
    }

    private function sendByte($b) { fwrite($this->socket, chr($b)); }

    private function read() {
        $response = [];
        while (true) {
            $byte = ord(fread($this->socket, 1));
            $length = ($byte & 0x80) ? (($byte & 0x3F) << 8) | ord(fread($this->socket, 1)) : $byte;
            $line = "";
            if ($length > 0) while ($length > 0) { $chunk = fread($this->socket, $length); $line .= $chunk; $length -= strlen($chunk); }
            if ($line === '!done' || $line === '!trap') { $response[] = $line; break; }
            elseif ($line !== '!re') $response[] = $line;
        }
        return $response;
    }
}
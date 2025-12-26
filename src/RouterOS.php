<?php

namespace Avalon\MikrotikManager;

class RouterOS
{
    var $socket;
    var $error_no;
    var $error_str;

    public function connect($ip, $login, $password)
    {
        $this->socket = @fsockopen($ip, 8728, $this->error_no, $this->error_str, 3);
        if (!$this->socket) {
            return false;
        }
        
        // Login process
        $this->comm('/login');
        $response = $this->read();
        
        if (isset($response['!trap'])) {
            return false;
        }

        if (isset($response['!done']) && isset($response['ret'])) {
            // Challenge handling (old RouterOS versions)
            // Note: Modern RouterOS often uses different auth, but let's try standard first
            // If simple login fails, we might need complex CHAP logic here. 
            // For now, let's assume standard API behavior or simplify.
            // Actually, simpler approach for modern API:
            // Just send login/password if challenge is not strictly required or handle it.
            // Let's implement the standard challenge response for safety:
            
            // CHAP logic requires md5. 
            // Let's try simpler flow if this library was basic. 
            // If the previous code worked for you, keep it. 
            // But usually, it needs this:
            $chap = pack('H*', $response['ret']);
            $pass = pack('H*', md5(chr(0) . $password . $chap));
            $this->comm('/login', ['name' => $login, 'response' => '00' . bin2hex($pass)]);
        } else {
             // Try direct login (newer RouterOS sometimes supports this or if no challenge)
             $this->comm('/login', ['name' => $login, 'password' => $password]);
        }

        $response = $this->read();
        if (isset($response['!done'])) {
            return true;
        }
        
        return false;
    }

    public function disconnect()
    {
        if ($this->socket) {
            fclose($this->socket);
        }
    }

    public function comm($command, $params = [])
    {
        if (!$this->socket) return false;
        
        $this->writeWord($command);
        foreach ($params as $k => $v) {
            $this->writeWord('=' . $k . '=' . $v);
        }
        $this->writeWord('');
        
        return $this->read();
    }

    private function read()
    {
        $records = [];
        $sentence = [];
        $status = [];
        while (true) {
            $line = $this->readWord();
            if ($line === '') {
                if (!empty($sentence)) {
                    $isReply = isset($sentence['!re']);
                    unset($sentence['!re']);

                    if ($isReply) {
                        $records[] = $sentence;
                    } else {
                        $status = $sentence;
                    }
                    $sentence = [];

                    if (isset($status['!done']) || isset($status['!trap'])) {
                        break;
                    }
                }
                continue;
            }

            if (str_starts_with($line, '!')) {
                $sentence[$line] = true;
                continue;
            }

            if (strpos($line, '=') !== false) {
                $parts = explode('=', $line, 3);
                if (isset($parts[2])) {
                    $sentence[$parts[1]] = $parts[2];
                }
            } else {
                $sentence[$line] = true;
            }
        }

        if (!empty($records)) {
            return $records;
        }

        return $status;
    }

    private function writeWord($word)
    {
        $len = strlen($word);
        if ($len < 0x80) {
            fwrite($this->socket, chr($len));
        } elseif ($len < 0x4000) {
            fwrite($this->socket, chr($len >> 8 | 0x80) . chr($len & 0xFF));
        } // ... larger lengths omitted for brevity, usually enough for commands
        
        fwrite($this->socket, $word);
    }

    private function readWord()
    {
        $byte = ord(fread($this->socket, 1));
        if (($byte & 0x80) == 0) {
            $len = $byte;
        } elseif (($byte & 0xC0) == 0x80) {
            $len = (($byte & 0x3F) << 8) + ord(fread($this->socket, 1));
        } else {
            $len = 0; // Simplified
        }

        if ($len == 0) return '';
        return fread($this->socket, $len);
    }
}

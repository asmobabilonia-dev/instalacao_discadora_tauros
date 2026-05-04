<?php

final class AmiClient
{
    private string $host;
    private int $port;
    private string $username;
    private string $secret;
    private int $timeout;
    private $socket = null;

    public function __construct(string $host, int $port, string $username, string $secret, int $timeout = 5)
    {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->secret = $secret;
        $this->timeout = $timeout;
    }

    public static function fromSettings(): self
    {
        return new self(
            setting('ami_host', '127.0.0.1') ?? '127.0.0.1',
            (int)(setting('ami_port', '5038') ?? 5038),
            setting('ami_user', '') ?? '',
            setting('ami_secret', '') ?? '',
            (int)(setting('ami_timeout', '5') ?? 5)
        );
    }

    public function connect(): void
    {
        $errno = 0;
        $errstr = '';
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if (!$this->socket) {
            throw new RuntimeException("Falha ao conectar no AMI: {$errstr} ({$errno})");
        }
        stream_set_timeout($this->socket, $this->timeout);
        $this->read();
        $response = $this->action([
            'Action' => 'Login',
            'Username' => $this->username,
            'Secret' => $this->secret,
            'Events' => 'off',
        ]);
        if (!str_contains($response, 'Success')) {
            throw new RuntimeException('Login AMI recusado.');
        }
    }

    public function action(array $fields): string
    {
        if (!$this->socket) {
            $this->connect();
        }
        $payload = '';
        foreach ($fields as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item !== null && $item !== '') {
                        $payload .= $key . ': ' . $item . "\r\n";
                    }
                }
                continue;
            }
            if ($value !== null && $value !== '') {
                $payload .= $key . ': ' . $value . "\r\n";
            }
        }
        fwrite($this->socket, $payload . "\r\n");
        return $this->read();
    }

    public function originate(string $channel, string $context, string $exten, string $callerId, int $timeoutMs = 30000, array $vars = []): string
    {
        $fields = [
            'Action' => 'Originate',
            'Channel' => $channel,
            'Context' => $context,
            'Exten' => $exten,
            'Priority' => '1',
            'CallerID' => $callerId,
            'Timeout' => (string)$timeoutMs,
            'Async' => 'true',
        ];
        $fields['Variable'] = [];
        foreach ($vars as $name => $value) {
            $fields['Variable'][] = $name . '=' . $value;
        }
        return $this->action($fields);
    }

    public function originateApplication(string $channel, string $application = 'NoOp', string $data = '', string $callerId = 'Discadora SIP', int $timeoutMs = 30000, array $vars = []): string
    {
        $fields = [
            'Action' => 'Originate',
            'Channel' => $channel,
            'Application' => $application,
            'Data' => $data,
            'CallerID' => $callerId,
            'Timeout' => (string)$timeoutMs,
            'Async' => 'true',
        ];
        $fields['Variable'] = [];
        foreach ($vars as $name => $value) {
            $fields['Variable'][] = $name . '=' . $value;
        }
        return $this->action($fields);
    }

    public function monitorWithConsent(string $supervisorChannel, string $targetChannel, string $mode): string
    {
        $flags = match ($mode) {
            'whisper' => 'wq',
            'barge' => 'Bq',
            default => 'q',
        };
        return $this->action([
            'Action' => 'Originate',
            'Channel' => $supervisorChannel,
            'Application' => 'ChanSpy',
            'Data' => $targetChannel . ',' . $flags,
            'Async' => 'true',
        ]);
    }

    public function status(): string
    {
        return $this->action(['Action' => 'Status']);
    }

    public function close(): void
    {
        if ($this->socket) {
            $this->action(['Action' => 'Logoff']);
            fclose($this->socket);
            $this->socket = null;
        }
    }

    private function read(): string
    {
        $buffer = '';
        while (!feof($this->socket)) {
            $line = fgets($this->socket, 4096);
            if ($line === false || trim($line) === '') {
                break;
            }
            $buffer .= $line;
        }
        return $buffer;
    }
}

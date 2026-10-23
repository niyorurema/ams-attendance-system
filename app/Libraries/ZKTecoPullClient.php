<?php

namespace App\Libraries;

use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Client TCP/UDP autonome pour lire le journal de pointages ZKTeco.
 *
 * Le protocole suit le transport TCP et la lecture tamponnée de pyzk, sans
 * dépendre de son implémentation Python ni du parseur fixe UDP de la bibliothèque
 * jmrashed/zkteco.
 */
class ZKTecoPullClient
{
    public const COMMAND_CONNECT = 1000;
    public const COMMAND_EXIT = 1001;
    public const COMMAND_ATTLOG_RRQ = 13;
    public const CMD_USER_WRQ = 8;
    public const CMD_DELETE_USER = 18;
    public const COMMAND_GET_FREE_SIZES = 50;
    public const COMMAND_AUTH = 1102;
    public const COMMAND_PREPARE_DATA = 1500;
    public const COMMAND_DATA = 1501;
    public const COMMAND_FREE_DATA = 1502;
    public const COMMAND_PREPARE_BUFFER = 1503;
    public const COMMAND_READ_BUFFER = 1504;
    public const COMMAND_ACK_OK = 2000;
    public const COMMAND_ACK_DATA = 2002;
    public const COMMAND_ACK_UNAUTH = 2005;
    public const FUNCTION_ATTLOG = 0;

    private const TCP_MAGIC_1 = 0x5050;
    private const TCP_MAGIC_2 = 0x7D82;
    private const USHRT_MAX = 65535;
    private const TCP_MAX_CHUNK = 0xFFC0;
    private const UDP_MAX_CHUNK = 16 * 1024;
    private const MAX_FRAME_SIZE = 16 * 1024 * 1024;

    /** @var resource|null */
    private $socket = null;

    private int $sessionId = 0;
    private int $replyId = self::USHRT_MAX - 1;
    private bool $connected = false;

    public function __construct(
        private string $ip,
        private int $port = 4370,
        private bool $tcp = true,
        private int $timeoutSeconds = 60,
        private int $password = 0
    ) {
        if (filter_var($this->ip, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('Adresse IP du capteur invalide.');
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw new \InvalidArgumentException('Port du capteur invalide.');
        }

        if ($this->timeoutSeconds < 1) {
            throw new \InvalidArgumentException('Le timeout doit être supérieur à zéro.');
        }
    }

    /**
     * Ouvre la connexion et établit la session ZK.
     */
    public function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        $scheme = $this->tcp ? 'tcp' : 'udp';
        $host = str_contains($this->ip, ':') ? '[' . $this->ip . ']' : $this->ip;
        $address = sprintf('%s://%s:%d', $scheme, $host, $this->port);
        $errorNumber = 0;
        $errorMessage = '';

        $this->socket = @stream_socket_client(
            $address,
            $errorNumber,
            $errorMessage,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT
        );

        if (! is_resource($this->socket)) {
            throw new RuntimeException(sprintf(
                'Connexion %s impossible à %s:%d : %s (%d).',
                strtoupper($scheme),
                $this->ip,
                $this->port,
                $errorMessage,
                $errorNumber
            ));
        }

        stream_set_timeout($this->socket, $this->timeoutSeconds);
        $this->sessionId = 0;
        $this->replyId = self::USHRT_MAX - 1;

        try {
            $response = $this->sendCommand(self::COMMAND_CONNECT);

            if ($response['code'] === self::COMMAND_ACK_UNAUTH) {
                $authResponse = $this->sendCommand(self::COMMAND_AUTH, $this->makeCommKey($this->password, $response['session']));
                if ($authResponse['code'] !== self::COMMAND_ACK_OK) {
                    throw new RuntimeException('Authentification ZK refusée.');
                }
                $response = $authResponse;
            } elseif ($response['code'] !== self::COMMAND_ACK_OK) {
                throw new RuntimeException(sprintf('CMD_CONNECT refusée (code %d).', $response['code']));
            }

            $this->sessionId = $response['session'];
            $this->connected = true;

            return true;
        } catch (Throwable $e) {
            $this->closeSocket();
            throw $e;
        }
    }

    /**
     * Ferme la session ZK et le socket.
     */
    public function disconnect(): void
    {
        try {
            if ($this->connected && is_resource($this->socket)) {
                $this->sendCommand(self::COMMAND_EXIT);
            }
        } finally {
            $this->connected = false;
            $this->sessionId = 0;
            $this->closeSocket();
        }
    }

    /**
     * Lit le nombre d’enregistrements annoncé par CMD_GET_FREE_SIZES.
     */
    public function getAttendanceCount(): int
    {
        $sizes = $this->readDeviceSizes();

        return $sizes['records'];
    }

    /**
     * Lit le journal ATTLOG par le buffer ZK 1503/1504 et décode ses formats.
     *
     * @param array<string,int> $userIdToUid Association USERID capteur vers UID interne, utile au format 16 octets.
     * @return list<array{uid:int,user_id:string,state:int,punch:int,timestamp:string}>
     */
    public function getAttendance(array $userIdToUid = []): array
    {
        $records = [];
        foreach ($this->getAttendanceBatches(1000, $userIdToUid) as $batch) {
            array_push($records, ...$batch);
        }

        return $records;
    }

    /**
     * Lit le journal ATTLOG et libère les enregistrements par lots.
     *
     * Le buffer du terminal est préparé une seule fois; les blocs réseau sont
     * demandés par offset et les enregistrements incomplets entre deux blocs
     * restent dans un résidu de quelques octets.
     *
     * @param array<string,int> $userIdToUid Association USERID capteur vers UID interne.
     * @return \Generator<int, list<array{uid:int,user_id:string,state:int,punch:int,timestamp:string}>>
     */
    public function getAttendanceBatches(
        int $batchSize = 1000,
        array $userIdToUid = [],
        int $startIndex = 0,
        ?callable $onPrepared = null
    ): \Generator {
        if (! $this->connected) {
            throw new RuntimeException('Le client ZKTeco doit être connecté avant la lecture.');
        }
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('La taille du lot doit être supérieure à zéro.');
        }
        if ($startIndex < 0) {
            throw new \InvalidArgumentException('L’index de départ des pointages ne peut pas être négatif.');
        }

        $recordCount = $this->readDeviceSizes()['records'];
        if ($onPrepared !== null) {
            $onPrepared($recordCount);
        }
        if ($startIndex > $recordCount) {
            $startIndex = 0;
        }

        if (function_exists('log_message')) {
            log_message('info', 'ZKTecoPullClient : {total} pointages présents, lecture à partir de l’index {startIndex} ({toRead} à lire) en lots de {batchSize}.', [
                'total' => $recordCount,
                'startIndex' => $startIndex,
                'toRead' => $recordCount - $startIndex,
                'batchSize' => $batchSize,
            ]);
        }
        if ($recordCount === 0) {
            return;
        }

        $response = $this->sendCommand(
            self::COMMAND_PREPARE_BUFFER,
            pack('CvVV', 1, self::COMMAND_ATTLOG_RRQ, self::FUNCTION_ATTLOG, 0)
        );

        if ($response['code'] === self::COMMAND_DATA) {
            $data = $response['data'];
            if (strlen($data) < 4) {
                throw new UnexpectedValueException('Réponse directe ATTLOG trop courte.');
            }
            $totalSize = (int) unpack('Vsize', substr($data, 0, 4))['size'];
            if ($totalSize % $recordCount !== 0) {
                throw new UnexpectedValueException('Taille ATTLOG directe incohérente avec le nombre de pointages.');
            }

            yield from $this->parseAttendanceBatches(
                [substr($data, 4, $totalSize)],
                intdiv($totalSize, $recordCount),
                $batchSize,
                $userIdToUid,
                0,
                $startIndex
            );
            return;
        }

        if (! in_array($response['code'], [self::COMMAND_PREPARE_DATA, self::COMMAND_ACK_OK], true)
            || strlen($response['data']) < 5
        ) {
            throw new RuntimeException(sprintf(
                'CMD_PREPARE_BUFFER ATTLOG sans taille exploitable (code=%d, octets=%d, début=%s).',
                $response['code'],
                strlen($response['data']),
                bin2hex(substr($response['data'], 0, 24))
            ));
        }

        $totalSize = (int) unpack('Vsize', substr($response['data'], 1, 4))['size'];
        if ($totalSize < 1 || $totalSize > self::MAX_FRAME_SIZE) {
            throw new UnexpectedValueException(sprintf('Taille du buffer ATTLOG invalide : %d octets.', $totalSize));
        }
        $dataSize = $totalSize - 4;
        if ($dataSize < 0 || $dataSize % $recordCount !== 0) {
            throw new UnexpectedValueException(sprintf(
                'Taille ATTLOG incohérente : buffer=%d octets (préambule 4 octets) pour %d pointages.',
                $totalSize,
                $recordCount
            ));
        }

        $recordSize = intdiv($dataSize, $recordCount);
        if (! in_array($recordSize, [8, 16, 40], true)) {
            throw new UnexpectedValueException(sprintf('Format ATTLOG non pris en charge : %d octets.', $recordSize));
        }

        $offset = $startIndex === 0 ? 0 : 4 + ($startIndex * $recordSize);
        $startOffset = $offset;
        $chunks = (function () use ($totalSize, &$offset): \Generator {
            $chunkLimit = $this->tcp ? self::TCP_MAX_CHUNK : self::UDP_MAX_CHUNK;

            try {
                while ($offset < $totalSize) {
                    $requestedSize = min($chunkLimit, $totalSize - $offset);
                    $chunkResponse = $this->sendCommand(
                        self::COMMAND_READ_BUFFER,
                        pack('V2', $offset, $requestedSize)
                    );

                    if ($chunkResponse['code'] === self::COMMAND_PREPARE_DATA) {
                        if (strlen($chunkResponse['data']) < 4) {
                            throw new UnexpectedValueException('CMD_PREPARE_DATA sans longueur de bloc.');
                        }

                        $chunkLength = (int) unpack('Vsize', substr($chunkResponse['data'], 0, 4))['size'];
                        if ($chunkLength < 1 || $chunkLength > $requestedSize) {
                            throw new UnexpectedValueException(sprintf(
                                'Taille de bloc annoncée invalide : %d octets (demande %d).',
                                $chunkLength,
                                $requestedSize
                            ));
                        }

                        $chunk = $this->receivePreparedChunk($chunkLength);
                    } elseif (in_array($chunkResponse['code'], [self::COMMAND_DATA, self::COMMAND_ACK_DATA], true)) {
                        $chunk = $chunkResponse['data'];
                    } else {
                        throw new RuntimeException(sprintf(
                            'CMD_READ_BUFFER refusée à l’offset %d (code %d).',
                            $offset,
                            $chunkResponse['code']
                        ));
                    }

                    if ($chunk === '') {
                        throw new UnexpectedValueException(sprintf('Bloc ZK vide à l’offset %d.', $offset));
                    }

                    if (strlen($chunk) > $requestedSize) {
                        $chunk = substr($chunk, 0, $requestedSize);
                    }

                    $offset += strlen($chunk);
                    yield $chunk;
                }
            } finally {
                $this->sendCommand(self::COMMAND_FREE_DATA);
            }
        })();

        yield from $this->parseAttendanceBatches(
            $chunks,
            $recordSize,
            $batchSize,
            $userIdToUid,
            $startOffset === 0 ? 4 : 0
        );
    }

    /**
     * Lit les 20 compteurs 32 bits de CMD_GET_FREE_SIZES.
     *
     * @return array{records:int,fields:list<int>}
     */
    private function readDeviceSizes(): array
    {
        $response = $this->sendCommand(self::COMMAND_GET_FREE_SIZES);
        if ($response['code'] !== self::COMMAND_ACK_OK) {
            throw new RuntimeException(sprintf('CMD_GET_FREE_SIZES refusée (code %d).', $response['code']));
        }

        if (strlen($response['data']) < 80) {
            throw new UnexpectedValueException(sprintf(
                'Réponse CMD_GET_FREE_SIZES trop courte : %d octets; 80 attendus.',
                strlen($response['data'])
            ));
        }

        $unpacked = unpack('V20', substr($response['data'], 0, 80));
        $fields = array_values($unpacked ?: []);

        return [
            'records' => (int) ($fields[8] ?? 0),
            'fields' => $fields,
        ];
    }

    /**
     * Exécute l’API bufferisée pyzk : PREPARE_BUFFER, READ_BUFFER et FREE_DATA.
     */
    private function readWithBuffer(int $command, int $function, int $extension = 0): string
    {
        $requestData = pack('CvVV', 1, $command, $function, $extension);
        $response = $this->sendCommand(self::COMMAND_PREPARE_BUFFER, $requestData);

        if ($response['code'] === self::COMMAND_DATA) {
            return $response['data'];
        }

        if (! in_array($response['code'], [self::COMMAND_PREPARE_DATA, self::COMMAND_ACK_OK], true)) {
            throw new RuntimeException(sprintf(
                'CMD_PREPARE_BUFFER refusée (code %d).',
                $response['code']
            ));
        }

        if (strlen($response['data']) < 5) {
            throw new UnexpectedValueException('Réponse CMD_PREPARE_BUFFER sans taille de buffer exploitable.');
        }

        $sizeInfo = unpack('Vsize', substr($response['data'], 1, 4));
        $totalSize = (int) $sizeInfo['size'];
        if ($totalSize < 1 || $totalSize > self::MAX_FRAME_SIZE) {
            throw new UnexpectedValueException(sprintf('Taille de buffer ZK invalide : %d octets.', $totalSize));
        }

        $data = '';
        $offset = 0;
        $chunkLimit = $this->tcp ? self::TCP_MAX_CHUNK : self::UDP_MAX_CHUNK;

        try {
            while ($offset < $totalSize) {
                $requestedSize = min($chunkLimit, $totalSize - $offset);
                $chunkResponse = $this->sendCommand(
                    self::COMMAND_READ_BUFFER,
                    pack('V2', $offset, $requestedSize)
                );

                if ($chunkResponse['code'] === self::COMMAND_PREPARE_DATA) {
                    if (strlen($chunkResponse['data']) < 4) {
                        throw new UnexpectedValueException('CMD_PREPARE_DATA sans longueur de bloc.');
                    }

                    $chunkLength = (int) unpack('Vsize', substr($chunkResponse['data'], 0, 4))['size'];
                    if ($chunkLength < 1 || $chunkLength > $requestedSize) {
                        throw new UnexpectedValueException(sprintf(
                            'Taille de bloc annoncée invalide : %d octets (demande %d).',
                            $chunkLength,
                            $requestedSize
                        ));
                    }

                    $chunk = $this->receivePreparedChunk($chunkLength);
                } elseif (in_array($chunkResponse['code'], [self::COMMAND_DATA, self::COMMAND_ACK_DATA], true)) {
                    $chunk = $chunkResponse['data'];
                } else {
                    throw new RuntimeException(sprintf(
                        'CMD_READ_BUFFER réponse inattendue à l’offset %d : code=%d, octets=%d.',
                        $offset,
                        $chunkResponse['code'],
                        strlen($chunkResponse['data'])
                    ));
                }

                if ($chunk === '') {
                    throw new UnexpectedValueException(sprintf('Bloc ZK vide à l’offset %d.', $offset));
                }

                $chunk = substr($chunk, 0, $requestedSize);
                $data .= $chunk;
                $offset += strlen($chunk);
            }
        } finally {
            $this->sendCommand(self::COMMAND_FREE_DATA);
        }

        return $data;
    }

    /**
     * Reçoit le payload TCP annoncé par CMD_PREPARE_DATA, puis son ACK de fin.
     */
    private function receivePreparedChunk(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $frame = $this->receiveResponse();
            if ($frame['code'] !== self::COMMAND_DATA) {
                throw new RuntimeException(sprintf(
                    'Trame de données ZK attendue (1501), code reçu : %d.',
                    $frame['code']
                ));
            }
            if ($frame['data'] === '') {
                throw new UnexpectedValueException('Trame CMD_DATA vide pendant la lecture bufferisée.');
            }

            $data .= $frame['data'];
            if (strlen($data) > $length) {
                throw new UnexpectedValueException(sprintf(
                    'Le capteur a envoyé %d octets pour un bloc annoncé de %d.',
                    strlen($data),
                    $length
                ));
            }
        }

        $acknowledgment = $this->receiveResponse();
        if ($acknowledgment['code'] !== self::COMMAND_ACK_OK) {
            throw new RuntimeException(sprintf(
                'ACK de bloc attendu (%d), code reçu : %d.',
                self::COMMAND_ACK_OK,
                $acknowledgment['code']
            ));
        }

        return $data;
    }

    /**
     * Décode les formats ATTLOG de 8, 16 et 40 octets utilisés par pyzk.
     *
     * @param array<string,int> $userIdToUid
     * @return list<array{uid:int,user_id:string,state:int,punch:int,timestamp:string}>
     */
    protected function parseAttendanceRecords(string $data, int $recordSize, array $userIdToUid = []): array
    {
        if (! in_array($recordSize, [8, 16, 40], true)) {
            throw new UnexpectedValueException(sprintf('Format ATTLOG non pris en charge : %d octets.', $recordSize));
        }

        if (strlen($data) % $recordSize !== 0) {
            throw new UnexpectedValueException(sprintf(
                'Données ATTLOG non alignées sur des enregistrements de %d octets (%d reçus).',
                $recordSize,
                strlen($data)
            ));
        }

        $records = [];
        for ($offset = 0, $length = strlen($data); $offset < $length; $offset += $recordSize) {
            $record = substr($data, $offset, $recordSize);
            $records[] = $this->parseAttendanceRecord($record, $recordSize, $userIdToUid);
        }

        return $records;
    }

    /**
     * Transforme des blocs bruts en lots, en conservant seulement un record incomplet.
     *
     * @param iterable<string> $chunks
     * @param array<string,int> $userIdToUid
     * @return \Generator<int, list<array{uid:int,user_id:string,state:int,punch:int,timestamp:string}>>
     */
    protected function parseAttendanceBatches(
        iterable $chunks,
        int $recordSize,
        int $batchSize,
        array $userIdToUid = [],
        int $skipBytes = 0,
        int $skipRecords = 0
    ): \Generator {
        if (! in_array($recordSize, [8, 16, 40], true)) {
            throw new UnexpectedValueException(sprintf('Format ATTLOG non pris en charge : %d octets.', $recordSize));
        }
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('La taille du lot doit être supérieure à zéro.');
        }
        if ($skipBytes < 0 || $skipRecords < 0) {
            throw new \InvalidArgumentException('Les offsets ATTLOG ne peuvent pas être négatifs.');
        }

        $buffer = '';
        $batch = [];
        foreach ($chunks as $chunk) {
            if ($skipBytes > 0) {
                $skipped = min($skipBytes, strlen($chunk));
                $chunk = substr($chunk, $skipped);
                $skipBytes -= $skipped;
            }

            $buffer .= $chunk;
            while (strlen($buffer) >= $recordSize) {
                $recordData = substr($buffer, 0, $recordSize);
                $buffer = substr($buffer, $recordSize);

                if ($skipRecords > 0) {
                    $skipRecords--;
                    continue;
                }

                $batch[] = $this->parseAttendanceRecord($recordData, $recordSize, $userIdToUid);
                if (count($batch) >= $batchSize) {
                    yield $batch;
                    $batch = [];
                }
            }
        }

        if ($buffer !== '') {
            throw new UnexpectedValueException(sprintf('Buffer ATTLOG terminé avec %d octets incomplets.', strlen($buffer)));
        }
        if ($skipBytes !== 0) {
            throw new UnexpectedValueException(sprintf('Préambule ATTLOG tronqué : %d octets manquants.', $skipBytes));
        }
        if ($batch !== []) {
            yield $batch;
        }
    }

    /**
     * Décode un seul enregistrement selon le format déduit du buffer.
     *
     * @param array<string,int> $userIdToUid
     * @return array{uid:int,user_id:string,state:int,punch:int,timestamp:string}
     */
    protected function parseAttendanceRecord(string $record, int $recordSize, array $userIdToUid = []): array
    {
        if ($recordSize === 8) {
            $fields = unpack('vuid/Cstate/a4time/Cpunch', $record);
            $userId = (string) $fields['uid'];
            $uid = (int) $fields['uid'];
            $timeBytes = $fields['time'];
            $state = (int) $fields['state'];
            $punch = (int) $fields['punch'];
        } elseif ($recordSize === 16) {
            $fields = unpack('Vuser_id/a4time/Cstate/Cpunch/a2reserved/Vworkcode', $record);
            $userId = (string) $fields['user_id'];
            $uid = $userIdToUid[$userId] ?? (ctype_digit($userId) ? (int) $userId : 0);
            $timeBytes = $fields['time'];
            $state = (int) $fields['state'];
            $punch = (int) $fields['punch'];
        } else {
            if (str_starts_with($record, "\xFF255\x00\x00\x00\x00\x00")) {
                throw new UnexpectedValueException('Enregistrement ATTLOG 40 octets avec préfixe sentinelle inattendu.');
            }

            $fields = unpack('vuid/a24user_id/Cstate/a4time/Cpunch/a8reserved', $record);
            $userId = $this->trimNullTerminated($fields['user_id']);
            $uid = (int) $fields['uid'];
            $timeBytes = $fields['time'];
            $state = (int) $fields['state'];
            $punch = (int) $fields['punch'];
        }

        return [
            'uid' => $uid,
            'user_id' => $userId,
            'state' => $state,
            'punch' => $punch,
            'timestamp' => $this->decodeZkTimestamp($timeBytes),
        ];
    }

    /**
     * Décode l’horodatage compact ZK en Y-m-d H:i:s.
     */
    protected function decodeZkTimestamp(string $bytes): string
    {
        if (strlen($bytes) !== 4) {
            throw new UnexpectedValueException('Horodatage ZK invalide : 4 octets attendus.');
        }

        $decoded = unpack('Vtimestamp', $bytes);
        $value = (int) $decoded['timestamp'];
        $second = $value % 60;
        $value = intdiv($value, 60);
        $minute = $value % 60;
        $value = intdiv($value, 60);
        $hour = $value % 24;
        $value = intdiv($value, 24);
        $day = $value % 31 + 1;
        $value = intdiv($value, 31);
        $month = $value % 12 + 1;
        $year = intdiv($value, 12) + 2000;

        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            throw new UnexpectedValueException(sprintf(
                'Horodatage ZK hors limites : hex=%s, valeur=%d, décodage=%04d-%02d-%02d %02d:%02d:%02d.',
                bin2hex($bytes),
                $decoded['timestamp'],
                $year,
                $month,
                $day,
                $hour,
                $minute,
                $second
            ));
        }

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
    }

    /**
     * Envoie une commande ZK puis lit et valide la réponse.
     *
     * @return array{code:int,checksum:int,session:int,reply:int,data:string}
     */
    private function sendCommand(int $command, string $data = ''): array
    {
        if (! is_resource($this->socket)) {
            throw new RuntimeException('Socket ZK non connecté.');
        }

        $packet = $this->buildZkPacket($command, $data);
        if ($this->tcp) {
            $packet = pack('vvV', self::TCP_MAGIC_1, self::TCP_MAGIC_2, strlen($packet)) . $packet;
        }

        $this->writeAll($packet);
        $response = $this->receiveResponse();
        $this->replyId = $response['reply'];

        return $response;
    }

    /**
     * Construit l’en-tête ZK et son checksum en conservant la séquence pyzk.
     */
    protected function buildZkPacket(int $command, string $data = ''): string
    {
        $checksumInput = pack('v4', $command, 0, $this->sessionId, $this->replyId) . $data;
        $checksum = $this->calculateChecksum($checksumInput);
        $reply = $this->replyId + 1;
        if ($reply >= self::USHRT_MAX) {
            $reply -= self::USHRT_MAX;
        }

        return pack('v4', $command, $checksum, $this->sessionId, $reply) . $data;
    }

    /**
     * Calcule le checksum 16 bits du protocole ZK.
     */
    protected function calculateChecksum(string $packet): int
    {
        $sum = 0;
        $length = strlen($packet);
        for ($offset = 0; $offset + 1 < $length; $offset += 2) {
            $word = unpack('vvalue', substr($packet, $offset, 2));
            $sum += (int) $word['value'];
            if ($sum > self::USHRT_MAX) {
                $sum -= self::USHRT_MAX;
            }
        }

        if ($length % 2 === 1) {
            $sum += ord($packet[$length - 1]);
        }
        while ($sum > self::USHRT_MAX) {
            $sum -= self::USHRT_MAX;
        }

        $checksum = ~$sum;
        while ($checksum < 0) {
            $checksum += self::USHRT_MAX;
        }
        while ($checksum > self::USHRT_MAX) {
            $checksum -= self::USHRT_MAX;
        }

        return $checksum;
    }

    /**
     * Reçoit une trame TCP encapsulée ou un datagramme UDP.
     *
     * @return array{code:int,checksum:int,session:int,reply:int,data:string}
     */
    private function receiveResponse(): array
    {
        if ($this->tcp) {
            $tcpHeader = $this->readExact(8);
            $wrapper = unpack('vmagic1/vmagic2/Vlength', $tcpHeader);

            if ($wrapper['magic1'] !== self::TCP_MAGIC_1 || $wrapper['magic2'] !== self::TCP_MAGIC_2) {
                throw new UnexpectedValueException('En-tête TCP ZK invalide.');
            }

            $length = (int) $wrapper['length'];
            if ($length < 8 || $length > self::MAX_FRAME_SIZE) {
                throw new UnexpectedValueException(sprintf('Longueur de trame TCP ZK invalide : %d.', $length));
            }

            $frame = $this->readExact($length);
        } else {
            $frame = fread($this->socket, 65507);
            if (! is_string($frame) || $frame === '') {
                throw new RuntimeException('Aucune réponse UDP du capteur ZK.');
            }
        }

        if (strlen($frame) < 8) {
            throw new UnexpectedValueException('Réponse ZK plus courte que son en-tête de 8 octets.');
        }

        $header = unpack('vcode/vchecksum/vsession/vreply', substr($frame, 0, 8));

        return [
            'code' => (int) $header['code'],
            'checksum' => (int) $header['checksum'],
            'session' => (int) $header['session'],
            'reply' => (int) $header['reply'],
            'data' => substr($frame, 8),
        ];
    }

    /**
     * Lit exactement le nombre d’octets demandé sur le flux TCP.
     */
    private function readExact(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));
            if (! is_string($chunk) || $chunk === '') {
                $metadata = stream_get_meta_data($this->socket);
                $reason = ! empty($metadata['timed_out']) ? 'timeout' : 'flux fermé';
                throw new RuntimeException(sprintf('Lecture TCP ZK interrompue (%s) : %d/%d octets.', $reason, strlen($data), $length));
            }
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * Écrit une trame complète sur le socket.
     */
    private function writeAll(string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($this->socket, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException(sprintf('Écriture socket ZK interrompue à %d/%d octets.', $offset, $length));
            }
            $offset += $written;
        }
    }

    /**
     * Produit la clé de session utilisée par l’authentification ZK historique.
     */
    private function makeCommKey(int $key, int $sessionId, int $ticks = 50): string
    {
        $value = $key;
        $scrambled = 0;
        for ($bit = 0; $bit < 32; $bit++) {
            $scrambled = ($scrambled << 1) | (($value & 1) !== 0 ? 1 : 0);
            $value >>= 1;
        }

        $bytes = array_values(unpack('C4', pack('V', ($scrambled + $sessionId) & 0xFFFFFFFF)));
        $bytes = [
            $bytes[0] ^ ord('Z'),
            $bytes[1] ^ ord('K'),
            $bytes[2] ^ ord('S'),
            $bytes[3] ^ ord('O'),
        ];
        $words = unpack('v2', pack('C4', ...$bytes));
        $bytes = array_values(unpack('C4', pack('v2', $words[2], $words[1])));
        $tick = $ticks & 0xFF;

        return pack(
            'C4',
            $bytes[0] ^ $tick,
            $bytes[1] ^ $tick,
            $tick,
            $bytes[3] ^ $tick
        );
    }

    /**
     * Supprime le padding nul d’un champ texte fixe.
     */
    private function trimNullTerminated(string $value): string
    {
        return trim(explode("\0", $value, 2)[0]);
    }

    /**
     * Ferme le flux s’il est ouvert.
     */
    private function closeSocket(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    /**
     * Ajoute ou met à jour un utilisateur sur le capteur.
     * Protocole ZK : CMD_USER_WRQ (8)
     *
     * @param int $uid Identifiant unique sur le capteur (badge_id)
     * @param string $userId Code RH (userid du capteur)
     * @param string $name Nom complet
     * @param string $password Mot de passe (optionnel)
     * @param int $role Rôle ZK (0=user, 14=admin)
     * @param string $cardNo Numéro de carte (optionnel)
     * @return bool Succès
     */
    public function setUser(int $uid, string $userId, string $name, string $password = '', int $role = 0, string $cardNo = ''): bool
    {
        // Buffer de 72 octets selon le protocole ZK
        $buffer = str_repeat("\x00", 72);
        $buffer = substr_replace($buffer, pack('v', $uid), 0, 2);
        $buffer = substr_replace($buffer, chr($role), 2, 1);
        $pwd = substr($password, 0, 8);
        $buffer = substr_replace($buffer, str_pad($pwd, 8, "\x00"), 3, 8);
        $nom = substr($name, 0, 24);
        $buffer = substr_replace($buffer, str_pad($nom, 24, "\x00"), 11, 24);
        $cardno = (int) $cardNo;
        $buffer = substr_replace($buffer, pack('V', $cardno), 35, 4);
        // Bytes 39-46 : group + timezone (laisser à 0)
        // Bytes 47-55 : userid (9 chars)
        $userIdPadded = str_pad(substr($userId, 0, 9), 9, "\x00");
        $buffer = substr_replace($buffer, $userIdPadded, 47, 9);
        $response = $this->sendCommand(8, $buffer);
        return ($response['code'] ?? 0) === self::COMMAND_ACK_OK;
    }

    /**
     * Met à jour un utilisateur existant.
     * Le protocole ZK remplace les données par celles envoyées (pas de patch partiel).
     *
     * @param int $uid Identifiant unique sur le capteur
     * @param array $data Données à mettre à jour
     * @return bool Succès
     */
    public function updateUser(int $uid, array $data): bool
    {
        return $this->setUser(
            $uid,
            $data['user_id'] ?? '',
            $data['name'] ?? '',
            $data['password'] ?? '',
            $data['role'] ?? 0,
            $data['card_no'] ?? ''
        );
    }

    /**
     * Désactive le capteur pendant une opération d'écriture.
     * Protocole ZK : CMD_DISABLEDEVICE (1003)
     *
     * @return bool Succès
     */
    public function disableDevice(): bool
    {
        $response = $this->sendCommand(1003);
        return ($response['code'] ?? 0) === self::COMMAND_ACK_OK;
    }

    /**
     * Réactive le capteur après une opération.
     * Protocole ZK : CMD_ENABLEDEVICE (1002)
     *
     * @return bool Succès
     */
    public function enableDevice(): bool
    {
        $response = $this->sendCommand(1002);
        return ($response['code'] ?? 0) === self::COMMAND_ACK_OK;
    }

    /**
     * Supprime un utilisateur du capteur.
     * Protocole ZK : CMD_DELETE_USER (18)
     *
     * @param int $uid Identifiant unique sur le capteur
     * @return bool Succès
     */
    public function removeUser(int $uid): bool
    {
        $payload = pack('v', $uid);
        $response = $this->sendCommand(self::CMD_DELETE_USER, $payload);
        return ($response['code'] ?? 0) === self::COMMAND_ACK_OK;
    }
}
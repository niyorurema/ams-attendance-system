<?php

use App\Libraries\ZKTecoPullClient;
use CodeIgniter\Test\CIUnitTestCase;
use Jmrashed\Zkteco\Lib\Helper\Util;

/**
 * @internal
 */
final class ZKTecoPullClientTest extends CIUnitTestCase
{
    public function testDecodeLeFormatDePointageDeHuitOctets(): void
    {
        $client = $this->createClient();
        $record = pack('vCVC', 2, 0, $this->encodeTime(2026, 10, 1, 8, 15, 0), 1);

        $result = $client->parseForTest($record, 8);

        $this->assertSame(2, $result[0]['uid']);
        $this->assertSame('2026-10-01 08:15:00', $result[0]['timestamp']);
        $this->assertSame(0, $result[0]['state']);
        $this->assertSame(1, $result[0]['punch']);
    }

    public function testDecodeLeFormatDeSeizeOctetsEtAssocieLeUserId(): void
    {
        $client = $this->createClient();
        $encodedTime = $this->encodeTime(2026, 10, 1, 8, 16, 0);
        $record = pack('V', 10041)
            . pack('V', $encodedTime)
            . pack('C2', 1, 0)
            . "\0\0"
            . pack('V', 0);

        $result = $client->parseForTest($record, 16, ['10041' => 2]);

        $this->assertSame(2, $result[0]['uid']);
        $this->assertSame('10041', $result[0]['user_id']);
        $this->assertSame('2026-10-01 08:16:00', $result[0]['timestamp']);
        $this->assertSame(1, $result[0]['state']);
    }

    public function testDecodeLeFormatDeQuaranteOctets(): void
    {
        $client = $this->createClient();
        $record = pack('v', 2)
            . str_pad('10041', 24, "\0")
            . pack('C', 0)
            . pack('V', $this->encodeTime(2026, 10, 1, 8, 17, 0))
            . pack('C', 1)
            . str_repeat("\0", 8);

        $result = $client->parseForTest($record, 40);

        $this->assertSame(2, $result[0]['uid']);
        $this->assertSame('10041', $result[0]['user_id']);
        $this->assertSame('2026-10-01 08:17:00', $result[0]['timestamp']);
    }

    public function testStreamLesLotsEtRecomposeLesEnregistrementsEntreBlocs(): void
    {
        $client = $this->createClient();
        $records = '';
        for ($uid = 1; $uid <= 5; $uid++) {
            $records .= pack('vCVC', $uid, 0, $this->encodeTime(2026, 10, 1, 8, 0, $uid), 1);
        }

        $chunks = [
            substr($records, 0, 13),
            substr($records, 13, 17),
            substr($records, 30),
        ];
        $batches = iterator_to_array($client->parseBatchesForTest($chunks, 8, 2), false);

        $this->assertSame([2, 2, 1], array_map('count', $batches));
        $this->assertSame([1, 2, 3, 4, 5], array_column(array_merge(...$batches), 'uid'));
    }

    public function testCommenceLaLectureAuPremierIndexNonTraite(): void
    {
        $client = $this->createClient();
        $records = '';
        for ($uid = 1; $uid <= 5; $uid++) {
            $records .= pack('vCVC', $uid, 0, $this->encodeTime(2026, 10, 1, 8, 0, $uid), 1);
        }

        $batches = iterator_to_array($client->parseBatchesForTest([$records], 8, 2, 3), false);

        $this->assertSame([4, 5], array_column(array_merge(...$batches), 'uid'));
    }

    public function testRejetteUneTailleDenregistrementInconnue(): void
    {
        $client = $this->createClient();

        $this->expectException(UnexpectedValueException::class);
        $client->parseForTest(str_repeat("\0", 12), 12);
    }

    public function testEnteteEtChecksumCorrespondentAuConstructeurZkExistant(): void
    {
        $client = $this->createClient();
        $payload = pack('CvVV', 1, 13, 1, 0);

        $this->assertSame(
            Util::createHeader(1503, 0, 0, 65534, $payload),
            $client->buildPacketForTest(1503, $payload)
        );
    }

    private function createClient(): ZKTecoPullClient
    {
        return new class ('127.0.0.1') extends ZKTecoPullClient {
            public function parseForTest(string $data, int $recordSize, array $mapping = []): array
            {
                return $this->parseAttendanceRecords($data, $recordSize, $mapping);
            }

            public function parseBatchesForTest(
                iterable $chunks,
                int $recordSize,
                int $batchSize,
                int $skipRecords = 0
            ): Generator
            {
                yield from $this->parseAttendanceBatches($chunks, $recordSize, $batchSize, [], 0, $skipRecords);
            }

            public function buildPacketForTest(int $command, string $data = ''): string
            {
                return $this->buildZkPacket($command, $data);
            }
        };
    }

    private function encodeTime(int $year, int $month, int $day, int $hour, int $minute, int $second): int
    {
        return (($year % 100) * 12 * 31 + (($month - 1) * 31) + $day - 1) * 86400
            + ($hour * 60 + $minute) * 60
            + $second;
    }
}
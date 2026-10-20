<?php

namespace Tests\Unit;

use App\Exceptions\SecretDeliveryException;
use App\Services\AwsSecretsManagerStore;
use Aws\MockHandler;
use Aws\Result;
use Aws\SecretsManager\SecretsManagerClient;
use PHPUnit\Framework\TestCase;

class AwsSecretsManagerStoreTest extends TestCase
{
    public function test_it_creates_and_deletes_only_the_owned_secret(): void
    {
        $arn = 'arn:aws:secretsmanager:us-east-1:123456789012:secret:test';
        $tags = [
            ['Key' => 'ManagedBy', 'Value' => 'NEXUS-AI-Laravel'],
            ['Key' => 'ProjectId', 'Value' => 'project-1'],
            ['Key' => 'Application', 'Value' => 'Partner app'],
        ];
        $handler = new MockHandler([
            new Result(['ARN' => $arn]),
            new Result(['ARN' => $arn, 'Tags' => $tags]),
            new Result(['ARN' => $arn]),
        ]);
        $client = new SecretsManagerClient([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
            'retries' => 0,
        ]);
        $store = new AwsSecretsManagerStore($client);
        $secretName = 'nexus-ai/application-keys/projects/project-1/applications/key-1';

        self::assertSame($arn, $store->store(
            $secretName,
            ['api_key' => 'secret-value'],
            'Application key',
            ['ManagedBy' => 'NEXUS-AI-Laravel', 'ProjectId' => 'project-1', 'Application' => 'Partner app'],
        ));
        self::assertSame('CreateSecret', $handler->getLastCommand()->getName());
        self::assertSame($secretName, $handler->getLastCommand()['Name']);

        $store->delete($secretName, [
            'ManagedBy' => 'NEXUS-AI-Laravel',
            'ProjectId' => 'project-1',
            'Application' => 'Partner app',
        ]);

        self::assertSame('DeleteSecret', $handler->getLastCommand()->getName());
        self::assertSame($secretName, $handler->getLastCommand()['SecretId']);
        self::assertSame(7, $handler->getLastCommand()['RecoveryWindowInDays']);
        self::assertCount(0, $handler);
    }

    public function test_it_refuses_to_delete_a_secret_with_mismatched_ownership_tags(): void
    {
        $handler = new MockHandler([
            new Result(['Tags' => [
                ['Key' => 'ManagedBy', 'Value' => 'Other system'],
                ['Key' => 'ProjectId', 'Value' => 'project-1'],
                ['Key' => 'Application', 'Value' => 'Partner app'],
            ]]),
        ]);
        $client = new SecretsManagerClient([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
            'retries' => 0,
        ]);
        $store = new AwsSecretsManagerStore($client);

        try {
            $store->delete('secret-name', [
                'ManagedBy' => 'NEXUS-AI-Laravel',
                'ProjectId' => 'project-1',
                'Application' => 'Partner app',
            ]);
            self::fail('Expected the store to refuse deleting a secret it does not own.');
        } catch (SecretDeliveryException) {
            self::assertSame('DescribeSecret', $handler->getLastCommand()->getName());
            self::assertCount(0, $handler);
        }
    }
}

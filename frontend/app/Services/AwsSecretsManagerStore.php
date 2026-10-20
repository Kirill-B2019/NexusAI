<?php

namespace App\Services;

use App\Exceptions\SecretDeliveryException;
use Aws\Exception\AwsException;
use Aws\SecretsManager\SecretsManagerClient;
use Throwable;

final class AwsSecretsManagerStore implements ApplicationSecretStore
{
    public function __construct(private readonly ?SecretsManagerClient $secretsManager = null) {}

    public function store(string $name, array $secret, string $description, array $tags): string
    {
        try {
            $result = $this->client()->createSecret([
                'Name' => $name,
                'Description' => $description,
                'SecretString' => json_encode($secret, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'Tags' => array_map(
                    static fn (string $key, string $value): array => ['Key' => $key, 'Value' => $value],
                    array_keys($tags),
                    array_values($tags),
                ),
            ]);
        } catch (Throwable $exception) {
            throw new SecretDeliveryException('AWS Secrets Manager could not store the application key.', previous: $exception);
        }

        $arn = $result['ARN'] ?? null;
        if (! is_string($arn) || $arn === '') {
            throw new SecretDeliveryException('AWS Secrets Manager returned no secret ARN.');
        }

        return $arn;
    }

    public function delete(string $name, array $expectedTags): void
    {
        try {
            $client = $this->client();
            $result = $client->describeSecret(['SecretId' => $name]);
        } catch (AwsException $exception) {
            if ($exception->getAwsErrorCode() === 'ResourceNotFoundException') {
                return;
            }

            throw new SecretDeliveryException('AWS Secrets Manager could not verify the application secret.', previous: $exception);
        } catch (Throwable $exception) {
            throw new SecretDeliveryException('AWS Secrets Manager could not verify the application secret.', previous: $exception);
        }

        $actualTags = [];
        foreach ($result['Tags'] ?? [] as $tag) {
            if (is_array($tag) && is_string($tag['Key'] ?? null) && is_string($tag['Value'] ?? null)) {
                $actualTags[$tag['Key']] = $tag['Value'];
            }
        }

        foreach ($expectedTags as $key => $value) {
            if (($actualTags[$key] ?? null) !== $value) {
                throw new SecretDeliveryException('AWS secret ownership tags do not match the revoked application key.');
            }
        }

        if (isset($result['DeletedDate'])) {
            return;
        }

        try {
            $client->deleteSecret([
                'SecretId' => $name,
                'RecoveryWindowInDays' => 7,
            ]);
        } catch (Throwable $exception) {
            throw new SecretDeliveryException('AWS Secrets Manager could not schedule deletion of the application secret.', previous: $exception);
        }
    }

    private function client(): SecretsManagerClient
    {
        return $this->secretsManager ?? new SecretsManagerClient([
            'version' => 'latest',
            'region' => config('services.application_key_delivery.region'),
        ]);
    }
}

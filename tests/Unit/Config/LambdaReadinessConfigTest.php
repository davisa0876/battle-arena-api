<?php

namespace Tests\Unit\Config;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards configuration that only matters on AWS Lambda (Bref), where credentials are
 * temporary role credentials and storage/ is relocated to /tmp at runtime.
 */
class LambdaReadinessConfigTest extends TestCase
{
    #[Test]
    public function test_s3_disks_forward_the_session_token_of_temporary_credentials(): void
    {
        foreach (['s3', 'profiles'] as $disk) {
            config([
                "filesystems.disks.{$disk}.key" => 'ASIAEXAMPLE',
                "filesystems.disks.{$disk}.secret" => 'secret',
                "filesystems.disks.{$disk}.token" => 'session-token',
                "filesystems.disks.{$disk}.bucket" => 'bucket',
            ]);

            /** @var S3Client $client */
            $client = Storage::build(config("filesystems.disks.{$disk}"))->getClient();
            $credentials = $client->getCredentials()->wait();

            $this->assertSame('session-token', $credentials->getSecurityToken(), "{$disk} disk drops AWS_SESSION_TOKEN");
        }
    }

    #[Test]
    public function test_openapi_spec_is_read_from_the_application_package(): void
    {
        $path = (string) config('l5-swagger.defaults.paths.docs');

        $this->assertSame(base_path('storage/api-docs'), $path);
        $this->assertFileExists($path.'/api-docs.yaml');
    }
}

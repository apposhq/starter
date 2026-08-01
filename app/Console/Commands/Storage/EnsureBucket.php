<?php

namespace App\Console\Commands\Storage;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('storage:bucket')]
#[Description('Create the configured S3 buckets if they do not exist yet')]
class EnsureBucket extends Command
{
    /**
     * SeaweedFS creates no bucket of its own, so a fresh clone has nowhere to put files until this runs —
     * which `mise up` does. Bucket names come from the disks themselves rather than the environment, so
     * they stay configured in exactly one place.
     *
     * Already-exists is the ordinary case on every run after the first and is not an error.
     */
    public function handle(): int
    {
        foreach ($this->objectStorageDisks() as $disk) {
            if (! $this->ensure($disk)) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Every disk config/filesystems.php backs with object storage.
     *
     * Read from config rather than listed here, so adding a third bucket cannot silently skip provisioning
     * — which surfaces much later as a NoSuchBucket on the first write to it.
     *
     * @return array<int, string>
     */
    protected function objectStorageDisks(): array
    {
        return collect(config()->array('filesystems.disks'))
            ->filter(fn (array $disk): bool => ($disk['driver'] ?? null) === 's3')
            ->keys()
            ->all();
    }

    /**
     * Create one disk's bucket, reporting what happened.
     */
    protected function ensure(string $disk): bool
    {
        $bucket = (string) config("filesystems.disks.{$disk}.bucket");

        $filesystem = Storage::disk($disk);

        // getClient() lives on the S3 adapter rather than the Filesystem contract, so narrowing here is
        // what makes the call visible to static analysis instead of resolving through __call.
        if (! $filesystem instanceof AwsS3V3Adapter) {
            $this->components->error("Disk [{$disk}] is not backed by S3.");

            return false;
        }

        try {
            $client = $filesystem->getClient();

            if ($client->doesBucketExist($bucket)) {
                $this->components->info("Bucket [{$bucket}] already exists.");

                return true;
            }

            $client->createBucket(['Bucket' => $bucket]);
        } catch (Throwable $e) {
            $this->components->error("Could not create bucket [{$bucket}]: {$e->getMessage()}");

            return false;
        }

        $this->components->info("Created bucket [{$bucket}].");

        return true;
    }
}

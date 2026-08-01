<?php

namespace App\Console\Commands\Rum;

use App\Providers\AppServiceProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Phar;
use PharData;

#[Signature('rum:sourcemaps')]
#[Description('Upload the current build\'s source maps to OpenObserve, then strip them from public/build')]
class UploadSourcemaps extends Command
{
    /**
     * Without this a production RUM stack trace is minified chunk names and line 1, column 48219.
     *
     * OpenObserve matches a map to an error on service, version and environment, so all three have to
     * equal what the browser reports. They are read from config rather than recomputed, because a value
     * derived twice eventually disagrees and the only symptom is stack traces that stay minified.
     *
     * Maps are deleted afterwards: they are uploaded but never served, which is the whole point of doing
     * this here rather than shipping them in the image, where FrankenPHP serves public/ and a map hands
     * the entire TypeScript source to any visitor.
     */
    public function handle(): int
    {
        $maps = glob(public_path('build/assets/*.js.map')) ?: [];

        // A successful run deletes the maps, so without this a second run would package an empty archive,
        // upload it, and exit 0 — replacing a good upload with nothing while reporting success.
        if ($maps === []) {
            $this->components->error('No source maps in public/build. Run `mise build` first.');

            return self::FAILURE;
        }

        // The same value the browser reports in every RUM error; OpenObserve matches a map to an error
        // on service+version+env, so a second derivation here is a triple that never matches.
        $version = AppServiceProvider::assetVersion();

        $archive = $this->archive($maps);

        // A stream, not a string: Guzzle sends it as it reads, where file_get_contents would hold the whole
        // archive and Guzzle's copy of it in memory at once — a build's worth of maps runs to tens of MB.
        $stream = fopen($archive, 'r');

        if ($stream === false) {
            $this->components->error("Could not read the source map archive at [{$archive}].");

            return self::FAILURE;
        }

        $response = Http::withBasicAuth((string) config('rum.username'), (string) config('rum.password'))
            ->attach('file', $stream, 'sourcemaps.zip')
            ->post(sprintf('%s/api/%s/sourcemaps', config('rum.base_url'), config('rum.organization')), [
                'service' => (string) config('rum.service'),
                'version' => $version,
                'env' => (string) config('rum.env'),
            ]);

        unlink($archive);

        if ($response->failed()) {
            $this->components->error("Upload failed: {$response->status()} {$response->body()}");

            return self::FAILURE;
        }

        array_map(unlink(...), $maps);

        $this->components->info(sprintf(
            'Uploaded %d source maps: service=%s version=%s env=%s',
            count($maps),
            config('rum.service'),
            $version,
            config('rum.env'),
        ));

        return self::SUCCESS;
    }

    /**
     * Package each map beside the script it belongs to.
     *
     * OpenObserve rejects the entire upload if any .js in the archive has no sibling .js.map, and the
     * rolldown runtime chunk is emitted without one, so pairs are assembled explicitly rather than
     * globbed. Archive paths mirror public/build so they match the URLs in a stack trace.
     *
     * PharData rather than ZipArchive: ext-zip exists only in the Dockerfile's vendor stage, where it
     * serves Composer's extraction, and is deliberately absent from the runtime image. ext-phar is
     * present everywhere, and writing a non-executable archive is allowed regardless of phar.readonly.
     *
     * @param  array<int, string>  $maps
     */
    protected function archive(array $maps): string
    {
        $path = sprintf('%s/sourcemaps-%s.zip', sys_get_temp_dir(), bin2hex(random_bytes(8)));

        $archive = new PharData($path, 0, null, Phar::ZIP);

        foreach ($maps as $map) {
            $script = substr($map, 0, -strlen('.map'));

            if (! file_exists($script)) {
                continue;
            }

            $archive->addFile($script, 'assets/'.basename($script));
            $archive->addFile($map, 'assets/'.basename($map));
        }

        return $path;
    }
}

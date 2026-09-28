<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Config;
use App\Support\ViewContext;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prepara el bucket: lo crea si no existe, bloquea el acceso público, activa cifrado,
 * configura CORS para el origen de la app y una regla de ciclo de vida para multiparts incompletos.
 * Con --check solo verifica la configuración y los permisos.
 */
#[AsCommand(name: 's3:setup', description: 'Crea/configura el bucket S3 (privado, CORS, ciclo de vida) o lo verifica con --check.')]
final class S3SetupCommand extends Command
{
    public function __construct(
        private readonly S3Client $client,
        private readonly Config $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_NONE, 'Solo verificar (no modifica nada)')
            ->addOption('origin', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Orígenes adicionales permitidos en CORS');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $bucket = $this->config->string('s3.bucket');
        $region = $this->config->string('s3.region', 'us-east-1');
        if ($bucket === '') {
            $io->error('S3_BUCKET no está configurado en .env');

            return Command::FAILURE;
        }
        $origins = array_values(array_unique(array_filter(array_merge(
            [ViewContext::origin($this->config->string('app.url'))],
            (array) $input->getOption('origin'),
        ))));
        $io->title(sprintf('Bucket "%s" (%s)', $bucket, $region));

        if ($input->getOption('check')) {
            return $this->check($io, $bucket);
        }

        try {
            if ($this->client->doesBucketExistV2($bucket, false)) {
                $io->writeln('✔ El bucket ya existe.');
            } else {
                $params = ['Bucket' => $bucket];
                if ($region !== 'us-east-1' && $this->config->string('s3.endpoint') === '') {
                    $params['CreateBucketConfiguration'] = ['LocationConstraint' => $region];
                }
                $this->client->createBucket($params);
                $this->client->waitUntil('BucketExists', ['Bucket' => $bucket]);
                $io->writeln('✔ Bucket creado.');
            }

            $this->step($io, 'Bloqueo de acceso público', fn () => $this->client->putPublicAccessBlock([
                'Bucket' => $bucket,
                'PublicAccessBlockConfiguration' => [
                    'BlockPublicAcls' => true,
                    'IgnorePublicAcls' => true,
                    'BlockPublicPolicy' => true,
                    'RestrictPublicBuckets' => true,
                ],
            ]));

            if ($this->config->string('s3.endpoint') === '') {
                $this->step($io, 'Cifrado en reposo (SSE-S3)', fn () => $this->client->putBucketEncryption([
                    'Bucket' => $bucket,
                    'ServerSideEncryptionConfiguration' => [
                        'Rules' => [['ApplyServerSideEncryptionByDefault' => ['SSEAlgorithm' => 'AES256']]],
                    ],
                ]));
            } else {
                // En almacenamientos compatibles (MinIO, SeaweedFS...) el cifrado por defecto requiere
                // configuración propia del servidor; forzarlo puede impedir escribir.
                $io->writeln('• Cifrado por defecto omitido (endpoint compatible con S3, no AWS).');
            }

            $this->step($io, 'CORS para ' . implode(', ', $origins), fn () => $this->client->putBucketCors([
                'Bucket' => $bucket,
                'CORSConfiguration' => [
                    'CORSRules' => [[
                        'AllowedOrigins' => $origins,
                        'AllowedMethods' => ['PUT', 'GET', 'HEAD'],
                        'AllowedHeaders' => ['*'],
                        'ExposeHeaders' => ['ETag', 'Content-Length', 'Content-Range'],
                        'MaxAgeSeconds' => 3600,
                    ]],
                ],
            ]));

            $this->step($io, 'Ciclo de vida: abortar multiparts incompletos a los 2 días', fn () => $this->client->putBucketLifecycleConfiguration([
                'Bucket' => $bucket,
                'LifecycleConfiguration' => [
                    'Rules' => [[
                        'ID' => 'abort-incomplete-multipart',
                        'Status' => 'Enabled',
                        'Filter' => ['Prefix' => $this->config->string('s3.prefix') !== '' ? $this->config->string('s3.prefix') . '/' : ''],
                        'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 2],
                    ]],
                ],
            ]));
        } catch (AwsException $e) {
            $io->error(sprintf('%s: %s', $e->getAwsErrorCode() ?? 'Error', $e->getAwsErrorMessage() ?: $e->getMessage()));

            return Command::FAILURE;
        }

        return $this->check($io, $bucket);
    }

    private function step(SymfonyStyle $io, string $label, callable $fn): void
    {
        try {
            $fn();
            $io->writeln('✔ ' . $label);
        } catch (AwsException $e) {
            $io->warning(sprintf('%s: no se pudo aplicar (%s). Configúralo manualmente (ver README).', $label, $e->getAwsErrorCode() ?? $e->getMessage()));
        }
    }

    /**
     * Prueba de extremo a extremo: escribir, leer, borrar y listar.
     */
    private function check(SymfonyStyle $io, string $bucket): int
    {
        $prefix = $this->config->string('s3.prefix');
        $key = ($prefix !== '' ? $prefix . '/' : '') . '_healthcheck/' . bin2hex(random_bytes(6));
        $ok = true;
        $tests = [
            'PutObject' => fn () => $this->client->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'ok', 'ContentType' => 'text/plain']),
            'HeadObject' => fn () => $this->client->headObject(['Bucket' => $bucket, 'Key' => $key]),
            'GetObject' => fn () => $this->client->getObject(['Bucket' => $bucket, 'Key' => $key]),
            'ListMultipartUploads' => fn () => $this->client->listMultipartUploads(['Bucket' => $bucket, 'MaxUploads' => 1]),
            'DeleteObject' => fn () => $this->client->deleteObject(['Bucket' => $bucket, 'Key' => $key]),
        ];
        foreach ($tests as $name => $fn) {
            try {
                $fn();
                $io->writeln(sprintf('✔ %s', $name));
            } catch (AwsException $e) {
                $ok = false;
                $io->writeln(sprintf('<error>✘ %s: %s</error>', $name, $e->getAwsErrorCode() ?? $e->getMessage()));
            }
        }
        try {
            $cors = $this->client->getBucketCors(['Bucket' => $bucket]);
            foreach ($cors['CORSRules'] ?? [] as $rule) {
                $io->writeln(sprintf('  CORS: %s → %s (expone: %s)', implode(', ', $rule['AllowedOrigins'] ?? []), implode(', ', $rule['AllowedMethods'] ?? []), implode(', ', $rule['ExposeHeaders'] ?? [])));
            }
        } catch (AwsException $e) {
            $io->writeln('<comment>  No se pudo leer la configuración CORS (' . ($e->getAwsErrorCode() ?? 'error') . ').</comment>');
        }
        if ($ok) {
            $io->success('El almacenamiento funciona correctamente.');

            return Command::SUCCESS;
        }
        $io->error('Hay operaciones que fallaron: revisa la política IAM (ver README).');

        return Command::FAILURE;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\FileRepository;
use App\Repositories\RateLimitRepository;
use App\Repositories\Repository;
use App\Repositories\TokenRepository;
use App\Repositories\UploadSessionRepository;
use App\Services\S3Service;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Limpieza periódica (cron cada hora):
 *  - aborta multiparts con más de 24 h,
 *  - elimina registros "uploading" abandonados y "failed" huérfanos (y su objeto en S3),
 *  - purga tokens expirados y contadores de rate limiting.
 */
#[AsCommand(name: 'uploads:cleanup', description: 'Aborta subidas abandonadas y limpia registros huérfanos.')]
final class UploadsCleanupCommand extends Command
{
    public function __construct(
        private readonly UploadSessionRepository $sessions,
        private readonly FileRepository $files,
        private readonly TokenRepository $tokens,
        private readonly RateLimitRepository $rateLimits,
        private readonly S3Service $s3,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Antigüedad mínima de una subida para considerarla abandonada', '24');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hours = max(1, (int) $input->getOption('hours'));
        $cutoff = Repository::now(sprintf('-%d hours', $hours));

        $aborted = 0;
        foreach ($this->sessions->staleActive($cutoff) as $session) {
            if ($session['s3_upload_id']) {
                $this->s3->abortMultipart((string) $session['s3_key'], (string) $session['s3_upload_id']);
            }
            $this->sessions->setStatus((int) $session['id'], 'aborted');
            $this->files->update((int) $session['file_id'], ['status' => 'failed']);
            $aborted++;
        }

        $removed = 0;
        foreach ($this->files->staleUploads($cutoff, Repository::now('-1 hour')) as $file) {
            if ((int) $file['active_sessions'] > 0) {
                continue;
            }
            $this->s3->delete((string) $file['s3_key']);
            $this->files->hardDelete((int) $file['id']);
            $removed++;
        }

        $tokens = $this->tokens->purgeExpired();
        $limits = $this->rateLimits->purgeExpired();

        $io->success(sprintf(
            'Multiparts abortados: %d · Registros huérfanos eliminados: %d · Tokens purgados: %d · Contadores purgados: %d',
            $aborted,
            $removed,
            $tokens,
            $limits,
        ));

        return Command::SUCCESS;
    }
}

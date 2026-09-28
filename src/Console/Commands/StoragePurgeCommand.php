<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\FileRepository;
use App\Repositories\Repository;
use App\Services\S3Service;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Elimina definitivamente de S3 los archivos borrados hace más de N días (y sus versiones anteriores).
 * El borrado desde la interfaz es lógico: permite recuperar datos durante ese periodo.
 */
#[AsCommand(name: 'storage:purge', description: 'Borra definitivamente de S3 los archivos eliminados hace más de N días.')]
final class StoragePurgeCommand extends Command
{
    public function __construct(
        private readonly FileRepository $files,
        private readonly S3Service $s3,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Días desde el borrado', '30')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Solo mostrar lo que se borraría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(1, (int) $input->getOption('days'));
        $dry = (bool) $input->getOption('dry-run');
        $total = 0;
        do {
            $batch = $this->files->purgeCandidates(Repository::now(sprintf('-%d days', $days)));
            foreach ($batch as $file) {
                // Incluye la cadena de versiones anteriores (más reciente primero).
                $chain = $this->files->versions((int) $file['id']);
                foreach ($chain as $version) {
                    $io->writeln(sprintf(' - %s v%d (%s)', $version['name'], $version['version'], $version['s3_key']), OutputInterface::VERBOSITY_VERBOSE);
                    if (!$dry) {
                        $this->s3->delete((string) $version['s3_key']);
                        $this->files->hardDelete((int) $version['id']);
                    }
                }
                $total++;
            }
        } while (!$dry && count($batch) > 0);

        $io->success(sprintf('%s %d archivos eliminados hace más de %d días.', $dry ? 'Se purgarían' : 'Purgados', $total, $days));

        return Command::SUCCESS;
    }
}

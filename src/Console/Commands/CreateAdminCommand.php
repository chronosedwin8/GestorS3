<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\AppException;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'user:create-admin', description: 'Crea un usuario administrador (o promueve uno existente).')]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Correo del administrador')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nombre completo')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Contraseña (mínimo 8 caracteres)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) ($input->getOption('email') ?: $io->ask('Correo'));
        $existing = $this->users->findByEmail($email);
        if ($existing !== null) {
            $this->users->update((int) $existing['id'], ['role' => 'admin', 'status' => 'active']);
            $io->success(sprintf('%s ya existía y ahora es administrador.', $email));

            return Command::SUCCESS;
        }
        $name = (string) ($input->getOption('name') ?: $io->ask('Nombre', 'Administrador'));
        $password = (string) ($input->getOption('password') ?: $io->askHidden('Contraseña (mínimo 8 caracteres)'));
        try {
            $user = $this->auth->createUser($email, $name, $password, 'admin');
        } catch (AppException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(sprintf('Administrador creado: %s <%s>', $user['name'], $user['email']));

        return Command::SUCCESS;
    }
}

<?php

namespace NickFairchild\Installer\Console;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class NewCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('new')
            ->setDescription('Create a new kynda WordPress application')
            ->addArgument('name', InputArgument::REQUIRED)
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Installs the latest "development" release')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forces install even if the directory already exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        sleep(1);

        $name = $input->getArgument('name');

        $directory = $name !== '.' ? getcwd().'/'.$name : '.';

        $version = $this->getVersion($input);

        if (! $input->getOption('force')) {
            $this->verifyApplicationDoesntExist($directory);
        }

        if ($input->getOption('force') && $directory === '.') {
            throw new RuntimeException('Cannot use --force option when using current directory for installation!');
        }

        $composer = $this->findComposer();

        $commands = [
            $composer." create-project nickfairchild/kynda \"$directory\" $version --remove-vcs --prefer-dist",
        ];

        if ($directory != '.' && $input->getOption('force')) {
            if (PHP_OS_FAMILY == 'Windows') {
                array_unshift($commands, "rd /s /q \"$directory\"");
            } else {
                array_unshift($commands, "rm -rf \"$directory\"");
            }
        }

        if (($process = $this->runCommands($commands, $input, $output))->isSuccessful()) {
            if ($name !== '.') {
                $this->replaceInFile(
                    'DB_NAME=database_name',
                    'DB_NAME='.str_replace('-', '_', strtolower($name)),
                    $directory.'/.env'
                );

                $this->replaceInFile(
                    'WP_HOME=http://localhost',
                    'WP_HOME=http://'.$name.'.test',
                    $directory.'/.env'
                );

                $this->replaceInFile(
                    'DB_NAME=database_name',
                    'DB_NAME='.str_replace('-', '_', strtolower($name)),
                    $directory.'/.env.example'
                );

                $this->installWordpress($input, $output);

                $this->setupTheme($name, $input, $output);
            }

            $output->writeln(PHP_EOL.'<comment>Application ready!</comment>');
        }

        return $process->getExitCode();
    }

    protected function installWordpress(InputInterface $input, OutputInterface $output): void
    {
        $helper = $this->getHelper('question');
    }

    protected function setupTheme(string $directory, InputInterface $input, OutputInterface $output): void
    {
        chdir($directory);

        $commands = array_filter([
            "mv \"public/wp-content/themes/website\" \"public/wp-content/themes/$directory\"",
            "cd \"public/wp-content/themes/$directory\"",
            $this->findComposer().' install',
            'yarn && yarn dev'
        ]);

        $this->runCommands($commands, $input, $output);
    }

    protected function verifyApplicationDoesntExist(string $directory): void
    {
        if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
            throw new RuntimeException('Application already exists!');
        }
    }

    protected function getVersion(InputInterface $input): string
    {
        if ($input->getOption('dev')) {
            return 'dev-master';
        }

        return '';
    }

    protected function findComposer(): string
    {
        $composerPath = getcwd().'/composer.phar';

        if (file_exists($composerPath)) {
            return '"'.PHP_BINARY.'" '.$composerPath;
        }

        return 'composer';
    }

    protected function runCommands(array $commands, InputInterface $input, OutputInterface $output): Process
    {
        if ($input->getOption('no-ansi')) {
            $commands = array_map(function ($value) {
                if (substr($value, 0, 5) === 'chmod') {
                    return $value;
                }

                return $value.' --no-ansi';
            }, $commands);
        }

        if ($input->getOption('quiet')) {
            $commands = array_map(function ($value) {
                if (substr($value, 0, 5) === 'chmod') {
                    return $value;
                }

                return $value.' --quiet';
            }, $commands);
        }

        $process = Process::fromShellCommandline(implode(' && ', $commands), null, null, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                $output->writeln('Warning: '.$e->getMessage());
            }
        }

        $process->run(function ($type, $line) use ($output) {
            $output->writeln('     '.$line);
        });

        return $process;
    }

    protected function replaceInFile(string $search, string $replace, string $file): string
    {
        file_put_contents(
            $file,
            str_replace($search, $replace, file_get_contents($file))
        );
    }
}

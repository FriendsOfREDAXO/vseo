<?php

declare(strict_types=1);

namespace Viewsion\SeoMonitor\Command;

use rex_console_command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Viewsion\SeoMonitor\Alert\Mailer;
use Viewsion\SeoMonitor\Alert\Rules;

final class AlertsCommand extends rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setDescription('vseo: check alert rules, send immediate mails and the weekly digest')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Domain ID or host')
            ->addOption('no-mail', null, InputOption::VALUE_NONE, 'Only create alerts, send no mails')
            ->addOption('digest', null, InputOption::VALUE_NONE, 'Send the weekly digest now');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        $domainId = DomainOption::resolve($input->getOption('domain'));
        if (false === $domainId) {
            $io->error('Unknown domain.');
            return 1;
        }
        $rules = new Rules();
        $ok = $rules->run($domainId, !$input->getOption('no-mail'));
        foreach ($rules->messages() as $message) {
            $io->writeln($message);
        }
        if ($input->getOption('digest')) {
            foreach ((new Mailer())->sendDigestIfDue(true) as $message) {
                $io->writeln($message);
            }
        }
        return $ok ? 0 : 1;
    }
}

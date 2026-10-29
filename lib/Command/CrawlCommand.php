<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\VSeo\Command;

use rex_console_command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use FriendsOfRedaxo\VSeo\Crawler\Crawler;
use FriendsOfRedaxo\VSeo\Util\Budget;

final class CrawlCommand extends rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setDescription('vseo: check robots.txt, sitemaps and the next batch of URLs')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Domain ID or host')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'URLs per domain (default: setting)')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Reload robots.txt and sitemaps now')
            ->addOption('budget', null, InputOption::VALUE_REQUIRED, 'Time budget in seconds (0 = unlimited)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        $domainId = DomainOption::resolve($input->getOption('domain'));
        if (false === $domainId) {
            $io->error('Unknown domain.');
            return 1;
        }
        $limit = $input->getOption('limit');
        $crawler = new Crawler();
        $ok = $crawler->run(
            new Budget((int) $input->getOption('budget')),
            $domainId,
            is_numeric($limit) ? max(1, (int) $limit) : null,
            (bool) $input->getOption('refresh'),
        );
        foreach ($crawler->messages() as $message) {
            $io->writeln($message);
        }
        return $ok ? 0 : 1;
    }
}

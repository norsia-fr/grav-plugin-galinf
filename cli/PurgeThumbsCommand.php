<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\Galinf\ReindexService;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes orphan thumbnails from `galinf-thumbs/` (images removed from the
 * index). Thumbnails are persistent, so deleting images leaves orphan files
 * behind; this command cleans them up.
 *
 *   bin/plugin galinf purge-thumbs
 */
class PurgeThumbsCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('purge-thumbs')
            ->setDescription('Delete orphan thumbnails from galinf-thumbs/');
    }

    protected function serve(): int
    {
        require_once __DIR__ . '/../vendor/autoload.php';

        $io = new SymfonyStyle($this->input, $this->output);
        $this->initializePlugins();

        $io->writeln('<info>Purging orphan thumbnails…</info>');

        // Progress bar per thumbnail folder (created on the first batch, once
        // the total is known).
        $bar = null;
        $stats = ReindexService::purgeOrphanThumbs(function (array $batch) use ($io, &$bar): void {
            if ($bar === null) {
                $bar = $io->createProgressBar(max(1, $batch['total']));
                $bar->setFormat(" %current%/%max% folders [%bar%] %percent:3s%%  %elapsed:6s%/%estimated:-6s%\n   %message%");
                $bar->setMessage('');
                $bar->start();
            }
            $bar->setMessage(sprintf('%d orphan(s) deleted, %s freed', $batch['deleted'], self::humanSize($batch['freed'])));
            $bar->setProgress(min($batch['processed'], $bar->getMaxSteps()));
        });
        if ($bar !== null) {
            $bar->finish();
            $io->newLine(2);
        }

        $io->success(sprintf(
            '%d orphan(s) deleted, %d kept.',
            $stats['deleted'],
            $stats['kept']
        ));
        $io->writeln(sprintf('Space freed: <yellow>%s</yellow>.', self::humanSize($stats['freed'])));

        return 0;
    }

    public static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < \count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return sprintf('%.1f %s', $n, $units[$i]);
    }
}

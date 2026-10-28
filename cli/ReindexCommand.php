<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\Galinf\Indexer;
use Grav\Plugin\Galinf\ReindexService;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Full gallery reindex from the CLI.
 *
 *   bin/plugin galinf reindex                 # updates the index
 *   bin/plugin galinf reindex --clear         # starts from an empty database
 *   bin/plugin galinf reindex --no-thumbs     # index only, no thumbnail created
 *   bin/plugin galinf reindex --force-thumbs  # recreates every thumbnail
 *
 * Required on first deployment and after a bulk import of articles (edits
 * made outside the admin do not fire any save event).
 */
class ReindexCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('reindex')
            ->addOption('clear', 'c', InputOption::VALUE_NONE, 'Empty the index before rebuilding everything')
            ->addOption('no-thumbs', null, InputOption::VALUE_NONE, 'Update the index without creating any thumbnail')
            ->addOption('force-thumbs', null, InputOption::VALUE_NONE, 'Recreate every thumbnail, even existing ones')
            ->setDescription('Rebuild the gallery SQLite index from every article');
    }

    protected function serve(): int
    {
        require_once __DIR__ . '/../vendor/autoload.php';

        $io = new SymfonyStyle($this->input, $this->output);

        $this->initializePlugins();

        $clear = (bool) $this->input->getOption('clear');
        if ($clear) {
            $io->writeln('<comment>Emptying the existing index…</comment>');
        }
        $io->writeln('<info>Indexing articles…</info>');

        // Per-article progress bar (created on the first callback, once the
        // total is known). Shows X/Y, %, elapsed/estimated time, and the
        // current article + the running image count.
        $bar = null;
        $progress = function (int $done, int $total, string $route, int $n, int $imagesTotal) use ($io, &$bar): void {
            if ($bar === null) {
                $bar = $io->createProgressBar($total);
                $bar->setFormat(" %current%/%max% articles [%bar%] %percent:3s%%  %elapsed:6s%/%estimated:-6s%\n   %message%");
                $bar->setMessage('');
                $bar->start();
            }
            $bar->setMessage(sprintf('%s — %d image(s)  ·  %d images in total', $route, $n, $imagesTotal));
            $bar->advance();
        };

        // Same service as the admin's Maintenance tab.
        $thumbs = Indexer::THUMBS_MISSING;
        if ($this->input->getOption('force-thumbs')) {
            $thumbs = Indexer::THUMBS_FORCE;
        } elseif ($this->input->getOption('no-thumbs')) {
            $thumbs = Indexer::THUMBS_NONE;
        }
        $stats = ReindexService::run($clear, $progress, $thumbs);

        if ($bar !== null) {
            $bar->finish();
        }
        $io->newLine(2);

        $io->success(sprintf('%d article(s), %d image(s) indexed.', $stats['articles'], $stats['images']));
        $io->writeln(sprintf('Total in the index: <yellow>%d</yellow> image(s).', $stats['total']));
        $io->writeln(sprintf('Thumbnails created: <yellow>%d</yellow>.', $stats['thumbs_created']));
        if ($stats['pruned'] > 0) {
            $io->writeln(sprintf('Vanished articles removed from the index: <yellow>%d</yellow>.', $stats['pruned']));
        }

        $missing = $stats['missing_thumbs'];
        if ($missing['count'] > 0) {
            $io->warning(sprintf(
                '%d image(s) without a thumbnail: the gallery serves the original instead (%d of them beyond the %s MP limit, system.images.max_pixels).',
                $missing['count'],
                $missing['over_limit'],
                rtrim(rtrim(number_format($missing['max_pixels'] / 1000000, 1, '.', ''), '0'), '.')
            ));
            foreach ($missing['items'] as $item) {
                $io->writeln(sprintf(
                    '   %s — %d × %d (%s MP)%s',
                    $item['image_path'],
                    $item['width'],
                    $item['height'],
                    number_format($item['width'] * $item['height'] / 1000000, 1, '.', ''),
                    $item['over_limit'] ? '' : ' — thumbnail generation failed'
                ));
            }
            if ($missing['count'] > \count($missing['items'])) {
                $io->writeln(sprintf('   … and %d more.', $missing['count'] - \count($missing['items'])));
            }
        }

        return 0;
    }
}

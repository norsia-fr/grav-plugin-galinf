<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\Galinf\ReindexService;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shrinks in place the images too large for Grav to resize (beyond
 * `system.images.max_pixels`), left without a thumbnail. The originals are
 * REPLACED and their EXIF data is lost.
 *
 *   bin/plugin galinf shrink-oversized [--yes]
 */
class ShrinkOversizedCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('shrink-oversized')
            ->setDescription('Shrink in place the images beyond system.images.max_pixels (originals replaced)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
    }

    protected function serve(): int
    {
        require_once __DIR__ . '/../vendor/autoload.php';

        $io = new SymfonyStyle($this->input, $this->output);
        $this->initializePlugins();

        $missing = ReindexService::missingThumbsList(100000);
        $targets = array_values(array_filter($missing['items'], static fn (array $i): bool => $i['over_limit']));

        if (!$targets) {
            $io->success('No image beyond the limit.');
            return 0;
        }

        foreach ($targets as $item) {
            $io->writeln(sprintf('   %s — %d × %d → %d × %d', $item['image_path'], $item['width'], $item['height'], $item['target_width'], $item['target_height']));
        }
        $io->warning(sprintf(
            '%d image(s) will be shrunk to just under %s MP. The ORIGINALS ARE REPLACED and their EXIF data is lost.',
            \count($targets),
            number_format($missing['max_pixels'] / 1000000, 1, '.', '')
        ));
        if (!$this->input->getOption('yes') && !$io->confirm('Continue?', false)) {
            return 0;
        }

        $io->writeln('<info>Shrinking…</info>');
        $results = ReindexService::shrinkOversized(
            array_column($targets, 'image_path'),
            static function (string $path, array $r) use ($io): void {
                $io->writeln($r['ok']
                    ? sprintf('<info>✓</info> %s — %d × %d → %d × %d', $path, $r['old_width'], $r['old_height'], $r['width'], $r['height'])
                    : sprintf('<error>✗</error> %s — %s', $path, $r['error']));
            }
        );

        $done = 0;
        foreach ($results as $path => $r) {
            if (!$r['ok']) {
                continue;
            }
            $done++;
            if (!$r['thumb']) {
                $io->writeln(sprintf('<comment>!</comment> %s — shrunk, but its thumbnail is still missing', $path));
            }
        }

        $io->success(sprintf('%d of %d image(s) shrunk.', $done, \count($targets)));

        return $done === \count($targets) ? 0 : 1;
    }
}

<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\Galinf\ReindexService;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Draws a new precomputed shuffle for the gallery's "random" order (used when
 * the "Random order" option is set to "precomputed").
 *
 *   bin/plugin galinf shuffle
 */
class ShuffleCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('shuffle')
            ->setDescription('Draw a new precomputed shuffle for the "random" order');
    }

    protected function serve(): int
    {
        require_once __DIR__ . '/../vendor/autoload.php';

        $io = new SymfonyStyle($this->input, $this->output);
        $this->initializePlugins();

        $result = ReindexService::shuffle();
        $io->success(sprintf('%d image(s) shuffled.', $result['images']));

        return 0;
    }
}

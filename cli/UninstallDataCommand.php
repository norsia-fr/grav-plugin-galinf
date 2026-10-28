<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\Galinf\ReindexService;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes the data created by the plugin, to run just before uninstalling
 * it: SQLite index and thumbnails (rebuildable), and, on explicit request,
 * the visitor comments (irreplaceable).
 *
 *   bin/plugin galinf uninstall-data [--comments] [--yes]
 */
class UninstallDataCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('uninstall-data')
            ->setDescription('Delete the plugin index and thumbnails (before uninstalling)')
            ->addOption('comments', null, InputOption::VALUE_NONE, 'ALSO delete the visitor comments (irreversible)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
    }

    protected function serve(): int
    {
        require_once __DIR__ . '/../vendor/autoload.php';

        $io = new SymfonyStyle($this->input, $this->output);
        $this->initializePlugins();

        $comments = (bool) $this->input->getOption('comments');
        $io->warning(
            'The gallery index and all thumbnails will be deleted.'
            . ($comments
                ? ' So will the visitor COMMENTS: they cannot be recovered.'
                : ' Visitor comments are kept.')
        );
        if (!$this->input->getOption('yes') && !$io->confirm('Continue?', false)) {
            return 0;
        }

        $r = ReindexService::removeData($comments);

        $io->success(sprintf(
            'Index %s, %d thumbnail(s) deleted%s. %s freed.',
            $r['index'] ? 'deleted' : 'already absent',
            $r['thumbs'],
            $r['comments'] === null ? '' : sprintf(', %d comment(s) deleted', $r['comments']),
            PurgeThumbsCommand::humanSize($r['freed'])
        ));
        $io->writeln('You can now uninstall the plugin. While it stays active, a visit to the gallery recreates an empty database.');

        return 0;
    }
}

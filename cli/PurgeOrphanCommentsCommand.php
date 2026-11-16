<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\Galinf\Comments;
use Grav\Plugin\Galinf\ReindexService;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes the orphan comments: those whose page no longer exists, and those
 * whose image is no longer in its page. Nothing deletes them on its own (a
 * deletion can be undone, a page or an image may only have been renamed…):
 * they are shown nowhere, and this command — or the Maintenance tab —
 * removes them for good.
 *
 *   bin/plugin galinf purge-orphan-comments [--yes]
 */
class PurgeOrphanCommentsCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('purge-orphan-comments')
            ->setDescription('Delete the comments whose page or image no longer exists')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
    }

    protected function serve(): int
    {
        require_once __DIR__ . '/../vendor/autoload.php';

        $io = new SymfonyStyle($this->input, $this->output);
        $this->initializePlugins();

        $counts = Comments::orphanCounts();
        if ($counts['pages'] + $counts['images'] === 0) {
            $io->success('No orphan comment: every comment has its page and its image.');
            return 0;
        }

        $io->warning(sprintf(
            '%d comment(s) of vanished pages and %d comment(s) of vanished images will be deleted. They cannot be recovered. '
            . 'If a page or an image was only renamed, fix these comments in the admin list first (Status column).',
            $counts['pages'],
            $counts['images']
        ));
        if (!$this->input->getOption('yes') && !$io->confirm('Continue?', false)) {
            return 0;
        }

        $result = ReindexService::purgeOrphanComments();
        $io->success(sprintf('%d comment(s) deleted (%d of vanished pages, %d of vanished images).', $result['deleted'], $result['pages'], $result['images']));

        return 0;
    }
}

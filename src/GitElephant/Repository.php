<?php

/*
 * This file is part of the GitElephant package.
 *
 * (c) Matteo Giachino <matteog@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Just for fun...
 */

namespace GitElephant;

use GitElephant\GitBinary;
use GitElephant\Command\Caller;
use GitElephant\Objects\Tree,
    GitElephant\Objects\TreeBranch,
    GitElephant\Objects\TreeTag,
    GitElephant\Objects\Diff\Diff,
    GitElephant\Objects\Commit,
    GitElephant\Objects\Log;
use GitElephant\Command\MainCommand,
    GitElephant\Command\BranchCommand,
    GitElephant\Command\TagCommand,
    GitElephant\Command\LsTreeCommand,
    GitElephant\Command\DiffCommand,
    GitElephant\Command\ShowCommand,
    GitElephant\Command\LogCommand;
use GitElephant\Utilities;

/**
 * Repository
 *
 * Base Class for repository operations
 *
 * @author Matteo Giachino <matteog@gmail.com>
 */

class Repository
{
    private $path;
    private $caller;

    private $mainCommand;
    private $branchCommand;
    private $tagCommand;
    private $lsTreeCommand;
    private $diffCommand;
    private $showCommand;
    private $logCommand;

    public function __construct($repository_path, GitBinary $binary = null)
    {
        if ($binary == null) {
            $binary = new GitBinary();
        }
        if (!is_dir($repository_path)) {
            throw new \InvalidArgumentException(sprintf('the path "%s" is not a repository folder', $repository_path));
        }
        $this->path = $repository_path;
        $this->caller = new Caller($binary, $repository_path);

        // command objects
        $this->mainCommand = new MainCommand();
        $this->branchCommand = new BranchCommand();
        $this->tagCommand = new TagCommand();
        $this->lsTreeCommand = new LsTreeCommand();
        $this->diffCommand = new DiffCommand();
        $this->showCommand = new ShowCommand();
        $this->logCommand = new LogCommand();
    }
    
    /**
     * Init the repository
     * @return void
     */
    public function init()
    {
        $this->caller->execute($this->mainCommand->init());
    }

    /**
     * Stage the working tree content
     * 
     * @param string $path the path to store
     * @return void
     */
    public function stage($path = '.')
    {
        $this->caller->execute($this->mainCommand->add($path));
    }

    /**
     * Commit content to the repository, eventually staging all unstaged content
     *
     * @param $message The commit message
     * @param bool $stageAll whether stage or not content before the commit
     * @return void
     */
    public function commit($message, $stageAll = false, $ref = null)
    {
        if ($ref != null) {
            $currentBranch = $this->getMainBranch();
            $this->checkout($ref);
        }
        if ($stageAll) $this->stage();
        $this->caller->execute($this->mainCommand->commit($message));
        if ($ref != null) {
            $this->checkout($currentBranch);
        }
    }

    /**
     * @return array output lines
     */
    public function getStatus()
    {
        $this->caller->execute($this->mainCommand->status());
        return array_map('trim', $this->caller->getOutputLines());
    }

    public function createBranch($name, $startPoint = null)
    {
        $this->caller->execute($this->branchCommand->create($name, $startPoint));
    }

    /**
     * Delete a branch by his name
     * This function change the state of the repository on the filesystem
     *
     * @param $name
     */
    public function deleteBranch($name)
    {
        $this->caller->execute($this->branchCommand->delete($name));
    }

    /**
     * An array of TreeBranch objects
     *
     * @return array
     */
    public function getBranches()
    {
        $branches = array();
        $this->caller->execute($this->branchCommand->lists());
        foreach($this->caller->getOutputLines() as $branchString) {
            $branches[] = new TreeBranch($branchString);
        }
        usort($branches, array($this, 'sortBranches'));
        return $branches;
    }

    /**
     * return the actually checked out branch
     *
     * @return GitElephant\Objects\TreeBranch
     */
    public function getMainBranch()
    {
        $filtered = array_filter($this->getBranches(), function(TreeBranch $branch) {
            return $branch->getCurrent();
        });
        sort($filtered);
        return $filtered[0];
    }

    /**
     * Retrieve a TreeBranch object by a branch name
     *
     * @param string $name
     * @return null|TreeBranch
     */
    public function getBranch($name)
    {
        foreach ($this->getBranches() as $treeBranch) {
            if ($treeBranch->getName() == $name) {
                return $treeBranch;
            }
        }
        return null;
    }

    public function createTag($name, $startPoint = null, $message = null)
    {
        $this->caller->execute($this->tagCommand->create($name, $startPoint, $message));
    }

    /**
     * Delete a tag by it's name or by passing a TreeTag object
     * This function change the state of the repository on the filesystem
     *
     * @param string|TreeTag $tag
     */
    public function deleteTag($tag)
    {
        $this->caller->execute($this->tagCommand->delete($tag));
    }

    /**
     * Gets an array of TreeTag objects
     *
     * @return array An array of TreeTag objects
     */
    public function getTags()
    {
        $tags = array();
        $this->caller->execute($this->tagCommand->lists());
        foreach($this->caller->getOutputLines() as $tagString) {
            $tags[] = new TreeTag($tagString);
        }
        return $tags;
    }

    /**
     * Return a tag object
     *
     * @param $name the tag name
     * @return GitElephant\Objects\TreeTag
     */
    public function getTag($name)
    {
        foreach ($this->getTags() as $treeTag) {
            if ($treeTag->getName() == $name) {
                return $treeTag;
            }
        }
        return null;
    }

    /**
     * Return a Commit object
     *
     * @param string $ref
     * @return Objects\Commit
     */
    public function getCommit($ref = 'HEAD')
    {
        $command = $this->showCommand->showCommit($ref);
        return new Commit($this->caller->execute($command)->getOutputLines());
    }

    public function getLog($obj, $branch = null)
    {
        $command = $this->logCommand->showLog($obj, $branch);
        //var_dump($command);
        return new Log($this->caller->execute($command)->getOutputLines());
    }

    /**
     * Checkout a branch
     * This function change the state of the repository on the filesystem
     *
     * @param string|TreeBranch $ref the ref to checkout
     */
    public function checkout($ref)
    {
        $this->caller->execute($this->mainCommand->checkout($ref));
    }

    /**
     * Retrieve an instance of Tree
     * Tree Object is Countable, Iterable and has ArrayAccess for easy manipulation
     *
     * @param string $path the physical path to the tree relative to the repository root
     * @param string|null $ref the treeish to check
     * @return GitElephant\Objects\Tree
     */
    public function getTree($ref = 'HEAD', $path = '')
    {
        $outputLines = $this->caller->execute($this->lsTreeCommand->tree($ref))->getOutputLines();
        return new Tree($outputLines, $path);
    }

    public function getCommitDiff(Commit $commit, $path = null) {
        $command = $this->diffCommand->commitDiff($commit, $path);
        $outputLines = $this->caller->execute($command)->getOutputLines();
        return new Diff($outputLines);
    }


    private function sortBranches(TreeBranch $a, TreeBranch $b) {
        if ($a->getName() == 'master') {
            return -1;
        } else if ($b->getName() == 'master') {
            return 1;
        } else {
            return 0;
        }
    }

    public function getPath()
    {
        return $this->path;
    }
}

<?php

namespace Rudel\Tests\Unit;

use Pitmaster\Pitmaster;
use Rudel\GitIntegration;
use Rudel\Tests\RudelTestCase;

class GitIntegrationTest extends RudelTestCase
{
    private GitIntegration $git;

    protected function setUp(): void
    {
        parent::setUp();
        $this->git = new GitIntegration();
    }

    private function createGitRepo(string $name, array $files = ['file.txt' => 'initial']): string
    {
        $path = $this->tmpDir . '/' . $name;
        mkdir($path, 0755, true);

        $repo = Pitmaster::init($path);
        $repo->config()->set('user.email', 'test@test.com');
        $repo->config()->set('user.name', 'Test');

        foreach ($files as $relative => $contents) {
            $fullPath = $path . '/' . $relative;
            $dir = dirname($fullPath);

            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($fullPath, $contents);
            $repo->add($relative);
        }

        $repo->commit('init');

        return $path;
    }

    public function testIsGitRepoReturnsTrueForGitDir(): void
    {
        $repo = $this->createGitRepo('test-repo');
        $this->assertTrue($this->git->is_git_repo($repo));
    }

    public function testIsGitRepoReturnsFalseForPlainDir(): void
    {
        $dir = $this->tmpDir . '/plain-dir';
        mkdir($dir, 0755, true);
        $this->assertFalse($this->git->is_git_repo($dir));
    }

    public function testCreateWorktreeCreatesDirectoryWithBranch(): void
    {
        $repo = $this->createGitRepo('wt-repo');
        $target = $this->tmpDir . '/wt-target';

        $result = $this->git->create_worktree($repo, $target, 'rudel/test-sandbox');

        $this->assertTrue($result);
        $this->assertDirectoryExists($target);
        $this->assertFileExists($target . '/file.txt');
        $this->assertFileExists($target . '/.git');
        $this->assertSame('rudel/test-sandbox', Pitmaster::open($target)->branch());
    }

    public function testRemoveWorktreeRemovesDirectory(): void
    {
        $repo = $this->createGitRepo('rm-wt-repo');
        $target = $this->tmpDir . '/rm-wt-target';

        $this->git->create_worktree($repo, $target, 'rudel/rm-test');
        $this->assertDirectoryExists($target);

        $result = $this->git->remove_worktree($repo, $target);
        $this->assertTrue($result);
        $this->assertDirectoryDoesNotExist($target);
    }

    public function testRemoveWorktreeAcceptsCommonGitDir(): void
    {
        $repo = $this->createGitRepo('rm-common-repo');
        $target = $this->tmpDir . '/rm-common-target';

        $this->git->create_worktree($repo, $target, 'rudel/rm-common');
        $common = $this->git->common_git_dir($target);

        $this->assertIsString($common);
        $this->assertTrue($this->git->remove_worktree($common, $target));
        $this->assertDirectoryDoesNotExist($target);
    }

    public function testIsBranchMergedReturnsTrueAfterMerge(): void
    {
        $repoPath = $this->createGitRepo('merge-repo');
        $repo = Pitmaster::open($repoPath);

        $repo->createBranch('rudel/feature');
        $repo->checkout('rudel/feature');
        file_put_contents($repoPath . '/feature.txt', 'feature');
        $repo->add('feature.txt');
        $repo->commit('feature');
        $repo->checkout('main');
        $repo->merge('rudel/feature');

        $this->assertTrue($this->git->is_branch_merged($repoPath, 'rudel/feature', 'main'));
    }

    public function testIsBranchMergedReturnsFalseWhenNotMerged(): void
    {
        $repoPath = $this->createGitRepo('unmerged-repo');
        $repo = Pitmaster::open($repoPath);

        $repo->createBranch('rudel/unmerged');
        $repo->checkout('rudel/unmerged');
        file_put_contents($repoPath . '/unmerged.txt', 'work');
        $repo->add('unmerged.txt');
        $repo->commit('wip');
        $repo->checkout('main');

        $this->assertFalse($this->git->is_branch_merged($repoPath, 'rudel/unmerged', 'main'));
    }

    public function testDeleteBranchRemovesMergedBranch(): void
    {
        $repoPath = $this->createGitRepo('del-branch-repo');
        $repo = Pitmaster::open($repoPath);

        $repo->createBranch('rudel/to-delete');
        $repo->checkout('rudel/to-delete');
        file_put_contents($repoPath . '/remove.txt', 'remove');
        $repo->add('remove.txt');
        $repo->commit('remove');
        $repo->checkout('main');
        $repo->merge('rudel/to-delete');

        $result = $this->git->delete_branch($repoPath, 'rudel/to-delete');

        $this->assertTrue($result);
        $this->assertNull(Pitmaster::open($repoPath)->branch('rudel/to-delete'));
    }

    public function testDeleteBranchAcceptsCommonGitDirAfterWorktreeRemoval(): void
    {
        $repo = $this->createGitRepo('del-common-repo');
        $target = $this->tmpDir . '/del-common-target';

        $this->git->create_worktree($repo, $target, 'rudel/del-common');
        $common = $this->git->common_git_dir($target);
        $this->assertIsString($common);

        $this->assertTrue($this->git->remove_worktree($common, $target));
        $this->assertTrue($this->git->delete_branch($common, 'rudel/del-common'));
    }

    public function testGetDefaultBranchReturnsMain(): void
    {
        $repo = $this->createGitRepo('default-branch-repo');
        $this->assertSame('main', $this->git->get_default_branch($repo));
    }

    public function testCloneWithWorktreesCreatesWorktreeForGitRepos(): void
    {
        $source = $this->tmpDir . '/themes-src';
        mkdir($source, 0755, true);

        $gitTheme = $source . '/git-theme';
        mkdir($gitTheme, 0755, true);
        $repo = Pitmaster::init($gitTheme);
        $repo->config()->set('user.email', 't@t.com');
        $repo->config()->set('user.name', 'T');
        file_put_contents($gitTheme . '/style.css', 'body {}');
        $repo->add('style.css');
        $repo->commit('init');

        $plainTheme = $source . '/plain-theme';
        mkdir($plainTheme, 0755, true);
        file_put_contents($plainTheme . '/style.css', 'body {}');

        $target = $this->tmpDir . '/themes-dst';
        mkdir($target, 0755, true);

        $results = $this->git->clone_with_worktrees($source, $target, 'my-sandbox');

        $this->assertArrayHasKey('git-theme', $results['worktrees']);
        $this->assertSame('rudel/my-sandbox', $results['worktrees']['git-theme']);
        $this->assertFileExists($target . '/git-theme/style.css');
        $this->assertFileExists($target . '/git-theme/.git');
        $this->assertContains('plain-theme', $results['copied']);
        $this->assertFileExists($target . '/plain-theme/style.css');
    }

    public function testCloneWithWorktreesHandlesEmptyDirectory(): void
    {
        $source = $this->tmpDir . '/empty-src';
        mkdir($source, 0755, true);

        $target = $this->tmpDir . '/empty-dst';
        mkdir($target, 0755, true);

        $results = $this->git->clone_with_worktrees($source, $target, 'test');

        $this->assertEmpty($results['worktrees']);
        $this->assertEmpty($results['copied']);
    }
}

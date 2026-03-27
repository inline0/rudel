<?php

namespace Rudel\Tests\Unit;

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

    private function hasGit(): bool
    {
        exec('git --version 2>&1', $output, $code);
        return 0 === $code;
    }

    private function createGitRepo(string $name): string
    {
        $path = $this->tmpDir . '/' . $name;
        mkdir($path, 0755, true);
        exec('git -C ' . escapeshellarg($path) . ' init 2>&1');
        exec('git -C ' . escapeshellarg($path) . ' config user.email "test@test.com" 2>&1');
        exec('git -C ' . escapeshellarg($path) . ' config user.name "Test" 2>&1');
        file_put_contents($path . '/file.txt', 'initial');
        exec('git -C ' . escapeshellarg($path) . ' add -A 2>&1');
        exec('git -C ' . escapeshellarg($path) . ' commit -m "init" 2>&1');
        return $path;
    }

    // is_git_repo()

    public function testIsGitRepoReturnsTrueForGitDir(): void
    {
        if (! $this->hasGit()) {
            $this->markTestSkipped('git not available');
        }

        $repo = $this->createGitRepo('test-repo');
        $this->assertTrue($this->git->is_git_repo($repo));
    }

    public function testIsGitRepoReturnsFalseForPlainDir(): void
    {
        $dir = $this->tmpDir . '/plain-dir';
        mkdir($dir, 0755, true);
        $this->assertFalse($this->git->is_git_repo($dir));
    }

    // create_worktree() / remove_worktree()

    public function testCreateWorktreeCreatesDirectoryWithBranch(): void
    {
        if (! $this->hasGit()) {
            $this->markTestSkipped('git not available');
        }

        $repo = $this->createGitRepo('wt-repo');
        $target = $this->tmpDir . '/wt-target';

        $result = $this->git->create_worktree($repo, $target, 'rudel/test-sandbox');

        $this->assertTrue($result);
        $this->assertDirectoryExists($target);
        $this->assertFileExists($target . '/file.txt');

        // Verify we're on the right branch.
        exec('git -C ' . escapeshellarg($target) . ' branch --show-current 2>&1', $output);
        $this->assertSame('rudel/test-sandbox', trim($output[0]));
    }

    public function testRemoveWorktreeRemovesDirectory(): void
    {
        if (! $this->hasGit()) {
            $this->markTestSkipped('git not available');
        }

        $repo = $this->createGitRepo('rm-wt-repo');
        $target = $this->tmpDir . '/rm-wt-target';

        $this->git->create_worktree($repo, $target, 'rudel/rm-test');
        $this->assertDirectoryExists($target);

        $result = $this->git->remove_worktree($repo, $target);
        $this->assertTrue($result);
        $this->assertDirectoryDoesNotExist($target);
    }

    // is_branch_merged()

    public function testIsBranchMergedReturnsTrueAfterMerge(): void
    {
        if (! $this->hasGit()) {
            $this->markTestSkipped('git not available');
        }

        $repo = $this->createGitRepo('merge-repo');

        // Create a branch, add a commit, merge it.
        exec('git -C ' . escapeshellarg($repo) . ' checkout -b rudel/feature 2>&1');
        file_put_contents($repo . '/feature.txt', 'feature');
        exec('git -C ' . escapeshellarg($repo) . ' add -A 2>&1');
        exec('git -C ' . escapeshellarg($repo) . ' commit -m "feature" 2>&1');
        exec('git -C ' . escapeshellarg($repo) . ' checkout main 2>&1');
        exec('git -C ' . escapeshellarg($repo) . ' merge rudel/feature 2>&1');

        $this->assertTrue($this->git->is_branch_merged($repo, 'rudel/feature', 'main'));
    }

    public function testIsBranchMergedReturnsFalseWhenNotMerged(): void
    {
        if (! $this->hasGit()) {
            $this->markTestSkipped('git not available');
        }

        $repo = $this->createGitRepo('unmerged-repo');

        exec('git -C ' . escapeshellarg($repo) . ' checkout -b rudel/unmerged 2>&1');
        file_put_contents($repo . '/unmerged.txt', 'work');
        exec('git -C ' . escapeshellarg($repo) . ' add -A 2>&1');
        exec('git -C ' . escapeshellarg($repo) . ' commit -m "wip" 2>&1');
        exec('git -C ' . escapeshellarg($repo) . ' checkout main 2>&1');

        $this->assertFalse($this->git->is_branch_merged($repo, 'rudel/unmerged', 'main'));
    }

    // delete_branch()

    public function testDeleteBranchRemovesMergedBranch(): void
    {
        if (! $this->hasGit()) {
            $this->markTestSkipped('git not available');
        }

        $repo = $this->createGitRepo('del-branch-repo');

        exec('git -C ' . escapeshellarg($repo) . ' checkout -b rudel/to-delete 2>&1');
        exec('git -C ' . escapeshellarg($repo) . ' checkout main 2>&1');
        exec('git -C ' . escapeshellarg($repo) . ' merge rudel/to-delete 2>&1');

        $result = $this->git->delete_branch($repo, 'rudel/to-delete');
        $this->assertTrue($result);

        // Branch should be gone.
        exec('git -C ' . escapeshellarg($repo) . ' branch 2>&1', $output);
        $branches = implode("\n", $output);
        $this->assertStringNotContainsString('rudel/to-delete', $branches);
    }

    // get_default_branch()

    public function testGetDefaultBranchReturnsMain(): void
    {
        if (! $this->hasGit()) {
            $this->markTestSkipped('git not available');
        }

        $repo = $this->createGitRepo('default-branch-repo');
        $branch = $this->git->get_default_branch($repo);

        // Without a remote, falls back to 'main'.
        $this->assertSame('main', $branch);
    }

    // clone_with_worktrees()

    public function testCloneWithWorktreesCreatesWorktreeForGitRepos(): void
    {
        if (! $this->hasGit()) {
            $this->markTestSkipped('git not available');
        }

        // Set up source: one git repo theme, one plain theme.
        $source = $this->tmpDir . '/themes-src';
        mkdir($source, 0755);

        $gitTheme = $source . '/git-theme';
        mkdir($gitTheme, 0755);
        exec('git -C ' . escapeshellarg($gitTheme) . ' init 2>&1');
        exec('git -C ' . escapeshellarg($gitTheme) . ' config user.email "t@t.com" 2>&1');
        exec('git -C ' . escapeshellarg($gitTheme) . ' config user.name "T" 2>&1');
        file_put_contents($gitTheme . '/style.css', 'body {}');
        exec('git -C ' . escapeshellarg($gitTheme) . ' add -A 2>&1');
        exec('git -C ' . escapeshellarg($gitTheme) . ' commit -m "init" 2>&1');

        $plainTheme = $source . '/plain-theme';
        mkdir($plainTheme, 0755);
        file_put_contents($plainTheme . '/style.css', 'body {}');

        $target = $this->tmpDir . '/themes-dst';
        mkdir($target, 0755);

        $results = $this->git->clone_with_worktrees($source, $target, 'my-sandbox');

        // Git theme should be a worktree.
        $this->assertArrayHasKey('git-theme', $results['worktrees']);
        $this->assertSame('rudel/my-sandbox', $results['worktrees']['git-theme']);
        $this->assertFileExists($target . '/git-theme/style.css');

        // Plain theme should be file-copied.
        $this->assertContains('plain-theme', $results['copied']);
        $this->assertFileExists($target . '/plain-theme/style.css');
    }

    public function testCloneWithWorktreesHandlesEmptyDirectory(): void
    {
        $source = $this->tmpDir . '/empty-src';
        mkdir($source, 0755);

        $target = $this->tmpDir . '/empty-dst';
        mkdir($target, 0755);

        $results = $this->git->clone_with_worktrees($source, $target, 'test');

        $this->assertEmpty($results['worktrees']);
        $this->assertEmpty($results['copied']);
    }

    public function testCloneWithWorktreesHandlesNonexistentSource(): void
    {
        $target = $this->tmpDir . '/no-src-dst';
        $results = $this->git->clone_with_worktrees('/nonexistent', $target, 'test');

        $this->assertEmpty($results['worktrees']);
        $this->assertEmpty($results['copied']);
    }
}

<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Rudel\GitHubIntegration;
use Rudel\Tests\RudelTestCase;

class GitHubIntegrationTest extends RudelTestCase
{
    // Constructor validation

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstructorThrowsWithoutToken(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GitHub token required');
        new GitHubIntegration('owner/repo');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstructorAcceptsExplicitToken(): void
    {
        $gh = new GitHubIntegration('owner/repo', 'ghp_test_token');
        $this->assertInstanceOf(GitHubIntegration::class, $gh);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstructorReadsTokenFromConstant(): void
    {
        define('RUDEL_GITHUB_TOKEN', 'ghp_constant_token');
        $gh = new GitHubIntegration('owner/repo');
        $this->assertInstanceOf(GitHubIntegration::class, $gh);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstructorRejectsEmptyToken(): void
    {
        $this->expectException(\RuntimeException::class);
        new GitHubIntegration('owner/repo', '');
    }

    // API error handling

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetDefaultBranchThrowsOnInvalidRepo(): void
    {
        $gh = new GitHubIntegration('nonexistent/repo-that-does-not-exist-12345', 'ghp_invalid_token');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GitHub API error');
        $gh->get_default_branch();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateBranchThrowsOnInvalidRepo(): void
    {
        $gh = new GitHubIntegration('nonexistent/repo-that-does-not-exist-12345', 'ghp_invalid_token');

        $this->expectException(\RuntimeException::class);
        $gh->create_branch('test-branch');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDeleteBranchReturnsFalseOnFailure(): void
    {
        $gh = new GitHubIntegration('nonexistent/repo-that-does-not-exist-12345', 'ghp_invalid_token');

        $this->assertFalse($gh->delete_branch('nonexistent-branch'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIsBranchMergedReturnsFalseOnFailure(): void
    {
        $gh = new GitHubIntegration('nonexistent/repo-that-does-not-exist-12345', 'ghp_invalid_token');

        $this->assertFalse($gh->is_branch_merged('nonexistent-branch'));
    }
}

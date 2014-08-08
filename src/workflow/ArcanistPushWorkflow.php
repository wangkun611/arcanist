<?php

/**
 * Pushs a branch by rebasing, merging and amending it.
 */
final class ArcanistPushWorkflow extends ArcanistWorkflow {

  private $isGit;
  private $isGitSvn;
  private $isHg;
  private $isHgSvn;

  private $oldBranch;
  private $branch;
  private $remote;
  private $remoteBranch;
  private $baseBranch;
  private $useSquash;
  private $keepBranch;
  private $shouldUpdateWithRebase;
  private $branchType;
  private $preview;
  private $noAmend;

  private $revision;
  private $message;

  public function getRevisionDict() {
    return $this->revision;
  }

  public function getWorkflowName() {
    return 'push';
  }

  public function getCommandSynopses() {
    return phutil_console_format(<<<EOTEXT
      **push** [__options__] __branch__
EOTEXT
      );
  }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
          Supports: git

          Push an accepted change (currently sitting in local feature branch
          __branch__) to the remote.

EOTEXT
      );
  }

  public function requiresWorkingCopy() {
    return true;
  }

  public function requiresConduit() {
    return true;
  }

  public function requiresAuthentication() {
    return true;
  }

  public function requiresRepositoryAPI() {
    return true;
  }

  public function getArguments() {
    return array(
      'amend' => array(
        'help' => phutil_console_format(
          "Amend the working copy, synchronizing the local commit message from Differential. [**default**]"),
        'supports' => array('git')
      ),
      'no-amend' => array(
        'help' => pht("Do not amend the working copy."),
        'conflicts' => array(
          'amend' => true,
        )
      ),
      'remote' => array(
        'param' => 'origin',
        'help' => pht(
          "Push to a remote other than the default ('origin' in git)."),
      ),
      'from' => array(
        'help' => pht(
          "__branch__ created from. ".
          "arc.push.branch.from is default.")
      ),
      'update-with-rebase' => array(
        'help' => pht(
          "When updating the feature branch, use rebase instead of merge. ".
          "This might make things work better in some cases. Set ".
          "arc.push.update.default to 'rebase' to make this the default."),
        'conflicts' => array(
          'merge' => pht(
            'The --merge strategy does not update the feature branch.'),
          'update-with-merge' => pht(
            'Cannot be used with --update-with-merge.'),
        ),
        'supports' => array(
          'git',
        ),
      ),
      'update-with-merge' => array(
        'help' => pht(
          "When updating the feature branch, use merge instead of rebase. ".
          "This is the default behavior. Setting arc.push.update.default to ".
          "'merge' can also be used to make this the default."),
        'conflicts' => array(
          'merge' => pht(
            'The --merge strategy does not update the feature branch.'),
          'update-with-rebase' => pht(
            'Cannot be used with --update-with-rebase.'),
        ),
        'supports' => array(
          'git',
        ),
      ),
      'revision' => array(
        'param' => 'id',
        'help' => pht(
          'Use the message from a specific revision, rather than '.
          'inferring the revision based on branch content.'),
      ),
      'preview' => array(
        'help' => pht(
          'Prints the commits that would be pushed. Does not '.
          'actually modify or push the commits.'),
      ),
      '*' => 'branch',
    );
  }

  public function run() {
    $this->readArguments();
    $this->validate();

    try {
      $this->pullFromRemote();
    } catch (Exception $ex) {
      $this->restoreBranch();
      throw $ex;
    }

    $this->printPendingCommits();
    if ($this->preview) {
      $this->restoreBranch();
      return 0;
    }

    $this->findRevision();

    $this->push();

    if ($this->oldBranch != $this->branch) {
      // If we were on some branch A and the user ran "arc push B",
      // switch back to A.
      $this->restoreBranch();
    }

    echo pht('Done.'), "\n";

    return 0;
  }

  private function getUpstreamMatching($branch, $pattern) {
    if ($this->isGit) {
      $repository_api = $this->getRepositoryAPI();
      list($err, $fullname) = $repository_api->execManualLocal(
        'rev-parse --symbolic-full-name %s@{upstream}',
        $branch);
      if (!$err) {
        $matches = null;
        if (preg_match($pattern, $fullname, $matches)) {
          return last($matches);
        }
      }
    }
    return null;
  }

  private function readArguments() {
    $repository_api = $this->getRepositoryAPI();
    $this->isGit = $repository_api instanceof ArcanistGitAPI;
    $this->isHg = $repository_api instanceof ArcanistMercurialAPI;

    if (!$this->isGit) {
      throw new ArcanistUsageException(
        pht("'arc push' only supports git"));
    }

    if ($this->isGit) {
      $repository = $this->loadProjectRepository();
      $this->isGitSvn = (idx($repository, 'vcs') == 'svn');
    }

    $branch = $this->getArgument('branch');
    if (empty($branch)) {
      $branch = $this->getBranchOrBookmark();

      if ($branch) {
        $this->branchType = $this->getBranchType($branch);
        echo pht("Pushing current %s '%s'.", $this->branchType, $branch), "\n";
        $branch = array($branch);
      }
    }

    if (count($branch) !== 1) {
      throw new ArcanistUsageException(
        pht('Specify exactly one branch to push changes from.'));
    }
    $this->branch = head($branch);

    $update_strategy = nonempty(
      $this->getConfigFromAnySource('arc.push.update.default'), 'rebase');
    $this->shouldUpdateWithRebase = $update_strategy == 'rebase';
    if ($this->getArgument('update-with-rebase')) {
      $this->shouldUpdateWithRebase = true;
    } else if ($this->getArgument('update-with-merge')) {
      $this->shouldUpdateWithRebase = false;
    }

    $this->preview = $this->getArgument('preview');
    $this->noAmend = $this->getArgument('no-amend');

    if (!$this->branchType) {
      $this->branchType = $this->getBranchType($this->branch);
    }

    $remote_default = $this->isGit ? 'origin' : '';
    $remote_default = coalesce(
      $this->getUpstreamMatching($this->branch, '/^refs\/remotes\/(.+?)\//'),
      $remote_default);
    $this->remote = $this->getArgument('remote', $remote_default);

    $this->remoteBranch = coalesce(
      $this->getUpstreamMatching($this->branch, '/^refs\/remotes\/.+?\/(.+)/'),
      "");

    if($this->remoteBranch) {
      $this->baseBranch = $this->remote.'/'.$this->remoteBranch;
    } else {
      $this->baseBranch = $this->remote.'/'.nonempty($this->getArgument('from'), 
                    $this->getConfigFromAnySource('arc.push.branch.from'),
                    'HEAD');
    }
    $this->oldBranch = $this->getBranchOrBookmark();
  }

  private function validate() {
    $repository_api = $this->getRepositoryAPI();

    if ($this->isGit) {
      list($err) = $repository_api->execManualLocal(
        'rev-parse --verify %s',
        $this->branch);

      if ($err) {
        throw new ArcanistUsageException(
          pht("Branch '%s' does not exist.", $this->branch));
      }
    }

    $this->requireCleanWorkingCopy();
  }

  private function printPendingCommits() {
    $repository_api = $this->getRepositoryAPI();

    if ($repository_api instanceof ArcanistGitAPI) {
      list($out) = $repository_api->execxLocal(
        'log --oneline %s %s --',
        $this->branch,
        '^'.$this->baseBranch);
    }

    if (!trim($out)) {
      $this->restoreBranch();
      throw new ArcanistUsageException(
          pht('No commits to push from %s.', $this->branch));
    }

    echo pht("The following commit(s) will be pushed:\n\n%s", $out), "\n";
  }

  private function findRevision() {
    $repository_api = $this->getRepositoryAPI();

    $this->parseBaseCommitArgument(array($this->baseBranch));

    $revision_id = $this->getArgument('revision');
    if ($revision_id) {
      $revision_id = $this->normalizeRevisionID($revision_id);
      $revisions = $this->getConduit()->callMethodSynchronous(
        'differential.query',
        array(
          'ids' => array($revision_id),
        ));
      if (!$revisions) {
        throw new ArcanistUsageException(pht(
          "No such revision '%s'!",
          "D{$revision_id}"));
      }
    } else {
      $revisions = $repository_api->loadWorkingCopyDifferentialRevisions(
        $this->getConduit(),
        array());
    }

    if (count($revisions) > 1) {
      $revisions_branch = array();
      // remove revisions which's branch is not '$this->branch'
      foreach ($revisions as $revision) {
          if ($revision["branch"] === $this->branch) {
              $revisions_branch[] = $revision;
          }
      }

      $revisions = $revisions_branch;
    }
    if (!count($revisions)) {
      throw new ArcanistUsageException(pht(
        "arc can not identify which revision exists on %s '%s'. Update the ".
        "revision with recent changes to synchronize the %s name and hashes, ".
        "or use 'arc amend' to amend the commit message at HEAD, or use ".
        "'--revision <id>' to select a revision explicitly.",
        $this->branchType,
        $this->branch,
        $this->branchType));
    } else if (count($revisions) > 1) {
      $message = pht(
        "There are multiple revisions on feature %s '%s' which are not pushed\n\n".
        "%s\n".
        "Separate these revisions onto different %s, or use --revision <id>' ".
        "to use the commit message from <id> and push them all.",
        $this->branchType,
        $this->branch,
        $this->renderRevisionList($revisions),
        $this->branchType.'s');
      throw new ArcanistUsageException($message);
    }

    $this->revision = head($revisions);

    $rev_status = $this->revision['status'];
    $rev_id = $this->revision['id'];
    $rev_title = $this->revision['title'];
    $rev_auxiliary = idx($this->revision, 'auxiliary', array());

    if ($this->revision['authorPHID'] != $this->getUserPHID()) {
      $other_author = $this->getConduit()->callMethodSynchronous(
        'user.query',
        array(
          'phids' => array($this->revision['authorPHID']),
        ));
      $other_author = ipull($other_author, 'userName', 'phid');
      $other_author = $other_author[$this->revision['authorPHID']];
      $ok = phutil_console_confirm(pht(
        "This %s has revision '%s' but you are not the author. Push this ".
        "revision by %s?",
        $this->branchType,
        "D{$rev_id}: {$rev_title}",
        $other_author));
      if (!$ok) {
        throw new ArcanistUserAbortException();
      }
    }

    if ($rev_status != ArcanistDifferentialRevisionStatus::ACCEPTED) {
      $ok = phutil_console_confirm(pht(
        "Revision '%s' has not been accepted. Continue anyway?",
        "D{$rev_id}: {$rev_title}"));
      if (!$ok) {
        throw new ArcanistUserAbortException();
      }
    }

    if ($rev_auxiliary) {
      $phids = idx($rev_auxiliary, 'phabricator:depends-on', array());
      if ($phids) {
        $dep_on_revs = $this->getConduit()->callMethodSynchronous(
          'differential.query',
           array(
             'phids' => $phids,
             'status' => 'status-open',
           ));

        $open_dep_revs = array();
        foreach ($dep_on_revs as $dep_on_rev) {
          $dep_on_rev_id = $dep_on_rev['id'];
          $dep_on_rev_title = $dep_on_rev['title'];
          $dep_on_rev_status = $dep_on_rev['status'];
          $open_dep_revs[$dep_on_rev_id] = $dep_on_rev_title;
        }

        if (!empty($open_dep_revs)) {
          $open_revs = array();
          foreach ($open_dep_revs as $id => $title) {
            $open_revs[] = '    - D'.$id.': '.$title;
          }
          $open_revs = implode("\n", $open_revs);

          echo pht("Revision '%s' depends on open revisions:\n\n%s",
                   "D{$rev_id}: {$rev_title}",
                   $open_revs);

          $ok = phutil_console_confirm(pht('Continue anyway?'));
          if (!$ok) {
            throw new ArcanistUserAbortException();
          }
        }
      }
    }

    $this->message = $this->getConduit()->callMethodSynchronous(
      'differential.getcommitmessage',
      array(
        'revision_id' => $rev_id,
      ));

    echo pht("Pushing revision '%s'...",
             "D{$rev_id}: {$rev_title}"), "\n";

    $diff_phid = idx($this->revision, 'activeDiffPHID');
    if ($diff_phid) {
      $this->checkForBuildables($diff_phid);
    }
  }

  private function pullFromRemote() {
    $repository_api = $this->getRepositoryAPI();

    $repository_api->execxLocal('checkout %s', $this->branch);

    echo phutil_console_format(pht(
      "Switched to branch **%s**. Updating branch...\n",
      $this->branch));

    if ($this->remoteBranch) {
      try {
        $cmd = 'pull --ff-only --no-stat';
        if ($this->shouldUpdateWithRebase) {
          $cmd = $cmd.' --rebase';
        }
        $repository_api->execxLocal($cmd);
      } catch (CommandException $ex) {
        if (!$this->isGitSvn) {
          throw $ex;
        }
      }
    }
  }

  private function push() {
    $repository_api = $this->getRepositoryAPI();

    if (!$this->noAmend) {
      $amend_workflow = $this->buildChildWorkflow(
        'amend',
        array(
          '--revision', $this->revision['id']
        ));
      $amend_workflow->run();
    }
    echo pht('Pushing change...'), "\n\n";

    chdir($repository_api->getPath());

    if ($this->isGitSvn) {
      $err = phutil_passthru('git svn dcommit');
      $cmd = 'git svn dcommit';
    } else if ($this->isGit) {
      $err = phutil_passthru(
        'git push %s %s',
        $this->remote,
        $this->branch);
      $cmd = 'git push';
    }

    if ($err) {
      $failed_str = pht('PUSH FAILED!');
      echo phutil_console_format("<bg:red>**   %s   **</bg>\n", $failed_str);
      $this->executeCleanupAfterFailedPush();
      if ($this->isGit) {
        throw new ArcanistUsageException(pht(
          "'%s' failed! Fix the error and run 'arc push' again.",
          $cmd));
      }
      throw new ArcanistUsageException(pht(
        "'%s' failed! Fix the error and push this change manually.",
        $cmd));
    }

    // If we know which repository we're in, try to tell Phabricator that we
    // pushed commits to it so it can update. This hint can help pull updates
    // more quickly, especially in rarely-used repositories.
    if ($this->getRepositoryCallsign()) {
      try {
        $this->getConduit()->callMethodSynchronous(
          'diffusion.looksoon',
          array(
            'callsigns' => array($this->getRepositoryCallsign()),
          ));
      } catch (ConduitClientException $ex) {
        // If we hit an exception, just ignore it. Likely, we are running
        // against a Phabricator which is too old to support this method.
        // Since this hint is purely advisory, it doesn't matter if it has
        // no effect.
      }
    }

    $mark_workflow = $this->buildChildWorkflow(
      'close-revision',
      array(
        '--finalize',
        '--quiet',
        $this->revision['id'],
      ));
    $mark_workflow->run();

    echo "\n";
  }

  private function executeCleanupAfterFailedPush() {
    $repository_api = $this->getRepositoryAPI();
    if ($this->isGit) {
      $this->restoreBranch();
    }
  }

  protected function getSupportedRevisionControlSystems() {
    return array('git');
  }

  private function getBranchOrBookmark() {
    $repository_api = $this->getRepositoryAPI();
    if ($this->isGit) {
      $branch = $repository_api->getBranchName();
    } else if ($this->isHg) {
      $branch = $repository_api->getActiveBookmark();
      if (!$branch) {
        $branch = $repository_api->getBranchName();
      }
    }

    return $branch;
  }

  private function getBranchType($branch) {
    $repository_api = $this->getRepositoryAPI();
    if ($this->isHg && $repository_api->isBookmark($branch)) {
      return 'bookmark';
    }
    return 'branch';
  }

  /**
   * Restore the original branch, e.g. after a successful land or a failed
   * pull.
   */
  private function restoreBranch() {
    $repository_api = $this->getRepositoryAPI();
    $repository_api->execxLocal(
      'checkout %s',
      $this->oldBranch);
    if ($this->isGit) {
      $repository_api->execxLocal(
        'submodule update --init --recursive');
    }
    echo phutil_console_format(
      "Switched back to {$this->branchType} **%s**.\n",
      $this->oldBranch);
  }


  /**
   * Check if a diff has a running or failed buildable, and prompt the user
   * before landing if it does.
   */
  private function checkForBuildables($diff_phid) {
    // NOTE: Since Harbormaster is still beta and this stuff all got added
    // recently, just bail if we can't find a buildable. This is just an
    // advisory check intended to prevent human error.

    try {
      $buildables = $this->getConduit()->callMethodSynchronous(
        'harbormaster.querybuildables',
        array(
          'buildablePHIDs' => array($diff_phid),
          'manualBuildables' => false,
        ));
    } catch (ConduitClientException $ex) {
      return;
    }

    if (!$buildables['data']) {
      // If there's no corresponding buildable, we're done.
      return;
    }

    $console = PhutilConsole::getConsole();

    $buildable = head($buildables['data']);

    if ($buildable['buildableStatus'] == 'passed') {
      $console->writeOut(
        "**<bg:green> %s </bg>** %s\n",
        pht('BUILDS PASSED'),
        pht(
          'Harbormaster builds for the active diff completed successfully.'));
      return;
    }

    switch ($buildable['buildableStatus']) {
      case 'building':
        $message = pht(
          'Harbormaster is still building the active diff for this revision:');
        $prompt = pht('Push revision anyway, despite ongoing build?');
        break;
      case 'failed':
        $message = pht(
          'Harbormaster failed to build the active diff for this revision. '.
          'Build failures:');
        $prompt = pht('Push revision anyway, despite build failures?');
        break;
      default:
        // If we don't recognize the status, just bail.
        return;
    }

    $builds = $this->getConduit()->callMethodSynchronous(
      'harbormaster.querybuilds',
      array(
        'buildablePHIDs' => array($buildable['phid']),
      ));

    $console->writeOut($message."\n\n");
    foreach ($builds['data'] as $build) {
      switch ($build['buildStatus']) {
        case 'failed':
          $color = 'red';
          break;
        default:
          $color = 'yellow';
          break;
      }

      $console->writeOut(
        "    **<bg:".$color."> %s </bg>** %s: %s\n",
        phutil_utf8_strtoupper($build['buildStatusName']),
        pht('Build %d', $build['id']),
        $build['name']);
    }

    $console->writeOut(
      "\n%s\n\n    **%s**: __%s__",
      pht('You can review build details here:'),
      pht('Harbormaster URI'),
      $buildable['uri']);

    if (!$console->confirm($prompt)) {
      throw new ArcanistUserAbortException();
    }
  }

}

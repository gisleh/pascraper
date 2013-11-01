<?php

require 'vendor/autoload.php';
require 'user_password.php';

use Goutte\Client;

// "Needs work" issue status ID.
const PROJECTAPP_SCRAPER_NEEDS_WORK = 13;
const PROJECTAPP_SCRAPER_NEEDS_REVIEW = 8;
const PROJECTAPP_SCRAPER_DUPLICATE = 3;
const PROJECTAPP_SCRAPER_POSTPONED = 4;
const PROJECTAPP_SCRAPER_POSTPONED_INFO = 16;
const PROJECTAPP_SCRAPER_WONTFIX = 5;

// Perform a user login.
global $client;
$client = new Client();
$crawler = $client->request('GET', 'https://drupal.org/user');
$form = $crawler->selectButton('Log in')->form();
// $user and $password must be set in user_password.php.
$crawler = $client->submit($form, array('name' => $user, 'pass' => $password));

$login_errors = $crawler->filter('.messages-error');
if ($login_errors->count() > 0) {
  print "Login failed.\n";
  exit(1);
}

// Get all "needs review" issues.
$crawler = $client->request('GET', 'https://drupal.org/project/issues/projectapplications?status=8');
$issues = $crawler->filterXPath('//tbody/tr/td[1]/a');

if ($issues->count() == 0) {
  print "No issues found.\n";
  exit(2);
}

$links = $issues->links();
$closed_issues = array();

// Go to each issue.
foreach ($links as $link) {
  // Do not touch issues that we already closed.
  if (in_array($link->getUri(), $closed_issues)) {
    continue;
  }

  $issue_page = $client->click($link);
  $issue_summary = $issue_page->filter('.field-name-body');
  // Get the issue summary + all commments.
  $issue_thread = $issue_page->filter('#block-system-main');
  // Initialize empty comment list that shoudl be posted.
  $post = array();
  // Do not touch the issue's status per default.
  $status = NULL;

  // Search for git repository links.
  $text = $issue_summary->text();
  $matches = array();
  // There are a couple of possible patterns:
  // http://git.drupal.org/sandbox/<user>/<nid>.git
  // <user>@git.drupal.org:sandbox/<user>/<nid>.git
  // http://drupalcode.org/sandbox/<user>/<nid>.git
  // git.drupal.org:sandbox/<user>/<nid>.git
  preg_match('/http:\/\/git\.drupal\.org\/sandbox\/[^\s]+\.git|[^\s]+@git\.drupal\.org:sandbox\/[^\s]+\.git|http:\/\/drupalcode\.org\/sandbox\/[^\s]+\.git|git\.drupal\.org:sandbox\/[^\s]+\.git/', $text, $matches);
  if (empty($matches)) {
    // Set the issue to "needs work" as the link to the project page is missing.
    $comment = 'Link to the project page and git clone command are missing in the issue summary, please add them.';
    // Only set the issue to "needs work" once, to avoid changing the status
    // over and over again.
    if (strpos($issue_thread->text(), $comment) === FALSE) {
      $post[] = $comment;
      $status = PROJECTAPP_SCRAPER_NEEDS_WORK;
    }
  }
  else {
    $url = $matches[0];
    preg_match('/sandbox\/.*\.git/', $url, $matches);
    $git_url = 'http://git.drupal.org/' . $matches[0];
  }

  if ($git_url) {
    // Check if someone already posted a link to pareview.sh automated reviews.
    $issue_links = $issue_thread->filterXPath("//@href[starts-with(., 'http://pareview.sh/pareview')]");
    $issue_links_ventral = $issue_thread->filterXPath("//@href[starts-with(., 'http://ventral.org/pareview')]");
    if ($issue_links->count() == 0 && $issue_links_ventral->count() == 0) {
      // Invoke pareview.sh now to check automated review errors.
      $pareview_output = array();
      $return_var = 0;
      exec('pareview.sh ' . escapeshellarg($git_url), $pareview_output, $return_var);
      if ($return_var == 1) {
        print 'Git clone failed for ' . $git_url . ', issue: ' . $client->getRequest()->getUri();
      }
      // If there are more than 30 lines output then we assume that some errors
      // should be fixed.
      elseif (count($pareview_output) > 30) {
        $post[] = 'There are some errors reported by automated review tools, did you already check them? See http://pareview.sh/pareview/' . str_replace(array('/', '.', ':'), '', $git_url);
        $status = PROJECTAPP_SCRAPER_NEEDS_WORK;
      }
    }
  }

  // Search for multiple applications for this user.
  $node_author = $issue_page->filterXPath("///div[@class = 'node node-project-issue clearfix']/div[@class = 'submitted']/a");
  $user_name = $node_author->text();

  // The username might have been shortened, so we go to the user account page
  // and get it from there.
  if (mb_substr($user_name, -3) == '...') {
    $user_page_link = $node_author->link();
    $user_page = $client->click($user_page_link);
    $user_name = $user_page->filter('#page-title')->text();
  }
  $search_results = $client->request('GET', 'https://drupal.org/project/issues/search/projectapplications?submitted=' . urlencode($user_name) . '&sid[0]=Open');
  $application_issues = $search_results->filterXPath('//tbody/tr/td[1]/a')->links();
  if (count($application_issues) > 1) {
    $comment = array();
    $comment[] = <<<COMMENT
<dl>
<dt>Multiple Applications</dt>
<dd>It appears that there have been multiple project applications opened under your username:

COMMENT;
    foreach ($application_issues as $count => $application_issue) {
      $comment[] = 'Project ' . ($count + 1) . ': ' . $application_issue->getUri();
    }
    $comment[] = <<<COMMENT
As successful completion of the project application process results in the applicant being granted the 'Create Full Projects' permission, there is no need to take multiple applications through the process. Once the first application has been successfully approved, then the applicant can promote other projects without review. Because of this, posting multiple applications is not necessary, and results in additional workload for reviewers ... which in turn results in longer wait times for everyone in the queue.  With this in mind, your secondary applications have been marked as 'closed(duplicate)', with only one application left open (chosen at random).

If you prefer that we proceed through this review process with a different application than the one which was left open, then feel free to close the 'open' application as a duplicate, and re-open one of the project applications which had been closed.</dd>
</dl>
COMMENT;
    // Leave the current application open and just post the comment.
    projectapp_scraper_post_comment($link->getUri(), $comment);

    // Close all other applications.
    foreach ($application_issues as $application_issue) {
      if ($application_issue->getUri() == $link->getUri()) {
        // Skip the current application.
        continue;
      }
      $duplicate_page = $client->click($application_issue);
      projectapp_scraper_post_comment($client->getRequest()->getUri(), $comment, PROJECTAPP_SCRAPER_DUPLICATE);
      // Rember that we closed this issue to not post to it in this run again.
      $closed_issues[] = $application_issue->getUri();
    }
  }

  // Post a hint to the review bonus program.
  $issue_text = $issue_thread->text();
  if (stripos($issue_text, 'review bonus') === FALSE) {
    $post[] = 'We are currently quite busy with all the project applications and we prefer projects with a <a href="http://drupal.org/node/1975228">review bonus</a>. Please help reviewing and put yourself on the <a href="http://drupal.org/project/issues/search/projectapplications?status[]=8&status[]=14&issue_tags=PAReview%3A+review+bonus">high priority list</a>, then we will take a look at your project right away :-)';
    $post[] = 'Also, you should get your friends, colleagues or other community members involved to review this application. Let them go through the <a href="http://drupal.org/node/1587704">review checklist</a> and post a comment that sets this issue to "needs work" (they found some problems with the project) or "reviewed & tested by the community" (they found no major flaws).';
  }

  if (!empty($post)) {
    projectapp_scraper_post_comment($link->getUri(), $post, $status);
  }
}

// Close "needs work" applications that got no update in more than 10 weeks.
$search_results = $client->request('GET', 'https://drupal.org/project/issues/search/projectapplications?sid[0]=13&sid[1]=4&sid[2]=16&order=changed&sort=asc');
$old_issues = $search_results->filterXPath('//tbody/tr/td[1]/a')->links();
// Extract the updated intervals from the issue table.
$intervals = $search_results->filterXPath('//tbody/tr/td[8]');

$comment = 'Closing due to lack of activity. Feel free to reopen if you are still working on this application (see also the <a href="https://drupal.org/node/532400">project application workflow</a>).';

foreach ($intervals as $count => $interval) {
  $updated = strtotime(trim($interval->nodeValue));
  $diff = $updated - time();
  // 10 weeks == 6048000 seconds.
  if ($diff > 6048000) {
    $issue_page = $client->click($old_issues[$count]);
    projectapp_scraper_post_comment($client->getRequest()->getUri(), $comment, PROJECTAPP_SCRAPER_WONTFIX);
  }
  else {
    // We reached the threshold of 10 weeks, all further issues are younger. So
    // we can stop here.
    break;
  }
}

/**
 * Helper function to either output the issue comment on a dry-run or post a new
 * comment to the issue.
 */
function projectapp_scraper_post_comment($issue_uri, $post, $status = NULL) {
  global $argv;
  global $client;
  if (!is_array($post)) {
    $post = array($post);
  }

  $post[] = "<i>I'm a robot and this is an automated message from <a href=\"http://drupal.org/sandbox/klausi/1938730\">Project Applications Scraper</a>.</i>";

  if ($status) {
    // If we need to set the status we edit the issue page itself.
    $edit_page = $client->request('GET', $issue_uri . '/edit');
  }
  else {
    // Otherwise we just add a comment with the usual comment form.
    $edit_page = $client->request('GET', $issue_uri);
  }
  $comment_form = $edit_page->selectButton('Save')->form();

  if (isset($argv[1]) && $argv[1] == 'dry-run') {
    // Dry run, so just print out the suggested comment.
    $output = array(
      'issue' => $comment_form->getUri(),
      'comment' => $post,
      'status' => $status,
    );
    print_r($output);
  }
  else {
    // Production run: post the comment to the drupal.org issue.
    $comment = implode("\n\n", $post);
    if ($status) {
      $form_values['nodechanges_comment_body[value]'] = $comment;
      // We need to HTML entity decode the issue summary here, otherwise we
      // would post back a double-encoded version, which would result in issue
      // summary changes that we don't want to touch.
      $form_values['body[und][0][value]'] = html_entity_decode($comment_form->get('body[und][0][value]')->getValue(), ENT_QUOTES, 'UTF-8');
      $form_values['field_issue_status[und]'] = $status;
    }
    else {
      $form_values['comment_body[und][0][value]'] = $comment;
    }
    $client->submit($comment_form, $form_values);
  }
}

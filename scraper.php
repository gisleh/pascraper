<?php

require 'vendor/autoload.php';
require 'user_password.php';

use Goutte\Client;

// "Needs work" issue status ID.
const PROJECTAPP_SCRAPER_NEEDS_WORK = 13;
const PROJECTAPP_SCRAPER_NEEDS_REVIEW = 8;

// Perform a user login.
global $client;
$client = new Client();
$crawler = $client->request('GET', 'http://drupal.org/user');
$form = $crawler->selectButton('Log in')->form();
// $user and $password must be set in user_password.php.
$crawler = $client->submit($form, array('name' => $user, 'pass' => $password));

$login_errors = $crawler->filter('.messages-error');
if ($login_errors->count() > 0) {
  print "Login failed.\n";
  exit(1);
}

// Get all "needs review" issues.
$crawler = $client->request('GET', 'http://drupal.org/project/issues/1339220?status=8');
$issues = $crawler->filterXPath('//tbody/tr/td[1]/a');

if ($issues->count() == 0) {
  print "No issues found.\n";
  exit(2);
}

$links = $issues->links();

// Go to each issue.
foreach ($links as $link) {
  $issue_page = $client->click($link);
  $issue_summary = $issue_page->filter('.node-content');
  $text = $issue_summary->text();

  // Search for the git repository link.
  $matches = array();
  preg_match('/http:\/\/git\.drupal\.org\/sandbox\/.+\.git/', $text, $matches);
  if (!empty($matches)) {
    $git_url = $matches[0];
  }
  else {
    // Try to find a user specific git URL.
    preg_match('/[^\s]+@git\.drupal\.org:sandbox\/.+\.git/', $text, $matches);
    if (!empty($matches)) {
      // Rewrite git URL to anonymous HTTP URL.
      $git_url = preg_replace('/^.+@git\.drupal\.org:sandbox/', 'http://git.drupal.org/sandbox', $matches[0]);
    }
    else {
      // Set the issue to "needs work" as the Git URL is missing.
      $comment_form = $issue_page->selectButton('Save')->form();
      $comment = 'Git repository URL is missing in the issue summary.';
      $client->submit($comment_form, array(
        'sid' => PROJECTAPP_SCRAPER_NEEDS_WORK,
        'comment' => $comment,
      ));
      continue;
    }
  }

  // Get the issue summary + all commments.
  $issue_thread =  $issue_page->filter('#content');
  // Check if someone already posted a link to ventral.org automated reviews.
  $issue_links = $issue_thread->filterXPath("//@href[starts-with(., 'http://ventral.org/pareview')]");
  if ($issue_links->count() == 0) {
    // Invoke pareview.sh now to check automated review errors.
    $pareview_output = array();
    exec('pareview.sh ' . escapeshellarg($git_url), $pareview_output);
    // If there are more than 10 lines output then we assume that some errors
    // should be fixed.
    if (count($pareview_output) > 10) {
      $comment_form = $issue_page->selectButton('Save')->form();
      $comment = 'There are some errors reported by automated review tools, did you already check them? See http://ventral.org/pareview/' . str_replace(array('/', '.', ':'), '', $git_url);
      $client->submit($comment_form, array(
        'sid' => PROJECTAPP_SCRAPER_NEEDS_WORK,
        'comment' => $comment,
      ));
      continue;
    }
  }

  // Post a hint to the review bonus program.
  $issue_text = $issue_thread->text();
  if (stripos($issue_text, 'review bonus') === FALSE) {
    $comment_form = $issue_page->selectButton('Save')->form();
    $comment = 'We are currently quite busy with all the project applications and I can only review projects with a <a href="http://drupal.org/node/1410826">review bonus</a>. Please help me reviewing and I\'ll take a look at your project right away :-)';
    $client->submit($comment_form, array('comment' => $comment));
    continue;
  }
}

/**
 * Helper function to either output the issue comment on a dry-run or post a new
 * comment to the issue.
 */
function projectapp_scraper_post_comment($issue_page, $comment, $status = NULL) {
  global $argv;
  global $client;
  if (isset($argv[1]) && $argv[1] == 'dry-run') {
    $output = array(
      'issue' => $client->getRequest()->getUri(),
      'comment' => $comment,
      'status' => $status,
    );
    print_r($output);
  }
  else {
    $comment_form = $issue_page->selectButton('Save')->form();
    $form_values = array('comment' => $comment);
    if ($status) {
      $form_values['sid'] = $status;
    }
    $client->submit($comment_form, $form_values);
  }
}